<?php
  namespace elpho\database;

  use elpho\di\DependencyProvider;

  class EntityProvider implements DependencyProvider{
    private $connection = null;
    private $entity = null;

    private $entityClass = null;
    private $entityShortName = null;

    public function __construct(\PDO $connection, $entityClass){
      $this->connection = $connection;
      $this->entityClass = $entityClass;
      $this->entityShortName = basename(str_replace('\\','/',$this->entityClass));
    }

    public function getProvidedClassName(){
      return $this->entityShortName;
    }

    public function getInstance(){
      if($this->entity == null)
        $this->entity = new $this->entityClass($this->connection);

      return $this->entity;
    }
  }