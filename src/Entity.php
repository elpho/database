<?php
  namespace elpho\database;

  use elpho\lang\Text;
  use elpho\lang\ArrayList;
  use elpho\lang\Dynamic;
  use elpho\lang\ProtoObject;
  use elpho\event\EventHandler;

  abstract class Entity extends EventHandler{
    private $table;
    private $position;
    private $keyField;
    private $isLocked;
    private $readOnly;
    private $fieldList;

    protected $connection;

    protected $record;
    protected $records;
    protected $hasRecords;
    protected $inPosition;

    private $statements;

    protected $separators = array("`","`");

    //constructor
    protected function __construct(\PDO $connection,$fieldList=array()){
      $this->setWritable(false);
      if(!$this->keyField) $this->keyField = "id";

      if(!$this->fieldList) $this->fieldList = $fieldList;
      $this->connection = $connection;
      $this->isLocked = false;
      $this->statements = new \stdClass();

      $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      $this->prepareStatements();
      $this->reset();
    }

    //set
    public function setWritable($writable){
      if(is_bool($this->readOnly))
        return false;

      $this->readOnly = !$writable;
      return true;
    }

    public function setRecord($record){
      $this->record = $record;
      $this->inPosition = !!$this->getId();
    }
    public function setId($id){
      $this->record->{$this->keyField} = $id;
    }
    protected function setKeyField($field){
      $this->keyField = $field;
    }
    protected function setTable($tableName){
      $this->table = $tableName;
    }
    protected function setFieldList($list,$_=null){
      if(is_array($list)){
        $this->fieldList = $list;
        return;
      }
      $this->fieldList = func_get_args();
    }
    protected function setSeparators($open, $close){
      $this->separators = array($open, $close);
    }

    //get
    public function getKeyField(){
      return $this->keyField;
    }
    public function getTable(){
      return $this->table;
    }
    public function getRecord(){
      return (new ProtoObject($this->record))->duplicate()->toPrimitive();
    }
    public function getRecords(){
      $new = array();
      foreach($this->records as $rec){
        $new[] = (new ProtoObject($rec))->duplicate()->toPrimitive();
      }
      return $new;
    }
    public function isAvailable(){
      return $this->inPosition;
    }
    public function hasRecords(){
      return $this->hasRecords;
    }
    public function getId(){
      if(!isset($this->record->{$this->keyField}))
        return;

      return $this->record->{$this->keyField};
    }
    public function getCount(){
      return count($this->records);
    }
    public function getPosition(){
      return $this->position;
    }
    public function getFieldList(){
      return new \elpho\lang\ArrayList_from($this->fieldList);
    }

    //buscar
    public function find($options=null){
      if($this->isLocked)
        return;

      if(!$options)
        $options = array();

      if(!is_array($options) and !is_object($options))
        return;

      if(is_object($options) and !($options instanceof ProtoObject))
        return;

      $prepared = new \stdClass();

      if(!empty($options["order"])){
        $prepared->order = $options["order"];
        unset($options["order"]);
      }
      if(isset($options["where"])){
        if(empty($options["where"]))
          return;
        $prepared->where = $options["where"];
        unset($options["where"]);
      }
      if(!empty($options["limit"])){
        $prepared->limit = $options["limit"];
      }
      if(!empty($options["start"])){
        $prepared->start = $options["start"];
      }

      $this->prepareStatements($prepared);

      $query = "read";
      if(isset($options["limit"])) $query = "readRange";

      $result = $this->queryResults($query,$options);
      $this->dispatchEvent(new event\Find($result,$options));
      $this->fetchFromResult($result);
      $result->closeCursor();
    }
    private function fetchFromResult($result){
      $this->reset();

      while($record = $result->fetchObject()){
        $this->records[] = $record;
        $this->hasRecords = true;
      }
    }
    public function findId($id,$fetch=true){
      $keyField = $this->getKeyField();
      $this->find(array("where"=>$keyField."='".$id."'"));
      if($fetch) return $this->first();
    }
    public function findIds(ArrayAccess $list=null){
      if(!$list)
        $list = array();

      if(count($list) == 0)
        return $this->reset();

      $where = array();
      foreach($list as $item){
        $where[] = $this->keyField." = '".$item."'";
      }
      $this->find(array("where"=>implode(" OR ",$where)));
    }

    //positioning
    public function reset(){
      if($this->isLocked)
        return false;

      $this->clear();
      $this->records = array();
      $this->hasRecords = false;
    }

    public function clear(){
      $this->position = -1;
      $this->inPosition = false;
      $this->record = new \stdClass();

      $this->record->{$this->keyField} = '';

      foreach ($this->fieldList as $name) {
        $this->record->{$name} = '';
      }
    }
    public function first(){
      $this->position = 0;
      return $this->fetchRecord();
    }
    public function prev(){
      if($this->position == -1) $this->position = $this->getCount();
      $this->position--;
      return $this->fetchRecord();
    }
    public function next(){
      $this->position++;
      return $this->fetchRecord();
    }
    public function last(){
      $this->position = $this->getCount()-1;
      return $this->fetchRecord();
    }
    public function get($index){
      $this->position = $index;
      return $this->fetchRecord();
    }

    protected function fetchRecord(){
      if($this->isLocked)
        return false;

      $this->inPosition = false;

      if(!$this->hasRecords)
        return false;

      if(!isset($this->records[$this->position])){
        $this->clear();
        return false;
      }

      $this->record = $this->records[$this->position];
      $this->inPosition = true;

      $this->dispatchEvent(new event\Read($this->position));
      return true;
    }
    public function lockRecord(){
      $this->isLocked = true;
    }
    public function invert(){
      $this->clear();
      $this->records = array_reverse($this->records,false);
    }

    //writting
    public function save(){
      if($this->readOnly)
        throw new DatabaseException("Entity is read only.");

      if(empty($this->table))
        throw new DatabaseException("No table set.");

      $options = new \stdClass();

      $query = 'create';
      $isNew = ((!$this->inPosition) or ($this->getId() == ""));

      $serie = $this->toArray($this->keyField);

      foreach($this->fieldList as $field){
        $options->{$field} = "";
      }
      foreach($serie as $key => $value){
        $options->{$key} = $value;
      }

      if(!$isNew) $query = 'update';

      if($this->getId() != "")
        $options->{$this->keyField} = $this->getId();

      $this->prepareStatements($options);

      $this->connection->beginTransaction();
      try{
        $result = $this->queryResults($query,$options);
        if($isNew) $this->record->{$this->keyField} = $this->connection->lastInsertId();
        $this->connection->commit();
      }catch(\Exception $e){
        $this->connection->rollBack();
        throw new DatabaseException($e->getMessage());
      }

      $this->records[] = $this->record;
      $this->hasRecords = true;

      $eventClass = 'elpho\database\event\\'.($isNew?"Create":"Update");
      $this->dispatchEvent(new $eventClass($result,$options));

      return $isNew;
    }
    public function delete($remove=true){
      if(!$this->inPosition)
        throw new DatabaseException("No record positioned.");

      if($this->readOnly)
        throw new DatabaseException("Entity is read only.");

      $key = $this->keyField;

      $query = 'delete';
      $options = new \stdClass();
      $options->{$key} = $this->getId();

      $this->connection->beginTransaction();
      try{
        $result = $this->queryResults($query,$options);
        $this->connection->commit();
      }catch(\Exception $e){
        $this->connection->rollBack();
        throw DatabaseException($e->getMessage());
      }

      $this->dispatchEvent(new event\Delete($result,$options));

      if($remove) $this->remove();
    }
    public function remove(){
      array_splice($this->records,$this->position,1);
      $this->prev();
    }

    //extra
    public function __get($attribute){
      if(!isset($this->record->{$attribute}))
        throw new \Exception("Field $attribute not found");

      $value = $this->record->{$attribute};
      $value = mb_detect_encoding($value, 'UTF-8', true)?$value:utf8_encode($value);
      return new Text($value);
    }
    public function __set($attribute,$value){
      if (!in_array($attribute, $this->fieldList))
        return;

      $this->record->{$attribute} = $value;
    }

    public function each($callback){
      $this->clear();
      while($this->next()){
        call($callback, $this);
      }
    }

    //core
    private function queryResults($query,$options=array()){
      $defaults = new \stdClass();
      $defaults->order = $this->keyField." ASC";

      $result = $this->statements->{$query};

      $tokens = array();
      $string = $result->queryString;
      foreach(explode(" ",$string) as $part){
        $part = preg_replace('/[^A-Za-z:_]/','',$part);
        if(substr($part,0,1) !== ":") continue;
        $tokens[] = substr($part,1);
      }

      $params = new \stdClass();
      foreach($defaults as $key => $value){
        $params->{$key} = $value;
      }
      foreach($options as $key => $value){
        $params->{$key} = $value;
      }
      $params->table = $this->table;

      foreach($tokens as $token){
        $result->bindValue(':'.$token,$params->{$token});
      }

      $result->execute();

      return $result;
    }
    private function prepareStatements($options=null){
      if(!$options)
        $options = new \stdClass();

      $prepared = new Dynamic();
      $openSeparator = $this->separators[0];
      $closeSeparator = $this->separators[1];

      $prepared->order = $this->keyField." ASC";
      $prepared->where = "1=1";
      $prepared->start = 0;
      $prepared->limit = 0;

      foreach($options as $key => $value){
        $prepared->{$key} = $value;
      }

      $fields = $this->fieldList;
      if(isset($options->{$this->keyField}))
        $fields[] = $this->keyField;

      $where = $prepared->where;
      $order = $prepared->order;
      $start = $prepared->start;
      $limit = $prepared->limit;

      //Preparing C.R.U.D. statements
      $this->statements->read = $this->connection->prepare("SELECT ".$openSeparator.$this->keyField.$closeSeparator.", ".$openSeparator.implode($closeSeparator.", ".$openSeparator,$fields).$closeSeparator." FROM ".$openSeparator.$this->table.$closeSeparator." WHERE ".$where." ORDER BY ".$order);
      try{
        //MySQL and MSSQL2012 do it like this
        $this->statements->readRange = $this->connection->prepare("SELECT ".$openSeparator.$this->keyField.$closeSeparator.", ".$openSeparator.implode($closeSeparator.", ".$openSeparator,$fields).$closeSeparator." FROM ".$openSeparator.$this->table.$closeSeparator." WHERE ".$where." ORDER BY ".$order." LIMIT ".$start.", ".$limit);
      }catch(\Exception $e){
        try{
          //Older SQL implementations (like IBase/Firebird) do it like this
          $this->statements->readRange = $this->connection->prepare("SELECT FIRST ".$limit." SKIP ".$start." ".$openSeparator.$this->keyField.$closeSeparator.", ".$openSeparator.implode($closeSeparator.", ".$openSeparator,$fields).$closeSeparator." FROM ".$openSeparator.$this->table.$closeSeparator." WHERE ".$where." ORDER BY ".$order);
        }catch(\Exception $e){
          //MSSQL2008_R2 still can't do, so we workaround
          $this->statements->readRange = $this->connection->prepare("SELECT * FROM (SELECT ROW_NUMBER() OVER (ORDER BY ".$order.") as RowNum, ".$openSeparator.$this->keyField.$closeSeparator.", ".$openSeparator.implode($closeSeparator.", ".$openSeparator,$fields).$closeSeparator." FROM ".$openSeparator.$this->table.$closeSeparator." WHERE ".$where.") AS internalResult WHERE RowNum >= ".$start." AND RowNum < ".($start + $limit)." ORDER BY RowNum");
        }
      }

      if($this->readOnly)
        return;

      $this->statements->create = $this->connection->prepare("INSERT INTO ".$openSeparator.$this->table.$closeSeparator." (".$openSeparator.implode($closeSeparator.", ".$openSeparator,$fields).$closeSeparator.") VALUES( ".implode(", ",array_map(function($field){ return ":".$field; },$fields))." )");
      $this->statements->update = $this->connection->prepare("UPDATE ".$openSeparator.$this->table.$closeSeparator." SET ".implode(", ",array_map(function($field) use($openSeparator,$closeSeparator){ return $openSeparator.$field.$closeSeparator." = :".$field; },$fields))." WHERE ".$openSeparator.$this->keyField.$closeSeparator." = :".$this->keyField);
      $this->statements->delete = $this->connection->prepare("DELETE FROM ".$openSeparator.$this->table.$closeSeparator." WHERE ".$openSeparator.$this->keyField.$closeSeparator." = :".$this->keyField);
    }
    public function toArray($exceptions=null,$_=null){
      $exceptions = func_get_args();
      $result = array();

      foreach($this->record as $var => $value){
        if(in_array($var,$exceptions))
          continue;

        $result[$var] = $value;
      }
      return $result;
    }
  }
?>