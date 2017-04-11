<?

require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyBase.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."lightifySocket.php"); 


abstract class lightifyDevice extends IPSModule {
	
	private $ParentID = null;
	private $socket = null;


  public function __construct($InstanceID) {
    parent::__construct($InstanceID);
  }


  public function Create() {
		if (!IPS_VariableProfileExists("OSR.Hue")) {
			IPS_CreateVariableProfile("OSR.Hue", 1);
			IPS_SetVariableProfileDigits("OSR.Hue", 0);
			IPS_SetVariableProfileText("OSR.Hue", "", "°");
			IPS_SetVariableProfileValues("OSR.Hue", 0, 360, 1);
		}

		if (!IPS_VariableProfileExists("OSR.ColorTemperature")) {
			IPS_CreateVariableProfile("OSR.ColorTemperature", 1);
			IPS_SetVariableProfileIcon("OSR.ColorTemperature", "Intensity");
			IPS_SetVariableProfileDigits("OSR.ColorTemperature", 0);
			IPS_SetVariableProfileText("OSR.ColorTemperature", "", " K");
			IPS_SetVariableProfileValues("OSR.ColorTemperature", 2000, 8000, 1);
		}

    parent::Create();
  }


  abstract protected function GetUniqueID();
  

  public function ApplyChanges() {
    parent::ApplyChanges();
    $this->ConnectParent("{C3859938-D71C-4714-8B02-F2889A62F481}");
  }


  protected function GetSocket() {
	  if ($this->socket == null) {
		  $Instance = IPS_GetInstance($this->InstanceID);
			$this->ParentID = ($Instance['ConnectionID'] > 0) ? $Instance['ConnectionID'] : false;
    
			if ($this->ParentID) {
	    	$host = IPS_GetProperty($this->ParentID, "Host");
				if ($host != "") return new lightifySocket($host, IPS_GetProperty($this->ParentID, "Port"));
			}
			return false;
		}
		
		return $this->socket;
  }
  
  
  public function SendData($MAC, $ModuleID) {
	  $this->socket = $this->GetSocket();
	  
	  if ($this->socket) {
			switch ($ModuleID) {
				case "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}": //Lightify light
					//$buffer = $this->socket->SendData(chr(0x00), OSR_GETDEVICEINFO, $MAC);
					$buffer = $this->socket->DeviceInfo($MAC);
					return $this->SetDeviceInfo($buffer);

				case "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}": //Lightify group/zone
					//$buffer = $this->socket->SendData(chr(0x00), OSR_GETGROUPINFO, $MAC);
					$buffer = $this->socket->GroupInfo($MAC);
					return $buffer;
				
				case "{2C0FD8E7-345F-4F7A-AF7D-86DFB43FE46A}"; //Lightify switch
					return true;
			}
		}

		return false;
	}

	
	public function SetValue($key, $Value) {
		$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
		$Online = GetValueBoolean($OnlineID);
		$result = false;

		if ($Online) {
			$this->socket = $this->GetSocket();

			if ($this->socket) {
				$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
				$MAC = UniqueIDToChr(IPS_GetProperty($this->InstanceID, "UniqueID"));
				$flag = ($ModuleID == "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}") ? chr(0x00) : chr(0x02);

				$HueID = @IPS_GetObjectIDByIdent('HUE', $this->InstanceID);
				$StateID = IPS_GetObjectIDByIdent('STATE', $this->InstanceID);
				$ColorID = @IPS_GetObjectIDByIdent('COLOR', $this->InstanceID);
				$ColorTempID = @IPS_GetObjectIDByIdent('COLOR_TEMPERATURE', $this->InstanceID);
				$BrightID = @IPS_GetObjectIDByIdent('BRIGHTNESS', $this->InstanceID);
				$SaturationID = @IPS_GetObjectIDByIdent('SATURATION', $this->InstanceID);

				$Hue = ($HueID) ? GetValueInteger($HueID) : 0;
				$State = GetValueBoolean($StateID);
				$Color = ($ColorID) ? GetValueInteger($ColorID) : 0;
				$ColorTemp = ($ColorTempID) ? GetValueInteger($ColorTempID) : 0;
				$Bright = ($BrightID) ? GetValueInteger($BrightID) : 0;
				$Saturation = ($SaturationID) ? GetValueInteger($SaturationID) : 0;

				switch ($key) {
					case "ALL_LIGHTS":
						if ($Value == 0 || $Value == 1) {
							if ($this->socket->AllLights(($Value == 0) ? 0 : 1)) {
								foreach (IPS_GetInstanceListByModuleID("{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}") as $key) {
									$StateID = @IPS_GetObjectIDByIdent("STATE", $key);
									$OnlineID = @IPS_GetObjectIDByIdent("ONLINE", $key);
									$State = GetValueBoolean($StateID);
									$Online = GetValueBoolean($OnlineID);

									if ($Online) {
										if ($Value != $State) SetValueBoolean($StateID, (($Value == 0) ? false : true));
									}
								}
								$result = true;
							}
						}
						break;

					case "STATE":
						if ($Value == 0 || $Value == 1) {
							if ($Value != $State) {
								if ($this->socket->State($MAC, $flag, $Value)) { 	
									SetValueBoolean($StateID, $Value);
									$result = true;
								}
							}
						}
						break;

					case "COLOR":
						if ($HueID) {
							$hex = str_pad(dechex($Value), 6, 0, STR_PAD_LEFT);
							$rgb = HEX2RGB($hex);

							if ($Value != $Color && $hex != 64) {
								if ($this->socket->Color($MAC, $flag, $rgb)) { 
									$result = (is_array($this->SendData($MAC, $ModuleID))) ? true : false;
								}
							}
						}
						break;

					case "COLOR_TEMPERATURE":
						$minTemp = ($HueID) ? 2000 : 2700;
						$maxTemp = ($HueID) ? 6500 : 6500;

						if ($Value < $minTemp) {
							IPS_LogMessage("SymconOSR", "Color Temperature [".$Value."K] out of range. Setting to ".$minTemp."K");
							$Value = $minTemp;
						} elseif ($Value > $maxTemp) {
							IPS_LogMessage("SymconOSR", "Color Temperature [".$Value."K] out of range. Setting to ".$maxTemp."K");
							$Value = $maxTemp;
						}

						if ($Value != $ColorTemp) {
							if ($this->socket->ColorTemperature($MAC, $flag, $Value)) { 
								$result = (is_array($this->SendData($MAC, $ModuleID))) ? true : false;
							}
						}
						break;

					case "BRIGHTNESS":
						if ($Value < 0) {
							IPS_LogMessage("SymconOSR", "Brightness [".$Value."%] out of range. Setting to 0%");
							$Value = 0;
						} elseif ($Value > 100) {
							IPS_LogMessage("SymconOSR", "Brightness [".$Value."%] out of range. Setting to 100%");
							$Value = 100;
						}

						if ($Value != $Bright) {
							if ($this->socket->Brightness($MAC, $flag, $Value)) {
								SetValueInteger($BrightID, $Value);
								if ($Value == 0 && $State) SetValueBoolean($StateID, $Value);
								$result = true;
							}
						}
						break;

					case "SATURATION":
						if ($HueID) {
							if ($Value < 0) {
								IPS_LogMessage("SymconOSR", "Brightness [".$Value."%] out of range. Setting to 0%");
								$Value = 0;
							} elseif ($Value > 100) {
								IPS_LogMessage("SymconOSR", "Brightness [".$Value."%] out of range. Setting to 100%");
								$Value = 100;
							}
							$hex = HSV2HEX($Hue, $Value, $Bright);
							$rgb = HEX2RGB($hex);

							if ($Value != $Saturation && $hex != 64) {
								if ($this->socket->Saturation($MAC, $flag, $rgb)) {
									$result = (is_array($this->SendData($MAC, $ModuleID))) ? true : false;
								}
							}
						}
						break;
				}
			}
		}

		return $result;
	}


	public function GetValue($key) {
		$id = @IPS_GetObjectIDByIdent($key, $this->InstanceID);
		if ($id) return GetValue($id);
		
		return false;
	}


	public function GetValueEx($key) {
		$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
		$Online = GetValueBoolean($OnlineID);

		if ($Online) {
			$this->socket = $this->GetSocket();
					
			if ($this->socket) {
				$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
				$MAC = UniqueIDToChr($this->GetUniqueID());

				$list = $this->SendData($MAC, $ModuleID);
				if (is_array($list) && in_array($key, $list)) return $list[$key];
			}
		}
		
		return false;
	}

	
	public function SetColorCycle($Cycle, $Agility) {
		$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
		$Online = GetValueBoolean($OnlineID);

		if ($Online) {
			$this->socket = $this->GetSocket();

			if ($this->socket) {
				if (@IPS_GetObjectIDByIdent('HUE', $this->InstanceID)) {
					if ($Agility < 5) {
						IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to 5 sec");
						$Agility = 5;
					} elseif ($Agility > 65535) {
						IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to 65535 sec");
						$Agility = 65535;
					}

					if ($this->socket->Saturation(UniqueIDToChr($this->GetUniqueID()), $Cycle, $Agility)) return true;
				}
			}
		}
		
		return false;
	}
	

	public function SyncState() {
		$this->socket = $this->GetSocket();

		if ($this->socket) {
			$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
			$MAC = UniqueIDToChr($this->GetUniqueID());

			if (is_array($result = $this->SendData($MAC, $ModuleID))) {
				echo "Light state successfully synced!\n";
				return $result;
			}
		}
		
		echo "Light state sync failed!";
    IPS_LogMessage("SymconOSR", "Light state sync failed!");

		return false;
	}
	
		
	private function SetDeviceInfo($data) {
		$deviceIndex = IPS_GetProperty($this->InstanceID, "deviceIndex");
		$Online = (strlen($data) == 32) ? true : false;
    $result = false;

    if (!$StateID = @$this->GetIDForIdent("STATE")) {
      $StateID = $this->RegisterVariableBoolean("STATE", "State", "~Switch", 1);
      $this->EnableAction("STATE");
    }

    if (!$OnlineID = @$this->GetIDForIdent("ONLINE")) {
      $OnlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "", 2);
      $this->EnableAction("STATE");
    }

		if ($deviceIndex != 0 && $Online) {
			$data = substr($data, 9);
			$rgb = ord($data{16}).ord($data{17}).	ord($data{18});

			if ($deviceIndex == 10) {
				if (!$HueID = @$this->GetIDForIdent("HUE")) {
					$HueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 0);
					$this->EnableAction("HUE");
   			}
   	
	 			if (!$ColorID = @$this->GetIDForIdent("COLOR")) {
	 				$ColorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 3);
	 				$this->EnableAction("COLOR");
	 			}

	 			if (!$SaturationID = @$this->GetIDForIdent("SATURATION")) {
	 				$SaturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "~Intensity.100", 6);
	 				$this->EnableAction("SATURATION");
	 			}
	 				
				//$White = ord($data{19});
				$hex = RGB2HEX(array('r' => ord($data{16}), 'g' => ord($data{17}), 'b' => ord($data{18})));
				$hsv = HEX2HSV($hex);

				$Hue = $hsv['h'];
				$Color = hexdec($hex);
				$Saturation = $hsv['s'];

				$result = array('HUE' => $Hue, 'COLOR' => $Color, 'SATURATION' => $Saturation);
			}

	 		if ($deviceIndex == 2 || $deviceIndex == 10) {
   			if (!$ColorTempID = @$this->GetIDForIdent("COLOR_TEMPERATURE")) {
      		$ColorTempID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTemperature", 4);
					$this->EnableAction("COLOR_TEMPERATURE");
   			}
	
   			$ColorTemp = hexdec(dechex(ord($data{15})).dechex(ord($data{14})));
   			$result['COLOR_TEMPERATURE'] = $ColorTemp;
   		}

	 		if (!$BrightID = @$this->GetIDForIdent("BRIGHTNESS")) {
      	$BrightID = $this->RegisterVariableInteger("BRIGHTNESS", "Brightness", "~Intensity.100", 5);
				$this->EnableAction("BRIGHTNESS");
   		}
   		
			$Brightness = ord($data{13});
			$result['BRIGHTNESS'] = $Brightness;
		}

		$State = ($Online) ? ord($data{12}) : false;
		SetValueBoolean($StateID, $State);
		SetValueBoolean($OnlineID, $Online);

    if (@$ColorTempID) SetValueInteger($ColorTempID, $ColorTemp);
	  if (@$BrightID) SetValueInteger($BrightID, $Brightness);
    if (@$HueID) SetValueInteger($HueID, $Hue);
    if (@$ColorID) SetValueInteger($ColorID, $Color);
    if (@$SaturationID) SetValueInteger($SaturationID, $Saturation);

		$result['STATE'] = $State;
		$result['ONLINE'] = $Online;

		return $result;
	}
	
}
