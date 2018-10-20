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

    //Store at runtime
    $this->SetBuffer("applyMode", 1);

    $this->SetBuffer("groupDevice", vtNoString);
    $this->SetBuffer("groupScene", vtNoString);
    $this->SetBuffer("deviceUID", vtNoString);

    $this->RegisterPropertyInteger("groupID", self::ITEMID_CREATE);
    $this->RegisterPropertyInteger("groupClass", classConstant::CLASS_LIGHTIFY_GROUP);
    $this->RegisterPropertyString("deviceList", vtNoString);

    $this->RegisterPropertyString("uintUUID", vtNoString);
    $this->RegisterPropertyInteger("classType", vtNoValue);
    $this->RegisterPropertyString("allDevices", vtNoString);

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
      $class   = $this->ReadPropertyInteger("groupClass");

      //Check config
      if ($groupID < self::ITEMID_MINIMUM) {
        $this->SetStatus(202);
        return false;
      }

      if ($class == vtNoValue) {
        $this->SetStatus(203);
        return false;
      }

      //Apply filter
      switch ($class) {
        case classConstant::CLASS_LIGHTIFY_GROUP:
          $mode = classConstant::MODE_GROUP_LOCAL;

        case classConstant::CLASS_ALL_DEVICES:
          $mode = (isset($mode)) ? $mode : classConstant::MODE_ALL_SWITCH;

          //$filter = ".*-g".preg_quote("\u".str_pad((string)$groupID, 4, "0", STR_PAD_LEFT)).".*";
          $filter = ".*-g".preg_quote(trim(json_encode(utf8_encode(chr($groupID))), '"')).".*";
          $this->SetReceiveDataFilter($filter);

          $status = $this->setGroupProperty($mode, $class, $groupID);
          break;

        case classConstant::CLASS_LIGHTIFY_SCENE:
          //$filter = ".*-s".preg_quote("\u".str_pad((string)$groupID, 4, "0", STR_PAD_LEFT)).".*";
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
      $device = $this->GetBuffer("groupDevice");
      $class  = $this->ReadPropertyInteger("groupClass");

      switch ($class) {
        case classConstant::CLASS_LIGHTIFY_GROUP:
          $formElements = [];

          $formOptions    = [];
          $formOptions [] = ['label' => "Group", 'value' => 2006];

          $formElements [] = ['type' => "NumberSpinner", 'name' => "groupID",     'caption' => "Group [id]"];
          $formElements [] = ['type' => "Select",        'name' => "groupClass", 'caption' => "Class", 'options' => $formOptions];

          if (!empty($device) && ord($device{0}) > 0) {
            $formElements [] = ['type' => "Label", 'label' => ""];

            $deviceColumns    = [];
            $deviceColumns [] = ['label' => "Instance ID", 'name' => "InstanceID",  'width' =>  "60px", 'visible' => false];
            $deviceColumns [] = ['label' => "ID",          'name' => "deviceID",    'width' =>  "35px"];
            $deviceColumns [] = ['label' => "Name",        'name' => "name",        'width' => "120px"];
            $deviceColumns [] = ['label' => "Hue",         'name' => "hue",         'width' =>  "35px"];
            $deviceColumns [] = ['label' => "Color",       'name' => "color",       'width' =>  "60px"];
            $deviceColumns [] = ['label' => "Temperature", 'name' => "temperature", 'width' =>  "80px"];
            $deviceColumns [] = ['label' => "Brightness",  'name' => "brightness",  'width' =>  "70px"];
            $deviceColumns [] = ['label' => "Saturation",  'name' => "saturation",  'width' =>  "70px"];

            $formElements [] = ['type' => "List", 'name' => "deviceList", 'caption' => "Devices", 'columns' => $deviceColumns];
          }

          $formActions    = [];
          $formActions [] = ['type' => "Button", 'label' => "On",  'onClick' => "OSR_WriteValue(\$id, \"STATE\", 1)"];
          $formActions [] = ['type' => "Button", 'label' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"STATE\", 0)"];

          $formStatus    = [];
          $formStatus [] = ['code' => 102, 'icon' => "active",   'caption' => "Group is active"];
          $formStatus [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Group is inactive"];
          $formStatus [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
          $formStatus [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid Group [id]"];
          $formStatus [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

          $formJSON = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
          break;

        case classConstant::CLASS_LIGHTIFY_SCENE:
          $formElements = [];

          $formOptions    = [];
          $formOptions [] = ['label' => "Scene", 'value' => 2007];

          $formElements [] = ['type' => "NumberSpinner", 'name' => "groupID",     'caption' => "Group/Scene [id]"];
          $formElements [] = ['type' => "Select",        'name' => "groupClass", 'caption' => "Class", 'options' => $formOptions];

          $formActions    = [];
          $formActions [] = ['type' => "Button", 'label' => "Apply", 'onClick' => "OSR_WriteValue(\$id, \"SCENE\", 1)"];

          $formStatus    = [];
          $formStatus [] = ['code' => 102, 'icon' => "active",   'caption' => "Scene is active"];
          $formStatus [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Scene is inactive"];
          $formStatus [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
          $formStatus [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid Scene [id]"];
          $formStatus [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

          $formJSON = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);
          return $formJSON;

        case classConstant::CLASS_ALL_DEVICES:
          $formElements = [];
          $formElements [] = ['type' => "Label", 'label' => "Dummy switch group to turn on/off all lights"];

          $formActions    = [];
          $formActions [] = ['type' => "Button", 'label' => "On",  'onClick' => "OSR_WriteValue(\$id, \"ALL_DEVICES\", 1)"];
          $formActions [] = ['type' => "Button", 'label' => "Off", 'onClick' => "OSR_WriteValue(\$id, \"ALL_DEVICES\", 0)"];

          $formJSON = json_encode(['elements' => $formElements, 'actions' => $formActions]);
          return $formJSON;

        default:
          $formElements = [];

          $formOptions    = [];
          $formOptions [] = ['label' => "Group", 'value' => 2006];
          $formOptions [] = ['label' => "Scene", 'value' => 2007];

          $formElements [] = ['type' => "NumberSpinner", 'name' => "groupID",     'caption' => "Group/Scene [id]"];
          $formElements [] = ['type' => "Select",        'name' => "groupClass", 'caption' => "Class", 'options' => $formOptions];

          $formStatus    = [];
          $formStatus [] = ['code' => 102, 'icon' => "active",   'caption' => "Group/Scene is active"];
          $formStatus [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Group/Scene is inactive"];
          $formStatus [] = ['code' => 201, 'icon' => "error",    'caption' => "Lightify gateway is not connected"];
          $formStatus [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid Group/Scene [id]"];
          $formStatus [] = ['code' => 203, 'icon' => "error",    'caption' => "Invalid Class"];
          $formStatus [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

          $formJSON = json_encode(['elements' => $formElements, 'status' => $formStatus]);
          return $formJSON;
      }

      if (!empty($device)) {
        $ncount = ord($device{0});
        $device = substr($device, 1);
        $data   = json_decode($formJSON);

        for ($i = 1; $i <= $ncount; $i++) {
          $uintUUID = substr($device, 0, classConstant::UUID_DEVICE_LENGTH);

          if ($instanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID)) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] != $parentID) {
              continue;
            }

            $stateID = @IPS_GetObjectIDByIdent('STATE', $instanceID);
            $state   = ($stateID) ? GetValueBoolean($stateID) : false;

            $deviceID = IPS_GetProperty($instanceID, "deviceID");
            $class    = IPS_GetProperty($instanceID, "deviceClass");

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

          $device = substr($device, classConstant::UUID_DEVICE_LENGTH);
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
    $class   = $this->ReadPropertyInteger("groupClass");
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
        $device = $this->getGroupDevice($groupID, $ncount, substr($buffer, 2));
        $this->SetBuffer("groupDevice", $device);

        if (!empty($device)) {
          if ($debug % 2 || $message) {
            $info = $ncount."/".$this->lightifyBase->decodeData($device);

            if ($debug % 2) {
              $this->SendDebug("<Group|ReceiveData|groups:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Group|ReceiveData|groups:local>   ".$info);
            }
          }

          $this->setGroupInfo($data->mode, $data->method, $class, $device);
        }
        break;

      case classConstant::MODE_STATE_GROUP:
        $device = $this->GetBuffer("groupDevice");
        //IPS_LogMessage("SymconOSR", "<Group|ReceiveData|group:devices>   ".IPS_GetName($this->InstanceID)." - ".json_encode(utf8_encode($device)));

        if (!empty($device)) {
          if ($debug % 2 || $message) {
            $info = $ncount."/".$this->lightifyBase->decodeData($device);

            if ($debug % 2) {
              $this->SendDebug("<Group|ReceiveData|groups:local>", $info, 0);
            }

            if ($message) {
              IPS_LogMessage("SymconOSR", "<Group|ReceiveData|groups:local>   ".$info);
            }
          }

          $this->setGroupInfo($data->mode, $data->method, $class, $device);
        }
        break;

      case classConstant::MODE_GROUP_CLOUD:
        break;

      case classConstant::MODE_GROUP_SCENE:
        $buffer = utf8_decode($data->buffer);
        $ncount = ord($buffer{0});

        //Store group scene buffer
        if ($class == classConstant::CLASS_LIGHTIFY_SCENE) {
          $scene = $this->getGroupScene($groupID, $ncount, substr($buffer, 2));
          $this->SetBuffer("groupScene", $scene);

          if (!empty($scene)) {
            if ($debug % 2 || $message) {
              $info = ord($scene{0})."/".ord($scene{1})."/".$this->lightifyBase->decodeData($scene);

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

      /*
      case classConstant::MODE_ALL_SWITCH:
        $buffer = json_encode($data->buffer);
        IPS_LogMessage("SymconOSR", "<Groupp|ReceiveData|all:switch>   ".IPS_GetName($this->InstanceID)." - ".$groupID."/".$buffer);
        break; */
    }

  }


  private function setGroupProperty($mode, $class, $groupID)
  {

    if (0 < ($parentID = $this->getParentInfo($this->InstanceID))) {
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
        $device = $this->getGroupDevice($groupID, $ncount, substr($buffer, 2));
        $this->SetBuffer("groupDevice", $device);

        if (!empty($device)) {
          $type = ord($buffer{1});
          $uintUUID = chr($groupID).chr(0x00).chr($type).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyInteger("classType") != $type) {
            IPS_SetProperty($this->InstanceID, "classType", (int)$type);
          }

          $this->setGroupInfo($mode, classConstant::METHOD_CREATE_CHILD, $class, $device);
          return 102;
        }
        return 104;
      }
      return 102;
    }
    return 201;

  }


  private function getGroupDevice($groupID, $ncount, $data)
  {

    $device = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $data    = substr($data, 2);
      $localID = ord($data{0});
      $dcount  = ord($data{1});

      if ($dcount > 0) {
        $data   = substr($data, 2);
        $length = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;

        if ($localID == $groupID) {
          $group = substr($data, 0, $dcount*$length);

          $deviceUID  = vtNoString;
          $uuidBuffer = vtNoString;

          for ($j = 1; $j <= $dcount; $j++) {
            $deviceUID  .= substr($group, 0, classConstant::ITEM_FILTER_LENGTH);
            $uuidBuffer .= substr($group, classConstant::ITEM_FILTER_LENGTH, classConstant::UUID_DEVICE_LENGTH);

            $group = substr($group, $length);
          }

          $device = chr($dcount).$uuidBuffer;
          break;
        }
      }

      $data = substr($data, $dcount*$length);
    }

    $this->SetBuffer("deviceUID", $deviceUID);
    //IPS_LogMessage("SymconOSR", "<Group|getGroupDevice|devices:buffer>   ".IPS_GetName($this->InstanceID)." - ".json_encode(utf8_encode($this->GetBuffer("deviceUID"))));

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

      if ($jsonString != vtNoString) {
        $localData   = json_decode($jsonString);
        $localBuffer = utf8_decode($localData->buffer);
        $localCount  = ord($localBuffer{0});

        //Store group scene buffer
        $groupScene = $this->getGroupScene($groupID, $localCount, substr($localBuffer, 2));
        $this->SetBuffer("groupScene", $groupScene);

        if (!empty($groupScene)) {
          $classType = ord($localBuffer{1});
          $uintUUID = chr($groupID).chr(0x00).chr($classType).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);

          if ($this->ReadPropertyString("uintUUID") != $uintUUID) {
            IPS_SetProperty($this->InstanceID, "uintUUID", $uintUUID);
          }

          if ($this->ReadPropertyInteger("classType") != $classType) {
            IPS_SetProperty($this->InstanceID, "classType", (int)$classType);
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

    $groupScene = vtNoString;

    for ($i = 1; $i <= $ncount; $i++) {
      $localID = ord($data{5});

      if ($localID == $groupID) {
          $groupScene = substr($data, classConstant::DATA_SCENE_LENGTH);
        break;
      }

      $data = substr($data, classConstant::DATA_SCENE_LENGTH);
    }

    return $groupScene;

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
          $Devices = array();

          for ($i = 1; $i <= $dcount; $i++) {
            $uintUUID = substr($data, 0, classConstant::UUID_DEVICE_LENGTH);

            if (false !== ($instanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID))) {
              $Devices[] = $instanceID;
            }

            $data = substr($data, classConstant::UUID_DEVICE_LENGTH);
          }

          //Set group/zone state
          $online    = $state = false;
          $newOnline = $online;
          $newState  = $state;

          if ($mode == classConstant::MODE_GROUP_LOCAL) {
            $hue = $color = $brightness = vtNoValue;
            $temperature  = $saturation = vtNoValue;

            $deviceHue         = $deviceColor = $deviceBrightness = vtNoValue;
            $deviceTemperature = $deviceSaturation                = vtNoValue;
          }

          foreach ($Devices as $device) {
            $deviceStateID = @IPS_GetObjectIDByIdent("STATE", $device);
            $deviceState   = ($deviceStateID) ? GetValueBoolean($deviceStateID) : false;

            if ($mode == classConstant::MODE_GROUP_LOCAL) {
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
              $stateID = $this->RegisterVariableBoolean($ident, $this->Translate("State"), "OSR.Switch", 313);
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
                $hueID = $this->RegisterVariableInteger("HUE", $this->Translate("Hue"), "OSR.Hue", 314);

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
                $colorID = $this->RegisterVariableInteger("COLOR", $this->Translate("Color"), "~HexColor", 315);
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
                $temperatureID = $this->RegisterVariableInteger("COLOR_TEMPERATURE", $this->Translate("Color Temperature"), "OSR.ColorTempExt", 316);
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
                $brightnessID = $this->RegisterVariableInteger("BRIGHTNESS", $this->Translate("Brightness"), "OSR.Intensity", 317);
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
                $saturationID = $this->RegisterVariableInteger("SATURATION", $this->Translate("Saturation"), "OSR.Intensity", 318);
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
        $sceneID = $this->RegisterVariableInteger("SCENE", $this->Translate("Scene"), "OSR.Scene", 311);
        $this->EnableAction("SCENE");
      }
    }

    if ($sceneID && GetValueInteger($sceneID) != 1) {
      SetValueInteger($sceneID, 1);
    }

  }


}
