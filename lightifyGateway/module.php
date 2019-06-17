<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


//Instance specific
define('TIMER_SYNC_LOCAL',       10);
define('TIMER_SYNC_LOCAL_MIN',    3);

define('TIMER_MODE_ON',        true);
define('TIMER_MODE_OFF',      false);

define('MAX_DEVICE_SYNC',        50);
define('MAX_GROUP_SYNC',         16);

//Cloud connection specific
define('LIGHITFY_INVALID_CREDENTIALS',    5001);
define('LIGHITFY_INVALID_SECURITY_TOKEN', 5003);
define('LIGHITFY_GATEWAY_OFFLINE',        5019);


class lightifyGateway extends IPSModule
{


  const GATEWAY_SERIAL_LENGTH   = 11;

  const LIST_CATEGORY_INDEX     =  5;
  const LIST_DEVICE_INDEX       =  8;
  const LIST_GROUP_INDEX        =  9;
  const LIST_SCENE_INDEX        = 10;

  const OAUTH_AUTHORIZE         = "https://oauth.ipmagic.de/authorize/";
  const OAUTH_FORWARD           = "https://oauth.ipmagic.de/forward/";
  const OAUTH_ACCESS_TOKEN      = "https://oauth.ipmagic.de/access_token/";
  const AUTHENTICATION_TYPE     = "Bearer";

  const RESOURCE_SESSION        = "/session";
  const LIGHTIFY_EUROPE         = "https://emea.lightify-api.com/";
  const LIGHTIFY_USA            = "https://na.lightify-api.com/";
  const LIGHTIFY_VERSION        = "v4/";

  const PROTOCOL_VERSION        =  1;
  const HEADER_AUTHORIZATION    = "Authorization: Bearer ";
  const HEADER_FORM_CONTENT     = "Content-Type: application/x-www-form-urlencoded";
  const HEADER_JSON_CONTENT     = "Content-Type: application/json";

  const RESSOURCE_DEVICES       = "devices/";
  const RESSOURCE_GROUPS        = "groups/";
  const RESSOURCE_SCENES        = "scenes/";

  const LIGHTIFY_MAXREDIRS      = 10;
  const LIGHTIFY_TIMEOUT        = 30;

  protected $oAuthIdent = "osram_lightify";
  protected $lightifyBase;

  protected $debug   = false;
  protected $message = false;

  use ParentInstance,
      WebOAuth,
      InstanceHelper;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->SetBuffer("applyMode", 0);
    $this->SetBuffer("cloudIntervall", vtNoString);
    $this->SetBuffer("localMethod", vtNoValue);

    $this->RegisterPropertyBoolean("active", false);
    $this->RegisterPropertyInteger("connectMode", classConstant::CONNECT_LOCAL_ONLY);

    //Local gateway
    $this->RegisterPropertyString("gatewayIP", vtNoString);
    $this->RegisterPropertyString("serialNumber", vtNoString);
    
    $this->RegisterPropertyInteger("localUpdate", TIMER_SYNC_LOCAL);
    $this->RegisterTimer("localTimer", 0, "OSR_GetLightifyData($this->InstanceID, 1202);");

    //Cloud Access Token
    $this->RegisterPropertyString("osramToken", vtNoString);

    //Global settings
    $this->RegisterPropertyString("listCategory", vtNoString);
    $this->RegisterPropertyString("listDevice", vtNoString);
    $this->RegisterPropertyString("listGroup", vtNoString);
    $this->RegisterPropertyString("listScene", vtNoString);
    $this->RegisterPropertyBoolean("deviceInfo", false);
    $this->RegisterPropertyBoolean("waitResult", false);

    $this->SetBuffer("cloudDevices", vtNoString);
    $this->SetBuffer("cloudGroups", vtNoString);
    $this->SetBuffer("cloudScenes", vtNoString);

    $this->RegisterPropertyInteger("debug", classConstant::DEBUG_DISABLED);
    $this->RegisterPropertyBoolean("message", false);

    //Create profiles
    if (!IPS_VariableProfileExists("OSR.Hue")) {
      IPS_CreateVariableProfile("OSR.Hue", vtInteger);
      IPS_SetVariableProfileIcon("OSR.Hue", "Shift");
      IPS_SetVariableProfileDigits("OSR.Hue", 0);
      IPS_SetVariableProfileText("OSR.Hue", vtNoString, "°");
      IPS_SetVariableProfileValues("OSR.Hue", classConstant::HUE_MIN, classConstant::HUE_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.ColorTemp")) {
      IPS_CreateVariableProfile("OSR.ColorTemp", vtInteger);
      IPS_SetVariableProfileIcon("OSR.ColorTemp", "Flame");
      IPS_SetVariableProfileDigits("OSR.ColorTemp", 0);
      IPS_SetVariableProfileText("OSR.ColorTemp", vtNoString, "K");
      IPS_SetVariableProfileValues("OSR.ColorTemp", classConstant::CTEMP_CCT_MIN, classConstant::CTEMP_CCT_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.ColorTempExt")) {
      IPS_CreateVariableProfile("OSR.ColorTempExt", vtInteger);
      IPS_SetVariableProfileIcon("OSR.ColorTempExt", "Flame");
      IPS_SetVariableProfileDigits("OSR.ColorTempExt", 0);
      IPS_SetVariableProfileText("OSR.ColorTempExt", vtNoString, "K");
      IPS_SetVariableProfileValues("OSR.ColorTempExt", classConstant::CTEMP_COLOR_MIN, classConstant::CTEMP_COLOR_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.Intensity")) {
      IPS_CreateVariableProfile("OSR.Intensity", vtInteger);
      IPS_SetVariableProfileDigits("OSR.Intensity", 0);
      IPS_SetVariableProfileText("OSR.Intensity", vtNoString, "%");
      IPS_SetVariableProfileValues("OSR.Intensity", classConstant::INTENSITY_MIN, classConstant::INTENSITY_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.Switch")) {
      IPS_CreateVariableProfile("OSR.Switch", vtBoolean);
      IPS_SetVariableProfileIcon("OSR.Switch", "Power");
      IPS_SetVariableProfileDigits("OSR.Switch", 0);
      IPS_SetVariableProfileValues("OSR.Switch", 0, 1, 0);
      IPS_SetVariableProfileAssociation("OSR.Switch", true, "On", vtNoString, 0xFF9200);
      IPS_SetVariableProfileAssociation("OSR.Switch", false, "Off", vtNoString, vtNoValue);
    }

    if (!IPS_VariableProfileExists("OSR.Scene")) {
      IPS_CreateVariableProfile("OSR.Scene", vtInteger);
      IPS_SetVariableProfileIcon("OSR.Scene", "Power");
      IPS_SetVariableProfileDigits("OSR.Scene", 0);
      IPS_SetVariableProfileValues("OSR.Scene", 1, 1, 0);
      IPS_SetVariableProfileAssociation("OSR.Scene", 1, "On", vtNoString, 0xFF9200);
    }

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->SetBuffer("applyMode", 1);
        $this->ApplyChanges();
        break;
    }

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) return;
    $applyMode = $this->GetBuffer("applyMode");
    $this->SetBuffer("sendQueue", vtNoString);

    if ($applyMode) {
      $this->RequireParent(classConstant::CLIENT_SOCKET, "Lightify Socket");

      $this->SetBuffer("connectTime", vtNoString);
      $localUpdate = 0;

      $active  = $this->ReadPropertyBoolean("active");
      $connect = $this->ReadPropertyInteger("connectMode");
      $result  = $this->validateConfig($active, $connect);

      if ($result) {
        $this->GetConfigurationForParent();
        $localUpdate = $this->ReadPropertyInteger("localUpdate")*1000;

        if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
          $this->RegisterOAuth($this->oAuthIdent);
        }

        $this->GetLightifyData(classConstant::METHOD_APPLY_LOCAL);
      }

      $this->SetTimerInterval("localTimer", $localUpdate);
    }

    if (!$applyMode) {
      $this->SetBuffer("applyMode", 1);
    }

  }


  protected function RequireParent($moduleID, $name = vtNoString) {

    $Instance = IPS_GetInstance($this->InstanceID);

    if ($Instance['ConnectionID'] == 0) {
      $id = IPS_CreateInstance($moduleID);

      if ($id) {
        $Instance = IPS_GetInstance($id);

        if ($name == vtNoString) {
          IPS_SetName($id, $Instance['ModuleInfo']['ModuleName']);
        } else {
          IPS_SetName($id, $name);
        }

        IPS_ConnectInstance($this->InstanceID, $id);
      }
    }

  }


  public function GetConfigurationForParent() {

    $gatewayIP = $this->ReadPropertyString("gatewayIP");
    $port = classConstant::GATEWAY_PORT;

    return "{\"Host\": \"$gatewayIP\", \"Port\": $port}";

  }


  public function GetConfigurationForm() {

    $elements = [];
    $elements [] = ['type' => "CheckBox", 'name' => "active", 'caption' => " Active"];

    $options = [];
    $options [] = ['label' => "Local only",      'value' => 1001];
    $options [] = ['label' => "Local and Cloud", 'value' => 1002];

    $elements [] = ['type' => "Select",            'name'    => "connectMode",  'caption' => " Connection", 'options' => $options];
    $elements [] = ['type' => "ValidationTextBox", 'name'    => "gatewayIP",    'caption' => "Gateway IP"];
    $elements [] = ['type' => "ValidationTextBox", 'name'    => "serialNumber", 'caption' => "Serial number"];
    $elements [] = ['type' => "NumberSpinner",     'name'    => "localUpdate",  'caption' => "Update interval [s]"];

    $columns = [];
    $columns [] = ['label' => "Type",        'name' => "Device",     'width' =>  "65px"];
    $columns [] = ['label' => "Category",    'name' => "Category",   'width' => "285px"];
    $columns [] = ['label' => "Category ID", 'name' => "categoryID", 'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "SelectCategory"]];
    $columns [] = ['label' => "Sync",        'name' => "Sync",       'width' =>  "45px"];
    $columns [] = ['label' => "Sync ID",     'name' => "syncID",     'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "CheckBox", 'caption' => " Synchronise values"]];
    $columns [] = ['label' => "",            'name' => "empty",      'width' =>  "auto"];

    $elements [] = ['type' => "List", 'name' => "listCategory", 'rowCount' => 5, 'columns' => $columns];
    $elements [] = ['type' => "CheckBox", 'name' => "deviceInfo", 'caption' => " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)"];
    $elements [] = ['type' => "CheckBox", 'name' => "waitResult", 'caption' => " Decode gateway command result (longer runtime)"];

    //Device list configuration
    $deviceList = $this->GetBuffer("deviceList");

    if (!empty($deviceList) && ord($deviceList{0}) > 0) {
      $cloudDevices = $this->GetBuffer("cloudDevices");

      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "deviceID",   'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",  'width' => "100px"];
      $columns [] = ['label' => "Name",  'name' => "deviceName", 'width' => "140px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",       'width' => "180px"];

      if (!empty($cloudDevices)) {
        $columns [] = ['label' => "Manufacturer", 'name' => "manufacturer", 'width' => "110px"];
        $columns [] = ['label' => "Model",        'name' => "deviceModel",  'width' => "190px"];
        $columns [] = ['label' => "Capabilities", 'name' => "deviceLabel",  'width' => "250px"];
        $columns [] = ['label' => "Firmware",     'name' => "firmware",     'width' =>  "85px"];
        $columns [] = ['label' => "",             'name' => "empty",        'width' =>  "auto"];
      }

      $elements [] = ['type' => "List", 'name' => "listDevice", 'rowCount' => 5, 'columns' => $columns];
    }

    //Group list configuration
    $groupList = $this->GetBuffer("groupList");

    if (!empty($groupList) && ord($groupList{0}) > 0) {
      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "groupID",     'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",   'width' => "100px"];
      $columns [] = ['label' => "Name",  'name' => "groupName",   'width' => "140px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "180px"];
      $columns [] = ['label' => "Info",  'name' => "information", 'width' => "110px"];
      $columns [] = ['label' => "",      'name' => "empty",       'width' =>  "auto"];

      $elements [] = ['type' => "List", 'name' => "listGroup", 'rowCount' => 5, 'columns' => $columns];
    }

    //Scene list configuration
    $sceneList = $this->GetBuffer("sceneList");

    if (!empty($sceneList) && ord($sceneList{0}) > 0) {
      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "sceneID",     'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",   'width' => "100px"];
      $columns [] = ['label' => "Name",  'name' => "sceneName",   'width' => "140px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "180px"];
      $columns [] = ['label' => "Group", 'name' => "groupName",   'width' => "140px"];
      $columns [] = ['label' => "Info",  'name' => "information", 'width' =>  "70px"];
      $columns [] = ['label' => "",      'name' => "empty",       'width' =>  "auto"];

      $elements [] = ['type' => "List", 'name' => "listScene", 'rowCount' => 5, 'columns' => $columns];
    }

    $options = [];
    $options [] = ['label' => "Disabled",            'value' =>  0];
    $options [] = ['label' => "Send buffer",         'value' =>  3];
    $options [] = ['label' => "Receive buffer",      'value' =>  7];
    $options [] = ['label' => "Send/Receive buffer", 'value' => 13];
    $options [] = ['label' => "Detailed error log",  'value' => 17];

    $elements [] = ['type' => "Select",   'name' => "debug",   'caption' => "Debug", 'options' => $options];
    $elements [] = ['type' => "CheckBox", 'name' => "message", 'caption' => " Messages"];

    $actions = [];
    $actions [] = ['type' => "Label",  'caption' => "Press Register to enable the cloud access"];
    $actions [] = ['type' => "Button", 'caption' => "Register", 'onClick' => "echo OSR_LightifyRegister(\$id);"];
    $actions [] = ['type' => "Label",  'caption' => "Press Create | Update to automatically apply the devices and settings"];
    $actions [] = ['type' => "Button", 'caption' => "Create | Update", 'onClick' => "OSR_GetLightifyData(\$id, 1206);"];

    $status = [];
    $status [] = ['code' => 101, 'icon' => "inactive", 'caption' => "Lightify gateway is closed"];
    $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Lightify gateway is active"];
    $status [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Enter all required informations"];
    $status [] = ['code' => 201, 'icon' => "inactive", 'caption' => "Lightify gateway is not connected"];
    $status [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid IP address"];
    $status [] = ['code' => 203, 'icon' => "error",    'caption' => "Invalid Serial number"];
    $status [] = ['code' => 205, 'icon' => "error",    'caption' => "Update interval < 3s"];
    $status [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

    //Encode configuration form
    $formJSON = json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);

    $data  = json_decode($formJSON);
    $Types = ["Device", "Sensor", "Group", "Scene"];

    //Only add default element if we do not have anything in persistence
    $Categories = json_decode($this->ReadPropertyString("listCategory"));
    //IPS_LogMessage("SymconOSR", "<Gateway|GetConfigurationForParent|Categories>   ".$this->ReadPropertyString("listCategory"));

    if (empty($Categories)) {
      foreach ($Types as $item) {
        $data->elements[self::LIST_CATEGORY_INDEX]->values[] = [
          'Device'     => $item,
          'categoryID' => 0, 
          'Category'   => $this->Translate("select ..."),
          'Sync'       => $this->Translate("no"),
          'syncID'     => false
        ];
      }
    } else {
      //Annotate existing elements
      foreach ($Categories as $index => $row) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        if ($row->categoryID && IPS_ObjectExists($row->categoryID)) {
          $data->elements[self::LIST_CATEGORY_INDEX]->values[$index] = [
            'Device'     => $Types[$index],
            'categoryID' => $row->categoryID,
            'Category'   => IPS_GetName(0)."\\".IPS_GetLocation($row->categoryID),
            'Sync'       => ($row->syncID) ? $this->Translate("yes") : $this->Translate("no"),
            'syncID'     => $row->syncID
          ];
        } else {
          $data->elements[self::LIST_CATEGORY_INDEX]->values[$index] = [
            'Device'     => $Types[$index],
            'Category'   => $this->Translate("select ..."),
            'categoryID' => 0,
            'Sync'       => $this->Translate("no"),
            'syncID'     => false
          ];
        }
      }
    }
    //IPS_LogMessage("SymconOSR", "<Gateway|GetConfigurationForParent|category>   ".json_encode($data));

    //Device list element
    if (!empty($deviceList)) {
      $ncount  = ord($deviceList{0});
      $deviceList = substr($deviceList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $deviceID = ord($deviceList{0});
        $deviceList = substr($deviceList, 1);

        $uintUUID = substr($deviceList, 0, classConstant::UUID_DEVICE_LENGTH);
        $UUID = $this->lightifyBase->chrToUUID($uintUUID);

        $name = trim(substr($deviceList, 8, classConstant::DATA_NAME_LENGTH));
        $info = trim(substr($deviceList, 23, classConstant::DATA_CLASS_INFO));

        $Devices = [
          'deviceID'   => $deviceID,
          'classInfo'  => $info,
          'UUID'       => $UUID,
          'deviceName' => $name
        ];

        if (!empty($cloudDevices)) {
          $mcount = ord($cloudDevices{0});
          $cloudBuffer = substr($cloudDevices, 1);

          for ($j = 1; $j <= $mcount; $j++) {
            unset($label);

            $length = ord($cloudBuffer{0});
            $type   = ord($cloudBuffer{1});
            $cloudBuffer = substr($cloudBuffer, 1);

            switch ($type) {
              case classConstant::TYPE_FIXED_WHITE:
                $label = classConstant::LABEL_FIXED_WHITE;

              case classConstant::TYPE_LIGHT_CCT:
                if (!isset($label)) $label = classConstant::LABEL_LIGHT_CCT;

              case classConstant::TYPE_LIGHT_DIMABLE:
                if (!isset($label)) $label = classConstant::LABEL_LIGHT_DIMABLE;

              case classConstant::TYPE_LIGHT_COLOR:
                if (!isset($label)) $label = classConstant::LABEL_LIGHT_COLOR;

              case classConstant::TYPE_LIGHT_EXT_COLOR:
                if (!isset($label)) $label = classConstant::LABEL_LIGHT_EXT_COLOR;

              case classConstant::TYPE_PLUG_ONOFF:
                if (!isset($label)) $label = classConstant::LABEL_PLUG_ONOFF;
                break;

              case classConstant::TYPE_SENSOR_MOTION:
                if (!isset($label)) $label = classConstant::LABEL_SENSOR_MOTION;

              case classConstant::TYPE_DIMMER_2WAY:
                if (!isset($label)) $label = classConstant::LABEL_DIMMER_2WAY;

              case classConstant::TYPE_SWITCH_4WAY:
                if (!isset($label)) $label = classConstant::LABEL_SWITCH_4WAY;

              case classConstant::TYPE_SWITCH_MINI:
                if (!isset($label)) $label = classConstant::LABEL_SWITCH_MINI;
                break;
            }

            if ($uintUUID == substr($cloudBuffer, 3, classConstant::UUID_DEVICE_LENGTH)) {
              $pointer = 3+classConstant::UUID_DEVICE_LENGTH+classConstant::CLOUD_ZIGBEE_LENGTH;
              $product = substr($cloudBuffer, $pointer+1, ord($cloudBuffer{$pointer}));

              $pointer += ord($cloudBuffer{$pointer});
              $manufacturer = substr($cloudBuffer, $pointer+1, classConstant::CLOUD_OSRAM_LENGTH);

              $pointer += classConstant::CLOUD_OSRAM_LENGTH+1;
              $model = substr($cloudBuffer, $pointer+1, ord($cloudBuffer{$pointer}));

              $pointer += ord($cloudBuffer{$pointer});
              $firmware = substr($cloudBuffer, $pointer+1, classConstant::CLOUD_FIRMWARE_LENGTH);

              $Devices += [
                'manufacturer' => $manufacturer,
                'deviceModel'  => $model,
                'deviceLabel'  => $label,
                'firmware'     => $firmware
              ];
            }

            $cloudBuffer = substr($cloudBuffer, $length);
          }
        }
        //IPS_LogMessage("SymconOSR", "<Gateway|GetConfigurationForParent|Devices>   ".json_encode($Devices));

        $data->elements[self::LIST_DEVICE_INDEX]->values[] = $Devices;
        $deviceList = substr($deviceList, classConstant::DATA_DEVICE_LIST);
      }
    }

    //Group list element
    if (!empty($groupList)) {
      $ncount = ord($groupList{0});
      $groupList = substr($groupList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $groupID = ord($groupList{0});
        $intUUID = $groupList{0}.$groupList{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);
        $name    = trim(substr($groupList, 2, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($groupList{18});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_GROUP_INDEX]->values[] = [
          'groupID'     => $groupID,
          'classInfo'   => $this->Translate("Group"),
          'UUID'        => $UUID,
          'groupName'   => $name,
          'information' => $info
        ];

        $groupList = substr($groupList, classConstant::DATA_GROUP_LIST);
      }
    }

    //Scene list element
    if (!empty($sceneList)) {
      $ncount = ord($sceneList{0});
      $sceneList = substr($sceneList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $intUUID = $sceneList{0}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $sceneID = ord($sceneList{0});
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);

        $sceneName = trim(substr($sceneList, 1, classConstant::DATA_NAME_LENGTH));
        $groupName = trim(substr($sceneList, 15, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($sceneList{31});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_SCENE_INDEX]->values[] = [
          'sceneID'     => $sceneID,
          'classInfo'   => $this->Translate("Scene"),
          'UUID'        => $UUID,
          'sceneName'   => $sceneName,
          'groupName'   => $groupName,
          'information' => $info
        ];

        $sceneList = substr($sceneList, classConstant::DATA_SCENE_LIST);
      }
    }

    return json_encode($data);

  }


  public function ForwardData($jsonString) {

    $parentID = $this->getParentInfo($this->InstanceID);
    $socket = ($parentID) ? IPS_GetProperty($parentID, "Open") : false;

    if ($socket && $this->ReadPropertyBoolean("active")) {
      $data = json_decode($jsonString);
      $jsonReturn = vtNoString;

      switch ($data->method) {
        case classConstant::METHOD_LOAD_CLOUD:
          if ($this->ReadPropertyInteger("connectMode") == classConstant::CONNECT_LOCAL_CLOUD) {
            $this->cloudGET($data->buffer);
          }
          break;

        case classConstant::SET_ALL_DEVICES:
          $this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_DEVICE_STATE, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $args   = str_repeat(chr(0xFF), 8).chr($buffer->state);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_DEVICE_STATE, chr(0x00), $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SAVE_LIGHT_STATE:
          $this->SetBuffer("infoDevice", $data->buffer);

          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SAVE_LIGHT_STATE, chr(0x00), $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::ACTIVATE_GROUP_SCENE:
          $buffer = json_decode($data->buffer);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::ACTIVATE_GROUP_SCENE, chr(0x00), chr($buffer->sceneID)))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_STATE:
          $this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_DEVICE_STATE, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr((int)$buffer->state);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_DEVICE_STATE, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_COLOR:
          $this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_LIGHT_COLOR, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $rgb    = $this->lightifyBase->HEX2RGB($buffer->hex);
          $args   = utf8_decode($buffer->UUID).chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0xFF).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_COLOR, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_COLOR_TEMPERATURE:
          $this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_COLOR_TEMPERATURE, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $hex    = dechex($buffer->temperature);

          if (strlen($hex) < 4) {
            $hex = str_repeat("0", 4-strlen($hex)).$hex;
          }
          $args   = utf8_decode($buffer->UUID).chr(hexdec(substr($hex, 2, 2))).chr(hexdec(substr($hex, 0, 2))).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_COLOR_TEMPERATURE, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_LEVEL:
          $this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_LIGHT_LEVEL, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr((int)$buffer->level).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_LEVEL, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_SATURATION:
          $this->SetBuffer("infoDevice", json_encode(['command' => classConstant::SET_SATURATION, 'buffer' => $data->buffer]));

          $buffer = json_decode($data->buffer);
          $rgb    = $this->lightifyBase->HEX2RGB($buffer->hex);
          $args   = utf8_decode($buffer->UUID).chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0x00).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_COLOR, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_DEVICE_NAME:
          $command = classCommand::SET_DEVICE_NAME;

        case classConstant::SET_GROUP_NAME:
          $this->SetBuffer("infoDevice", json_encode(['command' => $data->method, 'buffer' => $data->buffer]));
          if (!isset($command)) $command = classCommand::SET_GROUP_NAME;

          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).str_pad($buffer->name, classConstant::DATA_NAME_LENGTH).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw($command, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SET_SOFT_TIME:
          $buffer = json_decode($data->buffer);

          $command = ($buffer->mode == classConstant::SET_SOFT_ON) ? classCommand::SET_LIGHT_SOFT_ON : classCommand::SET_LIGHT_SOFT_OFF;
          $args    = utf8_decode($buffer->UUID).chr($buffer->time).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw($command, $buffer->flag, $args))]
          );

          $this->SendDataToParent($jsonString);
          break;

        case classConstant::METHOD_APPLY_CHILD:
          switch ($data->mode) {
            case classConstant::MODE_DEVICE_LOCAL:
              $localDevices = $this->GetBuffer("localDevices");

              if (!empty($localDevices) && ord($localDevices{0}) > 0) {
                $jsonReturn = json_encode([
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($localDevices)]
                );
              }
              return $jsonReturn;

            case classConstant::MODE_DEVICE_GROUP:
              $localGroups = $this->GetBuffer("localGroups");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($localGroups) && !empty($localGroups)) {
                $ncount = ord($localGroups{0});

                if ($ncount > 0) {
                  $groupUUID = $this->setModeDeviceGroup($ncount, substr($buffer, 1), $data->buffer);

                  $jsonReturn = json_encode([
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode($groupUUID)]
                  );
                }
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|devices:groups>   ".$jsonReturn);
              break;;

            case classConstant::MODE_DEVICE_CLOUD:
              $cloudDevices = $this->GetBuffer("cloudDevices");

              if (!empty($cloudDevices)) {
                $jsonReturn = json_encode([
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($cloudDevices)]
                );
              }
              break;;

            case classConstant::MODE_GROUP_LOCAL:
              $localGroups = $this->GetBuffer("localGroups");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($localGroups) && !empty($buffer)) {
                $ncount = ord($localGroups{0});

                if ($ncount > 0) {
                  $jsonReturn = json_encode([
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode(chr($ncount).$buffer)]
                  );
                }
              }
              break;;

            case classConstant::MODE_GROUP_SCENE:
              $cloudScenes = $this->GetBuffer("cloudScenes");

              if (!empty($cloudScenes) && ord($cloudScenes{0}) > 0) {
                $jsonReturn = json_encode([
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($cloudScenes)]
                );
              }
              break;;

            case classConstant::MODE_ALL_SWITCH:
              $allDevices = $this->getAllDevices();

              if (!empty($allDevices)) {
                $ncount = 1;

                $jsonReturn = json_encode([
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode(chr($ncount).$allDevices)]
                );
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData:all>   ".$jsonReturn);
              break;;
          }
      }
    }

    //Wait for function call
    if (!empty($jsonString)) {
      $this->SetBuffer("sendQueue", vtNoString);

      for ($x = 0; $x < 500; $x++) {
        if (!empty($this->GetBuffer("sendQueue"))) {
          $this->SetBuffer("sendQueue", vtNoString);
          break;
        }

        IPS_Sleep(10);
      }

      //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData:x>   ".$x);
    }

    return $jsonReturn;

  }


  public function ReceiveData($jsonString) {

    $this->SetBuffer("sendQueue", $jsonString);

    $localMethod = $this->GetBuffer("localMethod");
    $connect = $this->ReadPropertyInteger("connectMode");

    $this->debug   = $this->ReadPropertyInteger("debug");
    $this->message = $this->ReadPropertyBoolean("message");

    $decode = json_decode($jsonString);
    $data   = utf8_decode($decode->Buffer);

    $command = ord($data{3});
    $data    = substr($data, classConstant::BUFFER_HEADER_LENGTH + 1);

    switch ($command) {
      //Get Gateway WiFi configuration
      case classCommand::GET_GATEWAY_WIFI:
        if (strlen($data) >= (2+classConstant::DATA_WIFI_LENGTH)) {
          $ncount = ord($data{0});
          $data   = substr($data, 1);
          $result = false;

          for ($i = 1; $i <= $ncount; $i++) {
            $profile = trim(substr($data, 0, classConstant::WIFI_PROFILE_LENGTH-1));
            $SSID    = trim(substr($data, 32, classConstant::WIFI_SSID_LENGTH));
            $BSSID   = trim(substr($data, 65, classConstant::WIFI_BSSID_LENGTH));
            $channel = trim(substr($data, 71, classConstant::WIFI_CHANNEL_LENGTH));

            $ip      = ord($data{77}).".".ord($data{78}).".".ord($data{79}).".".ord($data{80});
            $gateway = ord($data{81}).".".ord($data{82}).".".ord($data{83}).".".ord($data{84});
            $netmask = ord($data{85}).".".ord($data{86}).".".ord($data{87}).".".ord($data{88});
            //$dns_1   = ord($data{89}).".".ord($data{90}).".".ord($data{91}).".".ord($data{92});
            //$dns_2   = ord($data{93}).".".ord($data{94}).".".ord($data{95}).".".ord($data{96});

            if ($this->ReadPropertyString("gatewayIP") == $ip) {
              $result = $SSID;
              break;
            }

            if (($length = strlen($data)) > classConstant::DATA_WIFI_LENGTH) {
              $length = classConstant::DATA_WIFI_LENGTH;
            }

            $data = substr($data, $length);
          }

          if ($result) {
            //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData:result>   ".$result);

            if ($this->GetValue("SSID") != $SSID) {
              $this->SetValue("SSID", (string)$SSID);
            }
          }
        }

        //Get gateway firmware version
        $jsonString = json_encode([
          'DataID' => classConstant::TX_VIRTUAL,
          'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))]
        );

        $this->SendDataToParent($jsonString);
        break;

      //Get gateway firmware version
      case classCommand::GET_GATEWAY_FIRMWARE:
        if (@$this->GetIDForIdent("FIRMWARE")) {
          $firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});
          //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData:$firmware>   ".$firmware);

          if ($this->GetValue("FIRMWARE") != $firmware) {
            $this->SetValue("FIRMWARE", (string)$firmware);
          }
        }

        //Set timer again
        $this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);
        break;

      //Get paired devices
      case classCommand::GET_DEVICE_LIST:
        $syncDevices = $syncCloud = false;

        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $sendMethod = $localMethod;

          $deviceBuffer  = vtNoString;
          $localGroups   = vtNoString;
          $cloudDevices  = vtNoString;

          $this->SetBuffer("deviceBuffer", vtNoString);
          $this->SetBuffer("deviceList", vtNoString);
          $this->SetBuffer("cloudDevices", vtNoString);
        } else {
          $sendMethod = classConstant::METHOD_UPDATE_CHILD;

          $localDevices = vtNoString;
          $deviceBuffer = $this->GetBuffer("deviceBuffer");
          $localGroups  = $this->GetBuffer("localGroups");
          $cloudDevices = $this->GetBuffer("cloudDevices");
        }

        if (strlen($data) >= (2 + classConstant::DATA_DEVICE_LENGTH)) {
          //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData:info>   ".$jsonString);
          $ncount = ord($data{0}) + ord($data{1});
          $data   = $this->structDeviceData($ncount, substr($data, 2));

          if (strcmp($data, $deviceBuffer) != 0) {
            //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Devices:buffer>   ".json_encode(utf8_encode($deviceBuffer)));
            $syncDevices = true;

            $ncount = ord($data{0});
            $localDevices = $this->structLightifyData(classCommand::GET_DEVICE_LIST, $ncount, substr($data, 2), $deviceBuffer);

            $this->SetBuffer("deviceBuffer", $data);
            $this->SetBuffer("localDevices", $localDevices);
          }

          //Get cloud devices
          if (!empty($localDevices)) {
            $ncount = ord($localDevices{0});

            if ($ncount > 0) {
              if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadPropertyString("osramToken")) && empty($cloudDevices)) {
                $syncCloud = true;

                $cloudDevices = $this->structLightifyData(classConstant::GET_DEVICE_CLOUD, $ncount, substr($localDevices, 2));
                $this->SetBuffer("cloudDevices", $cloudDevices);
              }

              //Create devices
              if ($sendMethod == classConstant::METHOD_CREATE_CHILD) {
                //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Devices:create>   ".json_encode(utf8_encode($localDevices)));
                $this->createInstance(classConstant::MODE_CREATE_DEVICE, vtNoValue, $ncount, substr($localDevices, 2));
              }
            }
          }


          //Update device informations
          if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
            $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE));

            if ($mcount > 0) {
              if ($syncDevices && !empty($localDevices) && ord($localDevices{0}) > 0) {
                //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Devices:update>   ".json_encode(utf8_encode($localDevices)));

                $this->SendDataToChildren(json_encode([
                  'DataID' => classConstant::TX_DEVICE,
                  'id'     => $this->InstanceID,
                  'mode'   => classConstant::MODE_DEVICE_LOCAL,
                  'method' => $sendMethod,
                  'buffer' => utf8_encode($localDevices)])
                );
              }

              //Update cloud informations
              if ($syncCloud && !empty($cloudDevices) && ord($cloudDevices{0}) > 0) {
                //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Scenes>   ".json_encode(utf8_encode($cloudScenes)));
                $this->SendDataToChildren(json_encode([
                  'DataID'  => classConstant::TX_DEVICE,
                  'id'      => $this->InstanceID,
                  'mode'    => classConstant::MODE_DEVICE_CLOUD,
                  'method'  => classConstant::METHOD_UPDATE_CHILD,
                  'buffer'  => utf8_encode($cloudDevices)])
                );
              }
            }
          }

          //Get group list
          if ($Categories = $this->ReadPropertyString("listCategory")) {
            list(, , $groupCategory) = json_decode($Categories);
            $syncGroups = (($groupCategory->categoryID > 0) && $groupCategory->syncID) ? true : false;
          }

          //Update group informations
          if ($syncGroups) {
            if ($syncDevices) {
              if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
                $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP));

                if ($mcount > 0) {
                  $groupDevices = $this->GetBuffer("groupBuffer");

                  if (!empty($localGroups) && ord($localGroups{0}) > 0 && !empty($groupDevices)) {
                    //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Groups:Devices>   ".json_encode(utf8_encode($groupDevices)));

                    $this->SendDataToChildren(json_encode([
                      'DataID'  => classConstant::TX_GROUP,
                      'id'      => $this->InstanceID,
                      'mode'    => classConstant::MODE_GROUP_LOCAL,
                      'method'  => $sendMethod,
                      'buffer'  => utf8_encode(ord($localGroups{0}).$groupDevices)])
                    );
                  }

                  //Update 'All Lights' dummy switch group
                  $allDevices = $this->getAllDevices();

                  if (!empty($allDevices)) {
                    //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|All:Devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
                    $ncount = 1;

                    $this->SendDataToChildren(json_encode([
                      'DataID'  => classConstant::TX_GROUP,
                      'id'      => $this->InstanceID,
                      'mode'    => classConstant::MODE_ALL_SWITCH,
                      'method'  => $sendMethod,
                      'buffer' => utf8_encode(chr($ncount).$allDevices)])
                    );
                  }
                }
              }
            }

            if (empty($localGroups)) {
              $jsonString = json_encode([
                'DataID' => classConstant::TX_VIRTUAL,
                'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_GROUP_LIST, chr(0x00)))]
              );

              return $this->SendDataToParent($jsonString);
            }
          } else {
            $this->SetBuffer("groupList", vtNoString);
            $this->SetBuffer("groupDevice", vtNoString);

            $this->SetBuffer("sceneList", vtNoString);
          }
        }

        //Set timer again
        $this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);
        break;

      case classCommand::GET_GROUP_LIST:
        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $sendMethod = $localMethod;

          $groupBuffer = vtNoString;
          $sceneBuffer = vtNoString;

          $this->SetBuffer("groupBuffer", vtNoString);
          $this->SetBuffer("sceneBuffer", vtNoString);

          $this->SetBuffer("groupList", vtNoString);
          $this->SetBuffer("sceneList", vtNoString);
        } else {
          $sendMethod = classConstant::METHOD_UPDATE_CHILD;

          $groupBuffer = $this->GetBuffer("groupBuffer");
          $sceneBuffer = $this->GetBuffer("sceneBuffer");
          $cloudScenes = $this->GetBuffer("cloudScenes");
        }

        if (strlen($data) >= (2 + classConstant::DATA_GROUP_LENGTH)) {
          $ncount = ord($data{0}) + ord($data{1});
          $localGroups = chr($ncount).substr($data, 2);

          if (!empty($localGroups)) {
            $ncount = ord($localGroups{0});

            $groupDevices = $this->structLightifyData(classCommand::GET_GROUP_LIST, $ncount, substr($localGroups, 1), $groupBuffer);
            $this->SetBuffer("groupBuffer", $groupDevices);

            if ($Categories = $this->ReadPropertyString("listCategory")) {
              list(, , $groupCategory, $sceneCategory) = json_decode($Categories);
              $syncScene = (($sceneCategory->categoryID > 0) && $sceneCategory->syncID) ? true : false;
            }

            //Create groups
            if ($sendMethod == classConstant::METHOD_CREATE_CHILD) {
              if ($ncount > 0) {
                $this->createInstance(classConstant::MODE_CREATE_GROUP, $groupCategory->categoryID, $ncount, substr($localGroups, 1));
              }

              //Create 'All Lights' dummy switch group
              $this->createInstance(classConstant::MODE_CREATE_ALL_SWITCH, $groupCategory->categoryID, vtNoValue, substr($localGroups, 1));
            }

            //Update group informations
            if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
              $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP));

              if ($mcount > 0) {
                if (!empty($localGroups) && ord($localGroups{0}) > 0 && !empty($groupDevices)) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Groups>   ".json_encode(utf8_encode($groupDevices)));

                  $this->SendDataToChildren(json_encode([
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'mode'    => classConstant::MODE_GROUP_LOCAL,
                    'method'  => $sendMethod,
                    'buffer'  => utf8_encode(ord($localGroups{0}).$groupDevices)])
                  );
                }

                //Update 'All Lights' dummy switch group
                $allDevices = $this->getAllDevices();

                if (!empty($allDevices)) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|all:Devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
                  $ncount = 1;

                  $this->SendDataToChildren(json_encode([
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'mode'    => classConstant::MODE_ALL_SWITCH,
                    'method'  => $sendMethod,
                    'buffer' => utf8_encode(chr($ncount).$allDevices)])
                  );
                }
              }
            }

            if ($syncScene) {
              if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadPropertyString("osramToken"))) {
                $ncount = ord($groupDevices{0});

                $cloudGroups = $this->structLightifyData(classConstant::GET_GROUP_CLOUD, $ncount);
                $this->SetBuffer("cloudGroups", $cloudGroups);

                if (!empty($cloudGroups)) {
                  $cloudScenes = $this->structLightifyData(classConstant::GET_GROUP_SCENE, vtNoValue, vtNoString, $sceneBuffer);
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Scenes>   ".json_encode(utf8_encode($cloudScenes)));
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Scenes:buffer>   ".json_encode(utf8_encode($sceneBuffer)));

                  if (strcmp($cloudScenes, $sceneBuffer) != 0) {
                    $this->SetBuffer("cloudScenes", $cloudScenes);
                    $this->SetBuffer("sceneBuffer", $cloudScenes);

                    if (!empty($cloudScenes) && ord($cloudScenes{0}) > 0) {
                      //Create scenes
                      if ($sendMethod == classConstant::METHOD_CREATE_CHILD) {
                        $this->createInstance(classConstant::MODE_CREATE_SCENE, $sceneCategory->categoryID, ord($cloudScenes{0}), substr($cloudScenes, 1));
                      }

                      //Update scene informations
                      if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
                        //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Scenes>   ".json_encode(utf8_encode($cloudScenes)));
                        $this->SendDataToChildren(json_encode([
                          'DataID'  => classConstant::TX_GROUP,
                          'id'      => $this->InstanceID,
                          'mode'    => classConstant::MODE_GROUP_SCENE,
                          'method'  => $sendMethod,
                          'buffer'  => utf8_encode($cloudScenes)])
                        );
                      }
                    }
                  }
                } else {
                  $this->SetBuffer("sceneList", vtNoString);
                }
              }
            }
          }
        }

        //Set timer again
        $this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);
        break;

      default:
        if ($this->ReadPropertyBoolean("waitResult")) {
          $infoDevice = $this->GetBuffer("infoDevice");

          if (!empty($infoDevice)) {
            $this->SetBuffer("infoDevice", vtNoString);

            $data   = json_decode($jsonString);
            $buffer = utf8_decode($data->Buffer);

            if (ord($buffer{0}) > classConstant::BUFFER_HEADER_LENGTH) {
              $code = ord($buffer{classConstant::BUFFER_HEADER_LENGTH});

              if ($code == 0) {
                if ($this->debug % 2 || $this->message) {
                  $info = $this->lightifyBase->decodeData($buffer);

                  if ($this->debug % 2) {
                    $this->SendDebug("<Gateway|ReceiveData|default>", $info, 0);
                  }

                  if ($this->message) {
                    IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|default>   ".$info);
                  }
                }

                $data = json_decode($infoDevice);
                $args = json_decode($data->buffer);

                switch ($data->command) {
                  case classCommand::SET_DEVICE_STATE:
                    SetValue($args->stateID, $args->state);
                    break;

                  case classCommand::SET_LIGHT_COLOR:
                    SetValue($args->colorID, $args->color);

                    if (GetValue($args->hueID) != $args->hsv['h']) {
                      SetValue($args->hueID, $args->hsv['h']);
                    }

                    if (GetValue($args->saturationID) != $args->hsv['s']) {
                      SetValue($args->saturationID, $args->hsv['s']);
                    }
                    break;

                  case classCommand::SET_COLOR_TEMPERATURE:
                    SetValue($args->temperatureID, $args->temperature);
                    break;

                  case classCommand::SET_LIGHT_LEVEL:
                    if ($args->light && $args->stateID) {
                      if ($args->level == 0) {
                        SetValue($args->stateID, false);
                      } else {
                        SetValue($args->stateID, true);
                      }
                    }

                    SetValue($args->levelID, $args->level);
                    break;

                  case classConstant::SET_SATURATION:
                    SetValue($args->saturationID, $args->saturation);

                    if (GetValue($args->colorID) != $args->color) {
                      SetValue($args->colorID, $args->color);
                    }
                    break;

                  case classConstant::SET_DEVICE_NAME:
                  case classConstant::SET_GROUP_NAME:
                    if (IPS_GetName($args->id) != $args->name) {
                      IPS_SetName($args->id, (string)$args->name);
                    }
                    break;
                }
              }
            }
          }
        }
    }

  }


  private function validateConfig(bool $active, int $connect) : bool {

    $localUpdate = $this->ReadPropertyInteger("localUpdate");
    $filterIP    = filter_var($this->ReadPropertyString("gatewayIP"), FILTER_VALIDATE_IP);

    if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
      if (strlen($this->ReadPropertyString("serialNumber")) != self::GATEWAY_SERIAL_LENGTH) {
        $this->SetStatus(203);
        return false;
      }
    }

    if ($filterIP) {
      if ($localUpdate < TIMER_SYNC_LOCAL_MIN) {
        $this->SetStatus(205);
        return false;
      }
    } else {
      $this->SetStatus(202); //IP error
      return false;
    }

    if ($active) {
      $this->SetStatus(102);
      return true;
    } else {
      $this->SetStatus(201);
      return false;
    }

  }


  public function LightifyRegister() {

    if ($this->ReadPropertyInteger("connectMode") == classConstant::CONNECT_LOCAL_CLOUD) {
      //Return everything which will open the browser
      return self::OAUTH_AUTHORIZE.$this->oAuthIdent."?username=".urlencode(IPS_GetLicensee());
    }

    echo $this->Translate("Lightify API registration available in cloud connection mode only!")."\n";
  }


  protected function ProcessOAuthData() {

    if ($_SERVER['REQUEST_METHOD'] == "GET") {
      if (isset($_GET['code'])) {
        return $this->getAccessToken($_GET['code']);
      } else {
        $error = $this->Translate("Authorization code expected!");

        $this->SendDebug("<Gateway|ProcessOAuthData:error>", $error, 0);
        IPS_LogMessage("SymconOSR", "<Gateway|ProcessOAuthData:error>   ".$error);
      }
    }

    return false;

  }


  private function getAccessToken(string $code) : bool {

    $debug   = $this->ReadPropertyInteger("debug");
    $message = $this->ReadPropertyBoolean("message");
    //IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:code>   ".$code);

    //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
    $cURL    = curl_init();
    $options = [
      CURLOPT_URL            => self::OAUTH_ACCESS_TOKEN.$this->oAuthIdent,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => "POST",
      CURLOPT_POSTFIELDS     => "code=".$code,
      CURLOPT_HTTPHEADER     => [
        self::HEADER_FORM_CONTENT
      ]
    ];

    curl_setopt_array($cURL, $options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    $data   = json_decode($result);
    curl_close($cURL);

    if ($debug % 2) {
      $this->SendDebug("<Gateway|getAccessToken:result>", $result, 0);
    }

    if ($message) {
      IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:result>   ".$result);
    }

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      $this->SetBuffer("applyMode", 0);

      if ($debug % 2) {
        $this->SendDebug("<Gateway|getAccessToken:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|getAccessToken:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|getAccessToken:refresh>", $data->refresh_token, 0);
      }

      if ($message) {
        IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:access>   ".$data->access_token);
        IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:expires>   ".date("Y-m-d H:i:s", time() + $data->expires_in));
        IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:refresh>   ".$data->refresh_token);
      }

      $buffer = json_encode([
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token]
      );

      IPS_SetProperty($this->InstanceID, "osramToken", $buffer);
      IPS_ApplyChanges($this->InstanceID);

      return true;
    }

    $this->SendDebug("<Gateway|getAccessToken:error>", $result, 0);
    IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:error>   ".$result);

    return false;

  }


  private function getRefreshToken() : string {

    $osramToken = $this->ReadPropertyString("osramToken");

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|getRefreshToken:token>", $osramToken, 0);
    }

    if ($this->message) {
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:token>   ".$osramToken);
    }

    //Exchange our refresh token for a temporary access token
    $data = json_decode($osramToken);

    if (!empty($data) && time() < $data->expires_in) {
      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|getRefreshToken:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|getRefreshToken:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|getRefreshToken:refresh>", $data->refresh_token, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:access>   ".$data->access_token);
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:expires>   ".date("Y-m-d H:i:s", $data->expires_in));
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:refresh>   ".$data->refresh_token);
      }

      return $data->access_token;
    }

    $cURL    = curl_init();
    $options = [
      CURLOPT_URL            => self::OAUTH_ACCESS_TOKEN.$this->oAuthIdent,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_MAXREDIRS      => 10,
      CURLOPT_TIMEOUT        => 30,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => "POST",
      CURLOPT_POSTFIELDS     => "refresh_token=".$data->refresh_token,
      CURLOPT_HTTPHEADER     => [
        self::HEADER_FORM_CONTENT
      ]
    ];

    curl_setopt_array($cURL, $options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    $data   = json_decode($result);
    curl_close($cURL);

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|getRefreshToken:result>", $result, 0);
    }

    if ($this->message) {
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:result>   ".$result);
    }

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|getRefreshToken:result>", $result, 0);
    }

    if ($this->message) {
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:result>   ".$result);
    }

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      //Update parameters to properly cache them in the next step
      $this->SetBuffer("applyMode", 0);

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|getRefreshToken:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|getRefreshToken:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|getRefreshToken:refresh>", $data->refresh_token, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:access>   ".$data->access_token);
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:expires>   ".date("Y-m-d H:i:s", time() + $data->expires_in));
        IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:refresh>   ".$data->refresh_token);
      }

      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:access>   ".$data->access_token);
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:expires>   ".date("Y-m-d H:i:s", time() + $data->expires_in));
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:refresh>   ".$data->refresh_token);

      $buffer = json_encode([
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token]
      );

      IPS_SetProperty($this->InstanceID, "osramToken", $buffer);
      IPS_ApplyChanges($this->InstanceID);

      return $data->access_token;
    } else {
      $this->SendDebug("<Gateway|getRefreshToken:error>", $result, 0);
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:error>   ".$result);

      return vtNoString;
    }

  }


  protected function cloudGET(string $url) : string {

    return $this->cloudRequest("GET", $url);

  }


  protected function cloudPATCH(string $ressource, string $args) : string {

    return $this->cloudRequest("PATCH", $ressource, $args);

  }


  private function cloudRequest(string $request, string $ressource, string $args = vtNoString) : string {

    $accessToken = $this->getRefreshToken();
    if (empty($accessToken)) return vtNoString;

    $cURL    = curl_init();
    $options = [
      CURLOPT_URL            => self::LIGHTIFY_EUROPE.self::LIGHTIFY_VERSION.$ressource,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_MAXREDIRS      => self::LIGHTIFY_MAXREDIRS,
      CURLOPT_TIMEOUT        => self::LIGHTIFY_TIMEOUT,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => $request,
      CURLOPT_HTTPHEADER     => [
        self::HEADER_AUTHORIZATION.$accessToken,
        self::HEADER_JSON_CONTENT
      ]
    ];

    curl_setopt_array($cURL, $options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    curl_close($cURL);

    if (!$result || $error) {
      $this->SendDebug("<Gateway|cloudRequest:error>", $error, 0);
      IPS_LogMessage("SymconOSR", "<Gateway|cloudRequest:error>   ".$error);

      return vtNoString;
    }

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|cloudRequest:result>", $result, 0);
    }

    if ($this->message) {
      IPS_LogMessage("SymconOSR", "<Gateway|cloudRequest:result>   ".$result);
    }

    return $result;

  }


  protected function sendRaw(int $command, string $flag, string $args = vtNoString) : string {

    $debug   = $this->ReadPropertyInteger("debug");
    $message = $this->ReadPropertyBoolean("message");

    //$this->requestID = ($this->requestID == classConstant::REQUESTID_HIGH) ? 1 : $this->requestID+1;
    //$data = $flag.chr($command).$this->lightifyBase->getRequestID($this->requestID);
    $data = $flag.chr($command).chr(0x00).chr(0x00).chr(0x00).chr(0x00);

    if (!empty($args)) {
      $data .= $args;
    }

    $data   = chr(strlen($data)).chr(0x00).$data;
    $length = strlen($data);

    if ($this->debug % 4 || $this->message) {
      $info = strtoupper(dechex($command)).":".hexdec($flag).":".$length."/".$this->lightifyBase->decodeData($data);

      if ($debug % 4) {
        IPS_SendDebug($this->parentID, "<Gateway|sendRaw:write>", $info, 0);
      }

      if ($message) {
        IPS_LogMessage("SymconOSR", "<Gateway|sendRaw:write>   ".$info);
      }
    }

    return $data;

  }


  public function GetLightifyData(int $localMethod) : void {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $parentID = $this->getParentInfo($this->InstanceID);
    $socket = ($parentID) ? IPS_GetProperty($parentID, "Open") : false;

    if ($socket && $this->ReadPropertyBoolean("active")) {
      $this->SetTimerInterval("localTimer", 0);
      $this->SetBuffer("localMethod", $localMethod);

      $firmwareID = @$this->GetIDForIdent("FIRMWARE");
      $portID     = @$this->GetIDForIdent("PORT");
      $ssidID     = @$this->GetIDForIdent("SSID");

      if ($localMethod == classConstant::METHOD_APPLY_LOCAL) {
        if (!$ssidID) {
          if (false !== ($ssidID = $this->RegisterVariableString("SSID", "SSID", vtNoString, 301))) {
            SetValueString($ssidID, vtNoString);
            IPS_SetDisabled($ssidID, true);
          }
        }

        if (!$portID) {
          if (false !== ($portID = $this->RegisterVariableInteger("PORT", "Port", vtNoString, 303))) {
            SetValueInteger($portID, classConstant::GATEWAY_PORT);
            IPS_SetDisabled($portID, true);
          }
        }

        if (!$firmwareID) {
          if (false !== ($firmwareID = $this->RegisterVariableString("FIRMWARE", $this->Translate("Firmware"), vtNoString, 304))) {
            SetValueString($firmwareID, "-.-.-.--");
            IPS_SetDisabled($firmwareID, true);
          }
        }

        //Get Gateway WiFi configuration
        if ($ssidID) {
          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_GATEWAY_WIFI, chr(classConstant::SCAN_WIFI_CONFIG)))]
          );

          $this->SendDataToParent($jsonString);
          return;
        }
      } else {
        //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Method>   ".$localMethod);
        $syncDevices = $syncSensor = false;

        //Get paired devices
        if ($Categories = $this->ReadPropertyString("listCategory")) {
          list($deviceCategory, $sensorCategory) = json_decode($Categories);

          $syncDevices = (($deviceCategory->categoryID > 0) && $deviceCategory->syncID) ? true : false;
          $syncSensor = (($sensorCategory->categoryID > 0) && $sensorCategory->syncID) ? true : false;
        }

        if ($syncDevices || $syncSensor) {
          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01)))]
          );

          $this->SendDataToParent($jsonString);
          return;
        } else {
          $this->SetBuffer("deviceList", vtNoString);
          $this->SetBuffer("deviceGroup", vtNoString);
        }
      }

      //Set timer again
      $this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);
    }

  }


  private function structDeviceData(int $ncount, string $data) : string {

    $localDevices = vtNoString;
    $localGroups  = vtNoString;
    $deviceList   = vtNoString;

    for ($i = 1, $j = 0, $n = 0, $m = 0; $i <= $ncount; $i++) {
      $uintUUID = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
      $deviceID = chr($i);

      $class   = ord($data{10});
      $known   = true;
      $noGroup = false;
      unset($info);

      //Decode device info
      switch ($class) {
        case classConstant::TYPE_FIXED_WHITE:
        case classConstant::TYPE_LIGHT_CCT:
        case classConstant::TYPE_LIGHT_DIMABLE:
        case classConstant::TYPE_LIGHT_COLOR:
        case classConstant::TYPE_LIGHT_EXT_COLOR:
          $info = $this->Translate("Light");

        case classConstant::TYPE_PLUG_ONOFF:
          if (!isset($info))  $info  = $this->Translate("Plug");
          break;

        case classConstant::TYPE_SENSOR_MOTION:
          if (!isset($info))  $info = $this->Translate("Sensor");

        case classConstant::TYPE_DIMMER_2WAY:
          if (!isset($info))  $info  = $this->Translate("Dimmer");

        case classConstant::TYPE_SWITCH_4WAY:
          if (!isset($info))  $info  = $this->Translate("Switch");

        case classConstant::TYPE_SWITCH_MINI:
          if (!isset($info))  $info  = $this->Translate("Switch");

          $noGroup = true;
          break;

        default:
          $known = false;

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structDeviceData|devices:local>   Device type <".$type."> unknown!");
          }
      }
      if (!$known) continue;

      $info = str_pad($info, classConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);
      $name = substr($data, 26, classConstant::DATA_NAME_LENGTH);

      $online = $data{15};
      $state  = $data{18};

      $zigBee = substr($data, 0, classConstant::OSRAM_ZIGBEE_LENGTH);
      $group  = substr($data, 16, classConstant::OSRAM_GROUP_LENGTH);

      $white  = $data{25};
      $red    = $data{22};
      $green  = $data{23};
      $blue   = $data{24};

      $temperature = $data{21}.$data{20};
      $level = $data{19};

      //$Devices .= $zigBee."-d".substr($data, 2, classConstant::DATA_DEVICE_LENGTH-classConstant::OSRAM_ZIGBEE_LENGTH);
      $deviceList   .= $deviceID.$uintUUID.$name.$info;
      $localDevices .= "-d".$uintUUID.$zigBee.substr($data, classConstant::OSRAM_ZIGBEE_LENGTH+classConstant::UUID_DEVICE_LENGTH, classConstant::DATA_DEVICE_LENGTH-classConstant::OSRAM_ZIGBEE_LENGTH-classConstant::UUID_DEVICE_LENGTH);
      $j += 1;

      //Device group
      if (!$noGroup) {
        $localGroups .= "-d".$uintUUID.substr($data, 16, classConstant::OSRAM_GROUP_LENGTH);
        $m += 1; 
      }

      if (($length = strlen($data)) > classConstant::DATA_DEVICE_LOADED) {
        $length = classConstant::DATA_DEVICE_LOADED;
      }

      //IPS_LogMessage("SymconOSR", "<Gateway|structDeviceData|Devices>   ".$i." ".strlen($Devices)." ".$name." ".json_encode(utf8_encode($localDevices)));
      $data = substr($data, $length);
    }

    //Store at runtime
    $this->SetBuffer("deviceList", chr($j).$deviceList);
    $this->SetBuffer("deviceGroup", chr($m).$localGroups);

    //IPS_LogMessage("SymconOSR", "<Gateway|structDeviceData|devices:length>   ".$i."/".($i*strlen(chr($j).chr($i).$Devices))/2014);
    return chr($j).chr($i).$localDevices;

  }


  private function structLightifyData(int $command, int $ncount = vtNoValue, string $data = vtNoString, string $buffer = vtNoString) : string {

    switch ($command) {
      case classCommand::GET_DEVICE_LIST:
        $localDevices = vtNoString;

        //Parse devices
        for ($i = 1, $j = 0, $k = 0, $n = 0; $i <= $ncount; $i++, $j++) {
          $data = substr($data, 2);
          $name = substr($data, 26, classConstant::DATA_NAME_LENGTH);

          $trunk = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
          $index = stripos($buffer, $trunk);

          if ($index === false || ($index && substr_compare($buffer, $trunk, $index, classConstant::DATA_DEVICE_LENGTH))) {
            $localDevices .= "-d".$trunk;
            $k += 1;
          }

          $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
        }

        if ($this->debug % 2 || $this->message) {
          $info = ($k > 0) ? $k.":".$i."/".$this->lightifyBase->decodeData($localDevices) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|devices:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:local>   ".$info);
          }
        }

        //Return device buffer string
        return chr($k).chr($i).$localDevices;
        break;

      case classConstant::GET_DEVICE_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_DEVICES);
        if (empty($cloudBuffer)) return vtNoString;

        $cloudBuffer = json_decode($cloudBuffer);
        $gateway = $cloudBuffer->devices[0];

        if ($gateway->name == strtoupper($this->ReadPropertyString("serialNumber"))) {
          $cloudDevices = vtNoString;

          $gatewayID = $gateway->id;
          unset($cloudBuffer->devices[0]);

          for ($i = 1, $j = 0; $i <= $ncount; $i++) {
            $data = substr($data, 2);

            $uintUUID = substr($data, 0, classConstant::UUID_DEVICE_LENGTH);
            $name = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
            $type = $data{10};

            foreach ($cloudBuffer->devices as $array => $device) {
              $cloudID = $gatewayID."-d".$uintUUID;

              if ($name == trim($device->name)) {
                $zigBee = dechex(ord($data{8})).dechex(ord($data{9}));
                $model  = strtoupper($device->deviceModel);
                $label  = "-";
                $j += 1;

                //Modell mapping
                //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Devices:model>   ".$name." ".$model);

                if (substr($model, 0, 10) == "CLA60 RGBW") {
                  $model = "CLASSIC A60 RGBW";
                }

                if (substr($model, 0, 8) == "CLA60 TW") {
                  $model = "CLASSIC A60 TW";
                }

                if (substr($model, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $model = "CLASSIC A60 W CLEAR";
                }

                if (substr($model, 0, 4) == "PLUG") {
                  $model = classConstant::MODEL_PLUG_ONOFF;
                }

                $trunk  = $type."-d".$uintUUID.$zigBee.chr(strlen($device->type)).$device->type.classConstant::MODEL_MANUFACTURER;
                $trunk .= chr(strlen($model)).$model.$device->firmwareVersion;
                //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Devices:trunk>   ".$name." ".ord($type)." ".$zigBee." ".strlen($trunk)." ".$trunk);

                $cloudDevices .= chr(strlen($trunk)).$trunk;
                break;
              }
            }

            $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
          }

          if (!empty($cloudDevices)) {
            $info = json_encode($cloudDevices);

            if ($this->debug % 2 || $this->message) {
              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|Devices:buffer>", $buffer, 0);
                $this->SendDebug("<Gateway|structLightifyData|Devices:cloud>", $info, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Devices:buffer>   ".$buffer);
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Devices:cloud>   ".$info);
              }
            }

            //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices>   ".json_encode(utf8_encode($cloudDevices)));
            return chr($j).$cloudDevices;
          }
        }
        break;

      case classCommand::GET_GROUP_LIST:
        $localGroups  = vtNoString;
        $groupDevices = vtNoString;
        $groupList    = vtNoString;

        for ($i = 1; $i <= $ncount; $i++) {
          $groupID = ord($data{0});
          $trunk   = substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $index   = stripos($buffer, $trunk);

          $buffer = $this->GetBuffer("deviceGroup");
          $dcount = ord($buffer{0});

          $groupUUID = vtNoString;
          $n = 0;

          if ($dcount > 0) {
            $buffer = substr($buffer, 1);

            for ($j = 1; $j <= $dcount; $j++) {
              $buffer = substr($buffer, 2);
              $decode = $this->lightifyBase->decodeGroup(ord($buffer{8}), ord($buffer{9}));
              //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Groups:local>   ".$dcount." - ".$groupID." - ".json_encode($decode));

              foreach ($decode as $key) {
                if ($groupID == $key) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($buffer, 0, classConstant::UUID_DEVICE_LENGTH)));
                  $uintUUID   = substr($buffer, 0, classConstant::UUID_DEVICE_LENGTH);
                  $groupUUID .= "-d".$uintUUID;
                  $n += 1;
                  break;
                }
              }

              $buffer = substr($buffer, classConstant::DATA_GROUP_DEVICE);
            }
          }

          $localGroups   = substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $groupDevices .= "-g".chr($groupID).chr($n).$groupUUID;
          $groupList    .= substr($data, 0, classConstant::DATA_GROUP_LENGTH).chr($n);

          if (($length = strlen($data)) > classConstant::DATA_GROUP_LENGTH) {
            $length = classConstant::DATA_GROUP_LENGTH;
          }

          $data = substr($data, $length);
        }

        //Store at runtime
        $this->SetBuffer("localGroups", chr($ncount).$localGroups);
        $this->SetBuffer("groupList", chr($ncount).$groupList);

        if ($this->debug % 2 || $this->message) {
          $info = ($ncount > 0) ? $ncount.":".classConstant::TYPE_DEVICE_GROUP."/".$this->lightifyBase->decodeData($localGroups) : "0:0/null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|Groups:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Groups:local>   ".$info);
          }
        }

        //Return group buffer string
        return chr(classConstant::TYPE_DEVICE_GROUP).$groupDevices;
        break;

      case classConstant::GET_GROUP_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_GROUPS);
        if (empty($cloudBuffer)) return vtNoString;

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|structLightifyData|Groups:cloud>", $cloudBuffer, 0);
        }

        if ($this->message) {
          IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Groups:cloud>   ".$cloudBuffer);
        }
        return $cloudBuffer;

      case classConstant::GET_GROUP_SCENE:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_SCENES);
        if (empty($cloudBuffer)) return vtNoString;

        $cloudGroups = $this->GetBuffer("cloudGroups");
        $cloudBuffer = json_decode($cloudBuffer);

        if (!empty($cloudGroups)) {
          $cloudGroups = json_decode($cloudGroups);
          $cloudScenes = vtNoString;
          $sceneList   = vtNoString;
          $i = 0;

          foreach ($cloudGroups->groups as $group) {
            $groupScenes = $group->scenes;
            $groupName   = str_pad($group->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);

            if (!empty($groupScenes)) {
              $j = 0;

              foreach ($groupScenes as $sceneID) {
                foreach ($cloudBuffer->scenes as $scene) {
                  if ($sceneID == $scene->id) {
                    $groupID = (int)substr($group->id, -2);
                    $sceneID = (int)substr($scene->id, -2);

                    $sceneName    = str_pad($scene->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);
                    $cloudScenes .= "-g".chr($groupID)."-s".chr($sceneID).$sceneName;
                    $sceneList   .= chr($sceneID).$sceneName.$groupName.chr(count($group->devices));

                    $i += 1; $j += 1;
                    break;
                  }
                }
              }
            }
          }

          //Store at runtime
          if (!empty($sceneList)) {
            $this->SetBuffer("sceneList", chr($i).$sceneList);
          }

          if (!empty($cloudScenes)) {
            if ($this->debug % 2 || $this->message) {
              $info = $i.":".classConstant::TYPE_GROUP_SCENE."/".$this->lightifyBase->decodeData($cloudScenes);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|Scenes:cloud>", $info, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|Scenes:cloud>   ".$info);
              }
            }

            return chr($i).chr(classConstant::TYPE_GROUP_SCENE).$cloudScenes;
          }
        }
        break;
    }

    return vtNoString;

  }


  private function createInstance(int $mode, int $categoryID, int $ncount = vtNoValue, string $data = vtNoString) : void {

    switch ($mode) {
      case classConstant::MODE_CREATE_DEVICE:
        $Categories = $this->ReadPropertyString("listCategory");
        list($deviceCategory, $sensorCategory) = json_decode($Categories);

        for ($i = 1; $i <= $ncount; $i++) {
          $data = substr($data, 2);
          $deviceID = $i;

          $type  = ord($data{10});
          $coded = true;

          switch ($type) {
            case classConstant::TYPE_PLUG_ONOFF:
              $class = classConstant::CLASS_LIGHTIFY_PLUG;
              $categoryID = ($deviceCategory->syncID) ? $deviceCategory->categoryID : false;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $class = classConstant::CLASS_LIGHTIFY_SENSOR;
              $categoryID = ($sensorCategory->syncID) ? $sensorCategory->categoryID : false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
            case classConstant::TYPE_SWITCH_4WAY:
            case classConstant::TYPE_SWITCH_MINI:
              $coded = false;
              break;

            default:
              $class = classConstant::CLASS_LIGHTIFY_LIGHT;
              $categoryID = ($deviceCategory->syncID) ? $deviceCategory->categoryID : false;
          }

          if ($coded && $categoryID) {
            $type = ord($data{10});

            //$uintUUID   = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $uintUUID   = substr($data, 0, classConstant::UUID_DEVICE_LENGTH);
            $InstanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
            $UUID       = $this->lightifyBase->ChrToUUID($uintUUID);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_DEVICE);

              $name = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
              $name = (!empty($name)) ? $name : "-Unknown-";
              //IPS_LogMessage("SymconOSR", "<createInstance|Devices>   ".$i." ".$deviceID." ".$type." ".$name." ".$uintUUID);

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetPosition($InstanceID, 210+$deviceID);
              IPS_SetName($InstanceID, $name);

              IPS_SetProperty($InstanceID, "uintUUID", $uintUUID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "itemClass") != $class) {
                IPS_SetProperty($InstanceID, "itemClass", (int)$class);
              }

              if (@IPS_GetProperty($InstanceID, "classType") != $type) {
                IPS_SetProperty($InstanceID, "classType", (int)$type);
              }

              if (@IPS_GetProperty($InstanceID, "UUID") != $UUID) {
                IPS_SetProperty($InstanceID, "UUID", $UUID);
              }

              //if (IPS_HasChanges($InstanceID)) {
                IPS_ApplyChanges($InstanceID);
              //}
            }
          }

          $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
        }
        break;

      case classConstant::MODE_CREATE_GROUP:
        for ($i = 1; $i <= $ncount; $i++) {
          $groupID = ord($data{0});

          $uintUUID   = $data{0}.$data{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
          $InstanceID = $this->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);

          if (!$InstanceID) {
            $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

            $name  = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
            $name = (!empty($name)) ? $name : "-Unknown-";

            IPS_SetParent($InstanceID, $categoryID);
            IPS_SetPosition($InstanceID, 210+$groupID);
            IPS_SetName($InstanceID, $name);

            IPS_SetProperty($InstanceID, "groupID", (int)$groupID);
          }

          if ($InstanceID) {
            if (@IPS_GetProperty($InstanceID, "itemClass") != classConstant::CLASS_LIGHTIFY_GROUP) {
              IPS_SetProperty($InstanceID, "itemClass", classConstant::CLASS_LIGHTIFY_GROUP);
            }

            if (@IPS_GetProperty($InstanceID, "uintUUID") != $uintUUID) {
              IPS_SetProperty($InstanceID,"uintUUID", $uintUUID);
            }

            if (@IPS_GetProperty($InstanceID, "classType") != classConstant::TYPE_DEVICE_GROUP) {
              IPS_SetProperty($InstanceID, "classType", classConstant::TYPE_DEVICE_GROUP);
            }

            if (IPS_HasChanges($InstanceID)) {
              IPS_ApplyChanges($InstanceID);
            }
          }

          $data = substr($data, classConstant::DATA_GROUP_LENGTH);
        }
        break;

      case classConstant::MODE_CREATE_SCENE:
        $data = substr($data, 1); //cut classType

        for ($i = 1, $j = 0; $i <= $ncount; $i++, $j++) {
          $sceneID = ord($data{5});

          $uintUUID   = $data{5}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
          $InstanceID = $this->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);
          $data = substr($data, 6);

          if (!$InstanceID) {
            $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

            $name = trim(substr($data, 0, classConstant::DATA_NAME_LENGTH));
            $name = (!empty($name)) ? $name : "-Unknown-";
            //IPS_LogMessage("SymconOSR", "<createInstance|scenes>   ".$ncount."/".$sceneID."/".$this->lightifyBase->chrToUUID($uintUUID)."/".$name);

            IPS_SetParent($InstanceID, $categoryID);
            IPS_SetPosition($InstanceID, 210+$sceneID);
            IPS_SetName($InstanceID, $name);

            IPS_SetProperty($InstanceID, "groupID", (int)$sceneID);
          }

          if ($InstanceID) {
            if (@IPS_GetProperty($InstanceID, "itemClass") != classConstant::CLASS_LIGHTIFY_SCENE) {
              IPS_SetProperty($InstanceID, "itemClass", classConstant::CLASS_LIGHTIFY_SCENE);
            }

            if (IPS_HasChanges($InstanceID)) {
              IPS_ApplyChanges($InstanceID);
            }
          }

          $data = substr($data, classConstant::DATA_NAME_LENGTH, ($ncount-$j)*classConstant::DATA_SCENE_LENGTH);
        }
        break;

      case classConstant::MODE_CREATE_ALL_SWITCH:
        $groupID    = classConstant::GROUP_ALL_DEVICES;
        $uintUUID   = chr($groupID).chr(0x00).chr(classConstant::TYPE_ALL_DEVICES).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $InstanceID = $this->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);

        if (!$InstanceID) {
          $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

          IPS_SetParent($InstanceID, $categoryID);
          IPS_SetName($InstanceID, $this->Translate("All Devices"));
          IPS_SetPosition($InstanceID, 210);

          IPS_SetProperty($InstanceID, "groupID", (int)$groupID);
        }

        if ($InstanceID) {
          if (@IPS_GetProperty($InstanceID, "itemClass") != classConstant::CLASS_ALL_DEVICES) {
            IPS_SetProperty($InstanceID, "itemClass", classConstant::CLASS_ALL_DEVICES);
          }

          if (@IPS_GetProperty($InstanceID, "uintUUID") != $uintUUID) {
            IPS_SetProperty($InstanceID,"uintUUID", $uintUUID);
          }

          if (IPS_HasChanges($InstanceID)) {
            IPS_ApplyChanges($InstanceID);
          }
        }
        break;
    }

  }


  private function getAllDevices() : string {

    $Instances = IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE);

    $deviceUUID = vtNoString;
    $allDevices = vtNoString;
    $i = 0;

    foreach($Instances as $instanceID) {
      $uintUUID = IPS_GetProperty($instanceID, "uintUUID");
      $class = IPS_GetProperty($instanceID, "itemClass");

      if ($class == classConstant::CLASS_LIGHTIFY_LIGHT || $class == classConstant::CLASS_LIGHTIFY_PLUG) {
        $deviceUUID .= "-d".$uintUUID;
        $i += 1;
      }
    }

    $allDevices = chr(classConstant::TYPE_ALL_DEVICES)."-g".chr(classConstant::GROUP_ALL_DEVICES).chr($i).$deviceUUID;
    //IPS_LogMessage("SymconOSR", "<Gateway|setAllLights|all:devices>   ".$allDevices);

    return $allDevices;
  }


  private function getDeviceGroups(string $uintUUID) : string {

    $buffer = $this->GetBuffer("deviceGroup");
    $dcount = ord($buffer{0});

    $groupUUID = vtNoString;
    $n = 0;

    if ($dcount > 0) {
      $buffer = substr($buffer, 1);

      for ($i = 1; $i <= $dcount; $i++) {
        $buffer = substr($buffer, 2);

        if ($uintUUID == substr($buffer, 0, classConstant::UUID_DEVICE_LENGTH)) {
          $decode = $this->lightifyBase->decodeGroup(ord($buffer{8}), ord($buffer{9}));

          foreach ($decode as $item) {
            $groupUUID .= "-g".chr($item);
          }

          break;
        }

        $buffer = substr($buffer, classConstant::DATA_GROUP_DEVICE);
      }
    }

    //IPS_LogMessage("SymconOSR", "<Gateway|getDeviceGroups:groupUUID>   ".$groupUUID);
    return $groupUUID;
  }


  private function setModeDeviceGroup(int $ncount, string $data, string $buffer) : string {

    $bufferUID   = "-g".chr(classConstant::GROUP_ALL_DEVICES);

    for ($i = 1; $i <= $ncount; $i++) {
      $groupID = $data{2};
      $dcount  = ord($data{3});

      if ($dcount > 0) {
        $data   = substr($data, 4);
        $length = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;
        $uuidBuffer = substr($data, 0, $dcount*$length);

        for ($j = 1; $j <= $dcount; $j++) {
          $UUID = $this->lightifyBase->ChrToUUID(substr($uuidBuffer, classConstant::ITEM_FILTER_LENGTH, classConstant::UUID_DEVICE_LENGTH));

          if ($UUID == $buffer) {
            //IPS_LogMessage("SymconOSR", "<Gateway|setModeDeviceGroup|devices:group>s   ".$i."/".ord($groupID)."/".$dcount."/".$data->buffer."  ".$j."/".$UUID);
            $bufferUID .= "-g".$groupID;
            break;
          }
          $uuidBuffer = substr($data, $length);
        }
      }
      $data = substr($data, $dcount*$length);
    }

    return $bufferUID;

  }


  protected function setAllDevices(int $value) {

    $args   = str_repeat(chr(0xFF), 8).chr($value);
    $buffer = $this->sendRaw(classCommand::SET_DEVICE_STATE, chr(0x00), $args);

    if ($buffer !== false) {
      return $buffer;
    }

    return false;

  }


}