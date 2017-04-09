<?

# Commands
# 13 List paired devices (broadcast)
# 1E List configured groups/zones (broadcast)
# 26 Get group/zone information (group/zone)
# 31 Set brigthness (device, group/zone)
# 32 Set power switch on/off (device, group/zone)
# 33 Set light color temperature (device, group/zone)
# 36 Set light color (RGBW) (device, group/zone)
# 52 Activate scene (device, group/zone)
# 68 Get device information (device)
# 6F Gateway Firmware version (broadcast)
# D5 Cycle group/zone color

define('OSR_GETPAIREDEVICES', 0x13);
define('OSR_GETGROUPLIST', 0x1E);
define('OSR_GETGROUPINFO', 0x26);
define('OSR_SETBULBBRIGHT', 0x31);
define('OSR_SETDEVICESTATE', 0x32);
define('OSR_SETCOLORTEMP', 0x33);
define('OSR_SETBULBCOLOR', 0x36);
define('OSR_SETDEVICESCENE', 0x52);
define('OSR_GETDEVICEINFO', 0x68);
define('OSR_GETGATEWAYFIRMWARE', 0x6F);
define('OSR_BULBCOLORCYCLE', 0xD5);

define('OSR_TRANSITION', 0x00); //0.0 sec
define('OSR_TRANSITMAX', 0x50); //8.0 sec

		
class lightifySocket {
	
	private $socket = null;
	

  public function __construct ($host, $port) {
		$this->Open($host, $port);
  }


	protected function Open($host, $port) {
		//$client = "tcp://".$host.":".$port;
		//$this->socket = stream_socket_client($client, $errno, $errstr, 3, 2);
		$this->socket = fsockopen($host, $port, $errno, $errstr, 5);

		if (!$this->socket) {
			throw new Exception($errno .": ".$errstr);
			return false;
		}	

		//stream config
		stream_set_timeout($this->socket, 1);
		stream_set_blocking($this->socket, true);
		
		stream_set_chunk_size($this->socket, 8192);
		stream_set_write_buffer($this->socket, 0);
		stream_set_read_buffer($this->socket, 0);
	}
	
	
	protected function close() {
		if ($this->socket) fclose($this-socket);
	}


	protected function SendData($flag, $command, $args = null) {
		//echo stream_get_meta_data($this->socket)['timed_out']."\n";
		$data = $flag.chr($command).chr(0x00).chr(0x00).chr(0x00).chr(0x00);
		if ($args != null) $data .= $args;

		$result = fwrite($this->socket, chr(strlen($data)).chr(0x00).$data);
		//echo $result."\n";
		fflush($this->socket);

		if ($result > 0) {
			$buffer = fread($this->socket, 8192); //Read 8192 bytes block
			//	$buffer = stream_get_contents($this->socket);
			$length = strlen($buffer);

			if ($length > 9) {
				$errno = ord($buffer{8});
				if ($errno == false) return $buffer;
			}
		}

		return $result;
	}
	

	public function AllLights($Value) {
		$args = str_repeat(chr(0xFF), 8).chr($Value);
		$buffer = $this->SendData(chr(0x00), OSR_SETDEVICESTATE, $args);
		//IPS_LogMessage("SymconOSR", "'ALL_LIGHTS' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}


	public function State($MAC, $flag, $Value) {
		$args = $MAC.chr($Value);
		$buffer = $this->SendData($flag, OSR_SETDEVICESTATE, $args);
		//IPS_LogMessage("SymconOSR", "'STATE' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}
	

	public function Color($MAC, $flag, $Value) {
		$args = $MAC.chr($Value['r']).chr($Value['g']).chr($Value['b']).chr(0xFF).chr(OSR_TRANSITION).chr(0x00);
		$buffer = $this->SendData($flag, OSR_SETBULBCOLOR, $args);
		//IPS_LogMessage("SymconOSR", "'COLOR' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}


	public function ColorTemperature($MAC, $flag, $Value) {
		$hex = dechex($Value);
		if (strlen($hex) < 4) $hex = str_repeat("0", 4-strlen($hex)).$hex;
							
		$args = $MAC.chr(hexdec(substr($hex, 2, 2))).chr(hexdec(substr($hex, 0, 2))).chr(OSR_TRANSITION).chr(0x00);
		$buffer = $this->SendData($flag, OSR_SETCOLORTEMP, $args);
		//IPS_LogMessage("SymconOSR", "'COLOR_TEMPERATURE' receive buffer length ".strlen($buffer));
	
		return ((strlen($buffer) == 20) ? true : false);
	}


	public function Brightness($MAC, $flag, $Value) {
		$args = $MAC.chr($Value).chr(OSR_TRANSITION).chr(0x00);
		$buffer = $this->SendData($flag, OSR_SETBULBBRIGHT, $args);
		//IPS_LogMessage("SymconOSR", "'BRIGHTNESS' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}


	public function Saturation($MAC, $flag, $Value) {
		$args = $MAC.chr($Value['r']).chr($Value['g']).chr($Value['b']).chr(0xFF).chr(OSR_TRANSITION).chr(0x00);
		$buffer = $this->SendData($flag, OSR_SETBULBCOLOR, $args);
		//IPS_LogMessage("SymconOSR", "'SATURATION' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}


	public function ColorCycle($MAC, $Cycle, $Value) {
		$Value = dechex($Value); 
		$Value = str_repeat("0", 4-strlen($Value)).$Value;

		$args = $MAC.(($Cycle) ? chr(0x01) : chr(0x00)).chr(hexdec(substr($Value, 2, 2))).chr(hexdec(substr($Value, 0, 2)));
		$buffer = $this->SendData(chr(0x00), OSR_BULBCOLORCYCLE, $args);
		//IPS_LogMessage("SymconOSR", "'COLOR_CYCLE' receive buffer length ".strlen($buffer));

		return ((strlen($buffer) == 20) ? true : false);
	}
			
								
	public function PairedDevices() {
		$args = chr(0x01).chr(0x00).chr(0x00).chr(0x00).chr(0x00);
		return $this->SendData(chr(0x00), OSR_GETPAIREDEVICES, $args);
	}
	
	
	public function GroupList() {
		return $this->SendData(chr(0x00), OSR_GETGROUPLIST);
	}


	public function DeviceInfo($MAC) {
		return $this->SendData(chr(0x00), OSR_GETDEVICEINFO, $MAC);
	}
	

	public function GroupInfo($MAC) {
		return $this->SendData(chr(0x00), OSR_GETGROUPINFO, $MAC);
	}
	
							
	public function GatewayFirmware() {
		return $this->SendData(chr(0x00), OSR_GETGATEWAYFIRMWARE);
	}


	function __desctruct() {
		$this->Close();
	}

}





