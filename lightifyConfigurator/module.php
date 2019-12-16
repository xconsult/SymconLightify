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

    $this->RegisterAttributeString("listLocations", vtNoString);
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

    $Class = [
      $this->Translate("Device"),
      $this->Translate("Sensor"),
      $this->Translate("Group"),
      $this->Translate("Scene")
    ];

    //Only add default element if we do not have anything in persistence
    //$Locations = json_decode($this->ReadPropertyString("listLocations"), true);
    $Locations = json_decode($this->ReadAttributeString("listLocations"), true);

    if (empty($Locations)) {
      $Locations = [];

      foreach ($Class as $item) {
        $Locations[] = [
          'class' => $item,
          'name'  => IPS_GetName(0),
          'ID'    => 0
        ];
      }

      $formJSON['actions'][1]['items'][0]['popup']['items'][0]['values'] = $Locations;
      $this->WriteAttributeString("listLocations", json_encode($Locations));
    } else {
      //Annotate existing elements
      foreach ($Locations as $index => $row) {
        //We only need to add annotations. Remaining data is merged from persistance automatically.
        //Order is determinted by the order of array elements
        if ($row['ID'] && IPS_ObjectExists($row['ID'])) {
          $formJSON['actions'][1]['items'][0]['popup']['items'][0]['values'][$index] = [
            'class' => $Class[$index],
            'name'  => IPS_GetName(0)."\\".IPS_GetLocation($row['ID']),
            'ID'    => $row['ID']
          ];
        } else {
          $formJSON['actions'][1]['items'][0]['popup']['items'][0]['values'][$index] = [
            'class' => $Class[$index],
            'name'  => IPS_GetName(0),
            'ID'    => 0
          ];
        }
      }
    }

    $Devices = $this->getDevicesConfigurator($this->getGatewayDevices($parentID));
    $Groups  = $this->geGroupsConfigurator($this->getGatewayGroups());
    $Scenes  = $this->getScenesConfigurator($this->getGatewayScenes());

    $formJSON['actions'][0]['values'] = array_merge($Devices, $Groups, $Scenes);
    //IPS_LogMessage("SymconOSR", "<Configurator|Get configuration:form>   ".json_encode($formJSON));
    return json_encode($formJSON);

  }


  private function sendData(int $cmd, array $param = []) : string {

    //Add Instance id
    $param['id']  = $this->InstanceID;
    //IPS_LogMessage("SymconOSR", "<Configurator|Send data:buffer>   ".json_encode($param));

    $result = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $cmd,
      'buffer' => json_encode($param)])
    );

    //IPS_LogMessage("SymconOSR", "<Configurator|Send data:result>   ".IPS_GetName($this->InstanceID)."|".$result);
    return $result;

  }


  public function SetLocations(object $List) : void {

    //Iterate through
    $Locations = [];
    $Values = [];

    foreach ($List as $line) {
      $Locations[] = [
        'class' => $line['class'],
        'name'  => IPS_GetName(0)."\\".IPS_GetLocation($line['ID']),
        'ID'    => $line['ID']
      ];
    }

    //Read and update
    $config = json_decode(IPS_GetConfigurationForm($this->InstanceID));

    foreach ($config->actions as $line) {
      if ($line->type == "Configurator" && $line->name == "Lightify") {
        foreach ($line->values as $item) {
          if ($item->class == "Light" || $item->class == "Plug") {
            $location = $this->getCategoryPath($Locations[0]['ID']);
          } 
          elseif ($item->class == "Sensor") {
            $location = $this->getCategoryPath($Locations[1]['ID']);
          }
          elseif ($item->class == "Group") {
            $location = $this->getCategoryPath($Locations[2]['ID']);
          }
          elseif ($item->class == "Scene") {
            $location = $this->getCategoryPath($Locations[3]['ID']);
          }

          $value = [
            'name'       => $item->name,
            'ID'         => $item->ID,
            'brand'      => $item->brand,
            'class'      => $item->class,
            'zigBee'     => $item->zigBee,
            'UUID'       => $item->UUID,
            'label'      => $item->label,
            'model'      => $item->model,
            'firmware'   => $item->firmware,
            'instanceID' => $item->instanceID
          ];

          if (isset($item->create)) {
            $value['create'] = [
              'moduleID'      => $item->create->moduleID,
              'configuration' => [
                'ID'    => $item->create->configuration->ID,
                'class' => $item->create->configuration->class,
                'type'  => $item->create->configuration->type,
                'UUID'  => $item->create->configuration->UUID,
              ],
              'location' => $location
            ];
          }

          $Values[] = $value;
        }
      }
    }
    //IPS_LogMessage("SymconOSR", "<Configurator|Set Locations:Values>   ".json_encode($Values));

    $this->UpdateFormField("listLocations", "values", json_encode($Locations));
    $this->WriteAttributeString("listLocations", json_encode($Locations));
    $this->UpdateFormField("Lightify", "values", json_encode($Values));

  }


  public function LoadListGroups() : void {

    //Clear values
    $this->UpdateFormField("newGroup", "value", vtNoString);
    $this->UpdateFormField("newGroup", "enabled", false);
    $this->UpdateFormField("renameGroup", "enabled", false);

    $this->UpdateFormField("listDevices", "values", json_encode([]));
    $this->UpdateFormField("setupMessage", "caption", vtNoString);

    $this->UpdateFormField("newDevice", "value", vtNoString);
    $this->UpdateFormField("newDevice", "enabled", false);
    $this->UpdateFormField("renameDevice", "enabled", false);

    //Load list
    $this->UpdateFormField("listGroups", "values", $this->getListGroups());

  }


  public function LoadGroupConfiguration(object $List) : void {

    //Set fields
    $this->UpdateFormField("newGroup", "enabled", true);
    $this->UpdateFormField("newGroup", "value", $List['name']);
    $this->UpdateFormField("renameGroup", "enabled", true);
    $this->UpdateFormField("setupMessage", "caption", vtNoString);

    //Get data
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUP_DEVICES])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Group configuration:data>   ".$data);

    $data = json_decode($data);
    $Devices = [];

    if (is_array($data) && count($data) > 0) {
      $buffer = json_decode($this->getListDevices());

      foreach ($buffer as $key) {
        //if (!$key->online) continue;
        $value = false;

        foreach ($data as $item) {
          if ($item->Group == $List['UUID']) {
            if (in_array($key->UUID, $item->Devices)) {
              $value = true;
              break;
            }
          }
        }

        $Devices[] = [
          'UUID'     => $key->UUID,
          'name'     => $key->name,
          'online'   => $key->online,
          'value'    => $value,
          'rowColor' => ($key->online) ? "" : "#ffc0c0"
        ];
      }

      $buffer = json_encode($Devices);
      $this->SetBuffer("listDevices", $buffer);

      $this->UpdateFormField("listDevices", "values", $buffer);
    }

  }


  public function LoadDeviceConfiguration(object $List) : void {

    //Set fields
    $this->UpdateFormField("newDevice", "enabled", true);
    $this->UpdateFormField("newDevice", "value", $List['name']);
    $this->UpdateFormField("renameDevice", "enabled", true);

  }


  public function SetGroupConfiguration(object $List, int $itemID, string $name) : void {

    if (!$List['online']) {
      $this->UpdateFormField("setupMessage", "caption", $this->Translate("Device is offline and cannot be ".(($List['value']) ? "added!" : "removed!")));
      $this->UpdateFormField("listDevices", "values", $this->GetBuffer("listDevices"));
      return;
    }

    //Iterate through
    $Devices = json_decode($this->GetBuffer("listDevices"));
    $buffer = [];

    foreach ($Devices as $item) {
      $value = $item->value;

      if ($item->UUID == $List['UUID']) {
        $value  = $List['value'];
        $update = ($item->value != $value) ? true : false;
      }

      $buffer[] = [
        'UUID'   => $item->UUID,
        'name'   => $item->name,
        'online' => $item->online,
        'value'  => $value
      ];
    }
    $this->SetBuffer("listDevices", json_encode($buffer));

    if ($update) {
      //Set fields
      $this->UpdateFormField("setupMessage", "visible", false);
      $this->UpdateFormField("setupProgress", "visible", true);

      if ($List['value']) {
        $cmd = classCommand::ADD_DEVICE_TO_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($itemID-classConstant::GROUP_ITEM_INDEX).chr(0x00).$this->lightifyBase->UUIDtoChr($List['UUID']).chr(strlen($name)).$name),
          'value' => vtNoValue
        ];
      } else {
        $cmd = classCommand::RENOVE_DEVICE_FROM_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($itemID-classConstant::GROUP_ITEM_INDEX).chr(0x00).$this->lightifyBase->UUIDtoChr($List['UUID'])),
          'value' => vtNoValue
        ];
      }

      $result = $this->sendData($cmd, $param);
      $status = json_decode($result);
      IPS_Sleep(500);

      if ($status->flag || $status->code == 0) {
        if ($cmd == classCommand::RENOVE_DEVICE_FROM_GROUP) {
          $n = 0;

          foreach ($List as $line) {
            if ($line['value']) {
              $n++;
            }
          }

          if ($n == 0) {
            $this->UpdateFormField("listGroups", "values", $this->getListGroups());
            $this->UpdateFormField("listDevices", "values", json_encode([]));
          }
        }

        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupMessage", "caption", vtNoString);
        $this->UpdateFormField("setupMessage", "visible", true);

        return;
      }
      //IPS_LogMessage("SymconOSR", "<Configurator|Set group configuration:result>   ".$result);

      //Error handling
      $caption = ($value) ? "Adding device to group failed!" : "Removing device from group failed!";

      $this->UpdateFormField("setupProgress", "visible", false);
      $this->UpdateFormField("setupMessage", "caption", $this->Translate($caption));
      $this->UpdateFormField("setupMessage", "visible", true);

      IPS_LogMessage("SymconOSR", "<Configurator|Set group configuration:error>   ".$caption);
    }

  }


  public function RenameListItem(object $List, string $item, string $name) : void {

    if (empty($List['name']) || empty(trim($name))) {
      $this->UpdateFormField("setupMessage", "caption", $this->Translate($item." name cannot be empty!"));
      return;
    }

    if ($name != $List['name']) {
      $this->UpdateFormField("setupProgress", "visible", true);
      $name = str_pad($name, classConstant::DATA_NAME_LENGTH);

      if ($item == "Group") {
        //IPS_LogMessage("SymconOSR", "<Configurator|Rename group:data>   ".$List['ID']."|".$List['name']."|".$name);
        $cmd = classCommand::SET_GROUP_NAME;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr((int)$List['ID']-classConstant::GROUP_ITEM_INDEX).chr(0x00).$name.chr(0x00)),
          'value' => vtNoValue
        ];
      } else {
        IPS_LogMessage("SymconOSR", "<Configurator|Rename device:data>   ".$List['UUID']."|".$List['name']."|".$name);
        $cmd = classCommand::SET_DEVICE_NAME;

        $param = [
          'flag'  => 0,
          'args'  => utf8_encode($this->lightifyBase->UUIDtoChr($List['UUID']).$name.chr(0x00)),
          'value' => vtNoValue
        ];
      }

      $result = $this->sendData($cmd, $param);
      $status = json_decode($result);
      IPS_Sleep(500);

      if ($status->flag && $status->code == 0) {
        $this->UpdateFormField("listGroups", "values", $this->getListGroups());
        $this->UpdateFormField("listDevices", "values", json_encode([]));

        $this->UpdateFormField("newGroup", "value", vtNoString);
        $this->UpdateFormField("newGroup", "enabled", false);
        $this->UpdateFormField("renameGroup", "enabled", false);

        $this->UpdateFormField("newDevice", "value", vtNoString);
        $this->UpdateFormField("newDevice", "enabled", false);
        $this->UpdateFormField("renameDevice", "enabled", false);

        $this->UpdateFormField("setupProgress", "visible", false);
      } else {
        $error = ($item == "Group") ? "Rename group failed!" : "Rename device failed!";

        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupMessage", "caption", $this->Translate($error));
        $this->UpdateFormField("setupMessage", "visible", true);

        IPS_LogMessage("SymconOSR", "<Configurator|Rename list item:error>   ".$error);
      }
    }

  }


  public function LoadListDevices() : void {

    //Set fields
    $this->UpdateFormField("createMessage", "caption", vtNoString);

    $buffer  = json_decode($this->getListDevices());
    $Devices = [];

    foreach ($buffer as $item) {
      //if (!$item->online) continue;

      $Devices[] = [
        'UUID'     => $item->UUID,
        'name'     => $item->name,
        'online'   => $item->online,
        'value'    => $item->value,
        'rowColor' => ($item->online) ? "" : "#ffc0c0"
      ];
    }

    $this->UpdateFormField("addDevices", "values", json_encode($Devices));

  }


  public function CreateGroup(object $List, $name) : void {

    $Groups = json_decode($this->getListGroups());
    $ID = count($Groups)+1;
    $i = 0;

    if ($ID == 17 || empty(trim($name))) {
      $caption = ($ID == 17) ? $this->Translate("Maximal number of groups reached!") : $this->Translate("Group name cannot be empty!"); 
      $this->UpdateFormField("createMessage", "caption", $caption);
      return;
    }

    $this->UpdateFormField("createMessage", "visible", false);
    $this->UpdateFormField("createProgress", "visible", true);

    foreach ($List as $line) {
      if ($line['value']) {
        IPS_LogMessage("SymconOSR", "<Configurator|Create group:device>   ".$line['UUID']."|".$line['name']."|".(int)$line['value']);
        $i++;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($ID).chr(0x00).$this->lightifyBase->UUIDtoChr($line['UUID']).chr(strlen($name)).$name),
          'value' => vtNoValue
        ];

        $result = $this->sendData(classCommand::ADD_DEVICE_TO_GROUP, $param);
        $status = json_decode($result);
        IPS_Sleep(500);
      }
    }
    //IPS_LogMessage("SymconOSR", "<Configurator|Create group:info>   ".$ID);

    $this->UpdateFormField("createProgress", "visible", false);
    $this->UpdateFormField("createMessage", "caption", vtNoString);
    $this->UpdateFormField("createMessage", "visible", true);

    //$this->UpdateFormField("addGroup", "value", vtNoString);
    //$this->UpdateFormField("addDevices", "values", $this->ReadAttributeString("listDevices"));

  }


  public function LoadPopupConfirm(string $popup) : void {

    //$this->UpdateFormField("confirmYes", "onClick", "OSR_SetGroupConfiguration(".'$id'.", ".'$listDevices'.", ".'$listGroups'."['ID'], ".'$listGroups'."['name']);");
    echo "OSR_SetGroupConfiguration(".'$id'.", ".'$listDevices'.", ".'$listGroups'."['ID'], ".'$listGroups'."['name']);";

    //$this->UpdateFormField("confirmYes", "onClick", "echo 'www.symcon.de';");
    $this->UpdateFormField("popupConfirm", "visible", true);

  }


  protected function getListDevices() : string {
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_DEVICES_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway groups:data>   ".$data);

    $data = json_decode($data);
    $List = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $device) {
        switch ($device->type) {
          case classConstant::TYPE_FIXED_WHITE:
          case classConstant::TYPE_LIGHT_CCT:
          case classConstant::TYPE_LIGHT_DIMABLE:
          case classConstant::TYPE_LIGHT_COLOR:
          case classConstant::TYPE_LIGHT_EXT_COLOR:
          case classConstant::TYPE_PLUG_ONOFF:
            $List[] = [
              'UUID'   => $device->UUID,
              'name'   => $device->name,
              'online' => $device->online,
              'value'  => false
            ];
            break;
        }
      }
    }

    return json_encode($List);
  }


  protected function getListGroups() : string {
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway groups:data>   ".$data);

    $data = json_decode($data);
    $List = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        $List[] = [
          'ID'   => classConstant::GROUP_ITEM_INDEX+$group->id,
          'UUID' => $group->UUID,
          'name' => $group->name
        ];
      }
    }

    return json_encode($List);
  }


  protected function getGatewayDevices(int $parentID) : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_DEVICES_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway devices:data>   ".$data);

    $data = json_decode($data);
    $Devices = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $device) {
        $type  = $device->type;
        $class = vtNoString;
        $label = vtNoString;
        $model = vtNoString;
        $known = true;

        //Decode device info
        switch ($type) {
          case classConstant::TYPE_FIXED_WHITE:
            $brand = "Device";
            $label = classConstant::LABEL_FIXED_WHITE;
            break;

          case classConstant::TYPE_LIGHT_CCT:
            $label = classConstant::LABEL_LIGHT_CCT;

          case classConstant::TYPE_LIGHT_DIMABLE:
            $brand = "Device";
            $class = "Light";

            if ($label == vtNoString) {
              $label = classConstant::LABEL_LIGHT_DIMABLE;
            }
            break;

          case classConstant::TYPE_LIGHT_COLOR:
            if ($label == vtNoString) {
              $label = classConstant::LABEL_LIGHT_COLOR;
            }

          case classConstant::TYPE_LIGHT_EXT_COLOR:
            $brand = "Device";
            $class = "Light";

            if ($label == vtNoString) {
              $label = classConstant::LABEL_LIGHT_EXT_COLOR;
            }
            break;

          case classConstant::TYPE_PLUG_ONOFF:
            $brand = "Device";
            $class = "Plug";
            $label = classConstant::LABEL_PLUG_ONOFF;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $brand = "Sensor";
            $class = "Sensor";
            $label = classConstant::LABEL_SENSOR_MOTION;
            break;

          case classConstant::TYPE_DIMMER_2WAY:
            $itemClass = "Dimmer";

          case classConstant::TYPE_SWITCH_4WAY:
          case classConstant::TYPE_SWITCH_MINI:
            $brand = "Device";

            if ($class == vtNoString) {
              $class = "Switch";
            }
           break;

          case classConstant::TYPE_ALL_DEVICES:
            $brand = "Device";
            $class = "Light";
            $label = "On|Off";
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
                $model = strtoupper($item->deviceModel);

                //Modell mapping
                if (substr($model, 0, 10) == "CLA60 RGBW") {
                  $model = "CLASSIC A60 RGBW";
                }
                elseif (substr($model, 0, 8) == "CLA60 TW") {
                  $model = "CLASSIC A60 TW";
                }
                elseif (substr($model, 0, 19) == "CLASSIC A60 W CLEAR") {
                  $model = "CLASSIC A60 W CLEAR";
                }
                elseif (substr($model, 0, 4) == "PLUG") {
                  $model = classConstant::MODEL_PLUG_ONOFF;
                }

                break;
              }
            }
          }
        }

        $Devices[] = [
          'parent'   => 1,
          'ID'       => classConstant::DEVICE_ITEM_INDEX+$device->id,
          'brand'    => $brand,
          'class'    => $class,
          'type'     => $type,
          'zigBee'   => $device->zigBee,
          'UUID'     => $device->UUID,
          'name'     => $device->name,
          'label'    => $label,
          'model'    => $model,
          'firmware' => $device->firmware
        ];
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway devices:data>", json_encode($Devices), 0);
      }
    }

    return $Devices;

  }


  protected function getGatewayGroups() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway groups:data>   ".$data);

    $data = json_decode($data);
    $Groups = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        $Groups[] = [
          'parent' => 2,
          'ID'     => classConstant::GROUP_ITEM_INDEX+$group->id,
          'brand'  => "Group",
          'class'  => "Group",
          'UUID'   => $group->UUID,
          'name'   => $group->name
        ];
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway groups:data>", json_encode($Groups), 0);
      }
    }

    return $Groups;

  }


  protected function getGatewayScenes() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_SCENES_LOCAL])
    );
    //IPS_LogMessage("SymconOSR", "<Configurator|Get Gateway scenes:data>   ".$data);

    $data = json_decode($data);
    $Scenes = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $scene) {
        $Scenes[] = [
          'parent' => 3,
          'ID'     => classConstant::SCENE_ITEM_INDEX+$scene->id,
          'brand'  => "Scene",
          'class'  => "Scene",
          'UUID'   => $scene->UUID,
          'name'   => $scene->name
        ];
      }

      if ($this->debug % 2) {
        $this->SendDebug("<Configurator|Gateway scenes:data>", json_encode($Scenes), 0);
      }
    }

    return $Scenes;

  }


  private function getDevicesConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      //$Locations = json_decode($this->ReadPropertyString("listLocations"), true);
      //IPS_LogMessage("SymconOSR", "<Configurator|Get device configurator:data>   ".$this->ReadPropertyString("listLocations"));
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      //IPS_LogMessage("SymconOSR", "<Configurator|Get device configurator:data>   ".$this->ReadAttributeString("listLocations"));

      if (empty($Locations)) {
        $Locations[0]['ID'] = 0;
        $Locations[1]['ID'] = 0;
      }

      $loDevice = $this->getCategoryPath($Locations[0]['ID']);
      $loSensor = $this->getCategoryPath($Locations[1]['ID']);

      $Devices = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_DEVICE, $item['class'], $item['UUID']);
        $location   = ($item['class'] == "Light") ? $loDevice : $loSensor;
        //IPS_LogMessage("SymconOSR", "<Configurator|Get device configurator:location>   ".$item['itemClass']."|".json_encode($location));

        $device = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'brand'      => $this->translate($item['brand']),
          'class'      => $this->translate($item['class']),
          'zigBee'     => $item['zigBee'],
          'UUID'       => $item['UUID'],
          'name'       => $item['name'],
          'label'      => $item['label'],
          'model'      => $item['model'],
          'firmware'   => $item['firmware'],
          'instanceID' => $instanceID
        ];

        if ($item['class'] != "Dimmer" && $item['class'] != "Switch") {
          $device['create'] = [
            'moduleID'      => classConstant::MODULE_DEVICE,
            'configuration' => [
              'ID'    => $item['ID'],
              'class' => $item['class'],
              'type'  => $item['type'],
              'UUID'  => $item['UUID']
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


  private function geGroupsConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      //$Locations = json_decode($this->ReadPropertyString("listLocations"), true);
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);

      if (empty($Locations)) {
        $Locations[2]['ID'] = 0;
      }

      $location   = $this->getCategoryPath($Locations[2]['ID']);
      $Groups = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_GROUP, $item['class'], $item['UUID']);

        $group = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'brand'      => $this->translate($item['brand']),
          'class'      => $this->translate($item['class']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'label'      => vtNoString,
          'model'      => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $group['create'] = [
          'moduleID'      => classConstant::MODULE_GROUP,
          'configuration' => [
            'ID'    => $item['ID'],
            'class' => $item['class'],
            'type'  => vtNoValue,
            'UUID'  => $item['UUID'],
          ],
          'location' => $location
        ];

        $Groups[] = $group;
      }

      return $Groups;
    }

    return [];

  }


  private function getScenesConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      //$Locations = json_decode($this->ReadPropertyString("listLocations"), true);
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);

      if (empty($Locations)) {
        $Locations[3]['ID'] = 0;
      }

      $location = $this->getCategoryPath($Locations[3]['ID']);
      $Scenes = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_SCENE, $item['class'], $item['UUID']);

        $scene = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'brand'      => $this->translate($item['brand']),
          'class'      => $this->translate($item['class']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'label'      => vtNoString,
          'model'      => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $scene['create'] = [
          'moduleID'      => classConstant::MODULE_SCENE,
          'configuration' => [
            'ID'    => $item['ID'],
            'class' => $item['class'],
            'type'  => vtNoValue,
            'UUID'  => $item['UUID'],
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
          'name' => $item['name']
        ];
      }

      return $Values;
    }

    return [];

  }


  private function getDeviceInstances($moduleID, $class, $UUID) {

    $IDs = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($IDs as $id) {
      if (($class == vtNoString || IPS_GetProperty($id, "class") == $class) && IPS_GetProperty($id, "UUID") == $UUID) {
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
