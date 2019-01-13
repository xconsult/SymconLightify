<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyDevice extends IPSModule
{


  use LightifyControl;


  public function Create()
  {

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


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
  {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->SetBuffer("applyMode", 1);
        $this->ApplyChanges();
        break;
    }

  }


  public function ApplyChanges()
  {

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


  public function GetConfigurationForm()
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $class  = $this->ReadPropertyInteger("itemClass");

      if ($class != vtNoValue) {
        $connect = IPS_GetProperty($parentID, "connectMode");

        $device = $this->GetBuffer("localDevice");
        $info   = IPS_GetProperty($parentID, "deviceInfo");
        $type   = $this->ReadPropertyInteger("classType");

        $status = [];
        $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Device is active"];
        $status [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Device is inactive"];
        $status [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
        $status [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

        $actions = [];
        $actions [] = ['type' => "Button", 'caption' => "On",  'onClick' => "OSR_WriteValue(\$id, \"STATE\", 1)"];
        $actions [] = ['type' => "Button", 'caption' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"STATE\", 0)"];

        switch ($class) {
          case classConstant::CLASS_LIGHTIFY_SENSOR:
            $options = [];
            $options [] = ['caption' => "Sensor", 'value' => 2003];

            $elements = [];
            $elements [] = ['type' => "Select", 'name' => "itemClass", 'caption' => "Class", 'options' => $options];

            if (!empty($device) && $info) {
              $elements [] = ['type' => "ValidationTextBox", 'name' => "UUID", 'caption' => "UUID"];
            }

            $formJSON = json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
            break;

          default:
            $cloud = $this->GetBuffer("cloudDevice");

            $options = [];
            $options [] = ($class == classConstant::CLASS_LIGHTIFY_PLUG) ? ['caption' => "Plug", 'value' => 2002] : ['caption' => "Light", 'value' => 2001];

            $elements = [];
            $elements [] = ['type' => "Select", 'name' => "itemClass", 'caption' => "Class", 'options' => $options];

            if (!empty($device) && $info) {
              $elements [] = ['type' => "ValidationTextBox", 'name' => "UUID", 'caption' => "UUID"];
            }

            if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($cloud) && $info) {
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


  public function ReceiveData($jsonString)
  {

    $uintUUID = $this->ReadPropertyString("uintUUID");
    $data     = json_decode($jsonString);

    $debug   = IPS_GetProperty($data->id, "debug");
    $message = IPS_GetProperty($data->id, "message");

    switch ($data->mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device buffer
        $device = $this->getDeviceLocal($uintUUID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$device);

        if (!empty($device)) {
          if ($debug % 2 || $message) {
            $info = ord($device{0}).":".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($device);

            if ($debug % 2) {
              $this->SendDebug("<Device|ReceiveData|devices:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Device|ReceiveData|devices:local>   ".$info);
            }
          }

          $this->setDeviceInfo($data->method, $data->mode, $device);
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
        $cloud = $this->getDeviceCloud($uintUUID, $data->buffer);
        $this->SetBuffer("cloudDevice", $cloud);

        if (!empty($cloud)) {
          if ($debug % 2 || $message) {
            $info = "0:".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($cloud);

            if ($debug % 2) {
              $this->SendDebug("<Device|ReceiveData|devices:cloud>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Device|ReceiveData|devices:cloud>   ".$info);
            }
          }

          $this->setDeviceInfo($data->method, $data->mode, $cloud, true);
        }
        break;
    }

  }


  private function setDeviceProperty($uintUUID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $connect = IPS_GetProperty($parentID, "connectMode");

      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_DEVICE_LOCAL))
      );

      if ($jsonString != vtNoString) {
        $data   = json_decode($jsonString);
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device buffer
        $device = $this->getDeviceLocal($uintUUID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$device);

        if (!empty($device)) {
          if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
            $jsonString = $this->SendDataToParent(json_encode(array(
              'DataID' => classConstant::TX_GATEWAY,
              'method' => classConstant::METHOD_APPLY_CHILD,
              'mode'   => classConstant::MODE_DEVICE_CLOUD))
            );

            if ($jsonString != vtNoString) {
              $data = json_decode($jsonString);

              //Store group device buffer
              $cloud = $this->getDeviceCloud($deviceID, $data->buffer);
              $this->SetBuffer("cloudDevice", $cloud);

              if (!empty($cloud)) {
                $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_CLOUD, $cloud, true);
              }
            }
          }

          $this->setDeviceInfo(classConstant::METHOD_CREATE_CHILD, classConstant::MODE_DEVICE_LOCAL, $device);
          return 102;
        }
        return 104;
      }
      return 102;
    }
    return 201;

  }


  private function getDeviceLocal($uintUUID, $ncount, $data)
  {

    $device = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $data = substr($data, 2);

      if ($uintUUID == substr($data, 2, classConstant::UUID_DEVICE_LENGTH)) {
        $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
    }

    return $device;

  }


  private function getDeviceCloud($uintUUID, $data)
  {

    $cloud  = vtNoString;
    $buffer = json_decode($data);

    foreach ($buffer as $device) {
      list($cloudID) = $device;

      if ($uintUUID == substr($cloudID, 2, classConstant::UUID_DEVICE_LENGTH)) {
        $cloud = json_encode($device);
        break;
      }
    }

    return $cloud;

  }


  private function setDeviceInfo($method, $mode, $data, $apply = false)
  {

    $classType = ord($data{10});

    switch ($mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $classLight = $classPlug = $classMotion = false;

        //Decode device class
        switch ($classType) {
          case classConstant::TYPE_PLUG_ONOFF:
            $classPlug = true;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $classMotion = true;
            break;

          default:
            $classLight = true;
        }

        $deviceRGB  = ($classType & 8) ? true: false;
        $deviceCCT  = ($classType & 2) ? true: false;
        $deviceCLR  = ($classType & 4) ? true: false;

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
        $zigBee   = dechex(ord($data{0})).dechex(ord($data{1}));
        $firmware = vtNoString;

        if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            //$onlineID = $this->RegisterVariableBoolean("ONLINE", $this->Translate("Online"), "OSR.Switch", 312);
            $onlineID = $this->RegisterVariableBoolean("ONLINE", "Online", "OSR.Switch", 312);

            IPS_SetDisabled($onlineID, true);
            IPS_SetHidden($onlineID, true);
          }
        }

        if ($onlineID !== false) {
           $online = GetValueBoolean($onlineID);

          if ($online != $newOnline) {
            SetValueBoolean($onlineID, $newOnline);
          }
        }

        if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            //$stateID = $this->RegisterVariableBoolean("STATE", $this->Translate("State"), "OSR.Switch", 313);
            $stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 313);

            if ($classLight || $classPlug) {
              $this->EnableAction("STATE");
            }
          }
        }

        if ($stateID !== false) {
           $state = GetValueBoolean($stateID);

          if ($state != $newState) {
            SetValueBoolean($stateID, $newState);
          }
        }

        if ($classLight || $classPlug) {
          if ($deviceRGB) {
            if (false == ($hueID = @$this->GetIDForIdent("HUE"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);
                $hueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);

                IPS_SetDisabled($hueID, true);
                IPS_SetHidden($hueID, true);
              }
            }

            if ($hueID !== false) {
              if (GetValueInteger($hueID) != $hue) {
                SetValueInteger($hueID, $hue);
              }
            }

            if (false == ($colorID = @$this->GetIDForIdent("COLOR"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
                $colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
                IPS_SetIcon($colorID, "Paintbrush");

                $this->EnableAction("COLOR");
              }
            }

            if ($colorID !== false) {
              if (GetValueInteger($colorID) != $color) {
                SetValueInteger($colorID, $color);
              }
            }

            if (false == ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
                $saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 318);
                IPS_SetIcon($saturationID, "Intensity");

                $this->EnableAction("SATURATION");
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
                //$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTemp", 316);
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTemp", 316);
                $this->EnableAction("COLOR_TEMPERATURE");
              }
            }

            if ($temperatureID !== false) {
              if (GetValueInteger($temperatureID) != $temperature) {
                SetValueInteger($temperatureID, $temperature);
              }
            }
          }

          if ($classLight) {
            if (false == ($levelID = @$this->GetIDForIdent("LEVEL"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);
                $levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 317);
                IPS_SetIcon($levelID, "Sun");

                $this->EnableAction("LEVEL");
              }
            }

            if ($levelID !== false) {
              if (GetValueInteger($levelID) != $level) {
                SetValueInteger($levelID, $level);
              }
            }
          }
        }

        if ($classMotion) {
          $battery = dechex(ord($data{19}));       //brightness = battery value
          $motion  = (bool)ord($data{23}); //Light = green, Sensor = motion detection

          if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              //$motionID = $this->RegisterVariableBoolean("MOTION", $this->Translate("Motion"), "~Motion", 322);
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
        list($cloudID, $zigBee, $type, $manufacturer, $model, $label, $firmware) = json_decode($data);

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
            //$firmwareID = $this->RegisterVariableString("FIRMWARE", $this->Translate("Firmware"), vtNoString, 322);
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
