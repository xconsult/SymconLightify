<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


trait LightifyControl
{

  protected $lightifyBase;
  protected $fade = 0;

  use InstanceStatus,
      InstanceHelper;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  private function sendData(int $cmd, array $param = []) : bool {

    //Add Instance id
    $param['id']  = $this->InstanceID;

    $result = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $cmd,
      'buffer' => json_encode($param)])
    );

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", IPS_GetName($this->InstanceID)."|".$result);
    return (bool)$result;

  }


  public function RequestAction($Ident, $Value) {

    $value = (int)$Value;
    $key   = (string)$Ident;

    switch ($Ident) {
      case "ALL_DEVICES":
      case "SAVE":
      case "SCENE":
      case "STATE":
      case "COLOR":
      case "COLOR_TEMPERATURE":
      case "LEVEL":
      case "SATURATION":
        break;
    }

    return $this->WriteValue($key, $value);
  }


  public function WriteValue(string $key, int $value) : bool {

    if (!$this->HasActiveParent()) {
      return false;
    }

    //Validate key
    $key = strtoupper($key);

    if (!in_array($key, explode(",", classConstant::WRITE_KEY_VALUES))) {
      return false;
    }

    switch($key) {
      case "ALL_DEVICES":
        if ($value == 0  || $value == 1) {
          if (0 < ($stateID = @$this->GetIDForIdent("ALL_DEVICES"))) {
            $param = [
              'flag'  => chr(0x00),
              'args'  => utf8_encode(str_repeat(chr(0xff), 8).chr($value)),
              'value' => $value
            ];

            //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($param));
            return $this->sendData(classConstant::SET_ALL_DEVICES, $param);
          }
        }
        return false;

      case "SCENE":
        $param = [
          'flag'  => chr(0x02),
          'args'  => utf8_encode(chr($value)),
          'value' => vtNoValue
        ];
        return $this->sendData(classCommand::ACTIVATE_GROUP_SCENE, $param);
    }

    //Get module
    $module = $this->ReadPropertyString("module");
    $Light  = ($module == "Light") ? true : false;
    $Plug   = ($module == "Plug") ? true : false;
    $Motion = ($module == "Sensor") ? true : false;
    $Group  = ($module == "Group") ? true : false;

    if ($Light || $Plug || $Motion) {
      $flag = 0;
      $UUID = $this->lightifyBase->UUIDtoChr($this->ReadPropertyString("UUID"));

      $onlineID = @$this->GetIDForIdent("ONLINE");
      $online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
    } else {
      $flag = 2;
      $UUID   = str_pad(chr($this->ReadPropertyInteger("ID")), classConstant::UUID_OSRAM_LENGTH, chr(0x00), STR_PAD_RIGHT);
      $online = true;
    }

    $stateID = @$this->GetIDForIdent("STATE");
    $state   = ($stateID) ? GetValueBoolean($stateID) : false;
    $this->fade = classConstant::TIME_MIN;

    if ($Light) {
      $type = $this->ReadPropertyInteger("type");

      $RGB  = ($type & 8) ? true: false;
      $CCT  = ($type & 2) ? true: false;
      $CLR  = ($type & 4) ? true: false;

      if (!$this->fade) {
        //$this->fade = IPS_GetProperty($this->InstanceID, "transition")*10;
        //$this->fade = $this->ReadPropertyFloat("transition")*10;
      }
    }

    switch($key) {
      case "SAVE":
        if ($Light) {
          if ($value == 1) {
            $param = [
              'flag'  => $flag,
              'args'  => utf8_encode($UUID.chr(0x00)),
              'value' => vtNoValue
            ];
            return $this->sendData(classCommand::SAVE_LIGHT_STATE, $param);
          }
        }
        return false;

      case "SOFT_ON":
        $cmd = classCommand::SET_LIGHT_SOFT_ON;

      case "SOFT_OFF":
        if (!isset($cmd)) {
          $cmd = classCommand::SET_LIGHT_SOFT_OFF;
        }

      case "FADE":
        if ($Light) {
          if (!isset($cmd)) {
            if ($this->ReadAttributeInteger("fade") != $value) {
              $this->WriteAttributeInteger("fade", $value);
            }
            return true;
          } else {
            $param = [
              'flag'  => $flag,
              'args'  => utf8_encode($UUID.chr($value).chr(0x00)),
              'value' => vtNoValue
            ];
            return $this->sendData($cmd, $param);

          }
        }
        return false;

      case "LIGHTIFY_LOOP":
        if ($online && ($RGB || $Group)) {
          if ($value == 0 || $value == 1) {
            return true;
          }
        }
        return false;

      case "STATE":
        if ($online && $stateID && ($value == 0 || $value == 1)) {
          $param = [
            'flag'  => $flag,
            'args'  => utf8_encode($UUID.chr($value)),
            'value' => $value
          ];

          $cmd = ($Group) ? classConstant::SET_GROUP_STATE : classCommand::SET_DEVICE_STATE;
          return $this->sendData($cmd, $param);
        }
        return false;

      case "PLANT_LIGHT":
        $value = classConstant::SCENE_PLANT_LIGHT;

      case "COLOR":
        if ($online && ($RGB || $Group)) {
          $hueID   = @$this->GetIDForIdent("HUE");
          $colorID = @$this->GetIDForIdent("COLOR");

          if ($hueID && $colorID) {
            $saturationID = @$this->GetIDForIdent("SATURATION");

            if ($saturationID && $value != GetValueInteger($colorID)) {
              $hex = str_pad(dechex($value), 6, "0", STR_PAD_LEFT);
              $hsv = $this->lightifyBase->HEX2HSV($hex);
              $rgb = $this->lightifyBase->HEX2RGB($hex);

              $param = [
                'flag'  => $flag,
                'args'  => utf8_encode($UUID.chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0xff).chr(dechex($this->fade)).chr(0x00).chr(0x00)),
                'value' => vtNoValue
              ];
              $result = $this->sendData(classCommand::SET_LIGHT_COLOR, $param);

              if ($result) {
                if ($hueID && GetValue($hueID) != $hsv['h']) {
                  SetValue($hueID, $hsv['h']);
                }

                if (GetValue($saturationID) != $hsv['s']) {
                  SetValue($saturationID, $hsv['s']);
                }
                SetValue($colorID, $value);
              }
              return $result;
            }
          }
        }
        return false;

      case "RELAX":
        $value = classConstant::SCENE_RELAX;

      case "ACTIVE":
        if (!isset($value)) {
          $value = classConstant::SCENE_ACTIVE;
        }

      case "COLOR_TEMPERATURE":
        if ($online && ($CCT || $Group)) {
          $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");

          if ($temperatureID) {
            $temperature = GetValueInteger($temperatureID);

            if ($value != $temperature) {
              $hex = dechex($value);

              if (strlen($hex) < 4) {
                $hex = str_repeat("0", 4-strlen($hex)).$hex;
              }

              $param = [
                'flag'  => $flag,
                'args'  => utf8_encode($UUID.chr(hexdec(substr($hex, 2, 2))).chr(hexdec(substr($hex, 0, 2))).chr(dechex($this->fade)).chr(0x00).chr(0x00)),
                'value' => vtNoValue
              ];
              $result = $this->sendData(classCommand::SET_COLOR_TEMPERATURE, $param);

              if ($result) {
                SetValue($temperatureID, $value);
              }
              return $result;
            }
          }
        }
        return false;

      case "LEVEL":
        if ($online) {
          $levelID = @$this->GetIDForIdent("LEVEL");

          if ($levelID) {
            $level = GetValueInteger($levelID);

            if ($value != $level) {
              $param = [
                'flag'  => $flag,
                'args'  => utf8_encode($UUID.chr((int)$value).chr(dechex($this->fade)).chr(0x00).chr(0x00)),
                'value' => $value
              ];
              $result = $this->sendData(classCommand::SET_LIGHT_LEVEL, $param);

              if ($result) {
                if ($Light && $stateID) {
                  if ($value == 0) {
                    SetValue($stateID, false);
                  } else {
                    SetValue($stateID, true);
                  }
                }
                SetValue($levelID, $value);
              }
              return $result;
            }
          }
        }
        return false;

      case "SATURATION":
        if ($online && ($RGB || $Group)) {
          $saturationID = @$this->GetIDForIdent("SATURATION");
          $saturationID = ($Light && $saturationID) ? $saturationID : false;

          if ($saturationID) {
            $saturation = GetValueInteger($saturationID);
            $colorID    = @$this->GetIDForIdent("COLOR");
            $colorID    = ($RGB && $colorID) ? $colorID : false;

            if ($colorID && $value != $saturation) {
              $hueID = @$this->GetIDForIdent("HUE");

              if ($hueID) {
                $hex   = $this->lightifyBase->HSV2HEX(GetValueInteger($hueID), $value, 100);
                $color = hexdec($hex);
                $rgb   = $this->lightifyBase->HEX2RGB($hex);

                $param = [
                  'flag'  => $flag,
                  'args'  => utf8_encode($UUID.chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0x00).chr(dechex($this->fade)).chr(0x00).chr(0x00)),
                  'value' => vtNoValue
                ];
                $result = $this->sendData(classCommand::SET_LIGHT_SATURATION, $param);

                if ($result) {
                  if ($Light) {
                    SetValue($saturationID, $value);
                  }

                  if ($RGB && GetValue($colorID) != $color) {
                    SetValue($colorID, $color);
                  }
                  return $result;
                }
              }
            }
          }
        }
        return false;
    }

    return true;

  }


  public function WriteValueEx(string $key, int $value, int $transition) : bool {

    $this->fade = $transition;
    return $this->WriteValue($key, $value);

  }


  public function ReadValue(string $key)
  {

    return $this->GetValue(strtoupper($key));

  }


  public function SetState(bool $state) : bool {

    $stateID = @$this->GetIDForIdent("STATE");
    $allID   = @$this->GetIDForIdent("ALL_DEVICES");

    if ($stateID || $allID) {
      $key = ($stateID) ? "STATE" : "ALL_DEVICES";
      return $this->WriteValue($key, (int)$state);
    }

    return false;

  }


  public function WriteName(string $name) : bool {

    if (!$this->HasActiveParent()) {
      return false;
    }

    $module = $this->ReadPropertyString("module");
    $name = substr(trim($name), 0, classConstant::DATA_NAME_LENGTH);

    if ($module == "Light" || $module == "Plug" || $module == "Sensor") {
      $cmd = classCommand::SET_DEVICE_NAME;
      $flag = chr(0x00);
      $UUID = utf8_encode($this->lightifyBase->UUIDtoChr($this->ReadPropertyString("UUID")));
    }
    elseif ($module == "Group") {
      $cmd = classCommand::SET_GROUP_NAME;
      $flag = chr(0x02);
      $UUID = utf8_encode(chr($this->ReadPropertyInteger("ID")-classConstant::GROUP_ITEM_INDEX).chr(0x00));
    }

    //Forward data to splitter
    $param = [
      'flag'  => $flag,
      'args'  => utf8_decode(($UUID).str_pad($name, classConstant::DATA_NAME_LENGTH).chr(0x00)),
      'value' => vtNoValue
    ];
    $this->sendData($cmd, $param);

    if (IPS_GetName($this->InstanceID) != $name) {
      IPS_SetName($this->InstanceID, $name);
    }

    return true;

  }


}