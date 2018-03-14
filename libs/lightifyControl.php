<?php

require_once __DIR__.'/../libs/baseModule.php';
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

  protected $itemType;
  protected $transition = false;

  protected $itemGroup  = false;
  protected $itemScene  = false;
  protected $itemDummy  = false;

  protected $deviceRGB  = false;
  protected $deviceCCT  = false;
  protected $deviceCLR  = false;

  protected $itemLight  = false;
  protected $itemOnOff  = false;
  protected $itemMotion = false;
  protected $itemDevice = false;

  use ParentInstance;


  public function __construct($InstanceID)
  {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;
  }


  private function sendData($method, $data = null)
  {

    $buffer = $data;

    switch ($method) {
      case classConstant::METHOD_LOAD_CLOUD:
        switch (true) {
          case $this->itemLight:
            //fall through

          case $this->itemOnOff:
            $ressource = RESSOURCE_DEVICE.self::BODY_CMD_SET.$this->ReadPropertyInteger("deviceID");
            break;

          case $this->itemGroup:
            $ressource = RESSOURCE_GROUP.self::BODY_CMD_SET.$this->ReadPropertyInteger("itemID");
            break;
        }
        $buffer = $ressource.self::BODY_CMD_TIME.$data;
        break;
    }

    $this->SendDataToParent(json_encode(array(
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $method,
      'buffer' => $buffer))
    );
  }


  private function setEnvironment()
  {

    $this->name     = IPS_GetName($this->InstanceID);
    $this->itemType = $this->ReadPropertyInteger("itemType");

    if ($this->itemType == classConstant::TYPE_DEVICE_GROUP) {
       $this->itemGroup = true;
     }

    if ($this->itemType == classConstant::TYPE_GROUP_SCENE) {
       $this->itemScene = true;
     }

    if (!$this->itemGroup && !$this->itemScene) {
      if ($this->itemType & 8) {
         $this->deviceRGB = true;
       }

      if ($this->itemType & 2) {
         $this->deviceCCT = true;
       }

      if ($this->itemType & 4) {
         $this->deviceCLR = true;
       }

      if ($this->deviceRGB || $this->deviceCCT || $this->deviceCLR) {
         $this->itemLight = true;
       }

      if ($this->itemType == classConstant::TYPE_FIXED_WHITE || $this->itemType == classConstant::TYPE_PLUG_ONOFF) {
         $this->itemOnOff = true;
       }

      if ($this->itemType == classConstant::TYPE_SENSOR_MOTION) {
         $this->itemMotion = true;
       }

      if ($this->itemLight || $this->itemOnOff || $this->itemMotion) {
         $this->itemDevice = true;
       }
    }
  }


  private function localConnect($parentID, $debug, $message)
  {

    $gatewayIP = IPS_GetProperty($parentID, "gatewayIP");
    $timeOut   = IPS_GetProperty($parentID, "timeOut");

    if ($timeOut > 0) {
      $connect = Sys_Ping($gatewayIP, $timeOut);
    } else {
      $connect = true;
    }

    if ($connect) {
      try { 
        $lightifyConnect = new lightifyConnect($parentID, $gatewayIP, $debug, $message);
      } catch (Exception $ex) {
        $error = $ex->getMessage();

        $this->SendDebug("<Lightify|localConnect:socket>", $error, 0);
        IPS_LogMessage("SymconOSR", "<Lightify|localConnect:socket>   ".$error);

        return false;
      }
      return $lightifyConnect;
    } else {
      IPS_LogMessage("SymconOSR", "<Lightify|localConnect:error>   Lightify gateway not online!");
      return false;
    }
  }


  public function RequestAction($Ident, $Value)
  {

    switch ($Ident) {
      case "ALL_LIGHTS":
        //fall-through

      case "SCENE":
        $Value = $this->ReadPropertyInteger("itemID");
        //fall-through

      case "STATE":
        //fall-through

      case "COLOR":
        //fall-through

      case "COLOR_TEMPERATURE":
        //fall-through

      case "BRIGHTNESS":
      case "LEVEL":
        //fall-through

      case "SATURATION":
        return $this->SetValue($Ident, $Value);
    }
  }


  public function SetValue($key, $value)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $this->setEnvironment();

      $debug   = IPS_GetProperty($parentID, "debug");
      $message = IPS_GetProperty($parentID, "message");

      if ($lightifyConnect = $this->localConnect($parentID, $debug, $message)) {
        $key = strtoupper($key);

        if (in_array($key, explode(",", classConstant::LIST_KEY_VALUES)) === false) {
          if ($debug % 2 || $message) {
            $error = "usage: [".$this->InstanceID."|".$this->name."] {key} not valid!";

            if ($debug % 2) {
              IPS_SendDebug($parentID, "<Lightify|SetValue:error>", $error, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Lightify|SetValue:error>   ".$error);
            }

            return false;
          }
        }

        $uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");
        $online   = false;

        if ($this->itemDevice) {
          $flag     = chr(0x00);
          $onlineID = @$this->GetIDForIdent("ONLINE");
          $online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
        }

        if ($this->itemGroup || $this->itemScene) {
          $flag     = chr(0x02);
          $uintUUID = str_pad(substr($uintUUID, 0, 1), classConstant::UUID_OSRAM_LENGTH, chr(0x00), STR_PAD_RIGHT);

          if ($this->itemGroup) {
            $this->transition = classConstant::TRANSITION_DEFAULT;
          }
        }

        if ($this->itemDevice || $this->itemGroup) {
          $stateID = @$this->GetIDForIdent("STATE");
          $state   = ($stateID) ? GetValueBoolean($stateID) : false;

          if ($this->itemLight) {
            if (!$this->transition) {
              //$this->transition = IPS_GetProperty($this->InstanceID, "transition")*10;
              $this->transition = $this->ReadPropertyFloat("transition")*10;
            }
          }
        }

        switch($key) {
          case "ALL_LIGHTS":
            $stateID = @$this->GetIDForIdent("ALL_LIGHTS");
            $state   = ($stateID) ? GetValueBoolean($stateID) : false;

            if (($value == 0 && $state !== false) || $value == 1) {
              if (false !== ($result = $lightifyConnect->setAllDevices($value))) {
                SetValue(@$this->GetIDForIdent($key), $value);
                $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                return true;
              }
            } else {
              if ($debug % 2 || $message) {
                $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true/false'";

                if ($debug % 2) {
                  IPS_SendDebug($parentID, "<Lightify|SetValue|ALL>", $info, 0);
                }

                if ($message) {
                  IPS_LogMessage("SymconOSR", "<Lightify|SetValue|ALL>   ".$info);
                }
              }
            }
            return false;

          case "SAVE":
            if ($this->itemLight) {
              if ($value == 1) {
                $result = $lightifyConnect->saveLightState($uintUUID);

                if ($result !== false) {
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:save>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:save>   ".$info);
                  }
                }
              }
            }
            return false;

          case "NAME":
            if ($this->itemScene == false) {
              $command = stdCommand::SET_DEVICE_NAME;

              if ($this->itemGroup) {
                $command  = stdCommand::SET_GROUP_NAME;
                $uintUUID = chr(hexdec(@$this->GetIDForIdent("groupID"))).chr(0x00);
              }

              if (is_string($value)) {
                $name = substr(trim($value), 0, classConstant::DATA_NAME_LENGTH);

                if (false !== ($result = $lightifyConnect->setName($uintUUID, $command, $flag, $name))) {
                  if (@IPS_GetName($this->InstanceID) != $name) {
                    IPS_SetName($this->InstanceID, (string)$name);
                  }

                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {name} musst be a string";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:name>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:name>   ".$info);
                  }
                }
              }
            }
            return false;

          case "SCENE":
            if ($this->itemScene) {
              if (is_int($value)) {
                if (false !== ($result = $lightifyConnect->activateGroupScene($value))) {
                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {sceneID} musst be numeric";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:scene>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:scene>   ".$info);
                  }
                }
              }
            }
            return false;

          case "DEFAULT":
            if ($this->itemLight || $this->itemGroup) {
              if ($value == 1) {
                //Reset light to default values
                $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::COLOR_DEFAULT));
                $lightifyConnect->setColorTemperature($uintUUID, $flag, classConstant::CTEMP_DEFAULT);
                $lightifyConnect->setBrightness($uintUUID, $flag, classConstant::INTENSITY_MAX);

                if ($this->itemLight) {
                  $lightifyConnect->setSoftTime($uintUUID, stdCommand::SET_LIGHT_SOFT_ON, classConstant::TRANSITION_DEFAULT);
                  $lightifyConnect->setSoftTime($uintUUID, stdCommand::SET_LIGHT_SOFT_OFF, classConstant::TRANSITION_DEFAULT);
                  IPS_SetProperty($this->InstanceID, "transition", classConstant::TRANSITION_DEFAULT/10);

                  if (IPS_HasChanges($this->InstanceID)) {
                    IPS_ApplyChanges($this->InstanceID);
                  }
                }
                return true;
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:default>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:default>   ".$info);
                  }
                }
              }
            }
            return false;

          case "SOFT_ON":
            $command = stdCommand::SET_LIGHT_SOFT_ON;
            //fall-trough

          case "SOFT_OFF":
            $command = (!isset($command)) ? stdCommand::SET_LIGHT_SOFT_OFF : $command;
            //fall-trough

          case "TRANSITION":
            if ($this->itemLight) {
              $value = ($value) ? $this->getValueRange("TRANSITION_TIME", $value) : classConstant::TRANSITION_DEFAULT/10;

              if (isset($command) == false) {
                if ($this->ReadPropertyFloat("transition") != $value) {
                  IPS_SetProperty($this->InstanceID, "transition", $value);
                  IPS_ApplyChanges($this->InstanceID);
                }
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
            if ($this->itemLight || $this->itemGroup) {
              $temperature = (isset($temperature)) ? $temperature : classConstant::SCENE_ACTIVE;

              if ($value == 1) {
                if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $temperature))) {
                  if ($this->itemLight && GetValue($this->InstanceID) != $value) {
                    SetValue($this->InstanceID, $value);
                  }

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:active>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:active>   ".$info);
                  }
                }
              }
            }
            return false;

          case "PLANT_LIGHT":
            if ($this->itemLight || $this->itemGroup) {
              if ($value == 1) {
                if (false !== ($result = $lightifyConnect->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(classConstant::SCENE_PLANT_LIGHT)))) {
                  if ($this->itemLight && GetValue($this->InstanceID) != $value) {
                    SetValue($this->InstanceID, $value);
                  }

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:plant>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:plant>   ".$info);
                  }
                }
              }
            }
            return false;

          case "LIGHTIFY_LOOP":
            if (($this->deviceRGB || $this->itemGroup) && $state) {
              if ($value == 0 || $value == 1) {
                if (false !== ($result = $lightifyConnect->sceneLightifyLoop($uintUUID, $flag, $value, 3268))){
                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:loop>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:loop>   ".$info);
                  }
                }
              }
            }
            return false;

          case "STATE":
            if ($this->itemDevice || $this->itemGroup) {
              if ($value == 0 || $value == 1) {
                IPS_LogMessage("SymconOSR", "<Lightify|SetValue>   key: ".$key."  value: ".$value);

                if (false !== ($result = $lightifyConnect->setState($uintUUID, $flag, $value))) {
                  SetValue($stateID, $value);
                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);

                  return true;
                }
              } else {
                if ($debug % 2 || $message) {
                  $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

                  if ($debug % 2) {
                    IPS_SendDebug($parentID, "<Lightify|SetValue:state>", $info, 0);
                  }

                  if ($message) {
                    IPS_LogMessage("SymconOSR", "<Lightify|SetValue:state>   ".$info);
                  }
                }
              }
            }
            return false;

          case "COLOR":
            if (($this->deviceRGB || $this->itemGroup) && $state) {
              $hueID        = @$this->GetIDForIdent("HUE");
              $hue          = ($hueID) ? GetValueInteger($hueID) : $hue;
              $colorID      = @$this->GetIDForIdent("COLOR");
              $color        = ($colorID) ? GetValueInteger($colorID) : $color;
              $saturationID = @$this->GetIDForIdent("SATURATION");
              $value        = $this->getValueRange($key, $value);

              if ($value != $color) {
                $hex = str_pad(dechex($value), 6, 0, STR_PAD_LEFT);
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

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "COLOR_TEMPERATURE":
            if (($this->deviceCCT || $this->itemGroup) && $state) {
              $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");
              $temperature   = ($temperatureID) ? GetValueInteger($temperatureID) : $temperature;
              $value         = $this->getValueRange($key, $value);

              if ($value != $temperature) {
                if (false !== ($result = $lightifyConnect->setColorTemperature($uintUUID, $flag, $value, $this->transition))) {
                  if ($temperatureID) {
                    SetValue($temperatureID, $value);
                  }

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "BRIGHTNESS":
          case "LEVEL":
            if (($this->itemLight || $this->itemGroup) && $state) {
              $brightnessID = @$this->GetIDForIdent("BRIGHTNESS");
              $brightness   = ($brightnessID) ? GetValueInteger($brightnessID) : $brightness;
              $value        = $this->getValueRange($key, $value);

              if ($value != $brightness) {
                if (false !== ($result = $lightifyConnect->setBrightness($uintUUID, $flag, $value, $this->transition))) {
                  if ($brightnessID) {
                    SetValue($brightnessID, $value);
                  }

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "SATURATION":
            if (($this->deviceRGB || $this->itemGroup) && $state) {
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

                  if ($this->itemLight && $saturationID) {
                    SetValue($saturationID, $value);
                  }

                  $this->sendData(classConstant::METHOD_RELOAD_LOCAL);
                  return true;
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

    $this->transition = $this->getValueRange("TRANSITION_TIME", $transition);
    return $this->SetValue($key, $value);
  }


  private function getValueRange($key, $value)
  {

    IPS_LogMessage("SymconOSR", "<Lightify|getValueRange>   key: ".$key."  value: ".$value);

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
        $minTemperature = ($this->itemType == classConstant::TYPE_LIGHT_EXT_COLOR) ? classConstant::CTEMP_COLOR_MIN : classConstant::CTEMP_CCT_MIN;
        $maxTemperature = ($this->itemType == classConstant::TYPE_LIGHT_EXT_COLOR) ? classConstant::CTEMP_COLOR_MAX : classConstant::CTEMP_CCT_MAX;

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
      IPS_LogMessage("SymconOSR", "<Lightify|SetValue:info>   ".$info);
    }

    return $value;
  }


  public function GetValue($key)
  {

    if ($objectID = @IPS_GetObjectIDByIdent($key, $this->InstanceID)) {
      return GetValue($objectID);
    }

    return false;
  }


  public function GetValueEx($key)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $itemClass = $this->ReadPropertyInteger("itemClass");

      $debug   = IPS_GetProperty($parentID, "debug");
      $message = IPS_GetProperty($parentID, "message");

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

    return false;
  }

}