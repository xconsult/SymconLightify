<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyClass.php';


class LightifyDiscovery extends IPSModule {

  private const MODULE_CONFIGURATOR = "{5552DA2D-B613-4291-8E57-61B0535B8047}";
  private const MODULE_DNSSD        = "{780B2D48-916C-4D59-AD35-5A429B2355A5}";

  private const METHOD_SET_TIMEOUT  = "set:timeout";
  private const DNSSD_TIMEOUT       = 500;


  public function Create() {

    //Never delete this line!
    parent::Create();
    $this->RegisterAttributeInteger("timeout", self::DNSSD_TIMEOUT);

  }


  public function ApplyChanges() {

    //Never delete this line!
    parent::ApplyChanges();

  }


  public function GetConfigurationForm() {

    $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);

    $Gateways = $this->DiscoverGateways();
    $Values = [];

    foreach ($Gateways as $gateway) {
      $gatewayIP    = $gateway['IP'];
      $serialNumber = $gateway['serial'];
      $instanceID   = $this->getConfiguratorInstance();

      $value = [
        'name'         => "OSRAM Lightify Configurator",
        'gatewayIP'    => $gatewayIP,
        'gatewayName'  => $gateway['name'],
        'serialNumber' => $serialNumber,
        'instanceName' => ($instanceID) ? IPS_GetName($instanceID) : "OSRAM Lightify Configurator",
        'instanceID'   => $instanceID
      ];

      $value['create'] = [
        [
          'moduleID' => self::MODULE_CONFIGURATOR,
          'configuration' => [
            'OSRAM' => true
          ]
        ],
        [
          'moduleID' => classConstant::MODULE_GATEWAY,
          'configuration' => [
            'cloudAPI'     => false,
            'update'       => classConstant::TIMER_SYNC,
            'serialNumber' => $serialNumber
          ]
        ],
        [
          'moduleID' => classConstant::CLIENT_SOCKET,
          'configuration' => [
            'Open' => true,
            'Host' => $gatewayIP,
            'Port' => classConstant::GATEWAY_PORT
          ]
        ]
      ];

      $Values[] = $value;
    }

    $timeout = $this->ReadAttributeInteger("timeout");
    $caption = "Time-out [".(string)$timeout."ms]";

    $formJSON['actions'][0]['caption'] = $caption;
    $formJSON['actions'][0]['value'] = $timeout;

    $formJSON['actions'][1]['values'] = $Values;
    return json_encode($formJSON);

  }


  public function GlobalDiscovery(array $param) : void {

    if ($param['method'] == self::METHOD_SET_TIMEOUT) {
      $value   = $param['value'];
      $caption = "Time-out [".$value."ms]";

      $this->UpdateFormField("timeSlider", "caption", $caption);
      $this->WriteAttributeInteger("timeout", (int)$value);
    }

  }


  public function DiscoverGateways() : array {

    $timeout  = $this->ReadAttributeInteger("timeout");
    $Gateways = [];

    if (IPS_ModuleExists(self::MODULE_DNSSD)) {
      $version  = IPS_GetKernelVersion();
      $moduleID = IPS_GetInstanceListByModuleID(self::MODULE_DNSSD)[0];

      if ($version >= 5.4) {
        $mDNS  = ZC_QueryServiceTypeEx($moduleID, "_http._tcp", "", $timeout);
      } else {
        $mDNS  = ZC_QueryServiceType($moduleID, "_http._tcp", "");
      }
      //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $moduleID."|".json_encode($mDNS));

      foreach ($mDNS as $item) {
        $name = $item['Name'];

        if (stripos($name, "Lightify-") !== false) {
          if ($version >= 5.4) {
            $query = ZC_QueryServiceEx($moduleID, $name, $item['Type'], "local.", $timeout);
          } else {
            $query = ZC_QueryService($moduleID, $name, $item['Type'], "local.");
          }
          //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $moduleID."|".json_encode($query));

          foreach ($query as $device) {
            if (array_key_exists("IPv4", $device)) {
              $Gateways[] = [
                'IP'     => $device['IPv4'][0],
                'name'   => $name,
                'serial' => "OSR".strtoupper(substr($name, strrpos($name, "-")+1-strlen($name)))
              ];
            }
          }
        }
      }
    }

    return $Gateways;

  }


  private function getConfiguratorInstance() : int {

    $IDs = IPS_GetInstanceListByModuleID(self::MODULE_CONFIGURATOR);

    foreach ($IDs as $id) {
      if (IPS_GetProperty($id, "OSRAM")) {
        return $id;
      }
    }

    return 0;

  }


}
