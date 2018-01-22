<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."libs".DIRECTORY_SEPARATOR."lightifyControl.php");


class lightifyDevice extends lightifyControl {

  const ITEMID_CREATE  = 0;
  const ITEMID_MINIMUM = 1;

  private $parentID = null;
  private $debug;
  private $message;


  public function Create() {
    parent::Create();

    $this->SetBuffer("localDevice", classConstant::NO_STRING);
    $this->SetBuffer("cloudDevice", classConstant::NO_STRING);
    $this->SetBuffer("saveID", serialize(classConstant::NO_VALUE));

    $this->RegisterPropertyInteger("deviceID", self::ITEMID_CREATE);
    $this->RegisterPropertyInteger("itemClass", classConstant::NO_VALUE);

    $this->RegisterPropertyString("UUID", classConstant::NO_STRING);
    $this->RegisterPropertyString("manufacturer", classConstant::NO_STRING);
    $this->RegisterPropertyString("deviceModel", classConstant::NO_STRING);
    $this->RegisterPropertyString("deviceLabel", classConstant::NO_STRING);

    $this->RegisterPropertyString("uintUUID", classConstant::NO_STRING);
    $this->RegisterPropertyInteger("itemType", classConstant::NO_VALUE);
    $this->RegisterPropertyFloat("transition", classConstant::TRANSITION_DEFAULT);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);
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

    if ($itemClass == classConstant::NO_VALUE) {
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
    if ($this->parentID == null) {
      $this->parentID = $this->classModule->getParentInfo($this->InstanceID);
    }

    if ($this->parentID) {
      $connectMode = IPS_GetProperty($this->parentID, "connectMode");
      $deviceInfo  = IPS_GetProperty($this->parentID, "deviceInfo");

      $localDevice = $this->GetBuffer("localDevice");
      $itemType    = $this->ReadPropertyInteger("itemType");

      $itemClass = $this->ReadPropertyInteger("itemClass");
      $formLabel = ($itemClass == classConstant::NO_VALUE) ? '{ "label": "Select...",  "value":   -1 },' : classConstant::NO_STRING;

      $infoText = ($itemClass != classConstant::NO_VALUE && $deviceInfo && empty($localDevice) === false) ? '
        { "type": "Label", "label": "----------------------------------------- Geräte spezifische Informationen ------------------------------------------------" },
        { "type": "ValidationTextBox", "name": "UUID",         "caption": "UUID" }' : classConstant::NO_STRING;

      switch ($itemType) {
        case classConstant::TYPE_SENSOR_MOTION:
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

          $infoText = ($connectMode == classConstant::CONNECT_LOCAL_CLOUD && empty($cloudDevice) === false && empty($infoText) === false) ? $infoText.'
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

    return vtNoForm;
  }


  public function ReceiveData($jsonString) {
    $deviceID = $this->ReadPropertyInteger("deviceID");
    $data     = json_decode($jsonString);

    $this->debug   = $data->debug;
    $this->message = $data->message;

    switch ($data->mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $localBuffer = utf8_decode($data->buffer);
        $localCount  = ord($localBuffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("localDevice", $localBuffer{0}.$localDevice);

        if (empty($localDevice) === false) {
          if ($data->debug % 2 || $data->message) {
            $info = $localCount."/".$this->lightifyBase->decodeData($localDevice);

            if ($data->debug % 2) {
              $this->SendDebug("<DEVICE|RECEIVEDATA|DEVICES:LOCAL>", $info, 0);
            }

            if ($data->message) {
              IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|DEVICES:LOCAL>   ".$info);
            }
          }

          $this->setDeviceInfo($data->method, $data->mode, $localDevice);
        }
        break;

      case classConstant::MODE_DEVICE_CLOUD:
        $cloudDevice = $this->getDeviceCloud($deviceID, $data->buffer);
        $this->SetBuffer("cloudDevice", $cloudDevice);

        if (empty($cloudDevice) === false) {
          if ($data->debug % 2 || $data->message) {
            $info = $this->lightifyBase->decodeData($cloudDevice);

            if ($data->debug % 2) {
              $this->SendDebug("<DEVICE|RECEIVEDATA|DEVICES:CLOUD>", $info, 0);
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
    if ($this->parentID == null) {
      $this->parentID = $this->classModule->getParentInfo($this->InstanceID);
    }

    if ($this->parentID) {
      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_DEVICE_LOCAL))
      );

      if ($jsonString != classConstant::NO_STRING) {
        $localData   = json_decode($jsonString);
        $localBuffer = utf8_decode($localData->buffer);
        $localCount  = ord($localBuffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("localDevice", $localBuffer{0}.$localDevice);

        if (empty($localDevice) === false) {
          $uintUUID  = substr($localDevice, 2, classConstant::UUID_DEVICE_LENGTH);
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
              'DataID' => classConstant::TX_GATEWAY,
              'method' => classConstant::METHOD_APPLY_CHILD,
              'mode'   => classConstant::MODE_DEVICE_CLOUD))
            );

            if ($jsonString != classConstant::NO_STRING) {
              $cloudData = json_decode($jsonString);

              //Store group device buffer
              $cloudDevice = $this->getDeviceCloud($deviceID, $cloudData->buffer);
              $this->SetBuffer("cloudDevice", $cloudDevice);

              if (empty($cloudDevice) === false) {
                $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_CLOUD, $cloudDevice, true);
              }
            }
          }

          $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_LOCAL, $localDevice);
          return 102;
        }

        return 104;
      }

      return 102;
    }

    return 201;
  }


  private function getDeviceLocal($deviceID, $buffer, $ncount) {
    $localDevice = classConstant::NO_STRING;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{0});
      $buffer  = substr($buffer, 1);

      if ($deviceID == $localID) {
        $localDevice = substr($buffer, 0, classConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $buffer = substr($buffer, classConstant::DATA_DEVICE_LENGTH);
    }

    return $localDevice;
  }


  private function getDeviceCloud($deviceID, $buffer) {
    $cloudDevice = classConstant::NO_STRING;
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
      case classConstant::MODE_DEVICE_LOCAL:
        $itemLight = $itemPlug = $itemMotion = false;

        //Decode Device label
        switch ($itemType) {
          case classConstant::TYPE_PLUG_ONOFF:
            $itemPlug = true;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $itemMotion = true;
            break;

          default:
            $itemLight = true;
        }

        $deviceRGB  = ($itemType & 8) ? true: false;
        $deviceCCT  = ($itemType & 2) ? true: false;
        $deviceCLR  = ($itemType & 4) ? true: false;

        $hue    = $color = $level      = classConstant::NO_STRING;
        $temperature     = $saturation = classConstant::NO_STRING;

        if ($itemLight || $itemPlug || $itemMotion) {
          $online    = (ord($data{15}) == classConstant::STATE_ONLINE) ? true : false; //Online: 2 - Offline: 0 - Unknown: 1
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
        $firmware = classConstant::NO_STRING;

        if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
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
            if ($method == classConstant::METHOD_CREATE_CHILD) {
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
          if ($method == classConstant::METHOD_CREATE_CHILD) {
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
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);
              }
            }

            if ($hueID !== false) {
              if (GetValueInteger($hueID) != $hue) {
                SetValueInteger($hueID, $hue);
              }
            }

            if (false == ($colorID = @$this->GetIDForIdent("COLOR"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
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
              if ($method == classConstant::METHOD_CREATE_CHILD) {
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

              if ($method == classConstant::METHOD_CREATE_CHILD) {
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
              if ($method == classConstant::METHOD_CREATE_CHILD) {
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

      case classConstant::MODE_DEVICE_CLOUD:
        list($cloudID, $deviceType, $manufacturer, $deviceModel, $bmpClusters, $zigBee, $firmware) = json_decode($data);

        if ($method == classConstant::METHOD_CREATE_CHILD) {
          if ($itemType != classConstant::TYPE_SENSOR_MOTION && $itemType != classConstant::TYPE_DIMMER_2WAY && $itemType != classConstant::TYPE_SWITCH_4WAY) {
            $deviceLabel = (empty($bmpClusters)) ? classConstant::NO_STRING : implode(" ", $bmpClusters);
    
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
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            $zigBeeID = $this->RegisterVariableString("ZIGBEE", "ZigBee", classConstant::NO_STRING, 321);
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
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            $firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", classConstant::NO_STRING, 322);
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
