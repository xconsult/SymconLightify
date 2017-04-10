<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyBase.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifySocket.php"); 


class lightifyGateway extends IPSModule {

	private $Host = "";
  private $Port = 4000;
  
  private $lightCategory = array();
  private $groupCategory = array();
  private $plugCategory = array();
  private $switchCategory = array();
  private $motionCategory = array();

	private $ModuleIDs = array(
		'lightifyLight' => "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}",
		'lightifyGroup' => "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}",
		'lightifySwitch' => "{2C0FD8E7-345F-4F7A-AF7D-86DFB43FE46A}"
	);
     
  private $LightIDs = array();  
  private $socket = null;


 	public function Create() {
    parent::Create();
    
    $this->RegisterPropertyString("Host", "");
    $this->RegisterPropertyInteger("Port", 4000);
    $this->RegisterPropertyInteger("updateInterval", 10);
    $this->RegisterPropertyString("Firmware", "");
		$this->RegisterPropertyBoolean("Open", false);
		
		$this->RegisterPropertyString("Categories", "");
	}


  public function ApplyChanges() {
	  $this->Host = "";
    $this->Port = 4000;

    parent::ApplyChanges();
		$this->Validate();

		$this->RegisterTimer("OSR_TIMER", $this->ReadPropertyInteger("updateInterval"), 'OSR_SyncDevices($_IPS[\'TARGET\'])');
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
				$id = $row->CategoryID;

				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements

				if ($id && IPS_ObjectExists($id)) {
					$path = "\\".IPS_GetName(0);
					
					while ($id = IPS_GetParent($id)) {
						if (IPS_GetObject($id)['ObjectType'] == 0) $path = $path."\\".IPS_GetName($id);
					}
					
					$data->elements[4]->values[] = array(
						"Device" => $Types[$key],
						"Category" => $path."\\".IPS_GetName($row->CategoryID),
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


  protected function RegisterTimer($Ident, $Interval, $Script) {
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
    if (!IPS_EventExists($id)) IPS_LogMessage("SymconOSR", "Ident with name [".$Ident."] used with wrong object type!");

    if ($Interval < 10) {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 10);
    } else {
      IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $Interval);
    }

    IPS_SetEventActive($id, $this->ReadPropertyBoolean("Open"));
  }

 
  private function Validate() {
	  if (filter_var($this->ReadPropertyString("Host"), FILTER_VALIDATE_IP) === false) 
	  	return $this->SetStatus(202);
	
    if ($this->ReadPropertyInteger("updateInterval") < 10)
      return $this->SetStatus(203);
    
		return $this->SetStatus(102);
  }
 
 
	private function GetCategories() {
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
 
 
  protected function GetSocket() {
	  if ($this->socket == null)  {
			$host = $this->ReadPropertyString("Host");
			if ($host != "") return new lightifySocket($host, $this->ReadPropertyInteger("Port"));
			return false;
		}
  } 	
	
	
	public function SyncDevices() {
		if ($this->ReadPropertyBoolean("Open")) {
			if ($this->socket = $this->GetSocket()) {			
				//Get gateway firmware version
				$buffer = $this->socket->GatewayFirmware();
			
				if (strlen($buffer) > 8) {
					$Firmware = ord($buffer{9}).".".ord($buffer{10}).".".ord($buffer{11}).".".ord($buffer{12});
					//IPS_LogMessage("SymconOSR", "Gateway firmware byte [01-".ord($buffer{9})."] [02-".ord($buffer{10})."] [03-".ord($buffer{11})."] [04-".ord($buffer{12})."] -> ".$Firmware);
				
					if ($this->ReadPropertyString("Firmware") != $Firmware) {
        		IPS_SetProperty($this->InstanceID, "Firmware", (string)$Firmware);
						IPS_ApplyChanges($this->InstanceID);
      		}
				}

				if ($this->GetCategories()) {
					//Get paired devices
					$buffer = $this->socket->PairedDevices();
					$this->LightIDs = array();
			
					if (strlen($buffer) > 60) {
						$cnt = ord($buffer{9})+ord($buffer{10});
						$buffer = substr($buffer, 11); //Renove 11 byte header

						for ($i = 0; $i < $cnt; $i++) {
							$this->GetDevices($buffer, $i+1);
							$buffer = substr($buffer, 50); //50 bytes per device
						}
					}
				}

				if ($this->groupCategory['CategoryID'] > 0 && $this->groupCategory['SyncID']) {
					//Get group list
					$buffer = $this->socket->GroupList();

					if (strlen($buffer) > 28) {
						$cnt = ord($buffer{9})+ord($buffer{10});
						$buffer = substr($buffer, 11); //Renove 11 byte header

						for ($i = 0; $i < $cnt; $i++) {
							$this->GetGroups($buffer);
							$buffer = substr($buffer, 18); //18 bytes per group/zone
						}				
					}
				}
			}

			return true;
		} else {
      IPS_LogMessage("SymconOSR", "Devices sync failed. Client socket not open!");
		}

		return false;
	}
	

	private function GetDevices($data, $idx) {
		$apply = false;
		
		$MAC = substr($data, 2, 8);
		$UniqueID = ChrToUniqueID($MAC);
		$Firmware = sprintf("%02d%02d%02d%02d", ord($data{11}), ord($data{12}), ord($data{13}), ord($data{14}).ord($data{15}));			
		$Name = trim(substr($data, 26, 15));
		$deviceType = ord($data{10});

		//IPS_LogMessage("SymconOSR", "Device type [".$Name."]: ".$deviceType);
		//IPS_LogMessage("SymconOSR", "Receive data [$Name]: ".DecodeData($data));
		//IPS_LogMessage("SymconOSR", "Device [$Name] firmware byte [01-".ord($data{11})."] [02-".ord($data{12})."] [03-".ord($data{13})."] [04-".ord($data{14})."] [05-".ord($data{15})."]: ".$Firmware);

			
		switch ($deviceType) {
			case 2: //Lightify bulb - Tunable white
				$deviceCapability = "Lightify bulb (Tunable White)";
							
			case 4: //Lightify bulb - Clear
				$deviceCapability = (!isset($deviceCapability)) ? "Lightify bulb (Clear)" : $deviceCapability;
				
			case 10: ////Lightify bulb - RGBW
				$ModuleID = $this->ModuleIDs['lightifyLight']; //Lightify light
				$CategoryID = ($this->lightCategory['SyncID']) ? $this->lightCategory['CategoryID'] : 0;
				$deviceCapability = (!isset($deviceCapability)) ? "Lightify bulb (RGBW)" : $deviceCapability;
				break;
				
			case 16:	//Lightify plug
				$deviceCapa = "Lightify plug/power socket";
				break;

			case 32:	//Lightify motion
				$deviceCapability = "Lightify motion sensor";
				break;
						
			case 64:	//Lightify switch - 2 buttons
				$deviceCapa = "Lightify switch (2 buttons)";
				
			case 65:	//Lightify switch - 4 buttons
				$ModuleID = $this->ModuleIDs['lightifySwitch']; //Lightify switch
				$CategoryID = ($this->switchCategory['SyncID']) ? $this->switchCategory['CategoryID'] : 0;
				$deviceCapability = (!isset($deviceCapability)) ? "Lightify switch (4 buttons)" : $deviceCapability;
				break;
					
			default:
				$CategoryID = 0;
		}

		if ($CategoryID > 0) {
			if (!$DeviceID = $this->GetDeviceByUniqueID($UniqueID, $ModuleID)) {
        $DeviceID = IPS_CreateInstance($ModuleID);
				IPS_SetParent($DeviceID, $CategoryID);
			}

			if (@IPS_GetName($DeviceID) != $Name) {
      	IPS_SetName($DeviceID, (string)$Name);
				$apply = true;
			}

			if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
				IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
				$apply = true;
			}

			if ($ModuleID == "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}") { //Lightify light
				if (@IPS_GetProperty($DeviceID, "LightID") != $idx) {
					IPS_SetProperty($DeviceID, "LightID", (integer)$idx);
					$apply = true;
				}
			}

			if (@IPS_GetProperty($DeviceID, "deviceCapability") != $deviceCapability) {
				IPS_SetProperty($DeviceID, "deviceCapability", (string)$deviceCapability);
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

			if ($ModuleID == $this->ModuleIDs['lightifyLight']) { //Lightify light
				if (is_array($result = OSR_SendData($DeviceID, $MAC, $ModuleID))) $this->LightIDs[] = array('DeviceID' => $DeviceID, 'UniqueID' => $UniqueID);
			}
    }
    
		return false;
	}


	private function GetGroups($data) {
		$apply = false;

		$MAC = substr($data, 0, 2);
		$UniqueID = ChrToUniqueID($MAC);
			
		$Name = trim(substr($data, 2, 15));
		$DeviceID = $this->GetDeviceByUniqueID($UniqueID, $this->ModuleIDs['lightifyGroup']); //Lightify group/zone
			
		if ($DeviceID == 0) {
    	$DeviceID = IPS_CreateInstance($this->ModuleIDs['lightifyGroup']); //Lightify group/zone
		 	IPS_SetParent($DeviceID, $this->groupCategory['CategoryID']);
		}

		if (@IPS_GetName($DeviceID) != $Name) {
    	IPS_SetName($DeviceID, (string)$Name);
			$apply = true;
		}

		if (@IPS_GetProperty($DeviceID, "UniqueID") != $UniqueID) {
			IPS_SetProperty($DeviceID, "UniqueID", (string)$UniqueID);
			$apply = true;
		}
		$Instances = $this->SetGroupInfo($DeviceID, $MAC);
			
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


	private function SetGroupInfo($DeviceID, $MAC) {
		$data = OSR_SendData($DeviceID, $MAC, $this->ModuleIDs['lightifyGroup']); //Lightify group/zone
		$Instances = array();

		if (strlen($data) > 10) {
			$cnt = ord($data{27});
			$data = substr($data, 28); //Renove 28 byte header

			for ($i = 0; $i < $cnt; $i++) {
				$UniqueID = ChrToUniqueID(substr($data, 0, 8));
				
				foreach ($this->LightIDs as $value) {
					if ($value['UniqueID'] == $UniqueID)
						$Instances[] = array ('DeviceID' => $value['DeviceID']);
				}
				$data = substr($data, 8); //Remove 8 bytes MAC
			}

		 	return json_encode($Instances);
		}
		
		return false;
	}
		

	private function GenerateRequestID() {
		$random = substr(str_shuffle('ABCDEF0123456789'), 0, 8);
		$id = "";

		for ($i = 0; $i < 8; $i += 2) {
			$id .= chr(ord(substr($random, $i, 2)));
		}
		return $id;
	}
	
	
  private function GetDeviceByUniqueID(string $UniqueID, $GUID) {
  	foreach(IPS_GetInstanceListByModuleID($GUID) as $key) {
      if (IPS_GetProperty($key, "UniqueID") == $UniqueID) return $key;
    }
  }

}
