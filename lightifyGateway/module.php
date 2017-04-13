<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyBase.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifySocket.php"); 


class lightifyGateway extends IPSModule {

	private $Host = "";
  private $Port = 4000;
  
  private $lightCategory = null;
  private $groupCategory = null;
  private $plugCategory = null;
  private $switchCategory = null;
  private $motionCategory = null;
     
  private $lightifyBase = null;
  private $lightifySocket = null;
  private $arrayLights = array();


	public function Create() {
    parent::Create();
    
    $this->RegisterPropertyString("Host", "");
    $this->RegisterPropertyInteger("Port", 4000);
    $this->RegisterPropertyInteger("updateInterval", 30);
    $this->RegisterPropertyString("Firmware", "");
		$this->RegisterPropertyBoolean("Open", false);
		
		$this->RegisterPropertyString("Categories", "");
	}


  public function ApplyChanges() {
	  $this->Host = "";
    $this->Port = 4000;

    parent::ApplyChanges();
		$this->configCheck();

		$this->registerTimer("OSR_TIMER", $this->ReadPropertyInteger("updateInterval"), 'OSR_SyncDevices($_IPS[\'TARGET\'])');
		//IPS_LogMessage("SymconOSR", "Device list: ".$this->ReadPropertyString("Categories"));
   }


	 public function GetConfigurationForm() {
	 	$data = json_decode(file_get_contents(__DIR__.DIRECTORY_SEPARATOR."form.json"));
	 	$Types = array("Light", "Group", "Plug", "Switch", "Motion");
			
		//Only add default element if we do not have anything in persistence
		if ($this->ReadPropertyString("Categories") == "") {
			foreach ($Types as $value) {
				$data->elements[4]->values[] = array(
					"Device" => $value,
					"CategoryID" => 0, 
					"Category" => "select ...",
					"Sync" => "no",
					"SyncID" => false				
				);
			}
		} else {
			//Annotate existing elements
			$Categories = json_decode($this->ReadPropertyString("Categories"));

			foreach ($Categories as $key => $row) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				if ($row->CategoryID && IPS_ObjectExists($row->CategoryID)) {
					$data->elements[4]->values[] = array(
						"Device" => $Types[$key],
						"Category" => IPS_GetName(0)."\\".IPS_GetLocation($row->CategoryID),
						"Sync" => ($row->SyncID) ? "yes" : "no"
					);
				} else {
					$data->elements[4]->values[] = array(
						"Device" => $Types[$key],
						"Category" => "select ...",
						"Sync" => "no"
					);
				}						
			}			
		}

		return json_encode($data);
	}


  protected function registerTimer($Ident, $Interval, $Script) {
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

    if ($Interval < 10) {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 10);
    } else {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $Interval);
    }

    IPS_SetEventActive($id, $this->ReadPropertyBoolean("Open"));
  }

 
  private function configCheck() {
	  if (filter_var($this->ReadPropertyString("Host"), FILTER_VALIDATE_IP) === false) 
	  	return $this->SetStatus(202);
	
    if ($this->ReadPropertyInteger("updateInterval") < 10)
      return $this->SetStatus(203);
    
		return $this->SetStatus(102);
  }
 
 
	private function getCategories() {
		if ($this->lightCategory == null || $this->groupCategory == null || $this->plugCategory == null || $this->switchCategory == null || $this->motionCategory == null) {
			$deviceCategory = json_decode($this->ReadPropertyString("Categories"), true);
		
			list(
				$this->lightCategory, 
				$this->groupCategory, 
				$this->plugCategory, 
				$this->switchCategory,
				$this->motionCategory
			) = $deviceCategory;
		}
		
		if ($this->lightCategory['CategoryID'] > 0 && $this->lightCategory['SyncID']) return true;
		if ($this->plugCategory['CategoryID'] > 0 && $this->plugCategory['SyncID']) return true;
		if ($this->switchCategory['CategoryID'] > 0 && $this->switchCategory['SyncID']) return true;
		if ($this->motionCategory['CategoryID'] > 0 && $this->motionCategory['SyncID']) return true;
	
		return false;
	}
 
 
  protected function openSocket() {
	  if ($this->lightifySocket == null)  {
			$host = $this->ReadPropertyString("Host");
			if ($host != "") return new lightifySocket($host, $this->ReadPropertyInteger("Port"));
			
			return false;
		}
  } 	
	
	
	public function syncDevices() {
		if ($this->ReadPropertyBoolean("Open")) {
			$this->lightifyBase = new lightifyBase;	 			

			if ($this->lightifySocket = $this->openSocket()) {
				//Get gateway firmware version			
				if (false !== ($buffer = $this->lightifySocket->getGatewayFirmware()) && strlen($buffer) > 8) {
					$Firmware = ord($buffer{9}).".".ord($buffer{10}).".".ord($buffer{11}).".".ord($buffer{12});
					//IPS_LogMessage("SymconOSR", "Gateway firmware byte [01-".ord($buffer{9})."] [02-".ord($buffer{10})."] [03-".ord($buffer{11})."] [04-".ord($buffer{12})."] -> ".$Firmware);
				
					if ($this->ReadPropertyString("Firmware") != $Firmware) {
        		IPS_SetProperty($this->InstanceID, "Firmware", (string)$Firmware);
						IPS_ApplyChanges($this->InstanceID);
      		}
				}

				if ($this->getCategories()) {
					//Get paired devices
					$this->arrayLights = array();
			
					if (false !== ($buffer = $this->lightifySocket->getPairedDevices()) && strlen($buffer) > 60) {
						$cnt = ord($buffer{9})+ord($buffer{10});
						$buffer = substr($buffer, 11); //Renove 11 byte header

						for ($i = 0; $i < $cnt; $i++) {
							$this->getDevices($buffer, $i+1);
							$buffer = substr($buffer, 50); //50 bytes per device
						}
					}
				}

				if ($this->groupCategory['CategoryID'] > 0 && $this->groupCategory['SyncID']) {
					//Get group list
					if (false !== ($buffer = $this->lightifySocket->getGroupList()) && strlen($buffer) > 28) {
						$cnt = ord($buffer{9})+ord($buffer{10});
						$buffer = substr($buffer, 11); //Renove 11 byte header

						for ($i = 0; $i < $cnt; $i++) {
							$this->getGroups($buffer);
							$buffer = substr($buffer, 18); //18 bytes per group/zone
						}				
					}
				}
			}
			return true;
		} else {
      IPS_LogMessage("SymconOSR", "Devices sync failed. Client lightifySocket not open!");
		}

		return false;
	}
	

	private function getDevices($data, $idx) {
		$apply = false;
		
		$MAC = substr($data, 2, 8);
		$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);
		$Name = trim(substr($data, 26, 15));
		$Firmware = sprintf("%02d%02d%02d%02d", ord($data{11}), ord($data{12}), ord($data{13}), 0);
	
		$Online = (ord($data{15}) == 0) ? false : true; //Offline: 0 - Online: 2
		$State = ($Online) ? ord($data{18}) : false;
				
		//IPS_LogMessage("SymconOSR", "Receive data [$Name]: ".DecodeData($data));
		//IPS_LogMessage("SymconOSR", "Device [$Name] firmware byte [01-".ord($data{11})."] [02-".ord($data{12})."] [03-".ord($data{13})."] [04-".ord($data{14})."]: ".$Firmware);
			
		switch ($deviceIndex = ord($data{10})) {
			case 0:
				$deviceType = "";

			case 2: //Lightify bulb - Tunable white
				$deviceType = (!isset($deviceType)) ? "Tunable White" : $deviceType;
							
			case 4: //Lightify bulb - Clear
				$deviceType = (!isset($deviceType)) ? "White Clear" : $deviceType;
				
			case 10: ////Lightify bulb - RGBW
				if ($sync = $this->lightCategory['SyncID']) {
					$ModuleID = lightifyModules::lightifyLight; //Lightify Light
					$CategoryID = ($this->lightCategory['SyncID']) ? $this->lightCategory['CategoryID'] : 0;
				
					$deviceModel = "LIGHTIFY Light";
					$deviceType = (!isset($deviceType)) ? "RGBW" : $deviceType;
				}
				break;
				
			case 16:	//Lightify plug
				if ($sync = $this->plugCategory['SyncID']) {
					$deviceModel = "LIGHTIFY Plug";
					$deviceType = "Plug/Power lightifySocket";
				}
				break;

			case 32:	//Lightify motion
				if ($sync = $this->motionCategory['SyncID']) {
					$deviceModel = "LIGHTIFY Motion";
					$deviceType = "Motion Sensor";
				}
				break;
						
			case 64:	//Lightify switch - 2 buttons
				$deviceType = "2 Button Switch";
				
			case 65:	//Lightify switch - 4 buttons
				if ($sync = $this->switchCategory['SyncID']) {
					$ModuleID = lightifyModules::lightifySwitch; //Lightify Switch
					$CategoryID = ($this->switchCategory['SyncID']) ? $this->switchCategory['CategoryID'] : 0;

					$deviceModel = "LIGHTIFY Switch";
					$deviceType = (!isset($deviceType)) ? "4 Button Switch" : $deviceType;
				}
				break;
					
			default:
				$CategoryID = 0;
		}

		//IPS_LogMessage("SymconOSR", "Device id [$Name] byte [00-".ord($data{0})."] [01-".ord($data{1})."]");
		//IPS_LogMessage("SymconOSR", "Device index [$Name]: ".$deviceIndex."  type: ".$deviceType);

		if ($sync && $CategoryID > 0 && IPS_CategoryExists($CategoryID)) {
			if (!$DeviceID = $this->GetDeviceByUniqueID($UniqueID, $ModuleID)) {
        $DeviceID = IPS_CreateInstance($ModuleID);
				IPS_SetParent($DeviceID, $CategoryID);
				IPS_SetPosition($DeviceID, $idx);
			}

			if (@IPS_GetName($DeviceID) != $Name) {
      	IPS_SetName($DeviceID, (string)$Name);
				$apply = true;
			}

			if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
				IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
				$apply = true;
			}

			if ($ModuleID == lightifyModules::lightifyLight) { //Lightify Light
				if (@IPS_GetProperty($DeviceID, "LightID") != $idx) {
					IPS_SetProperty($DeviceID, "LightID", (integer)$idx);
					$apply = true;
				}
			}

			if (@IPS_GetProperty($DeviceID, "deviceModel") != $deviceModel) {
				IPS_SetProperty($DeviceID, "deviceModel", (string)$deviceModel);
				$apply = true;
			}

			if (@IPS_GetProperty($DeviceID, "deviceType") != $deviceType) {
				IPS_SetProperty($DeviceID, "deviceType", (string)$deviceType);
				$apply = true;
			}
				
			if (@IPS_GetProperty($DeviceID, "Firmware") != $Firmware) {
				IPS_SetProperty($DeviceID, "Firmware", (string)$Firmware);
				$apply = true;
			}
			
			if (@IPS_GetProperty($DeviceID, "deviceIndex") != $deviceIndex) {
				IPS_SetProperty($DeviceID, "deviceIndex", (integer)$deviceIndex);
				$apply = true;
			}

			//Connect device to gateway
			if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
				@IPS_DisconnectInstance($DeviceID);
				IPS_ConnectInstance($DeviceID, $this->InstanceID);
			}
			
			if ($apply) IPS_ApplyChanges($DeviceID);
			$result = OSR_SendData($DeviceID, $this->lightifySocket, $MAC, $ModuleID);
			
			if ($ModuleID == lightifyModules::lightifyLight) { //Lightify Light
				$this->arrayLights[] = array('DeviceID' => $DeviceID, 'UniqueID' => $UniqueID);
			}
	  	
    	return true;
    }

		return false;
	}


	private function getGroups($data) {
		$CategoryID = $this->groupCategory['CategoryID'];
		
		if ($CategoryID > 0 && IPS_CategoryExists($CategoryID)) {
			$apply = false;

			$MAC = substr($data, 0, 2);
			$UniqueID = $this->lightifyBase->chrToUniqueID($MAC);
			
			$Name = trim(substr($data, 2, 15));
			$DeviceID = $this->getDeviceByUniqueID($UniqueID, lightifyModules::lightifyGroup); //Lightify Group/Zone
			
			if ($DeviceID == 0) {
    		$DeviceID = IPS_CreateInstance(lightifyModules::lightifyGroup); //Lightify Group/Zone
				IPS_SetParent($DeviceID, $CategoryID);
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
			$Instances = $this->setGroupInfo($DeviceID, $MAC);
			
			if (@IPS_GetProperty($DeviceID, "Instances") != $Instances) {
				IPS_SetProperty($DeviceID, "Instances", (string)$Instances);
				$apply = true;
			}

			//Connect Group/zone to gateway
			if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
				@IPS_DisconnectInstance($DeviceID);
				IPS_ConnectInstance($DeviceID, $this->InstanceID);
    	}

			if ($apply) IPS_ApplyChanges($DeviceID);
			return true;
		}
		
		return false;
	}


	private function setGroupInfo($DeviceID, $MAC) {
		$Instances = array();

		if (false !== ($data = OSR_SendData($DeviceID, $this->lightifySocket, $MAC, lightifyModules::lightifyGroup)) && strlen($data) > 10) {
			$cnt = ord($data{27});
			$data = substr($data, 28); //Renove 28 byte header

			for ($i = 0; $i < $cnt; $i++) {
				$UniqueID = $this->lightifyBase->chrToUniqueID(substr($data, 0, 8));
				
				foreach ($this->arrayLights as $value) {
					if ($value['UniqueID'] == $UniqueID)
						$Instances[] = array ('DeviceID' => $value['DeviceID']);
				}
				$data = substr($data, 8); //Remove 8 bytes MAC
			}

		 	return json_encode($Instances);
		}
		
		return false;
	}
	
	
  private function getDeviceByUniqueID(string $UniqueID, $GUID) {
  	foreach(IPS_GetInstanceListByModuleID($GUID) as $key) {
      if (IPS_GetProperty($key, "UniqueID") == $UniqueID) return $key;
    }
  }

}
