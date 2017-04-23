<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyDevice.php");


class lightifyPlug extends lightifyDevice {

	public function Create() {
		parent::Create();

		$this->RegisterPropertyString("UniqueID", "");
		$this->RegisterPropertyInteger("DeviceID", 0);
		$this->RegisterPropertyString("deviceModel", "");
		$this->RegisterPropertyString("deviceLabel", "");
		$this->RegisterPropertyString("Firmware", "");

		$this->RegisterPropertyInteger("deviceType", 0);
	}


	protected function getUniqueID() {
		$UniqueID = $this->ReadPropertyString("UniqueID");
		return $UniqueID;
	}

}
