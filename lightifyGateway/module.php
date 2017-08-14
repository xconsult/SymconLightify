<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyClass.php");
require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyConnect.php");


class lightifyGateway extends IPSModule {

	//Instance specific
	const TIMER_SYNC_LOCAL       = 10;
	const TIMER_SYNC_LOCAL_MIN   = 3;

	const TIMER_MODE_ON          = true;
	const TIMER_MODE_OFF         = false;

	const LIST_CATEGORY_INDEX    = 9;
	const LIST_DEVICE_INDEX      = 12;
	const LIST_GROUP_INDEX       = 13;
	const LIST_SCENE_INDEX       = 14;

	const MAX_DEVICE_SYNC        = 50;
	const MAX_GROUP_SYNC         = 16;

	const DEBUG_DISABLED         = 0;
	const DEBUG_SEND_BUFFER      = 3;
	const DEBUG_RECV_BUFFER      = 7;
	const DEBUG_SEND_RECV        = 13;
	const DEBUG_DETAIL_ERRORS    = 17;

	//Cloud connection specific
	const GATEWAY_SERIAL_LENGTH  = 11;

	const TIME_SESSION_TOKEN     = 840; //seconds - time-out 14 min
	const TIME_CLOUD_REQUEST     = 60;  //seconds

	const HEADER_CONTENT_TYPE    = "Content-Type: application/json";
	const HEADER_AUTHORIZATION   = "authorization: ";
	const RESSOURCE_DEVICE_LIST  = "/devices";
	const RESSOURCE_GROUP_LIST   = "/groups";

	const RESOURCE_SESSION       = "/session";
	const LIGHTIFY_EUROPE        = "https://eu.lightify-api.org/lightify/services";
	const LIGHTIFY_USA           = "https://us.lightify-api.org/lightify/services";

	const INVALID_CREDENTIALS    = 5001;
	const INVALID_SECURITY_TOKEN = 5003;
	const GATEWAY_OFFLINE        = 5019;


	private $lightifyBase    = null;
	private $lightifyConnect = null;

	private $deviceCategory;
	private $sensorCategory;
	private $GroupsCategory;
	private $ScenesCategory;

	private $createDevice;
	private $createSensor;

	private $createGroup;
	private $createScene;

	private $syncDevice;
	private $syncSensor;

	private $syncGroup;
	private $syncScene;

	private $connect;
	private $debug;
	private $message;


	public function __construct($InstanceID) {
		parent::__construct($InstanceID);
		$this->lightifyBase = new lightifyBase;
	}


	public function Create() {
		parent::Create();

		//Store at runtime
		$this->SetBuffer("deviceList", osrConstant::NO_STRING);
		$this->SetBuffer("groupList", osrConstant::NO_STRING);
		$this->SetBuffer("sceneList", osrConstant::NO_STRING);
		$this->SetBuffer("localDevice", osrConstant::NO_STRING);
		$this->SetBuffer("localGroup", osrConstant::NO_STRING);
		$this->SetBuffer("deviceGroup", osrConstant::NO_STRING);
		$this->SetBuffer("groupDevice", osrConstant::NO_STRING);
		$this->SetBuffer("cloudDevice", osrConstant::NO_STRING);
		$this->SetBuffer("cloudGroup", osrConstant::NO_STRING);
		$this->SetBuffer("cloudScene", osrConstant::NO_STRING);

		$this->RegisterPropertyBoolean("open", false);
		$this->RegisterPropertyInteger("connectMode", osrConstant::CONNECT_LOCAL_ONLY);

		//Cloud credentials
		$this->RegisterPropertyString("userName", osrConstant::NO_STRING);
		$this->RegisterPropertyString("password", osrConstant::NO_STRING);
		$this->RegisterPropertyString("serialNumber", osrConstant::NO_STRING);

		//Local gateway
		$this->RegisterPropertyString("host", osrConstant::NO_STRING);
		$this->RegisterPropertyInteger("localUpdate", self::TIMER_SYNC_LOCAL);
		$this->RegisterTimer("localTimer", 0, "OSR_getLightifyData($this->InstanceID, 1201, 1604);");

		//Global settings
		$this->RegisterPropertyString("categoryList", osrConstant::NO_STRING);
		$this->RegisterPropertyString("deviceList", osrConstant::NO_STRING);
		$this->RegisterPropertyString("groupList", osrConstant::NO_STRING);
		$this->RegisterPropertyString("sceneList", osrConstant::NO_STRING);
		$this->RegisterPropertyBoolean("deviceInfo", false);

		$this->RegisterPropertyInteger("debug", self::DEBUG_DISABLED);
		$this->RegisterPropertyBoolean("message", false);

		//Create profiles
		if (IPS_VariableProfileExists("OSR.Hue") === false) {
			IPS_CreateVariableProfile("OSR.Hue", osrConstant::IPS_INTEGER);
			IPS_SetVariableProfileIcon("OSR.Hue", "Shift");
			IPS_SetVariableProfileDigits("OSR.Hue", 0);
			IPS_SetVariableProfileText("OSR.Hue", "", "°");
			IPS_SetVariableProfileValues("OSR.Hue", osrConstant::HUE_MIN, osrConstant::HUE_MAX, 1);
		}

		if (IPS_VariableProfileExists("OSR.ColorTemp") === false) {
			IPS_CreateVariableProfile("OSR.ColorTemp", osrConstant::IPS_INTEGER);
			IPS_SetVariableProfileIcon("OSR.ColorTemp", "Sun");
			IPS_SetVariableProfileDigits("OSR.ColorTemp", 0);
			IPS_SetVariableProfileText("OSR.ColorTemp", "", "K");
			IPS_SetVariableProfileValues("OSR.ColorTemp", osrConstant::CTEMP_CCT_MIN, osrConstant::CTEMP_CCT_MAX, 1);
		}

		if (IPS_VariableProfileExists("OSR.ColorTempExt") === false) {
			IPS_CreateVariableProfile("OSR.ColorTempExt", osrConstant::IPS_INTEGER);
			IPS_SetVariableProfileIcon("OSR.ColorTempExt", "Sun");
			IPS_SetVariableProfileDigits("OSR.ColorTempExt", 0);
			IPS_SetVariableProfileText("OSR.ColorTempExt", "", "K");
			IPS_SetVariableProfileValues("OSR.ColorTempExt", osrConstant::CTEMP_COLOR_MIN, osrConstant::CTEMP_COLOR_MAX, 1);
		}

		if (IPS_VariableProfileExists("OSR.Intensity") === false) {
			IPS_CreateVariableProfile("OSR.Intensity", osrConstant::IPS_INTEGER);
			IPS_SetVariableProfileDigits("OSR.Intensity", 0);
			IPS_SetVariableProfileText("OSR.Intensity", "", "%");
			IPS_SetVariableProfileValues("OSR.Intensity", osrConstant::INTENSITY_MIN, osrConstant::INTENSITY_MAX, 1);
		}

		if (IPS_VariableProfileExists("OSR.Switch") === false) {
			IPS_CreateVariableProfile("OSR.Switch", osrConstant::IPS_BOOLEAN);
			IPS_SetVariableProfileIcon("OSR.Switch", "Power");
			IPS_SetVariableProfileDigits("OSR.Switch", 0);
			IPS_SetVariableProfileValues("OSR.Switch", 0, 1, 0);
			IPS_SetVariableProfileAssociation("OSR.Switch", true, "On", "", 0xFF9200);
			IPS_SetVariableProfileAssociation("OSR.Switch", false, "Off", "", -1);
		}

		if (IPS_VariableProfileExists("OSR.Scene") === false) {
			IPS_CreateVariableProfile("OSR.Scene", osrConstant::IPS_INTEGER);
			IPS_SetVariableProfileIcon("OSR.Scene", "Power");
			IPS_SetVariableProfileDigits("OSR.Scene", 0);
			IPS_SetVariableProfileValues("OSR.Scene", 1, 1, 0);
			IPS_SetVariableProfileAssociation("OSR.Scene", 1, "On", "", 0xFF9200);
		}
	}


	public function ApplyChanges() {
		parent::ApplyChanges();
		$this->SetBuffer("connectTime", osrConstant::NO_STRING);

		$open    = $this->ReadPropertyBoolean("open");
		$connect = $this->ReadPropertyInteger("connectMode");
		$result  = $this->configCheck($open, $connect);

		$localUpdate = ($result && $open) ? $this->ReadPropertyInteger("localUpdate")*1000 : 0;
		$this->SetTimerInterval("localTimer", $localUpdate);

		if ($result) {
			$this->SetBuffer("timerMode", self::TIMER_MODE_ON);
			$this->getLightifyData(osrConstant::METHOD_APPLY_LOCAL, osrConstant::GET_GATEWAY_LOCAL);
		}
	}


	public function GetConfigurationForm() {
		$deviceList = $this->GetBuffer("deviceList");
		$formDevice = "";

		if (empty($deviceList) === false && ord($deviceList{0}) > 0) {
			$formDevice = '
				{ "type": "Label",        "label": "----------------------------------------------- Registrierte Geräte/Gruppen/Szenen --------------------------------------" },
				{ "type": "List",         "name":  "deviceList",        "caption": "Devices",
					"columns": [
						{ "label": "ID",          "name": "deviceID",     "width": "30px"  },
						{ "label": "Class",       "name": "classInfo",    "width": "65px"  },
						{ "label": "Name",        "name": "deviceName",   "width": "110px" }';

			$cloudDevice = $this->GetBuffer("cloudDevice");
			$formDevice  = (empty($cloudDevice) === false) ? $formDevice.',
				{ "label": "Manufacturer",    "name": "manufacturer", "width": "80px"  },
				{ "label": "Model",           "name": "deviceModel",  "width": "130px" },
				{ "label": "Capabilities",    "name": "deviceLabel",  "width": "175px" },
				{ "label": "Firmware",        "name": "firmware",     "width": "65px"  }' : $formDevice;

			$formDevice .= ']},';
		}

		$groupList = $this->GetBuffer("groupList");
		$formGroup = (empty($groupList) === false && ord($groupList{0}) > 0) ? '
			{ "type": "List",           "name":  "groupList",         "caption": "Groups",
				"columns": [
					{ "label": "ID",          "name": "groupID",      "width": "30px"  },
					{ "label": "Class",       "name": "classInfo",    "width": "65px"  },
					{ "label": "Name",        "name": "groupName",    "width": "220px" },
					{ "label": "Info",        "name": "information",  "width": "70px"  }
				]
		},' : "";

		$sceneList = $this->GetBuffer("sceneList");
		$formScene = (empty($sceneList) === false && ord($sceneList{0}) > 0) ? '
			{ "type": "List",           "name":  "sceneList",         "caption": "Scenes",
				"columns": [
					{ "label": "ID",          "name": "sceneID",      "width": "30px"  },
					{ "label": "Class",       "name": "classInfo",    "width": "65px"  },
					{ "label": "Name",        "name": "sceneName",    "width": "110px" },
					{ "label": "Group",       "name": "groupName",    "width": "110px" },
					{ "label": "Info",        "name": "information",  "width": "70px"  }
				]
		},' : "";

		$formJSON = '{
			"elements": [
				{ "type": "CheckBox",     "name": "open",               "caption": " Open" },
				{ "type": "Select",       "name": "connectMode",        "caption": "Connection",
					"options": [
						{ "label": "Local only",      "value": 1001 },
						{ "label": "Local and Cloud", "value": 1002 }
					]
				},
				{ "name": "host",         "type":  "ValidationTextBox", "caption": "IP address"          },
				{ "name": "localUpdate",  "type":  "NumberSpinner",     "caption": "Update interval [s]" },
				{ "type": "Label",        "label": "------------------------------------------- Cloud Anmeldeinformationen (optional) ---------------------------------------" },
				{ "name": "userName",     "type":  "ValidationTextBox", "caption": "Username"            },
				{ "name": "password",     "type":  "PasswordTextBox",   "caption": "Password"            },
				{ "name": "serialNumber", "type":  "ValidationTextBox", "caption": "Serial number"       },
				{ "type": "Label",        "label": "----------------------------------------------------------- Auswahl ------------------------------------------------------------" },
				{ "type": "List",         "name":  "categoryList",      "caption": "Categories",
					"columns": [
						{ "label": "Type",        "name": "Device",     "width": "55px"  },
						{ "label": "Category",    "name": "Category",   "width": "265px" },
						{ "label": "Category ID", "name": "categoryID", "width": "10px", "visible": false,
							"edit": {
								"type": "SelectCategory"
							}
						},
						{ "label": "Sync",        "name": "Sync",       "width": "35px" },
						{ "label": "Sync ID",     "name": "syncID",     "width": "10px", "visible": false,
							"edit": {
								"type": "CheckBox", "caption": " Synchronise values"
							}
						}
					]
				},
				{ "type": "CheckBox",     "name": "deviceInfo",         "caption": " Show device specific informations (UUID, Manufacturer, Model, Capabilities, ZigBee, Firmware)" },
				'.$formDevice.'
				'.$formGroup.'
				'.$formScene.'
				{ "type": "Label",        "label": "----------------------------------------------------------------------------------------------------------------------------------" },
				{ "type": "Select", "name": "debug", "caption": "Debug",
					"options": [
						{ "label": "Disabled",            "value": 0  },
						{ "label": "Send buffer",         "value": 3  },
						{ "label": "Receive buffer",      "value": 7  },
						{ "label": "Send/Receive buffer", "value": 13 },
						{ "label": "Detailed error log",  "value": 17 }
					]
				},
				{ "type": "CheckBox",     "name":  "message",           "caption": " Messages" },
				{ "type": "Label",        "label": "----------------------------------------------------------------------------------------------------------------------------------" }
			],
			"actions": [
				{ "type": "Label",  "label": "Drücken Sie Erstellen / Aktualisieren, um die am Gateway registierten Geräte/Gruppen und Einstellungen automatisch anzulegen" },
				{ "type": "Button", "label": "Create / Update", "onClick": "OSR_getLightifyData($id, 1207, 1604)" }
			],
			"status": [
				{ "code": 102, "icon": "active",   "caption": "Gateway is open"                     },
				{ "code": 104, "icon": "inactive", "caption": "Enter all required informations"     },
				{ "code": 201, "icon": "inactive", "caption": "Gateway is closed"                   },
				{ "code": 205, "icon": "error",    "caption": "Invalid IP address"                  },
				{ "code": 207, "icon": "error",    "caption": "Minimum update interval = 3 seconds" },
				{ "code": 202, "icon": "error",    "caption": "Invalid Serial number!"              },
				{ "code": 203, "icon": "error",    "caption": "Enter a valid Username"              },
				{ "code": 204, "icon": "error",    "caption": "Enter a Password"                    },
				{ "code": 299, "icon": "error",    "caption": "Unknown error"                       }
			]
		}';

		//Categories list element
		$data  = json_decode($formJSON);
		$Types = array("Gerät", "Sensor", "Gruppe", "Szene");

		//Only add default element if we do not have anything in persistence
		if (empty($this->ReadPropertyString("categoryList"))) {
			foreach ($Types as $item) {
				$data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
					'Device'     => $item,
					'categoryID' => 0, 
					'Category'   => "select ...",
					'Sync'       => "no",
					'syncID'     => false
				);
			}
		} else {
			//Annotate existing elements
			$categoryList = json_decode($this->ReadPropertyString("categoryList"));

			foreach ($categoryList as $index => $row) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				if ($row->categoryID && IPS_ObjectExists($row->categoryID)) {
					$data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
						'Device'   => $Types[$index],
						'Category' => IPS_GetName(0)."\\".IPS_GetLocation($row->categoryID),
						'Sync'     => ($row->syncID) ? "ja" : "nein"
					);
				} else {
					$data->elements[self::LIST_CATEGORY_INDEX]->values[] = array(
						'Device'   => $Types[$index],
						'Category' => "wählen ...",
						'Sync'     => "nein"
					);
				}
			}
		}

		//Device list element
		if (empty($formDevice) === false) {
			$ncount     = ord($deviceList{0});
			$deviceList = substr($deviceList, 1);

			for ($i = 1; $i <= $ncount; $i++) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				$Devices    = json_decode($cloudDevice);
				$deviceID   = ord($deviceList{0});
				$deviceList = substr($deviceList, 1);

				$uint64     = substr($deviceList, 0, osrConstant::UUID_DEVICE_LENGTH);
				$UUID       = $this->lightifyBase->chrToUUID($uint64);
				$deviceName = trim(substr($deviceList, 8, osrConstant::DATA_NAME_LENGTH));
				$classInfo  = trim(substr($deviceList, 23, osrConstant::DATA_CLASS_INFO));

				$arrayList  = array(
					'deviceID'   => $deviceID,
					'classInfo'  => $classInfo,
					'deviceName' => $deviceName
				);

				if (empty($Devices) === false) {
					foreach ($Devices as $device) {
						list($cloudID, $deviceType, $manufacturer, $deviceModel, $bmpClusters, $zigBee, $firmware) = $device;
						$deviceLabel = (empty($bmpClusters)) ? "" : implode(" ", $bmpClusters);
	
						if ($deviceID == $cloudID) {
							$arrayList = $arrayList + array(
								'manufacturer' => $manufacturer,
								'deviceModel'  => $deviceModel,
								'deviceLabel'  => $deviceLabel,
								'firmware'     => $firmware
							);
							break;
						}
					}
				}

				$data->elements[self::LIST_DEVICE_INDEX]->values[] = $arrayList;
				$deviceList = substr($deviceList, osrConstant::DATA_DEVICE_LIST);
			}
		}

		//Group list element
		if (empty($formGroup) === false) {
			$ncount    = ord($groupList{0});
			$groupList = substr($groupList, 1);

			for ($i = 1; $i <= $ncount; $i++) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				$groupID     = ord($groupList{0});
				$intUUID     = $groupList{0}.$groupList{1}.chr(osrConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
				$UUID        = $this->lightifyBase->chrToUUID($intUUID);
				$groupName   = trim(substr($groupList, 2, osrConstant::DATA_NAME_LENGTH));

				$dcount      = ord($groupList{18});
				$information = ($dcount == 1) ? $dcount." Gerät" : $dcount." Geräte";

				$data->elements[self::LIST_GROUP_INDEX]->values[] = array(
					'groupID'     => $groupID,
					'classInfo'   =>  "Gruppe",
					'groupName'   => $groupName,
					'information' => $information
				);

				$groupList = substr($groupList, osrConstant::DATA_GROUP_LIST);
			}
		}

		//Scene list element
		if (empty($formScene) === false) {
			$ncount    = ord($sceneList{0});
			$sceneList = substr($sceneList, 1);

			for ($i = 1; $i <= $ncount; $i++) {
				//We only need to add annotations. Remaining data is merged from persistance automatically.
				//Order is determinted by the order of array elements
				$intUUID   = $sceneList{0}.chr(0x00).chr(osrConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
				$sceneID   = ord($sceneList{0});
				$UUID      = $this->lightifyBase->chrToUUID($intUUID);
				$sceneName = trim(substr($sceneList, 1, osrConstant::DATA_NAME_LENGTH));
				$groupName = trim(substr($sceneList, 15, osrConstant::DATA_NAME_LENGTH));

				$dcount      = ord($sceneList{31});
				$information = ($dcount == 1) ? $dcount." Gerät" : $dcount." Geräte";

				$data->elements[self::LIST_SCENE_INDEX]->values[] = array(
					'sceneID'     => $sceneID,
					'classInfo'   => "Szene",
					'sceneName'   => $sceneName,
					'groupName'   => $groupName,
					'information' => $information
				);

				$sceneList = substr($sceneList, osrConstant::DATA_SCENE_LIST);
			}
		}

		return json_encode($data);
	}


	public function ForwardData($jsonString) {
		$data = json_decode($jsonString);

		switch ($data->Method) {
			case osrConstant::METHOD_LOAD_LOCAL:
				$this->getLightifyData(osrConstant::METHOD_LOAD_LOCAL, osrConstant::GET_GATEWAY_LOCAL);
				break;

			case osrConstant::METHOD_LOAD_CLOUD:
				if ($this->ReadPropertyInteger("connectMode") == osrConstant::CONNECT_MODE_BOTH) {
					$args = array(
						'Resource' => $data->Buffer,
						'Method'   => "GET",
						'Buffer'   => null
					);

					$this->cloudRequest(json_encode($args));
				}
				break;

			case osrConstant::METHOD_APPLY_CHILD:
				$jsonReturn = osrConstant::NO_STRING;

				if ($this->ReadPropertyBoolean("open")) {
					switch ($data->Mode) {
						case osrConstant::MODE_DEVICE_LOCAL:
							$localDevice = $this->GetBuffer("localDevice");

							if (empty($localDevice) === false && ord($localDevice{0}) > 0) {
								$jsonReturn = json_encode(array(
									'Buffer'  => utf8_encode($localDevice),
									'Debug'   => $this->debug,
									'Message' => $this->message)
								);
							}
							return $jsonReturn;

						case osrConstant::MODE_DEVICE_CLOUD:
							$cloudDevice = $this->GetBuffer("cloudDevice");

							if (empty($cloudDevice) === false) {
								$jsonReturn = json_encode(array(
									'Buffer'  => $cloudDevice,
									'Debug'   => $this->debug,
									'Message' => $this->message)
								);
							}
							return $jsonReturn;

						case osrConstant::MODE_GROUP_LOCAL:
							$groupDevice = $this->GetBuffer("groupDevice");
							$localGroup  = $this->GetBuffer("localGroup");
							$ncount      = $localGroup{0};

							if (empty($localGroup) === false && ord($ncount) > 0) {
								$itemType = $localGroup{1};

								$jsonReturn = json_encode(array(
									'Buffer'  => utf8_encode($ncount.$itemType.$groupDevice),
									'Debug'   => $this->debug,
									'Message' => $this->message)
								);
							}
							return $jsonReturn;

						case osrConstant::MODE_GROUP_SCENE:
							$cloudScene = $this->GetBuffer("cloudScene");

							if (empty($cloudScene) === false && ord($cloudScene{0}) > 0) {
								$jsonReturn = json_encode(array(
									'Buffer'  => utf8_encode($cloudScene),
									'Debug'   => $this->debug,
									'Message' => $this->message)
								);
							}
							return $jsonReturn;
					}
				}
		}

		return true;
	}


	private function configCheck($open, $connect) {
		$localUpdate = $this->ReadPropertyInteger("localUpdate");
		$filterIP    = filter_var($this->ReadPropertyString("host"), FILTER_VALIDATE_IP);

		if ($connect == osrConstant::CONNECT_LOCAL_CLOUD) {
			$serialNumber	= $this->ReadPropertyString("serialNumber");

			if ($this->ReadPropertyString("userName") == "") {
				$this->SetStatus(203);
				return false;
			}
			if ($this->ReadPropertyString("password") == "") {
				$this->SetStatus(204);
				return false;
			}

			if (strlen($serialNumber) != self::GATEWAY_SERIAL_LENGTH) {
				$this->SetStatus(202);
				return false;
			}
		}

		if ($filterIP) {
			if ($localUpdate < self::TIMER_SYNC_LOCAL_MIN) {
				$this->SetStatus(207);
				return false;
			}
		} else {
			$this->SetStatus(205); //IP error
			return false;
		}

		if ($open)
			$this->SetStatus(102);
		else
			$this->SetStatus(201);

		return true;
	}


	private function setEnvironment() {
		if ($categories = $this->ReadPropertyString("categoryList")) {
			list( $this->deviceCategory, $this->sensorCategory, $this->groupCategory, $this->sceneCategory
			) = json_decode($this->ReadPropertyString("categoryList"));

			$this->createDevice = ($this->deviceCategory->categoryID > 0) ? true : false;
			$this->createSensor = ($this->sensorCategory->categoryID > 0) ? true : false;
			$this->createGroup  = ($this->groupCategory->categoryID > 0) ? true : false;
			$this->createScene  = ($this->createGroup && $this->sceneCategory->categoryID > 0) ? true : false;

			$this->syncDevice   = ($this->createDevice && $this->deviceCategory->syncID) ? true : false;
			$this->syncSensor   = ($this->createSensor && $this->sensorCategory->syncID) ? true : false;

			$this->syncGroup    = ($this->createGroup && $this->groupCategory->syncID) ? true : false;
			$this->syncScene    = ($this->syncGroup && $this->createScene && $this->sceneCategory->syncID) ? true : false;
		}

		$this->connect = $this->ReadPropertyInteger("connectMode");
		$this->debug   = $this->ReadPropertyInteger("debug");
		$this->message = $this->ReadPropertyBoolean("message");
	}


	private function cloudConnect() {
		if (strtotime($this->GetBuffer("sessionTime")) < time()) {
			$buffer = json_encode(array(
				'serialNumber' => IPS_GetProperty($this->InstanceID, "serialNumber"),
				'username'     => IPS_GetProperty($this->InstanceID, "userName"),
				'password'     => IPS_GetProperty($this->InstanceID, "password"))
			);

			$args = array(
				'Resource' => self::RESOURCE_SESSION,
				'Method'   => "POST",
				'Buffer'   => utf8_encode($buffer)
			);
			$jsonResult = $this->cloudRequest(json_encode($args));
			if ($jsonResult === false) return false;

			//Decode result
			$response = json_decode($jsonResult);

			if (false == ($userID = @$this->GetIDForIdent("USERID"))) {
				$userID = $this->RegisterVariableInteger("USERID", "Cloud user", "", 302);
				IPS_SetDisabled($userID, true);
			}

			if (isset($userID)) {
				if (GetValueInteger($userID) != $response->userId)
					SetValueInteger($userID, (integer)$response->userId);
			}

			//Security token is valid for 15 minutes, connect once 1 minute
			$this->SetBuffer("securityToken", $response->securityToken);
			$this->SetBuffer("sessionTime", date("d.m.Y H:i:s", time()+self::TIME_SESSION_TOKEN));
		}

		return true;
	}


	public function cloudRequest($args) {
		$data = json_decode($args);

		if ($data->Method == "POST" || $this->cloudConnect()) {
			$headers[] = self::HEADER_CONTENT_TYPE;
			$client    = curl_init();

			if ($client !== false) {
				if ($data->Resource != self::RESOURCE_SESSION)
					$headers[] = self::HEADER_AUTHORIZATION.$this->GetBuffer("securityToken");

				if ($data->Method == "POST") {
					curl_setopt($client, CURLOPT_POST, 1);
					curl_setopt($client, CURLOPT_POSTFIELDS, $data->Buffer);
				}

				curl_setopt($client, CURLOPT_URL, self::LIGHTIFY_EUROPE.$data->Resource);
				curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
				curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($client, CURLOPT_TIMEOUT, 5);
				curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
				$jsonResult = curl_exec($client);

				if (strlen($jsonResult) == 0) return false;
				$response = json_decode($jsonResult);
				curl_close($client);

				if (array_key_exists("errorCode", $response)) {
					$error = $response->errorCode.":".$response->errorMessage;

					if ($this->debug % 2) $this->SendDebug("<GATEWAY|CLOUDREQUEST:ERROR>", $error, 0);
					IPS_LogMessage("SymconOSR", "<GATEWAY|CLOUDREQUEST:ERROR>   ".$error);

					return false;
				} else {
					if ($this->debug % 2) $this->SendDebug("<GATEWAY|CLOUDREQUEST:RESULT>", $jsonResult, 0);
					if ($this->message) IPS_LogMessage("GATEWAY|SymconOSR", "<CLOUDREQUEST:RESULT>   ".$jsonResult);

					return $jsonResult;
				}
			}
		}

		return false;
	}


	public function getLightifyData(integer $localMethod, integer $target) {
		$this->setGatewayInfo($localMethod);
		$error = false;

		if ($this->ReadPropertyBoolean("open")) {
			$this->SetTimerInterval("localTimer", 0);

			$localDevice = $this->GetBuffer("localDevice");
			$localGroup  = $this->GetBuffer("localGroup");
			$cloudScene   = $this->GetBuffer("cloudScene");

			if ($this->lightifyConnect) {
				//Get Gateway WiFi configuration
				if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_GATEWAY_WIFI, osrConstant::SCAN_WIFI_CONFIG))) {
					if (strlen($data) >= (2+osrConstant::DATA_WIFI_LENGTH))
						$this->getWiFi(substr($data, 1), ord($data{0}));
				}

				//Get gateway firmware version
				if (isset($this->firmwareID)) {
					if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))) {
						$firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});
						if (GetValueString($this->firmwareID) != $firmware) SetValueString($this->firmwareID, (string)$firmware);
					}
				}

				//Get paired devices
				if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01)))) {
					if (strlen($data) >= (2+osrConstant::DATA_DEVICE_LENGTH)) {
						$localDevice = $this->readData(osrCommand::GET_DEVICE_LIST, $data);
						$localDevice = (ord($localDevice{0}) > 0) ? $localDevice : osrConstant::NO_STRING;
						$this->SetBuffer("localDevice", $localDevice);
					}
				}

				//Get Group/Zone list
				if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_GROUP_LIST, chr(0x00)))) {
					if (strlen($data) >= (2+osrConstant::DATA_GROUP_LENGTH)) {
						$localGroup = $this->readData(osrCommand::GET_GROUP_LIST, $data);
						$localGroup = (ord($localGroup{0}) > 0) ? $localGroup : osrConstant::NO_STRING;
						$this->SetBuffer("localGroup", $localGroup);
					}
				}

				//Get cloud data
				if ($this->connect == osrConstant::CONNECT_LOCAL_CLOUD) {
					$connectTime = $this->GetBuffer("connectTime");

					if (empty($connectTime) || strtotime($connectTime) < time()) {
						if ($this->syncDevice && empty($localDevice) === false) {
							$cloudDevice = $this->readData(osrConstant::GET_DEVICE_CLOUD);
							if ($cloudDevice !== false) $this->SetBuffer("cloudDevice", $cloudDevice);
						}

						if ($this->syncGroup && empty($localGroup) === false) {
							$cloudGroup = $this->readData(osrConstant::GET_GROUP_CLOUD);
							if ($cloudGroup !== false) $this->SetBuffer("cloudGroup", $cloudGroup);

							if ($this->syncScene) {
								$cloudScene = $this->readData(osrConstant::GET_GROUP_SCENE);
								if ($cloudScene !== false) $this->SetBuffer("cloudScene", $cloudScene);
							}
						}

						$this->SetBuffer("connectTime", date("d.m.Y H:i:s", time()+self::TIME_CLOUD_REQUEST));
					}
				}
			}

			//Read Buffer
			$groupDevice = $this->GetBuffer("groupDevice");
			$deviceGroup = $this->GetBuffer("deviceGroup");

			//Create childs
			if ($localMethod == osrConstant::METHOD_CREATE_CHILD) {
				$error = true;

				if ($this->syncDevice || $this->syncGroup) {
					$message = "Instances successfully created";

					if ($this->syncDevice) {
						if (empty($localDevice) === false) {
							$this->createInstance(osrConstant::MODE_CREATE_DEVICE, $localDevice);
							$error = false;
						} else {
							$message = "Creating device instances failed!";
						}
					}

					if ($this->syncGroup) {
						if (empty($localGroup) === false) {
							$this->createInstance(osrConstant::MODE_CREATE_GROUP, $localGroup);
							$error = false;
						} else {
							$message = "Creating group instances failed!";
						}
					}

					if ($this->syncScene) {
						if (empty($cloudScene) === false) {
							$this->createInstance(osrConstant::MODE_CREATE_SCENE, $cloudScene);
							$error = false;
						} else {
							$message = "Creating scene instances failed!";
						}
					}
				} else {
					$message = "Please define type and category to be created.";
				}

				echo $message."\n";
			}

			if ($error === false) {
				$sendMethod = ($localMethod == osrConstant::METHOD_LOAD_LOCAL) ? osrConstant::METHOD_UPDATE_CHILD : osrConstant::METHOD_CREATE_CHILD;

				//Update child informations
				if ($localMethod == osrConstant::METHOD_LOAD_LOCAL || $localMethod == osrConstant::METHOD_CREATE_CHILD) {
					if ($this->syncDevice && empty($localDevice) === false && ord($localDevice{0}) > 0) {
						if (count(IPS_GetInstanceListByModuleID(osrConstant::MODULE_DEVICE)) > 0) {
							$this->SendDataToChildren(json_encode(array(
								'DataID'	=> osrConstant::TX_DEVICE,
								'Connect'	=> $this->connect,
								'Mode'		=> osrConstant::MODE_DEVICE_LOCAL,
								'Method'	=> $sendMethod,
								'Buffer'	=> utf8_encode($localDevice),
								'Debug'		=> $this->debug,
								'Message'	=> $this->message))
							);
						}

						if ($this->connect == osrConstant::CONNECT_LOCAL_CLOUD) {
							if (empty($cloudDevice) === false && ord($cloudDevice{0}) > 0) {
								$this->SendDataToChildren(json_encode(array(
									'DataID'	=> osrConstant::TX_DEVICE,
									'Connect'	=> $this->connect,
									'Mode'		=> osrConstant::MODE_DEVICE_CLOUD,
									'Method'	=> $sendMethod,
									'Buffer'	=> $cloudDevice,
									'Debug'		=> $this->debug,
									'Message'	=> $this->message))
								);
							}
						}
					}

					if ($this->syncGroup && empty($localGroup) === false && ord($localGroup{0}) > 0) {
						if (count(IPS_GetInstanceListByModuleID(osrConstant::MODULE_GROUP)) > 0) {
							$ncount   = $localGroup{0};
							$itemType = $localGroup{1};

							$this->SendDataToChildren(json_encode(array(
								'DataID'	=> osrConstant::TX_GROUP,
								'Connect'	=> $this->connect,
								'Mode'		=> osrConstant::MODE_GROUP_LOCAL,
								'Method'	=> $sendMethod,
								'Buffer'  => utf8_encode($ncount.$itemType.$groupDevice),
								'Debug'		=> $this->debug,
								'Message'	=> $this->message))
							);
						}

						if ($this->connect == osrConstant::CONNECT_LOCAL_CLOUD) {
							if ($this->syncScene && empty($cloudScene) === false && ord($cloudScene{0}) > 0) {
								$this->SendDataToChildren(json_encode(array(
									'DataID'	=> osrConstant::TX_GROUP,
									'Connect'	=> $this->connect,
									'Mode'		=> osrConstant::MODE_GROUP_SCENE,
									'Method'	=> $sendMethod,
									'Buffer'  => utf8_encode($cloudScene),
									'Debug'		=> $this->debug,
									'Message'	=> $this->message))
								);
							}
						}
					}
				}
			}

			//Reset to default and activate timer
			$this->SetTimerInterval("localTimer", $this->ReadPropertyInteger("localUpdate")*1000);
			if ($this->GetBuffer("timerMode") == self::TIMER_MODE_OFF) $this->SetBuffer("timerMode", self::TIMER_MODE_ON);
		}
	}


	private function setGatewayInfo($method) {
		$this->SetEnvironment();

		$firmwareID = @$this->GetIDForIdent("FIRMWARE");
		$ssidID     = @$this->GetIDForIdent("SSID");

		if ($method == osrConstant::METHOD_APPLY_LOCAL) {
			if (false === ($ssidID)) {
				if (false !== ($ssidID = $this->RegisterVariableString("SSID", "SSID", "", 301))) {
					SetValueString($ssidID, "");
					IPS_SetDisabled($ssidID, true);
				}
			}

			if (false === ($portID = @$this->GetIDForIdent("PORT"))) {
				if (false !== ($portID = $this->RegisterVariableInteger("PORT", "Port", "", 303))) {
					SetValueInteger($portID, osrConstant::GATEWAY_PORT);
					IPS_SetDisabled($portID, true);
				}
			}

			if (false === ($firmwareID)) {
				if (false !== ($firmwareID = $this->RegisterVariableString("FIRMWARE", "Firmware", "", 304))) {
					SetValueString($firmwareID, "-.-.-.--");
					IPS_SetDisabled($firmwareID, true);
				}
			}
		}

		if ($this->lightifyConnect = new lightifyConnect($this->InstanceID, $this->ReadPropertyString("host"), $this->debug, $this->message)) {
			//Get Gateway WiFi configuration
			if ($ssidID) {
				if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_GATEWAY_WIFI, osrConstant::SCAN_WIFI_CONFIG))) {
					if (strlen($data) >= (2+osrConstant::DATA_WIFI_LENGTH)) {
						if (false !== ($SSID = $this->getWiFi($data))) {
							if (GetValueString($ssidID) != $SSID) SetValueString($ssidID, (string)$SSID);
						}
					}
				}
			}

			//Get gateway firmware version
			if ($firmwareID) {
				if (false !== ($data = $this->lightifyConnect->sendRaw(osrCommand::GET_GATEWAY_FIRMWARE, chr(0x00)))) {
					$firmware = ord($data{0}).".".ord($data{1}).".".ord($data{2}).".".ord($data{3});
					if (GetValueString($firmwareID) != $firmware) SetValueString($firmwareID, (string)$firmware);
				}
			}
		}
	}


	private function getWiFi($data) {
		$ncount = ord($data{0});
		$data   = substr($data, 1);
		$result = false;

		for ($i = 1; $i <= $ncount; $i++) {
			$profile = trim(substr($data, 0, osrConstant::WIFI_PROFILE_LENGTH-1));
			$SSID    = trim(substr($data, 32, osrConstant::WIFI_SSID_LENGTH));
			$BSSID   = trim(substr($data, 65, osrConstant::WIFI_BSSID_LENGTH));
			$channel = trim(substr($data, 71, osrConstant::WIFI_CHANNEL_LENGTH));

			$ip      = ord($data{77}).".".ord($data{78}).".".ord($data{79}).".".ord($data{80});
			$gateway = ord($data{81}).".".ord($data{82}).".".ord($data{83}).".".ord($data{84});
			$netmask = ord($data{85}).".".ord($data{86}).".".ord($data{87}).".".ord($data{88});
			//$dns_1   = ord($data{89}).".".ord($data{90}).".".ord($data{91}).".".ord($data{92});
			//$dns_2   = ord($data{93}).".".ord($data{94}).".".ord($data{95}).".".ord($data{96});

			if ($this->ReadPropertyString("host") == $ip) {
				$result = $SSID;
				break;
			}

			if (($length = strlen($data)) > osrConstant::DATA_WIFI_LENGTH) $length = osrConstant::DATA_WIFI_LENGTH;
			$data = substr($data, $length);
		}

		return $result;
	}


	private function readData($command, $data = null) {
		switch ($command) {
			case osrCommand::GET_DEVICE_LIST:
				$ncount = ord($data{0})+ord($data{1});
				$data   = substr($data, 2);

				$deviceList   = "";
				$deviceGroup  = "";
				$localDevice = "";

				//Parse devices
				for ($i = 1, $j = 0, $m = 0, $n = 0; $i <= $ncount; $i++) {
					$itemType    = ord($data{10});
					$implemented = true;
					$hasGroup    = false;

					//Decode Device label
					switch ($itemType) {
						case osrConstant::TYPE_FIXED_WHITE:
							//fall through

						case osrConstant::TYPE_LIGHT_CCT:
							//fall through

						case osrConstant::TYPE_LIGHT_DIMABLE:
							//fall through

						case osrConstant::TYPE_LIGHT_COLOR:
							//fall through

						case osrConstant::TYPE_LIGHT_EXT_COLOR:
							$classInfo = "Lampe";
							$hasGroup  = true;
							break;

						case osrConstant::TYPE_PLUG_ONOFF:
							$classInfo = "Steckdose";
							$hasGroup  = true;
							break;

						case osrConstant::TYPE_SENSOR_MOTION:
							$classInfo = "Sensor";
							break;

						case osrConstant::TYPE_DIMMER_2WAY:
							$classInfo   = "Dimmer";
							break;

						case osrConstant::TYPE_SWITCH_4WAY:
							$classInfo   = "Schalter";
							break;

						default:
							$implemented = false;
							$classInfo   = "Unbekannt";

							if ($this->debug % 2 || $this->message) {
								$info = "Type [".$itemType."] not defined!";

								if ($this->debug % 2) $this->SendDebug("<GATEWAY|READDATA|DEVICES:LOCAL>", $info, 0);
								if ($this->message) IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:LOCAL>   ".$info);
							}
					}

					if ($implemented) {
						$deviceID      = $i;
						$localDevice .= chr($deviceID).substr($data, 0, osrConstant::DATA_DEVICE_LENGTH);
						$classInfo     = str_pad($classInfo, osrConstant::DATA_CLASS_INFO, " ", STR_PAD_RIGHT);

						$uint64        = substr($data, 2, osrConstant::UUID_DEVICE_LENGTH);
						$deviceName    = substr($data, 26, osrConstant::DATA_NAME_LENGTH);
						$deviceList   .= chr($deviceID).$uint64.$deviceName.$classInfo;
						$j += 1;

						//Device group
						if ($this->syncDevice && $hasGroup) {
							$deviceGroup .= $uint64.substr($data, 16, 2);
							$n += 1; 
						}
					}

					if (($length = strlen($data)) > osrConstant::DATA_DEVICE_LENGTH) $length = osrConstant::DATA_DEVICE_LENGTH;
						$data = substr($data, $length);
				}

				//Store at runtime
				$this->SetBuffer("deviceList", chr($j).$deviceList);
				$this->SetBuffer("deviceGroup", chr($n).$deviceGroup);

				if ($this->debug % 2 || $this->message) {
					$info = $j."/".$i."/".$this->lightifyBase->decodeData($localDevice);

					if ($this->debug % 2) $this->SendDebug("<GATEWAY|READDATA|DEVICES:LOCAL>", $info, 0);
					if ($this->message) IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:LOCAL>   ".$info);
				}

				//Return device buffer string
				if ($this->syncDevice) return chr($j).chr($i).$localDevice;
				break;

			case osrConstant::GET_DEVICE_CLOUD:
				$args = array(
					'Resource' => self::RESSOURCE_DEVICE_LIST,
					'Method'   => "GET",
					'Buffer'   => null
				);
				$cloudBuffer = $this->cloudRequest(json_encode($args));

				if ($cloudBuffer !== false) {
					$localDevice = $this->GetBuffer("localDevice");
					$ncount      = ord($localDevice{0});
					$localDevice = substr($localDevice, 2);

					for ($i = 1; $i <= $ncount; $i++) {
						$Devices     = json_decode($cloudBuffer);
						$deviceID    = ord($localDevice{0});
						$localDevice = substr($localDevice, 1);
						$deviceName  = trim(substr($localDevice, 26, osrConstant::DATA_NAME_LENGTH));

						foreach ($Devices as $device) {
							if ($deviceID == $device->deviceId) {
								//IPS_LogMessage("SymconOSR", "<READDATA>   ".$deviceID."/".$device->deviceId."/".$deviceName."/".$device->name);

								$zigBee      = dechex(ord($localDevice{0})).dechex(ord($localDevice{1}));
								$deviceModel = strtoupper($device->modelName);

								//Modell mapping
								if (substr($deviceModel, 0, 19) == "CLASSIC A60 W CLEAR")
									$deviceModel = "CLASSIC A60 W CLEAR";

								if (substr($deviceModel, 0, 4) == "PLUG")
									$deviceModel = osrConstant::MODEL_PLUG_ONOFF;

								$cloudDevice[] = array(
									$device->deviceId, $device->deviceType,
									strtoupper($device->manufacturer), $deviceModel,
									$device->bmpClusters,
									$zigBee, $device->firmwareVersion
								);
								break;
							}
						}
						$localDevice = substr($localDevice, osrConstant::DATA_DEVICE_LENGTH);
					}
					$cloudDevice = json_encode($cloudDevice);

					if ($this->debug % 2) {
						$this->SendDebug("<GATEWAY|READDATA|DEVICES:CLOUD>", $cloudBuffer, 0);
						$this->SendDebug("<GATEWAY|READDATA|DEVICES:CLOUD>", $cloudDevice, 0);
					}

					if ($this->message) {
						IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:CLOUD>   ".$cloudBuffer);
						IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|DEVICES:CLOUD>   ".$cloudDevice);
					}

					return $cloudDevice;
				}
				return false;

			case osrCommand::GET_GROUP_LIST:
				$ncount      = ord($data{0})+ord($data{1});
				$data        = substr($data, 2);

				$itemType    = osrConstant::TYPE_DEVICE_GROUP;
				$localGroup  = "";
				$groupDevice = "";
				$groupList   = "";

				for ($i = 1; $i <= $ncount; $i++) {
					$deviceGroup  = $this->GetBuffer("deviceGroup");
					$groupID      = ord($data{0});
					$buffer       = "";

					if (($dcount = ord($deviceGroup{0})) > 0) {
						$deviceGroup = substr($deviceGroup, 1);

						for ($j = 1, $k = 0; $j <= $dcount; $j++) {
							$groups = $this->lightifyBase->decodeGroup(ord($deviceGroup{8}), ord($deviceGroup{9}));
	
							foreach ($groups as $key) {
								if ($groupID == $key) {
									$buffer .= substr($deviceGroup, 0, osrConstant::UUID_DEVICE_LENGTH);
									$k += 1;
									break;
								}
							}
							$deviceGroup = substr($deviceGroup, osrConstant::DATA_GROUP_DEVICE);
						}
					}

					$localGroup  .= substr($data,0, osrConstant::DATA_GROUP_LENGTH);
					$groupDevice .= chr($groupID).chr($k).$buffer;
					$groupList   .= substr($data,0, osrConstant::DATA_GROUP_LENGTH).chr($k);
					//IPS_LogMessage("SymconOSR", "<READDATA>   ".$i."/".$groupID."/".$k."/".$this->lightifyBase->decodeData($buffer));

					if (($length = strlen($data)) > osrConstant::DATA_GROUP_LENGTH) $length = osrConstant::DATA_GROUP_LENGTH;
						$data = substr($data, $length);
				}

				//Store at runtime
				$this->SetBuffer("groupList", chr($ncount).$groupList);
				$this->SetBuffer("groupDevice", $groupDevice);

				if ($this->debug % 2 || $this->message) {
					$info = $ncount."/".$itemType."/".$this->lightifyBase->decodeData($localGroup);

					if ($this->debug % 2) $this->SendDebug("<GATEWAY|READDATA|GROUPS:LOCAL>", $info, 0);
					if ($this->message) IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|GROUPS:LOCAL>   ".$info);
				}

				//Return group buffer string
				if ($this->syncGroup) return chr($ncount).chr($itemType).$localGroup;

			case osrConstant::GET_GROUP_CLOUD:
				$args = array(
					'Resource' => self::RESSOURCE_GROUP_LIST,
					'Method'   => "GET",
					'Buffer'   => null
				);
				$cloudGroup = $this->cloudRequest(json_encode($args));

				if ($this->debug % 2) $this->SendDebug("<GATEWAY|READDATA|GROUPS:CLOUD>", $cloudGroup, 0);
				if ($this->message) IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|GROUPS:CLOUD>   ".$cloudGroup);

				return $cloudGroup;

			case osrConstant::GET_GROUP_SCENE:
				$cloudGroup  = json_decode($this->GetBuffer("cloudGroup"));
				$itemType    = osrConstant::TYPE_GROUP_SCENE;

				$sceneList  = "";
				$cloudScene = "";
				$i = 0;

				foreach ($cloudGroup as $group) {
					$groupScenes = $group->scenes;
					$groupName   = str_pad($group->name, osrConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);

					if (empty($groupScenes) === false) {
						$j = 0;

						foreach ($groupScenes as $sceneID => $sceneName) {
							$sceneName   = str_pad($sceneName, osrConstant::DATA_NAME_LENGTH, " ", STR_PAD_RIGHT);
							$cloudScene .= chr($group->groupId).chr($sceneID).$sceneName;
							$sceneList  .= chr($sceneID).$sceneName.$groupName.chr(count($group->devices));
							$i += 1; $j += 1;
						}
					}
				}

				//Store at runtime
				$this->SetBuffer("sceneList", chr($i).$sceneList);

				if ($this->debug % 2 || $this->message) {
					$info = $i."/".$itemType."/".$this->lightifyBase->decodeData($cloudScene);

					if ($this->debug % 2) $this->SendDebug("<GATEWAY|READDATA|SCENES:CLOUD>", $info, 0);
					if ($this->message) IPS_LogMessage("SymconOSR", "<GATEWAY|READDATA|SCENES:CLOUD>   ".$info);
				}
				return chr($i).chr($itemType).$cloudScene;
		}

		return chr(00)."";
	}


	private function createInstance($mode, $data) {
		$ncount = ord($data{0});

		switch ($mode) {
			case osrConstant::MODE_CREATE_DEVICE:
				$data = substr($data, 2);

				for ($i = 1; $i <= $ncount; $i++) {
					$deviceID  = ord($data{0});
					$data      = substr($data, 1);
					$itemType  = ord($data{10});
					$implemted = true;

					switch ($itemType) {
						case osrConstant::TYPE_PLUG_ONOFF:
							$itemClass  = osrConstant::CLASS_LIGHTIFY_PLUG;
							$sync       = $this->syncDevice;
							$categoryID = ($sync) ? $this->deviceCategory->categoryID : false;
							break;

						case osrConstant::TYPE_SENSOR_MOTION:
							$itemClass  = osrConstant::CLASS_LIGHTIFY_SENSOR;
							$sync       = $this->syncSensor;
							$categoryID = ($sync) ? $this->sensorCategory->categoryID : false;
							break;

						case osrConstant::TYPE_DIMMER_2WAY:
							$implemented = false;
							break;

						case osrConstant::TYPE_SWITCH_4WAY:
							$implemented = false;
							break;

						default:
							$itemClass  = osrConstant::CLASS_LIGHTIFY_LIGHT;
							$sync       = $this->syncDevice;
							$categoryID = ($sync) ? $this->deviceCategory->categoryID : false;
					}

					if ($implemted && $categoryID !== false && IPS_CategoryExists($categoryID)) {
						$uintUUID   = substr($data, 2, osrConstant::UUID_DEVICE_LENGTH);
						$deviceName = trim(substr($data, 26, osrConstant::DATA_NAME_LENGTH));
						$InstanceID = $this->lightifyBase->getObjectByProperty(osrConstant::MODULE_DEVICE, "uintUUID", $uintUUID);
						//IPS_LogMessage("SymconOSR", "<CREATEINSTANCE>   ".$i."/".$deviceID."/".$itemType."/".$deviceName."/".$this->lightifyBase->decodeData($data));

						if ($InstanceID === false) {
							$InstanceID = IPS_CreateInstance(osrConstant::MODULE_DEVICE);

							IPS_SetParent($InstanceID, $categoryID);
							IPS_SetName($InstanceID, (string)$deviceName);
							IPS_SetPosition($InstanceID, 210+$deviceID);

							IPS_SetProperty($InstanceID, "deviceID", (integer)$deviceID);
						}

						if ($InstanceID) {
							if (@IPS_GetProperty($InstanceID, "itemClass") != $itemClass)
								IPS_SetProperty($InstanceID, "itemClass", (integer)$itemClass);

							if (IPS_HasChanges($InstanceID)) IPS_ApplyChanges($InstanceID);
						}
					}

					$data = substr($data, osrConstant::DATA_DEVICE_LENGTH);
				}
				break;

			case osrConstant::MODE_CREATE_GROUP:
				$data       = substr($data, 2);
				$sync       = $this->syncGroup;
				$categoryID = ($sync) ? $this->groupCategory->categoryID : false;

				if ($categoryID !== false && IPS_CategoryExists($categoryID)) {
					for ($i = 1; $i <= $ncount; $i++) {
						$uintUUID   = $data{0}.$data{1}.chr(osrConstant::TYPE_DEVICE_GROUP).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
						$groupID    = ord($data{0});

						$groupName  = trim(substr($data, 2, osrConstant::DATA_NAME_LENGTH));
						$InstanceID = $this->lightifyBase->getObjectByProperty(osrConstant::MODULE_GROUP, "uintUUID", $uintUUID);

						if ($InstanceID === false) {
							$InstanceID = IPS_CreateInstance(osrConstant::MODULE_GROUP);

							IPS_SetParent($InstanceID, $categoryID);
							IPS_SetName($InstanceID, (string)$groupName);
							IPS_SetPosition($InstanceID, 210+$groupID);

							IPS_SetProperty($InstanceID, "itemID", (integer)$groupID);
						}

						if ($InstanceID) {
							if (@IPS_GetProperty($InstanceID, "itemClass") != osrConstant::CLASS_LIGHTIFY_GROUP)
								IPS_SetProperty($InstanceID, "itemClass", osrConstant::CLASS_LIGHTIFY_GROUP);

							if (IPS_HasChanges($InstanceID)) IPS_ApplyChanges($InstanceID);
						}

						$data = substr($data, osrConstant::DATA_GROUP_LENGTH);
					}
				}
				break;

			case osrConstant::MODE_CREATE_SCENE:
				$data       = substr($data, 1);
				$sync       = $this->syncScene;
				$categoryID = ($sync) ? $this->sceneCategory->categoryID : false;

				if ($categoryID !== false && IPS_CategoryExists($categoryID)) {
					$itemType = ord($data{0});
					$data     = substr($data, 1);

					for ($i = 1; $i <= $ncount; $i++) {
						$uintUUID   = $data{1}.chr(0x00).chr(osrConstant::TYPE_GROUP_SCENE).chr(0x0f).chr(0x0f).chr(0x26).chr(0x18).chr(0x84);
						$sceneID    = ord($data{1});

						$sceneName  = trim(substr($data, 2, osrConstant::DATA_NAME_LENGTH));
						$InstanceID = $this->lightifyBase->getObjectByProperty(osrConstant::MODULE_GROUP, "uintUUID", $uintUUID);
						//IPS_LogMessage("SymconOSR", "<CREATEINSTANCE|SCENES>   ".$ncount."/".$itemType."/".ord($data{0})."/".$sceneID."/".$this->lightifyBase->chrToUUID($uintUUID)."/".$sceneName);

						if ($InstanceID === false) {
							$InstanceID = IPS_CreateInstance(osrConstant::MODULE_GROUP);

							IPS_SetParent($InstanceID, $this->sceneCategory->categoryID);
							IPS_SetName($InstanceID, (string)$sceneName);
							IPS_SetPosition($InstanceID, 210+$sceneID);

							IPS_SetProperty($InstanceID, "itemID", (integer)$sceneID);
						}

						if ($InstanceID) {
							if (@IPS_GetProperty($InstanceID, "itemClass") != osrConstant::CLASS_LIGHTIFY_SCENE)
								IPS_SetProperty($InstanceID, "itemClass", osrConstant::CLASS_LIGHTIFY_SCENE);

							if (IPS_HasChanges($InstanceID)) IPS_ApplyChanges($InstanceID);
						}

						$data = substr($data, osrConstant::DATA_SCENE_LENGTH);
					}
				}
				break;
		}
	}

}