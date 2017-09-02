<?

//Socket functions
class lightifyConnect extends stdClass {

	private $lightifyBase;
	private $lightifySocket;

	private $gatewayID;
	private $requestID;

	private $debug;
	private $message;


	public function __construct($gatewayID, $host, $debug = false, $message = false) {
		$this->lightifyBase = new lightifyBase;

		if (false === ($this->lightifySocket = @fsockopen($host, osrConstant::GATEWAY_PORT, $code, $error, 5))) {
			$error = "Socket open failed: ".$error." [".$code."]";	
			throw new Exception($error);
		}

		//Global variables
		$this->$gatewayID = $gatewayID;
		$this->requestID = 0;

		$this->debug   = $debug;
		$this->message = $message;

		//socket options
		stream_set_timeout($this->lightifySocket, 3);
		stream_set_blocking($this->lightifySocket, 1);
		//stream_set_chunk_size($this->lightifySocket, 4096);
	}


	public function sendRaw($command, $flag, $args = null) {
		//$this->requestID = ($this->requestID == osrConstant::REQUESTID_HIGH_VALUE) ? 1 : $this->requestID+1;
		//$data = $flag.chr($command).$this->lightifyBase->getRequestID($this->requestID);

		$data = $flag.chr($command).chr(0x00).chr(0x00).chr(0x00).chr(0x00);
		if ($args != null) $data .= $args;

		$data   = chr(strlen($data)).chr(0x00).$data;
		$length = strlen($data);

		if ($this->debug % 4 || $this->message) {
			$info = strtoupper(dechex($command))."/ ".hexdec($flag)."/ ".$length."/ ".$this->lightifyBase->decodeData($data);

			if ($this->debug % 4) IPS_SendDebug($this->gatewayID, "<SOCKET|CONNECT|SENDRAW:WRITE>", $info, 0);
			if ($this->message) IPS_LogMessage("SymconOSR", "<SOCKET|CONNECT|SENDRAW:WRITE>   ".$info);
		}

		if (false !== ($bytes = @fwrite($this->lightifySocket, $data, $length))) {
			if ($bytes == $length) {
				$buffer = "";

				while(!feof($this->lightifySocket)) {
					if (false !== ($buffer .= @fread($this->lightifySocket, 1024))) { //Read 1024 bytes block
						$metaData = @stream_get_meta_data($this->lightifySocket);
						if ($metaData['unread_bytes'] > 0) continue;
					} else {
						$error = "Socket read data failed!";
					}
					break;
				}
				$length = strlen($buffer);

				if ($this->debug % 3 || $this->message) {
					$info = strtoupper(dechex($command))."/ ".hexdec($flag)."/ ".$length."/ ".$this->lightifyBase->decodeData($buffer);

					if ($this->debug % 3) IPS_SendDebug($this->gatewayID, "<SOCKET|CONNECT|SENDRAW:READ>", $info, 0);
					if ($this->message) IPS_LogMessage("SymconOSR", "<SOCKET|CONNECT|SENDRAW:READ>   ".$info);
				}

				///Handle read buffer
				$bytes = osrConstant::BUFFER_HEADER_LENGTH+1;

				if ($length >= $bytes) {
					$code = ord($buffer{8});
					if ($code == 0) return (string)substr($buffer, $bytes, $length-$bytes);

					$error = "Receive buffer error [".$code."]";
				} else {
					$error = "Receive buffer has wrong size [".$length."]";
				}
			} else {
				$error = "Write returned wrong size [".$bytes."]";
			}
		} else {
			$error = "Socket write data failed!";
		}

		IPS_SendDebug($this->gatewayID, "<SOCKET|CONNECT|SENDRAW:ERROR>", $error, 0);
		IPS_LogMessage("SymconOSR", "<SOCKET|CONNECT|SENDRAW:ERROR>   ".$error);
	}


	public function setAllDevices($value) {
		$args   = str_repeat(chr(0xFF), 8).chr($value);
		$buffer = $this->sendRaw(osrCommand::SET_DEVICE_STATE, chr(0x00), $args);

		return (($buffer !== false) ? $buffer : false);
	}


	public function setState($uintUUID, $flag, $value) {
		$args   = $uintUUID.chr($value);
		$buffer = $this->sendRaw(osrCommand::SET_DEVICE_STATE, $flag, $args);

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function setName($uintUUID, $command, $flag, $name) {
		$args = $uintUUID.str_pad($name, osrConstant::DATA_NAME_LENGTH).chr(0x00);
		return $this->sendRaw($command, $flag, $args);
	}


	public function setColor($uintUUID, $flag, $value, $transition = osrConstant::TRANSITION_MIN) {
		$args   = $uintUUID.chr($value['r']).chr($value['g']).chr($value['b']).chr(0xFF).chr(dechex($transition)).chr(0x00);
		$buffer = $this->sendRaw(osrCommand::SET_LIGHT_COLOR, $flag, $args.chr(0x00));

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function setColorTemperature($uintUUID, $flag, $value, $transition = osrConstant::TRANSITION_MIN) {
		$hex = dechex($value);
		if (strlen($hex) < 4) $hex = str_repeat("0", 4-strlen($hex)).$hex;

		$args   = $uintUUID.chr(hexdec(substr($hex, 2, 2))).chr(hexdec(substr($hex, 0, 2))).chr(dechex($transition)).chr(0x00);
		$buffer = $this->sendRaw(osrCommand::SET_COLOR_TEMPERATURE, $flag, $args.chr(0x00));

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function setLevel($uintUUID, $flag, $value, $transition = osrConstant::TRANSITION_MIN) {
		$args   = $uintUUID.chr($value).chr(dechex($transition)).chr(0x00);
		$buffer = $this->sendRaw(osrCommand::SET_LIGHT_LEVEL, $flag, $args.chr(0x00));

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function setSaturation($uintUUID, $flag, $value, $transition = osrConstant::TRANSITION_MIN) {
		$args   = $uintUUID.chr($value['r']).chr($value['g']).chr($value['b']).chr(0x00).chr(dechex($transition)).chr(0x00);
		$buffer = $this->sendRaw(osrCommand::SET_LIGHT_COLOR, $flag, $args.chr(0x00));

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function saveLightState($uintUUID) {
		$buffer = $this->sendRaw(osrCommand::SAVE_LIGHT_STATE, chr(0x00), $uintUUID.chr(0x00));
		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function setSoftTime($uintUUID, $command, $transition) {
		$args   = $uintUUID.chr($transition).chr(0x00);
		$buffer = $this->sendRaw($command, chr(0x00), $args);

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function getPairedDevices() {
		return $this->sendRaw(osrCommand::GET_DEVICE_LIST, chr(0x00), chr(0x01));
	}


	public function getGroupList() {
		return $this->sendRaw(osrCommand::GET_GROUP_LIST, chr(0x00));
	}


	public function activateGroupScene($sceneID) {
		$buffer = $this->sendRaw(osrCommand::ACTIVATE_GROUP_SCENE, chr(0x00), chr($sceneID));
		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function getDeviceInfo($uintUUID) {
		$buffer = $this->sendRaw(osrCommand::GET_DEVICE_INFO, chr(0x00), $uintUUID);
		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_ONLINE_LENGTH) ? $buffer : false);
	}


	public function getGroupInfo($uintUUID) {
		return $this->sendRaw(osrCommand::GET_GROUP_INFO, chr(0x00), $uintUUID);
	}


	public function sceneLightifyLoop($uintUUID, $loop, $value) {
		$value  = dechex($value);
		$value  = str_repeat("0", 4-strlen($value)).$value;

		$args   = $uintUUID.(($loop) ? chr(0x01) : chr(0x00)).chr(hexdec(substr($value, 2, 2))).chr(hexdec(substr($value, 0, 2)));
		$buffer = $this->sendRaw(osrCommand::CYCLE_LIGHT_COLOR, chr(0x00), $args);

		return (($buffer !== false && strlen($buffer) == osrConstant::BUFFER_REPLY_LENGTH) ? $buffer : false);
	}


	public function getGatewayFirmware() {
		return $this->sendRaw(osrCommand::GET_GATEWAY_FIRMWARE, chr(0x00));
	}
	
	
	public function getGatewayWiFi($flags) {
		return $this->sendRaw(osrCommand::GET_GATEWAY_WIFI, $flags);
	}


	public function getUnknownInfo($command, $value) {
		return $this->sendRaw($command, chr(0x00), $value);
	}


	public function __destruct() {
		$result = @fclose($this->lightifySocket);
		//IPS_LogMessage("SymconOSR", "<GATEWAY|_DESTRUCT>   Socket close: ".$this->lightifySocket."/".(integer)$result);
	}

}