<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyDevice extends IPSModule
{


  const ITEMID_CREATE  = 0;
  const ITEMID_MINIMUM = 1;

  use LightifyControl;


  public function Create()
  {

    parent::Create();

    //Store at runtime
    $this->SetBuffer("applyMode", 1);

    $this->SetBuffer("localDevice", vtNoString);
    $this->SetBuffer("cloudDevice", vtNoString);
    $this->SetBuffer("deviceLabel", vtNoString);
    $this->SetBuffer("groupBuffer", vtNoString);

    $this->RegisterPropertyInteger("deviceID", self::ITEMID_CREATE);
    $this->RegisterPropertyInteger("deviceClass", vtNoValue);

    $this->RegisterPropertyString("UUID", vtNoString);
    $this->RegisterPropertyString("manufacturer", vtNoString);
    $this->RegisterPropertyString("deviceModel", vtNoString);
    $this->RegisterPropertyString("deviceLabel", vtNoString);

    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyInteger("classType", vtNoValue);
    $this->RegisterPropertyFloat("transition", classConstant::TRANSITION_DEFAULT);

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
      $deviceID    = $this->ReadPropertyInteger("deviceID");
      $deviceClass = $this->ReadPropertyInteger("deviceClass");

      //Check config
      if ($deviceID < self::ITEMID_MINIMUM) {
        $this->SetStatus(202);
        return false;
      }

      if ($deviceClass == vtNoValue) {
        $this->SetStatus(203);
        return false;
      }

      //Apply filter
      //$filter = ".*-d".preg_quote("\u".str_pad((string)$deviceID, 4, "0", STR_PAD_LEFT)).".*";
      $filter = ".*-d".preg_quote(trim(json_encode(utf8_encode(chr($deviceID))), '"')).".*";
      $this->SetReceiveDataFilter($filter);

      if (102 == ($status = $this->setDeviceProperty($deviceID))) {
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
      $connect = IPS_GetProperty($parentID, "connectMode");
      $info    = IPS_GetProperty($parentID, "deviceInfo");

      $device = $this->GetBuffer("localDevice");
      $type   = $this->ReadPropertyInteger("classType");
      $class  = $this->ReadPropertyInteger("deviceClass");

      $formStatus    = [];
      $formStatus [] = ['code' => 102, 'icon' => "active",   'caption' => "Device is active"];
      $formStatus [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Device is inactive"];
      $formStatus [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
      $formStatus [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid Device [id]"];
      $formStatus [] = ['code' => 203, 'icon' => "error",    'caption' => "Invalid Class"];
      $formStatus [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

      switch ($class) {
        case classConstant::TYPE_SENSOR_MOTION:
          $formElements = [];
          $formOptions  = [];

          if ($class == vtNoValue) {
             $formOptions [] = ['label' => "Select...",  'value' => -1];
          }

          $formOptions [] = ['label' => "Light",  'value' => 2001];
          $formOptions [] = ['label' => "Plug",   'value' => 2002];
          $formOptions [] = ['label' => "Sensor", 'value' => 2003];

          $formElements [] = ['type' => "NumberSpinner", 'name' => "deviceID",    'caption' => "Device [id]"];
          $formElements [] = ['type' => "Select",        'name' => "deviceClass", 'caption' => "Class", 'options' => $formOptions];

          if ($class != vtNoValue && $info && !empty($device)) {
            $formElements [] = ['type' => "Label",             'label' => ""];
            $formElements [] = ['type' => "ValidationTextBox", 'name'  => "UUID", 'caption' => "UUID"];
          }

          $formJSON = json_encode(['elements' => $formElements, 'status' => $formStatus]);
          break;

        default:
          $cloud = $this->GetBuffer("cloudDevice");

          $formElements = [];
          $formOptions  = [];

          if ($class == vtNoValue) {
             $formOptions [] = ['label' => "Select...",  'value' => -1];
          }

          $formOptions [] = ['label' => "Light",  'value' => 2001];
          $formOptions [] = ['label' => "Plug",   'value' => 2002];
          $formOptions [] = ['label' => "Sensor", 'value' => 2003];

          $formElements [] = ['type' => "NumberSpinner", 'name' => "deviceID",    'caption' => "Device [id]"];
          $formElements [] = ['type' => "Select",        'name' => "deviceClass", 'caption' => "Class", 'options' => $formOptions];

          if ($class != vtNoValue && $info && !empty($device)) {
            $formElements [] = ['type' => "Label",             'label' => ""];
            $formElements [] = ['type' => "ValidationTextBox", 'name'  => "UUID", 'caption' => "UUID"];
          }

          if ($connect == classConstant::CONNECT_LOCAL_CLOUD && $info && !empty($cloud)) {
            if ($this->ReadPropertyString("manufacturer") != vtNoString) {
              $formElements [] = ['type' => "ValidationTextBox", 'name'  => "manufacturer", 'caption' => "Manufacturer"];
            }

            if ($this->ReadPropertyString("deviceModel") != vtNoString) {
              $formElements [] = ['type' => "ValidationTextBox", 'name'  => "deviceModel", 'caption' => "Model"];
            }

            if ($this->ReadPropertyString("deviceLabel") != vtNoString) {
              $formElements [] = ['type' => "ValidationTextBox", 'name'  => "deviceLabel", 'caption' => "Capabilities"];
            }
          }

          $formActions    = [];
          $formActions [] = ['type' => "Button", 'label' => "On",  'onClick' => "OSR_WriteValue(\$id, \"STATE\", 1)"];
          $formActions [] = ['type' => "Button", 'label' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"STATE\", 0)"];

          $formJSON = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
      }

      return $formJSON;
    }

    return vtNoForm;

  }


  public function ReceiveData($jsonString)
  {

    $deviceID = $this->ReadPropertyInteger("deviceID");
    $data     = json_decode($jsonString);

    $debug   = IPS_GetProperty($data->id, "debug");
    $message = IPS_GetProperty($data->id, "message");

    switch ($data->mode) {
      case classConstant::MODE_DEVICE_LOCAL:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device buffer
        $device = $this->getDeviceLocal($deviceID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$device);

        if (!empty($device) && $ncount > 0) {
          $info = $ncount."/".$this->lightifyBase->decodeData($device);

          if ($debug % 2 || $message) {
            $info = $ncount."/".$this->lightifyBase->decodeData($device);

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

      case classConstant::MODE_DEVICE_GROUP:
        //IPS_LogMessage("SymconOSR", "<Device|ReceiveData|devices:group>   ".json_encode($data->buffer));
        $buffer   = utf8_decode($data->buffer);
        $groupUID = vtNoString;

        $dcount = ord($buffer{0});
        $buffer = substr($buffer, 1);

        for ($i = 1; $i <= $dcount; $i++) {
          $localID = ord($buffer{2});
          $ncount  = ord($buffer{3});
          $buffer  = substr($buffer, 4);

          if ($localID == $deviceID) {
            $decode = $buffer;

            for ($j = 1; $j <= $ncount; $j++) {
              $groupUID .= substr($decode, 0, classConstant::ITEM_FILTER_LENGTH);;
              $decode    = substr($decode, classConstant::ITEM_FILTER_LENGTH);
            }
            break;
          }
          $buffer = substr($buffer, $ncount*classConstant::ITEM_FILTER_LENGTH);
        }

        if (!empty($groupUID)) {
          //IPS_LogMessage("SymconOSR", "<Device|ReceiveData|group:buffer>   ".IPS_GetName($this->InstanceID)." - ".json_encode($groupUID));
          $this->SetBuffer("groupUID", $groupUID);
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
        $cloud = $this->getDeviceCloud($deviceID, $data->buffer);
        $this->SetBuffer("cloudDevice", $cloud);

        if (!empty($cloud)) {
          if ($debug % 2 || $message) {
            $info = $this->lightifyBase->decodeData($cloud);

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


  private function setDeviceProperty($deviceID)
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
        $device = $this->getDeviceLocal($deviceID, $ncount, substr($buffer, 2));
        $this->SetBuffer("localDevice", $buffer{0}.$device);

        if (!empty($device)) {
          $classType = ord($device{10});
          $uintUUID  = substr($device, 2, classConstant::UUID_DEVICE_LENGTH);
          $UUID      = $this->lightifyBase->ChrToUUID($uintUUID);

          //Store group buffer
          $jsonString = $this->SendDataToParent(json_encode(array(
            'DataID' => classConstant::TX_GATEWAY,
            'method' => classConstant::METHOD_APPLY_CHILD,
            'mode'   => classConstant::MODE_DEVICE_GROUP,
            'buffer' => $UUID))
          );

          if ($jsonString != vtNoString) {
            //IPS_LogMessage("SymconOSR", "<Device|setDeviceProperty|group:buffer>   ".IPS_GetName($this->InstanceID)." - ".$deviceID."/".$UUID."/".$jsonString);
            $data   = json_decode($jsonString);
            $buffer = utf8_decode($data->buffer);

            $this->SetBuffer("groupUID", $buffer);
          }

          if ($this->ReadPropertyInteger("classType") != $classType) {
            IPS_SetProperty($this->InstanceID, "classType", (int)$classType);
          }

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyString("UUID") != $UUID) {
            IPS_SetProperty($this->InstanceID, "UUID", $UUID);
          }

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


  private function getDeviceLocal($deviceID, $ncount, $data)
  {

    $device = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($data{2});
      $data    = substr($data, 3);

      if ($localID == $deviceID) {
        $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
        break;
      }

      $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
    }

    return $device;

  }


  private function getDeviceCloud($deviceID, $data)
  {

    $cloud  = vtNoString;
    $buffer = json_decode($data);

    foreach ($buffer as $device) {
      list($cloudID) = $device;

      if ($cloudID == $deviceID) {
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

        //Decode Device label
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

        $hue    = $color = $brightness = vtNoString;
        $temperature     = $saturation = vtNoString;

        $newOnline = (ord($data{15}) == classConstant::STATE_ONLINE) ? true : false; //Online: 2 - Offline: 0 - Unknown: 1
        $online    = $state = false;

        if ($classLight || $classPlug) {
          $newState  = ($newOnline) ? (bool)ord($data{18}) : false;
        } else {
          $newState = (bool)ord($data{22}); //State = red
        }

        if ($classLight) {
          $brightness = ord($data{19});
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
            $onlineID = $this->RegisterVariableBoolean("ONLINE", $this->Translate("Online"), "OSR.Switch", 312);

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
            $stateID = $this->RegisterVariableBoolean("STATE", $this->Translate("State"), "OSR.Switch", 313);

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
                $hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);

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
                $colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
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
                $saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
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
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTemp", 316);
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
            if (false == ($brightnessID = @$this->GetIDForIdent("BRIGHTNESS"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                $brightnessID = $this->RegisterVariableInteger("BRIGHTNESS", $this->Translate("Brightness"), "OSR.Intensity", 317);
                IPS_SetIcon($brightnessID, "Sun");

                $this->EnableAction("BRIGHTNESS");
              }
            }

            if ($brightnessID !== false) {
              if (GetValueInteger($brightnessID) != $brightness) {
                SetValueInteger($brightnessID, $brightness);
              }
            }
          }
        }

        if ($classMotion) {
          $battery = dechex(ord($data{19}));       //brightness = battery value
          $motion  = (bool)ord($data{23}); //Light = green, Sensor = motion detection

          if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $motionID = $this->RegisterVariableBoolean("MOTION", $this->Translate("Motion"), "~Motion", 322);
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
        list($cloudID, $deviceType, $manufacturer, $deviceModel, $deviceLabel, $firmware) = json_decode($data);

        if ($method == classConstant::METHOD_CREATE_CHILD) {
          if ($classType != classConstant::TYPE_SENSOR_MOTION && $classType != classConstant::TYPE_DIMMER_2WAY && $classType != classConstant::TYPE_SWITCH_4WAY) {
            if ($this->ReadPropertyString("manufacturer") != $manufacturer) {
              IPS_SetProperty($this->InstanceID, "manufacturer", $manufacturer);
            }

            if ($this->ReadPropertyString("deviceModel") != $deviceModel) {
              IPS_SetProperty($this->InstanceID, "deviceModel", $deviceModel);
            }

            if ($this->ReadPropertyString("deviceLabel") != $deviceLabel) {
              IPS_SetProperty($this->InstanceID, "deviceLabel", $deviceLabel);
            }
          }
        }

        //Create and update firmware version
        if (false === ($firmwareID = @$this->GetIDForIdent("FIRMWARE"))) {
          if ($method == classConstant::METHOD_CREATE_CHILD) {
            $firmwareID = $this->RegisterVariableString("FIRMWARE", $this->Translate("Firmware"), vtNoString, 322);
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
