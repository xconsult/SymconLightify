<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class lightifyScene extends IPSModule
{


  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->RegisterPropertyInteger("ID", vtNoValue);
    $this->RegisterPropertyString("module", vtNoString);
    $this->RegisterPropertyString("UUID", vtNoString);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

    //Apply filter
    //$filter = ".*".preg_quote(trim(json_encode($this->ReadPropertyString("UUID")), '"')).".*";
    //$this->SetReceiveDataFilter($filter);

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;
    }

  }


  public function GetConfigurationForm() {

    if (!$this->HasActiveParent()) {
      $this->SetStatus(201);
      return vtNoForm;
    }

    //Validate
    if ($this->ReadPropertyInteger("ID") != vtNoValue) {
      $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
      $formJSON['actions'][0]['onClick'] = "OSR_WriteValue(\$id, 'SCENE', ".$this->ReadPropertyInteger("ID").");";

      return json_encode($formJSON);
    }

    $elements[] = [
      'type'    => "Label",
      'caption' => "Scene can only be configured over the Lightify Discovery Instance!"
    ];
    $status[] = [
      'code'    => 104,
      'icon'    => "inactive",
      'caption' => "Scene is inactive"
    ];
    $formJSON = [
      'elements' => $elements,
      'status'   => $status
    ];

    $this->SetStatus(104);
    return json_encode($formJSON);

  }


  public function ReceiveData($JSONString) {

    $data  = json_decode($JSONString, true);
    $debug = IPS_GetProperty($data['id'], "debug");

    foreach ($data['buffer'] as $scene) {
      if ($scene['UUID'] == $this->ReadPropertyString("UUID")) {
        $this->setSceneInfo();
        break;
      }
    }

    //$this->setSceneInfo();

  }


  private function setSceneInfo() : void {

    //Scene control
    if (false === ($sceneID = @$this->GetIDForIdent("SCENE"))) {
      $this->MaintainVariable("SCENE", $this->Translate("Scene"), vtInteger, "OSR.Scene", 311, true);
      $sceneID = $this->GetIDForIdent("SCENE");

      //$sceneID = $this->RegisterVariableInteger("SCENE", $this->Translate("Scene"), "OSR.Scene", 311);
      //$sceneID = $this->RegisterVariableInteger("SCENE", "Scene", "OSR.Scene", 311);
      $this->EnableAction("SCENE");
    }

    if ($sceneID && GetValueInteger($sceneID) != 1) {
      SetValueInteger($sceneID, 1);
    }

  }


}
