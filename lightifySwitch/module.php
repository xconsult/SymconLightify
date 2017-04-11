<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyDevice.php"); 


class lightifySwitch extends lightifyDevice {

  public function Create() {
    parent::Create();
 
		$this->RegisterPropertyString("UniqueID", "");
    $this->RegisterPropertyString("deviceModel", "");
    $this->RegisterPropertyString("deviceType", "");
    $this->RegisterPropertyString("Firmware", "");
    
    $this->RegisterPropertyInteger("deviceIndex", 0);
  }
    
  
  protected function GetUniqueID() {
    $UniqueID = $this->ReadPropertyString("UniqueID");
    return $UniqueID;
  }

}
