<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';
require_once __DIR__.'/../libs/lightifyConnect.php';


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

  protected $classType;
  protected $transition  = false;

  protected $classGroup  = false;
  protected $classScene  = false;
  protected $classDummy  = false;

  protected $deviceRGB   = false;
  protected $deviceCCT   = false;
  protected $deviceCLR   = false;

  protected $classLight  = false;
  protected $classOnOff  = false;
  protected $classMotion = false;
  protected $classDevice = false;

  use ParentInstance,
      InstanceHelper;


  public function __construct($InstanceID)
  {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  private function sendData($method, $data = null)
  {

    switch ($method) {
      case classConstant::METHOD_LOAD_CLOUD:
        switch (true) {
          case $this->classLight:
          case $this->itemOnOff:
            $ressource = RESSOURCE_DEVICE.self::BODY_CMD_SET.$this->ReadPropertyInteger("deviceID");
            break;

          case $this->classGroup:
            $ressource = RESSOURCE_GROUP.self::BODY_CMD_SET.$this->ReadPropertyInteger("groupID");
            break;
        }

        $buffer = $ressource.self::BODY_CMD_TIME.$data;
        break;

      case classConstant::METHOD_STATE_DEVICE:
        $buffer = (string)$data.":".utf8_encode(substr($this->GetBuffer("groupDevice"), 1));
        break;

      case classConstant::METHOD_STATE_GROUP:
        $buffer = (string)$data.":".utf8_encode($this->ReadPropertyString("uintUUID"));
        break;

      case classConstant::METHOD_ALL_DEVICES:
        $buffer = (string)$data;
        break;
    }
    //IPS_LogMessage("SymconOSR", "<Lightify|sendData:buffer>   ".json_encode($buffer));

    $this->SendDataToParent(json_encode(array(
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $method,
      'buffer' => $buffer))
    );

  }


  private function setEnvironment()
  {

    $this->name      = IPS_GetName($this->InstanceID);
    $this->classType = $this->ReadPropertyInteger("classType");

    if ($this->classType == classConstant::TYPE_DEVICE_GROUP) {
       $this->classGroup = true;
     }

    if ($this->classType == classConstant::TYPE_GROUP_SCENE) {
       $this->classScene = true;
     }

    if (!$this->classGroup && !$this->classScene) {
      if ($this->classType & 8) {
         $this->deviceRGB = true;
       }

      if ($this->classType & 2) {
         $this->deviceCCT = true;
       }

      if ($this->classType & 4) {
         $this->deviceCLR = true;
       }

      if ($this->deviceRGB || $this->deviceCCT || $this->deviceCLR) {
         $this->classLight = true;
       }

      if ($this->classType == classConstant::TYPE_FIXED_WHITE || $this->classType == classConstant::TYPE_PLUG_ONOFF) {
         $this->itemOnOff = true;
       }

      if ($this->classType == classConstant::TYPE_SENSOR_MOTION) {
         $this->classMotion = true;
       }

      if ($this->classLight || $this->itemOnOff || $this->classMotion) {
         $this->classDevice = true;
       }
    }

  }


  private function localConnect($parentID, $debug, $message)
  {

    $gatewayIP = IPS_GetProperty($parentID, "gatewayIP");

    try { 
      $lightifyConnect = new lightifyConnect($parentID, $gatewayIP, $debug, $message);
    } catch (Exception $ex) {
      $error = $ex->getMessage();

      $this->SendDebug("<Lightify|localConnect:socket>", $error, 0);
      IPS_LogMessage("SymconOSR", "<Lightify|localConnect:socket>   ".$error);

      return false;
    }

    return $lightifyConnect;

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
      $this->setEnvironment();

      $open   = IPS_GetProperty($parentID, "open");
      $reload = IPS_GetProperty($parentID, "reloadLocal");

      $debug   = IPS_GetProperty($parentID, "debug");
      $message = IPS_GetProperty($parentID, "message");

      if ($open) {
        if ($lightifyConnect = $this->localConnect($parentID, $debug, $message)) {
          $key = strtoupper($key);
          $uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");

          if (in_array($key, explode(",", classConstant::WRITE_KEY_VALUES)) === false) {
            if ($debug % 2 || $message) {
              $error = "usage: [".$this->InstanceID."|".$this->name."] {key} not valid!";

              if ($debug % 2) {
                IPS_SendDebug($parentID, "<Lightify|WriteValue:error>", $error, 0);
              }

              if ($message) {
                IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:error>   ".$error);
              }
            }

            return false;
          }

          if ($this->classDevice) {
            $flag     = chr(0x00);
            $onlineID = @$this->GetIDForIdent("ONLINE");
            $online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
          }

          if ($this->classGroup || $this->classScene) {
            $flag     = chr(0x02);
            $uintUUID = str_pad(substr($uintUUID, 0, 1), classConstant::UUID_OSRAM_LENGTH, chr(0x00), STR_PAD_RIGHT);
            $online   = false;

            if ($this->classGroup) {
              $this->transition = classConstant::TRANSITION_DEFAULT;
            }
          }

          if ($this->classDevice || $this->classGroup) {
            $stateID = @$this->GetIDForIdent("STATE");
            $state   = ($stateID) ? GetValueBoolean($stateID) : false;

            if ($this->classLight) {
              if (!$this->transition) {
                //$this->transition = IPS_GetProperty($this->InstanceID, "transition")*10;
                //$this->transition = $this->ReadPropertyFloat("transition")*10;
              }
            }
          }

          switch($key) {
            case "ALL_DEVICES":
              if ($value == 0  || $value == 1) {
                if (false !== ($result = $lightifyConnect->setAllDevices($value))) {
                  $stateID = @$this->GetIDForIdent("ALL_DEVICES");

                  if ($stateID) {
                    SetValue($stateID, $value);
                  }
/*
                  if ($reload) {
                    $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  } else {
                    $this->sendData(classConstant::METHOD_ALL_DEVICES, $value);
                  }
*/
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be '1|0'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|WriteValue|all>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|WriteValue|all>   ".$info);
                  }
                }
              }
              return false;

            case "SAVE":
              if ($this->classLight) {
                if ($value == 1) {
                  $result = $lightifyConnect->saveLightState($uintUUID);

                  if ($result !== false) {
                    return true;
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be '1'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:save>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:save>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "SCENE":
              if ($this->classScene) {
                if (is_int($value)) {
                  if (false !== ($result = $lightifyConnect->activateGroupScene($value))) {
                    if ($reload) {
                      $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                    }

                    return true;
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: [".$this->InstanceID."|".$this->name."] {sceneID} musst be numeric";
  
                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:scene>", $info, 0);
                    }
  
                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:scene>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "DEFAULT":
              /*
              if (($this->classLight && $online) || $this->classGroup) {
                if ($value == 1) {
                  if ($this->setStateOn($state)) {
                    //Reset light to default values
                    $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::COLOR_DEFAULT));
                    $lightifyConnect->setColorTemperature($uintUUID, $flag, classConstant::CTEMP_DEFAULT);
                    $lightifyConnect->setBrightness($uintUUID, $flag, classConstant::INTENSITY_MAX);

                    if ($this->classLight) {
                      $lightifyConnect->setSoftTime($uintUUID, classCommand::SET_LIGHT_SOFT_ON, classConstant::TRANSITION_DEFAULT);
                      $lightifyConnect->setSoftTime($uintUUID, classCommand::SET_LIGHT_SOFT_OFF, classConstant::TRANSITION_DEFAULT);
                      IPS_SetProperty($this->InstanceID, "transition", classConstant::TRANSITION_DEFAULT/10);

                      if (IPS_HasChanges($this->InstanceID)) {
                        IPS_ApplyChanges($this->InstanceID);
                      }
                    }
                  }

                  return true;
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be '1'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:default>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:default>   ".$info);
                    }
                  }
                }
              } */
              return false;

            case "SOFT_ON":
              $command = classCommand::SET_LIGHT_SOFT_ON;
              //fall-trough

            case "SOFT_OFF":
              $command = (!isset($command)) ? classCommand::SET_LIGHT_SOFT_OFF : $command;
              //fall-trough

            case "TRANSITION":
              if ($this->classLight) {
                $value = ($value) ? $this->getValueRange("TRANSITION_TIME", $value) : classConstant::TRANSITION_DEFAULT/10;

                if (isset($command) == false) {
                  /*
                  if ($this->ReadPropertyFloat("transition") != $value) {
                    IPS_SetProperty($this->InstanceID, "transition", $value);
                    IPS_ApplyChanges($this->InstanceID);
                  } */

                  return true;
                } else {
                  $result = $lightifyConnect->setSoftTime($uintUUID, $command, $value*10);

                  if ($result !== false) {
                    return true;
                  }
                }
              }
              return false;

            case "RELAX":
              $temperature = classConstant::SCENE_RELAX;
              //fall-trough

            case "ACTIVE":
              if (($this->classLight && $online) || $this->classGroup) {
                $temperature = (isset($temperature)) ? $temperature : classConstant::SCENE_ACTIVE;

                if ($value == 1) {
                  if ($this->setStateOn($state)) {
                    if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $temperature))) {
/*
                      if ($reload) {
                        $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                      }
*/
                      return true;
                    }
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be '1'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:active>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:active>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "PLANT_LIGHT":
              if (($this->classLight && $online) || $this->classGroup) {
                if ($value == 1) {
                  if ($this->setStateOn($state)) {
                    if (false !== ($result = $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::SCENE_PLANT_LIGHT)))) {
/*
                      if ($reload) {
                        $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                      }
*/
                      return true;
                    }
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be '1'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:plant>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:plant>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "LIGHTIFY_LOOP":
              if (($this->deviceRGB && $online) || $this->classGroup) {
                if ($value == 0 || $value == 1) {
                  if ($this->setStateOn($state)) {
                    if (false !== ($result = $lightifyConnect->sceneLightifyLoop($uintUUID, $flag, $value, 3268))){
                      return true;
                    }
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be '1|0'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:loop>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:loop>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "STATE":
              if (($this->classDevice && $online) || $this->classGroup) {
                if ($value == 0 || $value == 1) {
                  if (false !== ($result = $lightifyConnect->setState($uintUUID, $flag, $value))) {
                    if ($stateID) {
                      SetValue($stateID, $value);
                    }
/*
                    if ($reload) {
                      $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                    } else {
                      if ($this->classDevice) {
                        $this->sendData(classConstant::METHOD_STATE_GROUP, $value);
                      } else {
                        $this->sendData(classConstant::METHOD_STATE_DEVICE, $value);
                      }
                    }
*/
                    return true;
                  }
                } else {
                  if ($debug % 2 || $message) {
                    $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be '1|0'";

                    if ($debug % 2) {
                      IPS_SendDebug($parentID, "<Lightify|WriteValue:state>", $info, 0);
                    }

                    if ($message) {
                      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:state>   ".$info);
                    }
                  }
                }
              }
              return false;

            case "COLOR":
              if (($this->deviceRGB && $online) || $this->classGroup) {
                if ($this->deviceRGB) {
                  $this->setStateOn($state);
                }

                $hueID        = @$this->GetIDForIdent("HUE");
                $hue          = ($hueID) ? GetValueInteger($hueID) : $hue;
                $colorID      = @$this->GetIDForIdent("COLOR");
                $color        = ($colorID) ? GetValueInteger($colorID) : $color;
                $saturationID = @$this->GetIDForIdent("SATURATION");
                $value        = $this->getValueRange($key, $value);

                if ($value != $color) {
                  $hex = str_pad(dechex($value), 6, "0", STR_PAD_LEFT);
                  $hsv = $this->lightifyBase->HEX2HSV($hex);
                  $rgb = $this->lightifyBase->HEX2RGB($hex);

                  if (false !== ($result = $lightifyConnect->setColor($uintUUID, $flag, $rgb, $this->transition))) {
                    if ($hueID && GetValue($hueID) != $hsv['h']) {
                      SetValue($hueID, $hsv['h']);
                    }

                    if ($saturationID && GetValue($saturationID) != $hsv['s']) {
                      SetValue($saturationID, $hsv['s']);
                    }

                    if ($colorID) {
                      SetValue($colorID, $value);
                    }
/*
                    if ($reload) {
                      $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                    }
*/
                    return true;
                  }
                }
              }
              return false;

            case "COLOR_TEMPERATURE":
              if (($this->deviceCCT && $online) || $this->classGroup) {
                if ($this->deviceCCT) {
                  $this->setStateOn($state);
                }

                $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");
                $temperature   = ($temperatureID) ? GetValueInteger($temperatureID) : $temperature;
                $value         = $this->getValueRange($key, $value);

                if ($value != $temperature) {
                  if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $value, $this->transition))) {
                    if ($temperatureID) {
                      SetValue($temperatureID, $value);
                    }
/*
                    if ($reload) {
                      $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                    }
*/
                    return true;
                  }
                }
              }
              return false;

            case "BRIGHTNESS":
            case "LEVEL":
              if (($this->classLight && $online) || $this->classGroup) {
                if ($this->classLight) {
                  $this->setStateOn($state);
                }

                $brightnessID = @$this->GetIDForIdent("BRIGHTNESS");
                $brightness   = ($brightnessID) ? GetValueInteger($brightnessID) : $brightness;
                $value        = $this->getValueRange($key, $value);

                if ($value != $brightness) {
                  if ($value == 0) {
                    $this->setStateOff($state);
                    return true;
                  } else {
                    if (false !== ($result = $lightifyConnect->setBrightness($uintUUID, $flag, $value, $this->transition))) {
                      if ($brightnessID) {
                        SetValue($brightnessID, $value);
                      }
/*
                      if ($reload) {
                        $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                      }
*/
                      return true;
                    }
                  }
                }
              }
              return false;

            case "SATURATION":
              if (($this->deviceRGB && $online) || $this->classGroup) {
                if ($this->deviceRGB) {
                  $this->setStateOn($state);
                }

                $hueID        = @$this->GetIDForIdent("HUE");
                $hue          = ($hueID) ? GetValueInteger($hueID) : $hue;
                $colorID      = @$this->GetIDForIdent("COLOR");
                $color        = ($colorID) ? GetValueInteger($colorID) : $color;
                $saturationID = @$this->GetIDForIdent("SATURATION");
                $saturation   = ($saturationID) ? GetValueInteger($saturationID) : $saturation;
                $value        = $this->getValueRange($key, $value);

                if ($value != $saturation) {
                  $hex   = $this->lightifyBase->HSV2HEX($hue, $value, 100);
                  $rgb   = $this->lightifyBase->HEX2RGB($hex);
                  $color = hexdec($hex);

                  if (false !== ($result = $lightifyConnect->setSaturation($uintUUID, $flag, $rgb, $this->transition))) {
                    if ($this->deviceRGB && $colorID && GetValue($colorID) != $color) {
                      SetValue($colorID, $color);
                    }

                    if ($this->classLight && $saturationID) {
                      SetValue($saturationID, $value);
                    }
/*
                    if ($reload) {
                      $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                    }
*/
                    return true;
                  }
                }
              }
              return false;
          }

          return true;
        }
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

    $this->transition = $this->getValueRange("TRANSITION_TIME", $transition);
    return $this->SetValue($key, $value);

  }


  public function WriteName(string $name)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $this->setEnvironment();

      if (IPS_GetProperty($parentID, "open")) {
        if ($lightifyConnect = $this->localConnect($parentID, $debug, $message)) {
          $name = substr(trim($value), 0, classConstant::DATA_NAME_LENGTH);

          if ($this->classDevice) {
            $flag     = chr(0x00);
            $command  = classCommand::SET_DEVICE_NAME;
            $uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");
          }

          if ($this->classGroup) {
            $flag = chr(0x02);
            $command  = classCommand::SET_GROUP_NAME;
            $uintUUID = chr(hexdec(@$this->GetIDForIdent("groupID"))).chr(0x00);
          }

          if (false !== ($result = $lightifyConnect->setName($uintUUID, $command, $flag, $name))) {
            if (@IPS_GetName($this->InstanceID) != $name) {
              IPS_SetName($this->InstanceID, (string)$name);
            }

            return true;
          }
        }
      }
    }

    return false;

  }


  private function setStateOn($state)
  {

    if (!$state) {
      $state = $this->WriteValue("STATE", 1);
    }

    return $state;
  }


  private function setStateOff($state)
  {

    if ($state) {
      $state = $this->WriteValue("STATE", 0);
    }

    return $state;

  }


  private function getValueRange($key, $value)
  {

    switch ($key) {
      case "COLOR":
        $minColor = hexdec(classConstant::COLOR_MIN);
        $maxColor = hexdec(classConstant::COLOR_MAX);

        if ($value < $minColor) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".classConstant::COLOR_MIN;
          $value = $minColor;
        } elseif ($value > $maxColor) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".classConstant::COLOR_MIN;
          $value = $maxColor;
        }
        break;

      case "COLOR_TEMPERATURE":
        $minTemperature = ($this->classType == classConstant::TYPE_LIGHT_EXT_COLOR) ? classConstant::CTEMP_COLOR_MIN : classConstant::CTEMP_CCT_MIN;
        $maxTemperature = ($this->classType == classConstant::TYPE_LIGHT_EXT_COLOR) ? classConstant::CTEMP_COLOR_MAX : classConstant::CTEMP_CCT_MAX;

        if ($value < $minTemperature) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$minTemperature."K";
          $value = $minTemperature;
        } elseif ($value > $maxTemperature) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$maxTemperature."K";
          $value = $maxTemperature;
        }
        break;

      case "BRIGHTNESS":
      case "LEVEL":
        if ($value < classConstant::INTENSITY_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Brightness {".$value."%} out of range. Setting to ".classConstant::INTENSITY_MIN."%";
          $value = classConstant::INTENSITY_MIN;
        } elseif ($value > classConstant::INTENSITY_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Brightness {".$value."%} out of range. Setting to ".classConstant::BRIGHTNESS_MAX."%";
          $value = classConstant::INTENSITY_MAX;
        }
        break;

      case "SATURATION":
        if ($value < classConstant::INTENSITY_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".classConstant::INTENSITY_MIN."%";
          $value = classConstant::INTENSITY_MIN;
        } elseif ($value > classConstant::INTENSITY_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".classConstant::INTENSITY_MAX."%";
          $value = classConstant::INTENSITY_MAX;
        }
        break;

      case "TRANSITION_TIME":
        $minTransition = classConstant::TRANSITION_MIN;
        $maxTransition = classConstant::TRANSITION_MAX;

        if ($value < ($minTransition /= 10)) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$minTransition.".0 sec";
          $value = $minTransition/10;
        } elseif ($value > ($maxTransition /= 10)) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$maxTransition.".0 sec";
          $value = $maxTransition/10;
        }
        break;

      case "LOOP_SPEEED":
        if ($value < classConstant::COLOR_SPEED_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".classConstant::COLOR_SPEED_MIN." ms";
          $value = classConstant::COLOR_SPEED_MIN;
        } elseif ($value > classConstant::COLOR_SPEED_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".classConstant::COLOR_SPEED_MAX." ms";
          $value = classConstant::COLOR_SPEED_MAX;
        }
    }

    if (isset($info)) {
      IPS_LogMessage("SymconOSR", "<Lightify|WriteValue:info>   ".$info);
    }

    return $value;

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
      $itemClass = $this->ReadPropertyInteger("itemClass");

      $open    = IPS_GetProperty($parentID, "open");
      $debug   = IPS_GetProperty($parentID, "debug");
      $message = IPS_GetProperty($parentID, "message");

      if ($open) {
        if ($lightifyConnect = $this->localConnect($parentID, $debug, $message)) {
          if ($itemClass == CLASS_LIGHTIFY_LIGHT || $itemClass == CLASS_LIGHTIFY_PLUG || $itemClass == CLASS_LIGHTIFY_SENSOR) {
            $onlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
            $online   = GetValueBoolean($onlineID);

            if ($online) {
              $uintUUID   = $this->lightifyBase->UUIDtoChr($this->ReadPropertyString("UUID"));
              $buffer = $lightifyConnect->setDeviceInfo($uintUUID);

              if (is_array($list = $buffer) && in_array($key, $list)) {
                return $list[$key];
              }
            }
          }
        }
      }
    }

    return false;

  }


}