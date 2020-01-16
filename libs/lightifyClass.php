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

  const GET_DEVICE_LIST          = 0x13;
  const GET_GROUP_LIST           = 0x1E;
  const GET_SCENE_LIST           = 0x1F;
  const ADD_DEVICE_TO_GROUP      = 0x20;
  const RENOVE_DEVICE_FROM_GROUP = 0x21;
  const GET_GROUP_INFO           = 0x26;
  const SET_GROUP_NAME           = 0x27;
  const SET_DEVICE_NAME          = 0x28;
  const SET_LIGHT_LEVEL          = 0x31;
  const SET_DEVICE_STATE         = 0x32;
  const SET_COLOR_TEMPERATURE    = 0x33;
  const SET_LIGHT_COLOR          = 0x36;
  const SET_LIGHT_SATURATION     = 0x36;
  const SAVE_LIGHT_STATE         = 0x38;
  const ACTIVATE_GROUP_SCENE     = 0x52;
  const GET_DEVICE_INFO          = 0x68;
  const GET_GATEWAY_FIRMWARE     = 0x6F;
  const CYCLE_LIGHT_COLOR        = 0xD5;
  const SET_LIGHT_SOFT_ON        = 0xDB;
  const SET_LIGHT_SOFT_OFF       = 0xDC;
  const GET_GATEWAY_WIFI         = 0xE3;

}


//Constants
class classConstant
{

  const CLIENT_SOCKET  = "{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}";
  const RX_VIRTUAL     = "{018EF6B5-AB94-40C6-AA53-46943E824ACF}";
  const TX_VIRTUAL     = "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}";

  const MODULE_GATEWAY = "{C3859938-D71C-4714-8B02-F2889A62F481}";
  const MODULE_DEVICE  = "{0028DE9E-6155-451A-97E1-7D2D1563F5BA}";
  const MODULE_GROUP   = "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}";
  const MODULE_SCENE   = "{4C839FA9-6926-4548-8105-DA5B111E39C3}";

  const TX_GATEWAY     = "{6C85A599-D9A5-4478-89F2-7907BB3E5E0E}";
  const TX_DEVICE      = "{0EC8C035-D581-4DF2-880D-E3C400F41682}";
  const TX_GROUP       = "{C74EF90E-1D24-4085-9A3B-7929F47FF6FA}";
  const TX_SCENE       = "{1C913701-904E-4EAD-9A70-702597567A0F}";

  const GATEWAY_PORT           = 4000;
  const TIMER_SYNC             = 5;
  const GATEWAY_SERIAL_LENGTH  = 11;

  const UUID_OSRAM_LENGTH      = 8;
  const DATA_NAME_LENGTH       = 15;

  const GET_DEVICES_LOCAL      = 1001;
  const GET_DEVICES_CLOUD      = 1002;
  const GET_GROUPS_LOCAL       = 1003;
  const GET_GROUPS_CLOUD       = 1004;
  const GET_SCENES_LOCAL       = 1005;
  const GET_SCENES_CLOUD       = 1006;

  const SET_ALL_DEVICES        = 1010;
  const SET_GROUP_STATE        = 1011;
  const SET_LIGHT_SATURATION   = 1012;

  const TYPE_FIXED_WHITE       = 1;   //Fixed White
  const TYPE_LIGHT_CCT         = 2;   //Tuneable White
  const TYPE_LIGHT_DIMABLE     = 4;   //Can only control level
  const TYPE_LIGHT_COLOR       = 8;   //Fixed White and RGB
  const TYPE_LIGHT_EXT_COLOR   = 10;  //Tuneable White and RGBW
  const TYPE_PLUG_ONOFF        = 16;  //Only On/off capable lamp/device
  const TYPE_SENSOR_CONTACT    = 31;  //Contact sensor
  const TYPE_SENSOR_MOTION     = 32;  //Motion sensor
  const TYPE_DIMMER_2WAY       = 64;  //2 button dimmer
  const TYPE_SWITCH_4WAY       = 65;  //4 butten switch
  const TYPE_SWITCH_MINI       = 66;  //Switch Mini
  const TYPE_SWITCH_UKNOWN     = 67;  //Unknown switch
  const TYPE_LIGHT_CCT_TRADFRI = 128; //Tradfri Tuneable White

  const TYPE_DEVICE            = 0;   // 0x00
  const TYPE_DEVICE_GROUP      = 240; // 0xF0
  const TYPE_GROUP_SCENE       = 241; // 0xF1
  const TYPE_ALL_DEVICES       = 255; // 0xFF

  const CTEMP_DIMABLE_MIN      = 2700;
  const CTEMP_DIMABLE_MAX      = 6500;
  const CTEMP_CCT_MIN          = 2700;
  const CTEMP_CCT_MAX          = 6500;
  const CTEMP_COLOR_MIN        = 2000;
  const CTEMP_COLOR_MAX        = 8000;

  const SCENE_PLANT_LIGHT      = "ff2a6D";
  const SCENE_RELAX            = 2700;
  const SCENE_ACTIVE           = 6500;

  const COLOR_SPEED_MIN        = 5;
  const COLOR_SPEED_MAX        = 65535;

  const TIME_MIN               = 0;    //0.0 sec
  const TIME_MAX               = 8000; //8.0 sec

  const WRITE_KEY_VALUES       = "ALL_DEVICES,NAME,SAVE,SCENE,MOOD,SOFT_ON,SOFT_OFF,FADE,RELAX,ACTIVE,PLANT_LIGHT,STATE,COLOR,COLOR_TEMPERATURE,LEVEL,SATURATION";
  const LIST_KEY_IDENTS        = "HUE,COLOR,COLOR_TEMPERATURE,LEVEL,SATURATION,MOTION,SCENE,MOOD,ZIGBEE,FIRMWARE";

}


//Base functions  
class lightifyBase
{


  public function getRequestID(int $uniqueID) : string {

    $arrayID   = str_split(str_pad(dechex($uniqueID), classConstant::UUID_DEVICE_LENGTH, "0", STR_PAD_RIGHT), 2);
    $requestID = vtNoString;

    foreach ($arrayID as $item) {
      $requestID .= chr($item);
    }

    return $requestID;

  }


  public function decodeData(string $data, bool $space = true) : string {

    $decode = vtNoString;

    for ($i = 0; $i < strlen($data); $i++) {
      $decode = $decode.(($space) ? " " : "").sprintf("%02d", ord($data{$i}));
    }

    return $decode;

  }


  public function decodeDataHex(string $data, bool $space = true) : string {

    $decode = vtNoString;

    for ($i = 0; $i < strlen($data); $i++) {
      $decode = $decode.(($space) ? " " : "").sprintf("%02x", ord($data{$i}));
    }

    return $decode;

  }


  public function decodeGroup(int $lowBits, int $highBits) : array {

    $binary = strrev(sprintf("%08s%08s", decbin($highBits), decbin($lowBits)));
    $split  = str_split($binary);
    $count  = count($split);
    $result = array();

    for ($i = $count; $i > 0; $i--) {
      if ($split[$i-1]) $result[] = $i;
    }

    return array_reverse($result);

  }


  public function UUIDtoChr(string $UUID) : string {

    $UUID   = explode(":", $UUID);
    $result = vtNoString;

    foreach ($UUID as $value) {
      $result = chr(hexdec($value)).$result;
    }

    $length = strlen($result);
    $result = ($length == classConstant::UUID_OSRAM_LENGTH) ? $result : $result.str_repeat(chr(00), classConstant::UUID_OSRAM_LENGTH-$length);

    return $result;

  }


  public function chrToUUID(string $UUID) : string {

    $length = strlen($UUID);
    $result = array();

    for ($i = 0; $i < $length; $i++) {
      $result[] = sprintf("%02x", ord(substr($UUID, $i, 1)));
    }

    return implode(":", array_reverse($result));

  }


  public function nameToChr(string $name) : string {

    $result = vtNoString;

    for ($i = 0; $i < classConstant::DATA_NAME_LENGTH; ++$i) {
      $result .= chr(ord(substr($name, $i, 1)));
    }

    return $result;

  }


  public function HEX2HSV(string $hex) : array {

    $r = substr($hex, 0, 2);
    $g = substr($hex, 2, 2);
    $b = substr($hex, 4, 2);

    return $this->RGB2HSV(hexdec($r), hexdec($g), hexdec($b));

  }


  private function RGB2HSV(int $r, int $g, int $b) : array {

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
    return ['h' => round($dH), 's' => round($dS), 'v' => round($dV)];

  }


  public function HSV2HEX(int $h, int $s, int $v) : string {

    $rgb = $this->HSV2RGB($h, $s, $v);

    $r = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
    $g = str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
    $b = str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

    return $r.$g.$b;

  }


  private function HSV2RGB(int $h, int $s, int $v) : array {

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

    return ['r' => round($r), 'g' => round($g), 'b' => round($b)];

  }


  public function RGB2HEX(array $rgb) : string {

    $hex  = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
    $hex .= str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

    return $hex;

  }


  public function HEX2RGB(string $hex) : array {

    if (strlen($hex) == 3) {
      $r = hexdec($hex[0].$hex[0]);
      $g = hexdec($hex[1].$hex[1]);
      $b = hexdec($hex[2].$hex[2]);
    } else {
      $r = hexdec($hex[0].$hex[1]);
      $g = hexdec($hex[2].$hex[3]);
      $b = hexdec($hex[4].$hex[5]);
    }

    return ['r' => $r, 'g' => $g, 'b' => $b];

  }


  public function getInstanceByUUID(string $moduleID, string $UUID) : int {

    //Get Instances
    $List = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($List as $id) {
      if (@IPS_GetProperty($id, "UUID") == $UUID) {
        return $id;
      }
    }

    return 0;

  }


  public function getInstanceByID(string $moduleID, int $ID) : int {

    //Get Instances
    $List = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($List as $id) {
      if (@IPS_GetProperty($id, "ID") == $ID) {
        return $id;
      }
    }

    return 0;

  }


}