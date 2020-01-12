<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class LightifyGroup extends IPSModule
{

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
      $formJSON['elements'][1]['items'][0]['values'] = $this->PanelGroupDevices();
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

    foreach($data['buffer'] as $item) {
      $ID = $this->ReadPropertyInteger("ID");

      if ($item['ID'] == $ID) {
        $UUID = $item['UUID'];
        $this->setGroupInfo($UUID);
        break;
      }
    }

  }


  public function PanelGroupDevices() : array {

      //Get data
      $data = $this->SendDataToParent(json_encode([
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::GET_GROUP_DEVICES])
      );
      //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

      $data = json_decode($data);
      $Devices = [];

      if (is_array($data) && count($data) > 0) {
        foreach ($data as $item) {
          if ($item->ID == $this->ReadPropertyInteger("ID")) {
            foreach ($item->UUID as $UUID) {
              $instanceID = $this->getDeviceInstances(classConstant::MODULE_DEVICE, $UUID);

              if ($instanceID) {
                $stateID = @IPS_GetObjectIDByIdent("STATE", $instanceID);
                $state   = ($stateID) ? GetValueBoolean($stateID) : false;
                $module  = @IPS_GetProperty($instanceID, "module");

                $hueID         = @IPS_GetObjectIDByIdent("HUE", $instanceID);
                $colorID       = @IPS_GetObjectIDByIdent("COLOR", $instanceID);
                $temperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $instanceID);
                $levelID       = @IPS_GetObjectIDByIdent("LEVEL", $instanceID);
                $saturationID  = @IPS_GetObjectIDByIdent("SATURATION", $instanceID);
                $zigBeeID      = @IPS_GetObjectIDByIdent("ZLL", $instanceID);
                $firmwareID    = @IPS_GetObjectIDByIdent("FIRMWARE", $instanceID);

                $hue         = ($hueID) ?  GetValueformatted($hueID) : vtNoString;
                $color       = ($colorID) ? strtolower(GetValueformatted($colorID)) : vtNoString;
                $temperature = ($temperatureID) ? GetValueformatted($temperatureID) : vtNoString;
                $level       = ($levelID) ? preg_replace('/\s+/', '', GetValueformatted($levelID)) : vtNoString;
                $saturation  = ($saturationID) ? preg_replace('/\s+/', '', GetValueformatted($saturationID)) : vtNoString;
                $zigBee      = ($zigBeeID) ? GetValueString($zigBeeID) : vtNoString;
                $firmware    = ($firmwareID) ? GetValueString($firmwareID) : vtNoString;

                if ($state) {
                  if ($module == $this->Translate("Plug")) {
                    $rowColor = self::ROW_COLOR_PLUG_ON;
                  } else {
                    $rowColor = ($color != vtNoString) ? self::ROW_COLOR_LIGHT_ON : self::ROW_COLOR_CCT_ON;
                  }
                } else {
                  $rowColor = self::ROW_COLOR_STATE_OFF;
                }

                $Devices[] = [
                  'InstanceID'  => $instanceID,
                  'module'      => $this->translate($module),
                  'name'        => IPS_GetName($instanceID),
                  'hue'         => $hue,
                  'color'       => ($color != vtNoString) ? self::ROW_COLOR_LIGHT_ON : vtNoString,
                  'temperature' => $temperature,
                  'level'       => $level,
                  'saturation'  => $saturation,
                  'transition'  => vtNoString,
                  'zigBee'      => $zigBee,
                  'firmware'    => $firmware,
                  'rowColor'    => $rowColor
                ];
              }
            }
            //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Devices));
            break;
          }
        }
      }

      return $Devices;

  }


  private function setGroupInfo(array $UUID) : void {

    //Get Device instances
    foreach ($UUID as $item) {
      $ID[] = $this->lightifyBase->getInstanceByUUID(classConstant::MODULE_DEVICE, $item);
    }

    //Set group/zone state
    $online    = $state = false;
    $newOnline = $online;
    $newState  = $state;

    $hue = $color = $level      = vtNoValue;
    $temperature  = $saturation = vtNoValue;

    $deviceHue         = $deviceColor = $deviceLevel = vtNoValue;
    $deviceTemperature = $deviceSaturation           = vtNoValue;

    foreach ($ID as $id) {
      $deviceStateID = @IPS_GetObjectIDByIdent("STATE", $id);
      $deviceState   = ($deviceStateID) ? GetValueBoolean($deviceStateID) : false;

      $deviceHueID         = @IPS_GetObjectIDByIdent("HUE", $id);
      $deviceColorID       = @IPS_GetObjectIDByIdent("COLOR", $id);
      $deviceTemperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $id);
      $deviceLevelID       = @IPS_GetObjectIDByIdent("LEVEL", $id);
      $deviceSaturationID  = @IPS_GetObjectIDByIdent("SATURATION", $id);

      $deviceHue           = ($deviceHueID) ?  GetValueInteger($deviceHueID) : vtNoValue;
      $deviceColor         = ($deviceColorID) ? GetValueInteger($deviceColorID) : vtNoValue;
      $deviceTemperature   = ($deviceTemperatureID) ? GetValueInteger($deviceTemperatureID) : vtNoValue;
      $deviceLevel         = ($deviceLevelID) ? GetValueInteger($deviceLevelID) : vtNoValue;
      $deviceSaturation    = ($deviceSaturationID) ? GetValueInteger($deviceSaturationID) : vtNoValue;

      if (!$newState && $deviceState) {
        $newState = true;
      }

      if ($newState && $hue == vtNoValue && $deviceHue != vtNoValue) {
        $hue = $deviceHue;
      }

      if ($newState && $color == vtNoValue && $deviceColor != vtNoValue) {
        $color = $deviceColor;
      }

      if ($newState && $level == vtNoValue && $deviceLevel != vtNoValue) {
        $level = $deviceLevel;
      }

      if ($newState && $temperature == vtNoValue && $deviceTemperature != vtNoValue) {
        $temperature = $deviceTemperature;
      }

      if ($newState && $saturation == vtNoValue && $deviceSaturation != vtNoValue) {
        $saturation = $deviceSaturation;
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


  private function getDeviceInstances($moduleID, $UUID) {

    $IDs = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($IDs as $id) {
      if (@IPS_GetProperty($id, "UUID") == $UUID) {
        return $id;
      }
    }

    return 0;

  }


}
