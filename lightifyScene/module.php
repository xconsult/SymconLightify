<?php

declare(strict_types=1);

require_once __DIR__.'/../libs/lightifyControl.php';


class LightifyScene extends IPSModule {

  use LightifyControl;


  public function Create() {

    parent::Create();

    //Store at runtime
    $this->RegisterPropertyInteger("ID", vtNoValue);
    $this->RegisterPropertyString("module", vtNoString);
    $this->RegisterAttributeInteger("group", vtNoValue);

    $this->ConnectParent(classConstant::MODULE_GATEWAY);

  }


  public function ApplyChanges() {

    $this->RegisterMessage(0, IPS_KERNELSTARTED);
    parent::ApplyChanges();

    if (IPS_GetKernelRunlevel() != KR_READY) {
      return;
    }

  }


  public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {

    switch ($Message) {
      case IPS_KERNELSTARTED:
        $this->ApplyChanges();
        break;
    }

  }


  public function GetConfigurationForm() {

    //Validate
    $ID = $this->ReadPropertyInteger("ID");

    if ($ID != vtNoValue) {
      $formJSON = json_decode(file_get_contents(__DIR__."/form.json"), true);
      $module = $this->ReadPropertyString("module");

      $formJSON['actions'][0]['items'][0]['value'] = $ID;
      $formJSON['actions'][0]['items'][1]['value'] = $this->Translate($module);

      $group = $this->ReadAttributeInteger("group");
      $instanceID = $this->lightifyBase->getInstanceByID(classConstant::MODULE_GROUP, $group);

      if ($instanceID) {
        $name = IPS_GetName($instanceID);

        $formJSON['actions'][1]['items'][1]['caption']  = $name;
        $formJSON['actions'][1]['items'][1]['objectID'] = $instanceID;

      } else {
        $formJSON['actions'][1]['items'][1]['caption'] = "- Unknown -";
        $formJSON['actions'][1]['items'][1]['enabled'] = false;
      }

      $caption = "[".IPS_GetName($this->InstanceID)."] ".$this->Translate("is connected to the following group");
      $formJSON['actions'][1]['items'][0]['caption'] = $caption;

      $formJSON['actions'][0]['onClick'] = "OSR_WriteValue(\$id, 'SCENE', ".$ID.");";
      return json_encode($formJSON);
    }

    $formJSON = [
      'elements' => [
        'type'    => "Label",
        'caption' => "Scene can only be configured over the Configurator Instance!"
      ],
    ];

    return json_encode($formJSON);

  }


  public function ReceiveData($JSONString) {

    //Decode data
    $data = json_decode($JSONString, true);

    foreach ($data['buffer'] as $scene) {
      if ($scene['ID'] == $this->ReadPropertyInteger("ID")) {
        $this->WriteAttributeInteger("group", $scene['group']);
        $this->setSceneInfo();

        break;
      }
    }

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
