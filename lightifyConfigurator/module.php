<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


class lightifyConfigurator extends IPSModule
{


  protected $lightifyBase;
  protected $debug = false;

  use InstanceStatus,
      InstanceHelper;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  public function Create() {

    //Never delete this line!
    parent::Create();
    $this->ConnectParent(classConstant::MODULE_GATEWAY);

    $this->RegisterPropertyString("listCategory", vtNoString);
    $this->RegisterPropertyString("listElements", vtNoString);

    $this->RegisterAttributeString("listDevices", vtNoString);
    $this->RegisterAttributeString("listGroups", vtNoString);
    $this->RegisterAttributeString("groupDevices", vtNoString);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    //Never delete this line!
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $this->WriteAttributeString("listDevices", vtNoString);
    $this->WriteAttributeString("listGroups", vtNoString);
    $this->WriteAttributeString("groupDevices", vtNoString);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;
    }

  }


  public function GetConfigurationForm() {

    if (!$this->HasActiveParent()) {
      return vtNoForm;
    }

    $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
    $this->debug = IPS_GetProperty($parentID, "debug");
    $this->WriteAttributeString("groupDevices", vtNoString);

    $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
    $Values = [];

    $Types = [
      $this->Translate("Device"),
      $this->Translate("Sensor"),
      $this->Translate("Group"),
      $this->Translate("Scene")
    ];

    //Only add default element if we do not have anything in persistence
    $Categories = json_decode($this->ReadPropertyString("listCategory"), true);

    if (empty($Categories)) {
      foreach ($Types as $item) {
        //$formJSON['elements'][2]['values'][] = [
        $formJSON['elements'][0]['items'][0]['popup']['items'][1]['values'][] = [
          'itemType'   => $item,
          'Category'   => IPS_GetName(0),
          'categoryID' => 0
        ];
      }
    } else {
      //Annotate existing elements
      foreach ($Categories as $index => $row) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        if ($row['categoryID'] && IPS_ObjectExists($row['categoryID'])) {
          $formJSON['elements'][0]['items'][0]['popup']['items'][1]['values'][$index] = [
            'itemType'   => $Types[$index],
            'Category'   => IPS_GetName(0)."\\".IPS_GetLocation($row['categoryID']),
            'categoryID' => $row['categoryID']
          ];
        } else {
          //$formJSON['elements'][2]['values'][$index] = [
          $formJSON['elements'][0]['items'][0]['popup']['items'][1]['values'][$index] = [
            'itemType'   => $Types[$index],
            'Category'   => IPS_GetName(0),
            'categoryID' => 0
          ];
        }
      }
    }

    if (empty($Categories)) {
      $Categories = [];

      for ($i = 0; $i < 4; $i++) {
        $Categories[] = ['categoryID' => 0];
      }
    }
    list($deviceCategory, $sensorCategory, $groupCategory, $sceneCategory) = $Categories;

    $Devices = $this->getDevicesConfigurator($this->getGatewayDevices($parentID), $deviceCategory['categoryID'], $sensorCategory['categoryID']);
    $Groups  = $this->geGroupsConfigurator($this->getGatewayGroups(), $groupCategory['categoryID']);
    $Scenes  = $this->getScenesConfigurator($this->getGatewayScenes(), $sceneCategory['categoryID']);

    //$formJSON['actions'][1]['items'][0]['items'][2]['values'] = $Devices['List'];
    $formJSON['actions'][0]['values'] = array_merge($Devices, $Groups, $Scenes);
    $formJSON['actions'][1]['items'][3]['popup']['items'][1]['items'][0]['values'] = json_decode($this->ReadAttributeString("listGroups"), true);

    //IPS_LogMessage("SymconOSR", "<Configurator|Get configuration:form>   ".json_encode($formJSON));
    return json_encode($formJSON);

  }


  private function sendData(int $cmd, array $param = []) : bool {

    //Add Instance id
    $param['id']  = $this->InstanceID;
    //IPS_LogMessage("SymconOSR", "<Configurator|Send data:buffer>   ".json_encode($param));

    $result = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $cmd,
      'buffer' => json_encode($param)])
    );

    //IPS_LogMessage("SymconOSR", "<Devices|Send data:result>   ".IPS_GetName($this->InstanceID)."|".$result);
    return (bool)$result;

  }


  public function GetGroupConfiguration(string $UUID, $name) : void {

    //Set fields
    $this->UpdateFormField("name", "enabled", true);
    $this->UpdateFormField("name", "value", $name);
    $this->UpdateFormField("rename", "enabled", true);

    //Get data
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUP_DEVICES])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Group configuration:data>   ".$data);

    if (!empty($data)) {
      $decode  = json_decode($data, true);
      $List    = json_decode($this->ReadAttributeString("listDevices"), true);
      $Devices = [];

      foreach ($List as $key) {
        $value = false;

        foreach ($decode as $item) {
          if ($item['Group'] == $UUID) {
            if (in_array($key['UUID'], $item['Devices'])) {
              $value = true;
              break;
            }
          }
        }

        $Devices[] = [
          'UUID'  => $key['UUID'],
          'name'  => $key['name'],
          'value' => $value
        ];
      }

      $this->UpdateFormField("listDevices", "values", json_encode($Devices));
    }

  }


  public function SetGroupConfiguration(int $itemID, string $name, string $UUID, bool $value) : void {

    //Set fields
    $this->UpdateFormField("progress", "visible", true);

    if ($value) {
      $cmd = classCommand::ADD_DEVICE_TO_GROUP;

      $param = [
        'flag'  => 2,
        'args'  => utf8_encode(chr($itemID-classConstant::GROUP_ITEM_INDEX).chr(0x00).$this->lightifyBase->UUIDtoChr($UUID).chr(strlen($name)).$name),
        'value' => vtNoValue
      ];
    } else {
      $cmd = classCommand::RENOVE_DEVICE_FROM_GROUP;

      $param = [
        'flag'  => 2,
        'args'  => utf8_encode(chr($itemID-classConstant::GROUP_ITEM_INDEX).chr(0x00).$this->lightifyBase->UUIDtoChr($UUID)),
        'value' => vtNoValue
      ];
    }

    $result = $this->sendData($cmd, $param);
    $this->UpdateFormField("progress", "visible", false);

  }


  public function RenameGroup(int $itemID, string $name) : void {

    $this->UpdateFormField("progress", "visible", true);
    IPS_LogMessage("SymconOSR", "<Configurator|Rename group:data>   ".$itemID."|".$name);

    $param = [
      'flag'  => chr(0x02),
      'args'  => utf8_decode(chr((int)$itemID-classConstant::GROUP_ITEM_INDEX).chr(0x00).str_pad($name, classConstant::DATA_NAME_LENGTH).chr(0x00)),
      'value' => vtNoValue
    ];

    $result = $this->sendData(classCommand::SET_GROUP_NAME, $param);
    $Groups = $this->getGatewayGroups();

    $this->UpdateFormField("listGroups", "values", $this->ReadAttributeString("listGroups"));
    $this->UpdateFormField("listDevices", "values", json_encode([]));

    $this->UpdateFormField("name", "value", vtNoString);
    $this->UpdateFormField("name", "enabled", false);
    $this->UpdateFormField("rename", "enabled", false);

    $this->UpdateFormField("progress", "visible", false);

  }


  protected function getGatewayDevices(int $parentID) : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_DEVICES_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway devices:data>   ".$data);

    $Devices = [];
    $data = json_decode($data);

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $device) {
        $classType = $device->type;
        $itemClass = vtNoString;
        $itemLabel = vtNoString;
        $itemModel = "-";
        $known = true;

        //Decode device info
        switch ($classType) {
          case classConstant::TYPE_FIXED_WHITE:
            $itemType  = "Device";
            $itemLabel = classConstant::LABEL_FIXED_WHITE;
            break;

          case classConstant::TYPE_LIGHT_CCT:
            $itemLabel = classConstant::LABEL_LIGHT_CCT;

          case classConstant::TYPE_LIGHT_DIMABLE:
            $itemType  = "Device";
            $itemClass = "Light";

            if ($itemLabel == vtNoString) {
              $itemLabel = classConstant::LABEL_LIGHT_DIMABLE;
            }
            break;

          case classConstant::TYPE_LIGHT_COLOR:
            if ($itemLabel == vtNoString) {
              $itemLabel = classConstant::LABEL_LIGHT_COLOR;
            }

          case classConstant::TYPE_LIGHT_EXT_COLOR:
            $itemType  = "Device";
            $itemClass = "Light";

            if ($itemLabel == vtNoString) {
              $itemLabel = classConstant::LABEL_LIGHT_EXT_COLOR;
            }
            break;

          case classConstant::TYPE_PLUG_ONOFF:
            $itemType  = "Device";
            $itemClass = "Plug";
            $itemLabel = classConstant::LABEL_PLUG_ONOFF;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $itemType  = "Sensor";
            $itemClass = "Sensor";
            $itemLabel = classConstant::LABEL_SENSOR_MOTION;
            break;

          case classConstant::TYPE_DIMMER_2WAY:
            $itemClass = "Dimmer";

          case classConstant::TYPE_SWITCH_4WAY:
          case classConstant::TYPE_SWITCH_MINI:
            $itemType = "Device";

            if ($itemClass == vtNoString) {
              $itemClass = "Switch";
            }

            if ($itemLabel == vtNoString) {
              $itemLabel = classConstant::LABEL_NO_CAPABILITY;
            }
            break;

          case classConstant::TYPE_ALL_DEVICES:
            $itemType  = "Device";
            $itemClass = "Light";
            $itemLabel = "On|Off";
            break;

          default:
            $known = false;
            IPS_LogMessage("SymconOSR", "<Configurator|devices:local>   Device type <".$classType."> unknown!");
        }

        if (!$known) {
          continue;
        }

        $buffer = $this->SendDataToParent(json_encode([
          'DataID' => classConstant::TX_GATEWAY,
          'method' => classConstant::GET_DEVICES_CLOUD])
        );
        $cloud = json_decode($buffer);

        if (!empty($cloud)) {
          $gateway = $cloud->devices[0];

          if ($gateway->name == strtoupper(IPS_GetProperty($parentID, "serialNumber"))) {
            $gatewayID = $gateway->id;
            unset($cloud->devices[0]);

            foreach ($cloud->devices as $key => $item) {
              if ($device->name == trim($item->name)) {
                $itemModel = strtoupper($item->deviceModel);

                //Modell mapping
                if (substr($itemModel, 0, 10) == "CLA60 RGBW") {
                  $itemModel = "CLASSIC A60 RGBW";
                }
                elseif (substr($itemModel, 0, 8) == "CLA60 TW") {
                  $itemModel = "CLASSIC A60 TW";
                }
                elseif (substr($itemModel, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $itemModel = "CLASSIC A60 W CLEAR";
                }
                elseif (substr($itemModel, 0, 4) == "PLUG") {
                  $itemModel = classConstant::MODEL_PLUG_ONOFF;
                }

                break;
              }
            }
          }
        }

        $Devices[] = [
          'parent'    => 1,
          'itemID'    => classConstant::DEVICE_ITEM_INDEX+$device->id,
          'itemType'  => $itemType,
          'itemClass' => $itemClass,
          'classType' => $classType,
          'zigBee'    => $device->zigBee,
          'UUID'      => $device->UUID,
          'itemName'  => $device->name,
          'itemLabel' => $itemLabel,
          'itemModel' => $itemModel,
          'firmware'  => $device->firmware
        ];

        if ($itemClass == "Light" || $itemClass == "Plug") {
          $List[] = [
            //'ID'   => classConstant::DEVICE_ITEM_INDEX+$device->id,
            'UUID'  => $device->UUID,
            'name'  => $device->name,
            'value' => false
          ];
        }
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway devices:data>", json_encode($Devices), 0);
      }
    }

    $this->WriteAttributeString("listDevices", json_encode($List));
    return $Devices;

  }


  protected function getGatewayGroups() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway groups:data>   ".$data);

    $Groups = [];
    $data = json_decode($data);

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        $Groups[] = [
          'parent'    => 2,
          'itemID'    => classConstant::GROUP_ITEM_INDEX+$group->id,
          'itemType'  => "Group",
          'itemClass' => "Group",
          'UUID'      => $group->UUID,
          'itemName'  => $group->name
        ];

        $List[] = [
          'ID'   => classConstant::GROUP_ITEM_INDEX+$group->id,
          'UUID' => $group->UUID,
          'name' => $group->name
        ];
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway groups:data>", json_encode($Groups), 0);
      }
    }

    $this->WriteAttributeString("listGroups", json_encode($List));
    return $Groups;

  }


  protected function getGatewayScenes() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_SCENES_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway scenes:data>   ".$data);

    $Scenes = [];
    $data = json_decode($data);

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $scene) {
        $Scenes[] = [
          'parent'    => 3,
          'itemID'    => classConstant::SCENE_ITEM_INDEX+$scene->id,
          'itemType'  => "Scene",
          'itemClass' => "Scene",
          'UUID'      => $scene->UUID,
          'itemName'  => $scene->name
        ];
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway scenes:data>", json_encode($Scenes), 0);
      }
    }

    return $Scenes;

  }


  private function getDevicesConfigurator(array $buffer, int $deviceCategory, int $sensorCategory): array {

    if (count($buffer) > 0) {
      $Devices = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_DEVICE, $item['itemClass'], $item['UUID']);
        $location   = ($item['itemClass'] == "Sensor") ? $this->getCategoryPath($sensorCategory) : $this->getCategoryPath($deviceCategory);

        $device = [
          'name'       => $item['itemName'],
          'itemID'     => $item['itemID'],
          'itemType'   => $this->translate($item['itemType']),
          'itemClass'  => $this->translate($item['itemClass']),
          'zigBee'     => $item['zigBee'],
          'UUID'       => $item['UUID'],
          'itemName'   => $item['itemName'],
          'itemLabel'  => $item['itemLabel'],
          'itemModel'  => $item['itemModel'],
          'firmware'   => $item['firmware'],
          'instanceID' => $instanceID
        ];

        if ($item['itemClass'] != "Dimmer" && $item['itemClass'] != "Switch") {
          $device['create'] = [
            'moduleID'      => classConstant::MODULE_DEVICE,
            'configuration' => [
              'itemID'    => $item['itemID'],
              'itemClass' => $item['itemClass'],
              'classType' => $item['classType'],
              'UUID'      => $item['UUID']
            ],
            'location' => $location
          ];
        }

        $Devices[] = $device;
      }

      return $Devices;
    }

    return [];

  }


  private function geGroupsConfigurator(array $buffer, int $groupCategory): array {

    if (count($buffer) > 0) {
      $Groups = [];
      $List   = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_GROUP, $item['itemClass'], $item['UUID']);
        $location   = $this->getCategoryPath($groupCategory);

        $group = [
          'name'       => $item['itemName'],
          'itemID'     => $item['itemID'],
          'itemType'   => $this->translate($item['itemType']),
          'itemClass'  => $this->translate($item['itemClass']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'itemName'   => $item['itemName'],
          'itemLabel'  => vtNoString,
          'itemModel'  => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $group['create'] = [
          'moduleID'      => classConstant::MODULE_GROUP,
          'configuration' => [
            'itemID'    => $item['itemID'],
            'itemClass' => $item['itemClass'],
            'UUID'      => $item['UUID'],
          ],
          'location' => $location
        ];

        $Groups[] = $group;
      }

      return $Groups;
    }

    return [];

  }


  private function getScenesConfigurator(array $buffer, int $sceneCategory): array {

    if (count($buffer) > 0) {
      $Scenes = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_SCENE, $item['itemClass'], $item['UUID']);
        $location   = $this->getCategoryPath($sceneCategory);

        $scene = [
          'name'       => $item['itemName'],
          'itemID'     => $item['itemID'],
          'itemType'   => $this->translate($item['itemType']),
          'itemClass'  => $this->translate($item['itemClass']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'itemName'   => $item['itemName'],
          'itemLabel'  => vtNoString,
          'itemModel'  => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $scene['create'] = [
          'moduleID'      => classConstant::MODULE_SCENE,
          'configuration' => [
            'itemID'    => $item['itemID'],
            'itemClass' => $item['itemClass'],
            'UUID'      => $item['UUID'],
          ],
          'location' => $location
        ];

        $Scenes[] = $scene;
      }

      return $Scenes;
    }

    return [];

  }


  private function getElementsRemane(array $Elements): array {

    if (count($Elements) > 0) {
      $Values = [];

      foreach ($Elements as $item) {
        $Values[] = [
          'itemName' => $item['itemName']
        ];
      }

      return $Values;
    }

    return [];

  }


  private function getDeviceInstances($moduleID, $itemClass, $UUID) {

    $IDs = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($IDs as $id) {
      if (($itemClass == vtNoString || IPS_GetProperty($id, "itemClass") == $itemClass) && IPS_GetProperty($id, "UUID") == $UUID) {
        return $id;
      }
    }

    return 0;

  }


  private function getCategoryPath(int $categoryID): array {

    if ($categoryID === 0) {
      return [];
    }

    $path[]   = IPS_GetName($categoryID);
    $parentID = IPS_GetObject($categoryID)['ParentID'];

    while ($parentID > 0) {
      $path[] = IPS_GetName($parentID);
      $parentID = IPS_GetObject($parentID)['ParentID'];
    }

    return array_reverse($path);

  }


}
