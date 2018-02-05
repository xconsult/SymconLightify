<?php

require_once __DIR__.'/../libs/classModule.php';
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


class lightifyGateway extends IPSModule {

  const LIST_CATEGORY_INDEX     = 10;
  const LIST_DEVICE_INDEX       = 14;
  const LIST_GROUP_INDEX        = 15;
  const LIST_SCENE_INDEX        = 16;

  const GATEWAY_SERIAL_LENGTH   =  11;
  const CLOUD_REQUEST_INTERVALL =  60; //seconds
  const CLOUD_SESSION_TIMEOUT   = 840; //Lightify session time-out 14 min

  const PROTOCOL_VERSION        =   1;
  const HEADER_CONTENT_TYPE     = "Content-Type: application/json";
  const HEADER_AUTHORIZATION    = "authorization: ";
  const RESSOURCE_DEVICE_LIST   = "/devices";
  const RESSOURCE_GROUP_LIST    = "/groups";

  const RESOURCE_SESSION        = "/session";
  const LIGHTIFY_EUROPE         = "https://eu.lightify-api.org/lightify/services";
  const LIGHTIFY_USA            = "https://us.lightify-api.org/lightify/services";

  const OAUTH_AUTHORIZE         = "https://oauth.ipmagic.de/authorize/";
  const OAUTH_FORWARD           = "https://oauth.ipmagic.de/forward/";
  const OAUTH_ACCESS_TOKEN      = "https://oauth.ipmagic.de/access_token/";

  private $oauthIdentifer = "osram_lightify";

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

  protected $lightifyCookie;
  protected $userId;
  protected $securityToken;
  protected $cache_expires;

  protected $debug;
  protected $message;


  public function __construct($InstanceID) {
    parent::__construct($InstanceID);

    $this->classModule  = new classModule;
    $this->lightifyBase = new lightifyBase;
  }


  public function Create() {
    parent::Create();

    //Store at runtime
    $this->SetBuffer("lightifyCache", vtNoString);
    $this->SetBuffer("cloudIntervall", vtNoString);

    $this->SetBuffer("deviceList", vtNoString);
    $this->SetBuffer("groupList", vtNoString);
    $this->SetBuffer("sceneList", vtNoString);
    $this->SetBuffer("localDevice", vtNoString);
    $this->SetBuffer("localGroup", vtNoString);
    $this->SetBuffer("deviceGroup", vtNoString);
    $this->SetBuffer("groupDevice", vtNoString);
    $this->SetBuffer("cloudDevice", vtNoString);
    $this->SetBuffer("cloudGroup", vtNoString);
    $this->SetBuffer("cloudScene", vtNoString);

    $this->RegisterPropertyBoolean("open", false);
    $this->RegisterPropertyInteger("connectMode", classConstant::CONNECT_LOCAL_ONLY);

    //Cloud credentials
    $this->RegisterPropertyString("userName", vtNoString);
    $this->RegisterPropertyString("password", vtNoString);
    $this->RegisterPropertyString("serialNumber", vtNoString);

    //Local gateway
    $this->RegisterPropertyString("gatewayIP", vtNoString);
    $this->RegisterPropertyInteger("timeOut", classConstant::MAX_PING_TIMEOUT);
    $this->RegisterPropertyInteger("localUpdate", TIMER_SYNC_LOCAL);
    $this->RegisterTimer("localTimer", 0, "OSR_getLightifyData($this->InstanceID, 1202);");

    //Global settings
    $this->RegisterPropertyString("listCategory", vtNoString);
    $this->RegisterPropertyString("listDevice", vtNoString);
    $this->RegisterPropertyString("listGroup", vtNoString);
    $this->RegisterPropertyString("listScene", vtNoString);
    $this->RegisterPropertyBoolean("deviceInfo", false);
    $this->RegisterPropertyBoolean("showControl", false);

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
      IPS_SetVariableProfileIcon("OSR.ColorTemp", "Sun");
      IPS_SetVariableProfileDigits("OSR.ColorTemp", 0);
      IPS_SetVariableProfileText("OSR.ColorTemp", vtNoString, "K");
      IPS_SetVariableProfileValues("OSR.ColorTemp", classConstant::CTEMP_CCT_MIN, classConstant::CTEMP_CCT_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.ColorTempExt")) {
      IPS_CreateVariableProfile("OSR.ColorTempExt", vtInteger);
      IPS_SetVariableProfileIcon("OSR.ColorTempExt", "Sun");
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


  public function ApplyChanges() {
    parent::ApplyChanges();
    $this->SetBuffer("connectTime", vtNoString);

    $open    = $this->ReadPropertyBoolean("open");
    $connect = $this->ReadPropertyInteger("connectMode");
    $result  = $this->configCheck($open, $connect);

    $localUpdate = ($result && $open) ? $this->ReadPropertyInteger("localUpdate")*1000 : 0;
    $this->SetTimerInterval("localTimer", $localUpdate);

    if ($result && $open) {
      $this->SetBuffer("timerMode", TIMER_MODE_ON);
      $this->getLightifyData(classConstant::METHOD_APPLY_LOCAL);
    }
  }


  public function GetConfigurationForm() {
    $deviceList = $this->GetBuffer("deviceList");
    $formDevice = vtNoString;

    if (!empty($deviceList) && ord($deviceList{0}) > 0) {
      $formDevice = '
        { "type": "Label",        "label": "----------------------------------------------- Registrierte Geräte/Gruppen/Szenen --------------------------------------" },
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
        { "name": "timeOut",      "type":  "NumberSpinner",     "caption": "Ping timeout [ms]"   },
        { "name": "localUpdate",  "type":  "NumberSpinner",     "caption": "Update interval [s]" },
        { "type": "Label",        "label": "------------------------------------------- Cloud Anmeldeinformationen (optional) ---------------------------------------" },
        { "name": "userName",     "type":  "ValidationTextBox", "caption": "Username"            },
        { "name": "password",     "type":  "PasswordTextBox",   "caption": "Password"            },
        { "name": "serialNumber", "type":  "ValidationTextBox", "caption": "Serial number"       },
        { "type": "Label",        "label": "----------------------------------------------------------- Auswahl ------------------------------------------------------------" },
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
        { "type": "CheckBox",     "name": "deviceInfo",         "caption": " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)" },
        { "type": "CheckBox",     "name": "showControl",        "caption": " Automatically hide/show available properties based on the device state"                        },
        '.$formDevice.'
        '.$formGroup.'
        '.$formScene.'
        { "type": "Label",        "label": "----------------------------------------------------------------------------------------------------------------------------------" },
        { "type": "Select", "name": "debug", "caption": "Debug",
          "options": [
            { "label": "Disabled",            "value": 0  },
            { "label": "Send buffer",         "value": 3  },
            { "label": "Receive buffer",      "value": 7  },
            { "label": "Send/Receive buffer", "value": 13 },
            { "label": "Detailed error log",  "value": 17 }
          ]
        },
        { "type": "CheckBox",     "name":  "message",           "caption": " Messages" },
        { "type": "Label",        "label": "----------------------------------------------------------------------------------------------------------------------------------" }
      ],
      "actions": [
        { "type": "Label",  "label": "Drücken Sie Erstellen | Aktualisieren, um die am Gateway registrierten Geräte/Gruppen/Szenen und Einstellungen automatisch anzulegen" },
        { "type": "Button", "label": "Create | Update", "onClick": "OSR_getLightifyData($id, 1207)" }
      ],
      "status": [
        { "code": 102, "icon": "active",   "caption": "Lightify gateway is open"        },
        { "code": 104, "icon": "inactive", "caption": "Enter all required informations" },
        { "code": 201, "icon": "inactive", "caption": "Lightify gateway is closed"      },
        { "code": 202, "icon": "error",    "caption": "Invalid IP address"              },
        { "code": 203, "icon": "error",    "caption": "Ping timeout < 0ms"              },
        { "code": 204, "icon": "error",    "caption": "Update interval < 3s"            },
        { "code": 205, "icon": "error",    "caption": "Enter a valid Username"          },
        { "code": 206, "icon": "error",    "caption": "Enter a Password"                },
        { "code": 207, "icon": "error",    "caption": "Invalid Serial number!"          },
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
            list($cloudID, $deviceType, $manufacturer, $deviceModel, $bmpClusters, $zigBee, $firmware) = $device;
            $deviceLabel = (empty($bmpClusters)) ? vtNoString : implode(" ", $bmpClusters);
  
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

        $dcount      = ord($sceneList{31});
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


  public function ForwardData($jsonString) {
    $data = json_decode($jsonString);

    switch ($data->method) {
      case classConstant::METHOD_PARENT_CONFIG:
        $jsonReturn = json_encode(array(
          'id'      => $this->InstanceID,
          'mode'    => $this->ReadPropertyInteger("connectMode"),
          'ip'      => $this->ReadPropertyString("gatewayIP"),
          'time'    => $this->ReadPropertyInteger("timeOut"),
          'info'    => $this->ReadPropertyBoolean("deviceInfo"),
          'debug'   => $this->debug,
          'message' => $this->message)
        );
        return $jsonReturn;

      case classConstant::METHOD_LOAD_LOCAL:
        $this->getLightifyData(classConstant::METHOD_LOAD_LOCAL);
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
                  'id'      => $this->InstanceID,
                  'buffer'  => utf8_encode($localDevice),
                  'debug'   => $this->debug,
                  'message' => $this->message)
                );
              }
              return $jsonReturn;

            case classConstant::MODE_DEVICE_CLOUD:
              $cloudDevice = $this->GetBuffer("cloudDevice");

              if (!empty($cloudDevice)) {
                $jsonReturn = json_encode(array(
                  'id'      => $this->InstanceID,
                  'buffer'  => $cloudDevice,
                  'debug'   => $this->debug,
                  'message' => $this->message)
                );
              }
              return $jsonReturn;

            case classConstant::MODE_GROUP_LOCAL:
              $groupDevice = $this->GetBuffer("groupDevice");
              $localGroup  = $this->GetBuffer("localGroup");
              $ncount      = $localGroup{0};

              if (!empty($localGroup) && ord($ncount) > 0) {
                $itemType = $localGroup{1};

                $jsonReturn = json_encode(array(
                  'id'      => $this->InstanceID,
                  'buffer'  => utf8_encode($ncount.$itemType.$groupDevice),
                  'debug'   => $this->debug,
                  'message' => $this->message)
                );
              }
              return $jsonReturn;

            case classConstant::MODE_GROUP_SCENE:
              $cloudScene = $this->GetBuffer("cloudScene");

              if (!empty($cloudScene) && ord($cloudScene{0}) > 0) {
                $jsonReturn = json_encode(array(
                  'id'      => $this->InstanceID,
                  'buffer'  => utf8_encode($cloudScene),
                  'debug'   => $this->debug,
                  'message' => $this->message)
                );
              }
              return $jsonReturn;
          }
        }
    }

    return false;
  }


  private function configCheck($open, $connect) {
    $localUpdate = $this->ReadPropertyInteger("localUpdate");
    $filterIP    = filter_var($this->ReadPropertyString("gatewayIP"), FILTER_VALIDATE_IP);

    if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
      $serialNumber = $this->ReadPropertyString("serialNumber");

      if ($this->ReadPropertyString("userName") == vtNoString) {
        $this->SetStatus(205);
        return false;
      }
      if ($this->ReadPropertyString("password") == vtNoString) {
        $this->SetStatus(206);
        return false;
      }

      if (strlen($serialNumber) != self::GATEWAY_SERIAL_LENGTH) {
        $this->SetStatus(207);
        return false;
      }
    }

    if ($filterIP) {
      if ($localUpdate < TIMER_SYNC_LOCAL_MIN) {
        $this->SetStatus(204);
        return false;
      }
    } else {
      $this->SetStatus(202); //IP error
      return false;
    }

    if ($this->ReadPropertyInteger("timeOut") < 0) {
      $this->SetStatus(203);
      return false;
    }

    if ($open) {
      $this->SetStatus(102);
    } else {
      $this->SetStatus(201);
    }

    return true;
  }


  private function setEnvironment() {
    if ($categories = $this->ReadPropertyString("listCategory")) {
      list($this->deviceCategory, $this->sensorCategory, $this->groupCategory, $this->sceneCategory) = json_decode($categories);

      $this->createDevice = ($this->deviceCategory->categoryID > 0) ? true : false;
      $this->createSensor = ($this->sensorCategory->categoryID > 0) ? true : false;
      $this->createGroup  = ($this->groupCategory->categoryID > 0) ? true : false;
      $this->createScene  = ($this->createGroup && $this->sceneCategory->categoryID > 0) ? true : false;

      $this->syncDevice   = ($this->createDevice && $this->deviceCategory->syncID) ? true : false;
      $this->syncSensor   = ($this->createSensor && $this->sensorCategory->syncID) ? true : false;

      $this->syncGroup    = ($this->createGroup && $this->groupCategory->syncID) ? true : false;
      $this->syncScene    = ($this->syncGroup && $this->createScene && $this->sceneCategory->syncID) ? true : false;
    }
  }


  private function localConnect() {
    $gatewayIP = $this->ReadPropertyString("gatewayIP");
    $timeOut   = $this->ReadPropertyInteger("timeOut");

    if ($timeOut > 0) {
      $connect = Sys_Ping($gatewayIP, $timeOut);
    } else {
      $connect = true;
    }

    if ($connect) {
      try { 
        $lightifySocket = new lightifyConnect($this->InstanceID, $gatewayIP, $this->debug, $this->message);
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


  protected function cloudGET($url) {
    return $this->cloudRequest("GET", $url);
  }


  protected function cloudPOST($url, $args) {
    return $this->cloudRequest("POST", $url, $args);
  }


  protected function cloudLogin($userName, $password, $serialNumber) {
    if (file_exists($this->lightifyCookie) && !empty($this->cache_expires) && $this->cache_expires > time()) {
      return true;
    }

    $args = json_encode(array(
      'username'     => $userName,
      'password'     => $password, 
      'serialNumber' => $serialNumber)
    );
    $result = $this->cloudPOST(self::RESOURCE_SESSION, $args);

    if (is_object($result)) {
      $this->userId        = $result->userId;
      $this->securityToken = $result->securityToken;
      $this->cache_expires = time()+self::CLOUD_SESSION_TIMEOUT; //Lightify session time-out 14 min

      $this->saveCache();
      return true;
    }

    return false;
  }


  private function cloudRequest($method, $url, $args = null) {
    $headers = array(self::HEADER_CONTENT_TYPE);
    $client  = curl_init();

    if ($client !== false) {
      if ($url[0] == "/")
        $url = self::LIGHTIFY_EUROPE.$url;

      if (isset($this->userId)) {
        $headers[] = self::HEADER_AUTHORIZATION.$this->securityToken;
      }

      if (is_array($args)) {
        $data = array();

        foreach ($args as $k => $v)
          $data[] = "$k=".urlencode($v);

        $data = implode("&", $data);
      } elseif (is_string($args)) {
        $data = $args;
      }

      curl_setopt($client, CURLOPT_URL, $url);
      curl_setopt($client, CURLOPT_CUSTOMREQUEST, $method);
      curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($client, CURLOPT_HEADER, false);
      curl_setopt($client, CURLOPT_COOKIEJAR, $this->lightifyCookie);
      curl_setopt($client, CURLOPT_COOKIEFILE, $this->lightifyCookie);

     if ($method == 'POST') {
        if (!isset($data)) {
          $error = "You need to specify \$data when sending a POST.";

          $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
          IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

          return false;
        }

        curl_setopt($client, CURLOPT_POST, true);
        curl_setopt($client, CURLOPT_POSTFIELDS, $data);
      }

      curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 5);
      curl_setopt($client, CURLOPT_TIMEOUT, 5);
      curl_setopt($client, CURLOPT_SSL_VERIFYPEER, true); //for security this should always be set to true.
      curl_setopt($client, CURLOPT_SSL_VERIFYHOST, 2);    //for security this should always be set to 2.
      curl_setopt($client, CURLOPT_SSLVERSION, 1);

      $response = curl_exec($client);
      $info = curl_getinfo($client);

      if ($info['http_code'] == 401 || (!$response && curl_errno($client) != 0)) {
        $error = "HTTP request returned an error: ".curl_error($client);

        $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
        IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

        return false;
      }

      $jsonResult = json_decode($response);
      curl_close($client);

      if (array_key_exists("errorCode", $jsonResult)) {
        $error = "HTTP ".$info['http_code']." ".$jsonResult->errorCode.":".$jsonResult->errorMessage;

        $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
        IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

        return false;
      }

      if (!is_object($jsonResult) && ($method == "GET" || $url == self::RESOURCE_SESSION)) {
        if (empty($response)) {
          $error = "Received empty response from HTTP request $url.";

          $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
          IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

          return false;
        }
      }

      if ($info['http_code'] == 400) {
        if (!is_object($jsonResult)) {
          $error = "HTTP 400 response: ".str_replace(array("\n","\r"), '', $response);

          $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
          IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

          return false;
        }
      }

      if ($info['download_content_length'] == 0) {
        return $info['http_code'] == 200;
      }

      if ($this->debug % 2) {
        $this->SendDebug("<GATEWAY|CLOUDREQUEST:RESULT>", $response, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:RESULT>   ".$response);
      }

      return $jsonResult;
    }

    return false;
  }


  public function getLightifyData(integer $localMethod) {
    if ($this->ReadPropertyBoolean("open")) {
      $this->SetTimerInterval("localTimer", 0);
      $connect = $this->ReadPropertyInteger("connectMode");

      $userName     = $this->ReadPropertyString("userName");
      $password     = $this->ReadPropertyString("password");
      $serialNumber = $this->ReadPropertyString("serialNumber");

      $this->debug   = $this->ReadPropertyInteger("debug");
      $this->message = $this->ReadPropertyBoolean("message");

      if ($lightifySocket = $this->localConnect()) {
        $this->setGatewayInfo($lightifySocket, $localMethod);
        $this->SetEnvironment();
        $error = false;

        $localDevice = $this->GetBuffer("localDevice");
        $localGroup  = $this->GetBuffer("localGroup");
        $cloudDevice = $this->GetBuffer("cloudDevice");
        $cloudGroup  = $this->GetBuffer("cloudGroup");
        $cloudScene  = $this->GetBuffer("cloudScene");

        //Get Gateway WiFi configuration
        if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_GATEWAY_WIFI, classConstant::SCAN_WIFI_CONFIG))) {
          if (strlen($data) >= (2+classConstant::DATA_WIFI_LENGTH)) { 
            $this->getWiFi(substr($data, 1), ord($data{0}));
          }
        }

        //Get gateway firmware version
        if (isset($this->firmwareID)) {
          if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))) {
            $firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});

            if (GetValueString($this->firmwareID) != $firmware) {
              SetValueString($this->firmwareID, (string)$firmware);
            }
          }
        }

        //Get paired devices
        if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01)))) {
          if (strlen($data) >= (2+classConstant::DATA_DEVICE_LENGTH)) {
            $localDevice = $this->readData(stdCommand::GET_DEVICE_LIST, $data);
            $localDevice = (ord($localDevice{0}) > 0) ? $localDevice : vtNoString;
            $this->SetBuffer("localDevice", $localDevice);
          }
        }

        //Get Group/Zone list
        if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_GROUP_LIST, chr(0x00)))) {
          if (strlen($data) >= (2+classConstant::DATA_GROUP_LENGTH)) {
            $localGroup = $this->readData(stdCommand::GET_GROUP_LIST, $data);
            $localGroup = (ord($localGroup{0}) > 0) ? $localGroup : vtNoString;
            $this->SetBuffer("localGroup", $localGroup);
          }
        }

        //Get cloud data
        if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
          $cloudIntervall = @unserialize($this->GetBuffer("cloudIntervall"));

          if (empty($cloudIntervall) || $cloudIntervall < time()) {
            $this->lightifyCookie = sys_get_temp_dir()."/".md5($userName.$password.$serialNumber);
            $this->classModule->secureTouch($this->lightifyCookie, "OSRAM");

            $this->loadCache();
            $login = $this->cloudLogin($userName, $password, $serialNumber);

            if ($login) {
              if ($this->syncDevice && !empty($localDevice)) {
                $cloudDevice = $this->readData(classConstant::GET_DEVICE_CLOUD);

                if ($cloudDevice !== false) {
                  $this->SetBuffer("cloudDevice", $cloudDevice);
                }
              }

              if ($this->syncGroup && !empty($localGroup)) {
                $cloudGroup = $this->readData(classConstant::GET_GROUP_CLOUD);

                if ($cloudGroup !== false) {
                  $this->SetBuffer("cloudGroup", $cloudGroup);
                }

                if ($this->syncScene && $cloudGroup !== false) {
                  $cloudScene = $this->readData(classConstant::GET_GROUP_SCENE);

                  if ($cloudScene !== false) {
                    $this->SetBuffer("cloudScene", $cloudScene);
                  }
                }
              }
            }

            $this->SetBuffer("cloudIntervall", serialize(time()+self::CLOUD_REQUEST_INTERVALL)); //Lightify cloud request intervall 60 sec
          }
        }

        //Read Buffer
        $groupDevice = $this->GetBuffer("groupDevice");
        $deviceGroup = $this->GetBuffer("deviceGroup");

        //Create childs
        if ($localMethod == classConstant::METHOD_CREATE_CHILD) {
          $message = vtNoString;
          $error   = false;

          if ($this->syncDevice || $this->syncGroup) {
            if ($this->syncDevice) {
              if (!empty($localDevice)) {
                $this->createInstance(classConstant::MODE_CREATE_DEVICE, $localDevice);
                $message = "Device";
              }
            }

            if ($this->syncGroup) {
              if (!empty($localGroup)) {
                $this->createInstance(classConstant::MODE_CREATE_GROUP, $localGroup);
                $message = $message.", Group";
              }
            }

            if ($this->syncScene) {
              if (!empty($cloudGroup) && !empty($cloudScene)) {
                $this->createInstance(classConstant::MODE_CREATE_SCENE, $cloudScene);
                $message = $message.", Scene";
              }
            }
          } else {
            $message = "Nothing selected. Please select a category first";
          }

          if ($message) {
            echo $message." instances successfully created/updated\n";
          } else {
            echo "Internal error occurred. No instances created/updated\n";
            $error = true;
          }
        }

        if (!$error) {
          $sendMethod = ($localMethod == classConstant::METHOD_LOAD_LOCAL) ? classConstant::METHOD_UPDATE_CHILD : classConstant::METHOD_CREATE_CHILD;

          //Update child informations
          if ($localMethod == classConstant::METHOD_LOAD_LOCAL || $localMethod == classConstant::METHOD_CREATE_CHILD) {
            if ($this->syncDevice && !empty($localDevice) && ord($localDevice{0}) > 0) {
              if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE)) > 0) {
                $this->SendDataToChildren(json_encode(array(
                  'DataID'  => classConstant::TX_DEVICE,
                  'id'      => $this->InstanceID,
                  'connect' => $connect,
                  'mode'    => classConstant::MODE_DEVICE_LOCAL,
                  'method'  => $sendMethod,
                  'buffer'  => utf8_encode($localDevice),
                  'debug'   => $this->debug,
                  'message' => $this->message))
                );
              }

              if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
                if (!empty($cloudDevice) && ord($cloudDevice{0}) > 0) {
                  $this->SendDataToChildren(json_encode(array(
                    'DataID'  => classConstant::TX_DEVICE,
                    'id'      => $this->InstanceID,
                    'connect' => $connect,
                    'mode'    => classConstant::MODE_DEVICE_CLOUD,
                    'method'  => $sendMethod,
                    'buffer'  => $cloudDevice,
                    'debug'   => $this->debug,
                    'message' => $this->message))
                  );
                }
              }
            }

            if ($this->syncGroup && !empty($localGroup) && ord($localGroup{0}) > 0) {
              if (count(IPS_GetInstanceListByModuleID(classConstant::MODULE_GROUP)) > 0) {
                $ncount   = $localGroup{0};
                $itemType = $localGroup{1};

                $this->SendDataToChildren(json_encode(array(
                  'DataID'  => classConstant::TX_GROUP,
                  'id'      => $this->InstanceID,
                  'connect' => $connect,
                  'mode'    => classConstant::MODE_GROUP_LOCAL,
                  'method'  => $sendMethod,
                  'buffer'  => utf8_encode($ncount.$itemType.$groupDevice),
                  'debug'   => $this->debug,
                  'message' => $this->message))
                );
              }

              if ($connect == classConstant::CONNECT_LOCAL_CLOUD) {
                if ($this->syncScene && !empty($cloudScene) && ord($cloudScene{0}) > 0) {
                  $this->SendDataToChildren(json_encode(array(
                    'DataID'  => classConstant::TX_GROUP,
                    'id'      => $this->InstanceID,
                    'connect' => $connect,
                    'mode'    => classConstant::MODE_GROUP_SCENE,
                    'method'  => $sendMethod,
                    'buffer'  => utf8_encode($cloudScene),
                    'debug'   => $this->debug,
                    'message' => $this->message))
                  );
                }
              }
            }
          }
        }

        //Reset to default and activate timer
        $this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);

        if ($this->GetBuffer("timerMode") == TIMER_MODE_OFF) {
          $this->SetBuffer("timerMode", TIMER_MODE_ON);
        }
      }
    }
  }


  private function setGatewayInfo($lightifySocket, $method) {
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
      if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_GATEWAY_WIFI, classConstant::SCAN_WIFI_CONFIG))) {
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
      if (false !== ($data = $lightifySocket->sendRaw(stdCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))) {
        $firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});

        if (GetValueString($firmwareID) != $firmware) {
          SetValueString($firmwareID, (string)$firmware);
        }
      }
    }
  }


  private function getWiFi($data) {
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


  private function readData($command, $data = null) {
    switch ($command) {
      case stdCommand::GET_DEVICE_LIST:
        $ncount = ord($data{0})+ord($data{1});
        $data   = substr($data, 2);

        $deviceList  = vtNoString;
        $deviceGroup = vtNoString;
        $localDevice = vtNoString;

        //Parse devices
        for ($i = 1, $j = 0, $m = 0, $n = 0; $i <= $ncount; $i++) {
          $itemType    = ord($data{10});
          $implemented = true;
          $withGroup   = false;

          //Decode Device label
          switch ($itemType) {
            case classConstant::TYPE_FIXED_WHITE:
              //fall through

            case classConstant::TYPE_LIGHT_CCT:
              //fall through

            case classConstant::TYPE_LIGHT_DIMABLE:
              //fall through

            case classConstant::TYPE_LIGHT_COLOR:
              //fall through

            case classConstant::TYPE_LIGHT_EXT_COLOR:
              $classInfo = "Lampe";
              $withGroup = true;
              break;

            case classConstant::TYPE_PLUG_ONOFF:
              $classInfo = "Steckdose";
              $withGroup = true;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $classInfo = "Sensor";
              break;

            case classConstant::TYPE_DIMMER_2WAY:
              $classInfo   = "Dimmer";
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $classInfo   = "Schalter";
              break;

            default:
              $implemented = false;
              $classInfo   = "Unbekannt";

              if ($this->debug % 2 || $this->message) {
                $info = "Type [".$itemType."] not defined!";

                if ($this->debug % 2) {
                  $this->SendDebug("<GATEWAY|READDATA|DEVICES:LOCAL>", $info, 0);
                }

                if ($this->message) {
                  IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:LOCAL>   ".$info);
                }
              }
          }

          if ($implemented) {
            $deviceID     = $i;
            $localDevice .= chr($deviceID).substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
            //$localDevice .= "i".str_pad($deviceID, 3, "0", STR_PAD_LEFT).substr($data, 0, classConstant::DATA_DEVICE_LENGTH);
            $classInfo    = str_pad($classInfo, classConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);

            $uint64       = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $deviceName   = substr($data, 26, classConstant::DATA_NAME_LENGTH);
            $deviceList  .= chr($deviceID).$uint64.$deviceName.$classInfo;
            $j += 1;

            //Device group
            if ($withGroup) {
              $deviceGroup .= $uint64.substr($data, 16, 2);
              $n += 1; 
            }
          }

          if (($length = strlen($data)) > classConstant::DATA_DEVICE_LENGTH) {
            $length = classConstant::DATA_DEVICE_LENGTH;
          }

          $data = substr($data, $length);
        }

        //Store at runtime
        $this->SetBuffer("deviceList", chr($j).$deviceList);
        $this->SetBuffer("deviceGroup", chr($n).$deviceGroup);

        if ($this->debug % 2 || $this->message) {
          $info = ($j > 0) ? $j."/".$i."/".$this->lightifyBase->decodeData($localDevice) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<GATEWAY|READDATA|DEVICES:LOCAL>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:LOCAL>   ".$info);
          }
        }

        //Return device buffer string
        if ($this->syncDevice) {
          return chr($j).chr($i).$localDevice;
        }
        break;

      case classConstant::GET_DEVICE_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_DEVICE_LIST);

        if ($cloudBuffer !== false) {
          $localDevice = $this->GetBuffer("localDevice");
          $ncount      = ord($localDevice{0});
          $localDevice = substr($localDevice, 2);

          for ($i = 1; $i <= $ncount; $i++) {
            $deviceID    = ord($localDevice{0});
            $localDevice = substr($localDevice, 1);
            $deviceName  = trim(substr($localDevice, 26, classConstant::DATA_NAME_LENGTH));

            foreach ($cloudBuffer as $device) {
              if ($deviceID == $device->deviceId) {
                //IPS_LogMessage("SymconOSR", "<READDATA>   ".$deviceID."/".$device->deviceId."/".$deviceName."/".$device->name);

                $zigBee      = dechex(ord($localDevice{0})).dechex(ord($localDevice{1}));
                $deviceModel = strtoupper($device->modelName);

                //Modell mapping
                if (substr($deviceModel, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $deviceModel = "CLASSIC A60 W CLEAR";
                }

                if (substr($deviceModel, 0, 4) == "PLUG") {
                  $deviceModel = classConstant::MODEL_PLUG_ONOFF;
                }

                $cloudDevice[] = array(
                  $device->deviceId, $device->deviceType,
                  strtoupper($device->manufacturer), $deviceModel,
                  $device->bmpClusters,
                  $zigBee, $device->firmwareVersion
                );
                break;
              }
            }
            $localDevice = substr($localDevice, classConstant::DATA_DEVICE_LENGTH);
          }

          if (isset($cloudDevice)) {
            $cloudDevice = json_encode($cloudDevice);

            if ($this->debug % 2 || $this->message) {
              $jsonBuffer = json_encode($cloudBuffer);

              if ($this->debug % 2) {
                $this->SendDebug("<GATEWAY|READDATA|DEVICES:CLOUD>", $jsonBuffer, 0);
                $this->SendDebug("<GATEWAY|READDATA|DEVICES:CLOUD>", $cloudDevice, 0);
              }

              if ($this->message) {
                IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:CLOUD>   ".$jsonBuffer);
                IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:CLOUD>   ".$cloudDevice);
              }
            }

            return $cloudDevice;
          }
        }
        return false;

      case stdCommand::GET_GROUP_LIST:
        $ncount      = ord($data{0})+ord($data{1});
        $data        = substr($data, 2);

        $itemType    = classConstant::TYPE_DEVICE_GROUP;
        $localGroup  = vtNoString;
        $groupDevice = vtNoString;
        $groupList   = vtNoString;

        for ($i = 1; $i <= $ncount; $i++) {
          $deviceGroup  = $this->GetBuffer("deviceGroup");
          $groupID      = ord($data{0});
          $buffer       = vtNoString;
          $n = 0;

          if (($dcount = ord($deviceGroup{0})) > 0) {
            $deviceGroup = substr($deviceGroup, 1);

            for ($j = 1; $j <= $dcount; $j++) {
              $groups = $this->lightifyBase->decodeGroup(ord($deviceGroup{8}), ord($deviceGroup{9}));

              foreach ($groups as $key) {
                if ($groupID == $key) {
                  $buffer .= substr($deviceGroup, 0, classConstant::UUID_DEVICE_LENGTH);
                  $n += 1;
                  break;
                }
              }
              $deviceGroup = substr($deviceGroup, classConstant::DATA_GROUP_DEVICE);
            }
          }

          $localGroup  .= substr($data, 0, classConstant::DATA_GROUP_LENGTH);
          $groupDevice .= chr($groupID).chr($n).$buffer;

          //$groupID      = "i".str_pad($groupID, 3, "0", STR_PAD_LEFT);
          //$localGroup  .= $groupID.substr($data, 1, classConstant::DATA_GROUP_LENGTH);
          $groupDevice .= $groupID.chr($n).$buffer;
          $groupList   .= substr($data,0, classConstant::DATA_GROUP_LENGTH).chr($n);
          //IPS_LogMessage("SymconOSR", "<READDATA>   ".$i."/".$groupID."/".$k."/".$this->lightifyBase->decodeData($buffer));

          if (($length = strlen($data)) > classConstant::DATA_GROUP_LENGTH) {
            $length = classConstant::DATA_GROUP_LENGTH;
          }

          $data = substr($data, $length);
        }

        //Store at runtime
        $this->SetBuffer("groupList", chr($ncount).$groupList);
        $this->SetBuffer("groupDevice", $groupDevice);

        if ($this->debug % 2 || $this->message) {
          $info = ($ncount > 0) ? $ncount."/".$itemType."/".$this->lightifyBase->decodeData($localGroup) : "null";

          if ($this->debug % 2) {
            $this->SendDebug("<GATEWAY|READDATA|GROUPS:LOCAL>", $info, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|GROUPS:LOCAL>   ".$info);
          }
        }

        //Return group buffer string
        if ($this->syncGroup) {
          return chr($ncount).chr($itemType).$localGroup;
        }
        return false;

      case classConstant::GET_GROUP_CLOUD:
        $cloudBuffer = $this->cloudGET(self::RESSOURCE_GROUP_LIST);

        if ($cloudBuffer !== false) {
          $cloudGroup = json_encode($cloudBuffer);

          if ($this->debug % 2) {
            $this->SendDebug("<GATEWAY|READDATA|GROUPS:CLOUD>", $cloudGroup, 0);
          }

          if ($this->message) {
            IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|GROUPS:CLOUD>   ".$cloudGroup);
          }

          return $cloudGroup;
        }
        return false;

      case classConstant::GET_GROUP_SCENE:
        $cloudGroup  = json_decode($this->GetBuffer("cloudGroup"));
        $itemType    = classConstant::TYPE_GROUP_SCENE;

        $cloudScene  = vtNoString;
        $i = 0;

        foreach ($cloudGroup as $group) {
          $groupScenes = $group->scenes;
          $groupName   = str_pad($group->name, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);

          if (!empty($groupScenes)) {
            $j = 0;

            foreach ($groupScenes as $sceneID => $sceneName) {
              $sceneName   = str_pad($sceneName, classConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);
              $cloudScene .= chr($group->groupId).chr($sceneID).$sceneName;
              $sceneList  .= chr($sceneID).$sceneName.$groupName.chr(count($group->devices));
              $i += 1; $j += 1;
            }
          }
        }

        //Store at runtime
        if (isset($sceneList)) {
          $this->SetBuffer("sceneList", chr($i).$sceneList);

          if ($this->debug % 2 || $this->message) {
            $info = $i."/".$itemType."/".$this->lightifyBase->decodeData($cloudScene);

            if ($this->debug % 2) {
              $this->SendDebug("<GATEWAY|READDATA|SCENES:CLOUD>", $info, 0);
            }

            if ($this->message) {
              IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|SCENES:CLOUD>   ".$info);
            }
          }

          return chr($i).chr($itemType).$cloudScene;
        }
        return false;
    }

    return chr(00).vtNoString;
  }


  private function createInstance($mode, $data) {
    $ncount = ord($data{0});

    switch ($mode) {
      case classConstant::MODE_CREATE_DEVICE:
        $data = substr($data, 2);

        for ($i = 1; $i <= $ncount; $i++) {
          $deviceID    = ord($data{0});
          $data        = substr($data, 1);

          //$deviceID    = (int)substr($data, 1, 3);
          //$data        = substr($data, 4);

          $itemType    = ord($data{10});
          $implemented = true;

          switch ($itemType) {
            case classConstant::TYPE_PLUG_ONOFF:
              $itemClass  = classConstant::CLASS_LIGHTIFY_PLUG;
              $sync       = $this->syncDevice;
              $categoryID = ($sync) ? $this->deviceCategory->categoryID : false;
              break;

            case classConstant::TYPE_SENSOR_MOTION:
              $itemClass  = classConstant::CLASS_LIGHTIFY_SENSOR;
              $sync       = $this->syncSensor;
              $categoryID = ($sync) ? $this->sensorCategory->categoryID : false;
              break;

            case classConstant::TYPE_DIMMER_2WAY:
              $implemented = false;
              break;

            case classConstant::TYPE_SWITCH_4WAY:
              $implemented = false;
              break;

            default:
              $itemClass  = classConstant::CLASS_LIGHTIFY_LIGHT;
              $sync       = $this->syncDevice;
              $categoryID = ($sync) ? $this->deviceCategory->categoryID : false;
          }

          if ($implemented && $categoryID !== false && IPS_CategoryExists($categoryID)) {
            $uintUUID   = substr($data, 2, classConstant::UUID_DEVICE_LENGTH);
            $deviceName = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
            //IPS_LogMessage("SymconOSR", "<CREATEINSTANCE>   ".$i."/".$deviceID."/".$itemType."/".$deviceName."/".$this->lightifyBase->decodeData($data));

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_DEVICE);

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetName($InstanceID, (string)$deviceName);
              IPS_SetPosition($InstanceID, 210+$deviceID);

              IPS_SetProperty($InstanceID, "deviceID", (integer)$deviceID);
            }

            if ($InstanceID) {
              if (@IPS_GetProperty($InstanceID, "itemClass") != $itemClass) {
                IPS_SetProperty($InstanceID, "itemClass", (integer)$itemClass);
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
        $data       = substr($data, 2);
        $sync       = $this->syncGroup;
        $categoryID = ($sync) ? $this->groupCategory->categoryID : false;

        if ($categoryID !== false && IPS_CategoryExists($categoryID)) {
          for ($i = 1; $i <= $ncount; $i++) {
            $uintUUID   = $data{0}.$data{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
            $groupID    = ord($data{0});

            //$groupID    = (int)substr($data, 1, 3);
            //$uintUUID   = chr($groupID).chr(0x00).chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
            //$data       = substr($data, 4);

            $groupName  = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
            //$groupName  = trim(substr($data, 0, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

              IPS_SetParent($InstanceID, $categoryID);
              IPS_SetName($InstanceID, (string)$groupName);
              IPS_SetPosition($InstanceID, 210+$groupID);

              IPS_SetProperty($InstanceID, "itemID", (integer)$groupID);
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
        $data       = substr($data, 1);
        $sync       = $this->syncScene;
        $categoryID = ($sync) ? $this->sceneCategory->categoryID : false;

        if ($categoryID !== false && IPS_CategoryExists($categoryID)) {
          $itemType = ord($data{0});
          $data     = substr($data, 1);

          for ($i = 1; $i <= $ncount; $i++) {
            $uintUUID   = $data{1}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
            $sceneID    = ord($data{1});

            //$sceneID    = (int)substr($data, 1, 3);
            //$uintUUID   = chr($sceneID).chr(0x00).chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
            //$data       = substr($data, 4);

            $sceneName  = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
            $InstanceID = $this->lightifyBase->getObjectByProperty(classConstant::MODULE_GROUP, "uintUUID", $uintUUID);
            //IPS_LogMessage("SymconOSR", "<CREATEINSTANCE|SCENES>   ".$ncount."/".$itemType."/".ord($data{0})."/".$sceneID."/".$this->lightifyBase->chrToUUID($uintUUID)."/".$sceneName);

            if (!$InstanceID) {
              $InstanceID = IPS_CreateInstance(classConstant::MODULE_GROUP);

              IPS_SetParent($InstanceID, $this->sceneCategory->categoryID);
              IPS_SetName($InstanceID, (string)$sceneName);
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


  protected function loadCache() {
    $buffer = $this->GetBuffer("lightifyCache");

    if (empty($buffer)) {
      return;
    } else {
      $lightifyCache = @unserialize($buffer);

      if (!$lightifyCache) {
        return;
      }
    }

    $this->userId        = $lightifyCache['userId'];
    $this->securityToken = $lightifyCache['securityToken'];
    $this->cache_expires = $lightifyCache['cache_expires'];

    if ($this->debug % 2 || $this->message) {
      $info = json_encode($lightifyCache);

      if ($this->debug % 2) {
        $this->SendDebug("<GATEWAY|LOADCACHE>", $info, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<GATEWAY|LOADCACHE>   ".$info);
      }
    }
  }


  protected function saveCache() {
    $buffer = array(
      'userId'        => $this->userId,
      'securityToken' => $this->securityToken,
      'cache_expires' => $this->cache_expires
    );

    if ($this->debug % 2 || $this->message) {
      $info = json_encode($buffer);

      if ($this->debug % 2) {
        $this->SendDebug("<GATEWAY|SAVECACHE>", $info, 0);
      }

      if ($this->message) {
        IPS_LogMessage("SymconOSR", "<GATEWAY|SAVECACHE>   ".$info);
      }
    }

    $this->SetBuffer("lightifyCache", serialize($buffer));
  }

}