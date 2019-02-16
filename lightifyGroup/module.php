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


  const LIST_ELEMENTS_INDEX = 0;
  use LightifyControl;


  public function Create()
  {

    parent::Create();

    //Store at runtime
    $this->SetBuffer("applyMode", 1);

    $this->SetBuffer("groupDevice", vtNoString);
    $this->SetBuffer("groupScene", vtNoString);

    $this->RegisterPropertyInteger("groupID", vtNoValue);
    $this->RegisterPropertyInteger("itemClass", vtNoValue);
    $this->RegisterPropertyString("deviceList", vtNoString);

    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyInteger("classType", vtNoValue);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);
  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
  {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->SetBuffer("applyMode", 1);
        $this->ApplyChanges();
        break;
    }

  }


  public function ApplyChanges()
  {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) return;
    $applyMode = $this->GetBuffer("applyMode");

    if ($applyMode) {
      $groupID = $this->ReadPropertyInteger("groupID");
      if ($groupID == vtNoValue) return $this->SetStatus(202);

      //Apply filter
      $class = $this->ReadPropertyInteger("itemClass");

      switch ($class) {
        case classConstant::CLASS_LIGHTIFY_GROUP:
          $mode = classConstant::MODE_GROUP_LOCAL;

        case classConstant::CLASS_ALL_DEVICES:
          $mode = (isset($mode)) ? $mode : classConstant::MODE_ALL_SWITCH;

          $filter = ".*-g".preg_quote(trim(json_encode(utf8_encode(chr($groupID))), '"')).".*";
          $this->SetReceiveDataFilter($filter);

          $status = $this->setGroupProperty($mode, $class, $groupID);
          break;

        case classConstant::CLASS_LIGHTIFY_SCENE:
          $filter = ".*-s".preg_quote(trim(json_encode(utf8_encode(chr($groupID))), '"')).".*";

          $this->SetReceiveDataFilter($filter);
          $status = $this->setSceneProperty($groupID);
          break;
      }

      if ($status == 102) {
        //Apply changes
        if (IPS_HasChanges($this->InstanceID)) {
          $this->SetBuffer("applyMode", 0);
          IPS_ApplyChanges($this->InstanceID);
        }
      }
      $this->SetStatus($status);
    }

    if (!$applyMode) {
      $this->SetBuffer("applyMode", 1);
    }

  }


  public function GetConfigurationForm()
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $groupDevice = $this->GetBuffer("groupDevice");

      $groupID = $this->ReadPropertyInteger("groupID");
      $class   = $this->ReadPropertyInteger("itemClass");

      switch ($class) {
        case classConstant::CLASS_LIGHTIFY_GROUP:
          $elements = [];

          if (!empty($groupDevice) && ord($groupDevice{0}) > 0) {
            $columns = [];
            $columns [] = ['label' => "Instance ID", 'name' => "InstanceID",  'width' =>  "60px", 'visible' => false];
            $columns [] = ['label' => "Name",        'name' => "name",        'width' => "120px"];
            $columns [] = ['label' => "Hue",         'name' => "hue",         'width' =>  "35px"];
            $columns [] = ['label' => "Color",       'name' => "color",       'width' =>  "60px"];
            $columns [] = ['label' => "Temperature", 'name' => "temperature", 'width' =>  "80px"];
            $columns [] = ['label' => "Level",       'name' => "level",       'width' =>  "70px"];
            $columns [] = ['label' => "Saturation",  'name' => "saturation",  'width' =>  "70px"];
            $columns [] = ['label' => "T (ms)",      'name' => "transition",  'width' =>  "45px"];

            $elements [] = ['type' => "List", 'name' => "deviceList", 'caption' => "Devices", 'columns' => $columns];
          }

          $actions = [];
          $actions [] = ['type' => "Button", 'caption' => "On",  'onClick' => "OSR_WriteValue(\$id, \"STATE\", 1)"];
          $actions [] = ['type' => "Button", 'caption' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"STATE\", 0)"];

          $status = [];
          $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Group is active"];
          $status [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Group is inactive"];
          $status [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
          $status [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

          $formJSON = json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
          break;

        case classConstant::CLASS_LIGHTIFY_SCENE:
          $actions = [];
          $actions [] = ['type' => "Button", 'caption' => "Apply", 'onClick' => "OSR_WriteValue(\$id, \"SCENE\", $groupID)"];

          $status = [];
          $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Scene is active"];
          $status [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Scene is inactive"];
          $status [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
          $status [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

          $formJSON = json_encode(['elements' => [], 'actions' => $actions, 'status' => $status]);
          return $formJSON;

        case classConstant::CLASS_ALL_DEVICES:
          $actions = [];
          $actions [] = ['type' => "Button", 'caption' => "On",  'onClick' => "OSR_WriteValue(\$id, \"ALL_DEVICES\", 1)"];
          $actions [] = ['type' => "Button", 'caption' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"ALL_DEVICES\", 0)"];

          $formJSON = json_encode(['elements' => [], 'actions' => $actions]);
          return $formJSON;

        default:
          $status = [];
          $status [] = ['code' => 202, 'icon' => "error", 'caption' => "Group/Scene can only be configured over the Lightify Gateway Instance"];

          $formJSON = json_encode(['elements' => [], 'status' => $status]);
          return $formJSON;
      }

      if (!empty($groupDevice)) {
        $ncount = ord($groupDevice{0});
        $groupDevice = substr($groupDevice, 1);

        $length = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;
        $data   = json_decode($formJSON);

        for ($i = 1; $i <= $ncount; $i++) {
          $uintUUID   = substr($groupDevice, classConstant::ITEM_FILTER_LENGTH, classConstant::UUID_DEVICE_LENGTH);
          $instanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
          //IPS_LogMessage("SymconOSR", "<Group|GetConfigurationForm|info>   ".IPS_GetName($this->InstanceID)." - ".$this->lightifyBase->chrToUUID($uintUUID));

          if ($instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] != $parentID) continue;

            $stateID = @IPS_GetObjectIDByIdent("STATE", $instanceID);
            $state   = ($stateID) ? GetValueBoolean($stateID) : false;
            $class   = @IPS_GetProperty($instanceID, "itemClass");

            switch ($class) {
              case classConstant::CLASS_LIGHTIFY_LIGHT:
                $classInfo = $this->Translate("Light");
                break;

              case classConstant::CLASS_LIGHTIFY_PLUG:
                $classInfo = $this->Translate("Plug");
                break;
            } 

            $hueID         = @IPS_GetObjectIDByIdent("HUE", $instanceID);
            $colorID       = @IPS_GetObjectIDByIdent("COLOR", $instanceID);
            $temperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $instanceID);
            $levelID       = @IPS_GetObjectIDByIdent("LEVEL", $instanceID);
            $saturationID  = @IPS_GetObjectIDByIdent("SATURATION", $instanceID);

            $hue           = ($hueID) ?  GetValueformatted($hueID) : vtNoString;
            $color         = ($colorID) ? strtolower(GetValueformatted($colorID)) : vtNoString;
            $temperature   = ($temperatureID) ? GetValueformatted($temperatureID) : vtNoString;
            $level         = ($levelID) ? preg_replace('/\s+/', '', GetValueformatted($levelID)) : vtNoString;
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
              'name'        => IPS_GetName($instanceID),
              'hue'         => $hue,
              'color'       => ($color != vtNoString) ? "#".strtoupper($color) : vtNoString,
              'temperature' => $temperature,
              'level'       => $level,
              'saturation'  => $saturation,
              'transition'  => @IPS_GetProperty($instanceID, "transition"),
              'rowColor'    => $rowColor
            );
          }

          $groupDevice = substr($groupDevice, $length);
        }

        return json_encode($data);
      }

      return $formJSON;
    }

    return vtNoForm;

  }


  public function ReceiveData($jsonString)
  {

    $groupID = $this->ReadPropertyInteger("groupID");
    $class   = $this->ReadPropertyInteger("itemClass");
    $data    = json_decode($jsonString);

    $debug   = IPS_GetProperty($data->id, "debug");
    $message = IPS_GetProperty($data->id, "message");

    switch ($data->mode) {
      case classConstant::MODE_GROUP_LOCAL:
      case classConstant::MODE_ALL_SWITCH:
        //IPS_LogMessage("SymconOSR", "<Group|ReceiveData|all:switch>   ".IPS_GetName($this->InstanceID)." - ".$groupID."/".$class."/".json_encode($data->buffer));
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store device group buffer
        $groupDevice = $this->getgroupDevice($groupID, $ncount, substr($buffer, 2));
        $this->SetBuffer("groupDevice", $groupDevice);

        if (!empty($groupDevice)) {
          if ($debug % 2 || $message) {
            $info = ord($groupDevice{0}).":".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($groupDevice);

            if ($debug % 2) {
              $this->SendDebug("<Group|ReceiveData|groups:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Group|ReceiveData|groups:local>   ".$info);
            }
          }

          $this->setGroupInfo($data->mode, $data->method, $class, $groupDevice);
        }
        break;

      case classConstant::MODE_STATE_GROUP:
        $groupDevice = $this->GetBuffer("groupDevice");
        //IPS_LogMessage("SymconOSR", "<Group|ReceiveData|group:devices>   ".IPS_GetName($this->InstanceID)." - ".json_encode(utf8_encode($groupDevice)));

        if (!empty($groupDevice)) {
          if ($debug % 2 || $message) {
            $info = ord($groupDevice{0}).":".IPS_GetName($this->InstanceID)."/".$this->lightifyBase->decodeData($groupDevice);

            if ($debug % 2) {
              $this->SendDebug("<Group|ReceiveData|groups:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Group|ReceiveData|groups:local>   ".$info);
            }
          }

          $this->setGroupInfo($data->mode, $data->method, $class, $groupDevice);
        }
        break;

      case classConstant::MODE_GROUP_CLOUD:
        break;

      case classConstant::MODE_GROUP_SCENE:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store group scene buffer
        if ($class == classConstant::CLASS_LIGHTIFY_SCENE) {
          $groupScene = $this->getGroupScene($groupID, $ncount, substr($buffer, 2));
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


  private function setGroupProperty($mode, $class, $groupID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $connect = IPS_GetProperty($parentID, "connectMode");

      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => $mode))
      );
      //IPS_LogMessage("SymconOSR", "<Group|setGroupProperty|groups:local>   ".IPS_GetName($this->InstanceID)." - ".$groupID."/".$jsonString);

      if ($jsonString != vtNoString) {
        $data   = json_decode($jsonString);
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store group device buffer
        $groupDevice = $this->getgroupDevice($groupID, $ncount, substr($buffer, 2));
        $this->SetBuffer("groupDevice", $groupDevice);

        if (!empty($groupDevice)) {
          if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
            $jsonString = $this->SendDataToParent(json_encode([
              'DataID' => classConstant::TX_GATEWAY,
              'method' => classConstant::METHOD_APPLY_CHILD,
              'mode'   => classConstant::MODE_GROUP_SCENE])
            );

            if ($jsonString != vtNoString) {
              $data   = json_decode($jsonString);
              $buffer = utf8_decode($data->buffer);
              $ncount = ord($buffer{0});

              $groupScene = $this->getGroupScene($groupID, $ncount, substr($buffer, 2));
              $this->SetBuffer("groupScene", $groupScene);

              if (!empty($groupScene)) {
                $this->setSceneInfo(classConstant::MODE_GROUP_SCENE, classConstant::METHOD_UPDATE_CHILD);
              }
            }
          }

          $this->setGroupInfo($mode, classConstant::METHOD_CREATE_CHILD, $class, $groupDevice);
          return 102;
        }
        return 104;
      }
      return 102;
    }
    return 201;

  }


  private function getgroupDevice($groupID, $ncount, $data)
  {

    $device = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $data   = substr($data, 2);
      $length = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;

      $localID = ord($data{0});
      $dcount  = ord($data{1});

      if ($dcount > 0) {
        $data   = substr($data, 2);

        if ($localID == $groupID) {
          $deviceUUID = vtNoString;
          $group = substr($data, 0, $dcount*$length);

          for ($j = 1; $j <= $dcount; $j++) {
            $deviceUUID .= substr($group, 0, $length);
            $group = substr($group, $length);
          }

          $device = chr($dcount).$deviceUUID;
          break;
        }
      }

      $data = substr($data, $dcount*$length);
    }

    if (!empty($device)) {
      $buffer = $device;
      $ncount = ord($buffer{0});
      $buffer = substr($buffer, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        $uintUUID = substr($buffer, classConstant::ITEM_FILTER_LENGTH, classConstant::UUID_DEVICE_LENGTH);
        $buffer   = substr($buffer, $length);
      }
    }

    return $device;

  }


  private function setSceneProperty($groupID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
      $jsonString = $this->SendDataToParent(json_encode(array(
        'DataID' => classConstant::TX_GATEWAY,
        'method' => classConstant::METHOD_APPLY_CHILD,
        'mode'   => classConstant::MODE_GROUP_SCENE))
      );
      //IPS_LogMessage("SymconOSR", "<Group|setSceneProperty|scenes:cloud>   ".IPS_GetName($this->InstanceID)." - ".$groupID."/".$jsonString);

      if ($jsonString != vtNoString) {
        $data   = json_decode($jsonString);
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store group scene buffer
        $groupScene = $this->getGroupScene($groupID, $ncount, substr($buffer, 2));
        $this->SetBuffer("groupScene", $groupScene);

        if (!empty($groupScene)) {
          $type = ord($buffer{1});
          $uintUUID = chr($groupID).chr(0x00).chr($type).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyInteger("classType") != $type) {
            IPS_SetProperty($this->InstanceID, "classType", (int)$type);
          }

          $this->setSceneInfo(classConstant::MODE_GROUP_SCENE, classConstant::METHOD_CREATE_CHILD);
          return 102;
        }
        return 104;
      }
      return 102;
    }
    return 201;

  }


  private function getGroupScene($groupID, $ncount, $data)
  {

    $scene = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($data{5});

      if ($localID == $groupID) {
        $scene = substr($data, 0, classConstant::DATA_SCENE_LENGTH);
        break;
      }

      $data = substr($data, 4); //Scene suffix
      $data = substr($data, classConstant::DATA_SCENE_LENGTH);
    }

    return $scene;

  }


  private function setGroupInfo($mode, $method, $class, $data)
  {

    switch ($mode) {
      case classConstant::MODE_GROUP_LOCAL:
      case classConstant::MODE_STATE_GROUP:
      case classConstant::MODE_ALL_SWITCH:
        $dcount = ord($data{0});

        if ($dcount > 0) {
          $data    = substr($data, 1);
          $length  = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;
          $Devices = [];

          for ($i = 1; $i <= $dcount; $i++) {
            $uintUUID = substr($data, classConstant::ITEM_FILTER_LENGTH, classConstant::UUID_DEVICE_LENGTH);

            if (false !== ($instanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID))) {
              $Devices[] = $instanceID;
            }

            $data = substr($data, $length);
          }

          //Set group/zone state
          $online    = $state = false;
          $newOnline = $online;
          $newState  = $state;

          if ($mode == classConstant::MODE_GROUP_LOCAL) {
            $hue = $color = $level      = vtNoValue;
            $temperature  = $saturation = vtNoValue;

            $deviceHue         = $deviceColor = $deviceLevel = vtNoValue;
            $deviceTemperature = $deviceSaturation           = vtNoValue;
          }

          foreach ($Devices as $device) {
            $deviceStateID = @IPS_GetObjectIDByIdent("STATE", $device);
            $deviceState   = ($deviceStateID) ? GetValueBoolean($deviceStateID) : false;

            if ($mode == classConstant::MODE_GROUP_LOCAL) {
              $deviceHueID         = @IPS_GetObjectIDByIdent("HUE", $device);
              $deviceColorID       = @IPS_GetObjectIDByIdent("COLOR", $device);
              $deviceTemperatureID = @IPS_GetObjectIDByIdent("COLOR_TEMPERATURE", $device);
              $deviceLevelID       = @IPS_GetObjectIDByIdent("LEVEL", $device);
              $deviceSaturationID  = @IPS_GetObjectIDByIdent("SATURATION", $device);

              $deviceHue           = ($deviceHueID) ?  GetValueInteger($deviceHueID) : vtNoValue;
              $deviceColor         = ($deviceColorID) ? GetValueInteger($deviceColorID) : vtNoValue;
              $deviceTemperature   = ($deviceTemperatureID) ? GetValueInteger($deviceTemperatureID) : vtNoValue;
              $deviceLevel         = ($deviceLevelID) ? GetValueInteger($deviceLevelID) : vtNoValue;
              $deviceSaturation    = ($deviceSaturationID) ? GetValueInteger($deviceSaturationID) : vtNoValue;
            }

            if (!$state && $deviceState) {
              $newState = true;
            }

            if ($mode == classConstant::MODE_GROUP_LOCAL) {
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
          }

          /*
          if ($mode == classConstant::MODE_STATE_GROUP) {
            if (!empty($Devices)) {
              $newState = (bool)$method;
            }
          } */

          //State
          $ident = ($class == classConstant::CLASS_ALL_DEVICES) ? "ALL_DEVICES" : "STATE";

          if (false == ($stateID = @$this->GetIDForIdent($ident))) {
            if ($method == classConstant::METHOD_CREATE_CHILD) {
              //$stateID = $this->RegisterVariableBoolean($ident, $this->Translate("State"), "OSR.Switch", 313);
              $stateID = $this->RegisterVariableBoolean($ident, "State", "OSR.Switch", 313);
              $this->EnableAction($ident);
            }
          }

          if ($stateID) {
            $state = GetValueBoolean($stateID);

            if ($state != $newState) {
              SetValueBoolean($stateID, $newState);
            }
          }

          if ($mode == classConstant::MODE_GROUP_LOCAL) {
            //Hue
            if (false == ($hueID = @$this->GetIDForIdent("HUE"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);
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
                //$colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
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
                //$temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTempExt", 316);
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", "Color Temperature", "OSR.ColorTempExt", 316);
                $this->EnableAction("COLOR_TEMPERATURE");
              }
            }

            if ($temperatureID && $temperature != vtNoValue) {
              if ($temperature != GetValueInteger($temperatureID)) {
                SetValueInteger($temperatureID, $temperature);
              }
            }

            //Level
            if (false == ($levelID = @$this->GetIDForIdent("LEVEL"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$levelID = $this->RegisterVariableInteger("LEVEL", $this->Translate("Level"), "OSR.Intensity", 317);
                $levelID = $this->RegisterVariableInteger("LEVEL", "Level", "OSR.Intensity", 317);
                IPS_SetIcon($levelID, "Sun");

                $this->EnableAction("LEVEL");
              }
            }

            if ($levelID && $level != vtNoValue) {
              if ($level != GetValueInteger($levelID)) {
                SetValueInteger($levelID, $level);
              }
            }

            //Saturation control
            if (false == ($saturationID = @$this->GetIDForIdent("SATURATION"))) {
              if ($method == classConstant::METHOD_CREATE_CHILD) {
                //$saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
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
        //$sceneID = $this->RegisterVariableInteger("SCENE", $this->Translate("Scene"), "OSR.Scene", 311);
        $sceneID = $this->RegisterVariableInteger("SCENE", "Scene", "OSR.Scene", 311);
        $this->EnableAction("SCENE");
      }
    }

    if ($sceneID && GetValueInteger($sceneID) != 1) {
      SetValueInteger($sceneID, 1);
    }

  }


}
