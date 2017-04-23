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
		$this->RegisterTimer("gatewayTimer", 0, "OSR_SyncGateway($this->InstanceID, true, false);");
	}


	public function ApplyChanges() {
		$this->Host = "";
		$this->Port = 4000;

		parent::ApplyChanges();
		$this->configCheck();

		//IPS_LogMessage("SymconOSR", "Device list: ".$this->ReadPropertyString("Categories"));
		$Intervall = ($this->ReadPropertyBoolean("Open")) ? $this->ReadPropertyInteger("updateInterval")*1000 : 0;
		$this->SetTimerInterval("gatewayTimer", $Intervall);
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

		if ($this->lightifySocket == null)  {
			if ($host != "") return new lightifySocket($host, 4000);
			return false;
		}
	}


	public function SyncGateway(boolean $syncGroups, boolean $forceSync) {
		if ($this->ReadPropertyBoolean("Open")) {
			$this->lightifyBase = new lightifyBase;

			if ($this->lightifySocket = $this->openSocket()) {
				//Get gateway firmware version
				if (false !== ($data = $this->lightifySocket->getGatewayFirmware()) && strlen($data) > osrBufferByte::bbHeader) {
					$Firmware = ord($data{9}).".".ord($data{10}).".".ord($data{11}).".".ord($data{12});
					//IPS_LogMessage("SymconOSR", "Gateway firmware byte [01-".ord($data{9})."] [02-".ord($data{10})."] [03-".ord($data{11})."] [04-".ord($data{12})."] -> ".$Firmware);
				
					if ($this->ReadPropertyString("Firmware") != $Firmware) {
						IPS_SetProperty($this->InstanceID, "Firmware", (string)$Firmware);
						IPS_ApplyChanges($this->InstanceID);
					}
				}

				if ($this->getCategories(1) || $forceSync) {
					//Get paired devices
					if (false !== ($data = $this->lightifySocket->getPairedDevices()))
						if (strlen($data) > (osrBufferByte::bbHeader+3+osrBufferByte::bbDeviceString))
							$this->getDevices(substr($data, osrBufferByte::bbHeader+3), ord($data{9})+ord($data{10}));
				}

				if ($syncGroups && ($this->getCategories(2) || $forceSync)) {
					//Get group list
					if (false !== ($data = $this->lightifySocket->getGroupList()))
						if (strlen($data) > (osrBufferByte::bbHeader+3+osrBufferByte::bbGroupString))
							$this->getGroups(substr($data, osrBufferByte::bbHeader+3), ord($data{9})+ord($data{10}));
				}
			}

			return true;
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
			$applyChanges = false;

			$MAC = substr($data, 2, osrBufferByte::bbDeviceMAC);
			$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);
			$Name = trim(substr($data, 26, osrBufferByte::bbDeviceName));
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
					$applyChanges = true;
				}

				if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
					IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
					$applyChanges = true;
				}

				if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug) {
					if (@IPS_GetProperty($DeviceID, "DeviceID") != $indexDevice) {
						IPS_SetProperty($DeviceID, "DeviceID", (integer)$indexDevice);
						$applyChanges = true;
					}
				}

				if (@IPS_GetProperty($DeviceID, "deviceModel") != $deviceModel) {
					IPS_SetProperty($DeviceID, "deviceModel", (string)$deviceModel);
					$applyChanges = true;
				}

				if (@IPS_GetProperty($DeviceID, "deviceLabel") != $deviceLabel) {
					IPS_SetProperty($DeviceID, "deviceLabel", (string)$deviceLabel);
					$applyChanges = true;
				}

				if (@IPS_GetProperty($DeviceID, "Firmware") != $Firmware) {
					IPS_SetProperty($DeviceID, "Firmware", (string)$Firmware);
					$applyChanges = true;
				}

				if (@IPS_GetProperty($DeviceID, "deviceType") != $deviceType) {
					IPS_SetProperty($DeviceID, "deviceType", (integer)$deviceType);
					$applyChanges = true;
				}

				//Connect device to gateway
				if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
					@IPS_DisconnectInstance($DeviceID);
					IPS_ConnectInstance($DeviceID, $this->InstanceID);
				}

				if ($applyChanges) IPS_ApplyChanges($DeviceID);
				if (($length = strlen($data)) > osrBufferByte::bbDeviceString) $length = osrBufferByte::bbDeviceString;

				if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug) {
					$this->SendDataToChildren(json_encode(array(
						"DataID" => osrIPSModule::omDeviceTX,
						"ModuleID" => $ModuleID,
						"DeviceID" => $DeviceID,
						"deviceType" => $deviceType,
						"arrayDevices" => null,
						"Buffer" => utf8_encode(substr($data, 0, $length))))
					);

					$this->arrayDevices[] = array('DeviceID' => $DeviceID, 'UniqueID' => $UniqueID);
				}
			}
			
			$data = substr($data, $length);
		}
	}


	private function getGroups($data, $countDevice) {
		//IPS_LogMessage("SymconOSR", "Receive data : ".$this->lightifyBase->decodeData($data));

		for ($indexDevice = 1; $indexDevice <= $countDevice; $indexDevice++) {
			$applyChanges = false;

			$MAC = substr($data, 0, osrBufferByte::bbGroupMAC);
			$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);

			$Name = trim(substr($data, 2, osrBufferByte::bbGroupName));
			$DeviceID = $this->getDeviceByUniqueID($UniqueID, osrIPSModule::omGroup); //Lightify Group/Zone

			if ($DeviceID == 0) {
				$DeviceID = IPS_CreateInstance(osrIPSModule::omGroup); //Lightify Group/Zone
				IPS_SetParent($DeviceID, $this->groupCategory['CategoryID']);
				IPS_SetPosition($DeviceID, ord($MAC{0}));
			}

			if (@IPS_GetName($DeviceID) != $Name) {
				IPS_SetName($DeviceID, (string)$Name);
				$applyChanges = true;
			}

			if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
				IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
				$applyChanges = true;
			}

			//Connect Group/zone to gateway
			if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
				@IPS_DisconnectInstance($DeviceID);
				IPS_ConnectInstance($DeviceID, $this->InstanceID);
			}

			if ($applyChanges) IPS_ApplyChanges($DeviceID);
			if (($length = strlen($data)) > osrBufferByte::bbGroupString) $length = osrBufferByte::bbGroupString;

			$this->SendDataToChildren(json_encode(array(
				"DataID" => osrIPSModule::omGroupTX,
				"ModuleID" => osrIPSModule::omGroup,
				"DeviceID" => $DeviceID,
				"deviceType" => null,
				"arrayDevices" => json_encode($this->arrayDevices),
				"Buffer" => utf8_encode(substr($data, 0, $length))))
			);

			$data = substr($data, $length);
		}
	}


	private function getDeviceByUniqueID(string $UniqueID, $ModuleID) {
		foreach(IPS_GetInstanceListByModuleID($ModuleID) as $id) {
			if (@IPS_GetProperty($id, "UniqueID") == $UniqueID) return $id;
		}
	}

}
