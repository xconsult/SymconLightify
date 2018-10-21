<?php

declare(strict_types=1);

//Commands
class classCommand
{

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
class classConstant
{

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

  const MODULE_GATEWAY = "{C3859938-D71C-4714-8B02-F2889A62F481}";
  const MODULE_DEVICE  = "{0028DE9E-6155-451A-97E1-7D2D1563F5BA}";
  const MODULE_GROUP   = "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}";

  const TX_GATEWAY     = "{6C85A599-D9A5-4478-89F2-7907BB3E5E0E}";
  const TX_DEVICE      = "{0EC8C035-D581-4DF2-880D-E3C400F41682}";
  const TX_GROUP       = "{C74EF90E-1D24-4085-9A3B-7929F47FF6FA}";

  const GATEWAY_PORT            = 4000;

  const STATE_ONLINE            = 2;
  const STATE_UNKNOWN           = 1;
  const STATE_OFFLINE           = 0;

  const CONNECT_LOCAL_ONLY      = 1001;
  const CONNECT_LOCAL_CLOUD     = 1002;

  const METHOD_PARENT_CONFIG    = 1201;
  const METHOD_LOAD_LOCAL       = 1202;
  const METHOD_RELOAD_LOCAL     = 1203;
  const METHOD_APPLY_LOCAL      = 1204;
  const METHOD_UPDATE_PARENT    = 1205;
  const METHOD_LOAD_CHILD       = 1206;
  const METHOD_APPLY_CHILD      = 1207;
  const METHOD_CREATE_CHILD     = 1208;
  const METHOD_UPDATE_CHILD     = 1209;
  const METHOD_LOAD_CLOUD       = 1210;
  const METHOD_WRITE_CLOUD      = 1211;
  const METHOD_LOAD_INSTANCE    = 1212;
  const METHOD_STATE_DEVICE     = 1213;
  const METHOD_STATE_GROUP      = 1214;
  const METHOD_STATE_ALL_SWITCH = 1215;
  const METHOD_ALL_DEVICES      = 1216;

  const MODE_GATEWAY_LOCAL      = 1401;
  const MODE_DEVICE_LOCAL       = 1402;
  const MODE_DEVICE_GROUP       = 1403;
  const MODE_DEVICE_CLOUD       = 1404;
  const MODE_GROUP_LOCAL        = 1405;
  const MODE_GROUP_CLOUD        = 1406;
  const MODE_GROUP_SCENE        = 1407;
  const MODE_SCENE_CLOUD        = 1408;
  const MODE_ALL_SWITCH         = 1409;

  const MODE_CREATE_DEVICE      = 1410;
  const MODE_CREATE_GROUP       = 1411;
  const MODE_CREATE_SCENE       = 1412;
  const MODE_CREATE_ALL_SWITCH  = 1413;

  const MODE_DEVICE_INFO        = 1414;
  const MODE_MAINTAIN_ACTION    = 1415;
  const MODE_DELETE_VARIABLE    = 1416;
  const MODE_STATE_DEVICE       = 1417;
  const MODE_STATE_GROUP        = 1418;
  const MODE_STATE_ALL_SWITCH   = 1419;

  const GET_GATEWAY_LOCAL       = 1604;
  const GET_DEVICE_LOCAL        = 1605;
  const GET_GROUP_LOCAL         = 1606;
  const GET_DEVICE_CLOUD        = 1607;
  const GET_GROUP_CLOUD         = 1608;
  const GET_GROUP_SCENE         = 1609;
  const GET_SCENE_CLOUD         = 1610;

  const SET_BUFFER_SYNC         = 1801;
  const SET_BUFFER_DEVICE       = 1802;
  const SET_BUFFER_GROUP        = 1803;
  const SET_LIGHT_DATA          = 1809;

  const SET_STATE_ON            = 1;
  const SET_STATE_OFF           = 0;

  const MODE_DEVICE_STATE       = 2003;
  const MODE_GROUP_STATE        = 2007;
  const MODE_LIGHTIFY_STATE     = 2013;

  const OSRAM_ZIGBEE_LENGTH     = 2;
  const ITEM_FILTER_LENGTH      = 3;
  const UUID_OSRAM_LIGHTIFY     = "84:18:26";
  const UUID_OSRAM_LENGTH       = 8;
  const UUID_DEVICE_LENGTH      = 8;
  const UUID_GROUP_LENGTH       = 2;
  const UUID_SCENE_LENGTH       = 1;
  const UUID_STRING_LENGTH      = 23;

  const BUFFER_HEADER_LENGTH    = 8;
  const BUFFER_TOKEN_LENGTH     = 4;
  const BUFFER_REPLY_LENGTH     = 11;
  const BUFFER_ONLINE_LENGTH    = 23;

  const DATA_DEVICE_LOADED      = 50;
  const DATA_DEVICE_LENGTH      = 41;
  const DATA_GROUP_LENGTH       = 18;
  const DATA_SCENE_LENGTH       = 17;
  const DATA_NAME_LENGTH        = 15;
  const DATA_WIFI_LENGTH        = 97;
  const DATA_CLASS_INFO         = 10;
  const DATA_DEVICE_LIST        = 33;
  const DATA_GROUP_LIST         = 19;
  const DATA_SCENE_LIST         = 32;
  const DATA_GROUP_DEVICE       = 10;

  const WIFI_PROFILE_LENGTH     = 31;
  const WIFI_SSID_LENGTH        = 32;
  const WIFI_BSSID_LENGTH       = 5;
  const WIFI_CHANNEL_LENGTH     = 3;

  const CLASS_LIGHTIFY_LIGHT    = 2001;
  const CLASS_LIGHTIFY_PLUG     = 2002;
  const CLASS_LIGHTIFY_SENSOR   = 2003;
  const CLASS_LIGHTIFY_DIMMER   = 2004;
  const CLASS_LIGHTIFY_SWITCH   = 2005;
  const CLASS_LIGHTIFY_GROUP    = 2006;
  const CLASS_LIGHTIFY_SCENE    = 2007;
  const CLASS_ALL_DEVICES       = 2008;
  const CLASS_UNKNOWN           = 2099;

  const TYPE_FIXED_WHITE        = 1;  //Fixed White
  const TYPE_LIGHT_CCT          = 2;  //Tuneable White
  const TYPE_LIGHT_DIMABLE      = 4;  //Can only control brightness
  const TYPE_LIGHT_COLOR        = 8;  //Fixed White and RGB
  const TYPE_LIGHT_EXT_COLOR    = 10; //Tuneable White and RGBW
  const TYPE_PLUG_ONOFF         = 16; //Only On/off capable lamp/device
  const TYPE_SENSOR_MOTION      = 32; //Motion sensor
  const TYPE_DIMMER_2WAY        = 64; //2 Way dimmer
  const TYPE_SWITCH_4WAY        = 65; //4 Way switch

  const TYPE_DEVICE_GROUP       = 240; // 0xF0
  const TYPE_GROUP_SCENE        = 241; // 0xF1
  const TYPE_ALL_DEVICES        = 255; // 0xFF
  const GROUPID_ALL_DEVICES     = 255; // 0xFF

  const MODEL_MANUFACTURER      = "OSRAM";
  const MODEL_FIXED_WHITE       = "Light-LIGHTIFY";
  const MODEL_LIGHT_CCT         = "Light-LIGHTIFY";
  const MODEL_LIGHT_DIMABLE     = "Light-LIGHTIFY";
  const MODEL_LIGHT_COLOR       = "Light-LIGHTIFY";
  const MODEL_LIGHT_EXT_COLOR   = "Light-LIGHTIFY";
  const MODEL_PLUG_ONOFF        = "Plug-LIGHTIFY";
  const MODEL_SENSOR_MOTION     = "Motion-LIGHTIFY";
  const MODEL_DIMMER_2WAY       = "Dimmer-LIGHTIFY";
  const MODEL_SWITCH_4WAY       = "Switch-LIGHTIFY";
  const MODEL_DEVICE            = "Device-LIGHTIFY";
  const MODEL_GROUP             = "Group-LIGHTIFY";
  const MODEL_UNKNOWN           = "Unknown-LIGHTIFY";

  const LABEL_FIXED_WHITE       = "On|Off";
  const LABEL_LIGHT_CCT         = "On|Off Level Temperature";
  const LABEL_LIGHT_DIMABLE     = "On|Off Level";
  const LABEL_LIGHT_COLOR       = "On|Off Level Colour";
  const LABEL_LIGHT_EXT_COLOR   = "On|Off Level Colour Temperature";
  const LABEL_PLUG_ONOFF        = "On|Off";
  const LABEL_SENSOR_MOTION     = "Active|Inactive";
  const LABEL_DIMMER_2WAY       = "-";
  const LABEL_SWITCH_4WAY       = "-";
  const LABEL_UNKNOWN           = "-Unknown-";

  const CTEMP_DEFAULT           = 2700;
  const CTEMP_DIMABLE_MIN       = 2700;
  const CTEMP_DIMABLE_MAX       = 6500;
  const CTEMP_CCT_MIN           = 2700;
  const CTEMP_CCT_MAX           = 6500;
  const CTEMP_COLOR_MIN         = 2000;
  const CTEMP_COLOR_MAX         = 8000;

  const HUE_MIN                 = 0;
  const HUE_MAX                 = 360;
  const COLOR_DEFAULT           = "ffffff";
  const COLOR_MIN               = "0000ff";
  const COLOR_MAX               = "ffffff";
  const INTENSITY_DEFAULT       = 100;
  const INTENSITY_MIN           = 0;
  const INTENSITY_MAX           = 100;

  const TRANSITION_DEFAULT      = 0;  //0.0 sec
  const TRANSITION_MIN          = 0;  //0.0 sec
  const TRANSITION_MAX          = 80; //8.0 sec

  const COLOR_SPEED_MIN         = 5;
  const COLOR_SPEED_MAX         = 65535;

  const SCENE_RELAX             = 2700;
  const SCENE_ACTIVE            = 6500;
  const SCENE_PLANT_LIGHT       = "ff2a6D";

  const GET_WIFI_CONFIG         = 0x00;
  const SET_WIFI_CONFIG         = 0x01;
  const SCAN_WIFI_CONFIG        = 0x03;

  const REQUESTID_HIGH     = 4294967295;
  const INFO_NOT_AVAILABLE = "---- Information nicht verfügbar ----";

  const WRITE_KEY_VALUES   = "ALL_DEVICES,SAVE,SCENE,DEFAULT,SOFT_ON,SOFT_OFF,TRANSITION,RELAX,ACTIVE,PLANT_LIGHT,STATE,COLOR,COLOR_TEMPERATURE,BRIGHTNESS,LEVEL,SATURATION";
  const LIST_KEY_IDENTS    = "HUE,COLOR,COLOR_TEMPERATURE,BRIGHTNESS,LEVEL,SATURATION,MOTION,SCENE,ZIGBEE,FIRMWARE";

}


//Base functions  
class lightifyBase
{


  public function getRequestID($uniqueID)
  {

    $arrayID   = str_split(str_pad(dechex($uniqueID), classConstant::UUID_DEVICE_LENGTH, "0", STR_PAD_RIGHT), 2);
    $requestID = vtNoString;

    foreach ($arrayID as $item) {
      $requestID .= chr($item);
    }

    return $requestID;

  }


  public function decodeData($data, $space = true)
  {

    $decode = vtNoString;

    for ($i = 0; $i < strlen($data); $i++) {
      $decode = $decode.(($space) ? " " : "").sprintf("%02d", ord($data{$i}));
    }

    return $decode;

  }


  public function decodeDataHex($data, $space = true)
  {

    $decode = vtNoString;

    for ($i = 0; $i < strlen($data); $i++) {
      $decode = $decode.(($space) ? " " : "").sprintf("%02x", ord($data{$i}));
    }

    return $decode;

  }


  public function decodeGroup($lowBits, $highBits)
  {

    $binary = strrev(sprintf("%08s%08s", decbin($highBits), decbin($lowBits)));
    $split  = str_split($binary);
    $count  = count($split);
    $result = array();

    for ($i = $count; $i > 0; $i--) {
      if ($split[$i-1]) $result[] = $i;
    }

    return array_reverse($result);

  }


  public function UUIDtoChr($UUID)
  {

    $UUID   = explode(":", $UUID);
    $result = vtNoString;

    foreach ($UUID as $value) {
      $result = chr(hexdec($value)).$result;
    }

    $length = strlen($result);
    $result = ($length == classConstant::UUID_OSRAM_LENGTH) ? $result : $result.str_repeat(chr(00), classConstant::UUID_OSRAM_LENGTH-$length);

    return $result;

  }


  public function chrToUUID($UUID)
  {

    $length = strlen($UUID);
    $result = array();

    for ($i = 0; $i < $length; $i++) {
      $result[] = sprintf("%02x", ord(substr($UUID, $i, 1)));
    }

    return implode(":", array_reverse($result));

  }


  public function nameToChr($name)
  {

    $result = "";

    for ($i = 0; $i < classConstant::DATA_NAME_LENGTH; ++$i) {
      $result .= chr(ord(substr($name, $i, 1)));
    }

    return $result;

  }


  public function HEX2HSV($hex)
  {

    $r = substr($hex, 0, 2);
    $g = substr($hex, 2, 2);
    $b = substr($hex, 4, 2);

    return $this->RGB2HSV(hexdec($r), hexdec($g), hexdec($b));

  }


  private function RGB2HSV($r, $g, $b)
  {

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


  public function HSV2HEX($h, $s, $v)
  {

    $rgb = $this->HSV2RGB($h, $s, $v);

    $r = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
    $g = str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
    $b = str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

    return $r.$g.$b;

  }


  private function HSV2RGB($h, $s, $v)
  {

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


  public function RGB2HEX($rgb)
  {

    $hex  = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

    return $hex;

  }


  public function HEX2RGB($hex)
  {

    if (strlen($hex) == 3) {
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