<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyDevice extends IPSModule
{


  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->SetBuffer("applyMode", 1);

    $this->SetBuffer("localDevice", vtNoString);
    $this->SetBuffer("cloudDevice", vtNoString);
    $this->SetBuffer("deviceLabel", vtNoString);

    $this->RegisterPropertyInteger("itemClass", vtNoValue);
    $this->RegisterPropertyInteger("classType", vtNoValue);
    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyString("UUID", vtNoString);

    $this->RegisterPropertyString("manufacturer", vtNoString);
    $this->RegisterPropertyString("deviceModel", vtNoString);
    $this->RegisterPropertyString("deviceLabel", vtNoString);

    $this->RegisterPropertyInteger("transition", classConstant::TIME_MIN);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->SetBuffer("applyMode", 1);
        $this->ApplyChanges();
        break;
    }

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) return;
    $applyMode = $this->GetBuffer("applyMode");

    if ($applyMode) {
      $uintUUID = $this->ReadPropertyString("uintUUID");
      if ($uintUUID == vtNoString) return $this->SetStatus(202);

      //Apply filter
      $class = $this->ReadPropertyInteger("itemClass");
      //$filter = ".*-d".preg_quote(trim(json_encode(utf8_encode(chr($deviceID))), '"')).".*";
      $filter = ".*-d".preg_quote(trim(json_encode(utf8_encode($uintUUID)), '"')).".*";

      $this->SetReceiveDataFilter($filter);
      $status = $this->setDeviceProperty($uintUUID);

      if ($status == 102) {
        //Apply changes
        if (IPS_HasChanges($this->InstanceID)) {
          $this->SetBuffer("applyMode", 0);
          IPS_ApplyChanges($this->InstanceID);
        }
      }
      $this->SetStatus($status);
    }

    if (!$applyMode) {
      $this->SetBuffer("applyMode", 1);
    }

  }


  public function GetConfigurationForm() {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $class  = $this->ReadPropertyInteger("itemClass");

      if ($class != vtNoValue) {
        $connect = IPS_GetProperty($parentID, "connectMode");

        $localDevice = $this->GetBuffer("localDevice");
        $info = IPS_GetProperty($parentID, "deviceInfo");

        $status = [];
        $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Device is active"];
        $status [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Device is inactive"];
        $status [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
        $status [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

        $actions = [];
        $actions [] = ['type' => "Button", 'caption' => "On",  'onClick' => "OSR_SetState(\$id, true);"];
        $actions [] = ['type' => "Button", 'caption' => "Off", 'onClick' => "OSR_SetState(\$id, false);"];

        switch ($class) {
          case classConstant::CLASS_LIGHTIFY_SENSOR:
            $options = [];
            $options [] = ['caption' => "Sensor", 'value' => 2003];

            $elements = [];
            $elements [] = ['type' => "Select", 'name' => "itemClass", 'caption' => "Class", 'options' => $options];

            if (!empty($localDevice) && $info) {
              $elements [] = ['type' => "ValidationTextBox", 'name' => "UUID", 'caption' => "UUID"];
            }

            $formJSON = json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
            break;

          default:
            $cloudDevice = $this->GetBuffer("cloudDevice");

            $options = [];
            $options [] = ($class == classConstant::CLASS_LIGHTIFY_PLUG) ? ['caption' => "Plug", 'value' => 2002] : ['caption' => "Light", 'value' => 2001];

            $elements = [];
            $elements [] = ['type' => "Select", 'name' => "itemClass", 'caption' => "Class", 'options' => $options];

            if (!empty($localDevice) && $info) {
              $elements [] = ['type' => "ValidationTextBox", 'name' => "UUID", 'caption' => "UUID"];
            }

            if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($cloudDevice) && $info) {
              if ($this->ReadPropertyString("manufacturer") != vtNoString) {
                $elements [] = ['type' => "ValidationTextBox", 'name'  => "manufacturer", 'caption' => "Manufacturer"];
              }

              if ($this->ReadPropertyString("deviceModel") != vtNoString) {
                $elements [] = ['type' => "ValidationTextBox", 'name'  => "deviceModel", 'caption' => "Model"];
              }

              if ($this->ReadPropertyString("deviceLabel") != vtNoString) {
                $elements [] = ['type' => "ValidationTextBox", 'name'  => "deviceLabel", 'caption' => "Capabilities"];
              }
            }

            $formJSON = json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
        }
      } else {
        $status = [];
        $status [] = ['code' => 202, 'icon' => "error", 'caption' => "Device can only be configured over the Lightify Gateway Instance"];

        $formJSON = json_encode(['elements' => [], 'status' => $status]);
      }

      return $formJSON;
    }

    return vtNoForm;

  }


  public function ReceiveData($jsonString) {

    $uintUUID = $this->ReadPropertyString("uintUUID");
    $data     = json_decode($jsonString);

    $debug   = IPS_GetProperty($data->id, "debug");
    $message = IPS_GetProperty($data->id, "message");

    switch ($data->mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($uintUUID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$localDevice);

        if (!empty($localDevice)) {
          if ($debug % 2 || $message) {
            $info = ord($localDevice{0}).":".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($localDevice);

            if ($debug % 2) {
              $this->SendDebug("<Device|ReceiveData|Devices:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Device|ReceiveData|Devices:local>   ".$info);
            }
          }

          $this->setDeviceInfo($data->method, $data->mode, $localDevice);
        }
        break;

      case classConstant::MODE_STATE_DEVICE:
        $onlineID = @$this->GetIDForIdent("ONLINE");

        if ($onlineID && GetValueBoolean($onlineID)) {
          $stateID = @$this->GetIDForIdent("STATE");

          if ($stateID) {
            $newState = (bool)$data->method;

            if (GetValueBoolean($stateID) != $newState) {
              SetValue($stateID, $newState);
            }
          }
        }
        break;

      case classConstant::MODE_DEVICE_CLOUD:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        $cloudDevice = $this->getDeviceCloud($uintUUID, $ncount, substr($buffer, 1));
        $this->SetBuffer("cloudDevice", $cloudDevice);

        if (!empty($cloudDevice)) {
          if ($debug % 2 || $message) {
            $info = "0:".IPS_GetName($this->InstanceID)."/".$cloudDevice;

            if ($debug % 2) {
              $this->SendDebug("<Device|ReceiveData|Devices:cloud>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Device|ReceiveData|Devices:cloud>   ".$info);
            }
          }

          $this->setDeviceInfo($data->method, $data->mode, $cloudDevice, true);
        }
        break;
    }

  }


  private function setDeviceProperty(string $uintUUID) : int {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $connect = IPS_GetProperty($parentID, "connectMode");

      $jsonString = $this->SendDataToParent(json_encode([
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_DEVICE_LOCAL])
      );

      if ($jsonString != vtNoString) {
        $data   = json_decode($jsonString);
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device buffer
        $localDevice = $this->getDeviceLocal($uintUUID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$localDevice);

        if (!empty($localDevice)) {
          if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
            $jsonString = $this->SendDataToParent(json_encode([
              'DataID' => classConstant::TX_GATEWAY,
              'method' => classConstant::METHOD_APPLY_CHILD,
              'mode'   => classConstant::MODE_DEVICE_CLOUD])
            );

            if ($jsonString != vtNoString) {
              $data   = json_decode($jsonString);
              $buffer = utf8_decode($data->buffer);
              $ncount = ord($buffer{0});

              //Store group device buffer
              $cloudDevice = $this->getDeviceCloud($uintUUID, $ncount, substr($buffer, 1));
              $this->SetBuffer("cloudDevice", $cloudDevice);

              if (!empty($cloudDevice)) {
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


  private function getDeviceLocal(string $uintUUID, int $ncount, string $data) : string {

    $device = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $data = substr($data, 2);

      if ($uintUUID == substr($data, 0, classConstant::UUID_DEVICE_LENGTH)) {
        $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
    }

    return $device;

  }


  private function getDeviceCloud(string $uintUUID, int $ncount, string $data) : string {

    $device  = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $length = ord($data{0});
      $type   = $data{1};
      $data   = substr($data, 1);

      //IPS_LogMessage("SymconOSR", "<Device|getDeviceCloud|device>   ".$i." ".$length." ".IPS_GetName($this->InstanceID)." ".ord($type)." ".json_encode(utf8_encode($uintUUID)). " ".json_encode(utf8_encode(substr($data, 3, classConstant::UUID_DEVICE_LENGTH))));

      if ($uintUUID == substr($data, 3, classConstant::UUID_DEVICE_LENGTH)) {
        $device = substr($data, 0, $length);
        break;
      }

      $data = substr($data, $length);
    }

    //IPS_LogMessage("SymconOSR", "<Device|getDeviceCloud|device>   ".IPS_GetName($this->InstanceID)." ".$device);
    return $device;

  }


  private function setDeviceInfo(int $method, int $mode, string $data, bool $apply = false) : void {

    $type = ord($data{10});

    switch ($mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $classLight = $classPlug = $classMotion = false;

        //Decode device class
        switch ($type) {
          case classConstant::TYPE_PLUG_ONOFF:
            $classPlug = true;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $classMotion = true;
            break;

          default:
            $classLight = true;
        }

        $deviceRGB  = ($type & 8) ? true: false;
        $deviceCCT  = ($type & 2) ? true: false;
        $deviceCLR  = ($type & 4) ? true: false;

        $hue    = $color = $level      = vtNoString;
        $temperature     = $saturation = vtNoString;

        $newOnline = (ord($data{15}) == classConstant::STATE_ONLINE) ? true : false; //Online: 2 - Offline: 0 - Unknown: 1
        $online    = $state = false;

        if ($classLight || $classPlug) {
          $newState  = ($newOnline) ? (bool)ord($data{18}) : false;
        } else {
          $newState = (bool)ord($data{22}); //State = red
        }

        if ($classLight) {
          $level = ord($data{19});
        }

        $white = ord($data{25});
        $hex   = $this->lightifyBase->RGB2HEX(['r' => ord($data{22}), 'g' => ord($data{23}), 'b' => ord($data{24})]);
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
        $zigBee   = dechex(ord($data{8})).dechex(ord($data{9}));
        $firmware = vtNoString;

        $onlineID = @$this->GetIDForIdent("ONLINE");
        $stateID  = @$this->GetIDForIdent("STATE");

        if (!$onlineID) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            //$this->MaintainVariable("ONLINE", $this->Translate("Online"), vtBoolean, "OSR.Switch", 312, true);
            //$onlineID = $this->GetIDForIdent("ONLINE");
            $onlineID = $this->RegisterVariableBoolean("ONLINE", $this->Translate("Online"), "OSR.Switch", 312);

            IPS_SetDisabled($onlineID, true);
            IPS_SetHidden($onlineID, true);
          }
        }

        if ($onlineID) {
           $online = GetValueBoolean($onlineID);

          if ($online != $newOnline) {
            SetValueBoolean($onlineID, $newOnline);
          }
        }

        if (!$stateID) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            //$this->MaintainVariable("STATE", $this->Translate("State"), vtBoolean, "OSR.Switch", 313, true);
            //$stateID = $this->GetIDForIdent("STATE");
            $stateID = $this->RegisterVariableBoolean("STATE", $this->Translate("State"), "OSR.Switch", 313);

            if ($classLight || $classPlug) {
              $this->EnableAction("STATE");
            }
          }
        }

        if ($stateID) {
           $state = GetValueBoolean($stateID);

          if ($state != $newState) {
            SetValueBoolean($stateID, $newState);
          }
        }

        if ($classLight || $classPlug) {
          if ($deviceRGB) {
            $hueID        = @$this->GetIDForIdent("HUE");
            $colorID      = @$this->GetIDForIdent("COLOR");
            $saturationID = @$this->GetIDForIdent("SATURATION");

            if (!$hueID) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$this->MaintainVariable("HUE", $this->Translate("Hue"), vtInteger, "OSR.Hue", 314, true);
                //$hueID = $this->GetIDForIdent("HUE");
                $hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);

                IPS_SetDisabled($hueID, true);
                IPS_SetHidden($hueID, true);
              }
            }

            if ($hueID) {
              if (GetValueInteger($hueID) != $hue) {
                SetValueInteger($hueID, $hue);
              }
            }

            if (!$colorID) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                $this->MaintainVariable("COLOR", $this->Translate("Color"), vtInteger, "~HexColor", 315, true);
                $colorID = $this->GetIDForIdent("COLOR");

                //$colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
                //$colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
                IPS_SetIcon($colorID, "Paintbrush");

                $this->EnableAction("COLOR");
              }
            }

            if ($colorID) {
              if (GetValueInteger($colorID) != $color) {
                SetValueInteger($colorID, $color);
              }
            }

            if (!$saturationID) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$this->MaintainVariable("SATURATION", $this->Translate("Saturation"), vtInteger, "OSR.Intensity", 318, true);
                //$saturationID = $this->GetIDForIdent("SATURATION");
                $saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
                IPS_SetIcon($saturationID, "Intensity");

                $this->EnableAction("SATURATION");
              }
            }

            if ($saturationID) {
              if (GetValueInteger($saturationID) != $saturation) {
                SetValueInteger($saturationID, $saturation);
              }
            }
          }

          if ($deviceCCT) {
            $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");

            if (!$temperatureID) {
              $profile = ($deviceRGB) ? "OSR.ColorTempExt" : "OSR.ColorTemp";

              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$this->MaintainVariable("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), vtInteger, "OSR.ColorTemp", 316, true);
                //$temperatureID = $this->GetIDForIdent("COLOR_TEMPERATURE");
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTemp", 316);
                $this->EnableAction("COLOR_TEMPERATURE");
              }
            }

            if ($temperatureID) {
              if (GetValueInteger($temperatureID) != $temperature) {
                SetValueInteger($temperatureID, $temperature);
              }
            }
          }

          if ($classLight) {
            $levelID = @$this->GetIDForIdent("LEVEL");

            if ($levelID === false) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$this->MaintainVariable("LEVEL", $this->Translate("Level"), vtInteger, "OSR.Intensity", 317, true);
                //$levelID = $this->GetIDForIdent("LEVEL");
                $levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);
                IPS_SetIcon($levelID, "Sun");

                $this->EnableAction("LEVEL");
              }
            }

            if ($levelID) {
              if (GetValueInteger($levelID) != $level) {
                SetValueInteger($levelID, $level);
              }
            }
          }
        }

        if ($classMotion) {
          $motionID = @$this->GetIDForIdent("MOTION");

          $battery = dechex(ord($data{19})); //Brightness = battery value
          $motion  = (bool)ord($data{23});   //Light = green, Sensor = motion detection

          if (!$motionID) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              //$this->MaintainVariable("MOTION", $this->Translate("Motion"), vtBooelan, "~Motion", 322, true);
              //$$motionID = $this->GetIDForIdent("MOTION");
              $motionID = $this->RegisterVariableBoolean("MOTION", $this->Translate("Motion"), "~Motion", 322);
              $this->EnableAction("MOTION");
            }
          }

          if ($motionID) {
            if (GetValueBoolean($motionID) != $motion) {
              SetValueBoolean($motionID, $motion);
            }
          }
        }
        break;

      case classConstant::MODE_DEVICE_CLOUD:
        $type = ord($data{0});
        $data = substr($data, 3+classConstant::UUID_DEVICE_LENGTH+classConstant::CLOUD_ZIGBEE_LENGTH);

        $length  = ord($data{0});
        $product = substr($data, 1, $length);
        $data    = substr($data, $length+1);

        $manufacturer = substr($data, 0, classConstant::CLOUD_OSRAM_LENGTH);
        $data = substr($data, classConstant::CLOUD_OSRAM_LENGTH);

        $length = ord($data{0});
        $model  = substr($data, 1, $length);
        $data   = substr($data, $length+1);
        $firmware = substr($data, 0, classConstant::CLOUD_FIRMWARE_LENGTH);

        switch ($type) {
          case classConstant::TYPE_FIXED_WHITE:
            $label = classConstant::LABEL_FIXED_WHITE;

          case classConstant::TYPE_LIGHT_CCT:
            if (!isset($label)) $label = classConstant::LABEL_LIGHT_CCT;

          case classConstant::TYPE_LIGHT_DIMABLE:
            if (!isset($label)) $label = classConstant::LABEL_LIGHT_DIMABLE;

          case classConstant::TYPE_LIGHT_COLOR:
            if (!isset($label)) $label = classConstant::LABEL_LIGHT_COLOR;

          case classConstant::TYPE_LIGHT_EXT_COLOR:
            if (!isset($label)) $label = classConstant::LABEL_LIGHT_EXT_COLOR;

          case classConstant::TYPE_PLUG_ONOFF:
            if (!isset($label)) $label = classConstant::LABEL_PLUG_ONOFF;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            if (!isset($label)) $label = classConstant::LABEL_SENSOR_MOTION;

          case classConstant::TYPE_DIMMER_2WAY:
            if (!isset($label)) $label = classConstant::LABEL_DIMMER_2WAY;

          case classConstant::TYPE_SWITCH_4WAY:
            if (!isset($label)) $label = classConstant::LABEL_SWITCH_4WAY;

          case classConstant::TYPE_SWITCH_MINI:
            if (!isset($label)) $label = classConstant::LABEL_SWITCH_MINI;
            break;
        }

        if ($method == classConstant::METHOD_CREATE_CHILD) {
          if ($type != classConstant::TYPE_SENSOR_MOTION && $type != classConstant::TYPE_DIMMER_2WAY && $type != classConstant::TYPE_SWITCH_4WAY) {
            if ($this->ReadPropertyString("manufacturer") != $manufacturer) {
              IPS_SetProperty($this->InstanceID, "manufacturer", $manufacturer);
            }

            if ($this->ReadPropertyString("deviceModel") != $model) {
              IPS_SetProperty($this->InstanceID, "deviceModel", $model);
            }

            if ($this->ReadPropertyString("deviceLabel") != $label) {
              IPS_SetProperty($this->InstanceID, "deviceLabel", $label);
            }
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
            $this->SetBuffer("applyMode", 0);
            IPS_ApplyChanges($this->InstanceID);
          }
        }
        break;
    }

  }


}
