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

  use WebOAuth;


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

/*
    $this->SetBuffer("deviceList", vtNoString);
    $this->SetBuffer("groupList", vtNoString);
    $this->SetBuffer("sceneList", vtNoString);

    $this->SetBuffer("deviceBuffer", vtNoString);
    //$this->SetBuffer("localDevice", vtNoString);
    $this->SetBuffer("deviceLabel", vtNoString);

    $this->SetBuffer("localGroup", vtNoString);
    $this->SetBuffer("deviceGroup", vtNoString);
    $this->SetBuffer("groupBuffer", vtNoString);
    $this->SetBuffer("groupDevice", vtNoString);

    $this->SetBuffer("cloudDevice", vtNoString);
    $this->SetBuffer("cloudGroup", vtNoString);
    $this->SetBuffer("cloudScene", vtNoString);
*/

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

    $deviceList = $this->GetBuffer("deviceList");
    $formDevice = vtNoString;

    if (!empty($deviceList) && ord($deviceList{0}) > 0) {
      $formDevice = '
        { "type": "List",         "name":  "listDevice",        "caption": "Devices",
          "columns": [
            { "label": "ID",          "name": "deviceID",     "width":  "30px" },
            { "label": "Class",       "name": "classInfo",    "width":  "65px" },
            { "label": "Name",        "name": "deviceName",   "width": "110px" },
            { "label": "UUID",        "name": "UUID",         "width": "140px" }';

      $cloudDevice = $this->GetBuffer("cloudDevice");
      $formDevice  = (!empty($cloudDevice)) ? $formDevice.',
        { "label": "Manufacturer",    "name": "manufacturer", "width":  "80px" },
        { "label": "Model",           "name": "deviceModel",  "width": "130px" },
        { "label": "Capabilities",    "name": "deviceLabel",  "width": "175px" },
        { "label": "Firmware",        "name": "firmware",     "width":  "65px" }' : $formDevice;

      $formDevice .= ']},';
    }

    $groupList = $this->GetBuffer("groupList");
    $formGroup = (!empty($groupList) && ord($groupList{0}) > 0) ? '
      { "type": "List",           "name":  "listGroup",         "caption": "Groups",
        "columns": [
          { "label": "ID",          "name": "groupID",      "width":  "30px" },
          { "label": "Class",       "name": "classInfo",    "width":  "65px" },
          { "label": "Name",        "name": "groupName",    "width": "110px" },
          { "label": "UUID",        "name": "UUID",         "width": "140px" },
          { "label": "Info",        "name": "information",  "width": "110px" }
        ]
    },' : vtNoString;

    $sceneList = $this->GetBuffer("sceneList");
    $formScene = (!empty($sceneList) && ord($sceneList{0}) > 0) ? '
      { "type": "List",           "name":  "listScene",         "caption": "Scenes",
        "columns": [
          { "label": "ID",          "name": "sceneID",      "width":  "30px" },
          { "label": "Class",       "name": "classInfo",    "width":  "65px" },
          { "label": "Name",        "name": "sceneName",    "width": "110px" },
          { "label": "UUID",        "name": "UUID",         "width": "140px" },
          { "label": "Group",       "name": "groupName",    "width": "110px" },
          { "label": "Info",        "name": "information",  "width":  "70px" }
        ]
    },' : vtNoString;

    $formJSON = '{
      "elements": [
        { "type": "CheckBox",     "name": "open",               "caption": " Open" },
        { "type": "Select",       "name": "connectMode",        "caption": "Connection",
          "options": [
            { "label": "Local only",      "value": 1001 },
            { "label": "Local and Cloud", "value": 1002 }
          ]
        },
        { "name": "gatewayIP",    "type":  "ValidationTextBox", "caption": "Gateway IP"          },
        { "name": "serialNumber", "type":  "ValidationTextBox", "caption": "Serial number"       },
        { "name": "localUpdate",  "type":  "NumberSpinner",     "caption": "Update interval [s]" },
        { "type": "Label",        "label": "" },
        { "type": "List",         "name":  "listCategory",      "caption": "Categories",
          "columns": [
            { "label": "Type",        "name": "Device",     "width":  "55px" },
            { "label": "Category",    "name": "Category",   "width": "265px" },
            { "label": "Category ID", "name": "categoryID", "width":  "10px", "visible": false,
              "edit": {
                "type": "SelectCategory"
              }
            },
            { "label": "Sync",        "name": "Sync",       "width": "35px" },
            { "label": "Sync ID",     "name": "syncID",     "width": "10px", "visible": false,
              "edit": {
                "type": "CheckBox", "caption": " Synchronise values"
              }
            }
          ]
        },
        { "type": "CheckBox",     "name": "reloadLocal",        "caption": " Reload data instantly (not recommended: higher gateway load and longer response time)"         },
        { "type": "CheckBox",     "name": "deviceInfo",         "caption": " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)" },
        '.$formDevice.'
        '.$formGroup.'
        '.$formScene.'
        { "type": "Select", "name": "debug", "caption": "Debug",
          "options": [
            { "label": "Disabled",            "value": 0  },
            { "label": "Send buffer",         "value": 3  },
            { "label": "Receive buffer",      "value": 7  },
            { "label": "Send/Receive buffer", "value": 13 },
            { "label": "Detailed error log",  "value": 17 }
          ]
        },
        { "type": "CheckBox",     "name":  "message",           "caption": " Messages" }
      ],
      "actions": [
        { "type": "Button", "label": "Register", "onClick": "echo OSR_LightifyRegister($id)"                 },
        { "type": "Label",  "label": "Press Create | Update to automatically apply the devices and settings" },
        { "type": "Button", "label": "Create | Update", "onClick": "OSR_GetLightifyData($id, 1208)"          }
      ],
      "status": [
        { "code": 101, "icon": "inactive", "caption": "Lightify gateway is closed"      },
        { "code": 102, "icon": "active",   "caption": "Lightify gateway is open"        },
        { "code": 104, "icon": "inactive", "caption": "Enter all required informations" },
        { "code": 201, "icon": "inactive", "caption": "Lightify gateway is closed"      },
        { "code": 202, "icon": "error",    "caption": "Invalid IP address"              },
        { "code": 203, "icon": "error",    "caption": "Invalid Serial number!"          },
        { "code": 205, "icon": "error",    "caption": "Update interval < 3s"            },
        { "code": 299, "icon": "error",    "caption": "Unknown error"                   }
      ]
    }';

    //Categories list element
    $data  = json_decode($formJSON);
    $Types = array("Gerät", "Sensor", "Gruppe", "Szene");

    //Only add default element if we do not have anything in persistence
    if (empty($this->ReadPropertyString("listCategory"))) {
      foreach ($Types as $item) {
        $data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
          'Device'     => $item,
          'categoryID' => 0, 
          'Category'   => "select ...",
          'Sync'       => "no",
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
            'Sync'     => ($row->syncID) ? "ja" : "nein"
          );
        } else {
          $data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
            'Device'   => $Types[$index],
            'Category' => "wählen ...",
            'Sync'     => "nein"
          );
        }
      }
    }

    //Device list element
    if (!empty($formDevice)) {
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
    if (!empty($formGroup)) {
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
        $information = ($dcount == 1) ? $dcount." Gerät" : $dcount." Geräte";

        $data->elements[self::LIST_GROUP_INDEX]->values[] = array(
          'groupID'     => $groupID,
          'classInfo'   => "Gruppe",
          'UUID'        => $UUID,
          'groupName'   => $groupName,
          'information' => $information
        );

        $groupList = substr($groupList, classConstant::DATA_GROUP_LIST);
      }
    }

    //Scene list element
    if (!empty($formScene)) {
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
        $information = ($dcount == 1) ? $dcount." Gerät" : $dcount." Geräte";

        $data->elements[self::LIST_SCENE_INDEX]->values[] = array(
          'sceneID'     => $sceneID,
          'classInfo'   => "Szene",
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

    $data = json_decode($jsonString);

    switch ($data->method) {
      case classConstant::METHOD_RELOAD_LOCAL:
        if ($this->ReadPropertyBoolean("reloadLocal")) {
          $this->GetLightifyData($data->method);
        }
        break;

      case classConstant::METHOD_LOAD_CLOUD:
        if ($this->ReadPropertyInteger("connectMode") == classConstant::CONNECT_LOCAL_CLOUD) {
          $this->cloudGET($data->buffer);
        }
        break;

      case classConstant::METHOD_APPLY_CHILD:
        $jsonReturn = vtNoString;

        if ($this->ReadPropertyBoolean("open")) {
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

            case classConstant::MODE_DEVICE_CLOUD:
              $cloudDevice = $this->GetBuffer("cloudDevice");

              if (!empty($cloudDevice)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => $cloudDevice)
                );
              }
              return $jsonReturn;

            case classConstant::MODE_GROUP_LOCAL:
              $localGroup  = $this->GetBuffer("localGroup");
              $groupDevice = $this->GetBuffer("groupBuffer");

              if (!empty($localGroup) && ord($localGroup{0}) > 0 && !empty($groupDevice)) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($localGroup{0}.$groupDevice))
                );
              }
              return $jsonReturn;

            case classConstant::MODE_GROUP_SCENE:
              $cloudScene = $this->GetBuffer("cloudScene");

              if (!empty($cloudScene) && ord($cloudScene{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'     => $this->InstanceID,
                  'buffer' => utf8_encode($cloudScene))
                );
              }
              return $jsonReturn;
          }
        }
    }

    return false;
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

    echo "Lightify API registration available in cloud connection mode only!\n";
  }


  protected function ProcessOAuthData()
  {

    if ($_SERVER['REQUEST_METHOD'] == "GET") {
      if (isset($_GET['code'])) {
        return $this->getAccessToken($_GET['code']);
      } else {
        $error = "Authorization code expected!";

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
              $this->SetBuffer("cloudScene", $cloudScene);

              if (!empty($cloudScene)) {
                $this->SetBuffer("sceneBuffer", $cloudScene);
              }
            } else {
              $this->SetBuffer("sceneList", vtNoString);
            }
          }
        }

        //Re-read group buffer
        $localGroup  = $this->GetBuffer("localGroup");

        //Create childs
        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $message = vtNoString;
          $error   = false;

          if ($syncDevice || $syncGroup) {
            if ($syncDevice && !empty($localDevice)) {
              $ncount = ord($localDevice{0});

              if ($ncount > 0) {
                $this->createInstance(classConstant::MODE_CREATE_DEVICE, $ncount, substr($localDevice, 2));
                $message = "Device";
              }
            }

            if ($syncGroup && !empty($localGroup)) {
              $ncount = ord($localGroup{0});

              if ($ncount > 0) {
                $this->createInstance(classConstant::MODE_CREATE_GROUP, $ncount, substr($localGroup, 1));
                $message = $message.", Group";
              }
            }

            if ($this->syncScene) {
              if (!empty($cloudGroup) && !empty($cloudScene)) {
                $ncount = ord($localGroup{0});

                if ($ncount > 0) {
                  $this->createInstance(classConstant::MODE_CREATE_SCENE, $ncount, substr($cloudScene, 1));
                  $message = $message.", Scene";
                }
              }
            }
          } else {
            //$message = "Nothing selected. Please select a category first";
            $error   = true;
          }

          if (!$error && $message) {
            echo $message." instances successfully created/updated\n";
          } else {
            echo "No instances created/updated\n";
            $error = true;
          }
        }

        if (!$error) {
          if ($localMethod == classConstant::METHOD_LOAD_LOCAL || $localMethod == classConstant::METHOD_RELOAD_LOCAL) {
            $sendMethod = classConstant::METHOD_UPDATE_CHILD;
          } else {
            $sendMethod = classConstant::METHOD_CREATE_CHILD;
          }

          //Update child informations
          if ($localMethod == classConstant::METHOD_LOAD_LOCAL || $localMethod == classConstant::METHOD_RELOAD_LOCAL || $localMethod == classConstant::METHOD_CREATE_CHILD) {
            if ($syncDevice && !empty($localDevice) && ord($localDevice{0}) > 0) {
              if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE)) > 0) {
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

            if ($syncGroup) {
              if (!empty($localGroup) && ord($localGroup{0}) > 0 && !empty($groupDevice)) {
                if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP)) > 0) {
                  $this->SendDataToChildren(json_encode(array(
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'connect' => $this->connect,
                    'mode'    => classConstant::MODE_GROUP_LOCAL,
                    'method'  => $sendMethod,
                    'buffer'  => utf8_encode($localGroup{0}.$groupDevice)))
                  );
                }

                if ($this->connect == classConstant::CONNECT_LOCAL_CLOUD && $localMethod != classConstant::METHOD_RELOAD_LOCAL) {
                  if ($this->syncScene && !empty($cloudScene) && ord($cloudScene{0}) > 0) {
                    $this->SendDataToChildren(json_encode(array(
                      'DataID'  => classConstant::TX_GROUP,
                      'id'      => $this->InstanceID,
                      'connect' => $this->connect,
                      'mode'    => classConstant::MODE_GROUP_SCENE,
                      'method'  => $sendMethod,
                      'buffer'  => utf8_encode($cloudScene)))
                    );
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

    $localDevice = vtNoString;

    for ($i = 1, $j = 0; $i <= $ncount; $i++) {
      $itemType = ord($data{10});
      $noType   = false;

      //Decode Device label
      switch ($itemType) {
        case classConstant::TYPE_FIXED_WHITE:
        case classConstant::TYPE_LIGHT_CCT:
        case classConstant::TYPE_LIGHT_DIMABLE:
        case classConstant::TYPE_LIGHT_COLOR:
        case classConstant::TYPE_LIGHT_EXT_COLOR:
        case classConstant::TYPE_PLUG_ONOFF:
        case classConstant::TYPE_SENSOR_MOTION:
        case classConstant::TYPE_DIMMER_2WAY:
        case classConstant::TYPE_SWITCH_4WAY:
          break;

        default:
          $noType = true;
      }
      if ($noType) continue;

      $deviceID = chr($i);
      $device   = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);

      $localDevice .= "-d".$deviceID.$device;
      $j += 1;

      if (($length = strlen($data)) > classConstant::DATA_DEVICE_LOADED) {
        $length = classConstant::DATA_DEVICE_LOADED;
      }

      $data = substr($data, $length);
    }

    return chr($j).chr($i).$localDevice;
  }


  private function structLightifyData($command, $ncount = 0, $data = null, $buffer = null)
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

          $itemType  = ord($data{10});
          $classInfo = "Lampe";
          $withGroup = true;

          //Decode device label
          switch ($itemType) {
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
              $label     = classConstant::LABEL_PLUG_ONOFF;
              $classInfo = "Steckdose";
              $withGroup = false;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $label     = classConstant::LABEL_SENSOR_MOTION;
              $classInfo = "Sensor";
              $withGroup = false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
              $label     = classConstant::LABEL_DIMMER_2WAY;
              $classInfo = "Dimmer";
              $withGroup = false;
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $label     = classConstant::LABEL_SWITCH_4WAY;
              $classInfo = "Schalter";
              $withGroup = false;
              break;
          }

          $classInfo   = str_pad($classInfo, classConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);
          $uint64      = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
          $deviceName  = substr($data, 26, classConstant::DATA_NAME_LENGTH);
          $deviceList .= $deviceID.$uint64.$deviceName.$classInfo;

          $deviceLabel[$i] = $label;
          $device = substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
          $index  = stripos($buffer, $device);

          if ($index === false || ($index && substr_compare($buffer, $device, $index, classConstant::DATA_DEVICE_LENGTH))) {
            $localDevice .= "-d".$deviceID.$device;
            $k += 1;
          }

          //Device group
          if ($withGroup) {
            $deviceGroup .= $uint64.substr($data, 16, 2);
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
                $zigBee      = dechex(ord($data{0})).dechex(ord($data{1}));
                $deviceModel = strtoupper($device->deviceModel);
                $deviceLabel = vtNoString;

                //Modell mapping
                if (substr($deviceModel, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $deviceModel = "CLASSIC A60 W CLEAR";
                }

                if (substr($deviceModel, 0, 4) == "PLUG") {
                  $deviceModel = classConstant::MODEL_PLUG_ONOFF;
                }

                if (is_object($labelBuffer)) {
                  $deviceLabel = $labelBuffer->$deviceID;
                }

                $cloudDevice[] = array(
                  $deviceID, $device->type,
                  classConstant::MODEL_MANUFACTURER, $deviceModel,
                  $deviceLabel,
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
        $itemType    = classConstant::TYPE_DEVICE_GROUP;
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
              $groups = $this->lightifyBase->decodeGroup(ord($deviceGroup{8}), ord($deviceGroup{9}));
              //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH)));

              foreach ($groups as $key) {
                if ($groupID == $key) {
                  //IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$dcount." - ".$groupID." - ".$this->lightifyBase->chrToUUID(substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH)));
                  $uuidBuffer .= substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH);
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
          $info = ($ncount > 0) ? $ncount."/".$itemType."/".$this->lightifyBase->decodeData($localGroup) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<Gateway|structLightifyData|groups:local>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|groups:local>   ".$info);
          }
        }

        //Return group buffer string
        if ($this->syncGroup) {
          return chr($itemType).$groupDevice;
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
          $cloudGroup = json_decode($cloudGroup);
          $itemType   = classConstant::TYPE_GROUP_SCENE;

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
            $result = chr($i).chr($itemType).$cloudScene;

            if ($this->debug % 2 || $this->message) {
              $info = $i."/".$itemType."/".$this->lightifyBase->decodeData($cloudScene);

              if ($this->debug % 2) {
                $this->SendDebug("<Gateway|structLightifyData|scenes:cloud>", $info, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<Gateway|structLightifyData|scenes:cloud>   ".$info);
              }
            }

            if (strcmp($result, $buffer) != 0) {
              return chr($i).chr($itemType).$cloudScene;
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
        if (false !== ($firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", vtNoString, 304))) {
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


  private function createInstance($mode, $ncount, $data)
  {

    switch ($mode) {
      case classConstant::MODE_CREATE_DEVICE:
        for ($i = 1; $i <= $ncount; $i++) {
          $deviceID = ord($data{2});
          $data     = substr($data, 3);

          $itemType    = ord($data{10});
          $implemented = true;

          switch ($itemType) {
            case classConstant::TYPE_PLUG_ONOFF:
              $itemClass  = classConstant::CLASS_LIGHTIFY_PLUG;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $itemClass  = classConstant::CLASS_LIGHTIFY_SENSOR;
              $categoryID = ($this->syncSensor) ? $this->sensorCategory->categoryID : false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
              $implemented = false;
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $implemented = false;
              break;

            default:
              $itemClass  = classConstant::CLASS_LIGHTIFY_LIGHT;
              $categoryID = ($this->syncDevice) ? $this->deviceCategory->categoryID : false;
          }

          if ($implemented && $categoryID && IPS_CategoryExists($categoryID)) {
            $uintUUID   = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $deviceName = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
            //IPS_LogMessage("SymconOSR", "<CREATEINSTANCE>   ".$i."/".$deviceID."/".$itemType."/".$deviceName."/".$this->lightifyBase->decodeData($data));

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_DEVICE);
              $deviceName = !empty($deviceName) ? $deviceName : "--unknown--";

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetName($InstanceID, $deviceName);
              IPS_SetPosition($InstanceID, 210+$deviceID);

              IPS_SetProperty($InstanceID, "deviceID", (int)$deviceID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "itemClass") != $itemClass) {
                IPS_SetProperty($InstanceID, "itemClass", (int)$itemClass);
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
            $groupName  = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetName($InstanceID, $groupName);
              IPS_SetPosition($InstanceID, 210+$groupID);

              IPS_SetProperty($InstanceID, "itemID", (int)$groupID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "itemClass") != classConstant::CLASS_LIGHTIFY_GROUP) {
                IPS_SetProperty($InstanceID, "itemClass", classConstant::CLASS_LIGHTIFY_GROUP);
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
        $data = substr($data, 1); //cut itemType
        $categoryID = ($this->syncScene) ? $this->sceneCategory->categoryID : false;

        if ($categoryID && IPS_CategoryExists($categoryID)) {
          for ($i = 1; $i <= $ncount; $i++) {
            $uintUUID = $data{2}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
            $sceneID  = ord($data{5});
            $data     = substr($data, 6);

            //$sceneName  = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
            $sceneName  = trim(substr($data, 0, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);
            //IPS_LogMessage("SymconOSR", "<CREATEINSTANCE|SCENES>   ".$ncount."/".$itemType."/".ord($data{0})."/".$sceneID."/".$this->lightifyBase->chrToUUID($uintUUID)."/".$sceneName);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

              IPS_SetParent($InstanceID, $this->sceneCategory->categoryID);
              IPS_SetName($InstanceID, $sceneName);
              IPS_SetPosition($InstanceID, 210+$sceneID);

              IPS_SetProperty($InstanceID, "itemID", $sceneID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "itemClass") != classConstant::CLASS_LIGHTIFY_SCENE) {
                IPS_SetProperty($InstanceID, "itemClass", classConstant::CLASS_LIGHTIFY_SCENE);
              }

              if (IPS_HasChanges($InstanceID)) {
                IPS_ApplyChanges($InstanceID);
              }
            }

            $data = substr($data, classConstant::DATA_SCENE_LENGTH);
          }
        }
        break;
    }
  }

}