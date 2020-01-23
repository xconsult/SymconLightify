<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class LightifyGroup extends IPSModule {

  const ROW_COLOR_LIGHT_ON  = "#fffde7";
  const ROW_COLOR_CCT_ON    = "#ffffff";
  const ROW_COLOR_PLUG_ON   = "#cdfcc6";
  const ROW_COLOR_STATE_OFF = "#f6c3c2";

  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->RegisterPropertyInteger("ID", vtNoValue);
    $this->RegisterPropertyString("module", vtNoString);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

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
    $ID = $this->ReadPropertyInteger("ID");

    if ($ID != vtNoValue) {
      $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
      $formJSON['elements'][0]['items'][1]['value'] = $this->Translate($this->ReadPropertyString("module"));

      if ($stateID = @$this->GetIDForIdent("STATE")) {
        if (GetValueBoolean($stateID)) {
          $formJSON['actions'][0]['items'][0]['visible'] = true;
        } else {
          $formJSON['actions'][0]['items'][1]['visible'] = true;
        }
      }

      //Expansion Panel
      //$formJSON['elements'][1]['items'][0]['values'] = $this->getGroupDevices();
      return json_encode($formJSON);
    }

    $formJSON = [
      'elements' => [
        'type'    => "Label",
        'caption' => "Group can only be configured over the Configurator Instance!"
      ]
    ];

    return json_encode($formJSON);

  }


  public function ReceiveData($JSONString) {

    //Decode data
    $data = json_decode($JSONString, true);
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($data['buffer']));

    foreach($data['buffer'] as $group) {
      if ($group['ID'] == $this->ReadPropertyInteger("ID")) {
        $List = $this->getListDevices($group['Devices']);
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($List));
        $this->setGroupInfo($List);
        break;
      }
    }

  }


  private function getGroupDevices() : array {

    //Get data
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    //Decode
    $data = json_decode($data, true);
    $List = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        if ($group['ID'] == $this->ReadPropertyInteger("ID")) {
          $List = $this->getListDevices($group['Devices']);
          //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($List));
          break;
        }
      }
    }

    return $List;

  }


  private function setGroupInfo(array $List) : void {

    //Set group state
    $online    = $state = false;
    $newOnline = $online;
    $newState  = $state;

    $hue = $color = $level      = vtNoValue;
    $temperature  = $saturation = vtNoValue;

    foreach ($List as $device) {
      if (!$newState && (bool)$device['state']) {
        $newState = true;
      }

      if ($newState && $hue == vtNoValue && $device['hue'] != vtNoString) {
        $hue = (int)$device['hue'];
      }

      if ($newState && $color == vtNoValue && $device['color'] != vtNoString) {
        $color = (int)$device['color'];
      }

      if ($newState && $level == vtNoValue && $device['level'] != vtNoString) {
        $level = (int)$device['level'];
      }

      if ($newState && $temperature == vtNoValue && $device['temperature'] != vtNoString) {
        $temperature = (int)$device['temperature'];
      }

      if ($newState && $saturation == vtNoValue && $device['saturation'] != vtNoString) {
        $saturation = (int)$device['saturation'];
      }
    }

    //State control
    if (false === ($stateID = @$this->GetIDForIdent("STATE"))) {
      $this->MaintainVariable("STATE", $this->Translate("State"), vtBoolean, "OSR.Switch", 313, true);
      $stateID = $this->GetIDForIdent("STATE");

      //$stateID = $this->RegisterVariableBoolean($ident, $this->Translate("State"), "OSR.Switch", 313);
      //$stateID = $this->RegisterVariableBoolean($ident, "State", "OSR.Switch", 313);
      $this->EnableAction("STATE");
    }

    if ($stateID) {
      $state = GetValueBoolean($stateID);

      if ($state != $newState) {
        SetValueBoolean($stateID, $newState);
      }
    }

    //Hue control
    if (false === ($hueID = @$this->GetIDForIdent("HUE"))) {
      $this->MaintainVariable("HUE", $this->Translate("Hue"), vtInteger, "OSR.Hue", 314, true);
      $hueID = $this->GetIDForIdent("HUE");

      //$hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);
      //$hueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);

      IPS_SetDisabled($hueID, true);
      IPS_SetHidden($hueID, true);
    }

    if ($hueID && $hue != vtNoValue) {
      if ($hue != GetValueInteger($hueID)) {
        SetValueInteger($hueID, $hue);
      }
    }

    //Color control
    if (false === ($colorID = @$this->GetIDForIdent("COLOR"))) {
      $this->MaintainVariable("COLOR", $this->Translate("Color"), vtInteger, "~HexColor", 315, true);
      $colorID = $this->GetIDForIdent("COLOR");

      //$colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
      //$colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);

      IPS_SetIcon($colorID, "Paintbrush");
      $this->EnableAction("COLOR");
    }

    if ($colorID && $color != vtNoValue) {
      if ($color != GetValueInteger($colorID)) {
        SetValueInteger($colorID, $color);
      }
    }

    //Color temperature control
    if (false === ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
      $this->MaintainVariable("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), vtInteger, "OSR.ColorTempExt", 316, true);
      $temperatureID = $this->GetIDForIdent("COLOR_TEMPERATURE");

      //$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTempExt", 316);
      //$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTempExt", 316);
      $this->EnableAction("COLOR_TEMPERATURE");
    }

    if ($temperatureID && $temperature != vtNoValue) {
      if ($temperature != GetValueInteger($temperatureID)) {
        SetValueInteger($temperatureID, $temperature);
      }
    }

    //Level control
    if (false === ($levelID = @$this->GetIDForIdent("LEVEL"))) {
      $this->MaintainVariable("LEVEL", $this->Translate("Level"), 1, "OSR.Intensity", 317, true);
      $levelID = $this->GetIDForIdent("LEVEL");

      //$levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);
      //$levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 317);
      IPS_SetIcon($levelID, "Sun");

      $this->EnableAction("LEVEL");
    }

    if ($levelID && $level != vtNoValue) {
      if ($level != GetValueInteger($levelID)) {
        SetValueInteger($levelID, $level);
      }
    }

    //Saturation control
    if (false === ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
      $this->MaintainVariable("SATURATION", $this->Translate("Saturation"), vtInteger, "OSR.Intensity", 318, true);
      $saturationID = $this->GetIDForIdent("SATURATION");

      //$saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
      //$saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 318);
      IPS_SetIcon($saturationID, "Intensity");

      $this->EnableAction("SATURATION");
    }

    if ($saturationID && $saturation != vtNoValue) {
      if ($saturation != GetValueInteger($saturationID)) {
        SetValueInteger($saturationID, $saturation);
      }
    }

  }


  private function getListDevices(array $Devices) : array {

    //Initialize
    $List = [];

    foreach ($Devices as $device) {
      $type  = $device['type'];
      $state = $device['state'];

      $hue    = $color = $level      = vtNoString;
      $temperature     = $saturation = vtNoString;

      //Decode device info
      switch ($type) {
        case classConstant::TYPE_FIXED_WHITE:
        case classConstant::TYPE_LIGHT_CCT:
        case classConstant::TYPE_LIGHT_DIMABLE:
        case classConstant::TYPE_LIGHT_COLOR:
        case classConstant::TYPE_LIGHT_EXT_COLOR:
          $module = "Light";

          $RGB = ($type & 8) ? true: false;
          $CCT = ($type & 2) ? true: false;
          $CLR = ($type & 4) ? true: false;

          $level = $device['level'];
          $white = $device['white'];
          $rgb   = $device['rgb'];
          $hex   = $this->lightifyBase->RGB2HEX($rgb);
          $hsv   = $this->lightifyBase->HEX2HSV($hex);

          if ($RGB) {
            $hue        = $hsv['h'];
            $color      = hexdec($hex);
            $saturation = $hsv['s'];
          }

          if ($CCT) {
            $temperature = $device['cct'];
          }
          break;

        case classConstant::TYPE_PLUG_ONOFF:
          $module = "Plug";
          break;

        default:
          continue 2;
      }

      if ($state) {
        if ($module == "Plug") {
          $rowColor = self::ROW_COLOR_PLUG_ON;
        } else {
          $rowColor = ($color != vtNoString) ? self::ROW_COLOR_LIGHT_ON : self::ROW_COLOR_CCT_ON;
        }
      } else {
        $rowColor = self::ROW_COLOR_STATE_OFF;
      }

      $List[] = [
        'module'      => $this->translate($module),
        'name'        => $device['name'],
        'state'       => $state,
        'hue'         => $hue,
        'color'       => $color,
        'temperature' => $temperature,
        'level'       => $level,
        'saturation'  => $saturation,
        'transition'  => vtNoString,
        'zigBee'      => $device['zigBee'],
        'firmware'    => $device['firmware'],
        'rowColor'    => $rowColor
      ];
    }

    return $List;

  }


}
