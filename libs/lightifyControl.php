<?

require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyClass.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."lightifyConnect.php");


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


abstract class lightifyControl extends IPSModule {

  private $lightifyBase;
  private $lightifyConnect;

  private $moduleID;
  private $parentID;

  private $connect;
  private $direct;

  private $itemType;
  private $transition = false;

  private $itemGroup  = false;
  private $itemScene  = false;
  private $itemDummy  = false;

  private $deviceRGB  = false;
  private $deviceCCT  = false;
  private $deviceCLR  = false;

  private $itemLight  = false;
  private $itemOnOff  = false;
  private $itemMotion = false;
  private $itemDevice = false;

  private $debug      = false;
  private $message    = false;


  public function __construct($InstanceID) {
    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

    $connection     = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
    $this->parentID = ($connection) ? $connection : false;
  }


  private function sendData($method, $data = null) {
    $buffer = $data;

    switch ($method) {
      case stdConstant::METHOD_LOAD_CLOUD:
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
      'DataID' => stdConstant::TX_GATEWAY,
      'method' => $method,
      'buffer' => $buffer))
    );
  }


  private function getParentConfig() {
    $this->connect = IPS_GetProperty($this->parentID, "connectMode");
    $this->debug   = IPS_GetProperty($this->parentID, "debug");
    $this->message = IPS_GetProperty($this->parentID, "message");
  }


  private function setEnvironment() {
    $this->moduleID = IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'];
    $this->name     = IPS_GetName($this->InstanceID);
    $this->itemType = $this->ReadPropertyInteger("itemType");

    if ($this->itemType == stdConstant::TYPE_DEVICE_GROUP) {
       $this->itemGroup = true;
     }

    if ($this->itemType == stdConstant::TYPE_GROUP_SCENE) {
       $this->itemScene = true;
     }

    if ($this->itemGroup === false && $this->itemScene === false) {
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

      if ($this->itemType == stdConstant::TYPE_FIXED_WHITE || $this->itemType == stdConstant::TYPE_PLUG_ONOFF) {
         $this->itemOnOff = true;
       }

      if ($this->itemType == stdConstant::TYPE_SENSOR_MOTION) {
         $this->itemMotion = true;
       }

      if ($this->itemLight || $this->itemOnOff || $this->itemMotion) {
         $this->itemDevice = true;
       }
    }
  }


  private function localConnect() {
    $gatewayIP = IPS_GetProperty($this->parentID, "gatewayIP");
    $timeOut   = IPS_GetProperty($this->parentID, "timeOut");

    if ($timeOut > 0) {
      $connect = Sys_Ping($gatewayIP, $timeOut);
    } else {
      $connect = true;
    }

    if ($connect) {
      try { 
        $this->lightifyConnect = new lightifyConnect($this->parentID, $gatewayIP, $this->debug, $this->message);
      } catch (Exception $ex) {
        $error = $ex->getMessage();

        $this->SendDebug("<GATEWAY|LOCALCONNECT|SOCKET>", $error, 0);
        IPS_LogMessage("SymconOSR", "<GATEWAY|LOCALCONNECT|SOCKET>   ".$error);

        return false;
      }
      return $lightifySocket;
    } else {
      IPS_LogMessage("SymconOSR", "<GATEWAY|LOCALCONNECT>   "."Lightify gateway not online!");
      return false;
    }
  }


  public function RequestAction($Ident, $Value) {
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

      case "LEVEL":
        //fall-through

      case "SATURATION":
        return $this->SetValue($Ident, $Value);
    }
  }


  public function SetValue(string $key, integer $value) {
    $this->getParentConfig();
    $this->setEnvironment();

    if ($lightifySocket = $this->localConnect()) {
      $key = strtoupper($key);

      if (in_array($key, explode(",", stdConstant::LIST_KEY_VALUES)) == false) {
        if ($this->debug % 2 || $this->message) {
          $error = "usage: [".$this->InstanceID."|".$this->name."] {key} not valid!";

          if ($this->debug % 2) {
            IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|ERROR>", $error, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|ERROR>   ".$error);
          }

          return false;
        }
      }

      if ($this->lightifyConnect) {
        $uintUUID = @IPS_GetProperty($this->InstanceID, "uintUUID");
        $online   = false;

        if ($this->itemDevice) {
          $flag     = chr(0x00);
          $onlineID = @$this->GetIDForIdent("ONLINE");
          $online   = ($onlineID) ? GetValueBoolean($onlineID) : false;
        }

        if ($this->itemGroup || $this->itemScene) {
          $flag     = chr(0x02);
          $uintUUID = str_pad(substr($uintUUID, 0, 1), stdConstant::UUID_OSRAM_LENGTH, chr(0x00), STR_PAD_RIGHT);

          if ($this->itemGroup) {
            $this->transition = stdConstant::TRANSITION_DEFAULT;
          }
        }

        if ($this->itemDevice || $this->itemGroup) {
          $stateID = @$this->GetIDForIdent("STATE");
          $state   = ($stateID) ? GetValueBoolean($stateID) : false;

          if ($this->itemLight) {
            if ($this->transition === false) {
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
              if (false !== ($result = $lightifySocket->setAllDevices($value))) {
                SetValue(@$this->GetIDForIdent($key), $value);
                $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                return true;
              }
            } else {
              if ($this->debug % 2 || $this->message) {
                $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true/false'";

                if ($this->debug % 2) {
                  IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|ALL>", $info, 0);
                }

                if ($this->message) {
                  IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|ALL>   ".$info);
                }
              }
            }
            return false;

          case "SAVE":
            if ($this->itemLight) {
              if ($value == 1) {
                $result = $lightifySocket->saveLightState($uintUUID);

                if ($result !== false) {
                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|SAVE>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|SAVE>   ".$info);
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
                $name = substr(trim($value), 0, stdConstant::DATA_NAME_LENGTH);

                if (false !== ($result = $lightifySocket->setName($uintUUID, $command, $flag, $name))) {
                  if (@IPS_GetName($this->InstanceID) != $name) {
                    IPS_SetName($this->InstanceID, (string)$name);
                  }

                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {name} musst be a string";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|NAME>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|NAME>   ".$info);
                  }
                }
              }
            }
            return false;

          case "SCENE":
            if ($this->itemScene) {
              if (is_int($value)) {
                if (false !== ($result = $lightifySocket->activateGroupScene($value))) {
                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {sceneID} musst be numeric";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|SCENE>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|SCENE>   ".$info);
                  }
                }
              }
            }
            return false;

          case "DEFAULT":
            if ($this->itemLight || $this->itemGroup) {
              if ($value == 1) {
                //Reset light to default values
                $lightifySocket->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(stdConstant::COLOR_DEFAULT));
                $lightifySocket->setColorTemperature($uintUUID, $flag, stdConstant::CTEMP_DEFAULT);
                $lightifySocket->setLevel($uintUUID, $flag, stdConstant::LEVEL_MAX);

                if ($this->itemLight) {
                  $lightifySocket->setSoftTime($uintUUID, stdCommand::SET_LIGHT_SOFT_ON, stdConstant::TRANSITION_DEFAULT);
                  $lightifySocket->setSoftTime($uintUUID, stdCommand::SET_LIGHT_SOFT_OFF, stdConstant::TRANSITION_DEFAULT);

                  IPS_SetProperty($this->InstanceID, "transition", stdConstant::TRANSITION_DEFAULT/10);
                  IPS_ApplyChanges($this->InstanceID);
                }
                return true;
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|DEFAULT>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|DEFAULT>   ".$info);
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
              $value = ($value) ? $this->getValueRange("TRANSITION_TIME", $value) : stdConstant::TRANSITION_DEFAULT/10;

              if (isset($command) == false) {
                if ($this->ReadPropertyFloat("transition") != $value) {
                  IPS_SetProperty($this->InstanceID, "transition", $value);
                  IPS_ApplyChanges($this->InstanceID);
                }
                return true;
              } else {
                $result = $lightifySocket->setSoftTime($uintUUID, $command, $value*10);

                if ($result !== false) {
                  return true;
                }
              }
            }
            return false;

          case "RELAX":
            $temperature = stdConstant::SCENE_RELAX;
            //fall-trough

          case "ACTIVE":
            if ($this->itemLight || $this->itemGroup) {
              $temperature = (isset($temperature)) ? $temperature : stdConstant::SCENE_ACTIVE;

              if ($value == 1) {
                if (false !== ($result = $lightifySocket->setColorTemperature($uintUUID, $flag, $temperature))) {
                  if ($this->itemLight && GetValue($this->InstanceID) != $value) {
                    SetValue($this->InstanceID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|ACTIVE>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|ACTIVE>   ".$info);
                  }
                }
              }
            }
            return false;

          case "PLANT_LIGHT":
            if ($this->itemLight || $this->itemGroup) {
              if ($value == 1) {
                if (false !== ($result = $lightifySocket->setColor($uintUUID, $flag, $this->lightifyBase->HEX2RGB(stdConstant::SCENE_PLANT_LIGHT)))) {
                  if ($this->itemLight && GetValue($this->InstanceID) != $value) {
                    SetValue($this->InstanceID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: [".$this->InstanceID."|".$this->name."] {value} musst be 'true'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|PLANT>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|PLANT>   ".$info);
                  }
                }
              }
            }
            return false;

          case "LIGHTIFY_LOOP":
            if (($this->deviceRGB || $this->itemGroup) && $state) {
              if ($value == 0 || $value == 1) {
                if (false !== ($result = $lightifySocket->sceneLightifyLoop($uintUUID, $flag, $value, 3268))){
                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|LOOP>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|LOOP>   ".$info);
                  }
                }
              }
            }
            return false;

          case "STATE":
            if (($this->itemDevice && $online) || $this->itemGroup) {
              if ($value == 0 || $value == 1) {
                if (false !== ($result = $lightifySocket->setState($uintUUID, $flag, $value))) {
                  SetValue($stateID, $value);
                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);

                  return true;
                }
              } else {
                if ($this->debug % 2 || $this->message) {
                  $info = "usage: ".$this->InstanceID."|".$this->name." [value] musst be 'true/false'";

                  if ($this->debug % 2) {
                    IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|STATE>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|STATE>   ".$info);
                  }
                }
              }
            }
            return false;

          case "COLOR":
            if ((($this->deviceRGB && $online) || $this->itemGroup) && $state) {
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

                if (false !== ($result = $lightifySocket->setColor($uintUUID, $flag, $rgb, $this->transition))) {
                  if ($hueID && GetValue($hueID) != $hsv['h']) {
                    SetValue($hueID, $hsv['h']);
                  }

                  if ($saturationID && GetValue($saturationID) != $hsv['s']) {
                    SetValue($saturationID, $hsv['s']);
                  }

                  if ($colorID) {
                    SetValue($colorID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "COLOR_TEMPERATURE":
            if ((($this->deviceCCT && $online) || $this->itemGroup) && $state) {
              $temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE");
              $temperature   = ($temperatureID) ? GetValueInteger($temperatureID) : $temperature;
              $value         = $this->getValueRange($key, $value);

              if ($value != $temperature) {
                if (false !== ($result = $lightifySocket->setColorTemperature($uintUUID, $flag, $value, $this->transition))) {
                  if ($temperatureID) {
                    SetValue($temperatureID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "LEVEL":
            if ((($this->itemLight && $online) || $this->itemGroup) && $state) {
              $levelID = @$this->GetIDForIdent("LEVEL");
              $level   = ($levelID) ? GetValueInteger($levelID) : $level;
              $value   = $this->getValueRange($key, $value);

              if ($value != $level) {
                if (false !== ($result = $lightifySocket->setLevel($uintUUID, $flag, $value, $this->transition))) {
                  if ($levelID) {
                    SetValue($levelID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;

          case "SATURATION":
            if ((($this->deviceRGB && $online) || $this->itemGroup) && $state) {
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

                if (false !== ($result = $lightifySocket->setSaturation($uintUUID, $flag, $rgb, $this->transition))) {
                  if ($this->deviceRGB && $colorID && GetValue($colorID) != $color) {
                    SetValue($colorID, $color);
                  }

                  if ($this->itemLight && $saturationID) {
                    SetValue($saturationID, $value);
                  }

                  $this->sendData(stdConstant::METHOD_LOAD_LOCAL);
                  return true;
                }
              }
            }
            return false;
        }
      }

      return true;
    }

    return false;
  }


  public function SetValueEx(string $key, integer $value, integer $transition) {
    $this->transition = $this->getValueRange("TRANSITION_TIME", $transition);
    return $this->SetValue($key, $value);
  }


  private function getValueRange($key, $value) {
    switch ($key) {
      case "COLOR":
        $minColor = hexdec(stdConstant::COLOR_MIN);
        $maxColor = hexdec(stdConstant::COLOR_MAX);

        if ($value < $minColor) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".stdConstant::COLOR_MIN;
          $value = $minColor;
        } elseif ($value > $maxColor) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color {#".dechex($value)."} out of range. Setting to #".stdConstant::COLOR_MIN;
          $value = $maxColor;
        }
        break;

      case "COLOR_TEMPERATURE":
        $minTemperature = ($this->itemType == stdConstant::TYPE_LIGHT_EXT_COLOR) ? stdConstant::CTEMP_COLOR_MIN : stdConstant::CTEMP_CCT_MIN;
        $maxTemperature = ($this->itemType == stdConstant::TYPE_LIGHT_EXT_COLOR) ? stdConstant::CTEMP_COLOR_MAX : stdConstant::CTEMP_CCT_MAX;

        if ($value < $minTemperature) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$minTemperature."K";
          $value = $minTemperature;
        } elseif ($value > $maxTemperature) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Color Temperature {".$value."K} out of range. Setting to ".$maxTemperature."K";
          $value = $maxTemperature;
        }
        break;

      case "LEVEL":
        if ($value < stdConstant::INTENSITY_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Level {".$value."%} out of range. Setting to ".stdConstant::INTENSITY_MIN."%";
          $value = stdConstant::INTENSITY_MIN;
        } elseif ($value > stdConstant::INTENSITY_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Level {".$value."%} out of range. Setting to ".stdConstant::INTENSITY_MAX."%";
          $value = stdConstant::INTENSITY_MAX;
        }
        break;

      case "SATURATION":
        if ($value < stdConstant::INTENSITY_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".stdConstant::INTENSITY_MIN."%";
          $value = stdConstant::INTENSITY_MIN;
        } elseif ($value > stdConstant::INTENSITY_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Saturation {".$value."%} out of range. Setting to ".stdConstant::INTENSITY_MAX."%";
          $value = stdConstant::INTENSITY_MAX;
        }
        break;

      case "TRANSITION_TIME":
        $minTransition = stdConstant::TRANSITION_MIN;
        $maxTransition = stdConstant::TRANSITION_MAX;

        if ($value < ($minTransition /= 10)) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$minTransition.".0 sec";
          $value = $minTransition/10;
        } elseif ($value > ($maxTransition /= 10)) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Transition time {".$value." sec} out of range. Setting to ".$maxTransition.".0 sec";
          $value = $maxTransition/10;
        }
        break;

      case "LOOP_SPEEED":
        if ($value < stdConstant::COLOR_SPEED_MIN) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".stdConstant::COLOR_SPEED_MIN." ms";
          $value = stdConstant::COLOR_SPEED_MIN;
        } elseif ($value > stdConstant::COLOR_SPEED_MAX) {
          $info = "usage: [".$this->InstanceID."|".$this->name."] Loop speed {".$value." ms} out of range. Setting to ".stdConstant::COLOR_SPEED_MAX." ms";
          $value = stdConstant::COLOR_SPEED_MAX;
        }
    }

    if (($this->debug % 2 || $this->message) && isset($info)) {
      if ($this->debug % 2) {
         IPS_SendDebug($this->parentID, "<LIGHTIFY|SETVALUE|INFO>", $info, 0);
       }

      if ($this->message) {
         IPS_LogMessage("SymconOSR", "<LIGHTIFY|SETVALUE|INFO>   ".$info);
       }
    }

    return $value;
  }


  public function GetValue(string $key) {
    if ($objectID = @IPS_GetObjectIDByIdent($key, $this->InstanceID)) {
      return GetValue($objectID);
    }

    return false;
  }


  public function GetValueEx(string $key) {
    $this->getParentConfig();
    $this->setEnvironment();

    if ($lightifySocket = $this->localConnect()) {
      if ($this->itemDevice) {
        $onlineID = IPS_GetObjectIDByIdent('ONLINE', $this->InstanceID);
        $online   = GetValueBoolean($onlineID);

        if ($online) {
          $uintUUID   = $this->lightifyBase->UUIDtoChr($this->ReadPropertyString("UUID"));
          $buffer = $lightifySocket->setDeviceInfo($uintUUID);

          if (is_array($list = $buffer) && in_array($key, $list)) {
            return $list[$key];
          }
        }
      }
    }

    return false;
  }

}