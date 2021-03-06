<?php
  namespace elpho\database\event;

  use elpho\event\Event;

  abstract class DatabaseEvent extends Event{
    private $statement;
    private $options;

    public function DatabaseEvent($statement,$options){
      $this->statement = $statement;
      $this->options = $options;
    }
    public function getStatement(){
      return $this->statement;
    }
    public function getOptions(){
      return $this->options;
    }
  }
?>