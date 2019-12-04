<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


//Instance specific
define('TIMER_SYNC_LOCAL',     5);
define('TIMER_SYNC_LOCAL_MIN', 5);

//Cloud connection specific
define('LIGHITFY_INVALID_CREDENTIALS',    5001);
define('LIGHITFY_INVALID_SECURITY_TOKEN', 5003);
define('LIGHITFY_GATEWAY_OFFLINE',        5019);


class lightifyGateway extends IPSModule
{


  const GATEWAY_SERIAL_LENGTH = 11;

  const OAUTH_AUTHORIZE      = "https://oauth.ipmagic.de/authorize/";
  const OAUTH_FORWARD        = "https://oauth.ipmagic.de/forward/";
  const OAUTH_ACCESS_TOKEN   = "https://oauth.ipmagic.de/access_token/";
  const AUTHENTICATION_TYPE  = "Bearer";

  const RESOURCE_SESSION     = "/session";
  const LIGHTIFY_EUROPE      = "https://emea.lightify-api.com/";
  const LIGHTIFY_USA         = "https://na.lightify-api.com/";
  const LIGHTIFY_VERSION     = "v4/";

  const PROTOCOL_VERSION     =  1;
  const HEADER_AUTHORIZATION = "Authorization: Bearer ";
  const HEADER_FORM_CONTENT  = "Content-Type: application/x-www-form-urlencoded";
  const HEADER_JSON_CONTENT  = "Content-Type: application/json";

  const RESSOURCE_DEVICES    = "devices/";
  const RESSOURCE_GROUPS     = "groups/";
  const RESSOURCE_SCENES     = "scenes/";

  const LIGHTIFY_MAXREDIRS   = 10;
  const LIGHTIFY_TIMEOUT     = 30;

  protected $lightifyBase;
  protected $oAuthIdent = "osram_lightify";
  protected $message = false;

  protected $queue = [
    'flag' => false,
    'cmd'  => vtNoValue,
    'code' => vtNoValue
  ];

  use ParentInstance,
      InstanceStatus,
      WebOAuth;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  public function Create() {

    parent::Create();

    //Local gateway
    $this->RegisterPropertyInteger("connect", classConstant::CONNECT_LOCAL_ONLY);
    $this->RegisterPropertyString("serialNumber", vtNoString);
    
    $this->RegisterPropertyInteger("update", TIMER_SYNC_LOCAL);
    $this->RegisterTimer("timer", 0, "OSR_GetLightifyData($this->InstanceID, 1203);");

    //Cloud Access Token
    $this->RegisterAttributeString("osramToken", vtNoString);

    //Global settings
    $this->RegisterPropertyInteger("debug", classConstant::DEBUG_DISABLED);
    $this->SetBuffer("sendStatus", json_encode($this->queue));

    $this->RegisterAttributeString("deviceBuffer", vtNoString);
    $this->RegisterAttributeString("cloudDevices", vtNoString);
    $this->RegisterAttributeString("groupBuffer", vtNoString);
    $this->RegisterAttributeString("cloudGroups", vtNoString);
    $this->RegisterAttributeString("groupDevices", vtNoString);
    $this->RegisterAttributeString("sceneBuffer", vtNoString);
    $this->RegisterAttributeString("cloudScenes", vtNoString);

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
    }

    if (IPS_VariableProfileExists("OSR.Switch")) {
      IPS_SetVariableProfileAssociation("OSR.Switch", true, $this->Translate("On"), vtNoString, 0xFF9200);
      IPS_SetVariableProfileAssociation("OSR.Switch", false, $this->Translate("Off"), vtNoString, vtNoValue);
    }

    if (!IPS_VariableProfileExists("OSR.Scene")) {
      IPS_CreateVariableProfile("OSR.Scene", vtInteger);
      IPS_SetVariableProfileIcon("OSR.Scene", "Power");
      IPS_SetVariableProfileDigits("OSR.Scene", 0);
      IPS_SetVariableProfileValues("OSR.Scene", 1, 1, 0);
    }

    if (IPS_VariableProfileExists("OSR.Scene")) {
      IPS_SetVariableProfileAssociation("OSR.Scene", 1, $this->Translate("Activate"), vtNoString, 0xFF9200);
    }

    $this->ConnectParent(classConstant::CLIENT_SOCKET);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    //Never delete this line!
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    if (!$this->HasActiveParent()) {
      return;
    }

    //Buffer queue
    $this->SetBuffer("sendStatus", json_encode($this->queue));

    if ($this->ReadPropertyInteger("connect") == classConstant::CONNECT_LOCAL_CLOUD) {
      $this->RegisterOAuth($this->oAuthIdent);
    }

    //Execute
    $this->GetLightifyData(classConstant::METHOD_APPLY_CONFIG);
    $this->SetTimerInterval("timer", $this->ReadPropertyInteger("update")*1000);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;
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


  public function ForwardData($JSONString) {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    //Decode data
    $data   = json_decode($JSONString);
    $method = $data->method;

    switch ($method) {
      case classConstant::GET_DEVICES_LOCAL:
        return $this->ReadAttributeString("deviceBuffer");

      case classConstant::GET_DEVICES_CLOUD:
        return $this->ReadAttributeString("cloudDevices");

      case classConstant::GET_GROUPS_LOCAL:
        return $this->ReadAttributeString("groupBuffer");

      case classConstant::GET_GROUPS_CLOUD:
        return $this->ReadAttributeString("cloudGroups");

      case classConstant::GET_GROUP_DEVICES:
        return $this->ReadAttributeString("groupDevices");

      case classConstant::GET_SCENES_LOCAL:
        return $this->ReadAttributeString("sceneBuffer");

      case classConstant::GET_SCENES_CLOUD:
        return $this->ReadAttributeString("cloudScenes");
    }

    switch ($method) {
      case classConstant::SET_ALL_DEVICES:
      case classConstant::SET_GROUP_STATE:
        $cmd = classCommand::SET_DEVICE_STATE;
        break;

      case classCommand::ADD_DEVICE_TO_GROUP:
      case classCommand::RENOVE_DEVICE_FROM_GROUP:
      case classCommand::SET_DEVICE_STATE:
      case classCommand::SAVE_LIGHT_STATE:
      case classCommand::ACTIVATE_GROUP_SCENE:
      case classCommand::SET_LIGHT_COLOR:
      case classCommand::SET_COLOR_TEMPERATURE:
      case classCommand::SET_LIGHT_LEVEL:
      case classConstant::SET_LIGHT_SATURATION:
      case classCommand::SET_DEVICE_NAME:
      case classCommand::SET_GROUP_NAME:
      case classCommand::SET_LIGHT_SOFT_ON:
      case classCommand::SET_LIGHT_SOFT_OFF:
        $cmd = $method;
        break;

      default:
        return vtNoString;
    }

    //Send data
    $buffer = json_decode($data->buffer);
    $status = json_encode($this->queue);

    //State functions
    if ($this->sendRaw($cmd, chr($buffer->flag), utf8_decode($buffer->args))) {
      //Wait for function call
      if (!empty($data)) {
        $status = $this->waitReceive();

        //Sync groups
        if ($method == classCommand::SET_GROUP_NAME) {
          $this->sendRaw(classCommand::GET_GROUP_LIST, chr(0x00));
          return $this->waitReceive();
        }

        //Sync devixes
        $this->sendRaw(classCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01));

        if ($method == classCommand::ADD_DEVICE_TO_GROUP || $method == classCommand::RENOVE_DEVICE_FROM_GROUP) {
          return $this->waitReceive();
        }
      }
    }

    return $status;

  }


  public function ReceiveData($JSONString) {

    $connect = $this->ReadPropertyInteger("connect");
    $this->debug = $this->ReadPropertyInteger("debug");
    //IPS_LogMessage("SymconOSR", "<Gateway|Receive:data>   ".$this->lightifyBase->decodeData($JSONString));

    $data = json_decode($JSONString);
    $data = utf8_decode($data->Buffer);
    //IPS_LogMessage("SymconOSR", "<Gateway|Receive:data>   ".strlen($data)."|".$this->lightifyBase->decodeData(substr($data, classConstant::BUFFER_HEADER_LENGTH + 1))."|".$this->lightifyBase->decodeData($data));

    $cmd  = ord($data{3});
    $code = ord($data{8});
    $data = substr($data, classConstant::BUFFER_HEADER_LENGTH + 1);

    //if ($code == 0 && strlen($data) >= classConstant::BUFFER_REPLY_LENGTH) {
    if ($code == 0) {
      $status = [
        'flag' => true,
        'cmd'  => $cmd,
        'code' => $code
      ];
      $this->SetBuffer("sendStatus", json_encode($status));

      switch ($cmd) {
        //Gateway WiFi configuration
        case classCommand::GET_GATEWAY_WIFI:
          $this->getGatewayWiFi($data);

          //Get gateway firmware version
          $this->sendRaw(classCommand::GET_GATEWAY_FIRMWARE, chr(0x00));
          break;

        //Gateway firmware version
        case classCommand::GET_GATEWAY_FIRMWARE:
          $this->getGatewayFirmware($data);
          break;

        //Gateway devices
        case classCommand::GET_DEVICE_LIST:
          //Update device informations
          $result = $this->getGatewayDevices($connect, $data);
          $this->WriteAttributeString("deviceBuffer", $result);
          $Devices = json_decode($result);

          if (!empty($Devices)) {
            $this->SendDataToChildren(json_encode([
              'DataID' => classConstant::TX_DEVICE,
              'id'     => $this->InstanceID,
              'buffer' => $Devices])
            );
          }

          //Get gateway groups
          $this->sendRaw(classCommand::GET_GROUP_LIST, chr(0x00));
          break;

        //Gateway groups
        case classCommand::GET_GROUP_LIST:
          //Update group informations
          $result = $this->getGatewayGroups($connect, $data);
          $this->WriteAttributeString("groupDevices", $result);
          $List = json_decode($result);

          if (!empty($List)) {
            $this->SendDataToChildren(json_encode([
              'DataID' => classConstant::TX_GROUP,
              'id'     => $this->InstanceID,
              'buffer' => $List])
            );
          }

          //Get gateway scenes
          $this->sendRaw(classCommand::GET_SCENE_LIST, chr(0x00));
          break;

        case classCommand::GET_SCENE_LIST:
          //Get gateway scenes
          $result = $this->getGatewayScenes($connect, $data);
          $this->WriteAttributeString("sceneBuffer", $result);
          $Scenes = json_decode($result);

          if (!empty($Scenes)) {
            $this->SendDataToChildren(json_encode([
              'DataID' => classConstant::TX_SCENE,
              'id'     => $this->InstanceID,
              'buffer' => $Scenes])
            );
          }
          break;
      }
    } else {
      $this->SendDebug("<Gateway|Receive data:error>", $code, 0);
      IPS_LogMessage("SymconOSR", "<Gateway|Receive data:error>   ".$cmd."|".$code."|".strlen($data));
    }

  }


  public function LightifyRegister() {

    //Return everything which will open the browser
    if ($this->ReadPropertyInteger("connect") == classConstant::CONNECT_LOCAL_CLOUD) {
      if (strlen($this->ReadPropertyString("serialNumber")) == self::GATEWAY_SERIAL_LENGTH) {
        self::OAUTH_AUTHORIZE.$this->oAuthIdent."?username=".urlencode(IPS_GetLicensee());
      } else {
        echo $this->Translate("Lightify Gateway serial must have 11 digits!")."\n";
      }

      return;
    }

    echo $this->Translate("Lightify API registration available in cloud connection mode only!")."\n";
  }


  protected function ProcessOAuthData() {

    if ($_SERVER['REQUEST_METHOD'] == "GET") {
      if (isset($_GET['code'])) {
        return $this->getAccessToken($_GET['code']);
      } else {
        $error = $this->Translate("Authorization code expected!");
        $this->SendDebug("<Gateway|Process OAuth data:error>", $error, 0);
      }
    }

    return false;

  }


  private function sendRaw(int $cmd, string $flag, string $args = vtNoString): bool {

    if (!$this->HasActiveParent()) {
      return false;
    }

    //$this->requestID = ($this->requestID == classConstant::REQUESTID_HIGH) ? 1 : $this->requestID+1;
    //$data = $flag.chr($command).$this->lightifyBase->getRequestID($this->requestID);
    $this->SetBuffer("sendStatus", json_encode($this->queue));

    $debug  = $this->ReadPropertyInteger("debug");
    $buffer = $flag.chr($cmd).chr(0x00).chr(0x00).chr(0x00).chr(0x00);

    if (!empty($args)) {
      $buffer .= $args;
    }

    $buffer = utf8_encode(chr(strlen($buffer)).chr(0x00).$buffer);
    $length = strlen($buffer);

    if ($debug % 4) {
      $info = strtoupper(dechex($cmd))."|".hexdec($flag)."|".$length."|".$this->lightifyBase->decodeData($buffer);
      $this->SendDebug("<Gateway|Send request:write>", $info, 0);
    }

    $data = json_encode([
      'DataID' => classConstant::TX_VIRTUAL,
      'Buffer' => $buffer]
    );

    $this->SendDataToParent($data);
    return true;

  }


  public function GetLightifyData(int $method) : void {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $firmwareID = @$this->GetIDForIdent("FIRMWARE");
    $portID = @$this->GetIDForIdent("PORT");
    $ssidID = @$this->GetIDForIdent("SSID");

    if ($method == classConstant::METHOD_APPLY_CONFIG) {
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

      if ($ssidID) {
        $this->sendRaw(classCommand::GET_GATEWAY_WIFI, chr(classConstant::SCAN_WIFI_CONFIG));
        return;
      }
    } else {
      //Get paired devices
      $this->sendRaw(classCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01));
      return;
    }

  }


  private function getAccessToken(string $code) : bool {

    $debug = $this->ReadPropertyInteger("debug");
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
      $this->SendDebug("<Gateway|Get access token:result>", $result, 0);
    }

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      $this->SetBuffer("applyMode", 0);

      if ($debug % 2) {
        $this->SendDebug("<Gateway|Get access token:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|Get access token:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|Get access token:refresh>", $data->refresh_token, 0);
      }

      $buffer = [
        'access_token'  => $data->access_token,
        'expires_in'    => time()+$data->expires_in,
        'refresh_token' => $data->refresh_token
      ];

      $this->WriteAttributeString("osramToken", json_encode($buffer));
      return true;
    }

    $this->SendDebug("<Gateway|Get access token:error>", $result, 0);
    return false;

  }


  private function getRefreshToken() : string {

    $osramToken = $this->ReadAttributeString("osramToken");

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|Get refresh token:token>", $osramToken, 0);
    }

    //Exchange our refresh token for a temporary access token
    $data = json_decode($osramToken);

    if (!empty($data) && time() < $data->expires_in) {
      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|Get refresh token:access>", $data->access_token, 0);
        //$this->SendDebug("<Gateway|Get refresh token:expires>", date("Y-m-d H:i:s", (int)time()+$data->expires_in), 0);
        $this->SendDebug("<Gateway|Get refresh token:refresh>", $data->refresh_token, 0);
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
      $this->SendDebug("<Gateway|Get refresh token:result>", $result, 0);
    }

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      //Update parameters to properly cache them in the next step
      $this->SetBuffer("applyMode", 0);

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|Get refresh token:access>", $data->access_token, 0);
        $this->SendDebug("<Gateway|Get refresh token:expires>", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
        $this->SendDebug("<Gateway|Get refresh token:refresh>", $data->refresh_token, 0);
      }

      $buffer = json_encode([
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token]
      );

      $this->WriteAttributeString("osramToken", $buffer);
      return $data->access_token;
    } else {
      $this->SendDebug("<Gateway|Get refresh token:error>", $result, 0);
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
      $this->SendDebug("<Gateway|Cloud request:error>", $error, 0);
      return vtNoString;
    }

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|Cloud request:result>", $result, 0);
    }

    return $result;

  }


  private function getGatewayWiFi(string $data) : void {

    if (strlen($data) >= (2+classConstant::DATA_WIFI_LENGTH)) {

      if (!empty($gatewayIP = $this->getGatewayIP())) {
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

          if ($gatewayIP == $ip) {
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
    }

  }


  private function getGatewayFirmware(string $data) : void {

    if (@$this->GetIDForIdent("FIRMWARE")) {
      $firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});
      //IPS_LogMessage("SymconOSR", "<Gateway|ReceiveData:$firmware>   ".$firmware);

      if ($this->GetValue("FIRMWARE") != $firmware) {
        $this->SetValue("FIRMWARE", (string)$firmware);
      }
    }

  }


  private function getGatewayDevices(int $connect, string $data) : string {

    if (strlen($data) >= (2 + classConstant::DATA_DEVICE_LENGTH)) {
      $Devices  = [];
      $Groups   = [];
      $allState = 0;

      $ncount = ord($data{0})+ord($data{1});
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $type = ord($data{10});

        $zigBee = dechex(ord($data{0})).dechex(ord($data{1}));
        $UUID   = $this->lightifyBase->chrToUUID(substr($data, 2, classConstant::UUID_DEVICE_LENGTH));

        $name = trim(substr($data, 26, classConstant::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";
        $firmware = sprintf("%02X%02X%02X%02X", ord($data{11}), ord($data{12}), ord($data{13}), ord($data{14}));

        $online = (ord($data{15}) == classConstant::STATE_ONLINE) ? 1 : 0; //Online: 2 - Offline: 0 - Unknown: 1
        $state  = ($online == 1) ? ord($data{18}) : 0;

        if ($state == 1 && $allState == 0) {
          $allState = 1;
        }

        $level = ord($data{19});
        $cct   = hexdec(dechex(ord($data{21})).dechex(ord($data{20})));
        $white = ord($data{25});

        $rgb   = [
          'r' => ord($data{22}),
          'g' => ord($data{23}),
          'b' => ord($data{24})
        ];

        $Devices[] = [
          'id'       => $i,
          'type'     => $type,
          'zigBee'   => $zigBee,
          'UUID'     => $UUID,
          'name'     => $name,
          'firmware' => $firmware,
          'online'   => $online,
          'state'    => $state,
          'level'    => $level,
          'cct'      => $cct,
          'rgb'      => $rgb,
          'white'    => $white
        ];

        //Decode device class
        $decode = $this->lightifyBase->decodeGroup(ord($data{16}), ord($data{17}));

        switch ($type) {
          case classConstant::TYPE_SENSOR_MOTION:
          case classConstant::TYPE_DIMMER_2WAY:
          case classConstant::TYPE_SWITCH_4WAY:
          case classConstant::TYPE_SWITCH_MINI:
            break;

          default:
            $Groups[] = [
              'UUID' => $UUID,
              'id'   => $decode
            ];
        }

        if (($length = strlen($data)) > classConstant::DATA_DEVICE_LOADED) {
          $length = classConstant::DATA_DEVICE_LOADED;
        }

        $data = substr($data, $length);
      }

      //Store
      $this->SetBuffer("deviceGroups", json_encode($Groups));

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|Gateway devices:data>", json_encode($Devices), 0);
      }

      if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudDevices = $this->cloudGET(self::RESSOURCE_DEVICES);
        $this->WriteAttributeString("cloudDevices", $cloudDevices);

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|Cloud devices:data>", $cloudDevices, 0);
        }
      }

      if (!empty($Devices)) {
        array_unshift($Devices, [
          'id'       => 0,
          'type'     => classConstant::TYPE_ALL_DEVICES,
          'zigBee'   => vtNoString,
          'UUID'     => $this->lightifyBase->chrToUUID(chr(0xff).chr(0x00).chr(0xff).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84)),
          'name'     => "All Devices",
          'firmware' => vtNoString,
          'online'   => vtNoString,
          'state'    => $allState,
          'level'    => vtNoString,
          'cct'      => vtNoString,
          'rgb'      => vtNoString,
          'white'    => vtNoString
        ]);
      }

      return json_encode($Devices);
    }

    return vtNoString;

  }


  private function getGatewayGroups(int $connect, string $data) : string {

    if (strlen($data) >= (2 + classConstant::DATA_GROUP_LENGTH)) {
      $buffer = $this->GetBuffer("deviceGroups");

      if (!empty($buffer)) {
        $buffer = json_decode($buffer);
      }

      $Groups = [];
      $List   = [];

      $ncount = ord($data{0})+ord($data{1});
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $UUID = $this->lightifyBase->chrToUUID($data{0}.$data{1}.chr(classConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84));

        $name = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";

        $Groups[] = [
          'id'   => ord($data{0}),
          'type' => classConstant::TYPE_DEVICE_GROUP,
          'UUID' => $UUID,
          'name' => $name
        ];

        //Get Group devices
        if (!empty($buffer)) {
          $Items = $buffer;

          $id = ord($data{0});
          $Devices = [];

          foreach ($Items as $group) {
            foreach ($group->id as $index) {
              if ($id == $index) {
                $Devices[] = $group->UUID;
                break;
              }
            }
          }

/*
          //Add All Lights
          if (!empty($Devices)) {
            array_unshift($Devices, $this->lightifyBase->chrToUUID(chr(0xff).chr(0x00).chr(0xff).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84)));
          }
*/
          $List[] = [
            'Group'   => $UUID,
            'Devices' => $Devices
          ];
        }

        if (($length = strlen($data)) > classConstant::DATA_GROUP_LENGTH) {
          $length = classConstant::DATA_GROUP_LENGTH;
        }

        $data = substr($data, $length);
      }

      //Store
      $this->WriteAttributeString("groupBuffer", json_encode($Groups));

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|Gateway groups:data>", json_encode($Groups), 0);
      }

      if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudGroups = $this->cloudGET(self::RESSOURCE_GROUPS);
        $this->WriteAttributeString("cloudGroups", $cloudGroups);

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|Cloud groups:data>", $cloudGroups, 0);
        }
      }

      //IPS_LogMessage("SymconOSR", "<<Gateway|Get groups:list>   ".json_encode($List));
      return json_encode($List);
    }

    return vtNoString;

  }


  private function getGatewayScenes(int $connect, string $data) : string {

    if (strlen($data) >= (2 + classConstant::DATA_DEVICE_LENGTH)) {
      $Scenes = [];
      $ncount = ord($data{0})+ord($data{1});
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $UUID  = $this->lightifyBase->chrToUUID($data{0}.chr(0x00).chr(classConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84));

        $name = trim(substr($data, 2, classConstant::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";

        $Scenes[] = [
          'id'   => ord($data{0}),
          'type' => classConstant::TYPE_GROUP_SCENE,
          'UUID' => $UUID,
          'name' => $name
        ];

        if (($length = strlen($data)) > classConstant::DATA_SCENE_LENGTH) {
          $length = classConstant::DATA_SCENE_LENGTH;
        }

        $data = substr($data, $length);
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Gateway|Gateway scenes:data>", json_encode($Scenes), 0);
      }

      if ($connect == classConstant::CONNECT_LOCAL_CLOUD && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudScenes = $this->cloudGET(self::RESSOURCE_SCENES);
        $this->WriteAttributeString("cloudScenes", $cloudScenes);

        if ($this->debug % 2) {
          $this->SendDebug("<Gateway|Cloud scenes:data>", $cloudScenes, 0);
        }
      }

      return json_encode($Scenes);
    }

    return vtNoString;

  }


  private function getGatewayIP() : string {

    if (0 < ($parentID = $this->getParentInstance($this->InstanceID))) {
      return IPS_GetProperty($parentID, "Host");
    }

    return vtNoString;

  }


  private function getAllDevices() : void {

    $Instances  = IPS_GetInstanceListByModuleID(classConstant::MODULE_DEVICE);
    $allDevices = [];

    foreach($Instances as $id) {
      $itemclass = IPS_GetProperty($id, "itemClass");

      if ($itemclass == "Light" || $itemclass == "Plug") {
        $allDevices [] = [IPS_GetProperty($id, "UUID")];
      }
    }

    //Store
    $this->WriteAttributeString("allDevices", json_encode($allDevices));

    if ($this->debug % 2) {
      $this->SendDebug("<Gateway|Gateway allDevices:data>", json_encode($allDevices), 0);
    }
  }


  private function waitReceive() : string {
    for ($x = 0; $x < 500; $x++) {
      $status = $this->GetBuffer("sendStatus");
      $decode = json_decode($status);

      if ($decode->flag) {
        $this->SetBuffer("sendStatus", json_encode($this->queue));
        break;
      }

      IPS_Sleep(10);
    }

    return $status;

  }

}