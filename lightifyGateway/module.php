<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';
require_once __DIR__.'/../libs/lightifyConnect.php';


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
  const LIST_DEVICE_INDEX       =  9;
  const LIST_GROUP_INDEX        = 10;
  const LIST_SCENE_INDEX        = 11;

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

  protected $classModule;
  protected $lightifyBase;
  protected $lightifyConnect;

  protected $deviceCategory;
  protected $sensorCategory;
  protected $GroupsCategory;
  protected $ScenesCategory;

  protected $createDevice;
  protected $createSensor;

  protected $createGroup;
  protected $createScene;

  protected $syncDevice;
  protected $syncSensor;

  protected $syncGroup;
  protected $syncScene;

  protected $connect;
  protected $debug;
  protected $message;

  use WebOAuth,
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

    $this->RegisterPropertyBoolean("open", false);
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

    $this->RegisterPropertyBoolean("reloadLocal", false);
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
      IPS_SetVariableProfileAssociation("OSR.Switch", false, "Off", vtNoString, -1);
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
      $this->SetBuffer("connectTime", vtNoString);
      $localUpdate = 0;

      $open    = $this->ReadPropertyBoolean("open");
      $connect = $this->ReadPropertyInteger("connectMode");
      $result  = $this->validateConfig($open, $connect);

      if ($result && $open) {
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


  public function GetConfigurationForm()
  {

    $elements = [];
    $elements [] = ['type' => "CheckBox", 'name' => "open", 'caption' => " Open"];

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
    $elements [] = ['type' => "CheckBox", 'name' => "reloadLocal",  'caption' => " Reload data instantly (not recommended: higher gateway load and longer response time)"];
    $elements [] = ['type' => "CheckBox", 'name' => "deviceInfo",   'caption' => " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)"];

    //Device list configuration
    $list = $this->GetBuffer("deviceList");

    if (!empty($list) && ord($list{0}) > 0) {
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
    $list = $this->GetBuffer("groupList");

    if (!empty($list) && ord($list{0}) > 0) {
      $columns = [];
      $columns [] = ['label' => "ID",    'name' => "groupID",     'width' =>  "30px"];
      $columns [] = ['label' => "Class", 'name' => "classInfo",   'width' =>  "65px"];
      $columns [] = ['label' => "Name",  'name' => "groupName",   'width' => "110px"];
      $columns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "140px"];
      $columns [] = ['label' => "Info",  'name' => "information", 'width' => "110px"];

      $elements [] = ['type' => "List", 'name' => "listGroup", 'caption' => "Groups", 'columns' => $columns];
    }

    //Scene list configuration
    $list = $this->GetBuffer("sceneList");

    if (!empty($list) && ord($list{0}) > 0) {
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
    $actions [] = ['type' => "Button", 'caption' => "Create | Update", 'onClick' => "OSR_GetLightifyData(\$id, 1208)"];

    $status = [];
    $status [] = ['code' => 101, 'icon' => "inactive", 'caption' => "Lightify gateway is closed"];
    $status [] = ['code' => 102, 'icon' => "active",   'caption' => "Lightify gateway is open"];
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
    $category = json_decode($this->ReadPropertyString("listCategory"));

    if (empty($category)) {
      foreach ($Types as $item) {
        $data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
          'Device'     => $item,
          'categoryID' => 0, 
          'Category'   => $this->Translate("select ..."),
          'Sync'       => $this->Translate("no"),
          'syncID'     => false
        );
      }
    } else {
      //Annotate existing elements
      foreach ($category as $index => $row) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        if ($row->categoryID && IPS_ObjectExists($row->categoryID)) {
          $data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
            'Device'   => $Types[$index],
            'Category' => IPS_GetName(0)."\\".IPS_GetLocation($row->categoryID),
            'Sync'     => ($row->syncID) ? $this->Translate("yes") : $this->Translate("no")
          );
        } else {
          $data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
            'Device'   => $Types[$index],
            'Category' => $this->Translate("select ..."),
            'Sync'     => $this->Translate("no")
          );
        }
      }
    }

    //Device list element
    $list = $this->GetBuffer("deviceList");

    if (!empty($list)) {
      $ncount = ord($list{0});
      $list   = substr($list, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $deviceID = ord($list{0});
        $list = substr($list, 1);

        $UUID = $this->lightifyBase->chrToUUID(substr($list, 0, classConstant::UUID_DEVICE_LENGTH));
        $name = trim(substr($list, 8, classConstant::DATA_NAME_LENGTH));
        $info = trim(substr($list, 23, classConstant::DATA_CLASS_INFO));

        $Lists = [ 
          'deviceID'   => $deviceID,
          'classInfo'  => $info,
          'UUID'       => $UUID,
          'deviceName' => $name
        ];

        if (!empty($cloud)) {
          $Devices = json_decode($cloud);

          foreach ($Devices as $device) {
            list($cloudID, $type, $manufacturer, $model, $label, $firmware) = $device;

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
        $list = substr($list, classConstant::DATA_DEVICE_LIST);
      }
    }

    //Group list element
    $list = $this->GetBuffer("groupList");

    if (!empty($list)) {
      $ncount = ord($list{0});
      $list   = substr($list, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $groupID = ord($list{0});
        $intUUID = $list{0}.$list{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);
        $name    = trim(substr($list, 2, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($list{18});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_GROUP_INDEX]->values[] = array(
          'groupID'     => $groupID,
          'classInfo'   => $this->Translate("Group"),
          'UUID'        => $UUID,
          'groupName'   => $name,
          'information' => $info
        );

        $list = substr($list, classConstant::DATA_GROUP_LIST);
      }
    }

    //Scene list element
    $list = $this->GetBuffer("sceneList");

    if (!empty($list)) {
      $ncount = ord($sceneList{0});
      $list   = substr($list, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $intUUID = $list{0}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $sceneID = ord($list{0});
        $UUID    = $this->lightifyBase->chrToUUID($intUUID);

        $scene = trim(substr($list, 1, classConstant::DATA_NAME_LENGTH));
        $group = trim(substr($list, 15, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($list{31});
        $info   = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_SCENE_INDEX]->values[] = array(
          'sceneID'     => $sceneID,
          'classInfo'   => $this->Translate("Scene"),
          'UUID'        => $UUID,
          'sceneName'   => $scene,
          'groupName'   => $group,
          'information' => $info
        );

        $list = substr($list, classConstant::DATA_SCENE_LIST);
      }
    }

    return json_encode($data);

  }


  public function ForwardData($jsonString)
  {

    if ($this->ReadPropertyBoolean("open")) {
      $this->setEnvironment();

      $data = json_decode($jsonString);
      $jsonReturn = vtNoString;

      switch ($data->method) {
        case classConstant::METHOD_RELOAD_LOCAL:
          $this->GetLightifyData($data->method);
          break;

        case classConstant::METHOD_LOAD_CLOUD:
          if ($this->connectMode == classConstant::CONNECT_LOCAL_CLOUD) {
            $this->cloudGET($data->buffer);
          }
          break;

        case classConstant::METHOD_STATE_DEVICE:
        case classConstant::METHOD_STATE_GROUP:
        case classConstant::METHOD_ALL_DEVICES:
          $this->setMethodState($data->method, $data->buffer);
          break;

        case classConstant::METHOD_APPLY_CHILD:
          switch ($data->mode) {
            case classConstant::MODE_DEVICE_LOCAL:
              $device = $this->GetBuffer("localDevice");

              if (!empty($device) && ord($device{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($device))
                );
              }
              return $jsonReturn;

            case classConstant::MODE_DEVICE_GROUP:
              $group  = $this->GetBuffer("localGroup");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($group) && !empty($buffer)) {
                $ncount = ord($group{0});

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
              $cloud = $this->GetBuffer("cloudDevice");

              if (!empty($cloud)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => $cloud)
                );
              }
              break;;

            case classConstant::MODE_GROUP_LOCAL:
              $group  = $this->GetBuffer("localGroup");
              $buffer = $this->GetBuffer("groupBuffer");

              if (!empty($group) && !empty($buffer)) {
                $ncount = ord($group{0});

                if ($ncount > 0) {
                  $jsonReturn = json_encode(array(
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode(chr($ncount).$buffer))
                  );
                }
              }
              break;;

            case classConstant::MODE_GROUP_SCENE:
              $scene = $this->GetBuffer("cloudScene");

              if (!empty($scene) && ord($scene{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($scene))
                );
              }
              break;;

            case classConstant::MODE_ALL_SWITCH:
              $allDevices = $this->getAllDevices();

              if (!empty($allDevices)) {
                $ncount = 1;

                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode(chr($ncount).$allDevices))
                );
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData:all>   ".$jsonReturn);
              break;;
          }
      }
    }

    return $jsonReturn;

  }


  private function validateConfig($open, $connect)
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

    if ($open) {
      $this->SetStatus(102);
    } else {
      $this->SetStatus(201);
    }

    return true;

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


  private function setEnvironment()
  {

    $this->connect = $this->ReadPropertyInteger("connectMode");
    $this->debug   = $this->ReadPropertyInteger("debug");
    $this->message = $this->ReadPropertyBoolean("message");

    if ($categories = $this->ReadPropertyString("listCategory")) {
      list($this->deviceCategory, $this->sensorCategory, $this->groupCategory, $this->sceneCategory) = json_decode($categories);

      $this->createDevice = ($this->deviceCategory->categoryID > 0) ? true : false;
      $this->createSensor = ($this->sensorCategory->categoryID > 0) ? true : false;

      $this->createGroup = (($this->createDevice || $this->createSensor) && $this->groupCategory->categoryID > 0) ? true : false;
      $this->createScene = ($this->createGroup && $this->sceneCategory->categoryID > 0) ? true : false;

      $this->syncDevice = ($this->createDevice && $this->deviceCategory->syncID) ? true : false;
      $this->syncSensor = ($this->createSensor && $this->sensorCategory->syncID) ? true : false;

      $this->syncGroup = ($this->createGroup && $this->groupCategory->syncID) ? true : false;
      $this->syncScene = ($this->createScene && $this->sceneCategory->syncID) ? true : false;
    }

  }


  private function localConnect()
  {

    $gatewayIP = $this->ReadPropertyString("gatewayIP");

    try { 
      $lightifySocket = new lightifyConnect($this->InstanceID, $gatewayIP, $this->debug, $this->message);
    } catch (Exception $ex) {
      $error = $ex->getMessage();

      $this->SendDebug("<Gateway|localConnect:socket>", $error, 0);
      IPS_LogMessage("SymconOSR", "<Gateway|localConnect:socket>   ".$error);

      return false;
    }

    return $lightifySocket;

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


  public function GetLightifyData(int $localMethod)
  {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    if ($this->ReadPropertyBoolean("open")) {
      $this->setEnvironment();

      if ($lightifySocket = $this->localConnect()) {
        $error = false;

        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $deviceBuffer = vtNoString;
          $this->SetBuffer("deviceBuffer", $deviceBuffer);

          $groupBuffer = vtNoString;
          $groupDevice = vtNoString;
          $sceneBuffer = vtNoString;

          $this->SetBuffer("groupBuffer", $groupBuffer);
          $this->SetBuffer("groupDevice", $groupDevice);
          $this->SetBuffer("sceneBuffer", $sceneBuffer);

          $this->SetBuffer("deviceList", vtNoString);
          $this->SetBuffer("groupList", vtNoString);
          $this->SetBuffer("sceneList", vtNoString);
        } else {
          $localDevice  = vtNoString;

          $deviceBuffer = $this->GetBuffer("deviceBuffer");
          $groupBuffer  = $this->GetBuffer("groupBuffer");
          $sceneBuffer  = $this->GetBuffer("sceneBuffer");

          $localGroup   = $this->GetBuffer("localGroup");
        }

        //Initialize
        $syncDevice = false;
        $syncSensor = false;
        $syncGroup  = false;
        $syncScene  = false;

        if ($localMethod != classConstant::METHOD_RELOAD_LOCAL) {
          $cloudDevice = $this->GetBuffer("cloudDevice");
          $cloudGroup  = $this->GetBuffer("cloudGroup");
          $cloudScene  = $this->GetBuffer("cloudScene");

          //Get Gateway WiFi and firmware version
          $this->setGatewayInfo($lightifySocket, $localMethod);
        }

        //Get paired devices
        if ($this->syncDevice || $this->syncSensor) {
          if (false !== ($data = $lightifySocket->sendRaw(classCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01)))) {
            if (strlen($data) >= (2 + classConstant::DATA_DEVICE_LENGTH)) {
              $ncount = ord($data{0}) + ord($data{1});
              $data   = $this->structDeviceData($ncount, substr($data, 2));

              if (strcmp($data, $deviceBuffer) != 0) {
                $ncount = ord($data{0});
                $localDevice = $this->structLightifyData(classCommand::GET_DEVICE_LIST, $ncount, substr($data, 2), $deviceBuffer);

                $this->SetBuffer("deviceBuffer", $data);
                $this->SetBuffer("localDevice", $localDevice);

                $syncDevice = true;
              }
            }
          }
        } else {
          $this->SetBuffer("deviceList", vtNoString);
          $this->SetBuffer("deviceGroup", vtNoString);
        }

        //Get Group/Zone list
        if ($this->syncGroup) {
          if ($localMethod == classConstant::METHOD_CREATE_CHILD || empty($localGroup)) {
            if (false !== ($data = $lightifySocket->sendRaw(classCommand::GET_GROUP_LIST, chr(0x00)))) {
              if (strlen($data) >= (2 + classConstant::DATA_GROUP_LENGTH)) {
                $ncount = ord($data{0}) + ord($data{1});
                $localGroup = chr($ncount).substr($data, 2);
              }
            }
          }

          if ($syncDevice && !empty($localGroup)) {
            $ncount = ord($localGroup{0});

            $groupDevice = $this->structLightifyData(classCommand::GET_GROUP_LIST, $ncount, substr($localGroup, 1), $groupBuffer);
            $this->SetBuffer("groupBuffer", $groupDevice);

            $syncGroup = true;
          }
        } else {
          $this->SetBuffer("groupList", vtNoString);
          $this->SetBuffer("groupDevice", vtNoString);

          $this->SetBuffer("sceneList", vtNoString);
        }

        if ($this->connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadPropertyString("osramToken"))) {
          if ($syncDevice && !empty($localDevice)) {
            $deviceList = $this->GetBuffer("deviceList");
            $ncount     = ord($deviceList{0});

            $cloudDevice = $this->structLightifyData(classConstant::GET_DEVICE_CLOUD, $ncount, substr($deviceList, 1));
            $this->SetBuffer("cloudDevice", $cloudDevice);
          }

          if ($syncGroup && !empty($localGroup)) {
            $ncount = ord($groupDevice{0});

            $cloudGroup = $this->structLightifyData(classConstant::GET_GROUP_CLOUD, $ncount);
            $this->SetBuffer("cloudGroup", $cloudGroup);

            if ($this->syncScene && !empty($cloudGroup)) {
              $cloudScene = $this->structLightifyData(classConstant::GET_GROUP_SCENE, vtNoValue, vtNoString, $sceneBuffer);

              if (strcmp($cloudScene, $sceneBuffer) != 0) {
                $this->SetBuffer("cloudScene", $cloudScene);
                $this->SetBuffer("sceneBuffer", $cloudScene);

                $syncScene = true;
              }
            } else {
              $this->SetBuffer("sceneList", vtNoString);
            }
          }
        }

        //Re-read group buffer
        $localGroup = $this->GetBuffer("localGroup");

        //Create childs
        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          if ($syncDevice && !empty($localDevice)) {
            $ncount = ord($localDevice{0});

            if ($ncount > 0) {
              $this->createInstance(classConstant::MODE_CREATE_DEVICE, $ncount, substr($localDevice, 2));
              $message = $this->Translate("Device");
            }

            //Create 'All Lights' dummy switch group
            if ($this->createGroup) {
              $this->createInstance(classConstant::MODE_CREATE_ALL_SWITCH, $ncount, substr($localGroup, 1));
            }
          }

          if ($syncGroup && !empty($localGroup)) {
            $ncount = ord($localGroup{0});

            if ($ncount > 0) {
              $this->createInstance(classConstant::MODE_CREATE_GROUP, $ncount, substr($localGroup, 1));
              $message = $message.", ".$this->Translate("Group");
            }

            if ($syncScene) {
              if (!empty($cloudGroup) && !empty($cloudScene)) {
                $ncount = ord($cloudScene{0});

                if ($ncount > 0) {
                  $this->createInstance(classConstant::MODE_CREATE_SCENE, $ncount, substr($cloudScene, 1));
                  $message = $message.", ".$this->Translate("Scene");
                }
              }
            }
          }

          if (isset($message)) {
            echo $message.$this->Translate(" instances successfully created/updated")."\n";
          } else {
            echo $this->Translate("No instances created/updated")."\n";
          }
        }

        if ($localMethod == classConstant::METHOD_LOAD_LOCAL || $localMethod == classConstant::METHOD_RELOAD_LOCAL) {
          $sendMethod = classConstant::METHOD_UPDATE_CHILD;
        } else {
          $sendMethod = classConstant::METHOD_CREATE_CHILD;
        }

        //Update child informations
        if ($localMethod == classConstant::METHOD_LOAD_LOCAL || $localMethod == classConstant::METHOD_RELOAD_LOCAL || $localMethod == classConstant::METHOD_UPDATE_CHILD) {
          if ($syncDevice) {
            $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE));

            if ($mcount > 0) {
              if (!empty($localDevice) && ord($localDevice{0}) > 0) {
                //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|devices>   ".json_encode(utf8_encode($localDevice)));

                $this->SendDataToChildren(json_encode(array(
                  'DataID' => classConstant::TX_DEVICE,
                  'id'     => $this->InstanceID,
                  'mode'   => classConstant::MODE_DEVICE_LOCAL,
                  'method' => $sendMethod,
                  'buffer' => utf8_encode($localDevice)))
                );
              }

              if ($this->connect == classConstant::CONNECT_LOCAL_CLOUD && $localMethod != classConstant::METHOD_RELOAD_LOCAL) {
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

          if ($syncGroup) {
            $mcount = count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP));

            if ($mcount > 0) {
              if (!empty($localGroup) && ord($localGroup{0}) > 0 && !empty($groupDevice)) {
                //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|groups>   ".json_encode(utf8_encode($groupDevice)));

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
                //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|all:devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
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

            if ($this->connect == classConstant::CONNECT_LOCAL_CLOUD && $localMethod != classConstant::METHOD_RELOAD_LOCAL) {
              if ($this->syncScene && !empty($cloudScene) && ord($cloudScene{0}) > 0) {
                //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|scenes>   ".json_encode(utf8_encode($cloudScene)));

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

/*
          //Update 'All Lights' dummy switch group
          $allDevices = $this->getAllDevices();

          if (!empty($allDevices)) {
            //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|all:devices>   ".json_encode(utf8_encode(chr($ncount).$allDevices)));
            $ncount = 1;

            $this->SendDataToChildren(json_encode(array(
              'DataID'  => classConstant::TX_GROUP,
              'id'      => $this->InstanceID,
              'mode'    => classConstant::MODE_ALL_SWITCH,
              'method'  => $sendMethod,
              'buffer' => utf8_encode(chr($ncount).$allDevices)))
            );
          }
*/
        }
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
        if ($this->syncDevice) {
          return chr($k).chr($i).$device;
        }
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
        if ($this->syncGroup) {
          return chr(classConstant::TYPE_DEVICE_GROUP).$device;
        }
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


  private function setGatewayInfo($lightifySocket, $method)
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
      if (false !== ($data = $lightifySocket->sendRaw(classCommand::GET_GATEWAY_WIFI, classConstant::SCAN_WIFI_CONFIG))) {
        if (strlen($data) >= (2+classConstant::DATA_WIFI_LENGTH)) {
          if (false !== ($SSID = $this->getWiFi($data))) {
            if (GetValueString($ssidID) != $SSID) {
              SetValueString($ssidID, (string)$SSID);
            }
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


  private function createInstance($mode, $ncount = vtNoValue, $data = vtNoString)
  {

    switch ($mode) {
      case classConstant::MODE_CREATE_DEVICE:
        for ($i = 1; $i <= $ncount; $i++) {
          $data = substr($data, 2);
          $deviceID = $i;

          $type  = ord($data{10});
          $coded = true;

          switch ($type) {
            case classConstant::TYPE_PLUG_ONOFF:
              $class = classConstant::CLASS_LIGHTIFY_PLUG;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $class = classConstant::CLASS_LIGHTIFY_SENSOR;
              $categoryID = ($this->syncSensor) ? $this->sensorCategory->categoryID : false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
            case classConstant::TYPE_SWITCH_4WAY:
            case classConstant::TYPE_SWITCH_MINI:
              $coded = false;
              break;

            default:
              $class = classConstant::CLASS_LIGHTIFY_LIGHT;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
          }

          if ($coded && $categoryID && IPS_CategoryExists($categoryID)) {
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
              if (@IPS_GetProperty($InstanceID, "deviceClass") != $class) {
                IPS_SetProperty($InstanceID, "deviceClass", (int)$class);
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
        $categoryID = ($this->syncGroup) ? $this->groupCategory->categoryID : false;

        if ($categoryID && IPS_CategoryExists($categoryID)) {
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
              if (@IPS_GetProperty($InstanceID, "groupClass") != classConstant::CLASS_LIGHTIFY_GROUP) {
                IPS_SetProperty($InstanceID, "groupClass", classConstant::CLASS_LIGHTIFY_GROUP);
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
        }
        break;

      case classConstant::MODE_CREATE_SCENE:
        $data = substr($data, 1); //cut classType
        $categoryID = ($this->syncScene) ? $this->sceneCategory->categoryID : false;

        if ($categoryID && IPS_CategoryExists($categoryID)) {
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

              IPS_SetParent($InstanceID, $this->sceneCategory->categoryID);
              IPS_SetPosition($InstanceID, 210+$sceneID);
              IPS_SetName($InstanceID, $name);

              IPS_SetProperty($InstanceID, "groupID", (int)$sceneID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "groupClass") != classConstant::CLASS_LIGHTIFY_SCENE) {
                IPS_SetProperty($InstanceID, "groupClass", classConstant::CLASS_LIGHTIFY_SCENE);
              }

              if (IPS_HasChanges($InstanceID)) {
                IPS_ApplyChanges($InstanceID);
              }
            }

            $data = substr($data, classConstant::DATA_NAME_LENGTH, ($ncount-$j)*classConstant::DATA_SCENE_LENGTH);
          }
        }
        break;

      case classConstant::MODE_CREATE_ALL_SWITCH:
        $categoryID = ($this->syncGroup) ? $this->groupCategory->categoryID : false;

        if ($categoryID && IPS_CategoryExists($categoryID)) {
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
            if (@IPS_GetProperty($InstanceID, "groupClass") != classConstant::CLASS_ALL_DEVICES) {
              IPS_SetProperty($InstanceID, "groupClass", classConstant::CLASS_ALL_DEVICES);
            }

            if (@IPS_GetProperty($InstanceID, "uintUUID") != $uintUUID) {
              IPS_SetProperty($InstanceID,"uintUUID", $uintUUID);
            }

            if (IPS_HasChanges($InstanceID)) {
              IPS_ApplyChanges($InstanceID);
            }
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
      $class = IPS_GetProperty($instanceID, "deviceClass");

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


  private function setMethodState($method, $data)
  {

    //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState:data>   ".json_encode($data));

    $value  = (int)substr($data, 0, 1); 
    $state = ($value == 1) ? classConstant::SET_STATE_ON : classConstant::SET_STATE_OFF;

    if ($method == classConstant::METHOD_STATE_DEVICE || $method == classConstant::METHOD_ALL_DEVICES) {
      if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE)) > 0) {
        $data = ($method == classConstant::METHOD_STATE_DEVICE) ? substr($data, 2) : utf8_encode($this->getAllDevices());

        $this->SendDataToChildren(json_encode(array(
          'DataID' => classConstant::TX_DEVICE,
          'id'     => $this->InstanceID,
          'mode'   => classConstant::MODE_STATE_DEVICE,
          'method' => $state,
          'buffer' => $data))
        );
      }

      //Update group state
      $localGroup  = $this->GetBuffer("localGroup");
      $groupBuffer = $this->GetBuffer("groupBuffer");

      if (!empty($localGroup) && !empty($groupBuffer)) {
        $ncount = ord($localGroup{0});

        if ($ncount > 0) {
          $buffer = utf8_encode($groupBuffer);
        }
      }

      if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP)) > 0) {
        if ($method == classConstant::METHOD_STATE_DEVICE) $buffer = utf8_encode("-g".chr(classConstant::GROUP_ALL_DEVICES)).$buffer;
        //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState|buffer>   ".json_encode($buffer));

        if (!empty($buffer)) {
          $this->SendDataToChildren(json_encode(array(
            'DataID'  => classConstant::TX_GROUP,
            'id'      => $this->InstanceID,
            'mode'    => classConstant::MODE_STATE_GROUP,
            'method'  => $state,
            'buffer'  => $buffer))
          );
        }
      }
    }

    if ($method == classConstant::METHOD_STATE_GROUP) {
      if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP)) > 0) {
        $buffer = $this->getDeviceGroups(utf8_decode(substr($data, 2)));
        //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState|buffer>   ".json_encode(utf8_encode($buffer)));

        if (!empty($buffer)) {
          $buffer = "-g".chr(classConstant::GROUP_ALL_DEVICES).$buffer;

          $this->SendDataToChildren(json_encode(array(
            'DataID'  => classConstant::TX_GROUP,
            'id'      => $this->InstanceID,
            'mode'    => classConstant::MODE_STATE_GROUP,
            'method'  => $state,
            'buffer'  => utf8_encode($buffer)))
          );
        }
      }
    }

    //Update device buffer state
    $deviceBuffer = $this->GetBuffer("deviceBuffer");
    $suffix = substr($deviceBuffer, 0, 2);
    $buffer = vtNoString;

    if (!empty($deviceBuffer)) {
      $newBuffer = vtNoString;
      $Devices   = utf8_decode($data);
      $length    = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;
      //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState|Devices>   ".$this->lightifyBase->decodeData($Devices));

      while (strlen($Devices) >= $length) {
        $uintBuffer = substr($Devices, 2, classConstant::UUID_DEVICE_LENGTH);
        $decode = substr($deviceBuffer, 2);
        $ncount = ord($deviceBuffer{0});

        for ($i = 1; $i <= $ncount; $i++) {
          $zigBee = substr($decode, 0, 2);
          $decode = substr($decode, 2);

          $uintUUID  = substr($decode, 2, classConstant::UUID_DEVICE_LENGTH);
          $newDevice = substr($decode, 0, classConstant::DATA_DEVICE_LENGTH);

          if ($uintBuffer == $uintUUID) {
            $name = substr($decode, 26, classConstant::DATA_NAME_LENGTH);
            $newDevice  = substr_replace($newDevice, chr($value), 18, 1);
            //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState|new:device>   ".$i."/".$this->lightifyBase->ChrToUUID($uintBuffer)."/".$this->lightifyBase->ChrToUUID($uintUUID)."/".trim($name)."/".ord($newDevice{18}));
          }

          $newBuffer .= $zigBee.$newDevice;
          $decode     = substr($decode, classConstant::DATA_DEVICE_LENGTH);
        }

        $Devices = substr($Devices, $length);
      }
    }

    if (!empty($newBuffer)) {
      //IPS_LogMessage("SymconOSR", "<Gateway|setMethodState|new:buffer>   ".json_encode(utf8_encode($suffix.$newBuffer)));
      $this->SetBuffer("deviceBuffer", $suffix.$newBuffer);
    }

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


}