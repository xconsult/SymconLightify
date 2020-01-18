<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/mainClass.php';
require_once __DIR__.'/../libs/lightifyClass.php';


class LightifyConfigurator extends IPSModule {

  const ROW_COLOR_OFFLINE         = "#f6c3c2";

  const DEVICE_ITEM_INDEX         = 1000;
  const GROUP_ITEM_INDEX          = 2000;
  const SCENE_ITEM_INDEX          = 3000;

  const METHOD_API_REGISTER       = "api:register";
  const METHOD_LOAD_LOCATIONS     = "load:locations";
  const METHOD_SET_LOCATIONS      = "set:locations";
  const METHOD_SET_CONFIGURATION  = "set:configuration";
  const METHOD_LOAD_LIST_GROUPS   = "load:list:groups";
  const METHOD_RENAME_LIST_GROUP  = "rename:list:group";
  const METHOD_RENAME_LIST_DEVICE = "rename:list:device";
  const METHOD_LOAD_GROUP_CONFIG  = "load:group:config";
  const METHOD_SET_GROUP_CONFIG   = "set:group:config";
  const METHOD_SET_DEVICE_RENAME  = "set:device:rename";
  const METHOD_LOAD_LIST_DEVICES  = "load:list:devices";
  const METHOD_CREATE_GROUP       = "create:group";
  const METHOD_ADD_DEVICE_LIST    = "add:device:list";

  const MODE_CONFIG_INITIAL       = "config:initial";
  const MODE_CONFIG_LOADED        = "config:loaded";

  const MODEL_MANUFACTURER        = "OSRAM";
  const MODEL_PLUG_ONOFF          = "PLUG";
  const MODEL_UNKNOWN             = "UNKNOWN";

  const LABEL_ALL_DEVICES         = "On|Off";
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
  const LABEL_GROUP_ONOFF         = "On|Off";
  const LABEL_SCENE_APPLY         = "Apply";
  const LABEL_NO_CAPABILITY       = "-";
  const LABEL_UNKNOWN             = "-Unknown-";

  protected $lightifyBase;

  use InstanceStatus,
      InstanceHelper;


  public function __construct($InstanceID) {

    parent::__construct($InstanceID);
    $this->lightifyBase = new lightifyBase;

  }


  public function Create() {

    //Never delete this line!
    parent::Create();

    $this->RegisterPropertyBoolean("OSRAM", true);
    $this->RegisterAttributeString("listLocations", vtNoString);
    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    //Never delete this line!
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    $this->SetBuffer("groupID", vtNoValue);

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

    $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
    $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
    $formJSON['elements'][0]['items'][0]['objectID'] = $parentID;

    $this->SetBuffer("listLocations", vtNoString);
    $this->SetBuffer("listGroups", vtNoString);
    $Values = [];

    $Modules = [
      "Lights", "Plugs", "Sensors", "Switches", "Groups", "Scenes"
    ];

    //Only add default element if we do not have anything in persistence
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

      $formJSON['actions'][1]['items'][0]['items'][0]['popup']['items'][0]['values'] = $Locations;
      $this->WriteAttributeString("listLocations", json_encode($Locations));
    }

    $Devices = $this->getDevicesConfigurator($this->getGatewayDevices($parentID));
    $Groups  = $this->geGroupsConfigurator($this->getGatewayGroups());
    $Scenes  = $this->getScenesConfigurator($this->getGatewayScenes());

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Devices));
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Groups));
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Scenes));

    $formJSON['actions'][0]['values'] = array_merge($Devices, $Groups, $Scenes);
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($formJSON));

    return json_encode($formJSON);

  }


  private function sendData(int $cmd, array $param = []) : string {

    //Add Instance id
    $param['id']  = $this->InstanceID;
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($param));

    $result = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => $cmd,
      'buffer' => json_encode($param)])
    );

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", IPS_GetName($this->InstanceID)."|".$result);
    return $result;

  }


  public function GlobalConfigurator(array $param) : void {

    switch ($param['method']) {
      case self::METHOD_API_REGISTER:
        if (!$this->HasActiveParent()) {
          return;
        }

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        echo OSR_LightifyRegister($parentID);
        break;

      case self::METHOD_LOAD_LOCATIONS:
        $this->loadLocations($param['mode']);
        break;

      case self::METHOD_SET_LOCATIONS:
        $this->setLocations($param['list']);
        break;

      case self::METHOD_SET_CONFIGURATION:
        $this->setConfiguration();
        break;

      case self::METHOD_LOAD_LIST_GROUPS:
        $List = $this->GetBuffer("listGroups");
        $this->loadListGroups($List);
        break;

      case self::METHOD_RENAME_LIST_GROUP:
      case self::METHOD_RENAME_LIST_DEVICE:
        $this->renameListItem($param['method'], $param['list'], $param['name']);
        break;

      case self::METHOD_LOAD_GROUP_CONFIG:
        $this->loadGroupConfiguration($param['mode'], $param['list']);
        break;

      case self::METHOD_SET_GROUP_CONFIG:
        $this->setGroupConfiguration($param['list']);
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

      case self::METHOD_ADD_DEVICE_LIST:
        $this->addDeviceList($param['list']);
        break;
    }

  }


  private function loadLocations(string $mode) : void {

    $this->UpdateFormField("locateProgress", "visible", false);
    $this->UpdateFormField("applyCategory", "enabled", false);
    $this->UpdateFormField("applyCategory", "visible", true);

    if ($mode == self::MODE_CONFIG_INITIAL) {
      $this->UpdateFormField("listLocations", "values", $this->ReadAttributeString("listLocations"));
    }

  }


  private function setLocations(object $List) : void {

    //Iterate through
    $Locations = [];

    foreach ($List as $line) {
      $Locations[] = [
        'module' => $line['module'],
        'name'  => IPS_GetLocation($line['ID']),
        'ID'    => $line['ID']
      ];
    }
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Locations));

    $Locations = json_encode($Locations);
    $this->UpdateFormField("listLocations", "values", $Locations);
    $this->SetBuffer("listLocations", $Locations);

    $this->UpdateFormField("applyCategory", "enabled", true);

  }


  private function setConfiguration() : void {

    //Store data
    $this->WriteAttributeString("listLocations", $this->GetBuffer("listLocations"));

    $this->UpdateFormField("applyCategory", "visible", false);
    $this->UpdateFormField("locateProgress", "visible", true);

    //Read and update
    $formJSON  = json_decode(IPS_GetConfigurationForm($this->InstanceID));
    $Locations = json_decode($this->ReadAttributeString("listLocations"), true);

    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Locations));
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($formJSON));

    foreach ($formJSON->actions as $line) {
      $Values = [];

      if ($line->type == "Configurator" && $line->name == "configModules") {
        $locateDevice = $this->getCategoryPath($Locations[0]['ID']);
        $locatePlug   = $this->getCategoryPath($Locations[1]['ID']);
        $locateSensor = $this->getCategoryPath($Locations[2]['ID']);
        $locateSwitch = $this->getCategoryPath($Locations[3]['ID']);
        $locateGroup  = $this->getCategoryPath($Locations[4]['ID']);
        $locateScene  = $this->getCategoryPath($Locations[5]['ID']);

        foreach ($line->values as $item) {
          $module   = $item->create->configuration->module;
          $location = vtNoString;

          switch ($module) {
            case "Light":
            case "All Devices":
              $location = $locateDevice;
              $class = "Device";
              break;

            case "Plug":
              $location = $locatePlug;
              $class = "Device";
              break;

            case "Sensor":
              $location = $locateSensor;
              $class = "Device";
              break;

            case "Dimmer":
            case "Switch":
              $location = $locateSwitch;
              $class = "Device";
              break;

            case "Group":
              $location = $locateGroup;

            case "Scene":
              if (empty($location)) {
                $location = $locateScene;
              }

              $class = "Group";
              break;
          }

          $value = [
            'ID'         => $item->ID,
            'name'       => $item->name,
            'module'     => $item->module,
            'zigBee'     => $item->zigBee,
            'UUID'       => $item->UUID,
            'label'      => $item->label,
            'model'      => $item->model,
            'firmware'   => $item->firmware,
            'instanceID' => $item->instanceID
          ];

          if ($class == "Device") {
            $value['create'] = [
              'moduleID' => $item->create->moduleID,
              'configuration' => [
                'module' => $item->create->configuration->module,
                'type'   => $item->create->configuration->type,
                'UUID'   => $item->create->configuration->UUID
              ],
              'location' => $location
            ];
          }
          elseif ($class == "Group") {
            $value['create'] = [
              'moduleID' => $item->create->moduleID,
              'configuration' => [
                'ID'     => $item->create->configuration->ID,
                'module' => $item->create->configuration->module
              ],
              'location' => $location
            ];
          }

          $Values[] = $value;
        }

        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", json_encode($Values));
        $this->UpdateFormField("configModules", "values", json_encode($Values));
      }
    }

    //Update
    $this->loadLocations(self::MODE_CONFIG_LOADED);

  }


  private function loadListGroups(string $List) : void {

    //Load list
    if (empty($List)){
      $Groups = json_encode($this->getListGroups());
      $this->SetBuffer("listGroups", $Groups);

      $this->UpdateFormField("listGroups", "values", $Groups);
    }

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

    $buffer  = $this->getListDevices();
    $Devices = [];

    foreach ($buffer as $item) {
      $value = (in_array($List['ID'], $item['Groups'])) ? true : false;
      $color = ($item['online']) ? vtNoString : self::ROW_COLOR_OFFLINE;

      $Devices[] = [
        'UUID'     => $item['UUID'],
        'name'     => $item['name'],
        'online'   => $item['online'],
        'value'    => $value,
        'rowColor' => $color
      ];
    }

    $this->UpdateFormField("listDevices", "values", json_encode($Devices));

  }


  private function setGroupConfiguration(array $List) : void {

    $online = $List[1]['online'];
    $value  = $List[1]['value'];

    $group  = $List[0]['name'];
    $device = $List[1]['name'];

    if (!$online) {
      $caption = $this->Translate("Device")." [".$device."] ";

      if ($value) {
        $caption .= $this->Translate("is offline and cannot be added to group ")."[".$group.$this->Translate("]!");
      } else {
        $caption .= $this->Translate("is offline and cannot be removed from group ")."[".$group."]".$this->Translate("!");
      }

      $this->UpdateFormField("alertMessage", "caption", $caption);
      $this->UpdateFormField("popupAlert", "visible", true);

      $this->loadGroupConfiguration(self::MODE_CONFIG_LOADED, $List[0]);
      return;
    }

    //if ($update) {
      $ID   = $List[0]['ID'];
      $UUID = $List[1]['UUID'];

      //Set fields
      $this->UpdateFormField("setupRefresh", "visible", false);
      $this->UpdateFormField("setupProgress", "visible", true);

      if ($value) {
        $cmd = classCommand::ADD_DEVICE_TO_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($ID).chr(0x00).$this->lightifyBase->UUIDtoChr($UUID).chr(strlen($group)).$group),
          'value' => vtNoValue
        ];
      } else {
        $cmd = classCommand::RENOVE_DEVICE_FROM_GROUP;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($ID).chr(0x00).$this->lightifyBase->UUIDtoChr($UUID)),
          'value' => vtNoValue
        ];
      }

      $result = $this->sendData($cmd, $param);
      $status = json_decode($result);
      usleep(750000);

      if ($status->flag || $status->code == 0) {
        if ($cmd == classCommand::RENOVE_DEVICE_FROM_GROUP) {
          $n = 0;

          foreach ($List[1] as $line) {
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

            $this->UpdateFormField("listGroups", "values", json_encode($this->getListGroups()));
            $this->UpdateFormField("listDevices", "values", json_encode([]));

            $this->UpdateFormField("setupRefresh", "enabled", false);
          }
        }

        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupRefresh", "visible", true);

        return;
      }
      //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $result);

      //Error handling
      $this->UpdateFormField("setupProgress", "visible", false);

      if ($value) {
        $caption = $this->Translate("Adding device")." [".$device."] ".$this->Translate("to group")." [".$group."] ".$this->Translate("failed!");
      } else {
        $caption = $this->Translate("Removing device")." [".$device."] ".$this->Translate("from group")." [".$group."] ".$this->Translate(" failed!");
      }

      $this->UpdateFormField("alertMessage", "caption", $caption);
      $this->UpdateFormField("popupAlert", "visible", true);

      $this->SendDebug("<".__FUNCTION__.">", $caption, 0);
      IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $caption);
    //}

  }


  private function setDeviceRename(string $name, bool $online) : void {

    if ($online) {
      //Set fields
      $this->UpdateFormField("newDevice", "enabled", true);
      $this->UpdateFormField("newDevice", "value", $name);
      $this->UpdateFormField("renameDevice", "enabled", true);
    } else {
      $this->UpdateFormField("newDevice", "value", vtNoString);
      $this->UpdateFormField("newDevice", "enabled", false);
      $this->UpdateFormField("renameDevice", "enabled", false);
    }

  }


  private function renameListItem(string $method, array $List, string $newName) : void {

    if ($method == self::METHOD_RENAME_LIST_GROUP) {
      $caption = "Please enter a valid group name!";
      $name = $List[0]['name'];
      $ID   = (int)$List[0]['ID'];
    } else {
      $caption = "Please enter a valid device name!";
      $name = $List[1]['name'];
      $UUID = $List[1]['UUID'];
    }

    if (empty($name) || empty(trim($newName))) {
      $this->UpdateFormField("alertMessage", "caption", $this->Translate($caption));
      $this->UpdateFormField("popupAlert", "visible", true);

      return;
    }

    if ($newName != $name) {
      $this->UpdateFormField("setupRefresh", "visible", false);
      $this->UpdateFormField("setupProgress", "visible", true);

      //Strip to max. length
      $newName = str_pad($newName, classConstant::DATA_NAME_LENGTH);

      if ($method == self::METHOD_RENAME_LIST_GROUP) {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $ID."|".$name."|".$newName);
        $cmd = classCommand::SET_GROUP_NAME;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($ID).chr(0x00).$newName.chr(0x00)),
          'value' => vtNoValue
        ];
      } else {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $UUID."|".$name."|".$newName);
        $cmd = classCommand::SET_DEVICE_NAME;

        $param = [
          'flag'  => 0,
          'args'  => utf8_encode($this->lightifyBase->UUIDtoChr($UUID).$newName.chr(0x00)),
          'value' => vtNoValue
        ];
      }

      $result = $this->sendData($cmd, $param);
      $status = json_decode($result);
      usleep(750000);

      if ($status->flag && $status->code == 0) {
        $this->UpdateFormField("setupProgress", "visible", false);
        $this->UpdateFormField("setupRefresh", "visible", true);

        if ($method == self::METHOD_RENAME_LIST_GROUP) {
          $this->UpdateFormField("newGroup", "value", vtNoString);
          $this->UpdateFormField("newGroup", "enabled", false);
          $this->UpdateFormField("renameGroup", "enabled", false);

          $this->UpdateFormField("newDevice", "value", vtNoString);
          $this->UpdateFormField("newDevice", "enabled", false);
          $this->UpdateFormField("renameDevice", "enabled", false);

          $this->loadListGroups(vtNoString);
          $this->UpdateFormField("listDevices", "values", json_encode([]));
        } else {
          $this->loadGroupConfiguration(self::MODE_CONFIG_LOADED, $List[0]);
        }
      } else {
        $this->UpdateFormField("setupProgress", "visible", false);
        $caption = ($method == self::METHOD_RENAME_LIST_GROUP) ? "Rename of group"." [".$newName."] failed!" : "Rename of device"." [".$newName."] failed!";

        $this->UpdateFormField("alertMessage", "caption", $this->Translate($caption));
        $this->UpdateFormField("popupAlert", "visible", true);

        $this->SendDebug("<".__FUNCTION__.">", $caption, 0);
        IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $caption);
      }
    }

  }


  private function loadListDevices(string $mode) : void {

    if ($mode == self::MODE_CONFIG_INITIAL) {
      $Groups = $this->getListGroups();

      $ID = count($Groups)+1;
      $this->SetBuffer("groupID", $ID);

      //Set fields
      $this->UpdateFormField("createID", "value", $ID);
      $this->UpdateFormField("addGroup", "value", vtNoString);
    }
    $this->UpdateFormField("createMessage", "caption", vtNoString);

    $buffer  = $this->getListDevices();
    $Devices = [];

    foreach ($buffer as $item) {
      $color = ($item['online']) ? vtNoString : self::ROW_COLOR_OFFLINE;

      $Devices[] = [
        'UUID'     => $item['UUID'],
        'name'     => $item['name'],
        'online'   => $item['online'],
        'value'    => $item['value'],
        'rowColor' => $color
      ];
    }

    $this->UpdateFormField("addDevices", "values", json_encode($Devices));

  }


  private function createGroup(object $List, string $name) : void {

    //Default
    $success = false;

    $ID = (int)$this->GetBuffer("groupID");
    $i  = 0;

    if ($ID == 17 || empty(trim($name))) {
      $caption = ($ID == 17) ? "Maximum number [16] of groups is exceeded!" : "Please enter a valid group name!"; 

      $this->UpdateFormField("alertMessage", "caption", $this->Translate($caption));
      $this->UpdateFormField("popupAlert", "visible", true);

      return;
    }

    $this->UpdateFormField("createRefresh", "visible", false);
    $this->UpdateFormField("createProgress", "visible", true);

    foreach ($List as $line) {
      if ($line['value']) {
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $line['UUID']."|".$line['name']."|".(int)$line['value']);
        $i++;

        $param = [
          'flag'  => 2,
          'args'  => utf8_encode(chr($ID).chr(0x00).$this->lightifyBase->UUIDtoChr($line['UUID']).chr(strlen($name)).$name),
          'value' => vtNoValue
        ];

        $result = $this->sendData(classCommand::ADD_DEVICE_TO_GROUP, $param);
        $status = json_decode($result);

        if ($status->flag && $status->code == 0) {
          $success = true;
        }

        usleep(750000);
      }
    }
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $ID);

    $this->UpdateFormField("createProgress", "visible", false);
    $this->UpdateFormField("createRefresh", "visible", true);

    if ($success) {
      $ID++;
      $this->SetBuffer("groupID", $ID);

      //Set fields
      $this->UpdateFormField("createID", "value", $ID);
    }

  }


  private function addDeviceList(object $List) : void {

    $name   = $List['name'];
    $online = $List['online'];
    $value  = $List['value'];

    if (!$online) {
      $caption = $this->Translate("Device")." [".$name."] ".$this->Translate("is offline and cannot be added to a group!");

      $this->UpdateFormField("alertMessage", "caption", $caption);
      $this->UpdateFormField("popupAlert", "visible", true);

      foreach($List as $line) {
        $value = ($line['UUID'] == $List['UUID']) ? !$line['value'] : $line['value'];
        $color = ($line['online']) ? "" : self::ROW_COLOR_OFFLINE;

        $Devices[] = [
          'UUID'     => $line['UUID'],
          'name'     => $line['name'],
          'online'   => $line['online'],
          'value'    => $value,
          'rowColor' => $color
        ];
      }

      $this->UpdateFormField("addDevices", "values", json_encode($Devices));
    }

  }


  protected function getListDevices() : array {
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_DEVICES_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

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
              'value'  => false,
              'Groups' => $device->Groups
            ];
            break;
        }
      }
    }

    return $List;
  }


  protected function getListGroups() : array {
    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    $data = json_decode($data);
    $List = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        $List[] = [
          'ID'   => $group->ID,
          'name' => $group->name
        ];
      }
    }

    return $List;
  }


  protected function getGatewayDevices(int $id) : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_DEVICES_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    $data = json_decode($data);
    $Devices = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $device) {
        $type   = $device->type;
        $module = vtNoString;
        $label  = vtNoString;
        $model  = vtNoString;
        $UUID   = $device->UUID;
        $known  = true;

        //Decode device info
        switch ($type) {
          case classConstant::TYPE_FIXED_WHITE:
            $label = self::LABEL_FIXED_WHITE;
            break;

          case classConstant::TYPE_LIGHT_CCT:
            $label = self::LABEL_LIGHT_CCT;

          case classConstant::TYPE_LIGHT_DIMABLE:
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
            $module = "Light";

            if ($label == vtNoString) {
              $label = self::LABEL_LIGHT_EXT_COLOR;
            }
            break;

          case classConstant::TYPE_PLUG_ONOFF:
            $module = "Plug";
            $label  = self::LABEL_PLUG_ONOFF;
            break;

          case classConstant::TYPE_SENSOR_MOTION:
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
            if ($module == vtNoString) {
              $module = "Switch";
            }

            if ($label == vtNoString) {
              $label = self::LABEL_SWITCH_4WAY;
            }
           break;

          case classConstant::TYPE_ALL_DEVICES:
            $module = "All Devices";
            $UUID   = vtNoString;
            $label  = self::LABEL_ALL_DEVICES;
            break;

          default:
            $known = false;

            $this->SendDebug("<".__FUNCTION__.">", "Device type <".$type."> unknown!", 0);
            IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", "Device type <".$type."> unknown!");
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

          if ($gateway->name == strtoupper(IPS_GetProperty($id, "serialNumber"))) {
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
          'ID'       => $device->ID,
          'module'   => $module,
          'type'     => $type,
          'zigBee'   => $device->zigBee,
          'UUID'     => $UUID,
          'name'     => $device->name,
          'label'    => $label,
          'model'    => $model,
          'firmware' => $device->firmware
        ];
      }

      $this->SendDebug("<".__FUNCTION__.">", json_encode($Devices), 0);
    }

    return $Devices;

  }


  protected function getGatewayGroups() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_GROUPS_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    $data = json_decode($data);
    $Groups = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $group) {
        $Groups[] = [
          'ID'     => $group->ID,
          'module' => "Group",
          'name'   => $group->name
        ];
      }

      $this->SendDebug("<".__FUNCTION__.">", json_encode($Groups), 0);
    }

    return $Groups;

  }


  protected function getGatewayScenes() : array {

    $data = $this->SendDataToParent(json_encode([
      'DataID' => classConstant::TX_GATEWAY,
      'method' => classConstant::GET_SCENES_LOCAL])
    );
    //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $data);

    $data   = json_decode($data);
    $Scenes = [];

    if (is_array($data) && count($data) > 0) {
      foreach ($data as $scene) {
        $Scenes[] = [
          'ID'     => $scene->ID,
          'module' => "Scene",
          'name'   => $scene->name,
        ];
      }

      $this->SendDebug("<".__FUNCTION__.">", json_encode($Scenes), 0);
    }

    return $Scenes;

  }


  private function getDevicesConfigurator(array $buffer): array {

    if (count($buffer) > 0) {
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $this->ReadAttributeString("listLocations"));
      $Devices = [];

      if (empty($Locations)) {
        $Locations[0]['ID'] = 0;
        $Locations[1]['ID'] = 0;
        $Locations[2]['ID'] = 0;
        $Locations[3]['ID'] = 0;
      }

      foreach ($buffer as $item) {
        $instanceID = $this->lightifyBase->getInstanceByUUID(classConstant::MODULE_DEVICE, $item['UUID']);

        switch ($item['module']) {
          case "Light":
          case "All Devices":
            $location = $this->getCategoryPath($Locations[0]['ID']);
            break;

          case "Plug":
            $location = $this->getCategoryPath($Locations[1]['ID']);
            break;

          case "Sensor":
            $location = $this->getCategoryPath($Locations[2]['ID']);
            break;

          case "Dimmer":
          case "Switch":
            $location = $this->getCategoryPath($Locations[3]['ID']);
            break;

          default:
            $location = 0;
        }
        //IPS_LogMessage("<SymconOSR|".__FUNCTION__.">", $item['module']."|".json_encode($location));

        $device = [
          'id'         => self::DEVICE_ITEM_INDEX+$item['ID'],
          'ID'         => $item['ID'],
          'name'       => $item['name'],
          'module'     => $this->Translate($item['module']),
          'name'       => $item['name'],
          'label'      => $this->Translate($item['label']),
          'model'      => $item['model'],
          'UUID'       => $item['UUID'],
          'zigBee'     => $item['zigBee'],
          'firmware'   => $item['firmware'],
          'instanceID' => $instanceID
        ];

        $device['create'] = [
          'moduleID' => classConstant::MODULE_DEVICE,
          'configuration' => [
            'module' => $item['module'],
            'type'   => $item['type'],
            'UUID'   => $item['UUID']
          ],
          'location' => $location
        ];

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
        $instanceID = $this->lightifyBase->getInstanceByID(classConstant::MODULE_GROUP, $item['ID']);

        $group = [
          'id'         => self::GROUP_ITEM_INDEX+$item['ID'],
          'ID'         => $item['ID'],
          'module'     => $this->Translate($item['module']),
          'name'       => $item['name'],
          'label'      => $this->Translate(self::LABEL_GROUP_ONOFF),
          'model'      => vtNoString,
          'UUID'       => vtNoString,
          'zigBee'     => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $group['create'] = [
          'moduleID' => classConstant::MODULE_GROUP,
          'configuration' => [
            'ID'     => $item['ID'],
            'module' => $item['module']
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
      $Locations = json_decode($this->ReadAttributeString("listLocations"), true);
      $location  = (empty($Locations)) ? 0 : $this->getCategoryPath($Locations[5]['ID']);
      $Scenes = [];

      foreach ($buffer as $item) {
        $instanceID = $this->lightifyBase->getInstanceByID(classConstant::MODULE_SCENE, $item['ID']);
        $info = (empty($item['info'])) ? vtNoString : "[".$item['info']."]²";

        $scene = [
          'id'         => self::SCENE_ITEM_INDEX+$item['ID'],
          'ID'         => $item['ID'],
          'module'     => $this->Translate($item['module']),
          'name'       => $item['name'],
          'label'      => $this->Translate(self::LABEL_SCENE_APPLY),
          'model'      => vtNoString,
          'UUID'       => vtNoString,
          'zigBee'     => vtNoString,
          'firmware'   => vtNoString,
          'instanceID' => $instanceID
        ];

        $scene['create'] = [
          'moduleID' => classConstant::MODULE_SCENE,
          'configuration' => [
            'ID'     => $item['ID'],
            'module' => $item['module']
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


  private function getCategoryPath(int $categoryID): array {

    if ($categoryID === 0) {
      return [];
    }

    $path[] = IPS_GetName($categoryID);
    $id = IPS_GetObject($categoryID)['ParentID'];

    while ($id > 0) {
      $path[] = IPS_GetName($id);
      $id = IPS_GetObject($id)['ParentID'];
    }

    return array_reverse($path);

  }


}
