<?

//Global types
class lightifyTypes extends stdClass {
	
	const vtNone = -1;
	const vtBoolean = 0;
	const vtInteger = 1;
	const vtFloat = 2;
	const vtString = 3;
    
}

//Global modules
class lightifyModules extends stdClass {
	
	const lightifyGateway = "{C3859938-D71C-4714-8B02-F2889A62F481}";
	const lightifyLight = "{42DCB28E-0FC3-4B16-ABDB-ADBF33A69032}";
	const lightifyGroup = "{7B315B21-10A7-466B-8F86-8CF069C3F7A2}";
	const lightifySwitch = "{2C0FD8E7-345F-4F7A-AF7D-86DFB43FE46A}";

}


//Base functions	
class lightifyBase extends stdClass {
	
	public function DecodeData($data) {
		$Decode = "";

		for ($i = 0; $i < strlen($data); $i++)
			$Decode = $Decode." ".sprintf("%02d", ord($data{$i}));
		
		return $$Decode;
	}


	public function UniqueIDToChr($UniqueID) {
		$UniqueID = explode(":", $UniqueID);
		$result = "";

		foreach($UniqueID as $value)
			$result = chr(hexdec($value)).$result;

		return ((strlen($result) == 8) ? $result : $result.str_repeat(chr(0x00), 8-strlen($result)));
	}


	public function ChrToUniqueID($UniqueID) {
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


	public function RGB2HSV($r, $g, $b) {
		$r /= 255; $g /= 255; $b /= 255;

		$maxRGB = max($r, $g, $b);
		$minRGB = min($r, $g, $b);
		$chroma = $maxRGB-$minRGB;
		$dV = 100*$maxRGB;

		if ($chroma == 0)
			return array('h' => 0, 's' => 0, 'v' => round($dV, 2));
		$dS = 100*($chroma/$maxRGB);

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

		$dH = 60*$h;
		return array('h' => round($dH), 's' => round($dS, 2), 'v' => round($dV));
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

		$dS = $s/100.0;
		$dV = $v/100.0;
		$dC = $dV*$dS;
		$dH = $h/60.0;
		$dT = $dH;

		while ($dT >= 2.0) $dT -= 2.0;
			$dX = $dC*(1-abs($dT-1));

		switch(floor($dH)) {
			case 0:
				$dR = $dC; $dG = $dX; $dB = 0.0;
				break;

			case 1:
				$dR = $dX; $dG = $dC; $dB = 0.0;
				break;

			case 2:
				$dR = 0.0; $dG = $dC; $dB = $dX; 
				break;

			case 3:
				$dR = 0.0; $dG = $dX; $dB = $dC;
				break;

			case 4:
				$dR = $dX; $dG = 0.0; $dB = $dC;
				break;

			case 5:
				$dR = $dC; $dG = 0.0; $dB = $dX;
				break;

			default:
				$dR = 0.0; $dG = 0.0; $dB = 0.0;
		}

		$dM  = $dV-$dC; $dR += $dM; $dG += $dM; $dB += $dM;
		$dR *= 255; $dG *= 255; $dB *= 255;

		return array('r' => round($dR), 'g' => round($dG), 'b' => round($dB));
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

 
  public function CT2HEX($ct){
    $ct = $ct/100;

    if ($ct <= 66) { 
			$r = 255;
      $g = 99.4708025861*log($ct)-161.1195681661;
			$b = ($ct <= 19) ? $b = 0 : $b = 138.5177312231*log($ct-10)-305.0447927307;
    } else {
			$r = 329.698727446*pow($ct-60, -0.1332047592);   
			$g = 288.1221695283*pow($ct-60, -0.0755148492);
      $b = 255;
    }

		if ($r < 0) $r = 0;
		if ($r > 255) $r = 255;
		if ($g < 0) $g = 0;
		if ($g > 255) $g = 255;
		if ($b < 0) $b = 0;
		if ($b > 255) $b = 255;
		
		return $this->RGB2HEX(array('r' => $r, 'g' => $g, 'b' => $b));
	}
	
}