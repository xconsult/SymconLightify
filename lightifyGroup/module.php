<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."libs".DIRECTORY_SEPARATOR."lightifyControl.php");


define('ROW_COLOR_LIGHT_ON',   "#fffde7");
define('ROW_COLOR_CCT_ON',     "#ffecB3");
define('ROW_COLOR_PLUG_ON',    "#c5e1a5");
define('ROW_COLOR_ONLINE_OFF', "#ffffff");
define('ROW_COLOR_LIGHT_OFF',  "#e0e0e0");
define('ROW_COLOR_PLUG_OFF',   "#ef9a9a");


//class lightifyGroup extends IPSModule {
class lightifyGroup extends lightifyControl {

  const LIST_ELEMENTS_INDEX = 3;
  const ITEMID_CREATE       = 0;
  const ITEMID_MINIMUM      = 1;

  private $lightifyBase;
  private $parentID;


  public function __construct(string $InstanceID) {
    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

    $connection     = @IPS_GetInstance($this->InstanceID)['ConnectionID'];
    $this->parentID = ($connection) ? $connection : false;
  }


  public function Create() {
    parent::Create();

    $this->SetBuffer("groupDevice", stdConstant::NO_STRING);
    $this->SetBuffer("groupScene", stdConstant::NO_STRING);

    $this->RegisterPropertyInteger("itemID", self::ITEMID_CREATE);
    $this->RegisterPropertyString("UUID", stdConstant::NO_STRING);
    $this->RegisterPropertyInteger("itemClass", stdConstant::CLASS_LIGHTIFY_GROUP);
    $this->RegisterPropertyString("deviceList", stdConstant::NO_STRING);

    $this->RegisterPropertyString("uintUUID", stdConstant::NO_STRING);
    $this->RegisterPropertyInteger("itemType", stdConstant::NO_VALUE);
    $this->RegisterPropertyString("allLights", stdConstant::NO_STRING);

    $this->ConnectParent(stdConstant::MODULE_GATEWAY);
    $this->parentID = ($this->parentID === false) ? IPS_GetInstance($this->InstanceID)['ConnectionID'] : $this->parentID;
  }


  public function ApplyChanges() {
    parent::ApplyChanges();

    //Check config
    if (($itemID = $this->ReadPropertyInteger("itemID")) < self::ITEMID_MINIMUM) {
      $this->SetStatus(202);
      return false;
    }

    //Set properties
    $itemClass = $this->ReadPropertyInteger("itemClass");
    $status    = ($itemClass == stdConstant::CLASS_LIGHTIFY_GROUP) ? $this->setGroupProperty($itemID) : $this->setSceneProperty($itemID);

    if ($status == 102) {
      if (IPS_HasChanges($this->InstanceID)) {
        IPS_ApplyChanges($this->InstanceID);
      }
    }

    $this->SetStatus($status);
  }


  public function GetConfigurationForm() {
    $groupDevice = $this->GetBuffer("groupDevice");
    $itemType    = $this->ReadPropertyInteger("itemType");

    switch ($itemType) {
      case stdConstant::TYPE_DEVICE_GROUP:
        $deviceList  = (empty($groupDevice) === false && ord($groupDevice{0}) > 0) ? '
          { "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" },
          { "type": "List",  "name":  "deviceList", "caption": "Devices",
            "columns": [
              { "label": "Instance ID", "name": "InstanceID",  "width": "60px", "visible": false },
              { "label": "ID",          "name": "deviceID",    "width": "35px"  },
              { "label": "Name",        "name": "name",        "width": "120px" },
              { "label": "Hue",         "name": "hue",         "width": "35px"  },
              { "label": "Color",       "name": "color",       "width": "60px"  },
              { "label": "Temperature", "name": "temperature", "width": "80px"  },
              { "label": "Level",       "name": "level",       "width": "50px"  },
              { "label": "Saturation",  "name": "saturation",  "width": "70px"  }
            ]
        },' : stdConstant::NO_STRING;

        $formJSON = '{
          "elements": [
            { "type": "NumberSpinner",    "name": "itemID",    "caption": "Group [id]" },
            { "type": "Select",           "name": "itemClass", "caption": "Class",
              "options": [
                { "label": "Group", "value": 2006 }
              ]
            },
            '.$deviceList.'
            { "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
          ],
          "actions": [
            { "type": "Button", "label": "On",  "onClick": "OSR_SetValue($id, \"STATE\", true)"  },
            { "type": "Button", "label": "Off", "onClick": "OSR_SetValue($id, \"STATE\", false)" }
          ],
          "status": [
            { "code": 102, "icon": "active",   "caption": "Group is active"                   },
            { "code": 104, "icon": "inactive", "caption": "Group is inactive"                 },
            { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
            { "code": 202, "icon": "error",    "caption": "Invalid Group [id]"                }
          ]
        }';
        break;

      case stdConstant::TYPE_GROUP_SCENE:
        $formJSON = '{
          "elements": [
            { "type": "NumberSpinner", "name": "itemID",             "caption": "Scene [id]" },
            { "type": "Select",        "name": "itemClass",          "caption": "Class",
              "options": [
                { "label": "Scene", "value": 2007 }
              ]
            },
            { "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" }
          ],
          "actions": [
            { "type": "Button", "label": "Activate",  "onClick": "OSR_SetValue($id, \"SCENE\", 2)" }
          ],
          "status": [
            { "code": 102, "icon": "active",   "caption": "Scene is active"                   },
            { "code": 104, "icon": "inactive", "caption": "Scene is inactive"                 },
            { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
            { "code": 202, "icon": "error",    "caption": "Invalid Scene [id]"                }
          ]
        }';
        return $formJSON;

      case stdConstant::TYPE_ALL_LIGHTS:
        $formJSON = '{
        }';
        return $formJSON;

      default:
        $formJSON = '{
          "elements": [
            { "type": "NumberSpinner", "name": "itemID",    "caption": "Group/Scene [id]" },
            { "type": "Select",        "name": "itemClass", "caption": "Class",
              "options": [
                { "label": "Group", "value": 2006 },
                { "label": "Scene", "value": 2007 }
              ]
            }
          ],
          "status": [
            { "code": 102, "icon": "active",   "caption": "Group/Scene is active"             },
            { "code": 104, "icon": "inactive", "caption": "Group/Scene is inactive"           },
            { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
            { "code": 202, "icon": "error",    "caption": "Invalid Group/Scene [id]"          }
          ]
        }';
        return $formJSON;
    }

    if (empty($groupDevice) === false && ($dcount = ord($groupDevice{0})) > 0) {
      $groupDevice = substr($groupDevice, 1);
      $data        = json_decode($formJSON);

      for ($i = 1; $i <= $dcount; $i++) {
        $uintUUID = substr($groupDevice, 0, stdConstant::UUID_DEVICE_LENGTH);

        if ($instanceID = $this->lightifyBase->getObjectByProperty(stdConstant::MODULE_DEVICE, "uintUUID", $uintUUID)) {
          if (IPS_GetInstance($instanceID)['ConnectionID'] != $this->parentID) {
            continue;
          }

          $onlineID      = @IPS_GetObjectIDByIdent('ONLINE', $instanceID);
          $stateID       = @IPS_GetObjectIDByIdent('STATE', $instanceID);
          $online        = ($onlineID) ? GetValueBoolean($onlineID) : false;
          $state         = ($stateID) ? GetValueBoolean($stateID) : false;

          $deviceID      = @IPS_GetProperty($instanceID, "deviceID");
          $itemClass     = @IPS_GetProperty($instanceID, "itemClass");

          switch ($itemClass) {
            case stdConstant::CLASS_LIGHTIFY_LIGHT:
              $classInfo = "Lampe";
              break;

            case stdConstant::CLASS_LIGHTIFY_PLUG:
              $classInfo = "Steckdose";
              break;
          } 

          $hueID         = @IPS_GetObjectIDByIdent("HUE", $instanceID);
          $colorID       = @IPS_GetObjectIDByIdent("COLOR", $instanceID);
          $temperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $instanceID);
          $levelID       = @IPS_GetObjectIDByIdent("LEVEL", $instanceID);
          $saturationID  = @IPS_GetObjectIDByIdent("SATURATION", $instanceID);

          $hue           = ($hueID) ?  GetValueformatted($hueID) : stdConstant::NO_STRING;
          $color         = ($colorID) ? strtolower(GetValueformatted($colorID)) : stdConstant::NO_STRING;
          $temperature   = ($temperatureID) ? GetValueformatted($temperatureID) : stdConstant::NO_STRING;
          $level         = ($levelID) ? preg_replace('/\s+/', '', GetValueformatted($levelID)) : stdConstant::NO_STRING;
          $saturation    = ($saturationID) ? preg_replace('/\s+/', '', GetValueformatted($saturationID)) : stdConstant::NO_STRING;

          if ($state) {
            if (@IPS_GetProperty($instanceID, "itemType") == stdConstant::TYPE_PLUG_ONOFF) {
              $rowColor = ROW_COLOR_PLUG_ON;
            } else {
              $rowColor = ($temperature) ? ROW_COLOR_CCT_ON : ROW_COLOR_LIGHT_ON;
            }
          } else {
            if (@IPS_GetProperty($instanceID, "itemType") == stdConstant::TYPE_PLUG_ONOFF) {
              $rowColor = ($online) ? ROW_COLOR_ONLINE_OFF : ROW_COLOR_PLUG_OFF;
            } else {
              $rowColor = ($online) ? ROW_COLOR_ONLINE_OFF : ROW_COLOR_LIGHT_OFF;
            }
          }

          $data->elements[self::LIST_ELEMENTS_INDEX]->values[] = array(
            "InstanceID"  => $instanceID,
            "deviceID"    => $deviceID,
            "name"        => IPS_GetName($instanceID),
            "hue"         => $hue,
            "color"       => ($color != stdConstant::NO_STRING) ? "#".strtoupper($color) : stdConstant::NO_STRING,
            "temperature" => $temperature,
            "level"       => $level,
            "saturation"  => $saturation,
            "rowColor"    => $rowColor
          );
        }

        $groupDevice = substr($groupDevice, stdConstant::UUID_DEVICE_LENGTH);
      }

      return json_encode($data);
    }

    return $formJSON;
  }


  public function ReceiveData($jsonString) {
    $itemID = $this->ReadPropertyInteger("itemID");
    $data   = json_decode($jsonString);

    $localBuffer = utf8_decode($data->buffer);
    $localCount  = ord($localBuffer{0});
    $itemType    = $this->ReadPropertyInteger("itemType");

    switch ($data->mode) {
      case stdConstant::MODE_GROUP_LOCAL:
        //Store device group buffer
        if ($itemType == stdConstant::TYPE_DEVICE_GROUP) {
          $groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
          $this->SetBuffer("groupDevice", $groupDevice);

          if (empty($groupDevice) === false) {
            if ($data->debug % 2 || $data->message) {
              $info = $localCount."/".$this->lightifyBase->decodeData($groupDevice);

              if ($data->debug % 2) {
                IPS_SendDebug($this->parentID, "<GROUP|RECEIVEDATA|GROUPS:LOCAL>", $info, 0);
              }

              if ($data->message) {
                IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|GROUPS:LOCAL>   ".$info);
              }
            }

            $this->setGroupInfo($data->mode, $data->method, $groupDevice);
          }
        }
        break;

      case stdConstant::MODE_GROUP_CLOUD:
        break;

      case stdConstant::MODE_GROUP_SCENE:
        //Store group scene buffer
        if ($itemType == stdConstant::TYPE_GROUP_SCENE) {
          $groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
          $this->SetBuffer("groupScene", $groupScene);

          if (empty($groupScene) === false) {
            if ($data->debug % 2 || $data->message) {
              $info = ord($groupScene{0})."/".ord($groupScene{1})."/".$this->lightifyBase->decodeData($groupScene);

              if ($data->debug % 2) {
                IPS_SendDebug($this->parentID, "<GROUP|RECEIVEDATA|SCENES:CLOUD>", $info, 0);
              }

              if ($data->message) {
                IPS_LogMessage("SymconOSR", "<DEVICE|RECEIVEDATA|SCENES:CLOUD>   ".$info);
              }
            }

            $this->setSceneInfo($data->mode, $data->method);
          }
        }
        break;
    }
  }


  private function setGroupProperty($itemID) {
    $jsonString = $this->SendDataToParent(json_encode(array(
      'DataID' => stdConstant::TX_GATEWAY,
      'method' => stdConstant::METHOD_APPLY_CHILD,
      'mode'   => stdConstant::MODE_GROUP_LOCAL))
    );

    if ($jsonString != stdConstant::NO_STRING) {
      $localData   = json_decode($jsonString);
      $localBuffer = utf8_decode($localData->buffer);
      $localCount  = ord($localBuffer{0});

      //Store group device buffer
      $groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
      $this->SetBuffer("groupDevice", $groupDevice);

      if (empty($groupDevice) === false) {
        $itemType = ord($localBuffer{1});
        $uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

        if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
          IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);
        }

        if ($this->ReadPropertyInteger("itemType") != $itemType) {
          IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);
        }

        $this->setGroupInfo(stdConstant::MODE_GROUP_LOCAL, stdConstant::METHOD_CREATE_CHILD, $groupDevice);
        return 102;
      }

      return 104;
    }

    return 102;
  }


  private function setSceneProperty($itemID) {
    $jsonString = $this->SendDataToParent(json_encode(array(
      'DataID' => stdConstant::TX_GATEWAY,
      'method' => stdConstant::METHOD_APPLY_CHILD,
      'mode'   => stdConstant::MODE_GROUP_SCENE))
    );

    if ($jsonString != stdConstant::NO_STRING) {
      $localData   = json_decode($jsonString);
      $localBuffer = utf8_decode($localData->buffer);
      $localCount  = ord($localBuffer{0});

      //Store group scene buffer
      $groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
      $this->SetBuffer("groupScene", $groupScene);

      if (empty($groupScene) === false) {
        $itemType = ord($localBuffer{1});
        $uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

        if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
          IPS_SetProperty($this->InstanceID, "uintUUID", (string)$uintUUID);
        }

        if ($this->ReadPropertyInteger("itemType") != $itemType) {
          IPS_SetProperty($this->InstanceID, "itemType", (integer)$itemType);
        }

        $this->setSceneInfo(stdConstant::MODE_GROUP_LOCAL, stdConstant::METHOD_CREATE_CHILD);
        return 102;
      }

      return 104;
    }

    return 102;
  }


  private function getGroupDevice($itemID, $buffer, $ncount) {
    $groupDevice = stdConstant::NO_STRING;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{0});

      if (($dcount = ord($buffer{1})) > 0) {
        $buffer = substr($buffer, 2);

        if ($itemID == $localID) {
          $groupDevice = chr($dcount).substr($buffer, 0, $dcount*stdConstant::UUID_DEVICE_LENGTH);
          break;
        }
      }

      $buffer = substr($buffer, $dcount*stdConstant::UUID_DEVICE_LENGTH);
    }

    return $groupDevice;
  }


  private function getGroupScene($itemID, $buffer, $ncount) {
    $groupScene = stdConstant::NO_STRING;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{1});

      if ($itemID == $localID) {
        $groupScene = substr($buffer, 0, stdConstant::DATA_SCENE_LENGTH);
        break;
      }

      $buffer = substr($buffer, stdConstant::DATA_SCENE_LENGTH);
    }

    return $groupScene;
  }


  private function setGroupInfo($mode, $method, $data) {
    switch ($mode) {
      case stdConstant::MODE_GROUP_LOCAL:
        if (($dcount = ord($data{0})) > 0) {
          $data    = substr($data, 1);
          $Devices = array();

          for ($i = 1; $i <= $dcount; $i++) {
            $uintUUID = substr($data, 0, stdConstant::UUID_DEVICE_LENGTH);

            if (false !== ($instanceID = $this->lightifyBase->getObjectByProperty(stdConstant::MODULE_DEVICE, "uintUUID", $uintUUID))) {
              $Devices[] = $instanceID;
            }

            $data = substr($data, stdConstant::UUID_DEVICE_LENGTH);
          }

          //Set group/zone state
          $online       = $state      = false;
          $newOnline    = $online;
          $newState     = $state;

          $hue = $color = $level      = stdConstant::NO_VALUE;
          $temperature  = $saturation = stdConstant::NO_VALUE;

          $deviceHue         = $deviceColor = $deviceLevel = stdConstant::NO_VALUE;
          $deviceTemperature = $deviceSaturation           = stdConstant::NO_VALUE;

          foreach ($Devices as $device) {
            $deviceOnlineID      = @IPS_GetObjectIDByIdent('ONLINE', $device);
            $deviceStateID       = @IPS_GetObjectIDByIdent('STATE', $device);
            $deviceOnline        = ($deviceOnlineID) ? GetValueBoolean($deviceOnlineID) : false;
            $deviceState         = ($deviceStateID) ? GetValueBoolean($deviceStateID) : false;

            $deviceHueID         = @IPS_GetObjectIDByIdent("HUE", $device);
            $deviceColorID       = @IPS_GetObjectIDByIdent("COLOR", $device);
            $deviceTemperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $device);
            $deviceLevelID       = @IPS_GetObjectIDByIdent("LEVEL", $device);
            $deviceSaturationID  = @IPS_GetObjectIDByIdent("SATURATION", $device);

            $deviceHue           = ($deviceHueID) ?  GetValueInteger($deviceHueID) : stdConstant::NO_VALUE;
            $deviceColor         = ($deviceColorID) ? GetValueInteger($deviceColorID) : stdConstant::NO_VALUE;
            $deviceTemperature   = ($deviceTemperatureID) ? GetValueInteger($deviceTemperatureID) : stdConstant::NO_VALUE;
            $deviceLevel         = ($deviceLevelID) ? GetValueInteger($deviceLevelID) : stdConstant::NO_VALUE;
            $deviceSaturation    = ($deviceSaturationID) ? GetValueInteger($deviceSaturationID) : stdConstant::NO_VALUE;

            if ($online === false && $deviceOnline === true) $newOnline = true;
            if ($state === false && $deviceState === true) $newState = true;

            if ($newState && $hue == stdConstant::NO_VALUE && $deviceHue != stdConstant::NO_VALUE) {
              $hue = $deviceHue;
            }

            if ($newState && $color == stdConstant::NO_VALUE && $deviceColor != stdConstant::NO_VALUE) {
              $color = $deviceColor;
            }

            if ($newState && $level == stdConstant::NO_VALUE && $deviceLevel != stdConstant::NO_VALUE) {
              $level = $deviceLevel;
            }

            if ($newState && $temperature == stdConstant::NO_VALUE && $deviceTemperature != stdConstant::NO_VALUE) {
              $temperature = $deviceTemperature;
            }

            if ($newState && $saturation == stdConstant::NO_VALUE && $deviceSaturation != stdConstant::NO_VALUE) {
              $saturation = $deviceSaturation;
            }
          }

          //State
          if ($stateID = @$this->GetIDForIdent("STATE")) {
            $this->MaintainAction("STATE", $newOnline);
          }

          if ($stateID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 0);
            }
          }

          if ($stateID) {
            if ($newState != ($state = GetValueBoolean($stateID))) {
              SetValueBoolean($stateID, $newState);
            }
          }

          //Hue
          if ($hueID = @$this->GetIDForIdent("HUE"))
            $this->MaintainAction("HUE", ($hue == stdConstant::NO_VALUE) ? false : true);

          if ($hueID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $hueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 1);
            }
          }

          if ($hueID && $hue != stdConstant::NO_VALUE) {
            if ($hue != GetValueInteger($hueID)) {
              SetValueInteger($hueID, $hue);
            }
          }

          //Color
          if ($colorID = @$this->GetIDForIdent("COLOR"))
            $this->MaintainAction("COLOR", ($color == stdConstant::NO_VALUE) ? false : true);

          if ($colorID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 2);
              IPS_SetIcon($colorID, "Paintbrush");
            }
          }

          if ($colorID && $color != stdConstant::NO_VALUE) {
            if ($color != GetValueInteger($colorID)) {
              SetValueInteger($colorID, $color);
            }
          }

          //Color temperature
          if ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE")) {
            $this->MaintainAction("COLOR_TEMPERATURE", ($temperature == stdConstant::NO_VALUE) ? false : true);
          }

          if ($temperatureID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTempExt", 3);
            }
          }

          if ($temperatureID && $temperature != stdConstant::NO_VALUE) {
            if ($temperature != GetValueInteger($temperatureID)) {
              SetValueInteger($temperatureID, $temperature);
            }
          }

          //Level
          if ($levelID = @$this->GetIDForIdent("LEVEL"))
            $this->MaintainAction("LEVEL", ($level == stdConstant::NO_VALUE) ? false : true);

          if ($levelID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 4);
              IPS_SetIcon($levelID, "Sun");
            }
          }

          if ($levelID && $level != stdConstant::NO_VALUE) {
            if ($level != GetValueInteger($levelID)) {
              SetValueInteger($levelID, $level);
            }
          }

          //Saturation control
          if ($saturationID = @$this->GetIDForIdent("SATURATION"))
            $this->MaintainAction("SATURATION", ($saturation == stdConstant::NO_VALUE) ? false : true);

          if ($saturationID === false) {
            if ($method == stdConstant::METHOD_CREATE_CHILD) {
              $saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 5);
              IPS_SetIcon($saturationID, "Intensity");
            }
          }

          if ($saturationID && $saturation != stdConstant::NO_VALUE) {
            if ($saturation != GetValueInteger($saturationID)) {
              SetValueInteger($saturationID, $saturation);
            }
          }
        }
        break;

      case stdConstant::MODE_GROUP_CLOUD:
        $cloudGroup = json_decode($data);
        return true;
    }
  }


  private function setSceneInfo($mode, $method) {
    //Create and update switch
    if (false === ($sceneID = @$this->GetIDForIdent("SCENE"))) {
      if ($method == stdConstant::METHOD_CREATE_CHILD) {
        $sceneID = $this->RegisterVariableInteger("SCENE", "Szene", "OSR.Scene", 311);
        $this->EnableAction("SCENE");
      }
    }

    if ($sceneID !== false && GetValueInteger($sceneID) != 1) {
      SetValueInteger($sceneID, 1);
    }
  }

}
