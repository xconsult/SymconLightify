<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


define('ROW_COLOR_LIGHT_ON',  "#fffde7");
define('ROW_COLOR_CCT_ON',    "#ffecB3");
define('ROW_COLOR_PLUG_ON',   "#c5e1a5");
define('ROW_COLOR_STATE_OFF', "#ffffff");
define('ROW_COLOR_LIGHT_OFF', "#e0e0e0");
define('ROW_COLOR_PLUG_OFF',  "#ef9a9a");


class lightifyGroup extends IPSModule
{

  const LIST_ELEMENTS_INDEX = 3;
  const ITEMID_CREATE       = 0;
  const ITEMID_MINIMUM      = 1;

  use LightifyControl;


  public function Create()
  {

    parent::Create();

    $this->SetBuffer("groupDevice", vtNoString);
    $this->SetBuffer("groupScene", vtNoString);

    $this->RegisterPropertyInteger("itemID", self::ITEMID_CREATE);
    $this->RegisterPropertyString("UUID", vtNoString);
    $this->RegisterPropertyInteger("itemClass", classConstant::CLASS_LIGHTIFY_GROUP);
    $this->RegisterPropertyString("deviceList", vtNoString);

    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyInteger("itemType", vtNoValue);
    $this->RegisterPropertyString("allLights", vtNoString);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);
  }


  public function ApplyChanges()
  {

    parent::ApplyChanges();

    //Check config
    $itemID = $this->ReadPropertyInteger("itemID");

    if ($itemID < self::ITEMID_MINIMUM) {
      $this->SetStatus(202);
      return false;
    }

    //Set properties
    $itemClass = $this->ReadPropertyInteger("itemClass");
    $status    = ($itemClass == classConstant::CLASS_LIGHTIFY_GROUP) ? $this->setGroupProperty($itemID) : $this->setSceneProperty($itemID);

    if ($status == 102) {
      if (IPS_HasChanges($this->InstanceID)) {
        IPS_ApplyChanges($this->InstanceID);
      }
    }

    $this->SetStatus($status);
  }


  public function GetConfigurationForm()
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $groupDevice = $this->GetBuffer("groupDevice");
      $itemType    = $this->ReadPropertyInteger("itemType");

      switch ($itemType) {
        case classConstant::TYPE_DEVICE_GROUP:
          $deviceList  = (!empty($groupDevice) && ord($groupDevice{0}) > 0) ? '
            { "type": "Label", "label": "----------------------------------------------------------------------------------------------------------------------------------" },
            { "type": "List",  "name":  "deviceList", "caption": "Devices",
              "columns": [
                { "label": "Instance ID", "name": "InstanceID",  "width": "60px", "visible": false },
                { "label": "ID",          "name": "deviceID",    "width": "35px"  },
                { "label": "Name",        "name": "name",        "width": "120px" },
                { "label": "Hue",         "name": "hue",         "width": "35px"  },
                { "label": "Color",       "name": "color",       "width": "60px"  },
                { "label": "Temperature", "name": "temperature", "width": "80px"  },
                { "label": "Brightness",  "name": "brightness",  "width": "70px"  },
                { "label": "Saturation",  "name": "saturation",  "width": "70px"  }
              ]
          },' : vtNoString;

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

        case classConstant::TYPE_GROUP_SCENE:
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
              { "type": "Button", "label": "Activate",  "onClick": "OSR_SetValue($id, \"SCENE\", 1)" }
            ],
            "status": [
              { "code": 102, "icon": "active",   "caption": "Scene is active"                   },
              { "code": 104, "icon": "inactive", "caption": "Scene is inactive"                 },
              { "code": 201, "icon": "error",    "caption": "Lightify gateway is not connected" },
              { "code": 202, "icon": "error",    "caption": "Invalid Scene [id]"                }
            ]
          }';
          return $formJSON;

        case classConstant::TYPE_ALL_LIGHTS:
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

      if (!empty($groupDevice) && (0 < ($dcount = ord($groupDevice{0})))) {
        $groupDevice = substr($groupDevice, 1);
        $data        = json_decode($formJSON);

        for ($i = 1; $i <= $dcount; $i++) {
          $uintUUID = substr($groupDevice, 0, classConstant::UUID_DEVICE_LENGTH);

          if ($instanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID)) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] != $parentID) {
              continue;
            }

            $stateID   = @IPS_GetObjectIDByIdent('STATE', $instanceID);
            $state     = ($stateID) ? GetValueBoolean($stateID) : false;

            $deviceID  = IPS_GetProperty($instanceID, "deviceID");
            $itemClass = IPS_GetProperty($instanceID, "itemClass");

            switch ($itemClass) {
              case classConstant::CLASS_LIGHTIFY_LIGHT:
                $classInfo = "Lampe";
                break;

              case classConstant::CLASS_LIGHTIFY_PLUG:
                $classInfo = "Steckdose";
                break;
            } 

            $hueID         = @IPS_GetObjectIDByIdent("HUE", $instanceID);
            $colorID       = @IPS_GetObjectIDByIdent("COLOR", $instanceID);
            $temperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $instanceID);
            $brightnessID  = @IPS_GetObjectIDByIdent("BRIGHTNESS", $instanceID);
            $saturationID  = @IPS_GetObjectIDByIdent("SATURATION", $instanceID);

            $hue           = ($hueID) ?  GetValueformatted($hueID) : vtNoString;
            $color         = ($colorID) ? strtolower(GetValueformatted($colorID)) : vtNoString;
            $temperature   = ($temperatureID) ? GetValueformatted($temperatureID) : vtNoString;
            $brightness    = ($brightnessID) ? preg_replace('/\s+/', '', GetValueformatted($brightnessID)) : vtNoString;
            $saturation    = ($saturationID) ? preg_replace('/\s+/', '', GetValueformatted($saturationID)) : vtNoString;

            if ($state) {
              if (@IPS_GetProperty($instanceID, "itemType") == classConstant::TYPE_PLUG_ONOFF) {
                $rowColor = ROW_COLOR_PLUG_ON;
              } else {
                $rowColor = ($temperature) ? ROW_COLOR_CCT_ON : ROW_COLOR_LIGHT_ON;
              }
            } else {
              if (@IPS_GetProperty($instanceID, "itemType") == classConstant::TYPE_PLUG_ONOFF) {
                $rowColor = ($state) ? ROW_COLOR_STATE_OFF : ROW_COLOR_PLUG_OFF;
              } else {
                $rowColor = ($state) ? ROW_COLOR_STATE_OFF : ROW_COLOR_LIGHT_OFF;
              }
            }

            $data->elements[self::LIST_ELEMENTS_INDEX]->values[] = array(
              'InstanceID'  => $instanceID,
              'deviceID'    => $deviceID,
              'name'        => IPS_GetName($instanceID),
              'hue'         => $hue,
              'color'       => ($color != vtNoString) ? "#".strtoupper($color) : vtNoString,
              'temperature' => $temperature,
              'brightness'  => $brightness,
              'saturation'  => $saturation,
              'rowColor'    => $rowColor
            );
          }

          $groupDevice = substr($groupDevice, classConstant::UUID_DEVICE_LENGTH);
        }

        return json_encode($data);
      }

      return $formJSON;
    }

    return vtNoForm;
  }


  public function ReceiveData($jsonString)
  {

    $itemID = $this->ReadPropertyInteger("itemID");
    $data   = json_decode($jsonString);

    $debug       = IPS_GetProperty($data->id, "debug");
    $message     = IPS_GetProperty($data->id, "message");

    $localBuffer = utf8_decode($data->buffer);
    $localCount  = ord($localBuffer{0});
    $itemType    = $this->ReadPropertyInteger("itemType");

    switch ($data->mode) {
      case classConstant::MODE_GROUP_LOCAL:
        //Store device group buffer
        if ($itemType == classConstant::TYPE_DEVICE_GROUP) {
          $groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
          $this->SetBuffer("groupDevice", $groupDevice);

          if (!empty($groupDevice)) {
            if ($debug % 2 || $message) {
              $info = $localCount."/".$this->lightifyBase->decodeData($groupDevice);

              if ($debug % 2) {
                $this->SendDebug("<Group|ReceiveData|groups:local>", $info, 0);
              }

              if ($message) {
                IPS_LogMessage("SymconOSR", "<Group|ReceiveData|groups:local>   ".$info);
              }
            }

            $this->setGroupInfo($data->mode, $data->method, $groupDevice);
          }
        }
        break;

      case classConstant::MODE_GROUP_CLOUD:
        break;

      case classConstant::MODE_GROUP_SCENE:
        //Store group scene buffer
        if ($itemType == classConstant::TYPE_GROUP_SCENE) {
          $groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
          $this->SetBuffer("groupScene", $groupScene);

          if (!empty($groupScene)) {
            if ($debug % 2 || $message) {
              $info = ord($groupScene{0})."/".ord($groupScene{1})."/".$this->lightifyBase->decodeData($groupScene);

              if ($debug % 2) {
                $this->SendDebug("<Group|ReceiveData|scenes:cloud>", $info, 0);
              }

              if ($message) {
                IPS_LogMessage("SymconOSR", "<Group|ReceiveData|scenes:cloud>   ".$info);
              }
            }

            $this->setSceneInfo($data->mode, $data->method);
          }
        }
        break;
    }
  }


  private function setGroupProperty($itemID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_GROUP_LOCAL))
      );

      if ($jsonString != vtNoString) {
        $localData   = json_decode($jsonString);
        $localBuffer = utf8_decode($localData->buffer);
        $localCount  = ord($localBuffer{0});

        //Store group device buffer
        $groupDevice = $this->getGroupDevice($itemID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("groupDevice", $groupDevice);

        if (!empty($groupDevice)) {
          $itemType = ord($localBuffer{1});
          $uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyInteger("itemType") != $itemType) {
            IPS_SetProperty($this->InstanceID, "itemType", (int)$itemType);
          }

          $this->setGroupInfo(classConstant::MODE_GROUP_LOCAL, classConstant::METHOD_CREATE_CHILD, $groupDevice);
          return 102;
        }

        return 104;
      }

      return 102;
    }

    return 201;
  }


  private function setSceneProperty($itemID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_GROUP_SCENE))
      );

      if ($jsonString != vtNoString) {
        $localData   = json_decode($jsonString);
        $localBuffer = utf8_decode($localData->buffer);
        $localCount  = ord($localBuffer{0});

        //Store group scene buffer
        $groupScene = $this->getGroupScene($itemID, substr($localBuffer, 2), $localCount);
        $this->SetBuffer("groupScene", $groupScene);

        if (!empty($groupScene)) {
          $itemType = ord($localBuffer{1});
          $uintUUID = chr($itemID).chr(0x00).chr($itemType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyInteger("itemType") != $itemType) {
            IPS_SetProperty($this->InstanceID, "itemType", (int)$itemType);
          }

          $this->setSceneInfo(classConstant::MODE_GROUP_LOCAL, classConstant::METHOD_CREATE_CHILD);
          return 102;
        }

        return 104;
      }

      return 102;
    }

    return 201;
  }


  private function getGroupDevice($itemID, $buffer, $ncount)
  {

    $groupDevice = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{0});
      $dcount  = ord($buffer{1});

      if ($dcount > 0) {
        $buffer = substr($buffer, 2);

        if ($localID == $itemID) {
          $groupDevice = chr($dcount).substr($buffer, 0, $dcount*classConstant::UUID_DEVICE_LENGTH);
          break;
        }
      }

      $buffer = substr($buffer, $dcount*classConstant::UUID_DEVICE_LENGTH);
    }

    return $groupDevice;
  }


  private function getGroupScene($itemID, $buffer, $ncount)
  {

    $groupScene = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($buffer{1});

      if ($localID == $itemID) {
        $groupScene = substr($buffer, 0, classConstant::DATA_SCENE_LENGTH);
        break;
      }

      $buffer = substr($buffer, classConstant::DATA_SCENE_LENGTH);
    }

    return $groupScene;
  }


  private function setGroupInfo($mode, $method, $data)
  {

    switch ($mode) {
      case classConstant::MODE_GROUP_LOCAL:
        if (($dcount = ord($data{0})) > 0) {
          $data    = substr($data, 1);
          $Devices = array();

          for ($i = 1; $i <= $dcount; $i++) {
            $uintUUID = substr($data, 0, classConstant::UUID_DEVICE_LENGTH);

            if (false !== ($instanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID))) {
              $Devices[] = $instanceID;
            }

            $data = substr($data, classConstant::UUID_DEVICE_LENGTH);
          }

          //Set group/zone state
          $online       = $state      = false;
          $newOnline    = $online;
          $newState     = $state;

          $hue = $color = $brightness = vtNoValue;
          $temperature  = $saturation = vtNoValue;

          $deviceHue         = $deviceColor = $deviceBrightness = vtNoValue;
          $deviceTemperature = $deviceSaturation                = vtNoValue;

          foreach ($Devices as $device) {
            $deviceStateID       = @IPS_GetObjectIDByIdent("STATE", $device);
            $deviceState         = ($deviceStateID) ? GetValueBoolean($deviceStateID) : false;

            $deviceHueID         = @IPS_GetObjectIDByIdent("HUE", $device);
            $deviceColorID       = @IPS_GetObjectIDByIdent("COLOR", $device);
            $deviceTemperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $device);
            $deviceBrightnessID  = @IPS_GetObjectIDByIdent("BRIGHTNESS", $device);
            $deviceSaturationID  = @IPS_GetObjectIDByIdent("SATURATION", $device);

            $deviceHue           = ($deviceHueID) ?  GetValueInteger($deviceHueID) : vtNoValue;
            $deviceColor         = ($deviceColorID) ? GetValueInteger($deviceColorID) : vtNoValue;
            $deviceTemperature   = ($deviceTemperatureID) ? GetValueInteger($deviceTemperatureID) : vtNoValue;
            $deviceBrightness    = ($deviceBrightnessID) ? GetValueInteger($deviceBrightnessID) : vtNoValue;
            $deviceSaturation    = ($deviceSaturationID) ? GetValueInteger($deviceSaturationID) : vtNoValue;

            if (!$state && $deviceState) {
              $newState = true;
            }

            if ($newState && $hue == vtNoValue && $deviceHue != vtNoValue) {
              $hue = $deviceHue;
            }

            if ($newState && $color == vtNoValue && $deviceColor != vtNoValue) {
              $color = $deviceColor;
            }

            if ($newState && $brightness == vtNoValue && $deviceBrightness != vtNoValue) {
              $brightness = $deviceBrightness;
            }

            if ($newState && $temperature == vtNoValue && $deviceTemperature != vtNoValue) {
              $temperature = $deviceTemperature;
            }

            if ($newState && $saturation == vtNoValue && $deviceSaturation != vtNoValue) {
              $saturation = $deviceSaturation;
            }
          }

          //State
          if (false == ($stateID = @$this->GetIDForIdent("STATE"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $stateID = $this->RegisterVariableBoolean("STATE", "State", "OSR.Switch", 313);
              $this->EnableAction("STATE");
            }
          }

          if ($stateID) {
            $state = GetValueBoolean($stateID);

            if ($state != $newState) {
              SetValueBoolean($stateID, $newState);
            }
          }

          //Hue
          if (false == ($hueID = @$this->GetIDForIdent("HUE"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $hueID = $this->RegisterVariableInteger("HUE", "Hue", "OSR.Hue", 314);

              IPS_SetDisabled($hueID, true);
              IPS_SetHidden($hueID, true);
            }
          }

          if ($hueID && $hue != vtNoValue) {
            if ($hue != GetValueInteger($hueID)) {
              SetValueInteger($hueID, $hue);
            }
          }

          //Color
          if (false == ($colorID = @$this->GetIDForIdent("COLOR"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $colorID = $this->RegisterVariableInteger("COLOR", "Color", "~HexColor", 315);
              IPS_SetIcon($colorID, "Paintbrush");

              $this->EnableAction("COLOR");
            }
          }

          if ($colorID && $color != vtNoValue) {
            if ($color != GetValueInteger($colorID)) {
              SetValueInteger($colorID, $color);
            }
          }

          //Color temperature
          if (false == ($temperatureID = @$this->GetIDForIdent("COLOR_TEMPERATURE"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTempExt", 316);
              $this->EnableAction("COLOR_TEMPERATURE");
            }
          }

          if ($temperatureID && $temperature != vtNoValue) {
            if ($temperature != GetValueInteger($temperatureID)) {
              SetValueInteger($temperatureID, $temperature);
            }
          }

          //Brightness
          if (false == ($brightnessID = @$this->GetIDForIdent("BRIGHTNESS"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $brightnessID = $this->RegisterVariableInteger("BRIGHTNESS", "Brightness", "OSR.Intensity", 317);
              IPS_SetIcon($brightnessID, "Sun");

              $this->EnableAction("BRIGHTNESS");
            }
          }

          if ($brightnessID && $brightness != vtNoValue) {
            if ($brightness != GetValueInteger($brightnessID)) {
              SetValueInteger($brightnessID, $brightness);
            }
          }

          //Saturation control
          if (false == ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              $saturationID = $this->RegisterVariableInteger("SATURATION", "Saturation", "OSR.Intensity", 318);
              IPS_SetIcon($saturationID, "Intensity");

              $this->EnableAction("SATURATION");
            }
          }

          if ($saturationID && $saturation != vtNoValue) {
            if ($saturation != GetValueInteger($saturationID)) {
              SetValueInteger($saturationID, $saturation);
            }
          }
        }
        break;

      case classConstant::MODE_GROUP_CLOUD:
        $cloudGroup = json_decode($data);
        return true;
    }
  }


  private function setSceneInfo($mode, $method)
  {

    //Create and update switch
    if (false == ($sceneID = @$this->GetIDForIdent("SCENE"))) {
      if ($method == classConstant::METHOD_CREATE_CHILD) {
        $sceneID = $this->RegisterVariableInteger("SCENE", "Szene", "OSR.Scene", 311);
        $this->EnableAction("SCENE");
      }
    }

    if ($sceneID && GetValueInteger($sceneID) != 1) {
      SetValueInteger($sceneID, 1);
    }
  }

}
