<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyBase.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifySocket.php"); 


class lightifyGateway extends IPSModule {

	private $Host = "";
  private $Port = 4000;
  
  private $deviceCategory = 0;
  private $lightCategory = 0;
  private $groupCategory = 0;
  private $plugCategory = 0;
  private $switchCategory = 0;
      
  private $Lights = array();  
  private $socket = null;


 	public function Create() {
    parent::Create();
    
    $this->RegisterPropertyString("Host", "");
    $this->RegisterPropertyInteger("Port", 4000);
    $this->RegisterPropertyInteger("updateInterval", 10);
    $this->RegisterPropertyString("Firmware", "");
		$this->RegisterPropertyBoolean("Open", false);
		
		$this->RegisterPropertyInteger("deviceCategory", 0);
		$this->RegisterPropertyInteger("lightCategory", 0);
		$this->RegisterPropertyInteger("groupCategory", 0);
		$this->RegisterPropertyInteger("plugCategory", 0);
		$this->RegisterPropertyInteger("switchCategory", 0);
	}


  public function ApplyChanges() {
	  $this->Host = "";
    $this->Port = 4000;

    parent::ApplyChanges();
		$this->Validate();

		$this->RegisterTimer("OSR_TIMER", $this->ReadPropertyInteger("updateInterval"), 'OSR_SyncDevices($_IPS[\'TARGET\'])');
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
    
    if ($this->ReadPropertyInteger("deviceCategory") == 0)
	  	return $this->SetStatus(104);

		return $this->SetStatus(102);
  }
 
 
	private function CheckCategory() {
 		//Create device categories
		$this->deviceCategory = $this->ReadPropertyInteger("deviceCategory");
		$apply = false;
		
		if (!$this->lightCategory = @IPS_GetCategoryIDByName("Light", $this->deviceCategory)) {
			$id = IPS_CreateCategory();
			IPS_SetProperty($this->InstanceID, "lightCategory", $id);
			
			IPS_SetName($id, "Light");
			IPS_SetParent($id, $this->deviceCategory);
			IPS_SetPosition ($id, 101);
			
			$this->lightCategory = $id;
			$apply = true;
		}

		if (!$this->groupCategory = @IPS_GetCategoryIDByName("Group", $this->deviceCategory)) {
			$id = IPS_CreateCategory();
			IPS_SetProperty($this->InstanceID, "groupCategory", $id);
						
			IPS_SetName($id, "Group");
			IPS_SetParent($id, $this->deviceCategory);
			IPS_SetPosition ($id, 102);

			$this->groupCategory = $id;
			$apply = true;
		}

		if (!$this->plugCategory = @IPS_GetCategoryIDByName("Plug", $this->deviceCategory)) {
			$id = IPS_CreateCategory();
			IPS_SetProperty($this->InstanceID, "plugCategory", $id);
			
			IPS_SetName($id, "Plug");
			IPS_SetParent($id, $this->deviceCategory);
			IPS_SetPosition ($id, 103);

			$this->plugCategory = $id;			
			$apply = true;
		}

		if (!$this->switchCategory = @IPS_GetCategoryIDByName("Switch", $this->deviceCategory)) {
			$id = IPS_CreateCategory();
			IPS_SetProperty($this->InstanceID, "switchCategory", $id);
			
			IPS_SetName($id, "Switch");
			IPS_SetParent($id, $this->deviceCategory);
			IPS_SetPosition ($id, 104);

			$this->switchCategory = $id;
			$apply = true;
		}

		if ($apply) IPS_ApplyChanges($this->InstanceID);
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
			$this->CheckCategory();
			$this->socket = $this->GetSocket();

			if ($this->socket) {			
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

				//Get paired devices
				$buffer = $this->socket->PairedDevices();
				$this->Lights = array();
			
				if (strlen($buffer) > 60) {
					$cnt = ord($buffer{9})+ord($buffer{10});
					$buffer = substr($buffer, 11); //Renove 11 byte header

					for ($i = 0; $i < $cnt; $i++) {
						$this->GetDevices($buffer, $i+1);
						$buffer = substr($buffer, 50); //50 bytes per device
					}
				}

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
				
				//echo "Devices successfully synced!";
				return true;
			}
		} else {
      IPS_LogMessage("SymconOSR", "Devices sync failed. Client socket is not open!");
		}

		return false;
	}
	

	private function GetDevices($data, $idx) {
		$apply = false;

		if (@$this->deviceCategory > 0) {			
			$MAC = substr($data, 2, 8);
			$UniqueID = ChrToUniqueID($MAC);
			$Firmware = sprintf("%02d%02d%02d%02d", ord($data{11}), ord($data{12}), ord($data{13}), ord($data{14}).ord($data{15}));			
			$Name = trim(substr($data, 26, 15));
			$Type = ord($data{10});

			//IPS_LogMessage("SymconOSR", "Receive data [$Name]: ".PrintBuffer($data));
			//IPS_LogMessage("SymconOSR", "Device [$Name] firmware byte [01-".ord($data{11})."] [02-".ord($data{12})."] [03-".ord($data{13})."] [04-".ord($data{14})."] [05-".ord($data{15})."]: ".$Firmware);

			
			switch ($Type) {
				case 2: 	//Classic A60 Tunable white
				case 10:	//Classic A60 RGBW
					$ModuleID = "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}"; //Lightify light
					$CategoryID = $this->lightCategory;
					$Capability = ($Type == 2) ? "Lightify bulb (Tunable White)" : "Lightify bulb (RGBW)";
					break;
				
				case 16:	//Lightify plug
					break;

				case 32:	//Lightify motion
					break;
						
				case 64:	//Lightify switch 2x
				case 65:	//Lightify switch 4x
					$ModuleID = "{2C0FD8E7-345F-4F7A-AF7D-86DFB43FE46A}"; //Lightify switch
					$CategoryID = $this->switchCategory;
					$Capability = ($Type == 64) ? "Lightify switch (2 buttons)" : "Lightify switch (4 buttons)";
					break;
			}
				
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

			if (@IPS_GetProperty($DeviceID, "Type") != $Capability) {
				IPS_SetProperty($DeviceID, "Type", (string)$Capability);
				$apply = true;
			}
				
			if (@IPS_GetProperty($DeviceID, "Firmware") != $Firmware) {
				IPS_SetProperty($DeviceID, "Firmware", (string)$Firmware);
				$apply = true;
			}
				
			//Connect device to gateway
			if (IPS_GetInstance($DeviceID)['ConnectionID'] <> $this->InstanceID) {
				@IPS_DisconnectInstance($DeviceID);
				IPS_ConnectInstance($DeviceID, $this->InstanceID);
			}
			
			if ($apply) IPS_ApplyChanges($DeviceID);			
			if ($ModuleID == "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}") { //Lightify light
				if (is_array($result = OSR_SendData($DeviceID, $MAC, $ModuleID))) $this->Lights[] = array('DeviceID' => $DeviceID, 'UniqueID' => $UniqueID);
    	}
    }
    
		return false;
	}


	private function GetGroups($data) {
		$apply = false;

		if (@$this->deviceCategory > 0) {
			$MAC = substr($data, 0, 2);
			$UniqueID = ChrToUniqueID($MAC);
			
			$Name = trim(substr($data, 2, 15));
			$DeviceID = $this->GetDeviceByUniqueID($UniqueID, "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}"); //Lightify group/zone
			
			if ($DeviceID == 0) {
      	$DeviceID = IPS_CreateInstance("{7B315B21-10A7-466B-8F86-8CF069C3F7A2}"); //Lightify group/zone
			 	IPS_SetParent($DeviceID, $this->groupCategory);
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
    } 
  
   	return false;
	}


	private function SetGroupInfo($DeviceID, $MAC) {
		$data = OSR_SendData($DeviceID, $MAC, "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}"); //Lightify group/zone

		if (strlen($data) > 10) {
			$cnt = ord($data{27});
			$data = substr($data, 28); //Renove 28 byte header

			for ($i = 0; $i < $cnt; $i++) {
				$UniqueID = ChrToUniqueID(substr($data, 0, 8));
				
				foreach ($this->Lights as $key => $value) {
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
	
	
	private function DeviceType($type) {
		IPS_LogMessage("SymconOSR", "Device type: ".$type);

		switch ($type) {
			case 2: 	//Classic A60 Tunable white
			case 10:	//Classic A60 RGBW
				return $this->ReadPropertyInteger("LightsCategory");
				
			case 16:	//Lightify plug
				break;

			case 32:	//Lightify motion
				break;
						
			case 64:	//Lightify switch 2x
				break;

			case 65:	//Lightify switch 4x
				break;
		}
	}

	
  private function GetDeviceByUniqueID(string $UniqueID, $GUID) {
  	foreach(IPS_GetInstanceListByModuleID($GUID) as $key) {
      if (IPS_GetProperty($key, "UniqueID") == $UniqueID) return $key;
    }
  }

}
