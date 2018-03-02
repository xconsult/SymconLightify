<?php

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyDevice extends lightifyControl {

  const ITEMID_CREATE  = 0;
  const ITEMID_MINIMUM = 1;

  private $parentID = null;
  private $debug;
  private $message;


  public function Create() {
    parent::Create();

    $this->SetBuffer("localDevice", vtNoString);
    $this->SetBuffer("cloudDevice", vtNoString);
    $this->SetBuffer("saveID", serialize(vtNoValue));

    $this->RegisterPropertyInteger("deviceID", self::ITEMID_CREATE);
    $this->RegisterPropertyInteger("itemClass", vtNoValue);

    $this->RegisterPropertyString("UUID", vtNoString);
    $this->RegisterPropertyString("manufacturer", vtNoString);
    $this->RegisterPropertyString("deviceModel", vtNoString);
    $this->RegisterPropertyString("deviceLabel", vtNoString);

    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyInteger("itemType", vtNoValue);
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

    if ($itemClass == vtNoValue) {
      $this->SetStatus(203);
      return false;
    }

    if (102 == ($status = $this->setDeviceProperty($deviceID))) {
      //Apply changes
      if (IPS_HasChanges($this->InstanceID)) {
        IPS_ApplyChanges($this->InstanceID);
      }
    }

    //$this->SetReceiveDataFilter(".*i".str_pad($deviceID, 3, "0", STR_PAD_LEFT).".*");
    $this->SetStatus($status);
  }


  public function GetConfigurationForm() {
    if ($this->parentID == null) {
      $this->parentID = $this->classModule->getParentInfo($this->InstanceID);
    }

    if ($this->parentID) {
      $connectMode  = IPS_GetProperty($this->parentID, "connectMode");
      $deviceInfo   = IPS_GetProperty($this->parentID, "deviceInfo");

      $localDevice = $this->GetBuffer("localDevice");
      $itemType    = $this->ReadPropertyInteger("itemType");

      $itemClass = $this->ReadPropertyInteger("itemClass");
      $formLabel = ($itemClass == vtNoValue) ? '{ "label": "Select...",  "value":   -1 },' : vtNoString;

      $infoText = ($itemClass != vtNoValue && $deviceInfo && !empty($localDevice)) ? '
        { "type": "Label", "label": "----------------------------------------- Geräte spezifische Informationen ------------------------------------------------" },
        { "type": "ValidationTextBox", "name": "UUID",         "caption": "UUID" }' : vtNoString;

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

          if (!empty($infoText)) {
            $infoText .= ",";
	        }

          $infoText = ($connectMode == classConstant::CONNECT_LOCAL_CLOUD && !empty($cloudDevice) && !empty($infoText)) ? $infoText.'
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

        if ($localCount > 0 && !empty($localDevice)) {
          $info = $localCount."/".$this->lightifyBase->decodeData($localDevice);

          if ($data->debug % 2 || $data->message) {
            $info = $localCount."/".$this->lightifyBase->decodeData($localDevice);

            if ($data->debug % 2) {
              $this->SendDebug("<DEVICE|RECEIVEDATA|DEVICES:LOCAL>", $info, 0);
            }

            if ($data->message) {
              IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|DEVICES:LOCAL>   ".$info);
            }
          }

          $showControl = IPS_GetProperty($data->id, "showControl");
          $this->setDeviceInfo($data->method, $data->mode, $localDevice, false, $showControl);
        }
        break;

      case classConstant::MODE_DEVICE_CLOUD:
        $cloudDevice = $this->getDeviceCloud($deviceID, $data->buffer);
        $this->SetBuffer("cloudDevice", $cloudDevice);

        if (!empty($cloudDevice)) {
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

      if ($jsonString != vtNoString) {
        $localData   = json_decode($jsonString);
        $localBuffer = utf8_decode($localData->buffer);
        $localCount  = ord($localBuffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($deviceID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("localDevice", $localBuffer{0}.$localDevice);

        if (!empty($localDevice)) {
          $itemType  = ord($localDevice{10});
          $uintUUID  = substr($localDevice, 2, classConstant::UUID_DEVICE_LENGTH);
          $UUID      = $this->lightifyBase->ChrToUUID($uintUUID);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);
          }

          if ($this->ReadPropertyInteger("itemType") != $itemType) {
            IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);
          }

          if ($this->ReadPropertyString("UUID") != $UUID) {
            IPS_SetProperty($this->InstanceID, "UUID", (string)$UUID);
          }

          if (IPS_GetProperty($this->parentID, "connectMode") == classConstant::CONNECT_LOCAL_CLOUD) {
            $jsonString = $this->SendDataToParent(json_encode(array(
              'DataID' => classConstant::TX_GATEWAY,
              'method' => classConstant::METHOD_APPLY_CHILD,
              'mode'   => classConstant::MODE_DEVICE_CLOUD))
            );

            if ($jsonString != vtNoString) {
              $cloudData = json_decode($jsonString);

              //Store group device buffer
              $cloudDevice = $this->getDeviceCloud($deviceID, $cloudData->buffer);
              $this->SetBuffer("cloudDevice", $cloudDevice);

              if (!empty($cloudDevice)) {
                $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_CLOUD, $cloudDevice, true);
              }
            }
          }

          $showControl = IPS_GetProperty($this->parentID, "showControl");
          $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_LOCAL, $localDevice, false, $showControl);

          return 102;
        }

        return 104;
      }

      return 102;
    }

    return 201;
  }


  private function getDeviceLocal($deviceID, $buffer, $ncount) {
    $localDevice = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{0});
      $buffer  = substr($buffer, 1);

      //$localID = substr($buffer, 1, 3);
      //$buffer  = substr($buffer, 4);

      if ($localID == $deviceID) {
        $localDevice = substr($buffer, 0, classConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $buffer = substr($buffer, classConstant::DATA_DEVICE_LENGTH);
    }

    return $localDevice;
  }


  private function getDeviceCloud($deviceID, $buffer) {
    $cloudDevice = vtNoString;
    $cloudBuffer = json_decode($buffer);

    foreach ($cloudBuffer as $device) {
      list($cloudID) = $device;

      if ($cloudID == $deviceID) {
        $cloudDevice = json_encode($device);
        break;
      }
    }

    return $cloudDevice;
  }


  private function setDeviceInfo($method, $mode, $data, $apply, $showControl = false) {
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

        $hue    = $color = $level      = vtNoString;
        $temperature     = $saturation = vtNoString;

        if ($itemLight || $itemPlug) {
          $newOnline = (ord($data{15}) == classConstant::STATE_ONLINE) ? true : false; //Online: 2 - Offline: 0 - Unknown: 1
          $newState  = ($newOnline) ? ord($data{18}) : false;
          $online    = $state = false;
        }

        if ($itemLight) {
          $level = ord($data{19});
        }

        if ($itemMotion) {
          $newState = (bool)ord($data{22}); //State = red
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

        //Additional informations
        $zigBee   = dechex(ord($data{0})).dechex(ord($data{1}));
        $firmware = vtNoString;

/*
        if ($itemLight || $itemPlug) {
          if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $onlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "OSR.Switch", 312);
              IPS_SetIcon($onlineID, "Electricity");
            }
          }

          if ($onlineID !== false) {
            $online = GetValueBoolean($onlineID);

            if ($online != $newOnline) {
              SetValueBoolean($onlineID, $newOnline);
            }
          }
        }
*/

        if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            $stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 313);
          }
        }

        if ($stateID !== false) {
           $state = GetValueBoolean($stateID);

          if ($state != $newState) {
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

              if ($showControl) {
                IPS_SetHidden($hueID, !$newState);
              } else {
                IPS_SetHidden($hueID, false);
              }

              $this->MaintainAction("HUE", $newState);
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

              if ($showControl) {
                IPS_SetHidden($colorID, !$newState);
              } else {
                IPS_SetHidden($colorID, false);
              }

              $this->MaintainAction("COLOR", $newState);
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
              $this->MaintainAction("SATURATION", $newState);

              if ($showControl) {
                IPS_SetHidden($saturationID, !$newState);
              } else {
                IPS_SetHidden($saturationID, false);
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
              $this->MaintainAction("COLOR_TEMPERATURE", $newState);

              if ($showControl) {
                IPS_SetHidden($temperatureID, !$newState);
              } else {
                IPS_SetHidden($temperatureID, false);
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
              $this->MaintainAction("LEVEL", $newState);

              if ($showControl) {
                IPS_SetHidden($levelID, !$newState);
              } else {
                IPS_SetHidden($levelID, false);
              }
            }
          }
        }

        if ($itemMotion) {
          $battery  = dechex(ord($data{19}));       //Level = battery value
          $motion   = (bool)ord($data{23}); //Light = green, Sensor = motion detection

/*
          if (false === ($batteryID = @$this->GetIDForIdent("BATTERY"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $batteryID = $this->RegisterVariableInteger("BATTERY", "Battery", "~Battery.100", 321);
            }
          }

          if ($batteryID !== false) {
            if (GetValueInteger($batteryID) != $battery) {
              SetValueInteger($batteryID, $battery);
            }
          }
*/

          if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $motionID = $this->RegisterVariableBoolean("MOTION", "Motion", "~Motion", 322);
            }
          }

          if ($motionID !== false) {
            if (GetValueBoolean($motionID) != $motion) {
              SetValueBoolean($motionID, $motion);
            }
          }
        }
        break;

      case classConstant::MODE_DEVICE_CLOUD:
        list($cloudID, $deviceType, $manufacturer, $deviceModel, $bmpClusters, $zigBee, $firmware) = json_decode($data);

        if ($method == classConstant::METHOD_CREATE_CHILD) {
          if ($itemType != classConstant::TYPE_SENSOR_MOTION && $itemType != classConstant::TYPE_DIMMER_2WAY && $itemType != classConstant::TYPE_SWITCH_4WAY) {
            $deviceLabel = (empty($bmpClusters)) ? vtNoString : implode(" ", $bmpClusters);
    
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
            $zigBeeID = $this->RegisterVariableString("ZIGBEE", "ZigBee", vtNoString, 321);
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
            $firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", vtNoString, 322);
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
