<?

//require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyClass.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyControl.php");


//class lightifyGroup extends IPSModule {
class lightifyGroup extends lightifyControl {

	//Instance specific
	const LIST_ELEMENTS_INDEX      = 3;

	const STATUS_ITEM_ACTIVE       = 102;
	const STATUS_ITEM_INACTIVE     = 104;
	const ERROR_GATEWAY_CONNECTION = 201;
	const ERROR_INVALID_ITEM_ID    = 202;


	const ITEM_CREATE_ID           = 0;
	const ITEM_MIN_ID              = 1;

	const ROW_COLOR_LIGHT_ON       = "#fffde7";
	const ROW_COLOR_CCT_ON         = "#ffecB3";
	const ROW_COLOR_PLUG_ON        = "#c5e1a5";
	const ROW_COLOR_ONLINE_OFF     = "#ffffff";
	const ROW_COLOR_LIGHT_OFF      = "#e0e0e0";
	const ROW_COLOR_PLUG_OFF       = "#ef9a9a";


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

		$this->SetBuffer("groupDevice", osrConstant::NO_STRING);
		$this->SetBuffer("groupScene", osrConstant::NO_STRING);
		$this->SetBuffer("saveID", serialize(osrConstant::NO_VALUE));

		$this->RegisterPropertyInteger("itemID", self::ITEM_CREATE_ID);
		$this->RegisterPropertyString("UUID", osrConstant::NO_STRING);
		$this->RegisterPropertyInteger("itemClass", osrConstant::CLASS_LIGHTIFY_GROUP);
		$this->RegisterPropertyString("deviceList", osrConstant::NO_STRING);

		$this->RegisterPropertyString("uintUUID", osrConstant::NO_STRING);
		$this->RegisterPropertyInteger("itemType", osrConstant::NO_VALUE);
		$this->RegisterPropertyString("allLights", osrConstant::NO_STRING);

		$this->ConnectParent(osrConstant::MODULE_GATEWAY);
	}


	public function ApplyChanges() {
		parent::ApplyChanges();

		//Check config
		if (($itemID = $this->ReadPropertyInteger("itemID")) < self::ITEM_MIN_ID) {
			$this->SetStatus(self::ERROR_INVALID_ITEM_ID);
			return false;
		}

		//Set properties
		$itemClass = $this->ReadPropertyInteger("itemClass");
		$status    = ($itemClass == osrConstant::CLASS_LIGHTIFY_GROUP) ? $this->setGroupProperty($itemID) : $this->setSceneProperty($itemID);

		if ($status == self::STATUS_ITEM_ACTIVE)
			if (IPS_HasChanges($this->InstanceID)) IPS_ApplyChanges($this->InstanceID);

		$this->SetStatus($status);
	}


	public function GetConfigurationForm() {
		$groupDevice = $this->GetBuffer("groupDevice");
		$itemType    = $this->ReadPropertyInteger("itemType");

		switch ($itemType) {
			case osrConstant::TYPE_DEVICE_GROUP:
				$deviceList  = (empty($groupDevice) === false && ord($groupDevice{0}) > 0) ? '
					{ "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" },
					{ "type": "List",  "name":  "deviceList", "caption": "Devices",
						"columns": [
							{ "label": "Instance ID", "name": "InstanceID",  "width": "60px", "visible": false },
							{ "label": "ID",          "name": "deviceID",    "width": "35px"  },
							{ "label": "Name",        "name": "name",        "width": "120px" },
							{ "label": "Hue",         "name": "hue",         "width": "35px"  },
							{ "label": "Color",       "name": "color",       "width": "60px"  },
							{ "label": "Temperature", "name": "temperature", "width": "80px"  },
							{ "label": "Level",       "name": "level",       "width": "50px"  },
							{ "label": "Saturation",  "name": "saturation",  "width": "70px"  }
						]
				},' : "";

				$formJSON = '{
					"elements": [
						{ "type": "NumberSpinner",    "name": "itemID",    "caption": "Group [id]" },
						{ "type": "Select",           "name": "itemClass", "caption": "Class",
							"options": [
								{ "label": "Group", "value": 2006 }
							]
						},
						'.$deviceList.'
						{ "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
					],
					"actions": [
						{ "type": "Button", "label": "On",  "onClick": "OSR_SetValue($id, \"STATE\", true)"  },
						{ "type": "Button", "label": "Off", "onClick": "OSR_SetValue($id, \"STATE\", false)" }
					],
					"status": [
						{ "code": 102, "icon": "active",   "caption": "Group is active"                   },
						{ "code": 104, "icon": "inactive", "caption": "Group is inactive"                 },
						{ "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
						{ "code": 202, "icon": "error",    "caption": "Invalid Group [id]"                }
					]
				}';
				break;

			case osrConstant::TYPE_GROUP_SCENE:
				$formJSON = '{
					"elements": [
						{ "type": "NumberSpinner", "name": "itemID",             "caption": "Scene [id]" },
						{ "type": "Select",        "name": "itemClass",          "caption": "Class",
							"options": [
								{ "label": "Scene", "value": 2007 }
							]
						}
					],
					"actions": [
						{ "type": "Button", "label": "Activate",  "onClick": "OSR_SetValue($id, \"SCENE\", 2)" }
					],
					"status": [
						{ "code": 102, "icon": "active",   "caption": "Scene is active"                   },
						{ "code": 104, "icon": "inactive", "caption": "Scene is inactive"                 },
						{ "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
						{ "code": 202, "icon": "error",    "caption": "Invalid Scene [id]"                }
					]
				}';
				return $formJSON;

			case osrConstant::TYPE_ALL_LIGHTS:
				$formJSON = '{
				}';
				return $formJSON;

			default:
				$formJSON = '{
					"elements": [
						{ "type": "NumberSpinner", "name": "itemID",    "caption": "Group/Scene [id]" },
						{ "type": "Select",        "name": "itemClass", "caption": "Class",
							"options": [
								{ "label": "Group", "value": 2006 },
								{ "label": "Scene", "value": 2007 }
							]
						}
					],
					"status": [
						{ "code": 102, "icon": "active",   "caption": "Group/Scene is active"             },
						{ "code": 104, "icon": "inactive", "caption": "Group/Scene is inactive".          },
						{ "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
						{ "code": 202, "icon": "error",    "caption": "Invalid Group/Scene [id]"          }
					]
				}';
				return $formJSON;
		}

		if (empty($groupDevice) === false && ($dcount = ord($groupDevice{0})) > 0) {
			$groupDevice = substr($groupDevice, 1);
			$data        = json_decode($formJSON);

			for ($i = 1; $i <= $dcount; $i++) {
				$uintUUID = substr($groupDevice, 0, osrConstant::UUID_DEVICE_LENGTH);

				if ($instanceID = $this->lightifyBase->getObjectByProperty(osrConstant::MODULE_DEVICE, "uintUUID", $uintUUID)) {
					if (IPS_GetInstance($instanceID)['ConnectionID'] != $this->parentID)
						continue;

					$onlineID      = @IPS_GetObjectIDByIdent('ONLINE', $instanceID);
					$stateID       = @IPS_GetObjectIDByIdent('STATE', $instanceID);
					$online        = ($onlineID) ? GetValueBoolean($onlineID) : osrConstant::NO_STRING;
					$state         = ($stateID) ? GetValueBoolean($stateID) : osrConstant::NO_STRING;

					$deviceID      = @IPS_GetProperty($instanceID, "deviceID");
					$itemClass     = @IPS_GetProperty($instanceID, "itemClass");

					switch ($itemClass) {
						case osrConstant::CLASS_LIGHTIFY_LIGHT:
							$classInfo = "Lampe";
							break;

						case osrConstant::CLASS_LIGHTIFY_PLUG:
							$classInfo = "Steckdose";
							break;
					} 

					$hueID         = @IPS_GetObjectIDByIdent("HUE", $instanceID);
					$colorID       = @IPS_GetObjectIDByIdent("COLOR", $instanceID);
					$temperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $instanceID);
					$levelID       = @IPS_GetObjectIDByIdent("LEVEL", $instanceID);
					$saturationID  = @IPS_GetObjectIDByIdent("SATURATION", $instanceID);

					$hue           = ($hueID) ?  GetValueformatted($hueID) : osrConstant::NO_STRING;
					$color         = ($colorID) ? strtolower(GetValueformatted($colorID)) : osrConstant::NO_STRING;
					$temperature   = ($temperatureID) ? GetValueformatted($temperatureID) : osrConstant::NO_STRING;
					$level         = ($levelID) ? preg_replace('/\s+/', '', GetValueformatted($levelID)) : osrConstant::NO_STRING;
					$saturation    = ($saturationID) ? preg_replace('/\s+/', '', GetValueformatted($saturationID)) : osrConstant::NO_STRING;

					if ($state) {
						if (@IPS_GetProperty($instanceID, "itemType") == osrConstant::TYPE_PLUG_ONOFF)
							$rowColor = self::ROW_COLOR_PLUG_ON;
						else
							$rowColor = ($temperature) ? self::ROW_COLOR_CCT_ON : self::ROW_COLOR_LIGHT_ON;
					} else {
						if (@IPS_GetProperty($instanceID, "itemType") == osrConstant::TYPE_PLUG_ONOFF)
							$rowColor = ($online) ? self::ROW_COLOR_ONLINE_OFF : self::ROW_COLOR_PLUG_OFF;
						else
							$rowColor = ($online) ? self::ROW_COLOR_ONLINE_OFF : self::ROW_COLOR_LIGHT_OFF;
					}

					$data->elements[self::LIST_ELEMENTS_INDEX]->values[] = array(
						"InstanceID"  => $instanceID,
						"deviceID"    => $deviceID,
						"name"        => IPS_GetName($instanceID),
						"hue"         => $hue,
						"color"       => ($color != osrConstant::NO_STRING) ? "#".strtoupper($color) : osrConstant::NO_STRING,
						"temperature" => $temperature,
						"level"       => $level,
						"saturation"  => $saturation,
						"rowColor"    => $rowColor
					);
				}

				$groupDevice = substr($groupDevice, osrConstant::UUID_DEVICE_LENGTH);
			}

			return json_encode($data);
		}

		return $formJSON;
	}


	public function ReceiveData($jsonString) {
		$itemID = $this->ReadPropertyInteger("itemID");
		$data   = json_decode($jsonString);

		$localBuffer = utf8_decode($data->Buffer);
		$localCount  = ord($localBuffer{0});
		$itemType    = $this->ReadPropertyInteger("itemType");

		switch ($data->Mode) {
			case osrConstant::MODE_GROUP_LOCAL:
				//Store device group buffer
				if ($itemType == osrConstant::TYPE_DEVICE_GROUP) {
					$groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
					$this->SetBuffer("groupDevice", $groupDevice);
	
					if (empty($groupDevice) === false) {
						if ($data->Debug % 2 || $data->Message) {
							$info = $localCount."/".$this->lightifyBase->decodeData($groupDevice);

							if ($data->Debug % 2) IPS_SendDebug($this->parentID, "<GROUP|RECEIVEDATA|GROUPS:LOCAL>", $info, 0);
							if ($data->Message) IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|GROUPS:LOCAL>   ".$info);
						}

						$this->setGroupInfo($data->Mode, $data->Method, $groupDevice);
					}
				}
				break;

			case osrConstant::MODE_GROUP_CLOUD:
				break;

			case osrConstant::MODE_GROUP_SCENE:
				//Store group scene buffer
				if ($itemType == osrConstant::TYPE_GROUP_SCENE) {
					$groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
					$this->SetBuffer("groupScene", $groupScene);

					if (empty($groupScene) === false) {
						if ($data->Debug % 2 || $data->Message) {
							$info = ord($groupScene{0})."/".ord($groupScene{1})."/".$this->lightifyBase->decodeData($groupScene);

							if ($data->Debug % 2) IPS_SendDebug($this->parentID, "<GROUP|RECEIVEDATA|SCENES:CLOUD>", $info, 0);
							if ($data->Message) IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|SCENES:CLOUD>   ".$info);
						}

						$this->setSceneInfo($data->Mode, $data->Method);
					}
				}
				break;
		}
	}


	private function setGroupProperty($itemID) {
		$jsonString = $this->SendDataToParent(json_encode(array(
			'DataID' => osrConstant::TX_GATEWAY,
			'Method' => osrConstant::METHOD_APPLY_CHILD,
			'Mode'   => osrConstant::MODE_GROUP_LOCAL))
		);

		if ($jsonString != osrConstant::NO_STRING) {
			$localData   = json_decode($jsonString);
			$localBuffer = utf8_decode($localData->Buffer);
			$localCount  = ord($localBuffer{0});

			//Store group device buffer
			$groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
			$this->SetBuffer("groupDevice", $groupDevice);

			if (empty($groupDevice) === false) {
				$itemType = ord($localBuffer{1});
				$uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
				$saveID   = unserialize($this->GetBuffer("saveID"));

				if ($saveID != $itemID) {
					if ($saveID != osrConstant::NO_VALUE)
						$this->maintainVariables(osrConstant::MODE_DELETE_VARIABLE);

					$this->SetBuffer("saveID", serialize($itemID));
				}

				if ($this->ReadPropertyString("uintUUID") != $uintUUID)
					IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);

				if ($this->ReadPropertyInteger("itemType") != $itemType)
					IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);

				$this->setGroupInfo(osrConstant::MODE_GROUP_LOCAL, osrConstant::METHOD_CREATE_CHILD, $groupDevice);
				return self::STATUS_ITEM_ACTIVE;
			}

			$this->maintainVariables(osrConstant::MODE_DELETE_VARIABLE);
			return self::STATUS_ITEM_INACTIVE;
		}

		return self::STATUS_ITEM_ACTIVE;
	}


	private function setSceneProperty($itemID) {
		$jsonString = $this->SendDataToParent(json_encode(array(
			'DataID' => osrConstant::TX_GATEWAY,
			'Method' => osrConstant::METHOD_APPLY_CHILD,
			'Mode'   => osrConstant::MODE_GROUP_SCENE))
		);

		if ($jsonString != osrConstant::NO_STRING) {
			$localData   = json_decode($jsonString);
			$localBuffer = utf8_decode($localData->Buffer);
			$localCount  = ord($localBuffer{0});

			//Store group scene buffer
			$groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
			$this->SetBuffer("groupScene", $groupScene);

			if (empty($groupScene) === false) {
				$itemType = ord($localBuffer{1});
				$uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
				$saveID   = unserialize($this->GetBuffer("saveID"));

				if ($saveID != $itemID) {
					if ($saveID != osrConstant::NO_VALUE)
						$this->maintainVariables(osrConstant::MODE_DELETE_VARIABLE);

					$this->SetBuffer("saveID", serialize($itemID));
				}

				if ($this->ReadPropertyString("uintUUID") != $uintUUID)
					IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);

				if ($this->ReadPropertyInteger("itemType") != $itemType)
					IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);

				$this->setSceneInfo(osrConstant::MODE_GROUP_LOCAL, osrConstant::METHOD_CREATE_CHILD);
				return self::STATUS_ITEM_ACTIVE;
			}

			$this->maintainVariables(osrConstant::MODE_DELETE_VARIABLE);
			return self::STATUS_ITEM_INACTIVE;
		}

		return self::STATUS_ITEM_ACTIVE;
	}


	private function getGroupDevice($itemID, $buffer, $ncount) {
		$groupDevice = "";

		for ($i = 1; $i <= $ncount; $i++) {
			$localID = ord($buffer{0});

			if (($dcount = ord($buffer{1})) > 0) {
				$buffer = substr($buffer, 2);
	
				if ($itemID == $localID) {
					$groupDevice = chr($dcount).substr($buffer, 0, $dcount*osrConstant::UUID_DEVICE_LENGTH);
					break;
				}
			}

			$buffer = substr($buffer, $dcount*osrConstant::UUID_DEVICE_LENGTH);
		}

		return $groupDevice;
	}


	private function getGroupScene($itemID, $buffer, $ncount) {
		$groupScene = "";

		for ($i = 1; $i <= $ncount; $i++) {
			$localID = ord($buffer{1});

			if ($itemID == $localID) {
				$groupScene = substr($buffer, 0, osrConstant::DATA_SCENE_LENGTH);
				break;
			}

			$buffer = substr($buffer, osrConstant::DATA_SCENE_LENGTH);
		}

		return $groupScene;
	}


	private function setGroupInfo($mode, $method, $data) {
		switch ($mode) {
			case osrConstant::MODE_GROUP_LOCAL:
				if (($dcount = ord($data{0})) > 0) {
					$data    = substr($data, 1);
					$Devices = array();

					for ($i = 1; $i <= $dcount; $i++) {
						$uintUUID = substr($data, 0, osrConstant::UUID_DEVICE_LENGTH);
	
						if (false !== ($instanceID = $this->lightifyBase->getObjectByProperty(osrConstant::MODULE_DEVICE, "uintUUID", $uintUUID)))
							$Devices[] = $instanceID;
	
						$data = substr($data, osrConstant::UUID_DEVICE_LENGTH);
					}
	
					//Set group/zone state
					$online       = $state      = false;
					$newOnline    = $online;
					$newState     = $state;
	
					$hue = $color = $level      = osrConstant::NO_STRING;
					$temperature  = $saturation = osrConstant::NO_STRING;
	
					$deviceHue         = $deviceColor = $deviceLevel = osrConstant::NO_STRING;
					$deviceTemperature = $deviceSaturation           = osrConstant::NO_STRING;
	
					foreach ($Devices as $device) {
						$deviceOnlineID      = @IPS_GetObjectIDByIdent('ONLINE', $device);
						$deviceStateID       = @IPS_GetObjectIDByIdent('STATE', $device);
						$deviceOnline        = ($deviceOnlineID) ? GetValueBoolean($deviceOnlineID) : osrConstant::NO_STRING;
						$deviceState         = ($deviceStateID) ? GetValueBoolean($deviceStateID) : osrConstant::NO_STRING;
	
						$deviceHueID         = @IPS_GetObjectIDByIdent("HUE", $device);
						$deviceColorID       = @IPS_GetObjectIDByIdent("COLOR", $device);
						$deviceTemperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $device);
						$deviceLevelID       = @IPS_GetObjectIDByIdent("LEVEL", $device);
						$deviceSaturationID  = @IPS_GetObjectIDByIdent("SATURATION", $device);
	
						$deviceHue           = ($deviceHueID) ?  GetValueInteger($deviceHueID) : osrConstant::NO_STRING;
						$deviceColor         = ($deviceColorID) ? GetValueInteger($deviceColorID) : osrConstant::NO_STRING;
						$deviceTemperature   = ($deviceTemperatureID) ? GetValueInteger($deviceTemperatureID) : osrConstant::NO_STRING;
						$deviceLevel         = ($deviceLevelID) ? GetValueInteger($deviceLevelID) : osrConstant::NO_STRING;
						$deviceSaturation    = ($deviceSaturationID) ? GetValueInteger($deviceSaturationID) : osrConstant::NO_STRING;
	
						if ($online === false && $deviceOnline === true) $newOnline = true;
						if ($state === false && $deviceState === true) $newState = true;
	
						if ($newOnline && $hue == osrConstant::NO_STRING && $deviceHue != osrConstant::NO_STRING) $hue = $deviceHue;
						if ($newOnline && $color == osrConstant::NO_STRING && $deviceColor != osrConstant::NO_STRING) $color = $deviceColor;
						if ($newOnline && $level == osrConstant::NO_STRING && $deviceLevel != osrConstant::NO_STRING) $level = $deviceLevel;
						if ($newOnline && $temperature == osrConstant::NO_STRING && $deviceTemperature != osrConstant::NO_STRING) $temperature = $deviceTemperature;
						if ($newOnline && $saturation == osrConstant::NO_STRING && $deviceSaturation != osrConstant::NO_STRING) $saturation = $deviceSaturation;
					}
	
					if ($stateID = @$this->GetIDForIdent("STATE"))
						$this->MaintainAction("STATE", $newOnline);
	
					if ($stateID === false) {
						if ($method == osrConstant::METHOD_CREATE_CHILD)
							$stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 0);
					}
	
					if ($stateID)
						if ($newState != ($state = GetValueBoolean($stateID))) SetValueBoolean($stateID, $newState);
	
					//Hue
					if ($hueID = @$this->GetIDForIdent("HUE"))
						$this->MaintainVariable("HUE", "Hue", osrConstant::IPS_INTEGER, "OSR.Hue", 1, $hue != osrConstant::NO_STRING);
	
					if ($hue != osrConstant::NO_STRING) {
						if ($hueID === false && $method == osrConstant::METHOD_CREATE_CHILD)
							$hueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 1);
	
						if ($hueID)
							if ($hue != GetValueInteger($hueID)) SetValueInteger($hueID, $hue);
					}
	
					//Color
					if ($colorID = @$this->GetIDForIdent("COLOR"))
						$this->MaintainVariable("COLOR", "Color", osrConstant::IPS_INTEGER, "~HexColor", 2, $color != osrConstant::NO_STRING);
	
					if ($color != osrConstant::NO_STRING) {
						if ($colorID === false && $method == osrConstant::METHOD_CREATE_CHILD) {
							$colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 2);
							IPS_SetIcon($colorID, "Paintbrush");
						}
	
						if ($colorID)
							if ($color != GetValueInteger($colorID)) SetValueInteger($colorID, $color);
					}
	
					//Color temperature
					if ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))
						$this->MaintainVariable("COLOR_TEMPERATURE", "Color Temperature", osrConstant::IPS_INTEGER, "OSR.ColorTempExt", 3, $temperature != osrConstant::NO_STRING);
	
					if ($temperature != osrConstant::NO_STRING) {
						if ($temperatureID === false && $method == osrConstant::METHOD_CREATE_CHILD)
							$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTempExt", 3);
	
						if ($temperatureID)
							if ($temperature != GetValueInteger($temperatureID)) SetValueInteger($temperatureID, $temperature);
					}
	
					//Level
					if ($levelID = @$this->GetIDForIdent("LEVEL"))
						$this->MaintainVariable("LEVEL", "Level", osrConstant::IPS_INTEGER, "OSR.Intensity", 4, $level != osrConstant::NO_STRING);
	
					if ($level != osrConstant::NO_STRING) {
						if ($levelID === false && $method == osrConstant::METHOD_CREATE_CHILD) {
							$levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 4);
							IPS_SetIcon($levelID, "Sun");
						}
	
						if ($levelID)
							if ($level != GetValueInteger($levelID)) SetValueInteger($levelID, $level);
					}
	
					//Saturation control
					if ($saturationID = @$this->GetIDForIdent("SATURATION"))
						$this->MaintainVariable("SATURATION", "Saturation", osrConstant::IPS_INTEGER, "OSR.Intensity", 5, $saturation != osrConstant::NO_STRING);
	
					if ($saturation != osrConstant::NO_STRING) {
						if ($saturationID === false && $method == osrConstant::METHOD_CREATE_CHILD) {
							$saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 5);
							IPS_SetIcon($saturationID, "Intensity");
						}
	
						if ($saturationID)
							if ($saturation != GetValueInteger($saturationID)) SetValueInteger($saturationID, $saturation);
					}
	
					if (isset($state) && $state != $newState) $this->maintainVariables(osrConstant::MODE_MAINTAIN_ACTION, $newState);
				}
				break;

			case osrConstant::MODE_GROUP_CLOUD:
				$cloudGroup = json_decode($data);
				return true;
		}
	}


	private function setSceneInfo($mode, $method) {
		//Create and update switch
		if (false === ($sceneID = @$this->GetIDForIdent("SCENE"))) {
			if ($method == osrConstant::METHOD_CREATE_CHILD) {
				$sceneID = $this->RegisterVariableInteger("SCENE", "Szene", "OSR.Scene", 311);
				$this->EnableAction("SCENE");
			}
		}

		if ($sceneID !== false && GetValueInteger($sceneID) != 1) SetValueInteger($sceneID, 1);
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
