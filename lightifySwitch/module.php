<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyDevice.php"); 


class lightifySwitch extends lightifyDevice {

  public function Create() {
    parent::Create();
 
		$this->RegisterPropertyString("UniqueID", "");
    $this->RegisterPropertyString("deviceModel", "");
    $this->RegisterPropertyString("deviceCapability", "");
    $this->RegisterPropertyString("Firmware", "");
  }
    
  
  protected function GetUniqueID() {
    $UniqueID = $this->ReadPropertyString("UniqueID");
    return $UniqueID;
  }

}
