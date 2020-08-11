<?php

//https://oauth.ipmagic.de/authorize/osram_lightify?username=xconsult@me.com

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';

//Cloud connection specific
define('LIGHITFY_INVALID_CREDENTIALS',    5001);
define('LIGHITFY_INVALID_SECURITY_TOKEN', 5003);
define('LIGHITFY_GATEWAY_OFFLINE',        5019);


class LightifyGateway extends IPSModule {

  private const OAUTH_AUTHORIZE      = "https://oauth.ipmagic.de/authorize/";
  private const OAUTH_FORWARD        = "https://oauth.ipmagic.de/forward/";
  private const OAUTH_ACCESS_TOKEN   = "https://oauth.ipmagic.de/access_token/";
  private const AUTHENTICATION_TYPE  = "Bearer";

  private const RESOURCE_SESSION     = "/session";
  private const LIGHTIFY_EUROPE      = "https://emea.lightify-api.com/";
  private const LIGHTIFY_USA         = "https://na.lightify-api.com/";
  private const LIGHTIFY_VERSION     = "v4/";

  private const PROTOCOL_VERSION     =  1;
  private const HEADER_AUTHORIZATION = "Authorization: Bearer ";
  private const HEADER_FORM_CONTENT  = "Content-Type: application/x-www-form-urlencoded";
  private const HEADER_JSON_CONTENT  = "Content-Type: application/json";

  private const RESSOURCE_DEVICES    = "devices/";
  private const RESSOURCE_GROUPS     = "groups/";
  private const RESSOURCE_SCENES     = "scenes/";

  private const LIGHTIFY_MAXREDIRS   = 10;
  private const LIGHTIFY_TIMEOUT     = 30;

  private const HUE_MIN              = 0;
  private const HUE_MAX              = 360;
  private const COLOR_MIN            = "0000ff";
  private const COLOR_MAX            = "ffffff";
  private const LEVEL_MIN            = 0;
  private const LEVEL_MAX            = 100;
  private const SATURATION_MIN       = 0;
  private const SATURATION_MAX       = 100;
  private const INTENSITY_MIN        = 0;
  private const INTENSITY_MAX        = 100;

  private const METHOD_APPLY_CONFIG  = "apply:config";
  private const METHOD_LOAD_LOCAL    = "load:local";

  private const BUFFER_HEADER_LENGTH = 8;
  private const BUFFER_REPLY_LENGTH  = 11;

  private const DATA_DEVICE_LOADED   = 50;
  private const DATA_DEVICE_LENGTH   = 41;
  private const DATA_GROUP_LENGTH    = 18;
  private const DATA_SCENE_LENGTH    = 20;
  private const DATA_WIFI_LENGTH     = 97;

  private const GET_WIFI_CONFIG      = 0x00;
  private const SET_WIFI_CONFIG      = 0x01;
  private const SCAN_WIFI_CONFIG     = 0x03;

  private const WIFI_PROFILE_LENGTH  = 31;
  private const WIFI_SSID_LENGTH     = 32;
  private const WIFI_BSSID_LENGTH    = 5;
  private const WIFI_CHANNEL_LENGTH  = 3;

  private const REQUESTID_HIGH       = 4294967295;

  protected $lightifyBase;
  protected $oAuthIdent = "osram_lightify";
  protected $message = false;

  protected $sendResult = [
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
    $this->RegisterPropertyBoolean("cloudAPI", false);
    $this->RegisterPropertyString("serialNumber", vtNoString);

    $this->RegisterPropertyInteger("update", Constants::TIMER_UPDATE);
    $this->RegisterTimer("update", Constants::TIMER_UPDATE*1000, "OSR_GetLightifyData($this->InstanceID, 'load:local');");

    //Cloud Access Token
    $this->RegisterAttributeString("osramToken", vtNoString);

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
      IPS_SetVariableProfileValues("OSR.Hue", self::HUE_MIN, self::HUE_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.ColorTemp")) {
      IPS_CreateVariableProfile("OSR.ColorTemp", vtInteger);
      IPS_SetVariableProfileIcon("OSR.ColorTemp", "Flame");
      IPS_SetVariableProfileDigits("OSR.ColorTemp", 0);
      IPS_SetVariableProfileText("OSR.ColorTemp", vtNoString, "K");
      IPS_SetVariableProfileValues("OSR.ColorTemp", self::CTEMP_CCT_MIN, self::CTEMP_CCT_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.ColorTempExt")) {
      IPS_CreateVariableProfile("OSR.ColorTempExt", vtInteger);
      IPS_SetVariableProfileIcon("OSR.ColorTempExt", "Flame");
      IPS_SetVariableProfileDigits("OSR.ColorTempExt", 0);
      IPS_SetVariableProfileText("OSR.ColorTempExt", vtNoString, "K");
      IPS_SetVariableProfileValues("OSR.ColorTempExt", self::CTEMP_COLOR_MIN, self::CTEMP_COLOR_MAX, 1);
    }

    if (!IPS_VariableProfileExists("OSR.Intensity")) {
      IPS_CreateVariableProfile("OSR.Intensity", vtInteger);
      IPS_SetVariableProfileDigits("OSR.Intensity", 0);
      IPS_SetVariableProfileText("OSR.Intensity", vtNoString, "%");
      IPS_SetVariableProfileValues("OSR.Intensity", self::INTENSITY_MIN, self::INTENSITY_MAX, 1);
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

    $this->ConnectParent(Constants::CLIENT_SOCKET);

  }


  public function ApplyChanges() {

    //Never delete this line!
    parent::ApplyChanges();

    if (0 < ($parentID = @$this->getParentInstance($this->InstanceID))) {
      $this->RegisterMessage($parentID, IM_CHANGESTATUS);
      $this->RegisterMessage($parentID, IM_CHANGESETTINGS);
    }
    $this->RegisterMessage(0, IPS_KERNELSTARTED);

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    //Buffer queue
    $this->SetBuffer("sendStatus", json_encode($this->sendResult));

    if ($this->ReadPropertyBoolean("cloudAPI")) {
      $this->RegisterOAuth($this->oAuthIdent);
    }

    //Execute
    $this->GetLightifyData(self::METHOD_APPLY_CONFIG);
    $this->SetTimerInterval("update", $this->ReadPropertyInteger("update")*1000);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;

      case IM_CHANGESETTINGS:
        $config = json_decode($Data[1]);
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $Message."|".(int)$config->Open);
        //$this->UpdateFormField("open", "enabled", (bool)$config->Open);
        break;
    }

  }


  public function GetConfigurationForm() {

    //Load form
    $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
    $formJSON['elements'][0]['items'][0]['objectID'] = IPS_GetInstance($this->InstanceID)['ConnectionID'];

    if ($this->ReadPropertyBoolean("cloudAPI") && strlen($this->ReadPropertyString("serialNumber")) == Constants::GATEWAY_SERIAL_LENGTH) {
      $formJSON['actions'][0]['enabled'] = true;
    } else {
      $formJSON['actions'][0]['enabled'] = false;
    }

    return json_encode($formJSON);

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
      case Constants::GET_DEVICES_LOCAL:
        return $this->ReadAttributeString("deviceBuffer");

      case Constants::GET_DEVICES_CLOUD:
        return $this->ReadAttributeString("cloudDevices");

      case Constants::GET_GROUPS_LOCAL:
        return $this->ReadAttributeString("groupBuffer");

      case Constants::GET_GROUPS_CLOUD:
        return $this->ReadAttributeString("cloudGroups");

      case Constants::GET_SCENES_LOCAL:
        return $this->ReadAttributeString("sceneBuffer");

      case Constants::GET_SCENES_CLOUD:
        return $this->ReadAttributeString("cloudScenes");

      case Constants::GET_BUFFER_DEVICES:
        if ($data->uID != vtNoValue) {
          return $this->GetBuffer("Device-".$data->uID);
        }
        return $this->getBufferDevices();

      case Constants::GET_BUFFER_GROUPS:
        if ($data->uID != vtNoValue) {
          return $this->GetBuffer("Group-".$data->uID);
        }
        return $this->getBufferGroups();

      case Constants::GET_BUFFER_SCENES:
        if ($data->uID != vtNoValue) {
          return $this->GetBuffer("Scene-".$data->uID);
        }
        return $this->getBufferScenes();
    }

    switch ($method) {
      case Constants::SET_ALL_DEVICES:
      case Constants::SET_GROUP_STATE:
        $cmd = Commands::SET_DEVICE_STATE;
        break;

      case Commands::ADD_DEVICE_TO_GROUP:
      case Commands::RENOVE_DEVICE_FROM_GROUP:
      case Commands::SET_DEVICE_STATE:
      case Commands::SAVE_LIGHT_STATE:
      case Commands::ACTIVATE_GROUP_SCENE:
      case Commands::SET_LIGHT_COLOR:
      case Commands::SET_COLOR_TEMPERATURE:
      case Commands::SET_LIGHT_LEVEL:
      case Constants::SET_LIGHT_SATURATION:
      case Commands::SET_GROUP_NAME:
      case Commands::SET_DEVICE_NAME:
      case Commands::SET_LIGHT_SOFT_ON:
      case Commands::SET_LIGHT_SOFT_OFF:
        $cmd = $method;
        break;

      default:
        return vtNoString;
    }

    //Disable timer
    $this->SetTimerInterval("update", vtNoValue);

    $buffer = json_decode($data->buffer);
    $status = json_encode($this->sendResult);

    //Send data
    if ($this->sendRaw($cmd, chr($buffer->flag), utf8_decode($buffer->args))) {
      $status = $this->waitReceive();

      //Sync devices
      if ($this->sendRaw(Commands::GET_DEVICE_LIST, chr(0x00), chr(0x01))) {
        $status = $this->waitReceive();
      }

      //Sync groups
      if ($method == Commands::ADD_DEVICE_TO_GROUP || $method == Commands::RENOVE_DEVICE_FROM_GROUP || $method == Commands::SET_GROUP_NAME) {
        if ($this->sendRaw(Commands::GET_GROUP_LIST, chr(0x00))) {
          $status = $this->waitReceive();
        }
      }
    }

    $this->SetTimerInterval("update", $this->ReadPropertyInteger("update")*1000);
    return $status;

  }


  public function ReceiveData($JSONString) {

    $cloudAPI = $this->ReadPropertyBoolean("cloudAPI");
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $this->lightifyBase->decodeData($JSONString));

    $data = json_decode($JSONString);
    $data = utf8_decode($data->Buffer);
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", strlen($data)."|".$this->lightifyBase->decodeData(substr($data, self::BUFFER_HEADER_LENGTH + 1))."|".$this->lightifyBase->decodeData($data));

    $cmd  = ord($data[3]);
    $code = ord($data[8]);
    $data = substr($data, self::BUFFER_HEADER_LENGTH + 1);

    //if ($code == 0 && strlen($data) >= self::BUFFER_REPLY_LENGTH) {
    if ($code == 0) {
      $status = [
        'flag' => true,
        'cmd'  => $cmd,
        'code' => $code
      ];
      $this->SetBuffer("sendStatus", json_encode($status));

      switch ($cmd) {
        //Gateway WiFi configuration
        case Commands::GET_GATEWAY_WIFI:
          $this->getGatewayWiFi($data);
          break;

        //Gateway firmware version
        case Commands::GET_GATEWAY_FIRMWARE:
          $this->getGatewayFirmware($data);
          break;

        //Gateway devices
        case Commands::GET_DEVICE_LIST:
          //Update device informations
          $Devices = $this->getGatewayDevices($cloudAPI, $data);
          $this->WriteAttributeString("deviceBuffer", json_encode($Devices));

          foreach ($this->getBufferDevices() as $line) {
            $device = json_decode($this->GetBuffer($line), true);

            $this->SendDataToChildren(json_encode([
              'DataID' => Constants::TX_DEVICE,
              'id'     => $this->InstanceID,
              'uID'    => "--".$device['UUID']."--",
              'buffer' => $device])
            );
          }
          break;

        //Gateway groups
        case Commands::GET_GROUP_LIST:
          //Update group informations
          $Groups = $this->getGatewayGroups($cloudAPI, $data);
          $this->WriteAttributeString("groupBuffer", json_encode($Groups));

          foreach ($this->getBufferGroups() as $line) {
            $group = json_decode($this->GetBuffer($line), true);

            $this->SendDataToChildren(json_encode([
              'DataID' => Constants::TX_GROUP,
              'id'     => $this->InstanceID,
              'uID'    => "--".(string)$group['ID']."--",
              'buffer' => $group])
            );
          }
          break;

        case Commands::GET_SCENE_LIST:
          //Get gateway scenes
          $Scenes = $this->getGatewayScenes($cloudAPI, $data);
          $this->WriteAttributeString("sceneBuffer", json_encode($Scenes));

          foreach ($this->getBufferScenes() as $line) {
            $scene = json_decode($this->GetBuffer($line), true);

            $this->SendDataToChildren(json_encode([
              'DataID' => Constants::TX_SCENE,
              'id'     => $this->InstanceID,
              'uID'    => "--".(string)$scene['ID']."--",
              'buffer' => $scene])
            );
          }
          break;
      }
    } else {
      $this->SendDebug("<".__FUNCTION__.">", $code, 0);
      IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $cmd."|".$code."|".strlen($data));
    }

  }


  public function LightifyRegister() {

    //Return everything which will open the browser
    if ($this->ReadPropertyBoolean("cloudAPI")) {
      if (strlen($this->ReadPropertyString("serialNumber")) == Constants::GATEWAY_SERIAL_LENGTH) {
        //echo urlencode(IPS_GetLicensee())."\n";

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
        $this->SendDebug("<".__FUNCTION__.">", $this->Translate("Authorization code expected!"), 0);
      }
    }

    return false;

  }


  private function sendRaw(int $cmd, string $flag, string $args = vtNoString) : bool {

    if (!$this->HasActiveParent()) {
      return false;
    }

    //$this->requestID = ($this->requestID == Constants::REQUESTID_HIGH) ? 1 : $this->requestID+1;
    //$data = $flag.chr($command).$this->lightifyBase->getRequestID($this->requestID);

    $this->SetBuffer("sendStatus", json_encode($this->sendResult));
    $buffer = $flag.chr($cmd).chr(0x00).chr(0x00).chr(0x00).chr(0x00);

    if (!empty($args)) {
      $buffer .= $args;
    }

    $buffer = utf8_encode(chr(strlen($buffer)).chr(0x00).$buffer);
    $length = strlen($buffer);

    //Debug info
    //$info = strtoupper(dechex($cmd))."|".hexdec($flag)."|".$length."|".$this->lightifyBase->decodeData($buffer);
    $info = "";
    $this->SendDebug("<".__FUNCTION__.">", $info, 0);

    $data = json_encode([
      'DataID' => Constants::TX_VIRTUAL,
      'Buffer' => $buffer]
    );

    $this->SendDataToParent($data);
    return true;

  }


  public function GetLightifyData(string $method) : void {

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $firmwareID = @$this->GetIDForIdent("FIRMWARE");
    $portID = @$this->GetIDForIdent("PORT");
    $ssidID = @$this->GetIDForIdent("SSID");

    if ($method == self::METHOD_APPLY_CONFIG) {
      if (!$ssidID) {
        if (false !== ($ssidID = $this->RegisterVariableString("SSID", "SSID", vtNoString, 301))) {
          SetValueString($ssidID, vtNoString);
          IPS_SetDisabled($ssidID, true);
        }
      }

      if (!$portID) {
        if (false !== ($portID = $this->RegisterVariableInteger("PORT", "Port", vtNoString, 303))) {
          SetValueInteger($portID, Constants::GATEWAY_PORT);
          IPS_SetDisabled($portID, true);
        }
      }

      if (!$firmwareID) {
        if (false !== ($firmwareID = $this->RegisterVariableString("FIRMWARE", $this->Translate("Firmware"), vtNoString, 304))) {
          SetValueString($firmwareID, vtNoString);
          IPS_SetDisabled($firmwareID, true);
        }
      }
    }

    //Get gateway WiFi configuration
    if (empty(GetValueString($ssidID))) {
      if ($this->sendRaw(Commands::GET_GATEWAY_WIFI, chr(self::SCAN_WIFI_CONFIG))) {
        $this->waitReceive();
      }
    }

    //Get gateway firmware version
    if (empty(GetValueString($firmwareID))) {
      if ($this->sendRaw(Commands::GET_GATEWAY_FIRMWARE, chr(0x00))) {
        $this->waitReceive();
      }
    }

    //Get paired devices
    if ($this->sendRaw(Commands::GET_DEVICE_LIST, chr(0x00), chr(0x01))) {
      $this->waitReceive();
    }


    //Get gateway groups
    if ($this->sendRaw(Commands::GET_GROUP_LIST, chr(0x00))) {
      $this->waitReceive();
    }


    //Get gateway scenes
    if ($this->sendRaw(Commands::GET_SCENE_LIST, chr(0x00))) {
      $this->waitReceive();
    }

  }


  private function getAccessToken(string $code) : bool {

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $code);

    //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
    $cURL    = curl_init();
    $Options = [
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

    curl_setopt_array($cURL, $Options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    $data   = json_decode($result);
    curl_close($cURL);

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      $this->SendDebug("<".__FUNCTION__.">", $data->access_token, 0);
      $this->SendDebug("<".__FUNCTION__.">", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
      $this->SendDebug("<".__FUNCTION__.">", $data->refresh_token, 0);

      $buffer = [
        'access_token'  => $data->access_token,
        'expires_in'    => time()+$data->expires_in,
        'refresh_token' => $data->refresh_token
      ];

      $this->WriteAttributeString("osramToken", json_encode($buffer));
      return true;
    }

    $this->SendDebug("<".__FUNCTION__.">", $result, 0);
    return false;

  }


  private function getRefreshToken() : string {

    $osramToken = $this->ReadAttributeString("osramToken");
    $this->SendDebug("<".__FUNCTION__.">", $osramToken, 0);

    //Exchange our refresh token for a temporary access token
    $data = json_decode($osramToken);

    if (!empty($data) && time() < $data->expires_in) {
      $this->SendDebug("<".__FUNCTION__.">", $data->access_token, 0);
      //$this->SendDebug("<".__FUNCTION__.">", date("Y-m-d H:i:s", (int)time()+$data->expires_in), 0);
      $this->SendDebug("<".__FUNCTION__.">", $data->refresh_token, 0);

      return $data->access_token;
    }

    $cURL    = curl_init();
    $Options = [
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

    curl_setopt_array($cURL, $Options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    $data   = json_decode($result);
    curl_close($cURL);

    if (isset($data->token_type) && $data->token_type == self::AUTHENTICATION_TYPE) {
      //Update parameters to properly cache them in the next step
      $this->SendDebug("<".__FUNCTION__.">", $data->access_token, 0);
      $this->SendDebug("<".__FUNCTION__.">", date("Y-m-d H:i:s", time() + $data->expires_in), 0);
      $this->SendDebug("<".__FUNCTION__.">", $data->refresh_token, 0);

      $buffer = json_encode([
        'access_token'  => $data->access_token,
        'expires_in'    => time() + $data->expires_in,
        'refresh_token' => $data->refresh_token]
      );

      $this->WriteAttributeString("osramToken", $buffer);
      return $data->access_token;

    } else {
      $this->SendDebug("<".__FUNCTION__.">", $result, 0);
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
    $Options = [
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

    curl_setopt_array($cURL, $Options);
    $result = curl_exec($cURL);
    $error  = curl_error($cURL);
    curl_close($cURL);

    if (!$result || $error) {
      $this->SendDebug("<".__FUNCTION__.">", $error, 0);
      return vtNoString;
    }

    $this->SendDebug("<".__FUNCTION__.">", $result, 0);
    return $result;

  }


  private function getGatewayWiFi(string $data) : void {

    if (strlen($data) >= (2+self::DATA_WIFI_LENGTH)) {

      if (!empty($gatewayIP = $this->getGatewayIP())) {
        $ncount = ord($data[0]);
        $data   = substr($data, 1);
        $result = false;

        for ($i = 1; $i <= $ncount; $i++) {
          $profile = trim(substr($data, 0, self::WIFI_PROFILE_LENGTH-1));
          $SSID    = trim(substr($data, 32, self::WIFI_SSID_LENGTH));
          $BSSID   = trim(substr($data, 65, self::WIFI_BSSID_LENGTH));
          $channel = trim(substr($data, 71, self::WIFI_CHANNEL_LENGTH));

          $ip      = ord($data[77]).".".ord($data[78]).".".ord($data[79]).".".ord($data[80]);
          $gateway = ord($data[81]).".".ord($data[82]).".".ord($data[83]).".".ord($data[84]);
          $netmask = ord($data[85]).".".ord($data[86]).".".ord($data[87]).".".ord($data[88]);
          //$dns_1   = ord($data[89]).".".ord($data[90]).".".ord($data[91]).".".ord($data[92]);
          //$dns_2   = ord($data[93]).".".ord($data[94]).".".ord($data[95]).".".ord($data[96]);

          if ($gatewayIP == $ip) {
            $result = $SSID;
            break;
          }

          if (($length = strlen($data)) > self::DATA_WIFI_LENGTH) {
            $length = self::DATA_WIFI_LENGTH;
          }

          $data = substr($data, $length);
        }

        if ($result) {
          //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $result);

          if ($this->GetValue("SSID") != $SSID) {
            $this->SetValue("SSID", (string)$SSID);
          }
        }
      }
    }

  }


  private function getGatewayFirmware(string $data) : void {

    if (@$this->GetIDForIdent("FIRMWARE")) {
      $firmware = ord($data[0]).".".ord($data[1]).".".ord($data[2]).".".ord($data[3]);
      //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $firmware);

      if ($this->GetValue("FIRMWARE") != $firmware) {
        $this->SetValue("FIRMWARE", (string)$firmware);
      }
    }

  }


  private function getGatewayDevices(bool $cloudAPI, string $data) : array {

    if (strlen($data) >= (2 + self::DATA_DEVICE_LENGTH)) {
      $Devices  = [];
      $allState = 0;

      $ncount = ord($data[0])+ord($data[1]);
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $type = ord($data[10]);

        $zigBee = dechex(ord($data[0])).dechex(ord($data[1]));
        $UUID   = $this->lightifyBase->chrToUUID(substr($data, 2, Constants::UUID_OSRAM_LENGTH));

        $name = trim(substr($data, 26, Constants::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";
        $firmware = sprintf("%02X%02X%02X%02X", ord($data[11]), ord($data[12]), ord($data[13]), ord($data[14]));

        $online = (ord($data[15]) == 2) ? 1 : 0; //Online: 2 - Offline: 0 - Unknown: 1
        $state  = ($online == 1) ? ord($data[18]) : 0;

        if ($state == 1 && $allState == 0) {
          $allState = 1;
        }

        $cct   = hexdec(dechex(ord($data[21])).dechex(ord($data[20])));
        $level = ord($data[19]);
        $white = ord($data[25]);

        $rgb   = [
          'r' => ord($data[22]),
          'g' => ord($data[23]),
          'b' => ord($data[24])
        ];

        switch ($type) {
          case Constants::TYPE_SENSOR_MOTION:
          case Constants::TYPE_DIMMER_2WAY:
          case Constants::TYPE_SWITCH_4WAY:
          case Constants::TYPE_SWITCH_MINI:
            $decode = [];
            break;

          default:
            //Decode device module
            $decode = $this->lightifyBase->decodeGroup(ord($data[16]), ord($data[17]));
        }

        $device = [
          'ID'       => $i,
          'type'     => $type,
          'zigBee'   => $zigBee,
          'UUID'     => $UUID,
          'name'     => $name,
          'firmware' => $firmware,
          'online'   => $online,
          'state'    => $state,
          'Groups'   => $decode
        ];
        $Devices[] = $device;

        $device += [
          'cct'    => $cct,
          'level'  => $level,
          'rgb'    => $rgb,
          'white'  => $white
        ];
        $this->SetBuffer("Device-".$i, json_encode($device));

        if (($length = strlen($data)) > self::DATA_DEVICE_LOADED) {
          $length = self::DATA_DEVICE_LOADED;
        }

        $data = substr($data, $length);
      }

      if ($cloudAPI && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudDevices = $this->cloudGET(self::RESSOURCE_DEVICES);
        $this->WriteAttributeString("cloudDevices", $cloudDevices);

        $this->SendDebug("<".__FUNCTION__.">", $cloudDevices, 0);
      }

      if (!empty($Devices)) {
        $allDevice = [
          'ID'       => 0,
          'type'     => Constants::TYPE_ALL_DEVICES,
          'zigBee'   => vtNoString,
          'UUID'     => "84:18:26:00:00:00:00:00",
          'name'     => "All Devices",
          'firmware' => vtNoString,
          'online'   => vtNoValue,
          'state'    => $allState,
          'Groups'   => []
        ];
        array_unshift($Devices, $allDevice);

        $allDevice += [
          'level'  => vtNoString,
          'cct'    => vtNoString,
          'rgb'    => vtNoString,
          'white'  => vtNoString
        ];
        $this->SetBuffer("Device-0", json_encode($allDevice));

      }

      $this->SendDebug("<".__FUNCTION__.">", json_encode($Devices), 0);
      return $Devices;
    }

    return [];

  }


  private function getGatewayGroups(bool $cloudAPI, string $data) : array {

    if (strlen($data) >= (2 + self::DATA_GROUP_LENGTH)) {
      //$Devices = json_decode($this->ReadAttributeString("deviceBuffer"));
      $Groups = [];
      $List   = [];

    $ncount = ord($data[0])+ord($data[1]);
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $buffer = [];

        $UUID = $this->lightifyBase->chrToUUID($data[0].$data[1].chr(Constants::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84));
        $name = trim(substr($data, 2, Constants::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";

        //Get Group devices
        $List = $this->getBufferDevices();

        if (!empty($List)) {
          foreach ($List as $line) {
            $device = json_decode($this->GetBuffer($line));

            foreach ($device->Groups as $id) {
              if (ord($data[0]) == $id) {
                $buffer[] = $device;
                break;
              }
            }
          }
        }

        $group = [
          'ID'      => ord($data[0]),
          'type'    => Constants::TYPE_DEVICE_GROUP,
          'name'    => $name,
        ];
        $Groups[] = $group;

        $group['Devices'] = $buffer;
        $this->SetBuffer("Group-".$i, json_encode($group));

        if (($length = strlen($data)) > self::DATA_GROUP_LENGTH) {
          $length = self::DATA_GROUP_LENGTH;
        }

        $data = substr($data, $length);
      }

      if ($cloudAPI && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudGroups = $this->cloudGET(self::RESSOURCE_GROUPS);
        $this->WriteAttributeString("cloudGroups", $cloudGroups);

        $this->SendDebug("<".__FUNCTION__.">", $cloudGroups, 0);
      }

      $this->SendDebug("<".__FUNCTION__.">", json_encode($Groups), 0);
      return $Groups;
    }

    return [];

  }


  private function getGatewayScenes(bool $cloudAPI, string $data) : array {

    if (strlen($data) >= (2 + self::DATA_DEVICE_LENGTH)) {
      $Scenes = [];
      $ncount = ord($data[0])+ord($data[1]);
      $data   = substr($data, 2);

      for ($i = 1; $i <= $ncount; $i++) {
        $UUID  = $this->lightifyBase->chrToUUID($data[0].chr(0x00).chr(Constants::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84));

        $name = trim(substr($data, 2, Constants::DATA_NAME_LENGTH));
        $name = (!empty($name)) ? $name : "-Unknown-";
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $i."|".$name."|".ord($data[18])." ".ord($data[19]));

        $scene = [
          'ID'    => ord($data[0]),
          'type'  => Constants::TYPE_GROUP_SCENE,
          'name'  => $name,
          'group' => ord($data[18])
        ];

        if (($length = strlen($data)) > self::DATA_SCENE_LENGTH) {
          $length = self::DATA_SCENE_LENGTH;
        }

        $Scenes[] = $scene;
        $this->SetBuffer("Scene-".$i, json_encode($scene));

        $data = substr($data, $length);
      }
      $this->SendDebug("<".__FUNCTION__.">", json_encode($Scenes), 0);

      if ($cloudAPI && !empty($this->ReadAttributeString("osramToken"))) {
        $cloudScenes = $this->cloudGET(self::RESSOURCE_SCENES);
        $this->WriteAttributeString("cloudScenes", $cloudScenes);

        $this->SendDebug("<".__FUNCTION__.">", $cloudScenes, 0);
      }

      return $Scenes;
    }

    return [];

  }


  private function getGatewayIP() : string {

    if (0 < ($parentID = @$this->getParentInstance($this->InstanceID))) {
      return IPS_GetProperty($parentID, "Host");
    }

    return vtNoString;

  }


  private function getAllDevices() : void {

    $Instances  = IPS_GetInstanceListByModuleID(Constants::MODULE_DEVICE);
    $allDevices = [];

    foreach($Instances as $id) {
      $itemclass = IPS_GetProperty($id, "itemClass");

      if ($itemclass == "Light" || $itemclass == "Plug") {
        $allDevices [] = [IPS_GetProperty($id, "UUID")];
      }
    }

    //Store
    $this->WriteAttributeString("allDevices", json_encode($allDevices));
    $this->SendDebug("<".__FUNCTION__.">", json_encode($allDevices), 0);
  }


  private function waitReceive() : string {
    for ($t = 0; $t < 750; $t++) {
      $status = $this->GetBuffer("sendStatus");
      $decode = json_decode($status);

      if ($decode->flag) {
        $this->SetBuffer("sendStatus", json_encode($this->sendResult));
        break;
      }

      usleep(10000);
    }

    return $status;

  }


  private function getBufferDevices(string $uID = vtNoString) : array {

    $buffer = [];
    $List   = $this->GetBufferList();

    foreach ($List as $line) {
      if (strpos($line, "Device-") !== false) {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $line);
        $buffer[] = $line;

        if ($uID != vtNoString && $line == "Device-".$uID) {
          break;
        }
      }
    }

    return $buffer;

  }


  private function getBufferGroups(int $uID = vtNoValue) : array {

    $buffer = [];
    $List   = $this->GetBufferList();

    foreach ($List as $line) {
      if (strpos($line, "Group-") !== false) {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $line);
        $buffer[] = $line;

        if ($uID != vtNoValue && $line == "Group-".$uID) {
          break;
        }
      }
    }

    return $buffer;

  }


  private function getBufferScenes(int $uID = vtNoValue) : array {

    $buffer = [];
    $List   = $this->GetBufferList();

    foreach ($List as $line) {
      if (strpos($line, "Scene-") !== false) {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $line);
        $buffer[] = $line;

        if ($uID != vtNoValue && $line == "Scene-".$uID) {
          break;
        }
      }
    }

    return $buffer;

  }


}