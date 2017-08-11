<?

require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyClass.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyConnect.php");


abstract class lightifyControl extends IPSModule {

	const BODY_CMD_SET        = "/set?idx=";
	const BODY_CMD_STATE      = "&onoff=";
	const BODY_CMD_HUE        = "&hue=";
	const BODY_CMD_COLOR      = "&color=";
	const BODY_CMD_CTEMP      = "&ctemp=";
	const BODY_CMD_LEVEL      = "&level=";
	const BODY_CMD_SATURATION = "&saturation=";
	const BODY_CMD_TIME       = "&time=0";

	const RESSOURCE_DEVICE    = "/device";
	const RESSOURCE_GROUP     = "/group";

	const DATA_INDEX_LENGTH   = 3;
	const WAIT_TIME_SEMAPHORE = 1500; //milliseconds

	private $lightifyBase     = null;
	private $lightifyConnect  = null;
	private $transition       = false;

	private $moduleID;
	private $parentID;
	private $Name;

	private $connect;
	private $direct;

	private $itemType;
	private $itemGroup  = false;
	private $itemScene  = false;
	private $itemDummy  = false;

	private $deviceRGB  = false;
	private $deviceCCT  = false;
	private $deviceCLR  = false;

	private $itemLight  = false;
	private $itemOnOff  = false;
	private $itemMotion = false;
	private $itemDevice = false;

	private $debug;
	private $message;


	abstract protected function maintainVariables($mode, $newState);


	public function __construct($InstanceID) {
		parent::__construct($InstanceID);
		$this->lightifyBase = new lightifyBase;

		$connection     = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
		$this->parentID = ($connection) ? $connection : false;
	}


	private function sendData($method, $data = null) {
		$buffer = $data;

		switch ($method) {
			case osrConstant::METHOD_LOAD_CLOUD:
				switch (true) {
					case $this->itemLight:
						//fall through

					case $this->itemOnOff:
						$ressource = self::RESSOURCE_DEVICE.self::BODY_CMD_SET.$this->ReadPropertyInteger("deviceID");
						break;

					case $this->itemGroup:
						$ressource = self::RESSOURCE_GROUP.self::BODY_CMD_SET.$this->ReadPropertyInteger("groupID");
						break;
				}
				$buffer = $ressource.self::BODY_CMD_TIME.$data;
				break;
		}

		$this->SendDataToParent(json_encode(array(
			'DataID' => osrConstant::TX_GATEWAY,
			'Method' => $method,
			'Buffer' => $buffer))
		);
	}


	private function setEnvironment() {
		$this->moduleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
		$this->name     = IPS_GetName($this->InstanceID);

		$this->itemType = $this->ReadPropertyInteger("itemType");
		if ($this->itemType == osrConstant::TYPE_DEVICE_GROUP) $this->itemGroup = true;
		if ($this->itemType == osrConstant::TYPE_GROUP_SCENE)  $this->itemScene = true;

		if ($this->itemGroup === false && $this->itemScene === false) {
			if ($this->itemType & 8) $this->deviceRGB = true;
			if ($this->itemType & 2) $this->deviceCCT = true;
			if ($this->itemType & 4) $this->deviceCLR = true;

			if ($this->deviceRGB || $this->deviceCCT || $this->deviceCLR) $this->itemLight = true;
			if ($this->itemType == osrConstant::TYPE_FIXED_WHITE || $this->itemType == osrConstant::TYPE_PLUG_ONOFF) $this->itemOnOff = true;
			if ($this->itemType == osrConstant::TYPE_SENSOR_MOTION) $this->itemMotion = true;
			if ($this->itemLight || $this->itemOnOff || $this->itemMotion) $this->itemDevice = true;
		}

		$this->connect = IPS_GetProperty($this->parentID, "connectMode");
		$this->debug   = IPS_GetProperty($this->parentID, "debug");
		$this->message = IPS_GetProperty($this->parentID, "message");

		$this->lightifyConnect = new lightifyConnect($this->parentID, IPS_GetProperty($this->parentID, "host"), $this->debug, $this->message);
	}


	public function RequestAction($Ident, $Value) {
		if ($this->parentID) {
			switch ($Ident) {
				case "ALL_LIGHTS":
					//fall-through

				case "SCENE":
					//fall-through

				case "STATE":
					//fall-through

				case "COLOR":
					//fall-through

				case "COLOR_TEMPERATURE":
					//fall-through

				case "LEVEL":
					//fall-through

				case "SATURATION":
					return $this->SetValue($Ident, $Value);
			}
		}

		return false;
	}


	public function SetValue(string $key, integer $value) {
		$this->SetEnvironment();

		if (in_array($key, explode(",", osrConstant::LIST_KEY_VALUES)) == false) {
			if ($this->debug % 2 || $this->message) {
				$info = "usage: [".$this->InstanceID."|".$this->name."] {key} not valid!";

				if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
				if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);

				return false;
			}
		}

		if ($this->lightifyConnect) {
			$uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");
			$online   = false;

			if ($this->itemDevice) {
				$flag     = chr(0x00);

				$onlineID = @$this->GetIDForIdent("ONLINE");
				$online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
			}

			if ($this->itemGroup || $this->itemScene) {
				$flag = chr(0x02);
				if ($this->itemGroup) $this->transition = osrConstant::TRANSITION_DEFAULT;
			}

			if ($this->itemDevice || $this->itemGroup) {
				$stateID = @$this->GetIDForIdent("STATE");
				$state   = ($stateID) ? GetValueBoolean($stateID) : false;

				if ($this->itemLight) {
					if ($this->transition === false)
						$this->transition = IPS_GetProperty($this->InstanceID, "transition")*10;
				}
			}

			switch($key) {
				case "ALL_LIGHTS":
					$stateID = @$this->GetIDForIdent("ALL_LIGHTS");
					$state   = ($stateID) ? GetValueBoolean($stateID) : false;

					if (($value == 0 && $state !== false) || $value == 1) {
						if (false !== ($result = $this->lightifyConnect->setAllDevices($value))) {
							SetValue(@$this->GetIDForIdent($key), $value);
							$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
							return true;
						}
					} else {
						if ($this->debug % 2 || $this->message) {
							$info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true/false'";

							if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
							if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
						}
					}
					return false;

				case "SAVE":
					if ($this->itemLight) {
						if ($value == 1) {
							$result = $this->lightifyConnect->saveLightState($uintUUID);
							if ($result !== false) return true;
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "NAME":
					if ($this->itemScene == false) {
						$command = osrCommand::SET_DEVICE_NAME;

						if ($this->itemGroup) {
							$command = osrCommand::SET_GROUP_NAME;
							$uintUUID    = chr(hexdec(@$this->GetIDForIdent("groupID"))).chr(0x00);
						}

						if (is_string($value)) {
							$name = substr(trim($value), 0, osrConstant::DATA_NAME_LENGTH);

							if (false !== ($result = $this->lightifyConnect->setName($uintUUID, $command, $flag, $name))) {
								if (@IPS_GetName($this->InstanceID) != $name) IPS_SetName($this->InstanceID, (string)$name);
								return true;
							}
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {name} musst be a string";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "SCENE":
					if ($this->itemScene) {
						if (is_int($value)) {
							if (false !== ($result = $this->lightifyConnect->activateGroupScene($value))) return true;
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {sceneID} musst be numeric";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "DEFAULT":
					if ($this->itemLight || $this->itemGroup) {
						if ($value == 1) {
							//Reset light to default values
							$this->lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(osrConstant::COLOR_DEFAULT));
							$this->lightifyConnect->setColorTemperature($uintUUID, $flag, osrConstant::CTEMP_DEFAULT);
							$this->lightifyConnect->setLevel($uintUUID, $flag, osrConstant::LEVEL_MAX);

							if ($this->itemLight) {
								$this->lightifyConnect->setSoftTime($uintUUID, osrCommand::SET_LIGHT_SOFT_ON, osrConstant::TRANSITION_DEFAULT);
								$this->lightifyConnect->setSoftTime($uintUUID, osrCommand::SET_LIGHT_SOFT_OFF, osrConstant::TRANSITION_DEFAULT);

								IPS_SetProperty($this->InstanceID, "transition", osrConstant::TRANSITION_DEFAULT/10);
								IPS_ApplyChanges($this->InstanceID);
							}
							return true;
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "SOFT_ON":
					$command = osrCommand::SET_LIGHT_SOFT_ON;
					//fall-trough

				case "SOFT_OFF":
					$command = (!isset($command)) ? osrCommand::SET_LIGHT_SOFT_OFF : $command;
					//fall-trough

				case "TRANSITION":
					if ($this->itemLight) {
						$value = ($value) ? $this->getValueRange("TRANSITION_TIME", $value) : osrConstant::TRANSITION_DEFAULT/10;

						if (isset($command) == false) {
							if ($this->ReadPropertyFloat("transition") != $value) {
								IPS_SetProperty($this->InstanceID, "transition", $value);
								IPS_ApplyChanges($this->InstanceID);
							}
							return true;
						} else {
							$result = $this->lightifyConnect->setSoftTime($uintUUID, $command, $value*10);
							if ($result !== false) return true;
						}
					}
					return false;

				case "RELAX":
					$temperature = osrConstant::SCENE_RELAX;
					//fall-trough

				case "ACTIVE":
					if ($this->itemLight || $this->itemGroup) {
						$temperature = (isset($temperature)) ? $temperature : osrConstant::SCENE_ACTIVE;

						if ($value == 1) {
							if (false !== ($result = $this->lightifyConnect->setColorTemperature($uintUUID, $flag, $temperature))) {
								if ($this->itemLight && GetValue($this->InstanceID) != $value) SetValue($this->InstanceID, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
								return true;
							}
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "PLANT_LIGHT":
					if ($this->itemLight || $this->itemGroup) {
						if ($value == 1) {
							if (false !== ($result = $this->lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(osrConstant::SCENE_PLANT_LIGHT)))) {
								if ($this->itemLight && GetValue($this->InstanceID) != $value) SetValue($this->InstanceID, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
								return true;
							}
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "LIGHTIFY_LOOP":
					if (($this->deviceRGB || $this->itemGroup) && $state) {
						if ($value == 0 || $value == 1) {
							if (false !== ($result = $this->lightifyConnect->sceneLightifyLoop($uintUUID, $flag, $value, 3268)))
								return true;
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "STATE":
					if (($this->itemDevice && $online) || $this->itemGroup) {
						if ($value == 0 || $value == 1) {
							if (false !== ($result = $this->lightifyConnect->setState($uintUUID, $flag, $value))) {
								SetValue($stateID, $value);

								$this->maintainVariables(osrConstant::MODE_MAINTAIN_ACTION, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);

								return true;
							}
						} else {
							if ($this->debug % 2 || $this->message) {
								$info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

								if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
							}
						}
					}
					return false;

				case "COLOR":
					if ((($this->deviceRGB && $online) || $this->itemGroup) && $state) {
						$hueID   = @$this->GetIDForIdent("HUE");
						$hue     = ($hueID) ? GetValueInteger($hueID) : $hue;
						$colorID = @$this->GetIDForIdent("COLOR");
						$color   = ($colorID) ? GetValueInteger($colorID) : $color;
						$value   = $this->getValueRange($key, $value);

						if ($value != $color) {
							$hex = str_pad(dechex($value), 6, 0, STR_PAD_LEFT);
							$hsv = $this->lightifyBase->HEX2HSV($hex);
							$rgb = $this->lightifyBase->HEX2RGB($hex);

							if (false !== ($result = $this->lightifyConnect->setColor($uintUUID, $flag, $rgb, $this->transition))) {
								if (GetValue($hueID) != $hsv['h']) SetValue($hueID, $hsv['h']);
								if (GetValue($saturationID) != $hsv['s']) SetValue($saturationID, $hsv['s']);

								SetValue($colorID, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);

								return true;
							}
						}
					}
					return false;

				case "COLOR_TEMPERATURE":
					if ((($this->deviceCCT && $online) || $this->itemGroup) && $state) {
						$temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");
						$temperature   = ($temperatureID) ? GetValueInteger($temperatureID) : $temperature;
						$value         = $this->getValueRange($key, $value);

						if ($value != $temperature) {
							if (false !== ($result = $this->lightifyConnect->setColorTemperature($uintUUID, $flag, $value, $this->transition))) {
								SetValue($temperatureID, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
								return true;
							}
						}
					}
					return false;

				case "LEVEL":
					if ((($this->itemLight && $online) || $this->itemGroup) && $state) {
						$levelID = @$this->GetIDForIdent("LEVEL");
						$level   = ($levelID) ? GetValueInteger($levelID) : $level;
						$value   = $this->getValueRange($key, $value);

						if ($value != $level) {
							if (false !== ($result = $this->lightifyConnect->setLevel($uintUUID, $flag, $value, $this->transition))) {
								SetValue($levelID, $value);
								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
								return true;
							}
						}
					}
					return false;

				case "SATURATION":
					if ((($this->deviceRGB && $online) || $this->itemGroup) && $state) {
						$hueID        = @$this->GetIDForIdent("HUE");
						$hue          = ($hueID) ? GetValueInteger($hueID) : $hue;
						$colorID      = @$this->GetIDForIdent("COLOR");
						$color        = ($colorID) ? GetValueInteger($colorID) : $color;
						$saturationID = @$this->GetIDForIdent("SATURATION");
						$saturation   = ($saturationID) ? GetValueInteger($saturationID) : $saturation;
						$value        = $this->getValueRange($key, $value);

						if ($value != $saturation) {
							$hex   = $this->lightifyBase->HSV2HEX($hue, $value, 100);
							$rgb   = $this->lightifyBase->HEX2RGB($hex);
							$color = hexdec($hex);

							if (false !== ($result = $this->lightifyConnect->setSaturation($uintUUID, $flag, $rgb, $this->transition))) {
								if ($this->deviceRGB && (GetValue($colorID) != $color)) SetValue($colorID, $color);
								if ($this->itemLight) SetValue($saturationID, $value);

								$this->sendData(osrConstant::METHOD_LOAD_LOCAL);
								return true;
							}
						}
					}
					return false;
			}
		}

		return false;
	}


	public function SetValueEx(string $key, integer $value, integer $transition) {
		$this->transition = $this->getValueRange("TRANSITION_TIME", $transition);
		return $this->SetValue($key, $value);
	}


	private function getValueRange($key, $value) {
		switch ($key) {
			case "COLOR":
				$minColor = hexdec(osrConstant::COLOR_MIN);
				$maxColor = hexdec(osrConstant::COLOR_MAX);

				if ($value < $minColor) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".osrConstant::COLOR_MIN;
					$value = $minColor;
				} elseif ($value > $maxColor) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".osrConstant::COLOR_MIN;
					$value = $maxColor;
				}
				break;

			case "COLOR_TEMPERATURE":
				$minTemperature = ($this->itemType == osrConstant::TYPE_LIGHT_EXT_COLOR) ? osrConstant::CTEMP_COLOR_MIN : osrConstant::CTEMP_CCT_MIN;
				$maxTemperature = ($this->itemType == osrConstant::TYPE_LIGHT_EXT_COLOR) ? osrConstant::CTEMP_COLOR_MAX : osrConstant::CTEMP_CCT_MAX;

				if ($value < $minTemperature) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$minTemperature."K";
					$value = $minTemperature;
				} elseif ($value > $maxTemperature) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$maxTemperature."K";
					$value = $maxTemperature;
				}
				break;

			case "LEVEL":
				if ($value < osrConstant::INTENSITY_MIN) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Level {".$value."%} out of range. Setting to ".osrConstant::INTENSITY_MIN."%";
					$value = osrConstant::INTENSITY_MIN;
				} elseif ($value > osrConstant::INTENSITY_MAX) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Level {".$value."%} out of range. Setting to ".osrConstant::INTENSITY_MAX."%";
					$value = osrConstant::INTENSITY_MAX;
				}
				break;

			case "SATURATION":
				if ($value < osrConstant::INTENSITY_MIN) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".osrConstant::INTENSITY_MIN."%";
					$value = osrConstant::INTENSITY_MIN;
				} elseif ($value > osrConstant::INTENSITY_MAX) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".osrConstant::INTENSITY_MAX."%";
					$value = osrConstant::INTENSITY_MAX;
				}
				break;

			case "TRANSITION_TIME":
				$minTransition = osrConstant::TRANSITION_MIN;
				$maxTransition = osrConstant::TRANSITION_MAX;

				if ($value < ($minTransition /= 10)) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$minTransition.".0 sec";
					$value = $minTransition/10;
				} elseif ($value > ($maxTransition /= 10)) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$maxTransition.".0 sec";
					$value = $maxTransition/10;
				}
				break;

			case "LOOP_SPEEED":
				if ($value < osrConstant::COLOR_SPEED_MIN) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".osrConstant::COLOR_SPEED_MIN." ms";
					$value = osrConstant::COLOR_SPEED_MIN;
				} elseif ($value > osrConstant::COLOR_SPEED_MAX) {
					$info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".osrConstant::COLOR_SPEED_MAX." ms";
					$value = osrConstant::COLOR_SPEED_MAX;
				}
		}

		if (($this->debug % 2 || $this->message) && isset($info)) {
			if ($this->debug % 2) IPS_SendDebug($this->parentID, "<SETVALUE>", $info, 0);
			if ($this->message) IPS_LogMessage("SymconOSR", "<SETVALUE>   ".$info);
		}

		return $value;
	}


	public function GetValue(string $key) {
		if ($objectID = @IPS_GetObjectIDByIdent($key, $this->InstanceID))
			return GetValue($objectID);

		return false;
	}


	public function GetValueEx(string $key) {
		if ($this->itemeDevice) {
			$onlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
			$online   = GetValueBoolean($onlineID);

			if ($online) {
				$uintUUID   = $this->lightifyBase->UUIDtoChr($this->ReadPropertyString("UUID"));
				$buffer = $this->lightifyConnect->setDeviceInfo($uintUUID);

				if (is_array($list = $buffer) && in_array($key, $list)) return $list[$key];
			}
		}

		return false;
	}


	private function setSceneInfo($mode, $method, $data) {
		switch ($mode) {
			case osrConstant::GET_SCENE_CLOUD:
				if (false === ($activateID = @$this->GetIDForIdent("SCENE"))) {
					$activateID = $this->RegisterVariableInteger("SCENE", "Apply", "OSR.Scene", 0);
					SetValueInteger($activateID, 1);
					$this->EnableAction("SCENE");
				}
				break;
		}
	}

}