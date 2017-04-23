<?

//IPS variable types
class osrIPSVariable extends stdClass {

	const vtNone = -1;
	const vtBoolean = 0;
	const vtInteger = 1;
	const vtFloat = 2;
	const vtString = 3;

}


//IPS device modules
class osrIPSModule extends stdClass {

	const omGateway = "{C3859938-D71C-4714-8B02-F2889A62F481}";
	const omLight = "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}";
	const omPlug = "{80AC7E4B-E3A2-475C-8D54-12802F14DD80}";
	const omGroup = "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}";
	const omSwitch = "{2C0FD8E7-345F-4F7A-AF7D-86DFB43FE46A}";

	const omGatewayTX = "{6C85A599-D9A5-4478-89F2-7907BB3E5E0E}";
	const omDeviceTX = "{0EC8C035-D581-4DF2-880D-E3C400F41682}";
	const omGroupTX = "{C74EF90E-1D24-4085-9A3B-7929F47FF6FA}";

}


//Buffer bytes
class osrBufferByte extends stdClass {

	const bbHeader = 8;
	const bbToken = 4;

	const bbDeviceString = 50;
	const bbGroupString = 18;
	const bbGroupInfo = 28;

	const bbDeviceName = 15;
	const bbGroupName = 15;

	const bbDeviceMAC = 8;
	const bbGroupMAC = 2;
}


//Device types
class osrDeviceType extends stdClass {

	const dtTW = 2;
	const dtClear = 4;
	const dtRGBW = 10;
	const dtPlug = 16;
	const dtMotion = 32;
	const dtDimmer = 64;
	const dtSwitch = 65;

}


//Device modes
class osrDeviceMode extends stdClass {

	const dmOnline = 0;
	const dmOffline = 255;

}


//Device values
class osrDeviceValue extends stdClass {

	const dvCT_Clear_Min = 2700;
	const dvCT_Clear_Max = 6500;
	const dvCT_TW_Min = 2700;
	const dvCT_TW_Max = 6535;
	const dvCT_RGBW_Min = 2000;
	const dvCT_RGBW_Max = 6535;

	const dvHue_Min = 0;
	const dvHue_Max = 360;
	const dvColor_Min = 0;
	const dvColor_Max = 16777215;
	const dvBright_Min = 0;
	const dvBright_Max = 100;
	const dvSat_Min = 0;
	const dvSat_Max = 100;

	const dvAS_Min = 5;
	const dvAS_Max = 65535;
	const dvTT_Def = 50; 	 //0.5 sec
	const dvTT_Min = 0; 	 //0.0 sec
	const dvTT_Max = 8000; //8.0 sec

}


//Base functions	
class lightifyBase extends stdClass {

	public function decodeData($data) {
		$Decode = "";

		for ($i = 0; $i < strlen($data); $i++)
			$Decode = $Decode." ".sprintf("%02d", ord($data{$i}));

		return $Decode;
	}


	public function uniqueIDToChr($UniqueID) {
		$UniqueID = explode(":", $UniqueID);
		$result = "";

		foreach($UniqueID as $value)
			$result = chr(hexdec($value)).$result;

		return ((strlen($result) == 8) ? $result : $result.str_repeat(chr(0x00), 8-strlen($result)));
	}


	public function chrToUniqueID($UniqueID) {
		$length = strlen($UniqueID);
		$result = array();

		for ($i = 0; $i < $length; $i++) {
			$hex = dechex(ord(substr($UniqueID, $i, 1)));
			if (strlen($hex) == 1) $hex = "0".$hex;
			$result[] = $hex;
		}

		if (count($result) == 2) {
			for ($j = 0; $j < 5; $j++) 
				$result[] = "00";
		}

		return implode(":", array_reverse($result));
	}


 	public function HEX2HSV($hex) {
		$r = substr($hex, 0, 2);
		$g = substr($hex, 2, 2);
		$b = substr($hex, 4, 2);

		return $this->RGB2HSV(hexdec($r), hexdec($g), hexdec($b));
	}


	private function RGB2HSV($r, $g, $b) {
		$r /= 255; $g /= 255; $b /= 255;

		$maxRGB = max($r, $g, $b);
		$minRGB = min($r, $g, $b);
		$chroma = $maxRGB-$minRGB;
		$dV = $maxRGB*100;

		if ($chroma == 0)
			return array('h' => 0, 's' => 0, 'v' => round($dV));
		$dS = ($chroma/$maxRGB)*100;

		switch ($minRGB) {
			case $r:
				$h = 3-(($g-$b)/$chroma);
				break;

			case $b:
				$h = 1-(($r-$g)/$chroma);
				break;

			default:
				$h = 5-(($b-$r)/$chroma);
		}

		$dH = $h*60;
		return array('h' => round($dH), 's' => round($dS), 'v' => round($dV));
	}


	public function HSV2HEX($h, $s, $v) {
		$rgb = $this->HSV2RGB($h, $s, $v);

		$r = str_pad(dechex($rgb['r']), 2, 0, STR_PAD_LEFT);
		$g = str_pad(dechex($rgb['g']), 2, 0, STR_PAD_LEFT);
		$b = str_pad(dechex($rgb['b']), 2, 0, STR_PAD_LEFT);

		return $r.$g.$b;
	}

 
 	private function HSV2RGB($h, $s, $v) {
		if ($h < 0)   $h = 0;
		if ($h > 360) $h = 360;
		if ($s < 0)   $s = 0;
		if ($s > 100) $s = 100;
		if ($v < 0)   $v = 0;
		if ($v > 100) $v = 100;

		$dS = $s/100;
		$dV = $v/100;
		$dC = $dV*$dS;
		$dH = $h/60;
		$dT = $dH;

		while ($dT >= 2) $dT -= 2;
			$dX = $dC*(1-abs($dT-1));

		switch(floor($dH)) {
			case 0:
				$r = $dC; $g = $dX; $b = 0;
				break;

			case 1:
				$r = $dX; $g = $dC; $b = 0;
				break;

			case 2:
				$r = 0; $g = $dC; $b = $dX; 
				break;

			case 3:
				$r = 0; $g = $dX; $b = $dC;
				break;

			case 4:
				$r = $dX; $g = 0; $b = $dC;
				break;

			case 5:
				$r = $dC; $g = 0; $b = $dX;
				break;

			default:
				$r = 0; $g = 0; $b = 0;
		}

		$dM  = $dV-$dC; $r += $dM; $g += $dM; $b += $dM;
		$r *= 255; $g *= 255; $b *= 255;

		return array('r' => round($r), 'g' => round($g), 'b' => round($b));
	}


	public function RGB2HEX($rgb) {
		$hex = str_pad(dechex($rgb['r']), 2, "0", STR_PAD_LEFT);
		$hex .= str_pad(dechex($rgb['g']), 2, "0", STR_PAD_LEFT);
		$hex .= str_pad(dechex($rgb['b']), 2, "0", STR_PAD_LEFT);

		return $hex;
	}


	public function HEX2RGB($hex) {
		if(strlen($hex) == 3) {
			$r = hexdec($hex[0].$hex[0]);
			$g = hexdec($hex[1].$hex[1]);
			$b = hexdec($hex[2].$hex[2]);
		} else {
			$r = hexdec($hex[0].$hex[1]);
			$g = hexdec($hex[2].$hex[3]);
			$b = hexdec($hex[4].$hex[5]);
		}

		return array('r' => $r, 'g' => $g, 'b' => $b);
	}

}