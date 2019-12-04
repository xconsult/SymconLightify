<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyDevice extends IPSModule
{


  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->RegisterPropertyInteger("itemID", vtNoValue);

    $this->RegisterPropertyString("itemClass", vtNoString);
    $this->RegisterPropertyInteger("classType", vtNoValue);
    $this->RegisterPropertyString("UUID", vtNoString);

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
    //$filter = ".*".preg_quote(trim(json_encode($this->ReadPropertyString("UUID")), '"')).".*";
    //$this->SetReceiveDataFilter($filter);

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

    if (!$this->HasActiveParent()) {
      $this->SetStatus(201);
      return vtNoForm;
    }

    //Validate
    $itemID = $this->ReadPropertyInteger("itemID");

    if ($itemID != vtNoValue) {
      $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);

      if ($itemID == 1000 || $this->ReadPropertyString("itemClass") != "Light") {
        $formJSON['actions'][0]['items'][2]['visible'] = false;
      }
      return json_encode($formJSON);
    }

    $elements[] = [
      'type'    => "Label",
      'caption' => "Group can only be configured over the Lightify Discovery Instance!"
    ];
    $status[] = [
      'code'    => 104,
      'icon'    => "inactive",
      'caption' => "Device is inactive"
    ];
    $formJSON = [
      'elements' => $elements,
      'status'   => $status
    ];

    $this->SetStatus(104);
    return json_encode($formJSON);

  }


  public function ReceiveData($JSONString) {

    $data  = json_decode($JSONString, true);
    $debug = IPS_GetProperty($data['id'], "debug");
    //IPS_LogMessage("SymconOSR", "<Device|Receive:data>   ".IPS_GetName($this->InstanceID)."|".count($data)."|".json_encode($data));

    foreach ($data['buffer'] as $device) {
      if ($device['UUID'] == $this->ReadPropertyString("UUID")) {
        if ($device['type'] == classConstant::TYPE_ALL_DEVICES) {
          $newState = (bool)$device['state'];

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
          $this->setDeviceInfo($device);
          break;
        }
      }
    }

  }


  private function setDeviceInfo(array $data) : void {

    //Decode device class
    $classLight = $classPlug = $classMotion = false;
    $classType  = $data['type'];

    switch ($classType) {
      case classConstant::TYPE_PLUG_ONOFF:
        $classPlug = true;
        break;

      case classConstant::TYPE_SENSOR_MOTION:
        $classMotion = true;
        break;

      case classConstant::TYPE_DIMMER_2WAY:
        $classDimmer = true;
        break;

      case classConstant::TYPE_SWITCH_4WAY:
      case classConstant::TYPE_SWITCH_MINI:
        $classSwitch = true;
        break;

      default:
        $classLight = true;
    }

    //Additional informations
    $zigBee   = $data['zigBee'];
    $firmware = $data['firmware'];

    if ($classLight || $classPlug || $classMotion) {
      $deviceRGB  = ($classType & 8) ? true: false;
      $deviceCCT  = ($classType & 2) ? true: false;
      $deviceCLR  = ($classType & 4) ? true: false;

      $hue    = $color = $level      = vtNoString;
      $temperature     = $saturation = vtNoString;

      if ($classLight) {
        $level = $data['level'];
        $white = $data['white'];
        $rgb   = $data['rgb'];
        $hex   = $this->lightifyBase->RGB2HEX($rgb);
        $hsv   = $this->lightifyBase->HEX2HSV($hex);
      }

      if ($deviceRGB) {
        $hue        = $hsv['h'];
        $color      = hexdec($hex);
        $saturation = $hsv['s'];
      }

      if ($deviceCCT) {
        $temperature = $data['cct'];
      }

      if (false === ($onlineID = @$this->GetIDForIdent("ONLINE"))) {
        //$this->MaintainVariable("ONLINE", $this->Translate("Online"), vtBoolean, "OSR.Switch", 312, true);
        //$onlineID = $this->GetIDForIdent("ONLINE");
        $onlineID = $this->RegisterVariableBoolean("ONLINE", $this->Translate("Online"), "OSR.Switch", 312);

        IPS_SetDisabled($onlineID, true);
        IPS_SetHidden($onlineID, true);
      }

      if ($onlineID) {
        $online = $data['online'];

        if (GetValueBoolean($onlineID) != $online) {
          SetValueBoolean($onlineID, $online);
        }
      }

      if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
        //$this->MaintainVariable("STATE", $this->Translate("State"), vtBoolean, "OSR.Switch", 313, true);
        //$stateID = $this->GetIDForIdent("STATE");
        $stateID = $this->RegisterVariableBoolean("STATE", $this->Translate("State"), "OSR.Switch", 313);

        if ($classLight || $classPlug) {
          $this->EnableAction("STATE");
        }
      }

      if ($stateID) {
        $state = ($classMotion) ? (bool)$data['rgb']['r'] : $data['state'];

        if (GetValueBoolean($stateID) != $state) {
          SetValueBoolean($stateID, $state);
        }
      }

      if ($classLight || $classPlug) {
        if ($deviceRGB) {
          if (false === ($hueID = @$this->GetIDForIdent("HUE"))) {
            //$this->MaintainVariable("HUE", $this->Translate("Hue"), vtInteger, "OSR.Hue", 314, true);
            //$hueID = $this->GetIDForIdent("HUE");
            $hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);

            IPS_SetDisabled($hueID, true);
            IPS_SetHidden($hueID, true);
          }

          if ($hueID) {
            if (GetValueInteger($hueID) != $hue) {
              SetValueInteger($hueID, $hue);
            }
          }

          if (false === ($colorID = @$this->GetIDForIdent("COLOR"))) {
            $this->MaintainVariable("COLOR", $this->Translate("Color"), vtInteger, "~HexColor", 315, true);
            $colorID = $this->GetIDForIdent("COLOR");

            //$colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
            //$colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
            IPS_SetIcon($colorID, "Paintbrush");
            $this->EnableAction("COLOR");
          }

          if ($colorID) {
            if (GetValueInteger($colorID) != $color) {
              SetValueInteger($colorID, $color);
            }
          }

          if (false === ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
            //$this->MaintainVariable("SATURATION", $this->Translate("Saturation"), vtInteger, "OSR.Intensity", 318, true);
            //$saturationID = $this->GetIDForIdent("SATURATION");
            $saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);

            IPS_SetIcon($saturationID, "Intensity");
            $this->EnableAction("SATURATION");
          }

          if ($saturationID) {
            if (GetValueInteger($saturationID) != $saturation) {
              SetValueInteger($saturationID, $saturation);
            }
          }
        }

        if ($deviceCCT) {
          if (false === ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
            $profile = ($deviceRGB) ? "OSR.ColorTempExt" : "OSR.ColorTemp";

            //$this->MaintainVariable("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), vtInteger, "OSR.ColorTemp", 316, true);
            //$temperatureID = $this->GetIDForIdent("COLOR_TEMPERATURE");
            $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTemp", 316);
            $this->EnableAction("COLOR_TEMPERATURE");
          }

          if ($temperatureID) {
            if (GetValueInteger($temperatureID) != $temperature) {
              SetValueInteger($temperatureID, $temperature);
            }
          }
        }

        if ($classLight) {
          if (false === ($levelID = @$this->GetIDForIdent("LEVEL"))) {
            //$this->MaintainVariable("LEVEL", $this->Translate("Level"), vtInteger, "OSR.Intensity", 317, true);
            //$levelID = $this->GetIDForIdent("LEVEL");
            $levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);

            IPS_SetIcon($levelID, "Sun");
            $this->EnableAction("LEVEL");
          }

          if ($levelID) {
            if (GetValueInteger($levelID) != $level) {
              SetValueInteger($levelID, $level);
            }
          }
        }
      }

      if ($classMotion) {
        if (false === ($motionID = @$this->GetIDForIdent("MOTION"))) {
          //$this->MaintainVariable("MOTION", $this->Translate("Motion"), vtBooelan, "~Motion", 322, true);
          //$$motionID = $this->GetIDForIdent("MOTION");
          $motionID = $this->RegisterVariableBoolean("MOTION", $this->Translate("Motion"), "~Motion", 322);
          $this->EnableAction("MOTION");
        }

        if ($motionID) {
          $motion = $data['rgb']['g']; //Motion detection = green

          if (GetValueBoolean($motionID) != $motion) {
            SetValueBoolean($motionID, $motion);
          }
        }
      }
    }

  }


}
