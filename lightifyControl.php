<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


define('BODY_CMD_SET',        "/set?idx=");
define('BODY_CMD_STATE',      "&onoff=");
define('BODY_CMD_HUE',        "&hue=");
define('BODY_CMD_COLOR',      "&color=");
define('BODY_CMD_CTEMP',      "&ctemp=");
define('BODY_CMD_LEVEL',      "&level=");
define('BODY_CMD_SATURATION', "&saturation=");
define('BODY_CMD_TIME',       "&time=0");

define('RESSOURCE_DEVICE',    "/device");
define('RESSOURCE_GROUP',     "/group");

define('DATA_INDEX_LENGTH',      3);
define('WAIT_TIME_SEMAPHORE', 1500); //milliseconds


trait LightifyControl
{


  protected $lightifyBase;
  protected $fade = false;

  use ParentInstance,
      InstanceHelper;


  public function __construct($InstanceID)
  {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  private function sendData($command, $buffer = null)
  {

    //IPS_LogMessage("SymconOSR", "<Lightify|sendData:buffer>   ".json_encode($buffer));

    $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $command,
      'buffer' => json_encode($buffer)])
    );

  }


  public function RequestAction($Ident, $Value)
  {

    $key   = (string)$Ident;
    $value = (int)$Value;

    switch ($key) {
      case "ALL_DEVICES":
        break;

      case "SCENE":
        $value = $this->ReadPropertyInteger("groupID");
        break;

      case "STATE":
      case "COLOR":
      case "COLOR_TEMPERATURE":
      case "BRIGHTNESS":
      case "LEVEL":
      case "SATURATION":
        break;
    }

    return $this->WriteValue($key, $value);
  }


  public function SetValue($key, $value)
  {

    $this->WriteValue($key, $value);

  }

  public function WriteValue(string $key, int $value)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      if (IPS_GetProperty($parentID, "active")) {
        $key = strtoupper($key);
        $uintUUID = $this->ReadPropertyString("uintUUID");

        if (!in_array($key, explode(",", classConstant::WRITE_KEY_VALUES))) {
          return false;
        }

        $waitResult = @IPS_GetProperty($parentID, "waitResult");
        $itemClass  = $this->ReadPropertyInteger("itemClass");

        $classLight  = ($itemClass == classConstant::CLASS_LIGHTIFY_LIGHT) ? true : false;
        $classPlug   = ($itemClass == classConstant::CLASS_LIGHTIFY_PLUG) ? true : false;
        $classMotion = ($itemClass == classConstant::CLASS_LIGHTIFY_SENSOR) ? true : false;
        $classGroup  = ($itemClass == classConstant::CLASS_LIGHTIFY_GROUP) ? true : false;
        $classScene  = ($itemClass == classConstant::CLASS_LIGHTIFY_SCENE) ? true : false;

        $deviceRGB  = ($itemClass & 8) ? true: false;
        $deviceCCT  = ($itemClass & 2) ? true: false;
        $deviceCLR  = ($itemClass & 4) ? true: false;

        if ($classLight || $classMotion || $classPlug) {
          $flag     = chr(0x00);
          $onlineID = @$this->GetIDForIdent("ONLINE");
          $online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
        }

        if ($classGroup || $classScene) {
          $flag     = chr(0x02);
          $uintUUID = str_pad(substr($uintUUID, 0, 1), classConstant::UUID_OSRAM_LENGTH, chr(0x00), STR_PAD_RIGHT);
          $online   = false;

          if ($classGroup) {
            $this->fade = classConstant::TRANSITION_DEFAULT;
          }
        }

        if ($classLight || $classMotion || $classPlug || $classGroup) {
          $stateID = @$this->GetIDForIdent("STATE");
          $state   = ($stateID) ? GetValueBoolean($stateID) : false;

          if ($classLight) {
            if (!$this->fade) {
              //$this->fade = IPS_GetProperty($this->InstanceID, "transition")*10;
              //$this->fade = $this->ReadPropertyFloat("transition")*10;
            }
          }
        }

        switch($key) {
          case "ALL_DEVICES":
            if ($value == 0  || $value == 1) {
              $stateID = @$this->GetIDForIdent("ALL_DEVICES");

              $args = [
                'stateID' => $stateID,
                'state'   => $value
              ];
              $this->sendData(classConstant::SET_ALL_DEVICES, $args);

              if ($stateID) {
                SetValue($stateID, (bool)$value);
              }

              return true;
            }
            return false;

          case "SAVE":
            if ($classLight) {
              if ($value == 1) {
                $args = [
                  'ID'   => $this->InstanceID,
                  'UUID' => utf8_encode($uintUUID)
                ];

                $this->sendData(classConstant::SAVE_LIGHT_STATE, $args);
                return true;
              }
            }
            return false;

          case "SCENE":
            if ($classScene) {
              if (is_int($value)) {
                $this->sendData(classConstant::ACTIVATE_GROUP_SCENE, ['sceneID' => $value]);
                return true;
              }
            }
            return false;

          case "DEFAULT":
            if ($value == 1) {
              //Reset light to default values
              if (($deviceRGB && $online) || $classGroup) {
                $hueID   = @$this->GetIDForIdent("HUE");
                $colorID = @$this->GetIDForIdent("COLOR");

                if ($hueID && $colorID) {
                  $saturationID = @$this->GetIDForIdent("SATURATION");

                  if ($saturationID) {
                    $hsv = $this->lightifyBase->HEX2HSV(classConstant::COLOR_DEFAULT);

                    $args = [
                      'hueID'        => $hueID,
                      'colorID'      => $colorID,
                      'saturationID' => $saturationID,
                      'UUID'         => utf8_encode($uintUUID),
                      'flag'         => $flag,
                      'color'        => $value,
                      'hex'          => classConstant::COLOR_DEFAULT,
                      'hsv'          => $hsv,
                      'fade'         => $this->fade
                    ];
                    $this->sendData(classConstant::SET_COLOR, $args);
                  }
                }
              }

              if (($deviceCCT && $online) || $classGroup) {
                $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");

                if ($temperatureID) {
                  $args = [
                    'temperatureID' => $temperatureID,
                    'UUID'          => utf8_encode($uintUUID),
                    'flag'          => $flag,
                    'temperature'   => classConstant::CTEMP_DEFAULT,
                    'fade'          => $this->fade
                  ];
                  $this->sendData(classConstant::SET_COLOR_TEMPERATURE, $args);
                }
              }

              if (($classLight && $online) || $classGroup) {
                $levelID = @$this->GetIDForIdent("LEVEL");

                if ($levelID) {
                  $level = GetValueInteger($levelID);

                  $args = [
                    'stateID' => $stateID,
                    'levelID' => $levelID,
                    'light'   => $classLight,
                    'UUID'    => utf8_encode($uintUUID),
                    'flag'    => $flag,
                    'state'   => $state,
                    'level'   => classConstant::LEVEL_MAX,
                    'fade'    => $this->fade
                  ];
                  $this->sendData(classConstant::SET_LEVEL, $args);
                }
              }

/*
              if ($classLight) {
                $lightifyConnect->setSoftTime($uintUUID, classCommand::SET_LIGHT_SOFT_ON, classConstant::TRANSITION_DEFAULT);
                $lightifyConnect->setSoftTime($uintUUID, classCommand::SET_LIGHT_SOFT_OFF, classConstant::TRANSITION_DEFAULT);

                IPS_SetProperty($this->InstanceID, "transition", classConstant::TRANSITION_DEFAULT/10);

                if (IPS_HasChanges($this->InstanceID)) {
                  IPS_ApplyChanges($this->InstanceID);
                }
              } */

              return true;
            }
            return false;

          case "SOFT_ON":
            $command = classCommand::SET_LIGHT_SOFT_ON;

          case "SOFT_OFF":
            $command = (!isset($command)) ? classCommand::SET_LIGHT_SOFT_OFF : $command;

          case "TRANSITION":
            if ($classLight) {
              if (!isset($command)) {
                /*
                if ($this->ReadPropertyFloat("transition") != $value) {
                  IPS_SetProperty($this->InstanceID, "transition", $value);
                  IPS_ApplyChanges($this->InstanceID);
                } 

                return true;
              } else {
                $result = $lightifyConnect->setSoftTime($uintUUID, $command, $value*10);

                if ($result !== false) {
                  return true;
                } */
              }
            }
            return false;

          case "RELAX":
            $temperature = classConstant::SCENE_RELAX;
            //fall-trough

          case "ACTIVE":
            if (($classLight && $online) || $classGroup) {
              $temperature = (isset($temperature)) ? $temperature : classConstant::SCENE_ACTIVE;

              if ($value == 1) {
                if ($this->setStateOn($state)) {
                  //if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $temperature))) {
                    return true;
                  //}
                }
              }
            }
            return false;

          case "PLANT_LIGHT":
            if (($classLight && $online) || $classGroup) {
              if ($value == 1) {
                if ($this->setStateOn($state)) {
                  //if (false !== ($result = $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::SCENE_PLANT_LIGHT)))) {
                    return true;
                  //}
                }
              }
            }
            return false;

          case "LIGHTIFY_LOOP":
            if (($deviceRGB && $online) || $classGroup) {
              if ($value == 0 || $value == 1) {
                if ($this->setStateOn($state)) {
                  //if (false !== ($result = $lightifyConnect->sceneLightifyLoop($uintUUID, $flag, $value, 3268))){
                    return true;
                  //}
                }
              }
            }
            return false;

          case "STATE":
            if ((($classLight || $classMotion || $classPlug) && $online) || $classGroup) {
              if ($stateID && $value == 0 || $value == 1) {
                $args = [
                  'stateID' => $stateID,
                  'UUID'    => utf8_encode($uintUUID),
                  'flag'    => $flag,
                  'state'   => $value
                ];
                $this->sendData(classConstant::SET_STATE, $args);

                if (!$waitResult && $stateID) {
                  SetValue($stateID, (bool)$value);
                }

                return true;
              }
            }
            return false;

          case "COLOR":
            if (($deviceRGB && $online) || $classGroup) {
              $hueID   = @$this->GetIDForIdent("HUE");
              $colorID = @$this->GetIDForIdent("COLOR");

              if ($hueID && $colorID) {
                $saturationID = @$this->GetIDForIdent("SATURATION");

                if ($saturationID && $value != GetValueInteger($colorID)) {
                  $hex = str_pad(dechex($value), 6, "0", STR_PAD_LEFT);
                  $hsv = $this->lightifyBase->HEX2HSV($hex);

                  $args = [
                    'hueID'        => $hueID,
                    'colorID'      => $colorID,
                    'saturationID' => $saturationID,
                    'UUID'         => utf8_encode($uintUUID),
                    'flag'         => $flag,
                    'color'        => $value,
                    'hex'          => $hex,
                    'hsv'          => $hsv,
                    'fade'         => $this->fade
                  ];
                  $this->sendData(classConstant::SET_COLOR, $args);

                  if (!$waitResult) {
                    if ($hueID && GetValue($hueID) != $hsv['h']) {
                      SetValue($hueID, $hsv['h']);
                    }

                    if (GetValue($saturationID) != $hsv['s']) {
                      SetValue($saturationID, $hsv['s']);
                    }

                    SetValue($colorID, $value);
                  }
                }

                return true;
              }
            }
            return false;

          case "COLOR_TEMPERATURE":
            if (($deviceCCT && $online) || $classGroup) {
              $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");

              if ($temperatureID) {
                $temperature = GetValueInteger($temperatureID);

                if ($value != $temperature) {
                  $args = [
                    'temperatureID' => $temperatureID,
                    'UUID'          => utf8_encode($uintUUID),
                    'flag'          => $flag,
                    'temperature'   => $value,
                    'fade'          => $this->fade
                  ];
                  $this->sendData(classConstant::SET_COLOR_TEMPERATURE, $args);

                  if (!$waitResult) {
                    SetValue($temperatureID, $value);
                  }

                  return true;
                }
              }
            }
            return false;

          case "BRIGHTNESS":
          case "LEVEL":
            if (($classLight && $online) || $classGroup) {
              $levelID = @$this->GetIDForIdent("LEVEL");

              if ($levelID) {
                $level = GetValueInteger($levelID);

                if ($value != $level) {
                  $args = [
                    'stateID' => $stateID,
                    'levelID' => $levelID,
                    'light'   => ($classLight) ? true : false,
                    'UUID'    => utf8_encode($uintUUID),
                    'flag'    => $flag,
                    'state'   => $state,
                    'level'   => $value,
                    'fade'    => $this->fade
                  ];
                  $this->sendData(classConstant::SET_LEVEL, $args);

                  if (!$waitResult) {
                    if ($classLight && $stateID) {
                      if ($value == 0) {
                        SetValue($stateID, false);
                      } else {
                        SetValue($stateID, true);
                      }
                    }

                    SetValue($levelID, $value);
                  }

                  return true;
                }
              }
            }
            return false;

          case "SATURATION":
            if (($deviceRGB && $online) || $classGroup) {
              $saturationID = @$this->GetIDForIdent("SATURATION");
              $saturationID = ($classLight && $saturationID) ? $saturationID : false;

              if ($saturationID) {
                $saturation = GetValueInteger($saturationID);
                $colorID    = @$this->GetIDForIdent("COLOR");
                $colorID    = ($deviceRGB && $colorID) ? $colorID : false;

                if ($colorID && $value != $saturation) {
                  $hueID = @$this->GetIDForIdent("HUE");

                  if ($hueID) {
                    $hex   = $this->lightifyBase->HSV2HEX(GetValueInteger($hueID), $value, 100);
                    $color = hexdec($hex);

                    $args = [
                      'saturationID' => $saturationID,
                      'colorID'      => $colorID,
                      'UUID'         => utf8_encode($uintUUID),
                      'flag'         => $flag,
                      'color'        => $color,
                      'hex'          => $hex,
                      'saturation'   => $value,
                      'fade'         => $this->fade
                    ];
                    $this->sendData(classConstant::SET_SATURATION, $args);

                    if (!$waitResult) {
                      if ($classLight) {
                        SetValue($saturationID, $value);
                      }

                      if ($deviceRGB && GetValue($colorID) != $color) {
                        SetValue($colorID, $color);
                      }
                    }

                    return true;
                  }
                }
              }
            }
            return false;
        }

        return true;
      }
    }

    return false;

  }


  public function SetValueEx($key, $value, $transition)
  {

    $this->WriteValueEx($key, $value, $transition);

  }


  public function WriteValueEx(string $key, int $value, float $transition)
  {

    $this->fade = $transition;
    return $this->SetValue($key, $value);

  }


  public function WriteName(string $name)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      if (IPS_GetProperty($parentID, "active")) {
        $name = substr(trim($value), 0, classConstant::DATA_NAME_LENGTH);

        $itemClass  = $this->ReadPropertyInteger("itemClass");

        if ($itemClass == classConstant::CLASS_LIGHTIFY_LIGHT || $itemClass == classConstant::CLASS_LIGHTIFY_PLUG || $itemClass == classConstant::CLASS_LIGHTIFY_SENSOR) {
          $flag     = chr(0x00);
          $command  = classConstant::SET_DEVICE_NAME;
          $uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");
        }

        if ($itemClass == classConstant::CLASS_LIGHTIFY_GROUP) {
          $flag = chr(0x02);
          $command  = classConstant::SET_GROUP_NAME;
          $uintUUID = chr(hexdec(@$this->GetIDForIdent("groupID"))).chr(0x00);
        }

        //Forward data to splitter
        $args = [
          'UUID' => utf8_encode($uintUUID),
          'flag' => $flag,
          'name' => $namee
        ];
        $this->sendData($command, $args);

        if (@IPS_GetName($this->InstanceID) != $name) {
          IPS_SetName($this->InstanceID, (string)$name);
        }

        return true;
      }
    }

    return false;

  }


  public function SetState(bool $state)
  {

    return $this->WriteValue("STATE", (int)$state);

  }


  public function GetValue($key)
  {

    $this->ReadValue($key);

  }


  public function ReadValue(string $key)
  {

    if ($objectID = @IPS_GetObjectIDByIdent($key, $this->InstanceID)) {
      return GetValue($objectID);
    }

    return false;

  }


  public function GetValueEx($key)
  {

    $this->ReadValueEx($key);

  }


  public function ReadValueEx(string $key)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      if (IPS_GetProperty($parentID, "active")) {
        $itemClass = $this->ReadPropertyInteger("itemClass");

        if ($itemClass == classConstant::CLASS_LIGHTIFY_LIGHT || $itemClass == classConstant::CLASS_LIGHTIFY_PLUG || $itemClass == classConstant::CLASS_LIGHTIFY_SENSOR) {
          $onlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
          $online   = GetValueBoolean($onlineID);

          if ($online) {
            $buffer = $lightifyConnect->setDeviceInfo(@IPS_GetProperty($this->InstanceID, "uintUUID"));

            if (is_array($list = $buffer) && in_array($key, $list)) {
              return $list[$key];
            }
          }
        }
      }
    }

    return false;

  }


}