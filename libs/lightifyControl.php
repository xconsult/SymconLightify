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
  protected $fade = 0;

  use ParentInstance,
      InstanceHelper;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  private function sendData(int $command, array $buffer = []) : void {

    //IPS_LogMessage("SymconOSR", "<Lightify|sendData:buffer>   ".json_encode($buffer));

    $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $command,
      'buffer' => json_encode($buffer)])
    );

  }


  public function RequestAction($Ident, $Value) {

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


  public function WriteValue(string $key, int $value) : bool {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      if (IPS_GetProperty($parentID, "active")) {
        $uintUUID = $this->ReadPropertyString("uintUUID");
        $key      = strtoupper($key);

        if (!in_array($key, explode(",", classConstant::WRITE_KEY_VALUES))) {
          return false;
        }

        $waitResult = @IPS_GetProperty($parentID, "waitResult");
        $itemClass  = $this->ReadPropertyInteger("itemClass");
        $classType  = $this->ReadPropertyInteger("classType");

        $classLight  = ($itemClass == classConstant::CLASS_LIGHTIFY_LIGHT) ? true : false;
        $classPlug   = ($itemClass == classConstant::CLASS_LIGHTIFY_PLUG) ? true : false;
        $classMotion = ($itemClass == classConstant::CLASS_LIGHTIFY_SENSOR) ? true : false;
        $classGroup  = ($itemClass == classConstant::CLASS_LIGHTIFY_GROUP) ? true : false;
        $classScene  = ($itemClass == classConstant::CLASS_LIGHTIFY_SCENE) ? true : false;

        $deviceRGB  = ($classType & 8) ? true: false;
        $deviceCCT  = ($classType & 2) ? true: false;
        $deviceCLR  = ($classType & 4) ? true: false;

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
            $this->fade = classConstant::TIME_MIN;
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
                    $hsv = $this->lightifyBase->HEX2HSV(classConstant::COLOR_MIN);

                    $args = [
                      'hueID'        => $hueID,
                      'colorID'      => $colorID,
                      'saturationID' => $saturationID,
                      'UUID'         => utf8_encode($uintUUID),
                      'flag'         => $flag,
                      'color'        => $value,
                      'hex'          => classConstant::COLOR_MIN,
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
                    'temperature'   => classConstant::CTEMP_CCT_MIN,
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

              if ($classLight) {
                //Set Soft Mode on
                $args = [
                  'UUID' => utf8_encode($uintUUID),
                  'flag' => chr(0x00),
                  'mode' => classConstant::SET_SOFT_ON,
                  'time' => classConstant::TIME_MIN
                ];
                $this->sendData(classConstant::SET_SOFT_TIME, $args);

                //Set Soft Mode off
                $args = [
                  'UUID' => utf8_encode($uintUUID),
                  'flag' => chr(0x00),
                  'mode' => classConstant::SET_SOFT_OFF,
                  'time' => classConstant::TIME_MIN
                ];
                $this->sendData(classConstant::SET_SOFT_TIME, $args);

                //Reset transition time
                $this->SetBuffer("applyMode", 0);
                IPS_SetProperty($this->InstanceID, "transition", classConstant::TIME_MIN);
                IPS_ApplyChanges($this->InstanceID);
              }
              return true;
            }
            return false;

          case "SOFT_ON":
            $mode = classConstant::SET_SOFT_ON;

          case "SOFT_OFF":
            if (!isset($mode)) $mode = classConstant::SET_SOFT_OFF;

          case "TRANSITION":
            if ($classLight) {
              if (!isset($mode)) {
                if ($this->ReadPropertyInteger("transition") != $value) {
                  $this->SetBuffer("applyMode", 0);
                  IPS_SetProperty($this->InstanceID, "transition", $value);
                  IPS_ApplyChanges($this->InstanceID);
                }
                return true;
              } else {
                $args = [
                  'UUID' => utf8_encode($uintUUID),
                  'flag' => chr(0x00),
                  'mode' => $mode,
                  'time' => $value
                ];
                $this->sendData(classConstant::SET_SOFT_TIME, $args);
              }
              return true;
            }
            return false;

          case "RELAX":
            $temperature = classConstant::SCENE_RELAX;

          case "ACTIVE":
            if (($classLight && $online) || $classGroup) {
              $temperature = (isset($temperature)) ? $temperature : classConstant::SCENE_ACTIVE;

              if ($value == 1) {
                  //if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $temperature))) {
                    return true;
                  //}
              }
            }
            return false;

          case "PLANT_LIGHT":
            if (($classLight && $online) || $classGroup) {
              if ($value == 1) {
                  //if (false !== ($result = $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::SCENE_PLANT_LIGHT)))) {
                    return true;
                  //}
              }
            }
            return false;

          case "LIGHTIFY_LOOP":
            if (($deviceRGB && $online) || $classGroup) {
              if ($value == 0 || $value == 1) {
                  //if (false !== ($result = $lightifyConnect->sceneLightifyLoop($uintUUID, $flag, $value, 3268))){
                    return true;
                  //}
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


  public function WriteValueEx(string $key, int $value, int $transition) : bool {

    $this->fade = $transition;
    return $this->WriteValue($key, $value);

  }


  public function ReadValue(string $key)
  {

    $key = strtoupper($key);
    $key = ($key == "BRIGHTNESS") ? "LEVEL" : $key;

    return $this->GetValue($key);

  }


  public function SetState(bool $state) : bool {

    return $this->WriteValue("STATE", (int)$state);

  }


  public function WriteName(string $name) : bool {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      if (IPS_GetProperty($parentID, "active")) {
        $itemClass = $this->ReadPropertyInteger("itemClass");
        $name      = substr(trim($name), 0, classConstant::DATA_NAME_LENGTH);

        if ($itemClass == classConstant::CLASS_LIGHTIFY_LIGHT || $itemClass == classConstant::CLASS_LIGHTIFY_PLUG || $itemClass == classConstant::CLASS_LIGHTIFY_SENSOR) {
          $flag     = chr(0x00);
          $command  = classConstant::SET_DEVICE_NAME;
          $uintUUID = $this->ReadPropertyString("uintUUID");
        }

        if ($itemClass == classConstant::CLASS_LIGHTIFY_GROUP) {
          $flag = chr(0x02);
          $command  = classConstant::SET_GROUP_NAME;
          $uintUUID = chr(hexdec($this->ReadPropertyInteger("groupID"))).chr(0x00);
        }

        //Forward data to splitter
        $args = [
          'id'   => $this->InstanceID,
          'UUID' => utf8_encode($uintUUID),
          'flag' => $flag,
          'name' => $name
        ];

        $this->sendData($command, $args);
        $waitResult = @IPS_GetProperty($parentID, "waitResult");

        if (!$waitResult) {
          if (IPS_GetName($this->InstanceID) != $name) {
            IPS_SetName($this->InstanceID, (string)$name);
          }
        }
        return true;
      }
    }

    return false;

  }


}