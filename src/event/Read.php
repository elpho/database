<?php
  namespace elpho\database\event;

  class Read extends DatabaseEvent{
    private $index;

    public function Read($index){
      $this->index = $index;
    }
    public function getIndex(){
      return $this->index;
    }
  }
?>