<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."libs".DIRECTORY_SEPARATOR."lightifyControl.php");


class lightifyDevice extends lightifyControl {

  const ITEMID_CREATE  = 0;
  const ITEMID_MINIMUM = 1;

  private $lightifyBase;
  private $parentID;


  public function __construct(string $InstanceID) {
    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

    $connection     = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
    $this->parentID = ($connection) ? $connection : false;
  }


  public function Create() {
    parent::Create();

    $this->SetBuffer("localDevice", stdConstant::NO_STRING);
    $this->SetBuffer("cloudDevice", stdConstant::NO_STRING);
    $this->SetBuffer("saveID", serialize(stdConstant::NO_VALUE));

    $this->RegisterPropertyInteger("deviceID", self::ITEMID_CREATE);
    $this->RegisterPropertyInteger("itemClass", stdConstant::NO_VALUE);

    $this->RegisterPropertyString("UUID", stdConstant::NO_STRING);
    $this->RegisterPropertyString("manufacturer", stdConstant::NO_STRING);
    $this->RegisterPropertyString("deviceModel", stdConstant::NO_STRING);
    $this->RegisterPropertyString("deviceLabel", stdConstant::NO_STRING);

    $this->RegisterPropertyString("uintUUID", stdConstant::NO_STRING);
    $this->RegisterPropertyInteger("itemType", stdConstant::NO_VALUE);
    $this->RegisterPropertyFloat("transition", stdConstant::TRANSITION_DEFAULT);

    $this->ConnectParent(stdConstant::MODULE_GATEWAY);
    $this->parentID = ($this->parentID === false) ? IPS_GetInstance($this->InstanceID)['ConnectionID'] : $this->parentID;
  }


  public function ApplyChanges() {
    parent::ApplyChanges();

    $deviceID  = $this->ReadPropertyInteger("deviceID");
    $itemClass = $this->ReadPropertyInteger("itemClass");

    //Check config
    if ($deviceID < self::ITEMID_MINIMUM) {
      $this->SetStatus(202);
      return false;
    }

    if ($itemClass == stdConstant::NO_VALUE) {
      $this->SetStatus(203);
      return false;
    }

    if (102 == ($status = $this->setDeviceProperty($deviceID))) {
      //Apply changes
      if (IPS_HasChanges($this->InstanceID)) {
        IPS_ApplyChanges($this->InstanceID);
      }
    }

    $this->SetStatus($status);
  }


  public function GetConfigurationForm() {
    $connectMode = ($this->parentID) ? IPS_GetProperty($this->parentID, "connectMode") : stdConstant::CONNECT_LOCAL_ONLY;
    $deviceInfo  = ($this->parentID) ? IPS_GetProperty($this->parentID, "deviceInfo") : false;

    $localDevice = $this->GetBuffer("localDevice");
    $itemType    = $this->ReadPropertyInteger("itemType");

    $itemClass = $this->ReadPropertyInteger("itemClass");
    $formLabel = ($itemClass == stdConstant::NO_VALUE) ? '{ "label": "Select...",  "value":   -1 },' : stdConstant::NO_STRING;

    $infoText = ($itemClass != stdConstant::NO_VALUE && $deviceInfo && empty($localDevice) === false) ? '
      { "type": "Label", "label": "----------------------------------------- Geräte spezifische Informationen ------------------------------------------------" },
      { "type": "ValidationTextBox", "name": "UUID",         "caption": "UUID" }' : stdConstant::NO_STRING;

    switch ($itemType) {
      case stdConstant::TYPE_SENSOR_MOTION:
        $infoText = (empty($infoText)) ? "}" : "},".$infoText;

        $formJSON = '{
          "elements": [
            { "type": "NumberSpinner", "name": "deviceID",  "caption": "Device [id]" },
            { "type": "Select",        "name": "itemClass", "caption": "Class",
              "options": [
                '.$formLabel.'
                { "label": "Light",  "value": 2001 },
                { "label": "Plug",   "value": 2002 },
                { "label": "Sensor", "value": 2003 }
              ]
            '.$infoText.'
          ],
          "status": [
            { "code": 102, "icon": "active",   "caption": "Device is active"                  },
            { "code": 104, "icon": "inactive", "caption": "Device is inactive"                },
            { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
            { "code": 202, "icon": "error",    "caption": "Invalid Device [id]"               },
            { "code": 203, "icon": "error",    "caption": "Invalid Class"                     }
          ]
        }';
        break;

      default:
        $cloudDevice = $this->GetBuffer("cloudDevice");
        if (empty($infoText) === false) $infoText .= ",";

        $infoText = ($connectMode == stdConstant::CONNECT_LOCAL_CLOUD && empty($cloudDevice) === false && empty($infoText) === false) ? $infoText.'
          { "type": "ValidationTextBox", "name": "manufacturer", "caption": "Manufacturer" },
          { "type": "ValidationTextBox", "name": "deviceModel",  "caption": "Model"        },
          { "type": "ValidationTextBox", "name": "deviceLabel",  "caption": "Capabilities" },' : $infoText;

        $formJSON = '{
          "elements": [
            { "type": "NumberSpinner", "name": "deviceID",  "caption": "Device [id]" },
            { "type": "Select",        "name": "itemClass", "caption": "Class",
              "options": [
                '.$formLabel.'
                { "label": "Light",  "value": 2001 },
                { "label": "Plug",   "value": 2002 },
                { "label": "Sensor", "value": 2003 }
              ]
            },
            '.$infoText.'
            { "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
          ],
          "actions": [
            { "type": "Button", "label": "On",  "onClick": "OSR_SetValue($id, \"STATE\", true)"  },
            { "type": "Button", "label": "Off", "onClick": "OSR_SetValue($id, \"STATE\", false)" }
          ],
          "status": [
            { "code": 102, "icon": "active",   "caption": "Device is active"                  },
            { "code": 104, "icon": "inactive", "caption": "Device is inactive"                },
            { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
            { "code": 202, "icon": "error",    "caption": "Invalid Device [id]"               },
            { "code": 203, "icon": "error",    "caption": "Invalid Class"                     }
          ]
        }';
    }

    return $formJSON;
  }


  public function ReceiveData($jsonString) {
    $deviceID = $this->ReadPropertyInteger("deviceID");
    $data     = json_decode($jsonString);

    switch ($data->mode) {
      case stdConstant::MODE_DEVICE_LOCAL:
        $localBuffer = utf8_decode($data->buffer);
        $localCount  = ord($localBuffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("localDevice", $localBuffer{0}.$localDevice);

        if (empty($localDevice) === false) {
          if ($data->debug % 2 || $data->message) {
            $info = $localCount."/".$this->lightifyBase->decodeData($localDevice);

            if ($data->debug % 2) {
	            IPS_SendDebug($this->parentID, "<DEVICE|RECEIVEDATA|DEVICES:LOCAL>", $info, 0);
	          }

            if ($data->message) {
	            IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|DEVICES:LOCAL>   ".$info);
	          }
          }

          $this->setDeviceInfo($data->method, $data->mode, $localDevice);
        }
        break;

      case stdConstant::MODE_DEVICE_CLOUD:
        $cloudDevice = $this->getDeviceCloud($deviceID, $data->buffer);
        $this->SetBuffer("cloudDevice", $cloudDevice);

        if (empty($cloudDevice) === false) {
          if ($data->debug % 2 || $data->message) {
            $info = $this->lightifyBase->decodeData($cloudDevice);

            if ($data->debug % 2) {
	            IPS_SendDebug($this->parentID, "<DEVICE|RECEIVEDATA|DEVICES:CLOUD>", $info, 0);
	          }

            if ($data->message) {
	            IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|DEVICES:CLOUD>   ".$info);
	          }
          }

          $this->setDeviceInfo($data->method, $data->mode, $cloudDevice, true);
        }
        break;
    }
  }


  private function setDeviceProperty($deviceID) {
    $jsonString = $this->SendDataToParent(json_encode(array(
      'DataID' => stdConstant::TX_GATEWAY,
      'method' => stdConstant::METHOD_APPLY_CHILD,
      'mode'   => stdConstant::MODE_DEVICE_LOCAL))
    );

    if ($jsonString != stdConstant::NO_STRING) {
      $localData   = json_decode($jsonString);
      $localBuffer = utf8_decode($localData->buffer);
      $localCount  = ord($localBuffer{0});

      //Store device buffer
      $localDevice = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
      $this->SetBuffer("localDevice", $localBuffer{0}.$localDevice);

      if (empty($localDevice) === false) {
        $uintUUID  = substr($localDevice, 2, stdConstant::UUID_DEVICE_LENGTH);
        $itemType  = ord($localDevice{10});

        if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
          IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);
        }

        if ($this->ReadPropertyInteger("itemType") != $itemType) {
          IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);
        }

        if (IPS_GetProperty($this->parentID, "deviceInfo")) {
          $UUID = $this->lightifyBase->ChrToUUID($uintUUID);

          if ($this->ReadPropertyString("UUID") != $UUID) {
            IPS_SetProperty($this->InstanceID, "UUID", (string)$UUID);
          }

          $jsonString = $this->SendDataToParent(json_encode(array(
            'DataID' => stdConstant::TX_GATEWAY,
            'method' => stdConstant::METHOD_APPLY_CHILD,
            'mode'   => stdConstant::MODE_DEVICE_CLOUD))
          );

          if ($jsonString != stdConstant::NO_STRING) {
            $cloudData = json_decode($jsonString);

            //Store group device buffer
            $cloudDevice = $this->getDeviceCloud($deviceID, $cloudData->buffer);
            $this->SetBuffer("cloudDevice", $cloudDevice);

            if (empty($cloudDevice) === false) {
              $this->setDeviceInfo(stdConstant::METHOD_CREATE_CHILD, stdConstant::MODE_DEVICE_CLOUD, $cloudDevice, true);
            }
          }
        }

        $this->setDeviceInfo(stdConstant::METHOD_CREATE_CHILD, stdConstant::MODE_DEVICE_LOCAL, $localDevice);
        return 102;
      }

      return 104;
    }

    return 102;
  }


  private function getDeviceLocal($deviceID, $buffer, $ncount) {
    $localDevice = stdConstant::NO_STRING;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{0});
      $buffer  = substr($buffer, 1);

      if ($deviceID == $localID) {
        $localDevice = substr($buffer, 0, stdConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $buffer = substr($buffer, stdConstant::DATA_DEVICE_LENGTH);
    }

    return $localDevice;
  }


  private function getDeviceCloud($deviceID, $buffer) {
    $cloudDevice = stdConstant::NO_STRING;
    $cloudBuffer = json_decode($buffer);

    foreach ($cloudBuffer as $device) {
      list($cloudID) = $device;

      if ($deviceID == $cloudID) {
        $cloudDevice = json_encode($device);
        break;
      }
    }

    return $cloudDevice;
  }


  private function setDeviceInfo($method, $mode, $data, $apply = false) {
    $itemType = ord($data{10});

    switch ($mode) {
      case stdConstant::MODE_DEVICE_LOCAL:
        $itemLight = $itemPlug = $itemMotion = false;

        //Decode Device label
        switch ($itemType) {
          case stdConstant::TYPE_PLUG_ONOFF:
            $itemPlug = true;
            break;

          case stdConstant::TYPE_SENSOR_MOTION:
            $itemMotion = true;
            break;

          default:
            $itemLight = true;
        }

        $deviceRGB  = ($itemType & 8) ? true: false;
        $deviceCCT  = ($itemType & 2) ? true: false;
        $deviceCLR  = ($itemType & 4) ? true: false;

        $hue    = $color = $level      = stdConstant::NO_STRING;
        $temperature     = $saturation = stdConstant::NO_STRING;

        if ($itemLight || $itemPlug || $itemMotion) {
          $online    = (ord($data{15}) == stdConstant::STATE_ONLINE) ? true : false; //Online: 2 - Offline: 0 - Unknown: 1
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

        if ($deviceCCT) {
	        $temperature = hexdec(dechex(ord($data{21})).dechex(ord($data{20})));
	      }

        if ($itemLight) {
	        $level = ord($data{19});
	      }

        //Additional informations
        $zigBee   = dechex(ord($data{0})).dechex(ord($data{1}));
        $firmware = stdConstant::NO_STRING;

        if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
          if ($method == stdConstant::METHOD_CREATE_CHILD) {
            $onlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "OSR.Switch", 312);
            IPS_SetIcon($onlineID, "Electricity");
          }
        }

        if ($onlineID !== false) {
          if ($newOnline != ($online = GetValueBoolean($onlineID))) {
	          SetValueBoolean($onlineID, $newOnline);
	        }
        }

        if ($itemMotion) {
          $motion = (bool)ord($data{23}); //Light = green, Sensor = motion detection

          if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $motionID = $this->RegisterVariableBoolean("MOTION", "Motion", "~Motion", 321);
            }
          }

          if ($motionID !== false) {
            if (GetValueBoolean($motionID) != $motion) {
	            SetValueBoolean($motionID, $motion);
	          }
          }
        }

        if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
          if ($method == stdConstant::METHOD_CREATE_CHILD) {
            $stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 313);
          }
        }

        if ($stateID !== false) {
          if ($newState != ($state = GetValueBoolean($stateID))) {
	          SetValueBoolean($stateID, $newState);
	        }

          if ($itemLight || $itemPlug) {
	          $this->MaintainAction("STATE", $newOnline);
	        }
        }

        if ($itemLight || $itemPlug) {
          if ($deviceRGB) {
            if (false == ($hueID = @$this->GetIDForIdent("HUE"))) {
              if ($method == stdConstant::METHOD_CREATE_CHILD) {
                $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);
              }
            }

            if ($hueID !== false) {
              if (GetValueInteger($hueID) != $hue) {
	              SetValueInteger($hueID, $hue);
	            }
            }

            if (false == ($colorID = @$this->GetIDForIdent("COLOR"))) {
              if ($method == stdConstant::METHOD_CREATE_CHILD) {
                $colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
                IPS_SetIcon($colorID, "Paintbrush");
              }
            }

            if ($colorID !== false) {
              if (GetValueInteger($colorID) != $color) {
	              SetValueInteger($colorID, $color);
	            }
            }

            if (false == ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
              if ($method == stdConstant::METHOD_CREATE_CHILD) {
                $saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 318);
                IPS_SetIcon($saturationID, "Intensity");
              }
            }

            if ($saturationID !== false) {
              if (GetValueInteger($saturationID) != $saturation) {
	              SetValueInteger($saturationID, $saturation);
	            }
            }
          }

          if ($deviceCCT) {
            if (false == ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
              $profile = ($deviceRGB) ? "OSR.ColorTempExt" : "OSR.ColorTemp";

              if ($method == stdConstant::METHOD_CREATE_CHILD) {
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", $profile, 316);
              }
            }

            if ($temperatureID !== false) {
              if (GetValueInteger($temperatureID) != $temperature) {
	              SetValueInteger($temperatureID, $temperature);
	            }
            }
          }

          if ($itemLight) {
            if (false == ($levelID = @$this->GetIDForIdent("LEVEL"))) {
              if ($method == stdConstant::METHOD_CREATE_CHILD) {
                $levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 317);
                IPS_SetIcon($levelID, "Sun");
              }
            }

            if ($levelID !== false) {
              if (GetValueInteger($levelID) != $level) {
	              SetValueInteger($levelID, $level);
	            }
            }
          }
       }
        break;

      case stdConstant::MODE_DEVICE_CLOUD:
        list($cloudID, $deviceType, $manufacturer, $deviceModel, $bmpClusters, $zigBee, $firmware) = json_decode($data);

        if ($method == stdConstant::METHOD_CREATE_CHILD) {
          if ($itemType != stdConstant::TYPE_SENSOR_MOTION && $itemType != stdConstant::TYPE_DIMMER_2WAY && $itemType != stdConstant::TYPE_SWITCH_4WAY) {
            $deviceLabel = (empty($bmpClusters)) ? stdConstant::NO_STRING : implode(" ", $bmpClusters);
    
            if ($this->ReadPropertyString("manufacturer") != $manufacturer) {
              IPS_SetProperty($this->InstanceID, "manufacturer", (string)$manufacturer);
            }

            if ($this->ReadPropertyString("deviceModel") != $deviceModel) {
              IPS_SetProperty($this->InstanceID, "deviceModel", (string)$deviceModel);
            }

            if ($this->ReadPropertyString("deviceLabel") != $deviceLabel) {
              IPS_SetProperty($this->InstanceID, "deviceLabel", (string)$deviceLabel);
            }
          }
        }

        //Create and update zigBee
        if (false === ($zigBeeID = @$this->GetIDForIdent("ZIGBEE"))) {
          if ($method == stdConstant::METHOD_CREATE_CHILD) {
            $zigBeeID = $this->RegisterVariableString("ZIGBEE", "ZigBee", stdConstant::NO_STRING, 321);
          }
        }

        if ($zigBeeID !== false) {
          if ($zigBee == "FFFF" || GetValueString($zigBeeID) != $zigBee) {
            SetValueString($zigBeeID, $zigBee);
            IPS_SetDisabled($zigBeeID, true);
            IPS_SetHidden($zigBeeID, true);
          }
        }

        //Create and update firmware version
        if (false === ($firmwareID = @$this->GetIDForIdent("FIRMWARE"))) {
          if ($method == stdConstant::METHOD_CREATE_CHILD) {
            $firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", stdConstant::NO_STRING, 322);
          }
        }

        if ($firmwareID !== false) {
          if (GetValueString($firmwareID) != $firmware) {
            SetValueString($firmwareID, $firmware);
            IPS_SetDisabled($firmwareID, true);
            IPS_SetHidden($firmwareID, true);
          }
        }

        //Apply changes
        if ($apply) {
          if (IPS_HasChanges($this->InstanceID)) {
	          IPS_ApplyChanges($this->InstanceID);
	        }
        }

        break;
    }
  }

}
