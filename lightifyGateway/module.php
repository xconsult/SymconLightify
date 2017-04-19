<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyClass.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifySocket.php"); 


class lightifyGateway extends IPSModule {

	private $lightCategory = null;
	private $plugCategory = null;
	private $groupCategory = null;
	private $switchCategory = null;
	private $motionCategory = null;

	private $lightifyBase = null;
	private $lightifySocket = null;
	private $arrayDevices = array();


	public function Create() {
		parent::Create();

		$this->RegisterPropertyString("Host", "");
		$this->RegisterPropertyInteger("TimeOut", 0);
		$this->RegisterPropertyInteger("updateInterval", 10);
		$this->RegisterPropertyString("Firmware", "");
		$this->RegisterPropertyBoolean("Open", false);

		$this->RegisterPropertyString("Categories", "");
	}


	public function ApplyChanges() {
		$this->Host = "";
		$this->Port = 4000;

		parent::ApplyChanges();
		$this->configCheck();

		$this->RegisterTimer("OSR_TIMER", $this->ReadPropertyInteger("updateInterval"), 'OSR_SyncGateway($_IPS[\'TARGET\'])');
		//IPS_LogMessage("SymconOSR", "Device list: ".$this->ReadPropertyString("Categories"));
	}


	public function GetConfigurationForm() {
		$data = json_decode(file_get_contents(__DIR__.DIRECTORY_SEPARATOR."form.json"));
		$Types = array("Light", "Plug", "Group", "Switch", "Motion");

		//Only add default element if we do not have anything in persistence
		if ($this->ReadPropertyString("Categories") == "") {
			foreach ($Types as $item) {
				$data->elements[4]->values[] = array(
					"Device" => $item,
					"CategoryID" => 0,
					"Category" => "select ...",
					"Sync" => "no",
					"SyncID" => false
				);
			}
		} else {
			//Annotate existing elements
			$Categories = json_decode($this->ReadPropertyString("Categories"));

			foreach ($Categories as $index => $row) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				if ($row->CategoryID && IPS_ObjectExists($row->CategoryID)) {
					$data->elements[4]->values[] = array(
						"Device" => $Types[$index],
						"Category" => IPS_GetName(0)."\\".IPS_GetLocation($row->CategoryID),
						"Sync" => ($row->SyncID) ? "yes" : "no"
					);
				} else {
					$data->elements[4]->values[] = array(
						"Device" => $Types[$index],
						"Category" => "select ...",
						"Sync" => "no"
					);
				}
			}
		}

		return json_encode($data);
	}


	protected function RegisterTimer($Ident, $updateInterval, $Script) {
		$id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);

		if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
			IPS_DeleteEvent($id);
			$id = 0;
		}

		if (!$id) {
			$id = IPS_CreateEvent(1);
			IPS_SetParent($id, $this->InstanceID);
			IPS_SetIdent($id, $Ident);
		}

		IPS_SetName($id, '[Gateway][update]');
		IPS_SetHidden($id, true);
		IPS_SetEventScript($id, "$Script;\n");
		if (!IPS_EventExists($id)) IPS_LogMessage("SymconOSR", "Ident with name [$Ident] used with wrong object type!");

		if ($updateInterval < 10) {
			return IPS_SetEventActive($id, false);
		} else {
			IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $updateInterval);
		}

		IPS_SetEventActive($id, $this->ReadPropertyBoolean("Open"));
  }


	private function configCheck() {
		if (filter_var($this->ReadPropertyString("Host"), FILTER_VALIDATE_IP) === false)
			return $this->SetStatus(202);

		if ($this->ReadPropertyInteger("updateInterval") < 5)
			return $this->SetStatus(203);

		return $this->SetStatus(102);
	}


	private function getCategories($Mode = 0) {
		if ($this->lightCategory == null || $this->plugCategory == null || $this->groupCategory == null || $this->switchCategory == null || $this->motionCategory == null) {
			$Instances = json_decode($this->ReadPropertyString("Categories"), true);

			list(
				$this->lightCategory,
				$this->plugCategory,
				$this->groupCategory,
				$this->switchCategory,
				$this->motionCategory
			) = $Instances;
		}

		switch ($Mode) {
			case 0:
				return true;

			case 1:
				if ($this->lightCategory['CategoryID'] > 0 && $this->lightCategory['SyncID']) return true;
				if ($this->plugCategory['CategoryID'] > 0 && $this->plugCategory['SyncID']) return true;
				if ($this->switchCategory['CategoryID'] > 0 && $this->switchCategory['SyncID']) return true;
				if ($this->motionCategory['CategoryID'] > 0 && $this->motionCategory['SyncID']) return true;

			case 2:
				if ($this->groupCategory['CategoryID'] > 0 && $this->groupCategory['SyncID']) return true;
		}

		return false;
	}


	protected function openSocket() {
		$host = $this->ReadPropertyString("Host");
		$timeOut = $this->ReadPropertyInteger("TimeOut");

		if ($timeOut > 0 && Sys_Ping($host, $timeOut) != true) {
			IPS_LogMessage("SymconOSR", "Gateway is not reachable!");
			return false;
		}

		if ($this->lightifySocket == null) {
			if ($host != "") return new lightifySocket($host, 4000);
			return false;
		}
	}


	public function SyncGateway() {
		if ($this->ReadPropertyBoolean("Open")) {
			$this->lightifyBase = new lightifyBase;

			if ($this->lightifySocket = $this->openSocket()) {
				//Get gateway firmware version			
				if (false !== ($data = $this->lightifySocket->getGatewayFirmware()) && strlen($data) > 8) {
					$Firmware = ord($data{9}).".".ord($data{10}).".".ord($data{11}).".".ord($data{12});
					//IPS_LogMessage("SymconOSR", "Gateway firmware byte [01-".ord($data{9})."] [02-".ord($data{10})."] [03-".ord($data{11})."] [04-".ord($data{12})."] -> ".$Firmware);

					if ($this->ReadPropertyString("Firmware") != $Firmware) {
						IPS_SetProperty($this->InstanceID, "Firmware", (string)$Firmware);
						IPS_ApplyChanges($this->InstanceID);
					}
				}

				if ($this->getCategories(1)) {
					//Get paired devices
					if (false !== ($data = $this->lightifySocket->getPairedDevices()))
						if (strlen($data) > 60) $this->getDevices(substr($data, 11), ord($data{9})+ord($data{10})); //Renove 11 byte header
				}

				if ($this->getCategories(2)) {
					//Get group list
					if (false !== ($data = $this->lightifySocket->getGroupList()))
						if (strlen($data) > 28) $this->getGroups(substr($data, 11), ord($data{9})+ord($data{10})); //Renove 11 byte header
				}
			}
			return true;
		} else {
			IPS_LogMessage("SymconOSR", "Gateway data sync failed. Client socket not open!");
		}

		return false;
	}


	private function getDevices($data, $countDevice) {
		//IPS_LogMessage("SymconOSR", "Receive data: ".$this->lightifyBase->decodeData($data));
		$this->arrayDevices = array();

		for ($indexDevice = 1; $indexDevice <= $countDevice; $indexDevice++) {
			unset($deviceModel);
			unset($deviceLabel);

			$ModuleID = "";
			$CategoryID = 0;
			$apply = false;

			$MAC = substr($data, 2, 8);
			$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);
			$Name = trim(substr($data, 26, 15));
			$Firmware = sprintf("%02d%02d%02d%02d", ord($data{11}), ord($data{12}), ord($data{13}), 0);

			$Online = (ord($data{15}) == 0) ? false : true; //Offline: 0 - Online: 2
			$State = ($Online) ? ord($data{18}) : false;

			switch ($deviceType = ord($data{10})) {
				case 0:
					$deviceLabel = "";
					//fall-through 10

				case osrDeviceType::dtTW: //Lightify bulb - Tunable white
					$deviceLabel = (!isset($deviceLabel)) ? "Tunable White" : $deviceLabel;
					//fall-through 10

				case osrDeviceType::dtClear: //Lightify bulb - Clear
					$deviceLabel = (!isset($deviceLabel)) ? "White Clear" : $deviceLabel;
					//fall-through 10

				case osrDeviceType::dtRGBW: //Lightify bulb - RGBW
					if ($sync = $this->lightCategory['SyncID']) {
						$ModuleID = osrIPSModule::omLight; //Lightify Light
						$CategoryID = ($this->lightCategory['SyncID']) ? $this->lightCategory['CategoryID'] : 0;

						$deviceModel = "Osram Lightify Light";
						$deviceLabel = (!isset($deviceLabel)) ? "RGBW" : $deviceLabel;
					}
					break;

				case osrDeviceType::dtPlug:	//Lightify Plug
					if ($sync = $this->plugCategory['SyncID']) {
						$ModuleID = osrIPSModule::omPlug; //Lightify Plug
						$CategoryID = ($this->plugCategory['SyncID']) ? $this->plugCategory['CategoryID'] : 0;

						$deviceModel = "Osram Lightify Plug";
						$deviceLabel = "Plug/Power Socket";
					}
					break;

				case osrDeviceType::dtMotion:	//Lightify Motion
					if ($sync = $this->motionCategory['SyncID']) {
						$deviceModel = "Lightify Motion";
						$deviceLabel = "Osram Motion Sensor";
					}
					break;

				case osrDeviceType::dtDimmer:	//Lightify Dimmer - 2 buttons
					$deviceModel = "Osram Lightify Dimmer";
					$deviceLabel = "2 Button Dimmer";
					//fall-through 65

				case osrDeviceType::dtSwitch:	//Lightify Switch - 4 buttons
					if ($sync = $this->switchCategory['SyncID']) {
						$ModuleID = osrIPSModule::omSwitch; //Lightify Switch
						$CategoryID = ($this->switchCategory['SyncID']) ? $this->switchCategory['CategoryID'] : 0;

						$deviceModel = (!isset($deviceModel)) ? "Osram Lightify Switch" : $deviceModel;
						$deviceLabel = (!isset($deviceLabel)) ? "4 Button Switch" : $deviceLabel;
					}
					break;

				default:
					$CategoryID = 0;
			}

			if ($sync && $CategoryID > 0 && IPS_CategoryExists($CategoryID)) {
				if (!$DeviceID = $this->GetDeviceByUniqueID($UniqueID, $ModuleID)) {
					$DeviceID = IPS_CreateInstance($ModuleID);
					IPS_SetParent($DeviceID, $CategoryID);
					IPS_SetPosition($DeviceID, $indexDevice);
				}

				if (@IPS_GetName($DeviceID) != $Name) {
					IPS_SetName($DeviceID, (string)$Name);
					$apply = true;
				}

				if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
					IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
					$apply = true;
				}

				if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug) {
					if (@IPS_GetProperty($DeviceID, "DeviceID") != $indexDevice) {
						IPS_SetProperty($DeviceID, "DeviceID", (integer)$indexDevice);
						$apply = true;
					}
				}

				if (@IPS_GetProperty($DeviceID, "deviceModel") != $deviceModel) {
					IPS_SetProperty($DeviceID, "deviceModel", (string)$deviceModel);
					$apply = true;
				}

				if (@IPS_GetProperty($DeviceID, "deviceLabel") != $deviceLabel) {
					IPS_SetProperty($DeviceID, "deviceLabel", (string)$deviceLabel);
					$apply = true;
				}

				if (@IPS_GetProperty($DeviceID, "Firmware") != $Firmware) {
					IPS_SetProperty($DeviceID, "Firmware", (string)$Firmware);
					$apply = true;
				}

				if (@IPS_GetProperty($DeviceID, "deviceType") != $deviceType) {
					IPS_SetProperty($DeviceID, "deviceType", (integer)$deviceType);
					$apply = true;
				}

				//Connect device to gateway
				if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
					@IPS_DisconnectInstance($DeviceID);
					IPS_ConnectInstance($DeviceID, $this->InstanceID);
				}
				if ($apply) IPS_ApplyChanges($DeviceID);

				if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug) {
					$result = OSR_SendData($DeviceID, null, null, null, $data, true);
					$this->arrayDevices[] = array('DeviceID' => $DeviceID, 'UniqueID' => $UniqueID);
				}
			}

			$length = strlen($data);
			$data = ($length < 50) ? substr($data, $length) : substr($data, 50); //50 bytes per device
		}
	}


	private function getGroups($data, $countDevice) {
		//IPS_LogMessage("SymconOSR", "Receive data : ".$this->lightifyBase->decodeData($data));

		for ($indexDevice = 1; $indexDevice <= $countDevice; $indexDevice++) {
			$apply = false;

			$MAC = substr($data, 0, 2);
			$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);

			$Name = trim(substr($data, 2, 15));
			$DeviceID = $this->getDeviceByUniqueID($UniqueID, osrIPSModule::omGroup); //Lightify Group/Zone

			if ($DeviceID == 0) {
				$DeviceID = IPS_CreateInstance(osrIPSModule::omGroup); //Lightify Group/Zone
				IPS_SetParent($DeviceID, $this->groupCategory['CategoryID']);
				IPS_SetPosition($DeviceID, ord($MAC{0}));
			}

			if (@IPS_GetName($DeviceID) != $Name) {
				IPS_SetName($DeviceID, (string)$Name);
				$apply = true;
			}

			if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
				IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
				$apply = true;
			}

			if (@IPS_GetProperty($DeviceID, "Instances") != ($Instances = $this->setGroupInfo($DeviceID, $MAC))) {
				IPS_SetProperty($DeviceID, "Instances", (string)$Instances);
				$apply = true;
			}

			//Connect Group/zone to gateway
			if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
				@IPS_DisconnectInstance($DeviceID);
				IPS_ConnectInstance($DeviceID, $this->InstanceID);
			}
			if ($apply) IPS_ApplyChanges($DeviceID);

			$length = strlen($data);
			$data = ($length < 18) ? substr($data, $length) : substr($data, 18); //18 bytes per Group/Zone
		}
	}


	private function setGroupInfo($DeviceID, $MAC) {
		$Instances = array();

		if (false !== ($data = OSR_SendData($DeviceID, $this->lightifySocket, $MAC, osrIPSModule::omGroup, null, false))) {
			//IPS_LogMessage("SymconOSR", "Receive data : ".$this->lightifyBase->decodeData($data));

			if (strlen($data) > 27) {
				$countDevice = ord($data{27});
				$data = substr($data, 28); //Renove 28 byte header

				for ($indexDevice = 1; $indexDevice <= $countDevice; $indexDevice++) {
					$UniqueID = $this->lightifyBase->chrToUniqueID(substr($data, 0, 8));

					foreach ($this->arrayDevices as $item) {
						if ($item['UniqueID'] == $UniqueID)
							$Instances[] = array ('DeviceID' => $item['DeviceID']);
					}

					$length = strlen($data);
					$data = ($length < 8) ? substr($data, $length) : substr($data, 8); //Remove 8 bytes MAC
				}
			}
		}

		return json_encode($Instances);
	}


	private function getDeviceByUniqueID(string $UniqueID, $ModuleID) {
		foreach(IPS_GetInstanceListByModuleID($ModuleID) as $ids) {
			if (IPS_GetProperty($ids, "UniqueID") == $UniqueID) return $ids;
		}
	}

}
