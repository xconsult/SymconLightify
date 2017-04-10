<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyDevice.php"); 


class lightifyLight extends lightifyDevice {

  public function Create() {
    parent::Create();
 
		$this->RegisterPropertyString("UniqueID", "");
    $this->RegisterPropertyInteger("LightID", 0);
    $this->RegisterPropertyString("deviceModel", "");
    $this->RegisterPropertyString("deviceCapability", "");
    $this->RegisterPropertyString("Firmware", "");
    
    $this->RegisterPropertyInteger("deviceType", 0);
  }
    
  
  protected function GetUniqueID() {
    $UniqueID = $this->ReadPropertyString("UniqueID");
    return $UniqueID;
  }

}
