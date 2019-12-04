<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


class lightifyDiscovery extends IPSModule
{


  const MODULE_DNSSD = "{780B2D48-916C-4D59-AD35-5A429B2355A5}";


  public function Create() {

    //Never delete this line!
    parent::Create();

  }


  public function ApplyChanges() {

    //Never delete this line!
    parent::ApplyChanges();

  }


  public function GetConfigurationForm() {

    $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);

    $Gateways = $this->DiscoverGateway();
    $Values = [];

    foreach ($Gateways as $gateway) {
      $gatewayIP    = $gateway['IP'];
      $serialNumber = $gateway['Serial'];
      $instanceID   = $this->getGatewayInstances($serialNumber);

      $value = [
        'name'         => "OSRAM Lightify Configurator",
        'gatewayIP'    => $gatewayIP,
        'gatewayName'  => $gateway['Name'],
        'serialNumber' => $serialNumber,
        'instanceName' => ($instanceID) ? IPS_GetName($instanceID) : "OSRAM Lightify Gateway",
        'instanceID'   => $instanceID,
      ];

      $value['create'] = [
        [
          'moduleID'      => classConstant::MODULE_CONFIGURATOR,
          'configuration' => new stdClass()
        ],
        [
          'moduleID'      => classConstant::MODULE_GATEWAY,
          'configuration' => [
            'serialNumber' => $serialNumber
          ]
        ],
        [
          'moduleID'      => classConstant::CLIENT_SOCKET,
          'configuration' => [
            'Host' => $gatewayIP,
            'Port' => classConstant::GATEWAY_PORT,
            'Open' => true
          ]
        ]
      ];

      $Values[] = $value;
    }

    $formJSON['actions'][0]['values'] = $Values;
    return json_encode($formJSON);

  }


  private function getGatewayInstances($serialNumber) : int {

    $IDs = IPS_GetInstanceListByModuleID(classConstant::MODULE_GATEWAY);

    foreach ($IDs as $id) {
      if (IPS_GetProperty($id, "serialNumber") == $serialNumber) {
        return $id;
      }
    }

    return 0;

  }


  public function DiscoverGateway() : array {

    $Gateways = [];

    if (IPS_ModuleExists(self::MODULE_DNSSD)) {
      $moduleID = IPS_GetInstanceListByModuleID(self::MODULE_DNSSD)[0];
      $mDNS = ZC_QueryServiceType($moduleID, "_http._tcp", "");
      //IPS_LogMessage("SymconOSR", "<Discovery|mDNS query:info>   ".json_encode($mDNS));

      for ($i = 0; $i < 10; $i++) {
        foreach ($mDNS as $item) {
          $name = $item['Name'];

          if (stripos($name, "Lightify") !== false) {
            $query = ZC_QueryService($moduleID, $name, $item['Type'],"local.");
            //IPS_LogMessage("SymconOSR", "<Discovery|Service query:info>   ".$moduleID."|".json_encode($query));

            foreach ($query as $device) {
              if (array_key_exists("IPv4", $device)) {
                $Gateways[] = [
                  'IP'     => $device['IPv4'][0],
                  'Name'   => $name,
                  'Serial' => "OSR".strtoupper(substr($name, strrpos($name, "-")+1-strlen($name)))
                ];
              }
            }
          }
        }

        if (!empty($query)) {
          break;
        }

        usleep(100000);
      }
    }

    return $Gateways;

  }


}
