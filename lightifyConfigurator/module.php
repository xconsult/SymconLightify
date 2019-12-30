<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


class lightifyConfigurator extends IPSModule
{

  const DEVICE_ITEM_INDEX         = 1000;
  const GROUP_ITEM_INDEX          = 2000;
  const SCENE_ITEM_INDEX          = 3000;

  const METHOD_SET_LOCATIONS      = "set:locations";
  const METHOD_LOAD_LIST_GROUPS   = "load:list:groups";
  const METHOD_RENAME_LIST_GROUP  = "rename:list:group";
  const METHOD_RENAME_LIST_DEVICE = "rename:list:device";
  const METHOD_LOAD_GROUP_CONFIG  = "load:group:config";
  const METHOD_SET_GROUP_CONFIG   = "set:group:config";
  const METHOD_SET_DEVICE_RENAME  = "set:device:rename";
  const METHOD_LOAD_LIST_DEVICES  = "load:list:devices";
  const METHOD_CREATE_GROUP       = "create:group";

  const MODE_CONFIG_INITIAL       = "config:initial";
  const MODE_CONFIG_REFRESH       = "config:refresh";

  const MODEL_MANUFACTURER        = "OSRAM";
  const MODEL_PLUG_ONOFF          = "PLUG";
  const MODEL_UNKNOWN             = "UNKNOWN";

  const LABEL_FIXED_WHITE         = "On|Off";
  const LABEL_LIGHT_CCT           = "On|Off Level Temperature";
  const LABEL_LIGHT_DIMABLE       = "On|Off Level";
  const LABEL_LIGHT_COLOR         = "On|Off Level Colour";
  const LABEL_LIGHT_EXT_COLOR     = "On|Off Level Colour Temperature";
  const LABEL_PLUG_ONOFF          = "On|Off";
  const LABEL_SENSOR_MOTION       = "Active|Inactive";
  const LABEL_SENSOR_CONTACT      = "Active|Inactive";
  const LABEL_DIMMER_2WAY         = "2 Button Dimmer";
  const LABEL_SWITCH_MINI         = "3 Button Switch Mini";
  const LABEL_SWITCH_4WAY         = "4 Button Switch";
  const LABEL_NO_CAPABILITY       = "-";
  const LABEL_UNKNOWN             = "-Unknown-";

  const ROW_COLOR_OFFLINE         = "#f6c3c2";

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

    $module = [
      "Lights", "Plugs", "Sensors", "Switches", "Groups", "Scenes"
    ];

    //Only add default element if we do not have anything in persistence
    //$Locations = json_decode($this->ReadPropertyString("listLocations"), true);
    $Locations = json_decode($this->ReadAttributeString("listLocations"), true);

    if (empty($Locations)) {
      $Locations = [];

      foreach ($module as $item) {
        $Locations[] = [
          'module' => $this->Translate($item),
          'name'   => IPS_GetName(0),
          'ID'     => 0
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
            'module' => $this->Translate($module[$index]),
            'name'   => IPS_GetName(0)."\\".IPS_GetLocation($row['ID']),
            'ID'     => $row['ID']
          ];
        } else {
          $formJSON['actions'][1]['items'][0]['popup']['items'][0]['values'][$index] = [
            'module' => $this->Translate($module[$index]),
            'name'   => IPS_GetName(0),
            'ID'     => 0
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


  public function LigthifyConfigurator(array $param) : void {

    switch ($param['method']) {
      case self::METHOD_SET_LOCATIONS:
        $this->setLocations($param['list']);
        break;

      case self::METHOD_LOAD_LIST_GROUPS:
        $this->loadListGroups();
        break;

      case self::METHOD_RENAME_LIST_GROUP:
      case self::METHOD_RENAME_LIST_DEVICE:
        $this->renameListItem($param['method'], $param['list'], $param['name']);
        break;

      case self::METHOD_LOAD_GROUP_CONFIG:
        $this->loadGroupConfiguration($param['mode'], $param['list']);
        break;

      case self::METHOD_SET_GROUP_CONFIG:
        $this->setGroupConfiguration($param['list'], $param['ID'], $param['name']);
        break;

      case self::METHOD_SET_DEVICE_RENAME:
        $this->setDeviceRename($param['name'], (bool)$param['online']);
        break;

      case self::METHOD_LOAD_LIST_DEVICES:
        $this->loadListDevices($param['mode']);
        break;

      case self::METHOD_CREATE_GROUP:
        $this->createGroup($param['list'], $param['name']);
        break;
    }

  }


  private function setLocations(object $List) : void {

    //Iterate through
    $Locations = [];
    $Values    = [];

    foreach ($List as $line) {
      $Locations[] = [
        'module' => $line['module'],
        'name'  => IPS_GetName(0)."\\".IPS_GetLocation($line['ID']),
        'ID'    => $line['ID']
      ];
    }
    //IPS_LogMessage("SymconOSR", "<Configurator|Set Locations:info>   ".json_encode($Locations));

    //Read and update
    $config = json_decode(IPS_GetConfigurationForm($this->InstanceID));

    foreach ($config->actions as $line) {
      if ($line->type == "Configurator" && $line->name == "Lightify") {
        foreach ($line->values as $item) {
          $module = $item->create->configuration->module;

          if ($module == "Light" || $module == "All Devices") {
            $location = $this->getCategoryPath($Locations[0]['ID']);
          }
          elseif ($module == "Plug") {
            $location = $this->getCategoryPath($Locations[1]['ID']);
          }
          elseif ($module == "Sensor") {
            $location = $this->getCategoryPath($Locations[2]['ID']);
          }
          elseif ($module == "Dimmer" || $module == "Switch") {
            $location = $this->getCategoryPath($Locations[3]['ID']);
          }
          elseif ($module == "Group") {
            $location = $this->getCategoryPath($Locations[4]['ID']);
          }
          elseif ($module == "Scene") {
            $location = $this->getCategoryPath($Locations[5]['ID']);
          } else {
            $location = 0;
          }

          $value = [
            'name'       => $item->name,
            'ID'         => $item->ID,
            'class'      => $item->class,
            'module'     => $item->module,
            'zigBee'     => $item->zigBee,
            'UUID'       => $item->UUID,
            'label'      => $item->label,
            'model'      => $item->model,
            'firmware'   => $item->firmware,
            'instanceID' => $item->instanceID
          ];

          if (isset($item->create)) {
            $config = [];

            if ($item->class == "Group" || $item->class == "Scene") {
              $config['ID'] = $item->create->configuration->ID;
            }
            $config['module'] = $item->create->configuration->module;

            if ($item->class == "Device" || $item->class == "Sensor" || $item->class == "Switch") {
              $config['type']   = $item->create->configuration->type;
              $config['zigBee'] = $item->create->configuration->zigBee;
            }
            $config['UUID'] = $item->create->configuration->UUID;

            $value['create'] = [
              'moduleID'      => $item->create->moduleID,
              'configuration' => $config,
              'location'      => $location
            ];
          }

          $Values[] = $value;
        }
      }
    }

    //IPS_LogMessage("SymconOSR", "<Configurator|Set Locations:Values>   ".json_encode($Values));
    $Locations = json_encode($Locations);

    $this->UpdateFormField("listLocations", "values", $Locations);
    $this->WriteAttributeString("listLocations", $Locations);
    $this->UpdateFormField("Lightify", "values", json_encode($Values));

  }


  private function loadListGroups() : void {

    //Clear values
    $this->UpdateFormField("newGroup", "value", vtNoString);
    $this->UpdateFormField("newGroup", "enabled", false);
    $this->UpdateFormField("renameGroup", "enabled", false);

    $this->UpdateFormField("listDevices", "values", json_encode([]));
    $this->UpdateFormField("setupMessage", "caption", vtNoString);

    $this->UpdateFormField("newDevice", "value", vtNoString);
    $this->UpdateFormField("newDevice", "enabled", false);
    $this->UpdateFormField("renameDevice", "enabled", false);
    $this->UpdateFormField("setupRefresh", "enabled", false);

    //Load list
    $this->UpdateFormField("listGroups", "values", $this->getListGroups());

  }


  private function loadGroupConfiguration(string $mode, object $List) : void {

    if ($mode == self::MODE_CONFIG_INITIAL) {
      $this->UpdateFormField("newGroup", "enabled", true);
      $this->UpdateFormField("newGroup", "value", $List['name']);
      $this->UpdateFormField("renameGroup", "enabled", true);
      $this->UpdateFormField("setupRefresh", "enabled", true);
    }

    $this->UpdateFormField("newDevice", "value", vtNoString);
    $this->UpdateFormField("newDevice", "enabled", false);
    $this->UpdateFormField("renameDevice", "enabled", false);
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
          'rowColor' => ($key->online) ? "" : self::ROW_COLOR_OFFLINE
        ];
      }

      $buffer = json_encode($Devices);
      $this->SetBuffer("listDevices", $buffer);

      $this->UpdateFormField("listDevices", "values", $buffer);
    }

  }


  private function setGroupConfiguration(object $List, int $itemID, string $name) : void {

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
      $this->UpdateFormField("setupRefresh", "visible", false);
      $this->UpdateFormField("setupProgress", "visible", true);

      if ($List['value']) {
        $cmd = classCommand::ADD_DEVICE_TO_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($itemID).chr(0x00).$this->lightifyBase->UUIDtoChr($List['UUID']).chr(strlen($name)).$name),
          'value' => vtNoValue
        ];
      } else {
        $cmd = classCommand::RENOVE_DEVICE_FROM_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($itemID).chr(0x00).$this->lightifyBase->UUIDtoChr($List['UUID'])),
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
            $this->UpdateFormField("newGroup", "value", vtNoString);
            $this->UpdateFormField("newGroup", "enabled", false);
            $this->UpdateFormField("renameGroup", "enabled", false);

            $this->UpdateFormField("newDevice", "value", vtNoString);
            $this->UpdateFormField("newDevice", "enabled", false);
            $this->UpdateFormField("renameDevice", "enabled", false);

            $this->UpdateFormField("listGroups", "values", $this->getListGroups());
            $this->UpdateFormField("listDevices", "values", json_encode([]));

            $this->UpdateFormField("setupRefresh", "enabled", false);
          }
        }

        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupMessage", "caption", vtNoString);
        $this->UpdateFormField("setupMessage", "visible", true);
        $this->UpdateFormField("setupRefresh", "visible", true);

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


  private function setDeviceRename(string $name, bool $online) : void {

    if ($online) {
      //Set fields
      $this->UpdateFormField("setupMessage", "caption", vtNoString);

      $this->UpdateFormField("newDevice", "enabled", true);
      $this->UpdateFormField("newDevice", "value", $name);
      $this->UpdateFormField("renameDevice", "enabled", true);
    } else {
      $this->UpdateFormField("newDevice", "value", vtNoString);
      $this->UpdateFormField("newDevice", "enabled", false);
      $this->UpdateFormField("renameDevice", "enabled", false);
    }

  }


  private function renameListItem(string $method, object $List, string $name) : void {

    if (empty($List['name']) || empty(trim($name))) {
      if ($method == self::METHOD_RENAME_LIST_GROUP) {
       $caption = "Group name cannot be empty!";
      } else {
       $caption = "Device name cannot be empty!";
      }

      $this->UpdateFormField("setupMessage", "caption", $this->Translate($caption));
      return;
    }

    if ($name != $List['name']) {
      $this->UpdateFormField("setupProgress", "visible", true);
      $name = str_pad($name, classConstant::DATA_NAME_LENGTH);

      if ($method == self::METHOD_RENAME_LIST_GROUP) {
        //IPS_LogMessage("SymconOSR", "<Configurator|Rename group:data>   ".$List['ID']."|".$List['name']."|".$name);
        $cmd = classCommand::SET_GROUP_NAME;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr((int)$List['ID']-self::GROUP_ITEM_INDEX).chr(0x00).$name.chr(0x00)),
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
        $error = ($method == self::METHOD_RENAME_LIST_GROUP) ? "Rename group failed!" : "Rename device failed!";

        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupMessage", "caption", $this->Translate($error));
        $this->UpdateFormField("setupMessage", "visible", true);

        IPS_LogMessage("SymconOSR", "<Configurator|Rename list item:error>   ".$error);
      }
    }

  }


  private function loadListDevices(string $mode) : void {

    if ($mode == self::MODE_CONFIG_INITIAL) {
      $this->UpdateFormField("addGroup", "value", vtNoString);
    }
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
        'rowColor' => ($item->online) ? "" : self::ROW_COLOR_OFFLINE
      ];
    }

    $this->UpdateFormField("addDevices", "values", json_encode($Devices));

  }


  private function createGroup(object $List, string $name) : void {

    $Groups = json_decode($this->getListGroups());
    $ID = count($Groups)+1;
    $i = 0;

    if ($ID == 17 || empty(trim($name))) {
      $caption = ($ID == 17) ? $this->Translate("Maximum number [16] of groups exeeded!") : $this->Translate("Group name cannot be empty!"); 
      $this->UpdateFormField("createMessage", "caption", $caption);
      return;
    }

    $this->UpdateFormField("createMessage", "visible", false);
    $this->UpdateFormField("createRefresh", "visible", false);
    $this->UpdateFormField("createProgress", "visible", true);

    foreach ($List as $line) {
      if ($line['value']) {
        //IPS_LogMessage("SymconOSR", "<Configurator|Create group:device>   ".$line['UUID']."|".$line['name']."|".(int)$line['value']);
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
    $this->UpdateFormField("createRefresh", "visible", true);

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
          'ID'   => $group->id,
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
        $type   = $device->type;
        $module = vtNoString;
        $label  = vtNoString;
        $model  = vtNoString;
        $known  = true;

        //Decode device info
        switch ($type) {
          case classConstant::TYPE_FIXED_WHITE:
            $class = "Device";
            $label = self::LABEL_FIXED_WHITE;
            break;

          case classConstant::TYPE_LIGHT_CCT:
            $label = self::LABEL_LIGHT_CCT;

          case classConstant::TYPE_LIGHT_DIMABLE:
            $class  = "Device";
            $module = "Light";

            if ($label == vtNoString) {
              $label = self::LABEL_LIGHT_DIMABLE;
            }
            break;

          case classConstant::TYPE_LIGHT_COLOR:
            if ($label == vtNoString) {
              $label = self::LABEL_LIGHT_COLOR;
            }

          case classConstant::TYPE_LIGHT_EXT_COLOR:
            $class  = "Device";
            $module = "Light";

            if ($label == vtNoString) {
              $label = self::LABEL_LIGHT_EXT_COLOR;
            }
            break;

          case classConstant::TYPE_PLUG_ONOFF:
            $class  = "Device";
            $module = "Plug";
            $label  = self::LABEL_PLUG_ONOFF;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
            $class  = "Sensor";
            $module = "Sensor";
            $label  = self::LABEL_SENSOR_MOTION;
            break;

          case classConstant::TYPE_DIMMER_2WAY:
            $module = "Dimmer";
            $label  = self::LABEL_DIMMER_2WAY;

          case classConstant::TYPE_SWITCH_MINI:
            if ($label == vtNoString) {
              $label = self::LABEL_SWITCH_MINI;
            }

          case classConstant::TYPE_SWITCH_4WAY:
            $class = "Switch";

            if ($module == vtNoString) {
              $module = "Switch";
            }

            if ($label == vtNoString) {
              $label = self::LABEL_SWITCH_4WAY;
            }
           break;

          case classConstant::TYPE_ALL_DEVICES:
            $class  = "Device";
            $module = "All Devices";
            $label  = "On|Off";
            break;

          default:
            $known = false;
            IPS_LogMessage("SymconOSR", "<Configurator|devices:local>   Device type <".$moduleType."> unknown!");
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
                  $model = self::MODEL_PLUG_ONOFF;
                }

                break;
              }
            }
          }
        }

        $Devices[] = [
          'parent'   => 1,
          'ID'       => self::DEVICE_ITEM_INDEX+$device->id,
          'class'    => $class,
          'module'   => $module,
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
          'ID'     => self::GROUP_ITEM_INDEX+$group->id,
          'class'  => "Group",
          'module' => "Group",
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
          'ID'     => self::SCENE_ITEM_INDEX+$scene->id,
          'class'  => "Scene",
          'module' => "Scene",
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
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      //IPS_LogMessage("SymconOSR", "<Configurator|Get device configurator:data>   ".$this->ReadAttributeString("listLocations"));
      $Devices = [];

      if (empty($Locations)) {
        $Locations[0]['ID'] = 0;
        $Locations[1]['ID'] = 0;
        $Locations[2]['ID'] = 0;
        $Locations[3]['ID'] = 0;
      }

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_DEVICE, $item['module'], $item['UUID']);

        if ($item['module'] == "Light" || $item['module'] == "All Devices") {
          $location = $this->getCategoryPath($Locations[0]['ID']);
        }
        elseif ($item['module'] == "Plug") {
          $location = $this->getCategoryPath($Locations[1]['ID']);
        }
        elseif ($item['module'] == "Sensor") {
          $location = $this->getCategoryPath($Locations[2]['ID']);
        }
        elseif ($item['module'] == "Dimmer" || $item['module'] == "Switch") {
          $location = $this->getCategoryPath($Locations[3]['ID']);
        }
        //IPS_LogMessage("SymconOSR", "<Configurator|Get device configurator:location>   ".$item['itemClass']."|".json_encode($location));

        $device = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'class'      => $this->Translate($item['class']),
          'module'     => $this->Translate($item['module']),
          'zigBee'     => $item['zigBee'],
          'UUID'       => $item['UUID'],
          'name'       => $item['name'],
          'label'      => $item['label'],
          'model'      => $item['model'],
          'firmware'   => $item['firmware'],
          'instanceID' => $instanceID
        ];

        //if ($item['module'] != "Dimmer" && $item['module'] != "Switch") {
          $config = [
            'module' => $item['module'],
            'type'   => $item['type'],
            'zigBee' => $item['zigBee'],
            'UUID'   => $item['UUID']
          ];

          $device['create'] = [
            'moduleID'      => classConstant::MODULE_DEVICE,
            'configuration' => $config,
            'location'      => $location
          ];
        //}

        $Devices[] = $device;
      }

      return $Devices;
    }

    return [];

  }


  private function geGroupsConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      $location  = (empty($Locations)) ? 0 : $this->getCategoryPath($Locations[4]['ID']);
      $Groups = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_GROUP, $item['module'], $item['UUID']);

        $group = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'class'      => $this->Translate($item['class']),
          'module'     => $this->Translate($item['module']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'label'      => vtNoString,
          'model'      => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $config = [
          'ID'     => $item['ID']-self::GROUP_ITEM_INDEX,
          'module' => $item['module'],
          'UUID'   => $item['UUID']
        ];

        $group['create'] = [
          'moduleID'      => classConstant::MODULE_GROUP,
          'configuration' => $config,
          'location'      => $location
        ];

        $Groups[] = $group;
      }

      return $Groups;
    }

    return [];

  }


  private function getScenesConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      $location  = (empty($Locations)) ? 0 : $this->getCategoryPath($Locations[5]['ID']);
      $Scenes = [];

      foreach ($buffer as $item) {
        $instanceID = $this->getDeviceInstances(classConstant::MODULE_SCENE, $item['module'], $item['UUID']);

        $scene = [
          'name'       => $item['name'],
          'ID'         => $item['ID'],
          'class'      => $this->Translate($item['class']),
          'module'     => $this->Translate($item['module']),
          'zigBee'     => vtNoString,
          'UUID'       => $item['UUID'],
          'label'      => vtNoString,
          'model'      => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $config = [
          'ID'     => $item['ID']-self::SCENE_ITEM_INDEX,
          'module' => $item['module'],
          'UUID'   => $item['UUID']
        ];

        $scene['create'] = [
          'moduleID'      => classConstant::MODULE_SCENE,
          'configuration' => $config,
          'location'      => $location
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


  private function getDeviceInstances($moduleID, $module, $UUID) {

    $IDs = IPS_GetInstanceListByModuleID($moduleID);

    foreach ($IDs as $id) {
      if (($module == vtNoString || @IPS_GetProperty($id, "module") == $module) && @IPS_GetProperty($id, "UUID") == $UUID) {
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
