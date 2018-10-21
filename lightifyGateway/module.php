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
    $this->RegisterPropertyBoolean("allSwitch", false);

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

    $deviceList = $this->GetBuffer("deviceList");
    $groupList  = $this->GetBuffer("groupList");
    $sceneList  = $this->GetBuffer("sceneList");

    $formElements    = [];
    $formElements [] = ['type' => "CheckBox", 'name' => "open", 'caption' => " Open"];

    $connectOptions    = [];
    $connectOptions [] = ['label' => "Local only",      'value' => 1001];
    $connectOptions [] = ['label' => "Local and Cloud", 'value' => 1002];

    $formElements [] = ['type' => "Select",       'name'  => "connectMode",       'caption' => " Connection", 'options' => $connectOptions];
    $formElements [] = ['name' => "gatewayIP",    'type'  => "ValidationTextBox", 'caption' => "Gateway IP"];
    $formElements [] = ['name' => "serialNumber", 'type'  => "ValidationTextBox", 'caption' => "Serial number"];
    $formElements [] = ['name' => "localUpdate",  'type'  => "NumberSpinner",     'caption' => "Update interval [s]"];
    $formElements [] = ['type' => "Label",        'label' => ""];

    $categoryColumns    = [];
    $categoryColumns [] = ['label' => "Type",        'name' => "Device",     'width' =>  "55px"];
    $categoryColumns [] = ['label' => "Category",    'name' => "Category",   'width' => "265px"];
    $categoryColumns [] = ['label' => "Category ID", 'name' => "categoryID", 'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "SelectCategory"]];
    $categoryColumns [] = ['label' => "Sync",        'name' => "Sync",       'width' =>  "35px"];
    $categoryColumns [] = ['label' => "Sync ID",     'name' => "syncID",     'width' =>  "10px", 'visible' => false, 'edit' => ['type' => "CheckBox", 'caption' => " Synchronise values"]];

    $formElements [] = ['type' => "List",     'name' => "listCategory", 'caption' => "Categories", 'columns' => $categoryColumns];
    $formElements [] = ['type' => "CheckBox", 'name' => "reloadLocal",  'caption' => " Reload data instantly (not recommended: higher gateway load and longer response time)"];
    $formElements [] = ['type' => "CheckBox", 'name' => "deviceInfo",   'caption' => " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)"];

    if (!empty($deviceList) && ord($deviceList{0}) > 0) {
      $cloudDevice = $this->GetBuffer("cloudDevice");

      $deviceColumns    = [];
      $deviceColumns [] = ['label' => "ID",    'name' => "deviceID",   'width' =>  "30px"];
      $deviceColumns [] = ['label' => "Class", 'name' => "classInfo",  'width' =>  "65px"];
      $deviceColumns [] = ['label' => "Name",  'name' => "deviceName", 'width' => "110px"];
      $deviceColumns [] = ['label' => "UUID",  'name' => "UUID",       'width' => "140px"];

      if (!empty($cloudDevice)) {
        $deviceColumns [] = ['label' => "Manufacturer", 'name' => "manufacturer", 'width' =>  "80px"];
        $deviceColumns [] = ['label' => "Model",        'name' => "deviceModel",  'width' => "130px"];
        $deviceColumns [] = ['label' => "Capabilities", 'name' => "deviceLabel",  'width' => "175px"];
        $deviceColumns [] = ['label' => "Firmware",     'name' => "firmware",     'width' =>  "65px"];
      }

      $formElements [] = ['type' => "List", 'name' => "listDevice", 'caption' => "Devices", 'columns' => $deviceColumns];
    }

    if (!empty($groupList) && ord($groupList{0}) > 0) {
      $groupColumns    = [];
      $groupColumns [] = ['label' => "ID",    'name' => "groupID",     'width' =>  "30px"];
      $groupColumns [] = ['label' => "Class", 'name' => "classInfo",   'width' =>  "65px"];
      $groupColumns [] = ['label' => "Name",  'name' => "groupName",   'width' => "110px"];
      $groupColumns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "140px"];
      $groupColumns [] = ['label' => "Info",  'name' => "information", 'width' => "110px"];

      $formElements [] = ['type' => "List", 'name' => "listGroup", 'caption' => "Groups", 'columns' => $groupColumns];
    }

    if (!empty($sceneList) && ord($sceneList{0}) > 0) {
      $sceneColumns    = [];
      $sceneColumns [] = ['label' => "ID",    'name' => "sceneID",     'width' =>  "30px"];
      $sceneColumns [] = ['label' => "Class", 'name' => "classInfo",   'width' =>  "65px"];
      $sceneColumns [] = ['label' => "Name",  'name' => "sceneName",   'width' => "110px"];
      $sceneColumns [] = ['label' => "UUID",  'name' => "UUID",        'width' => "140px"];
      $sceneColumns [] = ['label' => "Group", 'name' => "groupName",   'width' => "110px"];
      $sceneColumns [] = ['label' => "Info",  'name' => "information", 'width' =>  "70px"];

      $formElements [] = ['type' => "List", 'name' => "listScene", 'caption' => "Scenes", 'columns' => $sceneColumns];
    }

    $debugOptions    = [];
    $debugOptions [] = ['label' => "Disabled",            'value' =>  0];
    $debugOptions [] = ['label' => "Send buffer",         'value' =>  3];
    $debugOptions [] = ['label' => "Receive buffer",      'value' =>  7];
    $debugOptions [] = ['label' => "Send/Receive buffer", 'value' => 13];
    $debugOptions [] = ['label' => "Detailed error log",  'value' => 17];

    $formElements [] = ['type' => "Select",   'name' => "debug",   'caption' => "Debug", 'options' => $debugOptions];
    $formElements [] = ['type' => "CheckBox", 'name' => "message", 'caption' => "Messages"];

    $formActions    = [];
    $formActions [] = ['type' => "Button", 'label' => "Register",        'onClick' => "echo OSR_LightifyRegister(\$id)"];
    $formActions [] = ['type' => "Label",  'label' => "Press Create | Update to automatically apply the devices and settings"];
    $formActions [] = ['type' => "Button", 'label' => "Create | Update", 'onClick' => "OSR_GetLightifyData(\$id, 1208)"];

    $formStatus    = [];
    $formStatus [] = ['code' => 101, 'icon' => "inactive", 'caption' => "Lightify gateway is closed"];
    $formStatus [] = ['code' => 102, 'icon' => "active",   'caption' => "Lightify gateway is open"];
    $formStatus [] = ['code' => 104, 'icon' => "inactive", 'caption' => "Enter all required informations"];
    $formStatus [] = ['code' => 201, 'icon' => "inactive", 'caption' => "Lightify gateway is not connected"];
    $formStatus [] = ['code' => 202, 'icon' => "error",    'caption' => "Invalid IP address"];
    $formStatus [] = ['code' => 203, 'icon' => "error",    'caption' => "Invalid Serial number"];
    $formStatus [] = ['code' => 205, 'icon' => "error",    'caption' => "Update interval < 3s"];
    $formStatus [] = ['code' => 299, 'icon' => "error",    'caption' => "Unknown error"];

    //Encode configuration form
    $formJSON = json_encode(['elements' => $formElements, 'actions' => $formActions, 'status' => $formStatus]);

    $data = json_decode($formJSON);
    $Types = array("Device", "Sensor", "Group", "Scene");

    //Only add default element if we do not have anything in persistence
    if (empty($this->ReadPropertyString("listCategory"))) {
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
      $listCategory = json_decode($this->ReadPropertyString("listCategory"));

      foreach ($listCategory as $index => $row) {
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
    if (!empty($deviceList)) {
      $ncount     = ord($deviceList{0});
      $deviceList = substr($deviceList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $Devices    = json_decode($cloudDevice);
        $deviceID   = ord($deviceList{0});
        $deviceList = substr($deviceList, 1);

        $uint64     = substr($deviceList, 0, classConstant::UUID_DEVICE_LENGTH);
        $UUID       = $this->lightifyBase->chrToUUID($uint64);
        $deviceName = trim(substr($deviceList, 8, classConstant::DATA_NAME_LENGTH));
        $classInfo  = trim(substr($deviceList, 23, classConstant::DATA_CLASS_INFO));

        $arrayList  = array(
          'deviceID'   => $deviceID,
          'classInfo'  => $classInfo,
          'UUID'       => $UUID,
          'deviceName' => $deviceName
        );

        if (!empty($Devices)) {
          foreach ($Devices as $device) {
            list($cloudID, $deviceType, $manufacturer, $deviceModel, $deviceLabel, $firmware) = $device;

            if ($deviceID == $cloudID) {
              $arrayList = $arrayList + array(
                'manufacturer' => $manufacturer,
                'deviceModel'  => $deviceModel,
                'deviceLabel'  => $deviceLabel,
                'firmware'     => $firmware
              );
              break;
            }
          }
        }

        $data->elements[self::LIST_DEVICE_INDEX]->values[] = $arrayList;
        $deviceList = substr($deviceList, classConstant::DATA_DEVICE_LIST);
      }
    }

    //Group list element
    if (!empty($groupList)) {
      $ncount    = ord($groupList{0});
      $groupList = substr($groupList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $groupID     = ord($groupList{0});
        $intUUID     = $groupList{0}.$groupList{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $UUID        = $this->lightifyBase->chrToUUID($intUUID);
        $groupName   = trim(substr($groupList, 2, classConstant::DATA_NAME_LENGTH));

        $dcount      = ord($groupList{18});
        $information = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_GROUP_INDEX]->values[] = array(
          'groupID'     => $groupID,
          'classInfo'   => $this->Translate("Group"),
          'UUID'        => $UUID,
          'groupName'   => $groupName,
          'information' => $information
        );

        $groupList = substr($groupList, classConstant::DATA_GROUP_LIST);
      }
    }

    //Scene list element
    if (!empty($sceneList)) {
      $ncount    = ord($sceneList{0});
      $sceneList = substr($sceneList, 1);

      for ($i = 1; $i <= $ncount; $i++) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        $intUUID   = $sceneList{0}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
        $sceneID   = ord($sceneList{0});
        $UUID      = $this->lightifyBase->chrToUUID($intUUID);
        $sceneName = trim(substr($sceneList, 1, classConstant::DATA_NAME_LENGTH));
        $groupName = trim(substr($sceneList, 15, classConstant::DATA_NAME_LENGTH));

        $dcount = ord($sceneList{31});
        $information = ($dcount == 1) ? $dcount.$this->Translate(" Device") : $dcount.$this->Translate(" Devices");

        $data->elements[self::LIST_SCENE_INDEX]->values[] = array(
          'sceneID'     => $sceneID,
          'classInfo'   => $this->Translate("Scene"),
          'UUID'        => $UUID,
          'sceneName'   => $sceneName,
          'groupName'   => $groupName,
          'information' => $information
        );

        $sceneList = substr($sceneList, classConstant::DATA_SCENE_LIST);
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
          if (!empty($data->buffer)) {
            $this->setMethodState($data->method, $data->buffer);
          }
          break;

        case classConstant::METHOD_APPLY_CHILD:
          switch ($data->mode) {
            case classConstant::MODE_DEVICE_LOCAL:
              $localDevice = $this->GetBuffer("localDevice");

              if (!empty($localDevice) && ord($localDevice{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($localDevice))
                );
              }
              return $jsonReturn;

            case classConstant::MODE_DEVICE_GROUP:
              $localGroup  = $this->GetBuffer("localGroup");
              $groupBuffer = $this->GetBuffer("groupBuffer");

              if (!empty($localGroup) && !empty($groupBuffer)) {
                $ncount = ord($localGroup{0});

                if ($ncount > 0) {
                  $bufferUID = $this->setModeDeviceGroup($ncount, substr($groupBuffer, 1), $data->buffer);

                  $jsonReturn = json_encode(array(
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode($bufferUID))
                  );
                }
              }
              //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|devices:groups>   ".$jsonReturn);
              break;;

            case classConstant::MODE_DEVICE_CLOUD:
              $cloudDevice = $this->GetBuffer("cloudDevice");

              if (!empty($cloudDevice)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => $cloudDevice)
                );
              }
              break;;

            case classConstant::MODE_GROUP_LOCAL:
              $localGroup  = $this->GetBuffer("localGroup");
              $groupBuffer = $this->GetBuffer("groupBuffer");

              if (!empty($localGroup) && !empty($groupBuffer)) {
                $ncount = ord($localGroup{0});

                if ($ncount > 0) {
                  $jsonReturn = json_encode(array(
                    'id'     => $this->InstanceID,
                    'buffer' => utf8_encode(chr($ncount).$groupBuffer))
                  );
                }
              }
              break;;

            case classConstant::MODE_GROUP_SCENE:
              $cloudScene = $this->GetBuffer("cloudScene");

              if (!empty($cloudScene) && ord($cloudScene{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($cloudScene))
                );
              }
              break;;

            case classConstant::MODE_ALL_SWITCH:
              $allLights = $this->GetBuffer("allLights");
              $ncount    = 1;

              if (!empty($allLights)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode(chr($ncount).$allLights))
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

    //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
    $cURL    = curl_init();
    $options = array(
      CURLOPT_URL            => self::OAUTH_ACCESS_TOKEN.$this->oAuthIdent,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => "POST",
      CURLOPT_POSTFIELDS     => "code=".$code,
      CURLOPT_HTTPHEADER     => array(
        self::HEADER_FORM_CONTENT
      )
    );

    curl_setopt_array($cURL, $options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    $data   = json_decode($result);
    curl_close($cURL);

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|getAccessToken:result>", $result, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<Gateway|getAccessToken:result>   ".$result);
      }

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      $this->SetBuffer("applyMode", 0);

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|getAccessToken:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|getAccessToken:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|getAccessToken:refresh>", $data->refresh_token, 0);
      }

      if ($this->message) {
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
    $options = array(
      CURLOPT_URL            => self::OAUTH_ACCESS_TOKEN.$this->oAuthIdent,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => "POST",
      CURLOPT_POSTFIELDS     => "refresh_token=".$data->refresh_token,
      CURLOPT_HTTPHEADER     => array(
        self::HEADER_FORM_CONTENT
      )
    );

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
    $options = array(
      CURLOPT_URL            => self::LIGHTIFY_EUROPE.self::LIGHTIFY_VERSION.$ressource,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING       => vtNoString,
      CURLOPT_MAXREDIRS      => self::LIGHTIFY_MAXREDIRS,
      CURLOPT_TIMEOUT        => self::LIGHTIFY_TIMEOUT,
      CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST  => $method,
      CURLOPT_HTTPHEADER     => array(
        self::HEADER_AUTHORIZATION.$accessToken,
        self::HEADER_JSON_CONTENT
      )
    );

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
      if ($lightifySocket = $this->localConnect()) {
        $this->setEnvironment();
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

          $this->SetBuffer("deviceUID", vtNoString);
          $this->SetBuffer("allLights", vtNoString);
        } else {
          $localDevice  = vtNoString;

          $deviceBuffer = $this->GetBuffer("deviceBuffer");
          $groupBuffer  = $this->GetBuffer("groupBuffer");
          $sceneBuffer  = $this->GetBuffer("sceneBuffer");

          $localGroup   = $this->GetBuffer("localGroup");
        }

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
              $allLights = $this->GetBuffer("allLights");
              $ncount    = 1;

              if (!empty($allLights)) {
                //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|all:lights>   ".json_encode(utf8_encode(chr($ncount).$allLights)));

                $this->SendDataToChildren(json_encode(array(
                  'DataID'  => classConstant::TX_GROUP,
                  'id'      => $this->InstanceID,
                  'mode'    => classConstant::MODE_ALL_SWITCH,
                  'method'  => $sendMethod,
                  'buffer' => utf8_encode(chr($ncount).$allLights)))
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

          if ($syncDevice && $syncGroup) {
            $deviceUID = $this->GetBuffer("deviceUID");

            if (!empty($deviceUID)) {
              $localGroup  = $this->GetBuffer("localGroup");
              $groupBuffer = $this->GetBuffer("groupBuffer");

              if (!empty($groupBuffer)) {
                $ncount = ord($localGroup{0});

                if ($ncount > 0) {
                  $bufferUID = $this->setDeviceUID($ncount, $deviceUID, $groupBuffer);

                  if (!empty($bufferUID)) {
                    //IPS_LogMessage("SymconOSR", "<Gateway|GetLightifyData|buffer:uid>   ".json_encode(utf8_encode($bufferUID{0}.$bufferUID)));
                    $this->SetBuffer("deviceUID", $bufferUID);
                  }
                }
              }
            }
          }
        }
      }
    }

  }


  private function structDeviceData($ncount, $data)
  {

    $deviceUID   = vtNoString;
    $uuidBuffer  = vtNoString;
    $localDevice = vtNoString;

    for ($i = 1, $j = 0, $n = 0; $i <= $ncount; $i++) {
      $type = ord($data{10});

      $deviceID = chr($i);
      $noType   = false;

      //Decode Device label
      switch ($type) {
        case classConstant::TYPE_FIXED_WHITE:
        case classConstant::TYPE_LIGHT_CCT:
        case classConstant::TYPE_LIGHT_DIMABLE:
        case classConstant::TYPE_LIGHT_COLOR:
        case classConstant::TYPE_LIGHT_EXT_COLOR:
        case classConstant::TYPE_PLUG_ONOFF:
          $deviceUID  .= "-d".$deviceID;
          $uuidBuffer .= "-d".$deviceID.substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
          $n += 1;
          break;

        case classConstant::TYPE_SENSOR_MOTION:
        case classConstant::TYPE_DIMMER_2WAY:
        case classConstant::TYPE_SWITCH_4WAY:
        case classConstant::TYPE_UNKNOWN:
          break;

        default:
          $noType = true;

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structDeviceData|devices:local>   Device type <".$type."> unknown!");
          }
      }

      if ($noType) continue;
      $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);

      $localDevice .= "-d".$deviceID.$device;
      $j += 1;

      if (($length = strlen($data)) > classConstant::DATA_DEVICE_LOADED) {
        $length = classConstant::DATA_DEVICE_LOADED;
      }

      $data = substr($data, $length);
    }

    //Store at runtime
    $groupID   = classConstant::GROUPID_ALL_DEVICES;
    $allLights = chr(classConstant::TYPE_ALL_DEVICES)."-g".chr($groupID).chr($n).$uuidBuffer;

    $this->SetBuffer("deviceUID", chr($n).$deviceUID);
    $this->SetBuffer("allLights", $allLights);

    return chr($j).chr($i).$localDevice;

  }


  private function structLightifyData($command, $ncount = vtNoValue, $data = vtNoString, $buffer = vtNoString)
  {

    switch ($command) {
      case classCommand::GET_DEVICE_LIST:
        $deviceList  = vtNoString;
        $deviceGroup = vtNoString;
        $localDevice = vtNoString;
        $deviceLabel = array();

        //Parse devices
        for ($i = 1, $j = 0, $k = 0, $n = 0; $i <= $ncount; $i++, $j++) {
          $deviceID = $data{2};
          $data     = substr($data, 3);

          $type  = ord($data{10});
          $info  = $this->Translate("Light");
          $withGroup = true;

          //Decode device label
          switch ($type) {
            case classConstant::TYPE_FIXED_WHITE:
              $label = classConstant::LABEL_FIXED_WHITE;
              break;

            case classConstant::TYPE_LIGHT_CCT:
              $label = classConstant::LABEL_LIGHT_CCT;
              break;

            case classConstant::TYPE_LIGHT_DIMABLE:
              $label = classConstant::LABEL_LIGHT_DIMABLE;
              break;

            case classConstant::TYPE_LIGHT_COLOR:
              $label = classConstant::LABEL_LIGHT_COLOR;
              break;

            case classConstant::TYPE_LIGHT_EXT_COLOR:
              $label = classConstant::LABEL_LIGHT_EXT_COLOR;
              break;

            case classConstant::TYPE_PLUG_ONOFF:
              $label = classConstant::LABEL_PLUG_ONOFF;
              $info  = $this->Translate("Plug");
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $label = classConstant::LABEL_SENSOR_MOTION;
              $info = $this->Translate("Sensor");
              $withGroup = false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
              $label = classConstant::LABEL_DIMMER_2WAY;
              $info  = $this->Translate("Dimmer");
              $withGroup = false;
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $label = classConstant::LABEL_SWITCH_4WAY;
              $info  = $this->Translate("Switch");
              $withGroup = false;
              break;

            case classConstant::TYPE_UNKNOWN:
              $label = classConstant::LABEL_UNKNOWN;
              $info  = "-Unknown-";
              $withGroup = false;
              break;
          }

          $info   = str_pad($info, classConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);
          $uint64 = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);

          $deviceName  = substr($data, 26, classConstant::DATA_NAME_LENGTH);
          $deviceList .= $deviceID.$uint64.$deviceName.$info;

          $deviceLabel[$i] = $label;
          $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
          $index  = stripos($buffer, $device);

          if ($index === false || ($index && substr_compare($buffer, $device, $index, classConstant::DATA_DEVICE_LENGTH))) {
            $localDevice .= "-d".$deviceID.$device;
            $k += 1;
          }

          //Device group
          if ($withGroup) {
            $deviceGroup .= "-d".$deviceID.$uint64.substr($data, 16, 2);
            $n += 1; 
          }

          $data = substr($data, classConstant::DATA_DEVICE_LENGTH);
        }

        //Store at runtime
        $this->SetBuffer("deviceList", chr($j).$deviceList);
        $this->SetBuffer("deviceGroup", chr($n).$deviceGroup);
        $this->SetBuffer("deviceLabel", json_encode($deviceLabel));

        if ($this->debug % 2 || $this->message) {
          $info = ($k > 0) ? $k."/".$i."/".$this->lightifyBase->decodeData($localDevice) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|devices:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:local>   ".$info);
          }
        }

        //Return device buffer string
        if ($this->syncDevice) {
          return chr($k).chr($i).$localDevice;
        }
        break;

      case classConstant::GET_DEVICE_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_DEVICES);
        if (empty($cloudBuffer)) return vtNoString;

        $cloudBuffer = json_decode($cloudBuffer);
        $labelBuffer = json_decode($this->GetBuffer("deviceLabel"));
        $gateway     = $cloudBuffer->devices[0];

        if ($gateway->name == strtoupper($this->ReadPropertyString("serialNumber"))) {
          $gatewayID = $gateway->id;
          unset($cloudBuffer->devices[0]);

          for ($i = 1; $i <= $ncount; $i++) {
            $deviceID   = ord($data{0});
            $data       = substr($data, 1);
            $deviceName = trim(substr($data, 8, classConstant::DATA_NAME_LENGTH));

            foreach ($cloudBuffer->devices as $devices => $device) {
              $cloudID = $gatewayID."-d".str_pad((string)$deviceID, 2, "0", STR_PAD_LEFT);

              //if ($cloudID == $device->id) {
              if ($deviceName == trim($device->name)) {
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

                if (is_object($labelBuffer)) {
                  $label = $labelBuffer->$deviceID;
                }

                $cloudDevice[] = array(
                  $deviceID, $device->type,
                  classConstant::MODEL_MANUFACTURER,
                  $model, $label,
                  $device->firmwareVersion
                );
                break;
              }
            }

            $data = substr($data, classConstant::DATA_DEVICE_LIST);
          }

          if (isset($cloudDevice)) {
            $cloudDevice = json_encode($cloudDevice);

            if ($this->debug % 2 || $this->message) {
              $jsonBuffer = json_encode($cloudBuffer);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|devices:cloud>", $jsonBuffer, 0);
                $this->SendDebug("<Gateway|structLightifyData|devices:cloud>", $cloudDevice, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:cloud>   ".$jsonBuffer);
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|devices:cloud>   ".$cloudDevice);
              }
            }

            return $cloudDevice;
          }
        }
        break;

      case classCommand::GET_GROUP_LIST:
        $type = classConstant::TYPE_DEVICE_GROUP;

        $localGroup  = vtNoString;
        $groupDevice = vtNoString;
        $groupList   = vtNoString;

        for ($i = 1; $i <= $ncount; $i++) {
          $group   = substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $groupID = ord($data{0});
          $index   = stripos($buffer, $group);

          $deviceGroup = $this->GetBuffer("deviceGroup");
          $dcount = ord($deviceGroup{0});

          $uuidBuffer = vtNoString;
          $n = 0;

          if ($dcount > 0) {
            $deviceGroup = substr($deviceGroup, 1);

            for ($j = 1; $j <= $dcount; $j++) {
              $deviceID = $deviceGroup{2};
              $groups   = $this->lightifyBase->decodeGroup(ord($deviceGroup{11}), ord($deviceGroup{12}));
              $deviceGroup = substr($deviceGroup, 3);
              //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH)));

              foreach ($groups as $key) {
                if ($groupID == $key) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH)));
                  $deviceUID   = "-d".$deviceID;
                  $uuidBuffer .= $deviceUID.substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH);
                  $n += 1;
                  break;
                }
              }

              $deviceGroup = substr($deviceGroup, classConstant::DATA_GROUP_DEVICE);
            }
          }

          $localGroup  .= substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $groupList   .= substr($data, 0, classConstant::DATA_GROUP_LENGTH).chr($n);
          $groupDevice .= "-g".chr($groupID).chr($n).$uuidBuffer;

          if (($length = strlen($data)) > classConstant::DATA_GROUP_LENGTH) {
            $length = classConstant::DATA_GROUP_LENGTH;
          }

          $data = substr($data, $length);
        }

        //Store at runtime
        $this->SetBuffer("localGroup", chr($ncount).$localGroup);
        $this->SetBuffer("groupList", chr($ncount).$groupList);

        if ($this->debug % 2 || $this->message) {
          $info = ($ncount > 0) ? $ncount."/".$type."/".$this->lightifyBase->decodeData($localGroup) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|groups:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$info);
          }
        }

        //Return group buffer string
        if ($this->syncGroup) {
          return chr($type).$groupDevice;
        }
        break;

      case classConstant::GET_GROUP_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_GROUPS);

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|structLightifyData|groups:cloud>", $cloudBuffer, 0);
        }

        if ($this->message) {
          IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:cloud>   ".$cloudBuffer);
        }
        return $cloudBuffer;

      case classConstant::GET_GROUP_SCENE:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_SCENES);
        if (empty($cloudBuffer)) return vtNoString;

        $sceneCloud = json_decode($cloudBuffer);
        $cloudGroup = $this->GetBuffer("cloudGroup");

        if (!empty($cloudGroup)) {
          $type = classConstant::TYPE_GROUP_SCENE;
          $cloudGroup = json_decode($cloudGroup);

          $cloudScene = vtNoString;
          $sceneList  = vtNoString;
          $i = 0;

          foreach ($cloudGroup->groups as $group) {
            $groupScenes = $group->scenes;
            $groupName   = str_pad($group->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);

            if (!empty($groupScenes)) {
              $j = 0;

              foreach ($groupScenes as $sceneID) {
                foreach ($sceneCloud->scenes as $scene) {
                  if ($sceneID == $scene->id) {
                    $groupID = (int)substr($group->id, -2);
                    $sceneID = (int)substr($scene->id, -2);

                    $sceneName   = str_pad($scene->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);
                    //$cloudScene .= chr($groupID).chr($sceneID).$sceneName;
                    $cloudScene .= "-g".chr($groupID)."-s".chr($sceneID).$sceneName;
                    $sceneList  .= chr($sceneID).$sceneName.$groupName.chr(count($group->devices));

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

          if (!empty($cloudScene)) {
            $result = chr($i).chr($type).$cloudScene;

            if ($this->debug % 2 || $this->message) {
              $info = $i."/".$type."/".$this->lightifyBase->decodeData($cloudScene);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|scenes:cloud>", $info, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|scenes:cloud>   ".$info);
              }
            }

            if (strcmp($result, $buffer) != 0) {
              return chr($i).chr($type).$cloudScene;
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
          $deviceID = ord($data{2});
          $data     = substr($data, 3);

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
              $coded = false;
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $coded = false;
              break;

            case classConstant::TYPE_UNKNOWN:
              $class = classConstant::CLASS_UNKNOWN;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
              break;

            default:
              $class = classConstant::CLASS_LIGHTIFY_LIGHT;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
          }

          if ($coded && $categoryID && IPS_CategoryExists($categoryID)) {
            $uintUUID   = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $InstanceID = $this->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_DEVICE);

              $name = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
              $name = (!empty($name)) ? $name : "-Unknown-";
              //IPS_LogMessage("SymconOSR", "<createInstance|devices>   ".$i."/".$deviceID."/".$type."/".$name."/".$this->lightifyBase->decodeData($data));

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetPosition($InstanceID, 210+$deviceID);
              IPS_SetName($InstanceID, $name);

              IPS_SetProperty($InstanceID, "deviceID", (int)$deviceID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "deviceClass") != $class) {
                IPS_SetProperty($InstanceID, "deviceClass", (int)$class);
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
          $groupID    = classConstant::GROUPID_ALL_DEVICES;
          $uintUUID   = chr($groupID).chr(0x00).chr(classConstant::TYPE_ALL_DEVICES).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
          $InstanceID = $this->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);

          if (!$InstanceID) {
            $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

            IPS_SetParent($InstanceID, $categoryID);
            IPS_SetName($InstanceID, $this->Translate("All Lights"));
            IPS_SetPosition($InstanceID, 210);

            IPS_SetProperty($InstanceID, "groupID", (int)$groupID);
          }

          if ($InstanceID) {
            if (@IPS_GetProperty($InstanceID, "groupClass") != classConstant::CLASS_ALL_DEVICES) {
              IPS_SetProperty($InstanceID, "groupClass", classConstant::CLASS_ALL_DEVICES);
            }

            if (IPS_HasChanges($InstanceID)) {
              IPS_ApplyChanges($InstanceID);
            }
          }
        }
        break;
    }

  }


  private function setMethodState($method, $data)
  {

    //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData:data>   ".json_encode($data));

    $value  = (int)substr($data, 0, 1); 
    $state = ($value == 1) ? classConstant::SET_STATE_ON : classConstant::SET_STATE_OFF;
    $data  = substr($data, 2);

    if ($method == classConstant::METHOD_STATE_DEVICE || $method == classConstant::METHOD_ALL_DEVICES) {
      if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE)) > 0) {
        $this->SendDataToChildren(json_encode(array(
          'DataID' => classConstant::TX_DEVICE,
          'id'     => $this->InstanceID,
          'mode'   => classConstant::MODE_STATE_DEVICE,
          'method' => $state,
          'buffer' => $data))
        );
      }
    }

    //Update device buffer state
    $deviceBuffer = $this->GetBuffer("deviceBuffer");
    $suffix = substr($deviceBuffer, 0, 2);
    $buffer = vtNoString;

    if (!empty($deviceBuffer)) {
      $newBuffer = vtNoString;
      $Devices   = utf8_decode($data);

      while (strlen($Devices) >= classConstant::ITEM_FILTER_LENGTH) {
        $localID = ord(substr($Devices, 2, 1));
        $ncount  = ord($deviceBuffer{0});
        $decode  = substr($deviceBuffer, 2);

        for ($i = 1; $i <= $ncount; $i++) {
          $deviceID  = ord($decode{2});
          $decode    = substr($decode, 3);
          $newDevice = substr($decode, 0, classConstant::DATA_DEVICE_LENGTH);

          if ($localID == $deviceID) {
            $name = substr($decode, 26, classConstant::DATA_NAME_LENGTH);
            $newDevice  = substr_replace($newDevice, chr($value), 18, 1);
            //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|new:device>   ".$i."/".$localID."/".$deviceID."/".trim($name)."/".ord($newDevice{18}));
          }

          $newBuffer .= $newDevice;
          $decode     = substr($decode, classConstant::DATA_DEVICE_LENGTH);
        }
        $Devices = substr($Devices, classConstant::ITEM_FILTER_LENGTH);
      }
    }

    if (!empty($newBuffer)) {
      //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|new:buffer>   ".json_encode(utf8_encode($newBuffer)));
      $this->SetBuffer("deviceBuffer", $suffix.$newBuffer);
    }

    if ($method == classConstant::METHOD_STATE_DEVICE) {
      $allLights = $this->GetBuffer("allLights");

      $ncount = 1;
      $buffer = utf8_encode(chr($ncount).$allLights);
      $mode   = classConstant::MODE_STATE_GROUP;
    }

    if ($method == classConstant::METHOD_ALL_DEVICES) {
      $localGroup  = $this->GetBuffer("localGroup");
      $groupBuffer = $this->GetBuffer("groupBuffer");

      if (!empty($localGroup) && !empty($groupBuffer)) {
        $ncount = ord($localGroup{0});

        if ($ncount > 0) {
          $buffer = utf8_encode(chr($ncount).$groupBuffer);
          $mode   = classConstant::MODE_ALL_SWITCH;
        }
      }
    }

    if ($method == classConstant::METHOD_STATE_GROUP) {
      $buffer = $data;
      $mode   = classConstant::MODE_STATE_GROUP;
    }

    if (!empty($buffer)) {
      if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP)) > 0) {
        //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|buffer>   ".json_encode(utf8_encode($buffer)));

        $this->SendDataToChildren(json_encode(array(
          'DataID'  => classConstant::TX_GROUP,
          'id'      => $this->InstanceID,
          'mode'    => $mode,
          'method'  => $state,
          'buffer'  => $buffer))
        );
      }
    }

  }


  private function setModeDeviceGroup($ncount, $data, $buffer)
  {

    $bufferUID   = "-g".chr(classConstant::GROUPID_ALL_DEVICES);

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
            //IPS_LogMessage("SymconOSR", "<Gateway|ForwardData|devices:group>s   ".$i."/".ord($groupID)."/".$dcount."/".$data->buffer."  ".$j."/".$UUID);
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


  private function setDeviceUID($ncount, $deviceUID, $groupBuffer)
  {

    $bufferUID = vtNoString;
    $mcount    = ord($deviceUID{0});
    $deviceUID = substr($deviceUID, 1);

    for ($i = 1, $n = 0; $i <= $mcount; $i++) {
      $groupDevice = substr($groupBuffer, 1);

      $localID  = $deviceUID{2};
      $groupUID = "-g".chr(classConstant::GROUPID_ALL_DEVICES);
      $m = 1;

      for ($j = 1; $j <= $ncount; $j++) {
        $groupID = $groupDevice{2};
        $dcount  = ord($groupDevice{3});

        if ($dcount > 0) {
          $length = classConstant::ITEM_FILTER_LENGTH+classConstant::UUID_DEVICE_LENGTH;

          $groupDevice = substr($groupDevice, 4);
          $uuidBuffer  = substr($groupDevice, 0, $dcount*$length);

          for ($k = 1; $k <= $dcount; $k++) {
            $deviceID = $uuidBuffer{2};

            if (ord($localID) == ord($deviceID)) {
              $groupUID .= "-g".$groupID;
              $m += 1;
              break;
            }
            $uuidBuffer = substr($uuidBuffer, $length);
          }

          $groupDevice = substr($groupDevice, $dcount*$length);
        }
      }

      $bufferUID .= "-d".$localID.chr($m).$groupUID;
      $n += 1;

      $deviceUID = substr($deviceUID, classConstant::ITEM_FILTER_LENGTH);
    }

    return $bufferUID;
  }

}