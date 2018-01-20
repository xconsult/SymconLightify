<?

if (@constant('IPS_BASE') == null) { //Nur wenn Konstanten noch nicht bekannt sind.
  // --- BASE MESSAGE
  define('IPS_BASE',                         10000);             //Kernel Base Message
  define('IPS_MODULBASE',                    20000);             //Modul Base Message
  define('IPS_KERNELSTARTED',         IPS_BASE + 1);             //Post Ready Message
  define('IPS_KERNELSHUTDOWN',        IPS_BASE + 2);             //Pre Shutdown Message, Runlevel UNINIT Follows

  // --- KERNEL
  define('IPS_KERNELMESSAGE',         IPS_BASE + 100);           //Kernel Message
  define('KR_CREATE',                 IPS_KERNELMESSAGE + 1);    //Kernel is beeing created
  define('KR_INIT',                   IPS_KERNELMESSAGE + 2);    //Kernel Components are beeing initialised, Modules loaded, Settings read
  define('KR_READY',                  IPS_KERNELMESSAGE + 3);    //Kernel is ready and running
  define('KR_UNINIT',                 IPS_KERNELMESSAGE + 4);    //Got Shutdown Message, unloading all stuff
  define('KR_SHUTDOWN',               IPS_KERNELMESSAGE + 5);    //Uninit Complete, Destroying Kernel Inteface

  // --- KERNEL LOGMESSAGE
  define('IPS_LOGMESSAGE',            IPS_BASE + 200);           //Logmessage Message
  define('KL_MESSAGE',                IPS_LOGMESSAGE + 1);       //Normal Message                      | FG: Black | BG: White  | STLYE : NONE
  define('KL_SUCCESS',                IPS_LOGMESSAGE + 2);       //Success Message                     | FG: Black | BG: Green  | STYLE : NONE
  define('KL_NOTIFY',                 IPS_LOGMESSAGE + 3);       //Notiy about Changes                 | FG: Black | BG: Blue   | STLYE : NONE
  define('KL_WARNING',                IPS_LOGMESSAGE + 4);       //Warnings                            | FG: Black | BG: Yellow | STLYE : NONE
  define('KL_ERROR',                  IPS_LOGMESSAGE + 5);       //Error Message                       | FG: Black | BG: Red    | STLYE : BOLD
  define('KL_DEBUG',                  IPS_LOGMESSAGE + 6);       //Debug Informations + Script Results | FG: Grey  | BG: White  | STLYE : NONE
  define('KL_CUSTOM',                 IPS_LOGMESSAGE + 7);       //User Message                        | FG: Black | BG: White  | STLYE : NONE

  // --- MODULE LOADER
  define('IPS_MODULEMESSAGE',         IPS_BASE + 300);           //Module Loader Message
  define('ML_LOAD',                   IPS_MODULEMESSAGE + 1);    //Module loaded
  define('ML_UNLOAD',                 IPS_MODULEMESSAGE + 2);    //Module unloaded

  // --- OBJECT MANAGER
  define('IPS_OBJECTMESSAGE',         IPS_BASE + 400);           //Object Manager Message
  define('OM_REGISTER',               IPS_OBJECTMESSAGE + 1);    //Object was registered
  define('OM_UNREGISTER',             IPS_OBJECTMESSAGE + 2);    //Object was unregistered
  define('OM_CHANGEPARENT',           IPS_OBJECTMESSAGE + 3);    //Parent was Changed
  define('OM_CHANGENAME',             IPS_OBJECTMESSAGE + 4);    //Name was Changed
  define('OM_CHANGEINFO',             IPS_OBJECTMESSAGE + 5);    //Info was Changed
  define('OM_CHANGETYPE',             IPS_OBJECTMESSAGE + 6);    //Type was Changed
  define('OM_CHANGESUMMARY',          IPS_OBJECTMESSAGE + 7);    //Summary was Changed
  define('OM_CHANGEPOSITION',         IPS_OBJECTMESSAGE + 8);    //Position was Changed
  define('OM_CHANGEREADONLY',         IPS_OBJECTMESSAGE + 9);    //ReadOnly was Changed
  define('OM_CHANGEHIDDEN',           IPS_OBJECTMESSAGE + 10);   //Hidden was Changed
  define('OM_CHANGEICON',             IPS_OBJECTMESSAGE + 11);   //Icon was Changed
  define('OM_CHILDADDED',             IPS_OBJECTMESSAGE + 12);   //Child for Object was added
  define('OM_CHILDREMOVED',           IPS_OBJECTMESSAGE + 13);   //Child for Object was removed
  define('OM_CHANGEIDENT',            IPS_OBJECTMESSAGE + 14);   //Ident was Changed

  // --- INSTANCE MANAGER
  define('IPS_INSTANCEMESSAGE',       IPS_BASE + 500);           //Instance Manager Message
  define('IM_CREATE',                 IPS_INSTANCEMESSAGE + 1);  //Instance created
  define('IM_DELETE',                 IPS_INSTANCEMESSAGE + 2);  //Instance deleted
  define('IM_CONNECT',                IPS_INSTANCEMESSAGE + 3);  //Instance connected
  define('IM_DISCONNECT',             IPS_INSTANCEMESSAGE + 4);  //Instance disconncted
  define('IM_CHANGESTATUS',           IPS_INSTANCEMESSAGE + 5);  //Status was Changed
  define('IM_CHANGESETTINGS',         IPS_INSTANCEMESSAGE + 6);  //Settings were Changed
  define('IM_CHANGESEARCH',           IPS_INSTANCEMESSAGE + 7);  //Searching was started/stopped
  define('IM_SEARCHUPDATE',           IPS_INSTANCEMESSAGE + 8);  //Searching found new results
  define('IM_SEARCHPROGRESS',         IPS_INSTANCEMESSAGE + 9);  //Searching progress in %
  define('IM_SEARCHCOMPLETE',         IPS_INSTANCEMESSAGE + 10); //Searching is complete

  // --- VARIABLE MANAGER
  define('IPS_VARIABLEMESSAGE',       IPS_BASE + 600);           //Variable Manager Message
  define('VM_CREATE',                 IPS_VARIABLEMESSAGE + 1);  //Variable Created
  define('VM_DELETE',                 IPS_VARIABLEMESSAGE + 2);  //Variable Deleted
  define('VM_UPDATE',                 IPS_VARIABLEMESSAGE + 3);  //On Variable Update
  define('VM_CHANGEPROFILENAME',      IPS_VARIABLEMESSAGE + 4);  //On Profile Name Change
  define('VM_CHANGEPROFILEACTION',    IPS_VARIABLEMESSAGE + 5);  //On Profile Action Change

  // --- SCRIPT MANAGER
  define('IPS_SCRIPTMESSAGE',         IPS_BASE + 700);           //Script Manager Message
  define('SM_CREATE',                 IPS_SCRIPTMESSAGE + 1);    //On Script Create
  define('SM_DELETE',                 IPS_SCRIPTMESSAGE + 2);    //On Script Delete
  define('SM_CHANGEFILE',             IPS_SCRIPTMESSAGE + 3);    //On Script File changed
  define('SM_BROKEN',                 IPS_SCRIPTMESSAGE + 4);    //Script Broken Status changed

  // --- EVENT MANAGER
  define('IPS_EVENTMESSAGE',          IPS_BASE + 800);           //Event Scripter Message
  define('EM_CREATE',                 IPS_EVENTMESSAGE + 1);     //On Event Create
  define('EM_DELETE',                 IPS_EVENTMESSAGE + 2);     //On Event Delete
  define('EM_UPDATE',                 IPS_EVENTMESSAGE + 3);
  define('EM_CHANGEACTIVE',           IPS_EVENTMESSAGE + 4);
  define('EM_CHANGELIMIT',            IPS_EVENTMESSAGE + 5);
  define('EM_CHANGESCRIPT',           IPS_EVENTMESSAGE + 6);
  define('EM_CHANGETRIGGER',          IPS_EVENTMESSAGE + 7);
  define('EM_CHANGETRIGGERVALUE',     IPS_EVENTMESSAGE + 8);
  define('EM_CHANGETRIGGEREXECUTION', IPS_EVENTMESSAGE + 9);
  define('EM_CHANGECYCLIC',           IPS_EVENTMESSAGE + 10);
  define('EM_CHANGECYCLICDATEFROM',   IPS_EVENTMESSAGE + 11);
  define('EM_CHANGECYCLICDATETO',     IPS_EVENTMESSAGE + 12);
  define('EM_CHANGECYCLICTIMEFROM',   IPS_EVENTMESSAGE + 13);
  define('EM_CHANGECYCLICTIMETO',     IPS_EVENTMESSAGE + 14);

  // --- MEDIA MANAGER
  define('IPS_MEDIAMESSAGE',          IPS_BASE + 900);           //Media Manager Message
  define('MM_CREATE',                 IPS_MEDIAMESSAGE + 1);     //On Media Create
  define('MM_DELETE',                 IPS_MEDIAMESSAGE + 2);     //On Media Delete
  define('MM_CHANGEFILE',             IPS_MEDIAMESSAGE + 3);     //On Media File changed
  define('MM_AVAILABLE',              IPS_MEDIAMESSAGE + 4);     //Media Available Status changed
  define('MM_UPDATE',                 IPS_MEDIAMESSAGE + 5);

  // --- LINK MANAGER
  define('IPS_LINKMESSAGE',           IPS_BASE + 1000);          //Link Manager Message
  define('LM_CREATE',                 IPS_LINKMESSAGE + 1);      //On Link Create
  define('LM_DELETE',                 IPS_LINKMESSAGE + 2);      //On Link Delete
  define('LM_CHANGETARGET',           IPS_LINKMESSAGE + 3);      //On Link TargetID change

  // --- DATA HANDLER
  define('IPS_DATAMESSAGE',           IPS_BASE + 1100);          //Data Handler Message
  define('DM_CONNECT',                IPS_DATAMESSAGE + 1);      //On Instance Connect
  define('DM_DISCONNECT',             IPS_DATAMESSAGE + 2);      //On Instance Disconnect

  // --- SCRIPT ENGINE
  define('IPS_ENGINEMESSAGE',         IPS_BASE + 1200);          //Script Engine Message
  define('SE_UPDATE',                 IPS_ENGINEMESSAGE + 1);    //On Library Refresh
  define('SE_EXECUTE',                IPS_ENGINEMESSAGE + 2);    //On Script Finished execution
  define('SE_RUNNING',                IPS_ENGINEMESSAGE + 3);    //On Script Started execution

  // --- PROFILE POOL
  define('IPS_PROFILEMESSAGE',        IPS_BASE + 1300);
  define('PM_CREATE',                 IPS_PROFILEMESSAGE + 1);
  define('PM_DELETE',                 IPS_PROFILEMESSAGE + 2);
  define('PM_CHANGETEXT',             IPS_PROFILEMESSAGE + 3);
  define('PM_CHANGEVALUES',           IPS_PROFILEMESSAGE + 4);
  define('PM_CHANGEDIGITS',           IPS_PROFILEMESSAGE + 5);
  define('PM_CHANGEICON',             IPS_PROFILEMESSAGE + 6);
  define('PM_ASSOCIATIONADDED',       IPS_PROFILEMESSAGE + 7);
  define('PM_ASSOCIATIONREMOVED',     IPS_PROFILEMESSAGE + 8);
  define('PM_ASSOCIATIONCHANGED',     IPS_PROFILEMESSAGE + 9);

  // --- TIMER POOL
  define('IPS_TIMERMESSAGE',          IPS_BASE + 1400);          //Timer Pool Message
  define('TM_REGISTER',               IPS_TIMERMESSAGE + 1);
  define('TM_UNREGISTER',             IPS_TIMERMESSAGE + 2);
  define('TM_SETINTERVAL',            IPS_TIMERMESSAGE + 3);
  define('TM_UPDATE',                 IPS_TIMERMESSAGE + 4);
  define('TM_RUNNING',                IPS_TIMERMESSAGE + 5);

  // --- STATUS CODES
  define('IS_SBASE',                           100);             //Status Codes
  define('IS_CREATING',               IS_SBASE + 1);             //Module is being created
  define('IS_ACTIVE',                 IS_SBASE + 2);             //Module created and running
  define('IS_DELETING',               IS_SBASE + 3);             //Module is being deleted
  define('IS_INACTIVE',               IS_SBASE + 4);             //Module is not beeing used

  // --- ERROR CODES
  define('IS_EBASE',                           200);             //Default Error Codes
  define('IS_NOTCREATED',             IS_EBASE + 1);             //Instance could not be created

  // --- Search Handling
  define('FOUND_UNKNOWN',     0); //Undefined value
  define('FOUND_NEW',         1); //Device is new and not configured yet
  define('FOUND_OLD',         2); //Device is already configues (InstanceID should be set)
  define('FOUND_CURRENT',     3); //Device is already configues (InstanceID is from the current/searching Instance)
  define('FOUND_UNSUPPORTED', 4); //Device is not supported by Module

  define('vtBoolean', 0);
  define('vtInteger', 1);
  define('vtFloat',   2);
  define('vtString',  3);

  define('vtNoValue',  -1);
  define('vtNoString', "");
}


//Commands
class stdCommand extends stdClass {

  # 13 List paired devices (broadcast)
  # 1E List configured groups/zones (broadcast)
  # 20 Add device to group/zone
  # 21 Remove device from group/zone
  # 26 Get group/zone information (group/zone)
  # 27 Set group/zone name
  # 28 Set device name
  # 31 Set brigthness (device, group/zone)
  # 32 Set power switch on/off (device, group/zone)
  # 33 Set light color temperature (device, group/zone)
  # 36 Set light color (RGBW) (device, group/zone)
  # 38 Set light state save,
  # 52 Activate scene (device, group/zone)
  # 68 Get device information (device)
  # 6F Gateway Firmware version (broadcast)
  # D5 Cycle group/zone color
  # DB Set light soft on
  # DC Set light soft off

  const GET_DEVICE_LIST       = 0x13;
  const GET_GROUP_LIST        = 0x1E;
  const ADD_DEVICE_TO_GROUP   = 0x20;
  const DEL_DEVICE_FROM_GROUP = 0x21;
  const GET_GROUP_INFO        = 0x26;
  const SET_GROUP_NAME        = 0x27;
  const SET_DEVICE_NAME       = 0x28;
  const SET_LIGHT_LEVEL       = 0x31;
  const SET_DEVICE_STATE      = 0x32;
  const SET_COLOR_TEMPERATURE = 0x33;
  const SET_LIGHT_COLOR       = 0x36;
  const SAVE_LIGHT_STATE      = 0x38;
  const ACTIVATE_GROUP_SCENE  = 0x52;
  const GET_DEVICE_INFO       = 0x68;
  const GET_GATEWAY_FIRMWARE  = 0x6F;
  const CYCLE_LIGHT_COLOR     = 0xD5;
  const SET_LIGHT_SOFT_ON     = 0xDB;
  const SET_LIGHT_SOFT_OFF    = 0xDC;
  const GET_GATEWAY_WIFI      = 0xE3;

}


//Constants
class stdConstant extends stdClass {

  const IPS_BOOLEAN = 0;
  const IPS_INTEGER = 1;
  const IPS_FLOAT   = 2;
  const IPS_STRING  = 3;

  const NO_VALUE    = -1;
  const NO_STRING   = "";

  const DEBUG_DISABLED      = 0;
  const DEBUG_SEND_BUFFER   = 3;
  const DEBUG_RECV_BUFFER   = 7;
  const DEBUG_SEND_RECV     = 13;
  const DEBUG_DETAIL_ERRORS = 17;

  const MODULE_GATEWAY         = "{C3859938-D71C-4714-8B02-F2889A62F481}";
  const MODULE_DEVICE          = "{0028DE9E-6155-451A-97E1-7D2D1563F5BA}";
  const MODULE_GROUP           = "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}";

  const TX_GATEWAY             = "{6C85A599-D9A5-4478-89F2-7907BB3E5E0E}";
  const TX_DEVICE              = "{0EC8C035-D581-4DF2-880D-E3C400F41682}";
  const TX_GROUP               = "{C74EF90E-1D24-4085-9A3B-7929F47FF6FA}";

  const GATEWAY_PORT           = 4000;
  const MAX_PING_TIMEOUT       = 2500; //milli secondes

  const CONNECT_LOCAL_ONLY     = 1001;
  const CONNECT_LOCAL_CLOUD    = 1002;

  const METHOD_PARENT_CONFIG   = 1201;
  const METHOD_LOAD_LOCAL      = 1202;
  const METHOD_APPLY_LOCAL     = 1203;
  const METHOD_UPDATE_PARENT   = 1204;
  const METHOD_LOAD_CHILD      = 1205;
  const METHOD_APPLY_CHILD     = 1206;
  const METHOD_CREATE_CHILD    = 1207;
  const METHOD_UPDATE_CHILD    = 1208;
  const METHOD_LOAD_CLOUD      = 1209;
  const METHOD_WRITE_CLOUD     = 1210;
  const METHOD_LOAD_INSTANCE   = 1211;

  const MODE_GATEWAY_LOCAL     = 1401;
  const MODE_DEVICE_LOCAL      = 1402;
  const MODE_DEVICE_CLOUD      = 1403;
  const MODE_GROUP_LOCAL       = 1404;
  const MODE_GROUP_CLOUD       = 1405;
  const MODE_GROUP_SCENE       = 1406;
  const MODE_SCENE_CLOUD       = 1407;

  const MODE_CREATE_DEVICE     = 1408;
  const MODE_CREATE_GROUP      = 1409;
  const MODE_CREATE_SCENE      = 1410;

  const MODE_DEVICE_INFO       = 1411;
  const MODE_MAINTAIN_ACTION   = 1412;
  const MODE_DELETE_VARIABLE   = 1413;

  const GET_GATEWAY_LOCAL      = 1604;
  const GET_DEVICE_LOCAL       = 1605;
  const GET_GROUP_LOCAL        = 1606;
  const GET_DEVICE_CLOUD       = 1607;
  const GET_GROUP_CLOUD        = 1608;
  const GET_GROUP_SCENE        = 1609;
  const GET_SCENE_CLOUD        = 1610;

  const SET_BUFFER_SYNC        = 1801;
  const SET_BUFFER_DEVICE      = 1802;
  const SET_BUFFER_GROUP       = 1803;
  const SET_LIGHT_DATA         = 1899;

  const MODE_DEVICE_STATE      = 2003;
  const MODE_GROUP_STATE       = 2007;
  const MODE_LIGHTIFY_STATE    = 2013;

  const OSRAM_ZIGBEE_LENGTH    = 2;
  const UUID_OSRAM_LIGHTIFY    = "84:18:26";
  const UUID_OSRAM_LENGTH      = 8;
  const UUID_DEVICE_LENGTH     = 8;
  const UUID_GROUP_LENGTH      = 2;
  const UUID_SCENE_LENGTH      = 1;
  const UUID_STRING_LENGTH     = 23;

  const BUFFER_HEADER_LENGTH   = 8;
  const BUFFER_TOKEN_LENGTH    = 4;
  const BUFFER_REPLY_LENGTH    = 11;
  const BUFFER_ONLINE_LENGTH   = 23;

  const DATA_DEVICE_LENGTH     = 50;
  const DATA_GROUP_LENGTH      = 18;
  const DATA_SCENE_LENGTH      = 17;
  const DATA_NAME_LENGTH       = 15;
  const DATA_WIFI_LENGTH       = 97;
  const DATA_CLASS_INFO        = 10;
  const DATA_DEVICE_LIST       = 33;
  const DATA_GROUP_LIST        = 19;
  const DATA_SCENE_LIST        = 32;
  const DATA_GROUP_DEVICE      = 10;

  const WIFI_PROFILE_LENGTH    = 31;
  const WIFI_SSID_LENGTH       = 32;
  const WIFI_BSSID_LENGTH      = 5;
  const WIFI_CHANNEL_LENGTH    = 3;

  const CLASS_LIGHTIFY_LIGHT   = 2001;
  const CLASS_LIGHTIFY_PLUG    = 2002;
  const CLASS_LIGHTIFY_SENSOR  = 2003;
  const CLASS_LIGHTIFY_DIMMER  = 2004;
  const CLASS_LIGHTIFY_SWITCH  = 2005;
  const CLASS_LIGHTIFY_GROUP   = 2006;
  const CLASS_LIGHTIFY_SCENE   = 2007;
  const CLASS_ALL_LIGHTS       = 2008;
  const CLASS_UNKNOWN          = 2099;

  const TYPE_FIXED_WHITE       = 1;  //Fixed White
  const TYPE_LIGHT_CCT         = 2;  //Tuneable White
  const TYPE_LIGHT_DIMABLE     = 4;  //Can only control level
  const TYPE_LIGHT_COLOR       = 8;  //Fixed White and RGB
  const TYPE_LIGHT_EXT_COLOR   = 10; //Tuneable White and RGBW
  const TYPE_PLUG_ONOFF        = 16; //Only On/off capable lamp/device
  const TYPE_SENSOR_MOTION     = 32; //Motion sensor
  const TYPE_DIMMER_2WAY       = 64; //2 Way dimmer
  const TYPE_SWITCH_4WAY       = 65; //4 Way switch

  const TYPE_DEVICE_GROUP     = 0xF0;
  const TYPE_GROUP_SCENE      = 0xF1;
  const TYPE_ALL_LIGHTS       = 0xFF;

  const MODEL_MANUFACTURER    = "OSRAM";
  const MODEL_FIXED_WHITE     = "Light-LIGHTIFY";
  const MODEL_LIGHT_CCT       = "Light-LIGHTIFY";
  const MODEL_LIGHT_DIMABLE   = "Light-LIGHTIFY";
  const MODEL_LIGHT_COLOR     = "Light-LIGHTIFY";
  const MODEL_LIGHT_EXT_COLOR = "Light-LIGHTIFY";
  const MODEL_PLUG_ONOFF      = "Plug-LIGHTIFY";
  const MODEL_SENSOR_MOTION   = "Motion-LIGHTIFY";
  const MODEL_DIMMER_2WAY     = "Dimmer-LIGHTIFY";
  const MODEL_SWITCH_4WAY     = "Switch-LIGHTIFY";
  const MODEL_DEVICE          = "Device-LIGHTIFY";
  const MODEL_GROUP           = "Group-LIGHTIFY";
  const MODEL_UNKNOWN         = "Unknown-LIGHTIFY";

  const LABEL_FIXED_WHITE     = "On|Off";
  const LABEL_LIGHT_CCT       = "On|Off Level Temperature";
  const LABEL_LIGHT_DIMABLE   = "On|Off Level";
  const LABEL_LIGHT_COLOR     = "On|Off Level Colour";
  const LABEL_LIGHT_EXT_COLOR = "On|Off Level Colour Temperature";
  const LABEL_PLUG_ONOFF      = "On|Off";
  const LABEL_SENSOR_MOTION   = "Active|Inactive";
  const LABEL_DIMMER_2WAY     = "-";
  const LABEL_SWITCH_4WAY     = "-";
  const LABEL_UNKNOWN         = "Unknown-LIGHTIFY";

  const STATE_ONLINE          = 2;
  const STATE_UNKNOWN         = 1;
  const STATE_OFFLINE         = 0;

  const CTEMP_DEFAULT         = 2702;
  const CTEMP_DIMABLE_MIN     = 2702;
  const CTEMP_DIMABLE_MAX     = 6535;
  const CTEMP_CCT_MIN         = 2702;
  const CTEMP_CCT_MAX         = 6535;
  const CTEMP_COLOR_MIN       = 2000;
  const CTEMP_COLOR_MAX       = 6535;

  const HUE_MIN               = 0;
  const HUE_MAX               = 360;
  const COLOR_DEFAULT         = "ffffff";
  const COLOR_MIN             = "0000ff";
  const COLOR_MAX             = "ffffff";
  const INTENSITY_DEFAULT     = 100;
  const INTENSITY_MIN         = 0;
  const INTENSITY_MAX         = 100;

  const TRANSITION_DEFAULT    = 0;  //0.0 sec
  const TRANSITION_MIN        = 0;  //0.0 sec
  const TRANSITION_MAX        = 80; //8.0 sec

  const COLOR_SPEED_MIN       = 5;
  const COLOR_SPEED_MAX       = 65535;

  const SCENE_RELAX           = 2702;
  const SCENE_ACTIVE          = 6535;
  const SCENE_PLANT_LIGHT     = "ff2a6D";

  const GET_WIFI_CONFIG       = 0x00;
  const SET_WIFI_CONFIG       = 0x01;
  const SCAN_WIFI_CONFIG      = 0x03;

  const REQUESTID_HIGH_VALUE  = 4294967295;
  const INFO_NOT_AVAILABLE    = "---- Information nicht verfügbar ----";

  const LIST_KEY_VALUES       = "ALL_LIGHTS,SAVE,NAME,SCENE,DEFAULT,SOFT_ON,SOFT_OFF,TRANSITION,RELAX,ACTIVE,PLANT_LIGHT,STATE,COLOR,COLOR_TEMPERATURE,LEVEL,SATURATION";
  const LIST_KEY_IDENTS       = "HUE,COLOR,COLOR_TEMPERATURE,LEVEL,SATURATION,MOTION,SCENE,ZIGBEE,FIRMWARE";

}


//Base functions  
class lightifyBase extends stdClass {

  public function getObjectByProperty($moduleID, $property, $value) {
    $Instances = IPS_GetInstanceListBymoduleID($moduleID);

    foreach ($Instances as $objectID) {
      if (@IPS_GetProperty($objectID, $property) == $value) return $objectID;
    }

    return false;
  }


  public function getRequestID($uniqueID) {
    $arrayID   = str_split(str_pad(dechex($uniqueID), self::UUID_DEVICE_LENGTH, 0, STR_PAD_RIGHT), 2);
    $requestID = "";

    foreach ($arrayID as $item) {
      $requestID .= chr($item);
    }

    return $requestID;
  }


  public function decodeData($data) {
    $Decode = "";

    for ($i = 0; $i < strlen($data); $i++) {
      $Decode = $Decode." ".sprintf("%02d", ord($data{$i}));
    }

    return $Decode;
  }


  public function decodeGroup($lowBits, $highBits) {
    $binary = strrev(sprintf("%08s%08s", decbin($highBits), decbin($lowBits)));
    $split  = str_split($binary);
    $count  = count($split);
    $result = array();

    for ($i = $count; $i > 0; $i--) {
      if ($split[$i-1]) $result[] = $i;
    }

    return array_reverse($result);
  }


  public function UUIDtoChr($UUID) {
    $UUID   = explode(":", $UUID);
    $result = "";

    foreach ($UUID as $value) {
      $result = chr(hexdec($value)).$result;
    }

    $length = strlen($result);
    $result = ($length == self::UUID_OSRAM_LENGTH) ? $result : $result.str_repeat(chr(00), self::UUID_OSRAM_LENGTH-$length);

    return $result;
  }


  public function chrToUUID($UUID) {
    $length = strlen($UUID);
    $result = array();

    for ($i = 0; $i < $length; $i++) {
      $result[] = sprintf("%02x", ord(substr($UUID, $i, 1)));
    }

    return implode(":", array_reverse($result));
  }


  public function nameToChr($name) {
    $result = "";

    for ($i = 0; $i < self::DATA_NAME_LENGTH; ++$i) {
      $result .= chr(ord(substr($name, $i, 1)));
    }

    return $result;
  }


  public function HEX2HSV($hex) {
    $r = substr($hex, 0, 2);
    $g = substr($hex, 2, 2);
    $b = substr($hex, 4, 2);

    return $this->RGB2HSV(hexdec($r), hexdec($g), hexdec($b));
  }


  private function RGB2HSV($r, $g, $b) {
    $r /= 255; $g /= 255; $b /= 255;

    $maxRGB = max($r, $g, $b);
    $minRGB = min($r, $g, $b);
    $chroma = $maxRGB-$minRGB;
    $dV     = $maxRGB*100;

    if ($chroma == 0) {
      return array('h' => 0, 's' => 0, 'v' => round($dV));
    }

    $dS = ($chroma / $maxRGB) * 100;

    switch ($minRGB) {
      case $r:
        $h = 3 - (($g - $b) / $chroma);
        break;

      case $b:
        $h = 1 - (($r - $g) / $chroma);
        break;

      default:
        $h = 5 - (($b - $r) / $chroma);
    }

    $dH = $h * 60;
    return array('h' => round($dH), 's' => round($dS), 'v' => round($dV));
  }


  public function HSV2HEX($h, $s, $v) {
    $rgb = $this->HSV2RGB($h, $s, $v);

    $r = str_pad(dechex($rgb['r']), 2, 0, STR_PAD_LEFT);
    $g = str_pad(dechex($rgb['g']), 2, 0, STR_PAD_LEFT);
    $b = str_pad(dechex($rgb['b']), 2, 0, STR_PAD_LEFT);

    return $r.$g.$b;
  }


  private function HSV2RGB($h, $s, $v) {
    if ($h < 0) $h = 0;
    if ($h > 360) $h = 360;
    if ($s < 0) $s = 0;
    if ($s > 100) $s = 100;
    if ($v < 0) $v = 0;
    if ($v > 100) $v = 100;

    $dS = $s / 100;
    $dV = $v / 100;
    $dC = $dV * $dS;
    $dH = $h / 60;
    $dT = $dH;

    while ($dT >= 2) {
      $dT -= 2;
    }

    $dX = $dC * (1 - abs($dT - 1));

    switch(floor($dH)) {
      case 0:
        $r = $dC; $g = $dX; $b = 0;
        break;

      case 1:
        $r = $dX; $g = $dC; $b = 0;
        break;

      case 2:
        $r = 0; $g = $dC; $b = $dX; 
        break;

      case 3:
        $r = 0; $g = $dX; $b = $dC;
        break;

      case 4:
        $r = $dX; $g = 0; $b = $dC;
        break;

      case 5:
        $r = $dC; $g = 0; $b = $dX;
        break;

      default:
        $r = 0; $g = 0; $b = 0;
    }

    $dM = $dV - $dC; $r += $dM; $g += $dM; $b += $dM;
    $r *= 255; $g *= 255; $b *= 255;

    return array('r' => round($r), 'g' => round($g), 'b' => round($b));
  }


  public function RGB2HEX($rgb) {
    $hex  = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

    return $hex;
  }


  public function HEX2RGB($hex) {
    if(strlen($hex) == 3) {
      $r = hexdec($hex[0].$hex[0]);
      $g = hexdec($hex[1].$hex[1]);
      $b = hexdec($hex[2].$hex[2]);
    } else {
      $r = hexdec($hex[0].$hex[1]);
      $g = hexdec($hex[2].$hex[3]);
      $b = hexdec($hex[4].$hex[5]);
    }

    return array('r' => $r, 'g' => $g, 'b' => $b);
  }

}