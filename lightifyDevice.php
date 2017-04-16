<?

require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyClass.php"); 
require_once(__DIR__.DIRECTORY_SEPARATOR."lightifySocket.php"); 


abstract class lightifyDevice extends IPSModule {

	private $dvTransition = osrDeviceValue::dvTT_Def/10;	
	private $lightifyBase = null;
	private $lightifySocket = null;
	
	private $Name = "";
	private $ParentID = null;
	

  public function __construct($InstanceID) {
    parent::__construct($InstanceID);
    
    $this->Name = IPS_GetName($this->InstanceID);
    $this->lightifyBase = new lightifyBase;
  }


  public function Create() {
		if (!IPS_VariableProfileExists("OSR.Hue")) {
			IPS_CreateVariableProfile("OSR.Hue", osrIPSVariable::vtInteger);
			IPS_SetVariableProfileDigits("OSR.Hue", 0);
			IPS_SetVariableProfileText("OSR.Hue", "", "°");
			IPS_SetVariableProfileValues("OSR.Hue", 0, 360, 1);
		}

		if (!IPS_VariableProfileExists("OSR.ColorTemperature")) {
			IPS_CreateVariableProfile("OSR.ColorTemperature", osrIPSVariable::vtInteger);
			IPS_SetVariableProfileIcon("OSR.ColorTemperature", "Intensity");
			IPS_SetVariableProfileDigits("OSR.ColorTemperature", 0);
			IPS_SetVariableProfileText("OSR.ColorTemperature", "", " K");
			IPS_SetVariableProfileValues("OSR.ColorTemperature", 2000, 8000, 1);
		}

    parent::Create();
  }


  abstract protected function getUniqueID();
  

  public function ApplyChanges() {
    parent::ApplyChanges();
    $this->ConnectParent(osrIPSModule::omGateway);
  }


  protected function openSocket() {
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
	  
	  if ($this->lightifySocket = $this->openSocket()) {
			switch ($ModuleID) {
				case osrIPSModule::omLight: //Lightify Light
					//fall-through
				
				case osrIPSModule::omPlug: //Lightify Plug
					$buffer = $this->lightifySocket->getDeviceInfo($MAC);
					$length = strlen($buffer);
					
					if ($buffer !== false) {
						if ($length == 20 || $length == 32) {
							return $this->setDeviceInfo($buffer);
						}
					}
					return false;

				case osrIPSModule::omGroup: //Lightify Group/Zone
					return $this->lightifySocket->getGroupInfo($MAC);
				
				case osrIPSModule::omSwitch; //Lightify Switch
					return false;
			}
		}
	}

	
	public function SetValue(string $key, integer $Value) {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
		$result = false;

		if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug || $ModuleID == osrIPSModule::omGroup) {
			$deviceType = ($ModuleID == osrIPSModule::omLight) ? IPS_GetProperty($this->InstanceID, "deviceType") : osrDeviceType::dtRGBW;

			if (($key != "ALL_DEVICES") && ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug)) {
				$OnlineID = @IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
				$Online = ($OnlineID) ? GetValueBoolean($OnlineID) : false;
			}

			if (($key == "ALL_DEVICES") || $ModuleID == osrIPSModule::omGroup || $Online) {
				$this->lightifySocket = $this->openSocket();

				if ($this->lightifySocket) {
					if ($key != "ALL_DEVICES" && ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug)) {
						$StateID = @IPS_GetObjectIDByIdent('STATE', $this->InstanceID);
						$State = ($StateID) ? GetValueBoolean($StateID) : osrIPSVariable::vtNone;
					}

					if ($key != "ALL_DEVICES") {
						$MAC = $this->lightifyBase->uniqueIDToChr(IPS_GetProperty($this->InstanceID, "UniqueID"));
						$flag = ($ModuleID == osrIPSModule::omGroup) ? chr(0x02) : chr(0x00);

						if ($ModuleID == osrIPSModule::omLight) {
							if ($deviceType == osrDeviceType::dtRGBW) {
								$HueID = @IPS_GetObjectIDByIdent('HUE', $this->InstanceID);
								$ColorID = @IPS_GetObjectIDByIdent('COLOR', $this->InstanceID);
								$SaturationID = @IPS_GetObjectIDByIdent('SATURATION', $this->InstanceID);
							
								$Hue = ($HueID) ? GetValueInteger($HueID) : 0;
								$Color = ($ColorID) ? GetValueInteger($ColorID) : 0;
								$Saturation = ($SaturationID) ? GetValueInteger($SaturationID) : 0;
							}
						
							if ($deviceType != osrDeviceType::dtClear) {
								$ColorTempID = @IPS_GetObjectIDByIdent('COLOR_TEMPERATURE', $this->InstanceID);
								$ColorTemp = ($ColorTempID) ? GetValueInteger($ColorTempID) : 0;
							}

							$BrightID = @IPS_GetObjectIDByIdent('BRIGHTNESS', $this->InstanceID);
							$Bright = ($BrightID) ? GetValueInteger($BrightID) : 0;
						}
					}

					switch ($key) {
						case "ALL_DEVICES":
							if ($Value == 0 || $Value == 1) {
								if (false === ($result = $this->lightifySocket->setAllDevicesState(($Value == 0) ? 0 : 1) )) return false;
								//IPS_LogMessage("SymconOSR", $this->lightifyBase->decodeData($result));

								foreach (IPS_GetInstanceListByModuleID(osrIPSModule::omLight) as $k)
									$arrayDevices[]['DeviceID'] = $k;

								foreach (IPS_GetInstanceListByModuleID(osrIPSModule::omPlug) as $k)
									$arrayDevices[]['DeviceID'] = $k;
								
								$this->setDeviceValue($arrayDevices, "STATE", $Value);
							}
							return true;
									
						case "STATE":
							if ($Value == 0 || $Value == 1) {
								if (false === ($result = $this->lightifySocket->setState($MAC, $flag, $Value))) return false;
								
								switch ($ModuleID) {
									case osrIPSModule::omLight:
										//fall-trough
									case osrIPSModule::omPlug:
										SetValueBoolean($StateID, (($Value == 0) ? false : true));
										break;
										
									case osrIPSModule::omGroup:
										$this->setDeviceValue(json_decode($this->ReadPropertyString("Instances"), true), $key, $Value);
										break;
								}
							}
							return true;
							
						case "COLOR":
							if (($ModuleID == osrIPSModule::omLight && $deviceType == osrDeviceType::dtRGBW) || $ModuleID == osrIPSModule::omGroup) {
								if ($ModuleID == osrIPSModule::omGroup || ($State)) {
									if ($ModuleID == osrIPSModule::omLight) $Value = getValueRange($this->Name, $key, $value);

									if ($ModuleID == osrIPSModule::omGroup || ($Value != $Color)) {
										$hex = str_pad(dechex($Value), 6, 0, STR_PAD_LEFT);
										$rgb = $this->lightifyBase->HEX2RGB($hex);
										
										if (false !== ($result = $this->lightifySocket->setColor($MAC, $flag, $rgb, $this->dvTransition))) {
											switch ($ModuleID) {
												case osrIPSModule::omLight:
													//fall-trough
												case osrIPSModule::omPlug:
													if (false === ($result = $this->SendData($this->lightifySocket, $MAC, $ModuleID))) return false;
	
												case osrIPSModule::omGroup:
													$this->setDeviceValue(json_decode($this->ReadPropertyString("Instances"), true), $key, $Value);
													break;
											}
										}
									}
									return true;
								}
							}

						case "COLOR_TEMPERATURE":
							if (($ModuleID == osrIPSModule::omLight && $deviceType != osrDeviceType::dtClear) || $ModuleID == osrIPSModule::omGroup) {
								if ($ModuleID == osrIPSModule::omGroup || ($State)) {
									if ($ModuleID == osrIPSModule::omLight) $Value = $this->getValueRange($this->Name, $key, $value, $deviceType);

									if ($ModuleID == osrIPSModule::omGroup || ($Value != $ColorTemp)) {
										if (false === ($result = $this->lightifySocket->setColorTemperature($MAC, $flag, $Value, $this->dvTransition))) return false;
										
										switch ($ModuleID) {
											case osrIPSModule::omLight:
												//fall-trough
											case osrIPSModule::omPlug:
												SetValueInteger($ColorTempID, $Value);
												break;
										
											case osrIPSModule::omGroup:
												$this->setDeviceValue(json_decode($this->ReadPropertyString("Instances"), true), $key, $Value);
												break;
										}
									}
									return true;
								}
							}

						case "BRIGHTNESS":
							if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omGroup) {
								if ($ModuleID == osrIPSModule::omGroup || ($State)) {
									if ($ModuleID == osrIPSModule::omLight) $Value = $this->getValueRange($this->Name, $key, $Value);
									
									if ($ModuleID == osrIPSModule::omGroup || ($Value != $Bright)) {
										if (false === ($result = $this->lightifySocket->setBrightness($MAC, $flag, $Value, $this->dvTransition))) return false;

										switch ($ModuleID) {
											case osrIPSModule::omLight:
												//fall-trough
											case osrIPSModule::omPlug:
												SetValueInteger($BrightID, $Value);
												break;
										
											case osrIPSModule::omGroup:
												$this->setDeviceValue(json_decode($this->ReadPropertyString("Instances"), true), $key, $Value);
												break;
										}
									}
									return true;
								}
							}

						case "SATURATION":
							if (($ModuleID == osrIPSModule::omLight && $deviceType == osrDeviceType::dtRGBW) || $ModuleID == osrIPSModule::omGroup) {
								if ($ModuleID == osrIPSModule::omGroup || ($State)) {
									if ($ModuleID == osrIPSModule::omLight) $Value = $this->getValueRange($this->Name, $key, $Value);

									if ($ModuleID == osrIPSModule::omGroup || ($Value != $Saturation)) {
										$hex = $this->lightifyBase->HSV2HEX($Hue, $Value, $Bright);
										$rgb = $this->lightifyBase->HEX2RGB($hex);
										
										if (false !== ($result = $this->lightifySocket->setSaturation($MAC, $flag, $rgb, $this->dvTransition))) {
											switch ($ModuleID) {
												case osrIPSModule::omLight:
													//fall-trough
												case osrIPSModule::omPlug:
													if (false === ($result = $this->SendData($this->lightifySocket, $MAC, $ModuleID))) return false;

												case osrIPSModule::omGroup:
													$this->setDeviceValue(json_decode($this->ReadPropertyString("Instances"), true), $key, $Value);
													break;
											}
										}												
									}
									return true;
								}
							}
					}
				}
			}
		}

		return false;
	}


	private function setDeviceValue($arrayDevices, $key, $Value) {
		foreach ($arrayDevices as $k => $v) {
			$ModuleID = IPS_GetInstance($v['DeviceID'])['ModuleInfo']['ModuleID'];
			
			if (($key == "STATE") || $ModuleID == osrIPSModule::omLight) {		
				$OnlineID = @IPS_GetObjectIDByIdent("ONLINE", $v['DeviceID']);
				$Online = ($OnlineID) ? GetValueBoolean($OnlineID) : false;

				if ($Online) {
					if ($id = @IPS_GetObjectIDByIdent($key, $v['DeviceID'])) {
						if ($ModuleID == osrIPSModule::omLight)
							$valueNew = $this->getValueRange(IPS_GetName($v['DeviceID']), $key, $Value, IPS_GetProperty($v['DeviceID'], "deviceType"));

						$valueSave = GetValue($id);
						if ($id && $valueSave != $valueNew) SetValue($id, $valueNew);
					}
				}
			}
		}
	}


	private function getValueRange($Name, $key, $Value, $deviceType = null) {
		switch ($key) {
			case "COLOR":
				if ($Value < osrDeviceValue::dvColor_Min) {
					IPS_LogMessage("SymconOSR", $Name." Color [".$Value."K] out of range. Setting to ".osrDeviceValue::dvColor_Min." #".dechex(osrDeviceValue::dvColor_Min));
					$Value = osrDeviceValue::dvColor_Min;
				} elseif ($Value > osrDeviceValue::dvColor_Max) {
					IPS_LogMessage("SymconOSR", $Name." Color [".$Value."K] out of range. Setting to ".osrDeviceValue::dvColor_Max." #".dechex(osrDeviceValue::dvColor_Max));
					$Value = osrDeviceValue::dvColor_Max;
				}
				break;

			case "COLOR_TEMPERATURE":
				$minCT = ($deviceType == osrDeviceType::dtRGBW) ? osrDeviceValue::dvCT_RGBW_Min : osrDeviceValue::dvCT_TW_Min;
				$maxCT = ($deviceType == osrDeviceType::dtRGBW) ? osrDeviceValue::dvCT_RGBW_Max : osrDeviceValue::dvCT_TW_Max;

				if ($Value < $minCT) {
					IPS_LogMessage("SymconOSR", $Name." Color Temperature [".$Value."K] out of range. Setting to ".$minCT."K");
					$Value = $minCT;
				} elseif ($Value > $maxCT) {
					IPS_LogMessage("SymconOSR", $Name." Color Temperature [".$Value."K] out of range. Setting to ".$maxCT."K");
					$Value = $maxCT;
				}
				break;

			case "BRIGHTNESS":
				if ($Value < osrDeviceValue::dvBright_Min) {
					IPS_LogMessage("SymconOSR", $this->Name." Brightness [".$Value."%] out of range. Setting to ".osrDeviceValue::dvBright_Min."%");
					$Value = osrDeviceValue::dvBright_Min;
				} elseif ($Value > osrDeviceValue::dvBright_Max) {
					IPS_LogMessage("SymconOSR", $this->Name." Brightness [".$Value."%] out of range. Setting to ".osrDeviceValue::dvBright_Min."%");
					$Value = osrDeviceValue::dvBright_Max;
				}
				break;

			case "SATURATION":
				if ($Value < osrDeviceValue::dvSat_Min) {
					IPS_LogMessage("SymconOSR", $this->Name." Saturation [".$Value."%] out of range. Setting to ".osrDeviceValue::dvSat_Min."%");
					$Value = osrDeviceValue::dvSat_Min;
				} elseif ($Value > osrDeviceValue::dvSat_Max) {
					IPS_LogMessage("SymconOSR", $this->Name." Saturation [".$Value."%] out of range. Setting to ".osrDeviceValue::dvSat_Max."%");
					$Value = osrDeviceValue::dvSat_Max;
				}
				break;
		}
		
		return $Value;	
	}
	
	
	public function SetValueEx(string $key, integer $Value, integer $Transition) {
		if ($Transition < osrDeviceValue::dvTT_Min) {
			IPS_LogMessage("SymconOSR", $this->Name." Transition [".$Transition."ms] out of range. Setting to ".osrDeviceValue::dvTT_Min."ms");
			$Transition = osrDeviceValue::dvTT_Min;
		} elseif ($Transition > osrDeviceValue::dvTT_Max) {
			IPS_LogMessage("SymconOSR", $this->Name." Transition [".$Transition."ms] out of range. Setting to ".osrDeviceValue::dvTT_Max."ms");
			$Transition = osrDeviceValue::dvTT_Max;
		}

		$this->dvTransition = $Transition/10;	
		return $this->SetValue($key, $Value);
	}
	
	
	public function GetValue(string $key) {
		$id = @IPS_GetObjectIDByIdent($key, $this->InstanceID);
		if ($id) return GetValue($id);
		
		return false;
	}


	public function GetValueEx(string $key) {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == osrIPSModule::omLight || $ModuleID == osrIPSModule::omPlug) {
			$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$Online = GetValueBoolean($OnlineID);

			if ($Online) {
				if ($this->lightifySocket = $this->openSocket()) {
					$MAC = $this->lightifyBase->uniqueIDToChr($this->getUniqueID());
					if (is_array($list = $this->SendData($this->lightifySocket, $MAC, $ModuleID)) && in_array($key, $list)) return $list[$key];
				}
			}
		}
		
		return false;
	}

	
	public function SetColorCycle(boolean $Cycle, integer $Agility) {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == osrIPSModule::omLight) {
			$OnlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$Online = GetValueBoolean($OnlineID);

			if ($Online) {
				if ($this->lightifySocket = $this->openSocket()) {
					if (@IPS_GetObjectIDByIdent('HUE', $this->InstanceID)) {
						if ($Agility < osrDeviceValue::dvAS_Min) {
							IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to ".osrDeviceValue::dvAS_Min." sec");
							$Agility = osrDeviceValue::dvAS_Min;
						} elseif ($Agility > osrDeviceValue::dvAS_Max) {
							IPS_LogMessage("SymconOSR", "Color cycle agility [".$Agility." sec] out of range. Setting to ".osrDeviceValue::dvAS_Max." sec");
							$Agility = osrDeviceValue::dvAS_Max;
						}

						$MAC = $this->lightifyBase->uniqueIDToChr($this->getUniqueID());
						if ($this->lightifySocket->setColorCycle($MAC, $Cycle, $Agility) !== false) return true;
					}
				}
			}
		}
		
		return false;
	}
	

	public function SyncState() {
		$ModuleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];

		if ($ModuleID == osrIPSModule::omLight) {
			if ($this->lightifySocket = $this->openSocket()) {
				$MAC = $this->lightifyBase->uniqueIDToChr($this->getUniqueID());

				if (false !== ($result = $this->sendData($this->lightifySocket, $MAC, $ModuleID))) {
					echo "Light state successfully synced!\n";
					return $result;
				}
			}
		
			echo "Light state sync failed!";
			IPS_LogMessage("SymconOSR", "Light state sync failed!");
		}
		
		return false;
	}
	
		
	private function setDeviceInfo($data) {
		//IPS_LogMessage("SymconOSR", IPS_GetName($this->InstanceID)." buffer length: ".strlen($data)."  online: ".ord($data{19})."  state: ".((ord($data{19}) == 0) ? ord($data{21}) : "0"));
		$Mode = ord($data{19});
		$result = false;
			
		if ($Mode == osrDeviceMode::dmOnline || $Mode == osrDeviceMode::dmOffline) {
			$deviceType = IPS_GetProperty($this->InstanceID, "deviceType");
			$Online = ($Mode == osrDeviceMode::dmOnline) ? true : false; //Online: 0 - Offline: 255
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

			if (($deviceType == osrDeviceType::dtTW || $deviceType == osrDeviceType::dtClear || $deviceType == osrDeviceType::dtRGBW) && $Online) {
				$data = substr($data, 9);
				$rgb = ord($data{16}).ord($data{17}).	ord($data{18});

				if ($deviceType == osrDeviceType::dtRGBW) {
					//$Alpha = ord($data{19});
					$hex = $this->lightifyBase->RGB2HEX(array('r' => ord($data{16}), 'g' => ord($data{17}), 'b' => ord($data{18})));
					$hsv = $this->lightifyBase->HEX2HSV($hex);

					if (!$HueID = @$this->GetIDForIdent("HUE")) {
						$HueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 0);
						$this->EnableAction("HUE");
   				}
   			
	 				if (isset($HueID)) {
	   				if (GetValueInteger($HueID) != ($Hue = $hsv['h'])) SetValueInteger($HueID, $Hue);
		 				$result['HUE'] = $Hue;
	 				}
   	
	 				if (!$ColorID = @$this->GetIDForIdent("COLOR")) {
	 					$ColorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 3);
	 					$this->EnableAction("COLOR");
	 				}

	 				if (isset($ColorID)) {
		 				if (GetValueInteger($ColorID) != ($Color = hexdec($hex))) SetValueInteger($ColorID, $Color);
		 				$result['COLOR'] = $Color;
	 				}
	 			
	 				if (!$SaturationID = @$this->GetIDForIdent("SATURATION")) {
	 					$SaturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "~Intensity.100", 6);
	 					$this->EnableAction("SATURATION");
	 				}
	 				
	 				if (isset($SaturationID)) {
						if (GetValueInteger($SaturationID) != ($Saturation = $hsv['s'])) SetValueInteger($SaturationID, $Saturation);
						$result['SATURATION'] = $Saturation;
	 				}
				}

				if ($deviceType == 2 || $deviceType == 10) {
   				if (!$ColorTempID = @$this->GetIDForIdent("COLOR_TEMPERATURE")) {
      			$ColorTempID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTemperature", 4);
						$this->EnableAction("COLOR_TEMPERATURE");
   				}
	
	 				if (isset($ColorTempID)) {
		 				if (GetValueInteger($ColorTempID) != ($ColorTemp = hexdec(dechex(ord($data{15})).dechex(ord($data{14}))))) SetValueInteger($ColorTempID, $ColorTemp);  
		 				$result['COLOR_TEMPERATURE'] = $ColorTemp;
	 				}
   			}

	 			if (!$BrightID = @$this->GetIDForIdent("BRIGHTNESS")) {
      		$BrightID = $this->RegisterVariableInteger("BRIGHTNESS", "Brightness", "~Intensity.100", 5);
					$this->EnableAction("BRIGHTNESS");
   			}

	 			if (isset($BrightID)) {
	 				//$Bright = ($deviceType == osrDeviceType::dtRGBW) ? $hsv['v'] : ord($data{13});
	 				if (GetValueInteger($BrightID) != ($Bright = ($deviceType == osrDeviceType::dtRGBW) ? $hsv['v'] : ord($data{13}))) SetValueInteger($BrightID, $Bright);
	 				$result['BRIGHTNESS'] = $Bright;
 				}
			}
		}
	
		return $result;
	}

}
