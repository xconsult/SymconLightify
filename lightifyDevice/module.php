<?

//require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyClass.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyControl.php");


//class lightifyDevice extends IPSModule {
class lightifyDevice extends lightifyControl {

	const STATUS_DEVICE_ACTIVE     = 102;
	const ERROR_GATEWAY_CONNECTION = 201;
	const ERROR_INVALID_DEVICE_ID  = 202;

	const ID_DEVICE_CREATE         = 0;
	const ID_DEVICE_MIN            = 1;

	private $lightifyBase;
	private $parentID;

	private $debug;
	private $message;


	public function __construct(string $InstanceID) {
		parent::__construct($InstanceID);
		$this->lightifyBase = new lightifyBase;

		$this->parentID = @IPS_GetInstance($InstanceID)['ConnectionID'];
	}


	public function Create() {
		parent::Create();

		$this->SetBuffer("deviceBuffer", osrConstant::NO_STRING);
		$this->SetBuffer("cloudDevice", osrConstant::NO_STRING);
		$this->SetBuffer("saveID", serialize(osrConstant::NO_VALUE));

		$this->RegisterPropertyInteger("deviceID", self::ID_DEVICE_CREATE);
		$this->RegisterPropertyInteger("itemClass", osrConstant::CLASS_LIGHTIFY_LIGHT);

		$this->RegisterPropertyString("UUID", osrConstant::NO_STRING);
		$this->RegisterPropertyString("manufacturer", osrConstant::NO_STRING);
		$this->RegisterPropertyString("deviceModel", osrConstant::NO_STRING);
		$this->RegisterPropertyString("deviceLabel", osrConstant::NO_STRING);

		$this->RegisterPropertyString("uintUUID", osrConstant::NO_STRING);
		$this->RegisterPropertyInteger("itemType", osrConstant::NO_VALUE);
		$this->RegisterPropertyFloat("transition", osrConstant::TRANSITION_DEFAULT);

		$this->ConnectParent(osrConstant::MODULE_GATEWAY);
	}


	public function ApplyChanges() {
		parent::ApplyChanges();

		//Check config
		if (($deviceID = $this->ReadPropertyInteger("deviceID")) < self::ID_DEVICE_MIN) {
			$this->SetStatus(self::ERROR_INVALID_DEVICE_ID);
			return false;
		}

		if (self::STATUS_DEVICE_ACTIVE == ($status = $this->setDeviceProperty($deviceID))) {
			//Apply changes
			if (IPS_HasChanges($this->InstanceID)) IPS_ApplyChanges($this->InstanceID);
		}

		$this->SetStatus($status);
	}


	public function GetConfigurationForm() {
		$connectMode  = IPS_GetProperty($this->parentID, "connectMode");
		$deviceInfo   = IPS_GetProperty($this->parentID, "deviceInfo");
		$deviceBuffer = $this->GetBuffer("deviceBuffer");
		$itemType     = $this->ReadPropertyInteger("itemType");

		$infoText     = ($deviceInfo && empty($deviceBuffer) === false) ? '
			{ "type": "Label", "label": "----------------------------------------------------- Optional informations -----------------------------------------------------" },
			{ "type": "ValidationTextBox", "name": "UUID",         "caption": "UUID" },' : "";

		switch ($itemType) {
			case osrConstant::TYPE_SENSOR_MOTION:
				$formJSON = '{
					"elements": [
						{ "type": "NumberSpinner", "name": "deviceID",  "caption": "Device [id]" },
						{ "type": "Select",        "name": "itemClass", "caption": "Class",
							"options": [
								{ "label": "Light",  "value": 2001 },
								{ "label": "Plug",   "value": 2002 },
								{ "label": "Sensor", "value": 2003 }
							]
						},
						'.$infoText.'
						{ "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
					],
					"status": [
						{ "code": 102, "icon": "active", "caption": "Device is active" },
						{ "code": 201, "icon": "error",  "caption": "Lightify gateway is not connected" },
						{ "code": 202, "icon": "error",  "caption": "Please enter a valid Device [id]" }
					]
				}';
				break;

			default:
				$infoText = ($connectMode == osrConstant::CONNECT_LOCAL_CLOUD && empty($infoText) === false) ? $infoText.'
					{ "type": "ValidationTextBox", "name": "manufacturer", "caption": "Manufacturer" },
					{ "type": "ValidationTextBox", "name": "deviceModel",  "caption": "Model" },
					{ "type": "ValidationTextBox", "name": "deviceLabel",  "caption": "Capabilities" },' : $infoText;

				$formJSON = '{
					"elements": [
						{ "type": "NumberSpinner", "name": "deviceID",  "caption": "Device [id]" },
						{ "type": "Select",        "name": "itemClass", "caption": "Class",
							"options": [
								{ "label": "Light",  "value": 2001 },
								{ "label": "Plug",   "value": 2002 },
								{ "label": "Sensor", "value": 2003 }
							]
						},
						'.$infoText.'
						{ "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
					],
					"actions": [
						{ "type": "Button", "label": "An",  "onClick": "OSR_SetValue($id, \"STATE\", true)" },
						{ "type": "Button", "label": "Aus", "onClick": "OSR_SetValue($id, \"STATE\", false)" }
					],
					"status": [
						{ "code": 102, "icon": "active", "caption": "Device is active" },
						{ "code": 201, "icon": "error",  "caption": "Lightify gateway is not connected" },
						{ "code": 202, "icon": "error",  "caption": "Please enter a valid Device [id]" }
					]
				}';
		}

		return $formJSON;
	}


	public function ReceiveData($jsonString) {
		$deviceID = $this->ReadPropertyInteger("deviceID");
		$data     = json_decode($jsonString);

		switch ($data->Mode) {
			case osrConstant::MODE_DEVICE_LOCAL:
				$localBuffer = utf8_decode($data->Buffer);
				$localCount  = ord($localBuffer{0});

				//Store device buffer
				$deviceBuffer = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
				$deviceBuffer = ($deviceBuffer === false) ? "" : $deviceBuffer;

				if ($data->Debug % 2) $this->SendDebug("<RECEIVEDATA|MODE_DEVICE_LOCAL>", $localCount."/".$this->lightifyBase->decodeData($deviceBuffer), 0);
				if ($data->Message) IPS_LogMessage("SymconOSR", "<RECEIVEDATA|MODE_DEVICE_LOCAL>   ".$localCount.$this->lightifyBase->decodeData($deviceBuffer));

				if (empty($deviceBuffer) === false) {
					$this->SetBuffer("deviceBuffer", $localBuffer{0}.$deviceBuffer);
					$this->setDeviceInfo($data->Method, $data->Mode, $deviceBuffer, false);
				}
				break;

			case osrConstant::MODE_DEVICE_CLOUD:
				$cloudDevice = $this->getDeviceCloud($deviceID, $data->Buffer);
				$cloudDevice = ($cloudDevice === false) ? "" : $cloudDevice;

				if ($data->Debug % 2) $this->SendDebug("<RECEIVEDATA|MODE_DEVICE_CLOUD>", $this->lightifyBase->decodeData($cloudDevice), 0);
				if ($data->Message) IPS_LogMessage("SymconOSR", "<RECEIVEDATA|MODE_DEVICE_CLOUD>   ".$this->lightifyBase->decodeData($cloudDevice));

				if (empty($cloudDevice) === false) {
					$this->SetBuffer("cloudDevice", $cloudDevice);
					$this->setDeviceInfo($data->Method, $data->Mode, $cloudDevice, true);
				}
				break;
		}
	}


	private function setDeviceProperty($deviceID) {
		$jsonString = $this->SendDataToParent(json_encode(array(
			'DataID' => osrConstant::TX_GATEWAY,
			'Method' => osrConstant::METHOD_APPLY_CHILD,
			'Mode'   => osrConstant::MODE_DEVICE_LOCAL))
		);

		if ($jsonString != osrConstant::NO_STRING) {
			$localData   = json_decode($jsonString);
			$localBuffer = utf8_decode($localData->Buffer);
			$localCount  = ord($localBuffer{0});
			$maxDevice   = ord($localBuffer{1});
			//IPS_LogMessage("SymconOSR", "<SETDEVICEPROPERTY>   ".$deviceID."/".IPS_GetName($this->InstanceID)."/".$localCount."/".$maxDevice);

			if ($deviceID > $maxDevice)
				return self::ERROR_INVALID_DEVICE_ID;

			//Store device buffer
			$deviceBuffer = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
			$deviceBuffer =  ($deviceBuffer === false) ? "" : $deviceBuffer;
			//IPS_LogMessage("SymconOSR", "<SETDEVICEPROPERTY>   ".$deviceID."/".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($deviceBuffer));

			if ($deviceBuffer !== false) {
				$uintUUID  = substr($deviceBuffer, 2, osrConstant::UUID_DEVICE_LENGTH);
				$itemType  = ord($deviceBuffer{10});
				$saveID    = unserialize($this->GetBuffer("saveID"));

				if ($saveID != $deviceID) {
					if ($saveID != osrConstant::NO_VALUE)
						$this->maintainVariables(osrConstant::MODE_DELETE_VARIABLE);

					$this->SetBuffer("saveID", serialize($deviceID));
				}

				if ($this->ReadPropertyString("uintUUID") != $uintUUID)
					IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);

				if ($this->ReadPropertyInteger("itemType") != $itemType)
					IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);

				if (IPS_GetProperty($this->parentID, "deviceInfo")) {
					$UUID = $this->lightifyBase->ChrToUUID($uintUUID);

					if ($this->ReadPropertyString("UUID") != $UUID)
						IPS_SetProperty($this->InstanceID, "UUID", (string)$UUID);

					$jsonString = $this->SendDataToParent(json_encode(array(
						'DataID' => osrConstant::TX_GATEWAY,
						'Method' => osrConstant::METHOD_APPLY_CHILD,
						'Mode'   => osrConstant::MODE_DEVICE_CLOUD))
					);

					if ($jsonString != osrConstant::NO_STRING) {
						$cloudData = json_decode($jsonString);

						//Store group device buffer
						$cloudDevice = $this->getDeviceCloud($deviceID, $cloudData->Buffer);
						$cloudDevice = ($cloudDevice === false) ? "" : $cloudDevice;

						if ($cloudDevice !== false) {
							$this->SetBuffer("cloudDevice", $cloudDevice);
							$this->setDeviceInfo(osrConstant::METHOD_APPLY_CHILD, osrConstant::MODE_DEVICE_CLOUD, $cloudDevice, false);
						}
					}
				}

				$this->SetBuffer("deviceBuffer", $localBuffer{0}.$deviceBuffer);
				$this->setDeviceInfo(osrConstant::METHOD_CREATE_CHILD, osrConstant::MODE_DEVICE_LOCAL, $deviceBuffer, false);
			}
		}

		return self::STATUS_DEVICE_ACTIVE;
	}


	private function getDeviceLocal($deviceID, $buffer, $ncount) {
		$deviceBuffer = false;

		for ($i = 1; $i <= $ncount; $i++) {
			$localID = ord($buffer{0});
			$buffer  = substr($buffer, 1);
			//IPS_LogMessage("SymconOSR", "<GETDEVICELOCAL>   ".$ncount."/".$i."/".$deviceID."/".$localID."/".IPS_GetName($this->InstanceID));

			if ($deviceID == $localID) {
				if (IPS_GetInstance($this->InstanceID)['ConnectionID'] != $this->parentID)
					continue;

				$deviceBuffer = substr($buffer, 0, osrConstant::DATA_DEVICE_LENGTH);
				break;
			}

			$buffer = substr($buffer, osrConstant::DATA_DEVICE_LENGTH);
		}

		return $deviceBuffer;
	}


	private function getDeviceCloud($deviceID, $buffer) {
		$cloudDevice = false;
		$Devices     = json_decode($buffer);

		foreach ($Devices as $device) {
			list($cloudID) = $device;

			if ($deviceID == $cloudID) {
				if (IPS_GetInstance($this->InstanceID)['ConnectionID'] != $this->parentID)
					continue;

				$cloudDevice = json_encode($device);
				break;
			}
		}

		return $cloudDevice;
	}


	private function setDeviceInfo($method, $mode, $data, $apply) {
		$itemType = ord($data{10});
		$result   = false;

		switch ($mode) {
			case osrConstant::MODE_DEVICE_LOCAL:
				$itemLight = $itemPlug = $itemMotion = false;

				//Decode Device label
				switch ($itemType) {
					case osrConstant::TYPE_PLUG_ONOFF:
						$itemPlug = true;
						break;

					case osrConstant::TYPE_SENSOR_MOTION:
						$itemMotion = true;
						break;

					default:
						$itemLight = true;
				}

				$deviceRGB  = ($itemType & 8) ? true: false;
				$deviceCCT  = ($itemType & 2) ? true: false;
				$deviceCLR  = ($itemType & 4) ? true: false;

				$online = !((bool)ord($data{15})); //Online: 2 - Offline: 0
				$hue    = $color = $level      = osrConstant::NO_STRING;
				$temperature     = $saturation = osrConstant::NO_STRING;

				if ($itemLight || $itemPlug || $itemMotion) {
					$online    = ($online == osrConstant::STATE_ONLINE) ? true : false; //Online: 0 - Offline: 255
					$state     = ($online) ? ord($data{18}) : false;
					$newOnline = $online; 
					$newState  = ($itemMotion) ? ord($data{22}) : $state;
				}

				$white = ord($data{25});
				$hex   = $this->lightifyBase->RGB2HEX(array('r' => ord($data{22}), 'g' => ord($data{23}), 'b' => ord($data{24})));
				$hsv   = $this->lightifyBase->HEX2HSV($hex);

				if ($deviceRGB) {
					$hue        = $hsv['h'];
					$color      = hexdec($hex);
					$saturation = $hsv['s'];
				}

				if ($deviceCCT) $temperature = hexdec(dechex(ord($data{21})).dechex(ord($data{20})));
				if ($itemLight) $level = ord($data{19});

				//Additional informations
				$zigBee   = dechex(ord($data{0})).dechex(ord($data{1}));
				$firmware = osrConstant::NO_STRING;

				if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
					if ($method == osrConstant::METHOD_CREATE_CHILD) {
						$onlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "~Switch", 312);
						IPS_SetIcon($onlineID, "Electricity");
					}
				}

				if ($onlineID !== false) {
					if ($newOnline != ($online = GetValueBoolean($onlineID))) SetValueBoolean($onlineID, $newOnline);
					$result['ONLINE'] = $newOnline;
				}

				if ($itemMotion) {
					$motion = (bool)ord($data{23}); //Light = green, Sensor = motion detection

					if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
						if ($method == osrConstant::METHOD_CREATE_CHILD)
							$motionID = $this->RegisterVariableBoolean("MOTION", "Motion", "~Motion", 321);
					}

					if ($motionID !== false) {
						if (GetValueBoolean($motionID) != $motion) SetValueBoolean($motionID, $motion);
						$result['MOTION'] = $motion;
					}
				}

				if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
					if ($method == osrConstant::METHOD_CREATE_CHILD)
						$stateID = $this->RegisterVariableBoolean("STATE", "State", "~Switch", 313);
				}

				if ($stateID !== false) {
					if ($newState != ($state = GetValueBoolean($stateID))) SetValueBoolean($stateID, $newState);
					if ($itemLight || $itemPlug) $this->MaintainAction("STATE", $newOnline);

					$result['STATE'] = $newState;
				}

				if ($itemLight || $itemPlug) {
					if ($deviceRGB) {
						if (false == ($hueID = @$this->GetIDForIdent("HUE"))) {
							if ($method == osrConstant::METHOD_CREATE_CHILD)
								$this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);
						}

						if ($hueID !== false) {
							if (GetValueInteger($hueID) != $hue) SetValueInteger($hueID, $hue);
							$result['HUE'] = $hue;
						}

						if (false == ($colorID = @$this->GetIDForIdent("COLOR"))) {
							if ($method == osrConstant::METHOD_CREATE_CHILD) {
								$colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
								IPS_SetIcon($colorID, "Paintbrush");
							}
						}

						if ($colorID !== false) {
							if (GetValueInteger($colorID) != $color) SetValueInteger($colorID, $color);
							$result['COLOR'] = $color;
						}

						if (false == ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
							if ($method == osrConstant::METHOD_CREATE_CHILD) {
								$saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 318);
								IPS_SetIcon($saturationID, "Intensity");
							}
						}

						if ($saturationID !== false) {
							if (GetValueInteger($saturationID) != $saturation) SetValueInteger($saturationID, $saturation);
							$result['SATURATION'] = $saturation;
						}
					}

					if ($deviceCCT) {
						if (false == ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
							$profile = ($deviceRGB) ? "OSR.ColorTempExt" : "OSR.ColorTemp";

							if ($method == osrConstant::METHOD_CREATE_CHILD)
								$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", $profile, 316);
						}

						if ($temperatureID !== false) {
							if (GetValueInteger($temperatureID) != $temperature) SetValueInteger($temperatureID, $temperature); 
							$result['COLOR_TEMPERATURE'] = $temperature;
						}
					}

					if ($itemLight) {
						if (false == ($levelID = @$this->GetIDForIdent("LEVEL"))) {
							if ($method == osrConstant::METHOD_CREATE_CHILD) {
								$levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 317);
								IPS_SetIcon($levelID, "Sun");
							}
						}

						if ($levelID !== false) {
							if (GetValueInteger($levelID) != $level) SetValueInteger($levelID, $level);
							$result['LEVEL'] = $level;
						}
					}

					if ($newState != $state) $this->maintainVariables(osrConstant::MODE_MAINTAIN_ACTION, $newState);
				}
				break;

			case osrConstant::MODE_DEVICE_CLOUD:
				list($cloudID, $deviceType, $manufacturer, $modelName, $bmpClusters, $zigBee, $firmwareVersion) = json_decode($data);

				if ($method == osrConstant::METHOD_CREATE_CHILD) {
					if ($itemType != osrConstant::TYPE_SENSOR_MOTION && $itemType != osrConstant::TYPE_DIMMER_2WAY && $itemType != osrConstant::TYPE_SWITCH_4WAY) {
						$deviceLabel = (empty($bmpClusters)) ? "--" : implode(" ", $bmpClusters);
		
						if ($this->ReadPropertyString("manufacturer") != $manufacturer)
							IPS_SetProperty($this->InstanceID, "manufacturer", (string)$manufacturer);
		
						if ($this->ReadPropertyString("deviceModel") != $modelName)
							IPS_SetProperty($this->InstanceID, "deviceModel", (string)$modelName);
		
						if ($this->ReadPropertyString("deviceLabel") != $deviceLabel)
							IPS_SetProperty($this->InstanceID, "deviceLabel", (string)$deviceLabel);
					}
				}

				//Create and update zigBee
				if (false === ($zigBeeID = @$this->GetIDForIdent("ZIGBEE")))
					$zigBeeID = $this->RegisterVariableString("ZIGBEE", "ZigBee", "", 321);

				if (isset($zigBeeID)) {
					if ($zigBee == "FFFF" || GetValueString($zigBeeID) != $zigBee) {
						SetValueString($zigBeeID, $zigBee);
						IPS_SetDisabled($zigBeeID, true);
						IPS_SetHidden($zigBeeID, true);
					}
				}

				//Create and update firmware version
				if (false === ($firmwareID = @$this->GetIDForIdent("FIRMWARE")))
					$firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", "", 322);

				if (isset($firmwareID)) {
					if (GetValueString($firmwareID) != $firmwareVersion) {
						SetValueString($firmwareID, $firmwareVersion);
						IPS_SetDisabled($firmwareID, true);
						IPS_SetHidden($firmwareID, true);
					}
				}

				//Apply changes
				if ($apply)
					if (IPS_HasChanges($this->InstanceID)) IPS_ApplyChanges($this->InstanceID);

				$result= true;
				break;
		}

		return $result;
	}


	protected function maintainVariables($mode, $newState = false) {
		$variableList = IPS_GetChildrenIDs($this->InstanceID);
		$identList    = explode(",", osrConstant::LIST_KEY_IDENTS);

		foreach($variableList as $item) {
			$ident = IPS_GetObject($item)['ObjectIdent'];

			if (in_array($ident, $identList)) {
				if ($mode == osrConstant::MODE_DELETE_VARIABLE)
					IPS_DeleteVariable($item);
				else
					$this->MaintainAction($ident, $newState);
			}
		}

		return true;
	}

}
