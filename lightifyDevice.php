<?

require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyBase.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."lightifySocket.php"); 


abstract class lightifyDevice extends IPSModule {
	
	private $lightifyBase = null;
	private $lightifySocket = null;
	private $ParentID = null;
	

  public function __construct($InstanceID) {
    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;
  }


  public function Create() {
		if (!IPS_VariableProfileExists("OSR.Hue")) {
			IPS_CreateVariableProfile("OSR.Hue", lightifyTypes::vtInteger);
			IPS_SetVariableProfileDigits("OSR.Hue", 0);
			IPS_SetVariableProfileText("OSR.Hue", "", "°");
			IPS_SetVariableProfileValues("OSR.Hue", 0, 360, 1);
		}

		if (!IPS_VariableProfileExists("OSR.ColorTemperature")) {
			IPS_CreateVariableProfile("OSR.ColorTemperature", lightifyTypes::vtInteger);
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
    $this->ConnectParent(lightifyModules::lightifyGateway);
  }


  protected function GetSocket() {
	  if ($this->lightifySocket == null) {
		  $Instance = IPS_GetInstance($this->InstanceID);
			$this->ParentID = ($Instance['ConnectionID'] > 0) ? $Instance['ConnectionID'] : false;
    
			if ($this->ParentID) {
	    	$host = IPS_GetProperty($this->ParentID, "Host");
				if ($host != "") return new lightifySocket($host, IPS_GetProperty($this->ParentID, "Port"));
			}
			return false;
		}
		
		return $this->lightifySocket;
  }
  
  
  public function SendData($socket, $MAC, $ModuleID) {
	  $this->lightifySocket = $socket;
	  
	  if ($this->lightifySocket = $this->GetSocket()) {
			switch ($ModuleID) {
				case lightifyModules::lightifyLight: //Lightify Light
					$buffer = $this->lightifySocket->DeviceInfo($MAC);
					$length = strlen($buffer);
					
					if ($length == 2) {
						IPS_LogMessage("SymconOSR", "Light [$MAC] not registered on gateway!");
						return false;
					} elseif ($length == 20 || $length == 32) {
						return $this->SetDeviceInfo($buffer);
					} else {
						return false;
					}

				case lightifyModules::lightifyGroup: //Lightify Group/Zone
					$buffer = $this->lightifySocket->GroupInfo($MAC);
					$length = strlen($buffer);
					
					if ($length == 2) {
						IPS_LogMessage("SymconOSR", "Group/Zone [$MAC] not registered on gateway!");
						return false;
					} else {
						return $buffer;
					}
				
				case lightifyModules::lightifySwitch; //Lightify Switch
					return false;
			}
		}
	}

	
	public function SetValue($key, $Value) {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
		$result = false;

		if ($ModuleID == lightifyModules::lightifyLight || $ModuleID == lightifyModules::lightifyGroup) {
			$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$Online = GetValueBoolean($OnlineID);

			if ($Online) {
				$this->lightifySocket = $this->GetSocket();

				if ($this->lightifySocket) {
					$MAC = $this->lightifyBase->UniqueIDToChr(IPS_GetProperty($this->InstanceID, "UniqueID"));
					$flag = ($ModuleID == lightifyModules::lightifyLight) ? chr(0x00) : chr(0x02);

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
								if ($this->lightifySocket->AllLights(($Value == 0) ? 0 : 1)) {
									foreach (IPS_GetInstanceListByModuleID(lightifyModules::lightifyLight) as $key) {
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
									if ($this->lightifySocket->State($MAC, $flag, $Value)) { 	
										SetValueBoolean($StateID, $Value);
										$result = true;
									}
								}
							}
							break;

						case "COLOR":
							if ($HueID) {
								$hex = str_pad(dechex($Value), 6, 0, STR_PAD_LEFT);
								$rgb = $this->lightifyBase->HEX2RGB($hex);

								if ($Value != $Color && $hex != 64) {
									if ($this->lightifySocket->Color($MAC, $flag, $rgb)) { 
										$result = (is_array($this->SendData($this->lightifySocket, $MAC, $ModuleID))) ? true : false;
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
								if ($this->lightifySocket->ColorTemperature($MAC, $flag, $Value)) { 
									$result = (is_array($this->SendData($this->lightifySocket, $MAC, $ModuleID))) ? true : false;
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
								if ($this->lightifySocket->Brightness($MAC, $flag, $Value)) {
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
								$hex = $this->lightifyBase->HSV2HEX($Hue, $Value, $Bright);
								$rgb = $this->lightifyBase->HEX2RGB($hex);

								if ($Value != $Saturation && $hex != 64) {
									if ($this->lightifySocket->Saturation($MAC, $flag, $rgb)) {
										$result = (is_array($this->SendData($this->lightifySocket, $MAC, $ModuleID))) ? true : false;
									}
								}
							}
							break;
					}
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
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == lightifyModules::lightifyLight) {
			$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$Online = GetValueBoolean($OnlineID);

			if ($Online) {
				if ($this->lightifySocket = $this->GetSocket()) {
					$MAC = $this->lightifyBase->UniqueIDToChr($this->GetUniqueID());
					$list = $this->SendData($this->lightifySocket, $MAC, $ModuleID);
					if (is_array($list) && in_array($key, $list)) return $list[$key];
				}
			}
		}
		
		return false;
	}

	
	public function SetColorCycle($Cycle, $Agility) {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == lightifyModules::lightifyLight) {
			$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$Online = GetValueBoolean($OnlineID);

			if ($Online) {
				if ($this->lightifySocket = $this->GetSocket()) {
					if (@IPS_GetObjectIDByIdent('HUE', $this->InstanceID)) {
						if ($Agility < 5) {
							IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to 5 sec");
							$Agility = 5;
						} elseif ($Agility > 65535) {
							IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to 65535 sec");
							$Agility = 65535;
						}

						if ($this->lightifySocket->Saturation($this->lightifyBase->UniqueIDToChr($this->GetUniqueID()), $Cycle, $Agility)) return true;
					}
				}
			}
		}
		
		return false;
	}
	

	public function SyncState() {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == lightifyModules::lightifyLight) {
			if ($this->lightifySocket = $this->GetSocket()) {
				$MAC = $this->lightifyBase->UniqueIDToChr($this->GetUniqueID());

				if (is_array($result = $this->SendData($this->lightifySocket, $MAC, $ModuleID))) {
					echo "Light state successfully synced!\n";
					return $result;
				}
			}
		
			echo "Light state sync failed!";
			IPS_LogMessage("SymconOSR", "Light state sync failed!");
		}
		
		return false;
	}
	
		
	private function SetDeviceInfo($data) {
		//IPS_LogMessage("SymconOSR", "Device ".IPS_GetName($this->InstanceID)." length: ".strlen($data)."  online: ".ord($data{19})."  state: ".((ord($data{19}) == 0) ? ord($data{21}) : "0"));
		$Mode = ord($data{19});
		$result = false;
			
		if ($Mode == 0 || $Mode == 255) {
			$deviceIndex = IPS_GetProperty($this->InstanceID, "deviceIndex");
			$Online = ($Mode == 0) ? true : false; //Online: 0 - Offline: 255
			$State = ($Online) ? ord($data{21}) : false;
			
			if (!$OnlineID = @$this->GetIDForIdent("ONLINE")) {
    		$OnlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "", 1);
				$this->EnableAction("ONLINE");
    	}

			if (isset($OnlineID)) {
				if (GetValueBoolean($OnlineID) != $Online) SetValueBoolean($OnlineID, $Online);
				$result['ONLINE'] = $Online;
		 	}
		
		 	if (!$StateID = @$this->GetIDForIdent("STATE")) {
    		$StateID = $this->RegisterVariableBoolean("STATE", "State", "~Switch", 2);
				$this->EnableAction("STATE");
    	}

			if (isset($StateID)) {
				if (GetValueBoolean($StateID) != $State) SetValueBoolean($StateID, $State);
				$result['STATE'] = $State;
			}

			if ($deviceIndex != 0 && $Online) {
				$data = substr($data, 9);
				$rgb = ord($data{16}).ord($data{17}).	ord($data{18});

				if ($deviceIndex == 10) {
					//$Alpha = ord($data{19});
					$hex = $this->lightifyBase->RGB2HEX(array('r' => ord($data{16}), 'g' => ord($data{17}), 'b' => ord($data{18})));
					$hsv = $this->lightifyBase->HEX2HSV($hex);

					if (!$HueID = @$this->GetIDForIdent("HUE")) {
						$HueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 0);
						$this->EnableAction("HUE");
   				}
   			
	 				if (isset($HueID)) {
	   				$Hue = $hsv['h'];
		 				if (GetValueInteger($HueID) != $Hue) SetValueInteger($HueID, $Hue);
		 				$result['HUE'] = $Hue;
	 				}
   	
	 				if (!$ColorID = @$this->GetIDForIdent("COLOR")) {
	 					$ColorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 3);
	 					$this->EnableAction("COLOR");
	 				}

	 				if (isset($ColorID)) {
		 				$Color = hexdec($hex);
		 				if (GetValueInteger($ColorID) != $Color) SetValueInteger($ColorID, $Color);
		 				$result['COLOR'] = $Color;
	 				}
	 			
	 				if (!$SaturationID = @$this->GetIDForIdent("SATURATION")) {
	 					$SaturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "~Intensity.100", 6);
	 					$this->EnableAction("SATURATION");
	 				}
	 				
	 				if (isset($SaturationID)) {
						$Saturation = $hsv['s'];
						if (GetValueInteger($SaturationID) != $Saturation) SetValueInteger($SaturationID, $Saturation);
						$result['SATURATION'] = $Saturation;
	 				}
				}

				if ($deviceIndex == 2 || $deviceIndex == 10) {
   				if (!$ColorTempID = @$this->GetIDForIdent("COLOR_TEMPERATURE")) {
      			$ColorTempID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTemperature", 4);
						$this->EnableAction("COLOR_TEMPERATURE");
   				}
	
	 				if (isset($ColorTempID)) {
		 				$ColorTemp = hexdec(dechex(ord($data{15})).dechex(ord($data{14})));
		 				if (GetValueInteger($ColorTempID) != $ColorTemp) SetValueInteger($ColorTempID, $ColorTemp);  
		 				$result['COLOR_TEMPERATURE'] = $ColorTemp;
	 				}
   			}

	 			if (!$BrightID = @$this->GetIDForIdent("BRIGHTNESS")) {
      		$BrightID = $this->RegisterVariableInteger("BRIGHTNESS", "Brightness", "~Intensity.100", 5);
					$this->EnableAction("BRIGHTNESS");
   			}

	 			if (isset($BrightID)) {
	 				$Brightness = ord($data{13});
	 				if (GetValueInteger($BrightID) != $Brightness) SetValueInteger($BrightID, $Brightness);
	 				$result['BRIGHTNESS'] = $Brightness;
 				}
			}
		}
	
		return $result;
	}

}
