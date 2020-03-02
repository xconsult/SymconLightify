<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class LightifyDevice extends IPSModule {

  private const METHOD_SET_DEVICE_FADING = "set:device:fading";
  private const METHOD_SET_STATE         = "set:state";
  private const METHOD_SET_SAVE          = "set:save";

  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->RegisterAttributeInteger("ID", vtNoValue);
    $this->RegisterPropertyString("UUID", vtNoString);

    $this->RegisterPropertyString("module", vtNoString);
    $this->RegisterPropertyInteger("type", vtNoValue);

    $this->RegisterAttributeInteger("transition", classConstant::TIME_MIN);
    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    //Apply filter
    //$filter = '.*\\"UUID\\":"\\'.$this->ReadPropertyString("UUID").'\\".*';
    $filter = ".*--".$this->ReadPropertyString("UUID")."--.*";
    $this->SetReceiveDataFilter($filter);

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", IPS_GetName($this->InstanceID)."|".$filter);
    $this->WriteAttributeInteger("transition", classConstant::TIME_MIN);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;
    }

  }


  public function GetConfigurationForm() {

    //Validate
    $type = $this->ReadPropertyInteger("type");

    if ($type != vtNoValue) {
      $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
      $onlineID = @$this->GetIDForIdent("ONLINE");

      $module = $this->ReadPropertyString("module");
      $formJSON['actions'][0]['items'][0]['value'] = $this->Translate($module);

      if ($module == "Light" || $module == "Plug") {
        if ($module == "Light") {
          $formJSON['actions'][2]['items'][1]['enabled'] = true;
        }
        $i = 0;

        foreach ($this->getDeviceGroups() as $group) {
          $instanceID = $this->lightifyBase->getInstanceByID(classConstant::MODULE_GROUP, $group);

          if ($instanceID) {
            $name = IPS_GetName($instanceID);

            $formJSON['actions'][5]['items'][1]['items'][$i]['type']     = "OpenObjectButton";
            $formJSON['actions'][5]['items'][1]['items'][$i]['enabled']  = true;
            $formJSON['actions'][5]['items'][1]['items'][$i]['caption']  = $name;
            $formJSON['actions'][5]['items'][1]['items'][$i]['objectID'] = $instanceID;
            $formJSON['actions'][5]['items'][1]['items'][$i]['width']    = "auto";
          }

          $i++;
        }

        $caption = $this->Translate("Connected to the following group(s)");
        $formJSON['actions'][5]['items'][0]['caption'] = $caption;

      } else {
        $caption = $this->Translate("Not connected to any group");
        $formJSON['actions'][5]['items'][0]['caption'] = $caption;
      }

      if ($module == "Light" || $module == "Plug" || $module == "Sensor") {
        $stateID = @$this->GetIDForIdent("STATE");

        if ($onlineID && GetValueBoolean($onlineID)) {
          $formJSON['actions'][3]['items'][0]['enabled'] = true;
          $formJSON['actions'][3]['items'][1]['enabled'] = true;

          if ($module == "Light") {
            $formJSON['actions'][3]['items'][2]['enabled'] = true;
          } else {
            $formJSON['actions'][3]['items'][2]['enabled'] = false;
          }
        } else {
          $formJSON['actions'][3]['items'][0]['enabled'] = false;
          $formJSON['actions'][3]['items'][1]['enabled'] = false;
          $formJSON['actions'][3]['items'][2]['enabled'] = false;
        }
      }
      elseif ($module == "Dimmer" || $module == "Switch") {
        $stateID = $onlineID;

        $formJSON['actions'][3]['items'][0]['enabled'] = false;
        $formJSON['actions'][3]['items'][1]['enabled'] = false;
        $formJSON['actions'][3]['items'][2]['enabled'] = false;
      }

      if ($type == classConstant::TYPE_ALL_DEVICES) {
        $stateID = @$this->GetIDForIdent("ALL_DEVICES");
        $formJSON['actions'][3]['items'][2]['enabled'] = false;
      }

      if ($stateID && GetValueBoolean($stateID)) {
        $formJSON['actions'][0]['items'][1]['visible'] = true;
      } else {
        $formJSON['actions'][0]['items'][2]['visible'] = true;
      }

      return json_encode($formJSON);
    }

    $formJSON = [
      'elements' => [
        'type'    => "Label",
        'caption' => "Device can only be configured over the Configurator Instance!"
      ]
    ];

    return json_encode($formJSON);

  }


  public function GlobalDeviceModule(array $param) : void {

    switch ($param['method']) {
      case self::METHOD_SET_DEVICE_FADING:
        $this->setDeviceFading($param['value']);
        break;

      case self::METHOD_SET_STATE:
        $this->deviceStateChange((bool)$param['value']);
        break;

      case self::METHOD_SET_SAVE:
        $this->deviceStoreValues($param['values']);
        break;
    }

  }


  public function ReceiveData($JSONString) {

    //Decode data
    $data = json_decode($JSONString, true);
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $this->InstanceID."|".json_encode($data['buffer']));

    if (!empty($data)) {
      $buffer = $data['buffer'];

      if ($buffer['UUID'] == $this->ReadPropertyString("UUID")) {
        $this->WriteAttributeInteger("ID", $buffer['ID']);

        if ($buffer['type'] == classConstant::TYPE_ALL_DEVICES) {
          $newState = (bool)$buffer['state'];

          if (false === ($stateID  = @$this->GetIDForIdent("ALL_DEVICES"))) {
            $stateID = $this->RegisterVariableBoolean("ALL_DEVICES", $this->Translate("State"), "OSR.Switch", 313);
            $this->EnableAction("ALL_DEVICES");
          }

          if ($stateID) {
             $state = GetValueBoolean($stateID);

            if ($state != $newState) {
              SetValueBoolean($stateID, $newState);
            }
          }

        } else {
          $this->setDeviceInfo($buffer);
        }
      }
    }

  }


  private function setDeviceInfo(array $data) : void {

    //Decode device module
    $light = $plug = $motion = false;
    $type  = $data['type'];

    switch ($type) {
      case classConstant::TYPE_PLUG_ONOFF:
        $plug = true;
        break;

      case classConstant::TYPE_SENSOR_MOTION:
        $motion = true;
        break;

      case classConstant::TYPE_DIMMER_2WAY:
        $dimmer = true;
        break;

      case classConstant::TYPE_SWITCH_4WAY:
      case classConstant::TYPE_SWITCH_MINI:
        $switch = true;
        break;

      default:
        $light = true;
    }

    //Additional informations
    $disable  = true;

    if (false === ($zigBeeID = @$this->GetIDForIdent("ZLL"))) {
      $zigBeeID = $this->RegisterVariableString("ZLL", "ZLL", vtNoString, 311);

      IPS_SetIcon($zigBeeID, "Network");
      IPS_SetDisabled($zigBeeID, true);
    }

    if ($zigBeeID) {
      $zigBee = $data['zigBee'];

      if (GetValueString($zigBeeID) != $zigBee) {
        SetValueString($zigBeeID, $zigBee);
      }
    }

    if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
      //$this->MaintainVariable("ONLINE", $this->Translate("Online"), vtBoolean, "OSR.Switch", 312, true);
      //$onlineID = $this->GetIDForIdent("ONLINE");
      $onlineID = $this->RegisterVariableBoolean("ONLINE", $this->Translate("Online"), "OSR.Switch", 312);

      IPS_SetDisabled($onlineID, true);
      IPS_SetHidden($onlineID, true);
    }

    if ($onlineID) {
      $online  = (bool)$data['online'];
      $disable = (bool)!$online;

      if (GetValueBoolean($onlineID) != $online) {
        SetValueBoolean($onlineID, $online);
      }
    }

    if ($light || $plug || $motion) {
      $RGB = ($type & 8) ? true: false;
      $CCT = ($type & 2) ? true: false;
      $CLR = ($type & 4) ? true: false;

      $hue = $color = $level = vtNoString;
      $cct = $saturation     = vtNoString;

      if ($light) {
        $rgb = $data['rgb'];
        $hex = $this->lightifyBase->RGB2HEX($rgb);
        $hsv = $this->lightifyBase->HEX2HSV($hex);

        $level = $data['level'];
        $white = $data['white'];
      }

      if ($RGB) {
        $hue = $hsv['h'];
        $color = hexdec($hex);
        $saturation = $hsv['s'];
      }

      if ($CCT) {
        $cct = $data['cct'];
      }

      if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
        //$this->MaintainVariable("STATE", $this->Translate("State"), vtBoolean, "OSR.Switch", 313, $online);
        //$stateID = $this->GetIDForIdent("STATE");
        $stateID = $this->RegisterVariableBoolean("STATE", $this->Translate("State"), "OSR.Switch", 313);
      }

      if ($stateID) {
        $state = ($motion) ? (bool)$data['rgb']['r'] : (bool)$data['state'];

        if ($light || $plug) {
          if ($disable) {
            $this->DisableAction("STATE");
          } else {
            $this->EnableAction("STATE");
          }
        }

        if (GetValueBoolean($stateID) != $state) {
          SetValueBoolean($stateID, $state);
        }
      }

      if ($light || $plug) {
        if ($RGB) {
          if (false === ($hueID = @$this->GetIDForIdent("HUE"))) {
            //$this->MaintainVariable("HUE", $this->Translate("Hue"), vtInteger, "OSR.Hue", 314, true);
            //$hueID = $this->GetIDForIdent("HUE");
            $hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);

            IPS_SetDisabled($hueID, true);
            IPS_SetHidden($hueID, true);
          }

          if ($hueID) {
            if (!$disable && GetValueInteger($hueID) != $hue) {
              SetValueInteger($hueID, $hue);
            }
          }

          if (false === ($colorID = @$this->GetIDForIdent("COLOR"))) {
            //$this->MaintainVariable("COLOR", $this->Translate("Color"), vtInteger, "~HexColor", 315, true);
            //$colorID = $this->GetIDForIdent("COLOR");
            $colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
            IPS_SetIcon($colorID, "Paintbrush");
          }

          if ($colorID) {
            if ($disable) {
              $this->DisableAction("COLOR");
            } else {
              $this->EnableAction("COLOR");
            }

            if (!$disable && GetValueInteger($colorID) != $color) {
              SetValueInteger($colorID, $color);
            }
          }

          if (false === ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
            //$this->MaintainVariable("SATURATION", $this->Translate("Saturation"), vtInteger, "OSR.Intensity", 318, true);
            //$saturationID = $this->GetIDForIdent("SATURATION");
            $saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
            IPS_SetIcon($saturationID, "Intensity");
          }

          if ($saturationID) {
            $this->DisableAction("SATURATION");

            if (!$disable && GetValueInteger($saturationID) != $saturation) {
              SetValueInteger($saturationID, $saturation);
            }
          }
        }

        if ($CCT) {
          if (false === ($cctID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
            $profile = ($RGB) ? "OSR.ColorTempExt" : "OSR.ColorTemp";

            //$this->MaintainVariable("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), vtInteger, "OSR.ColorTemp", 316, true);
            //$cctID = $this->GetIDForIdent("COLOR_TEMPERATURE");
            $cctID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTemp", 316);
          }

          if ($cctID) {
            if ($disable) {
              $this->DisableAction("COLOR_TEMPERATURE");
            } else {
              $this->EnableAction("COLOR_TEMPERATURE");
            }

            if (!$disable && GetValueInteger($cctID) != $cct) {
              SetValueInteger($cctID, $cct);
            }
          }
        }

        if ($light) {
          if (false === ($levelID = @$this->GetIDForIdent("LEVEL"))) {
            //$this->MaintainVariable("LEVEL", $this->Translate("Level"), vtInteger, "OSR.Intensity", 317, true);
            //$levelID = $this->GetIDForIdent("LEVEL");
            $levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);
            IPS_SetIcon($levelID, "Sun");
          }

          if ($levelID) {
            if ($disable) {
              $this->DisableAction("LEVEL");
            } else {
              $this->EnableAction("LEVEL");
            }

            if (!$disable && GetValueInteger($levelID) != $level) {
              SetValueInteger($levelID, $level);
            }
          }
        }
      }

      if ($motion) {
        if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
          //$this->MaintainVariable("MOTION", $this->Translate("Motion"), vtBooelan, "~Motion", 322, true);
          //$$motionID = $this->GetIDForIdent("MOTION");
          $motionID = $this->RegisterVariableBoolean("MOTION", $this->Translate("Motion"), "~Motion", 322);
        }

        if ($motionID) {
          $detect = $data['rgb']['g']; //Motion detection = green

          if ($disable) {
            $this->DisableAction("MOTION");
          } else {
            $this->EnableAction("MOTION");
          }

          if (!$disable && GetValueBoolean($motionID) != $detect) {
            SetValueBoolean($motionID, $detect);
          }
        }
      }
    }

    //Firmware
    if (false === ($firmwareID = @$this->GetIDForIdent("FIRMWARE"))) {
      $firmwareID = $this->RegisterVariableString("FIRMWARE", $this->Translate("Firmware"), vtNoString, 324);
      IPS_SetDisabled($firmwareID, true);
    }

    if ($firmwareID) {
      $firmware = $data['firmware'];

      if (GetValueString($firmwareID) != $firmware) {
        SetValueString($firmwareID, $firmware);
      }
    }

  }


  protected function getDeviceGroups() : array {
    //Get buffer list
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_BUFFER_DEVICES,
      'uID'    => $this->ReadAttributeInteger("ID")])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    //Decode
    $data   = json_decode($data);
    $Groups = [];

    if (!empty($data)) {
      $Groups = $data->Groups;
    }

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Groups));
    return $Groups;
  }


  private function setDeviceFading(float $value) : void {

    $caption = $this->Translate("Fade On/Off")." "."[".$this->Translate("Duration").sprintf(" %0.1fs]", $value);
    $this->UpdateFormField("deviceFade", "caption", $caption);

  }


  private function deviceStateChange(bool $state) : void {

    $result = OSR_WriteValue($this->InstanceID, 'STATE', $state);
    $status = json_decode($result);

    if ($status->flag && $status->code == 0) {
      $this->UpdateFormField("deviceOn", "visible", $state);
      $this->UpdateFormField("deviceOff", "visible", !$state);
    }

  }

  private function deviceStoreValues(array $values) : void {

    //Show progress bar
    $this->showProgressBar(true);

    //Set On/Off
    list($save, $fading) = $values;
    $result = OSR_WriteValue($this->InstanceID, 'SOFT_ON', $fading*10);

    $result = OSR_WriteValue($this->InstanceID, 'SAVE', $save);
    $status = json_decode($result);
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $result);

    //Hide progress bar
    $this->showProgressBar(false);

    //Show info
    if ($status->flag || $status->code == 0) {
      $caption = $this->Translate("Current device settings were successfully stored!");
    } else {
      $caption = $this->Translate("Current device settings were not stored!")."  (Error: ".$status->code.")";
    }

    //Show info
    $this->showInfoWindow($caption);

  }


  private function showInfoWindow(string $caption) : void {

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $caption);

    $this->UpdateFormField("alertProgress", "visible", false);
    $this->UpdateFormField("alertMessage", "visible", true);
    $this->UpdateFormField("alertMessage", "caption", $caption);

    $this->UpdateFormField("popupAlert", "visible", true);

  }


  private function showProgressBar(bool $show) : void {

    if ($show) {
      $this->UpdateFormField("alertProgress", "visible", true);
      $this->UpdateFormField("alertMessage", "visible", false);

      $this->UpdateFormField("popupAlert", "visible", true);

    } else {
      $this->UpdateFormField("popupAlert", "visible", false);
    }
  }


}
