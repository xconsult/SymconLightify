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

  const LIST_CATEGORY_INDEX     =  6;
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


  public function __construct($InstanceID)
  {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  public function Create()
  {

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


  protected function RequireParent($moduleID, $name = vtNoString)
  {

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


  public function GetConfigurationForParent()
  {

    $gatewayIP = $this->ReadPropertyString("gatewayIP");
    $port = classConstant::GATEWAY_PORT;

    return "{\"Host\": \"$gatewayIP\", \"Port\": $port}";

  }


  public function GetConfigurationForm()
  {

    $elements = [];
    $elements [] = ['type' => "CheckBox", 'name' => "active", 'caption' => " Active"];

    $options = [];
    $options [] = ['label' => "Local only",      'value' => 1001];
    $options [] = ['label' => "Local and Cloud", 'value' => 1002];

    $elements [] = ['type' => "Select",            'name'    => "connectMode",  'caption' => " Connection", 'options' => $options];
    $elements [] = ['type' => "ValidationTextBox", 'name'    => "gatewayIP",    'caption' => "Gateway IP"];
    $elements [] = ['type' => "ValidationTextBox", 'name'    => "serialNumber", 'caption' => "Serial number"];
    $elements [] = ['type' => "NumberSpinner",     'name'    => "localUpdate",  'caption' => "Update interval [s]"];
    $elements [] = ['type' => "Label",             'caption' => ""];

    $columns = [];
    $columns [] = ['label' => "Type",        'name' => "Device",     'width' =>  "55px"];
    $columns [] = ['label' => "Category",    'name' => "Category",   'width' => "265px"];
    $columns [] = ['label' => "Category ID", 'name' => "categoryID", 'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "SelectCategory"]];
    $columns [] = ['label' => "Sync",        'name' => "Sync",       'width' =>  "35px"];
    $columns [] = ['label' => "Sync ID",     'name' => "syncID",     'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "CheckBox", 'caption' => " Synchronise values"]];

    $elements [] = ['type' => "List",     'name' => "listCategory", 'caption' => "Categories", 'columns' => $columns];
    $elements [] = ['type' => "CheckBox", 'name' => "deviceInfo",   'caption' => " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)"];

    //Device list configuration
    $Devices = $this->GetBuffer("deviceList");

    if (!empty($Devices) && ord($Devices{0}) > 0) {
      $cloud = $this->GetBuffer("cloudDevice");

      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "deviceID",   'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",  'width' =>  "65px"];
      $columns [] = ['label' => "Name",  'name' => "deviceName", 'width' => "110px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",       'width' => "140px"];

      if (!empty($cloud)) {
        $columns [] = ['label' => "Manufacturer", 'name' => "manufacturer", 'width' =>  "80px"];
        $columns [] = ['label' => "Model",        'name' => "deviceModel",  'width' => "130px"];
        $columns [] = ['label' => "Capabilities", 'name' => "deviceLabel",  'width' => "175px"];
        $columns [] = ['label' => "Firmware",     'name' => "firmware",     'width' =>  "65px"];
      }

      $elements [] = ['type' => "List", 'name' => "listDevice", 'caption' => "Devices", 'columns' => $columns];
    }

    //Group list configuration
    $Groups = $this->GetBuffer("groupList");

    if (!empty($Groups) && ord($Groups{0}) > 0) {
      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "groupID",     'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",   'width' =>  "65px"];
      $columns [] = ['label' => "Name",  'name' => "groupName",   'width' => "110px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "140px"];
      $columns [] = ['label' => "Info",  'name' => "information", 'width' => "110px"];

      $elements [] = ['type' => "List", 'name' => "listGroup", 'caption' => "Groups", 'columns' => $columns];
    }

    //Scene list configuration
    $Scenes = $this->GetBuffer("sceneList");

    if (!empty($Scenes) && ord($Scenes{0}) > 0) {
      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "sceneID",     'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",   'width' =>  "65px"];
      $columns [] = ['label' => "Name",  'name' => "sceneName",   'width' => "110px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "140px"];
      $columns [] = ['label' => "Group", 'name' => "groupName",   'width' => "110px"];
      $columns [] = ['label' => "Info",  'name' => "information", 'width' =>  "70px"];

      $elements [] = ['type' => "List", 'name' => "listScene", 'caption' => "Scenes", 'columns' => $columns];
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
    $actions [] = ['type' => "Button", 'caption' => "Register", 'onClick' => "echo OSR_LightifyRegister(\$id)"];
    $actions [] = ['type' => "Label",  'caption' => "Press Create | Update to automatically apply the devices and settings"];
    $actions [] = ['type' => "Button", 'caption' => "Create | Update", 'onClick' => "OSR_GetLightifyData(\$id, 1206)"];

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
    if (!empty($Devices)) {
      $ncount  = ord($Devices{0});
      $Devices = substr($Devices, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $deviceID = ord($Devices{0});
        $Devices  = substr($Devices, 1);

        $UUID = $this->lightifyBase->chrToUUID(substr($Devices, 0, classConstant::UUID_DEVICE_LENGTH));
        $name = trim(substr($Devices, 8, classConstant::DATA_NAME_LENGTH));
        $info = trim(substr($Devices, 23, classConstant::DATA_CLASS_INFO));

        $Lists = [ 
          'deviceID'   => $deviceID,
          'classInfo'  => $info,
          'UUID'       => $UUID,
          'deviceName' => $name
        ];

        if (!empty($cloud)) {
          $Infos = json_decode($cloud);

          foreach ($Infos as $info) {
            list($cloudID, $type, $manufacturer, $model, $label, $firmware) = $info;

            if ($deviceID == $cloudID) {
              $Lists .= [
                'manufacturer' => $manufacturer,
                'deviceModel'  => $model,
                'deviceLabel'  => $label,
                'firmware'     => $firmware
              ];
              break;
            }
          }
        }

        $data->elements[self::LIST_DEVICE_INDEX]->values[] = $Lists;
        $Devices = substr($Devices, classConstant::DATA_DEVICE_LIST);
      }
    }

    //Group list element
    if (!empty($Groups)) {
      $ncount = ord($Groups{0});
      $Groups = substr($Groups, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $groupID = ord($Groups{0});
        $intUUID = $Groups{0}.$Groups{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);
        $name    = trim(substr($Groups, 2, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($Groups{18});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_GROUP_INDEX]->values[] = array(
          'groupID'     => $groupID,
          'classInfo'   => $this->Translate("Group"),
          'UUID'        => $UUID,
          'groupName'   => $name,
          'information' => $info
        );

        $Groups = substr($Groups, classConstant::DATA_GROUP_LIST);
      }
    }

    //Scene list element
    if (!empty($Scenes)) {
      $ncount = ord($Scenes{0});
      $Scenes = substr($Scenes, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $intUUID = $Scenes{0}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $sceneID = ord($Scenes{0});
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);

        $scene = trim(substr($Scenes, 1, classConstant::DATA_NAME_LENGTH));
        $group = trim(substr($Scenes, 15, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($Scenes{31});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_SCENE_INDEX]->values[] = array(
          'sceneID'     => $sceneID,
          'classInfo'   => $this->Translate("Scene"),
          'UUID'        => $UUID,
          'sceneName'   => $scene,
          'groupName'   => $group,
          'information' => $info
        );

        $Scenes = substr($Scenes, classConstant::DATA_SCENE_LIST);
      }
    }

    return json_encode($data);

  }


  public function ForwardData($jsonString)
  {

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
          $buffer = json_decode($data->buffer);
          $args   = str_repeat(chr(0xFF), 8).chr($buffer->state);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_DEVICE_STATE, chr(0x00), $args))]
          );

          //$this->SetBuffer("infoDevice", $data->buffer);
          $this->SendDataToParent($jsonString);
          break;

        case classConstant::SAVE_LIGHT_STATE:
          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SAVE_LIGHT_STATE, chr(0x00), $args))]
          );

          //$this->SetBuffer("infoDevice", $data->buffer);
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
          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr((int)$buffer->state);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_DEVICE_STATE, $buffer->flag, $args))]
          );
          $this->SendDataToParent($jsonString);

          //$this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_DEVICE_STATE, 'buffer' => $data->buffer]));
          break;

        case classConstant::SET_COLOR:
          $buffer = json_decode($data->buffer);
          $rgb    = $this->lightifyBase->HEX2RGB($buffer->hex);
          $args   = utf8_decode($buffer->UUID).chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0xFF).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_COLOR, $buffer->flag, $args))]
          );
          $this->SendDataToParent($jsonString);

          //$this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_LIGHT_COLOR, 'buffer' => $data->buffer]));
          break;

        case classConstant::SET_COLOR_TEMPERATURE:
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

          //$this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_COLOR_TEMPERATURE, 'buffer' => $data->buffer]));
          break;

        case classConstant::SET_BRIGHTNESS:
          $buffer = json_decode($data->buffer);
          $args   = utf8_decode($buffer->UUID).chr((int)$buffer->brightness).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_LEVEL, $buffer->flag, $args))]
          );
          $this->SendDataToParent($jsonString);

          //$this->SetBuffer("infoDevice", json_encode(['command' => classCommand::SET_LIGHT_LEVEL, 'buffer' => $data->buffer]));
          break;

        case classConstant::SET_SATURATION:
          $buffer = json_decode($data->buffer);
          $rgb    = $this->lightifyBase->HEX2RGB($buffer->hex);
          $args   = utf8_decode($buffer->UUID).chr($rgb['r']).chr($rgb['g']).chr($rgb['b']).chr(0x00).chr(dechex($buffer->fade)).chr(0x00).chr(0x00);

          $jsonString = json_encode([
            'DataID' => classConstant::TX_VIRTUAL,
            'Buffer' => utf8_encode($this->sendRaw(classCommand::SET_LIGHT_COLOR, $buffer->flag, $args))]
          );
          $this->SendDataToParent($jsonString);

          //$this->SetBuffer("infoDevice", json_encode(['command' => classConstant::SET_SATURATION, 'buffer' => $data->buffer]));
          break;

        case classConstant::METHOD_APPLY_CHILD:
          switch ($data->mode) {
            case classConstant::MODE_DEVICE_LOCAL:
              $Devices = $this->GetBuffer("localDevice");

              if (!empty($Devices) && ord($Devices{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($Devices))
                );
              }
              return $jsonReturn;

            case classConstant::MODE_DEVICE_GROUP:
              $Groups  = $this->GetBuffer("localGroup");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($Groups) && !empty($Groups)) {
                $ncount = ord($Groups{0});

                if ($ncount > 0) {
                  $groupUUID = $this->setModeDeviceGroup($ncount, substr($buffer, 1), $data->buffer);

                  $jsonReturn = json_encode(array(
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode($groupUUID))
                  );
                }
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|devices:groups>   ".$jsonReturn);
              break;;

            case classConstant::MODE_DEVICE_CLOUD:
              $Devices = $this->GetBuffer("cloudDevice");

              if (!empty($Devices)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => $Devices)
                );
              }
              break;;

            case classConstant::MODE_GROUP_LOCAL:
              $Groups  = $this->GetBuffer("localGroup");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($Groups) && !empty($buffer)) {
                $ncount = ord($Groups{0});

                if ($ncount > 0) {
                  $jsonReturn = json_encode(array(
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode(chr($ncount).$buffer))
                  );
                }
              }
              break;;

            case classConstant::MODE_GROUP_SCENE:
              $Scenes = $this->GetBuffer("cloudScene");

              if (!empty($Scenes) && ord($Scenes{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($Scenes))
                );
              }
              break;;

            case classConstant::MODE_ALL_SWITCH:
              $Devices = $this->getAllDevices();

              if (!empty($Devices)) {
                $ncount = 1;

                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode(chr($ncount).$Devices))
                );
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData:all>   ".$jsonReturn);
              break;;
          }
      }
    }

    return $jsonReturn;

  }


  public function ReceiveData($jsonString)
  {

    $localMethod = $this->GetBuffer("localMethod");
    $connect = $this->ReadPropertyInteger("connectMode");

    $this->debug   = $this->ReadPropertyInteger("debug");
    $this->message = $this->ReadPropertyBoolean("message");

    $decode = json_decode($jsonString);
    $data   = utf8_decode($decode->Buffer);

    $command = ord($data{3});
    $data    = substr($data, classConstant::BUFFER_HEADER_LENGTH + 1);

    switch ($command) {
      //Get paired devices
      case classCommand::GET_DEVICE_LIST:
        $syncDevice = false;

        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $sendMethod = $localMethod;

          $deviceBuffer = vtNoString;
          $localGroup   = vtNoString;

          $this->SetBuffer("deviceBuffer", $deviceBuffer);
          $this->SetBuffer("deviceList", vtNoString);
        } else {
          $sendMethod = classConstant::METHOD_UPDATE_CHILD;

          $localDevice  = vtNoString;
          $deviceBuffer = $this->GetBuffer("deviceBuffer");
          $localGroup   = $this->GetBuffer("localGroup");
        }

        //Get Gateway WiFi and firmware version
        //$this->setGatewayInfo($localMethod);

        if (strlen($data) >= (2 + classConstant::DATA_DEVICE_LENGTH)) {
          //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData:info>   ".$jsonString);

          $ncount = ord($data{0}) + ord($data{1});
          $data   = $this->structDeviceData($ncount, substr($data, 2));

          if (strcmp($data, $deviceBuffer) != 0) {
            $syncDevice = true;

            $ncount = ord($data{0});
            $localDevice = $this->structLightifyData(classCommand::GET_DEVICE_LIST, $ncount, substr($data, 2), $deviceBuffer);

            $this->SetBuffer("deviceBuffer", $data);
            $this->SetBuffer("localDevice", $localDevice);

            if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadPropertyString("osramToken"))) {
              if (!empty($localDevice)) {
                $deviceList = $this->GetBuffer("deviceList");
                $ncount     = ord($deviceList{0});

                $cloudDevice = $this->structLightifyData(classConstant::GET_DEVICE_CLOUD, $ncount, substr($deviceList, 1));
                $this->SetBuffer("cloudDevice", $cloudDevice);
              }
            }

            //Create childs
            if ($sendMethod == classConstant::METHOD_CREATE_CHILD) {
              if (!empty($localDevice)) {
                $ncount = ord($localDevice{0});

                if ($ncount > 0) {
                  $this->createInstance(classConstant::MODE_CREATE_DEVICE, vtNoValue, $ncount, substr($localDevice, 2));
                }
              }
            }

            //Update child informations
            if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
              $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE));

              if ($mcount > 0) {
                if (!empty($localDevice) && ord($localDevice{0}) > 0) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Devices>   ".json_encode(utf8_encode($localDevice)));

                  $this->SendDataToChildren(json_encode(array(
                    'DataID' => classConstant::TX_DEVICE,
                    'id'     => $this->InstanceID,
                    'mode'   => classConstant::MODE_DEVICE_LOCAL,
                    'method' => $sendMethod,
                    'buffer' => utf8_encode($localDevice)))
                  );
                }

                if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
                  $cloudDevice = $this->GetBuffer("cloudDevice");

                  if (!empty($cloudDevice) && ord($cloudDevice{0}) > 0) {
                    $this->SendDataToChildren(json_encode(array(
                      'DataID' => classConstant::TX_DEVICE,
                      'id'     => $this->InstanceID,
                      'mode'   => classConstant::MODE_DEVICE_CLOUD,
                      'method' => $sendMethod,
                      'buffer' => $cloudDevice))
                    );
                  }
                }
              }
            }
          }

          //Get group list
          if ($Categories = $this->ReadPropertyString("listCategory")) {
            list(, , $groupCategory) = json_decode($Categories);

            $folderGroup = ($groupCategory->categoryID > 0) ? true : false;
            $syncGroup = ($folderGroup && $groupCategory->syncID) ? true : false;
          }

          if ($syncGroup) {
            if (empty($localGroup)) {
              $jsonString = json_encode([
                'DataID' => classConstant::TX_VIRTUAL,
                'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_GROUP_LIST, chr(0x00)))]
              );

              $this->SendDataToParent($jsonString);
            }

            if ($syncDevice) {
              //Update child informations
              if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
                $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP));

                if ($mcount > 0) {
                  $groupDevice = $this->GetBuffer("groupBuffer");

                  if (!empty($localGroup) && ord($localGroup{0}) > 0 && !empty($groupDevice)) {
                    //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Groups>   ".json_encode(utf8_encode($groupDevice)));

                    $this->SendDataToChildren(json_encode(array(
                      'DataID'  => classConstant::TX_GROUP,
                      'id'      => $this->InstanceID,
                      'mode'    => classConstant::MODE_GROUP_LOCAL,
                      'method'  => $sendMethod,
                      'buffer'  => utf8_encode(ord($localGroup{0}).$groupDevice)))
                    );
                  }

                  //Update 'All Lights' dummy switch group
                  $allDevices = $this->getAllDevices();

                  if (!empty($allDevices)) {
                    //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|all:Devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
                    $ncount = 1;

                    $this->SendDataToChildren(json_encode(array(
                      'DataID'  => classConstant::TX_GROUP,
                      'id'      => $this->InstanceID,
                      'mode'    => classConstant::MODE_ALL_SWITCH,
                      'method'  => $sendMethod,
                      'buffer' => utf8_encode(chr($ncount).$allDevices)))
                    );
                  }
                }
              }
            }
          } else {
            $this->SetBuffer("groupList", vtNoString);
            $this->SetBuffer("groupDevice", vtNoString);

            $this->SetBuffer("sceneList", vtNoString);
          }
        }
        break;

      case classCommand::GET_GROUP_LIST:
        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $sendMethod = $localMethod;

          $groupBuffer = vtNoString;
          $sceneBuffer = vtNoString;

          $this->SetBuffer("groupBuffer", $groupBuffer);
          $this->SetBuffer("sceneBuffer", $sceneBuffer);

          $this->SetBuffer("groupList", vtNoString);
          $this->SetBuffer("sceneList", vtNoString);
        } else {
          $sendMethod = classConstant::METHOD_UPDATE_CHILD;

          $groupBuffer = $this->GetBuffer("groupBuffer");
          $sceneBuffer = $this->GetBuffer("sceneBuffer");
        }

        //Get Gateway WiFi and firmware version
        //$this->setGatewayInfo($localMethod);

        if (strlen($data) >= (2 + classConstant::DATA_GROUP_LENGTH)) {
          $ncount = ord($data{0}) + ord($data{1});
          $localGroup = chr($ncount).substr($data, 2);

          if (!empty($localGroup)) {
            $ncount = ord($localGroup{0});

            $groupDevice = $this->structLightifyData(classCommand::GET_GROUP_LIST, $ncount, substr($localGroup, 1), $groupBuffer);
            $this->SetBuffer("groupBuffer", $groupDevice);

            if ($Categories = $this->ReadPropertyString("listCategory")) {
              list(, , $groupCategory, $sceneCategory) = json_decode($Categories);

              $folderScene = ($sceneCategory->categoryID > 0) ? true : false;
              $syncScene = ($folderScene && $sceneCategory->syncID) ? true : false;
            }

            //Create childs
            if ($sendMethod == classConstant::METHOD_CREATE_CHILD) {
              if ($ncount > 0) {
                $this->createInstance(classConstant::MODE_CREATE_GROUP, $groupCategory->categoryID, $ncount, substr($localGroup, 1));
              }

              //Create 'All Lights' dummy switch group
              $this->createInstance(classConstant::MODE_CREATE_ALL_SWITCH, $groupCategory->categoryID, vtNoValue, substr($localGroup, 1));
            }

            //Update child informations
            if ($sendMethod == classConstant::METHOD_UPDATE_CHILD) {
              $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP));

              if ($mcount > 0) {
                if (!empty($localGroup) && ord($localGroup{0}) > 0 && !empty($groupDevice)) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Groups>   ".json_encode(utf8_encode($groupDevice)));

                  $this->SendDataToChildren(json_encode(array(
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'mode'    => classConstant::MODE_GROUP_LOCAL,
                    'method'  => $sendMethod,
                    'buffer'  => utf8_encode(ord($localGroup{0}).$groupDevice)))
                  );
                }

                //Update 'All Lights' dummy switch group
                $allDevices = $this->getAllDevices();

                if (!empty($allDevices)) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|all:Devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
                  $ncount = 1;

                  $this->SendDataToChildren(json_encode(array(
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'mode'    => classConstant::MODE_ALL_SWITCH,
                    'method'  => $sendMethod,
                    'buffer' => utf8_encode(chr($ncount).$allDevices)))
                  );
                }
              }
            }

            if ($syncScene) {
              if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadPropertyString("osramToken"))) {
                $ncount = ord($groupDevice{0});

                $cloudGroup = $this->structLightifyData(classConstant::GET_GROUP_CLOUD, $ncount);
                $this->SetBuffer("cloudGroup", $cloudGroup);

                if (!empty($cloudGroup)) {
                  $cloudScene = $this->structLightifyData(classConstant::GET_GROUP_SCENE, vtNoValue, vtNoString, $sceneBuffer);

                  if (strcmp($cloudScene, $sceneBuffer) != 0) {
                    $this->SetBuffer("cloudScene", $cloudScene);
                    $this->SetBuffer("sceneBuffer", $cloudScene);

                    if (!empty($cloudScene) && ord($cloudScene{0})) {
                      //Create childs
                      if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
                        $this->createInstance(classConstant::MODE_CREATE_SCENE, $sceneCategory->categoryID, ord($cloudScene{0}), substr($cloudScene, 1));
                      }

                      //Update child informations
                      if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
                        //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|Scenes>   ".json_encode(utf8_encode($cloudScene)));

                        $this->SendDataToChildren(json_encode(array(
                          'DataID'  => classConstant::TX_GROUP,
                          'id'      => $this->InstanceID,
                          'mode'    => classConstant::MODE_GROUP_SCENE,
                          'method'  => $sendMethod,
                          'buffer'  => utf8_encode($cloudScene)))
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
        break;

      default:
        $infoDevice = $this->GetBuffer("infoDevice");

        if (!empty($infoDevice)) {
          $this->SetBuffer("infoDevice", vtNoString);

          $data   = json_decode($jsonString);
          $buffer = utf8_decode($data->Buffer);

          if (ord($buffer{0}) > classConstant::BUFFER_HEADER_LENGTH) {
            $code = ord($buffer{classConstant::BUFFER_HEADER_LENGTH});

            if ($code == 0) {
              //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData|default>   ".$data->buffer);

              $data = json_decode($infoDevice);
              $args = json_decode($data->buffer);

              switch ($data->command) {
                case classCommand::SET_DEVICE_STATE:
                  if ($args->stateID) {
                    SetValue($args->stateID, $args->state);
                  }
                  break;

                case classCommand::SET_LIGHT_COLOR:
                  $hsv = $this->lightifyBase->HEX2HSV($args->hex);

                  if ($args->hueID && GetValue($args->hueID) != $hsv['h']) {
                    SetValue($args->hueID, $hsv['h']);
                  }

                  if ($args->colorID) {
                    SetValue($args->colorID, $args->color);
                  }

                  if ($args->saturationID && GetValue($args->saturationID) != $hsv['s']) {
                    SetValue($args->saturationID, $hsv['s']);
                  }
                  break;

                case classCommand::SET_COLOR_TEMPERATURE:
                  if ($args->temperatureID) {
                    SetValue($args->temperatureID, $args->temperature);
                  }
                  break;

                case classCommand::SET_LIGHT_LEVEL:
                  if ($args->stateID && $args->light) {
                    if ($args->brightness == 0) {
                      SetValue($args->stateID, false);
                    } else {
                      SetValue($args->stateID, true);
                    }
                  }

                  if ($args->brightnessID) {
                    SetValue($args->brightnessID, $args->brightness);
                  }
                  break;

                case classConstant::SET_SATURATION:
                  $color = hexdec($args->hex);

                  if ($args->colorID && GetValue($args->colorID) != $color) {
                    SetValue($args->colorID, $color);
                  }

                  if ($args->saturationID) {
                    SetValue($args->saturationID, $args->saturation);
                  }
                  break;
              }
            }
          }
        }
    }

  }


  private function validateConfig($active, $connect)
  {

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


  public function LightifyRegister()
  {

    if ($this->ReadPropertyInteger("connectMode") == classConstant::CONNECT_LOCAL_CLOUD) {
      //Return everything which will open the browser
      return self::OAUTH_AUTHORIZE.$this->oAuthIdent."?username=".urlencode(IPS_GetLicensee());
    }

    echo $this->Translate("Lightify API registration available in cloud connection mode only!")."\n";
  }


  protected function ProcessOAuthData()
  {

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


  private function getAccessToken($code)
  {

    $debug   = $this->ReadPropertyInteger("debug");
    $message = $this->ReadPropertyBoolean("message");
    IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:code>   ".$code);

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

      $buffer = json_encode(array(
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token)
      );

      IPS_SetProperty($this->InstanceID, "osramToken", $buffer);
      IPS_ApplyChanges($this->InstanceID);

      return true;
    }

    $this->SendDebug("<Gateway|getAccessToken:error>", $result, 0);
    IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:error>   ".$result);

    return false;

  }


  private function getRefreshToken()
  {

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

      $buffer = json_encode(array(
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token)
      );

      IPS_SetProperty($this->InstanceID, "osramToken", $buffer);
      IPS_ApplyChanges($this->InstanceID);

      return $data->access_token;
    } else {
      $this->SendDebug("<Gateway|getRefreshToken:error>", $result, 0);
      IPS_LogMessage("SymconOSR", "<Gateway|getRefreshToken:error>   ".$result);

      return false;
    }

  }


  protected function cloudGET($url)
  {

    return $this->cloudRequest("GET", $url);

  }


  protected function cloudPATCH($ressource, $args)
  {

    return $this->cloudRequest("PATCH", $ressource, $args);

  }


  private function cloudRequest($method, $ressource, $args = null)
  {

    $accessToken = $this->getRefreshToken();
    if (!$accessToken) return vtNoString;

    $cURL    = curl_init();
    $options = [
      CURLOPT_URL            => self::LIGHTIFY_EUROPE.self::LIGHTIFY_VERSION.$ressource,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_MAXREDIRS      => self::LIGHTIFY_MAXREDIRS,
      CURLOPT_TIMEOUT        => self::LIGHTIFY_TIMEOUT,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => $method,
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


  protected function sendRaw($command, $flag, $args = vtNoValue)
  {

    $debug   = $this->ReadPropertyInteger("debug");
    $message = $this->ReadPropertyBoolean("message");

    //$this->requestID = ($this->requestID == classConstant::REQUESTID_HIGH) ? 1 : $this->requestID+1;
    //$data = $flag.chr($command).$this->lightifyBase->getRequestID($this->requestID);
    $data = $flag.chr($command).chr(0x00).chr(0x00).chr(0x00).chr(0x00);

    if ($args != vtNoValue) {
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


  public function GetLightifyData(int $localMethod)
  {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $parentID = $this->getParentInfo($this->InstanceID);
    $socket = ($parentID) ? IPS_GetProperty($parentID, "Open") : false;

    if ($socket && $this->ReadPropertyBoolean("active")) {
      $this->SetBuffer("localMethod", $localMethod);

      $syncDevice = false;
      $syncSensor = false;

      if ($Categories = $this->ReadPropertyString("listCategory")) {
        list($deviceCategory, $sensorCategory) = json_decode($Categories);

        $folderDevice = ($deviceCategory->categoryID > 0) ? true : false;
        $folderSensor = ($sensorCategory->categoryID > 0) ? true : false;

        $syncDevice = ($folderDevice && $deviceCategory->syncID) ? true : false;
        $syncSensor = ($folderSensor && $sensorCategory->syncID) ? true : false;
      }

      //Get paired devices
      if ($syncDevice || $syncSensor) {
        $jsonString = json_encode([
          'DataID' => classConstant::TX_VIRTUAL,
          'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01)))]
        );

        $this->SendDataToParent($jsonString);
      } else {
        $this->SetBuffer("deviceList", vtNoString);
        $this->SetBuffer("deviceGroup", vtNoString);
      }
    }

  }


  private function structDeviceData($ncount, $data)
  {

    $device = vtNoString;
    $list   = vtNoString;
    $group  = vtNoString;
    $Labels = [];

    for ($i = 1, $j = 0, $n = 0, $m = 0; $i <= $ncount; $i++) {
      $uintUUID = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
      $deviceID = chr($i);

      $type    = ord($data{10});
      $known   = true;
      $noGroup = false;

      unset($label);
      unset($info);

      //Decode Device label
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
          $info = $this->Translate("Light");

        case classConstant::TYPE_PLUG_ONOFF:
          if (!isset($label)) $label = classConstant::LABEL_PLUG_ONOFF;
          if (!isset($info))  $info  = $this->Translate("Plug");
          break;

        case classConstant::TYPE_SENSOR_MOTION:
          if (!isset($label)) $label = classConstant::LABEL_SENSOR_MOTION;
          if (!isset($info))  $info = $this->Translate("Sensor");

        case classConstant::TYPE_DIMMER_2WAY:
          if (!isset($label)) $label = classConstant::LABEL_DIMMER_2WAY;
          if (!isset($info))  $info  = $this->Translate("Dimmer");

        case classConstant::TYPE_SWITCH_4WAY:
          if (!isset($label)) $label = classConstant::LABEL_SWITCH_4WAY;
          if (!isset($info))  $info  = $this->Translate("Switch");

        case classConstant::TYPE_SWITCH_MINI:
          if (!isset($label)) $label = classConstant::LABEL_SWITCH_MINI;
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
      $Labels[$i] = $label;

      $info  = str_pad($info, classConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);
      $name  = substr($data, 26, classConstant::DATA_NAME_LENGTH);
      $list .= $deviceID.$uintUUID.$name.$info;

      $device .= substr($data, 0, classConstant::OSRAM_ZIGBEE_LENGTH)."-d".substr($data, 2, classConstant::DATA_DEVICE_LENGTH-classConstant::OSRAM_ZIGBEE_LENGTH);
      $j += 1;

      //Device group
      if (!$noGroup) {
        $group .= "-d".$uintUUID.substr($data, 16, classConstant::OSRAM_GROUP_LENGTH);
        $m += 1; 
      }

      if (($length = strlen($data)) > classConstant::DATA_DEVICE_LOADED) {
        $length = classConstant::DATA_DEVICE_LOADED;
      }

      $data = substr($data, $length);
    }

    //Store at runtime
    $this->SetBuffer("deviceList", chr($j).$list);
    $this->SetBuffer("deviceLabel", json_encode($Labels));
    $this->SetBuffer("deviceGroup", chr($m).$group);

    //IPS_LogMessage("SymconOSR", "<Gateway|structDeviceData|devices:length>   ".$i."/".($i*strlen(chr($j).chr($i).$device))/2014);
    return chr($j).chr($i).$device;

  }


  private function structLightifyData($command, $ncount = vtNoValue, $data = vtNoString, $buffer = vtNoString)
  {

    switch ($command) {
      case classCommand::GET_DEVICE_LIST:
        $device = vtNoString;

        //Parse devices
        for ($i = 1, $j = 0, $k = 0, $n = 0; $i <= $ncount; $i++, $j++) {
          $data = substr($data, 2);

          $trunk = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
          $index = stripos($buffer, $trunk);

          if ($index === false || ($index && substr_compare($buffer, $trunk, $index, classConstant::DATA_DEVICE_LENGTH))) {
            $device .= "-d".$trunk;
            $k += 1;
          }

          $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
        }

        if ($this->debug % 2 || $this->message) {
          $info = ($k > 0) ? $k.":".$i."/".$this->lightifyBase->decodeData($device) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|devices:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:local>   ".$info);
          }
        }

        //Return device buffer string
        return chr($k).chr($i).$device;
        break;

      case classConstant::GET_DEVICE_CLOUD:
        $Clouds = $this->cloudGET(self::RESSOURCE_DEVICES);
        if (empty($Clouds)) return vtNoString;

        $Clouds  = json_decode($Clouds);
        $Labels  = json_decode($this->GetBuffer("deviceLabel"));
        $gateway = $Clouds->devices[0];

        if ($gateway->name == strtoupper($this->ReadPropertyString("serialNumber"))) {
          $gatewayID = $gateway->id;
          unset($Clouds->devices[0]);

          for ($i = 1; $i <= $ncount; $i++) {
            $data = substr($data, 1);

            $uintUUID = substr($data, 0, classConstant::UUID_DEVICE_LENGTH);
            $name     = trim(substr($data, 8, classConstant::DATA_NAME_LENGTH));

            foreach ($Clouds->devices as $array => $device) {
              $cloudID = $gatewayID."-d".$uintUUID;

              if ($name == trim($device->name)) {
                $zigBee = dechex(ord($data{0})).dechex(ord($data{1}));
                $model  = strtoupper($device->deviceModel);
                $label  = vtNoString;

                //Modell mapping
                if (substr($model, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $model = "CLASSIC A60 W CLEAR";
                }

                if (substr($model, 0, 4) == "PLUG") {
                  $model = classConstant::MODEL_PLUG_ONOFF;
                }

                if (is_object($Labels)) {
                  $label = $Labels->$deviceID;
                }

                $Devices[] = [
                  "-d".$uintUUID, $zigBee,
                  $device->type,
                  classConstant::MODEL_MANUFACTURER,
                  $model, $label,
                  $device->firmwareVersion
                ];
                break;
              }
            }

            $data = substr($data, classConstant::DATA_DEVICE_LIST);
          }

          if (isset($Devices)) {
            $Devices = json_encode($Devices);

            if ($this->debug % 2 || $this->message) {
              $Clouds  = json_encode($Clouds);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|devices:cloud>", $Devices, 0);
                $this->SendDebug("<Gateway|structLightifyData|devices:cloud>", $Clouds, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:cloud>   ".$Devices);
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:cloud>   ".$Clouds);
              }
            }

            return $Devices;
          }
        }
        break;

      case classCommand::GET_GROUP_LIST:
        $group  = vtNoString;
        $list   = vtNoString;
        $device = vtNoString;

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
              //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".json_encode($decode));

              foreach ($decode as $key) {
                if ($groupID == $key) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($buffer, 0, classConstant::UUID_DEVICE_LENGTH)));
                  $uintUUID   = substr($buffer, 0, classConstant::UUID_DEVICE_LENGTH);
                  $groupUUID .= "-d".$uintUUID;
                  $n += 1;
                  break;
                }
              }

              $buffer = substr($buffer, classConstant::DATA_GROUP_DEVICE);
            }
          }

          $group  .= substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $list   .= substr($data, 0, classConstant::DATA_GROUP_LENGTH).chr($n);
          $device .= "-g".chr($groupID).chr($n).$groupUUID;

          if (($length = strlen($data)) > classConstant::DATA_GROUP_LENGTH) {
            $length = classConstant::DATA_GROUP_LENGTH;
          }

          $data = substr($data, $length);
        }

        //Store at runtime
        $this->SetBuffer("localGroup", chr($ncount).$group);
        $this->SetBuffer("groupList", chr($ncount).$list);

        if ($this->debug % 2 || $this->message) {
          $info = ($ncount > 0) ? $ncount.":".classConstant::TYPE_DEVICE_GROUP."/".$this->lightifyBase->decodeData($group) : "0:0/null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|groups:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$info);
          }
        }

        //Return group buffer string
        return chr(classConstant::TYPE_DEVICE_GROUP).$device;
        break;

      case classConstant::GET_GROUP_CLOUD:
        $cloud = $this->cloudGET(self::RESSOURCE_GROUPS);

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|structLightifyData|groups:cloud>", $cloud, 0);
        }

        if ($this->message) {
          IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:cloud>   ".$cloud);
        }
        return $cloud;

      case classConstant::GET_GROUP_SCENE:
        $cloud = $this->cloudGET(self::RESSOURCE_SCENES);
        if (empty($cloud)) return vtNoString;

        $Clouds = json_decode($cloud);
        $Groups = $this->GetBuffer("cloudGroup");

        if (!empty($Groups)) {
          $Groups = json_decode($Groups);
          $scene  = vtNoString;
          $list   = vtNoString;
          $i = 0;

          foreach ($Groups->groups as $group) {
            $Scenes = $group->scenes;
            $nameG  = str_pad($group->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);

            if (!empty($Scenes)) {
              $j = 0;

              foreach ($Scenes as $sceneID) {
                foreach ($Clouds->scenes as $cloud) {
                  if ($sceneID == $scene->id) {
                    $groupID = (int)substr($group->id, -2);
                    $sceneID = (int)substr($cloud->id, -2);

                    $nameS  = str_pad($cloud->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);
                    $scene .= "-g".chr($groupID)."-s".chr($sceneID).$nameS;
                    $list  .= chr($sceneID).$nameS.$nameG.chr(count($group->devices));

                    $i += 1; $j += 1;
                    break;
                  }
                }
              }
            }
          }

          //Store at runtime
          if (!empty($list)) {
            $this->SetBuffer("sceneList", chr($i).$list);
          }

          if (!empty($scene)) {
            $result = chr($i).chr($type).$scene;

            if ($this->debug % 2 || $this->message) {
              $info = $i.":".classConstant::TYPE_GROUP_SCENE."/".$this->lightifyBase->decodeData($scene);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|scenes:cloud>", $info, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|scenes:cloud>   ".$info);
              }
            }

            if (strcmp($result, $buffer) != 0) {
              return chr($i).chr(classConstant::TYPE_GROUP_SCENE).$scene;
            }
          }
        }
        break;
    }

    return vtNoString;

  }


  private function setGatewayInfo($method)
  {

    $firmwareID = @$this->GetIDForIdent("FIRMWARE");
    $ssidID     = @$this->GetIDForIdent("SSID");

    if ($method == classConstant::METHOD_APPLY_LOCAL) {
      if (!$ssidID) {
        if (false !== ($ssidID = $this->RegisterVariableString("SSID", "SSID", vtNoString, 301))) {
          SetValueString($ssidID, vtNoString);
          IPS_SetDisabled($ssidID, true);
        }
      }

      if (false === ($portID = @$this->GetIDForIdent("PORT"))) {
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
    }

    //Get Gateway WiFi configuration
    if ($ssidID) {
      $jsonString = json_encode([
        'DataID' => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}",
        'Buffer' => utf8_encode($this->sendRaw(classCommand::GET_GATEWAY_WIFI, classConstant::SCAN_WIFI_CONFIG))]
      );

      $result = $this->SendDataToParent($jsonString);
      IPS_LogMessage("SymconOSR", "<Gateway|sendRaw:write>   ".$result);
      return;

      if (strlen($data) >= (2+classConstant::DATA_WIFI_LENGTH)) {
        if (false !== ($SSID = $this->getWiFi($data))) {
          if (GetValueString($ssidID) != $SSID) {
            SetValueString($ssidID, (string)$SSID);
          }
        }
      }
    }

    //Get gateway firmware version
    if ($firmwareID) {
      if (false !== ($data = $lightifySocket->sendRaw(classCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))) {
        $firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});

        if (GetValueString($firmwareID) != $firmware) {
          SetValueString($firmwareID, (string)$firmware);
        }
      }
    }

  }


  private function getWiFi($data)
  {

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

    return $result;

  }


  private function createInstance($mode, $categoryID, $ncount = vtNoValue, $data = vtNoString)
  {

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

            $uintUUID   = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $InstanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
            $UUID       = $this->lightifyBase->ChrToUUID($uintUUID);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_DEVICE);

              $name = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
              $name = (!empty($name)) ? $name : "-Unknown-";
              //IPS_LogMessage("SymconOSR", "<createInstance|devices>   ".$i."/".$deviceID."/".$type."/".$name."/".$this->lightifyBase->decodeData($data));

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

              if (IPS_HasChanges($InstanceID)) {
                IPS_ApplyChanges($InstanceID);
              }
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


  private function getAllDevices()
  {

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


  private function getDeviceGroups($uintUUID)
  {

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


  private function setModeDeviceGroup($ncount, $data, $buffer)
  {

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


  protected function setAllDevices($value)
  {

    $args   = str_repeat(chr(0xFF), 8).chr($value);
    $buffer = $this->sendRaw(classCommand::SET_DEVICE_STATE, chr(0x00), $args);

    if ($buffer !== false) {
      return $buffer;
    }

    return false;

  }


}