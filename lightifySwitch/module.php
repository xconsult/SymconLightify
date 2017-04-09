<?

require_once(__DIR__.DIRECTORY_SEPARATOR."..".DIRECTORY_SEPARATOR."lightifyDevice.php"); 


class lightifySwitch extends lightifyDevice {

  public function Create() {
    parent::Create();
 
		$this->RegisterPropertyString("UniqueID", "");
    $this->RegisterPropertyString("Type", "");
    $this->RegisterPropertyString("ModelID", "");
    $this->RegisterPropertyString("Firmware", "");
  }
    
  
  protected function GetUniqueID() {
    $id = $this->ReadPropertyString("UniqueID");
    return $id;
  }

}
