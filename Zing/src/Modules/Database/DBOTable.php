<?php

namespace Modules\Database;

use Exception;
use Modules\DBO;
use Modules\ModuleShare;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

/**
 * @method array getItemsBy_*() getItemsBy*(mixed $value) Gets items from the table by column name
 */
class DBOTable extends DBO{

    private $table_primary_keys = array();
    protected $table;
    private $limit              = 0;
    private $joins              = array();
    private $columns            = array();
    private $orderByCol         = array();
    private $groupByCol         = array();
    private $internalQuery      = array(
        "select" => "",
        "order"  => "",
        "where"  => "",
        "group"  => "",
        "params" => array()
    );

    public function __construct($table_name, $config){
        $this->setConnectionParams($config);
        if(!$this->_validName($table_name)){
            throw new Exception("Invalid Table Name '$table_name'.");
        }
        $this->table = $table_name;
        parent::__construct($config);
    }

    /**
     *
     * @param string $name The name of the method
     * @param array $arguments The list of arguments
     * @return type
     */
    public function __call($name, $arguments){
        $matches = array();
        if(preg_match("/^getItemsBy_(.+)/", $name, $matches)){
            $this->_getItemsByColumn($matches[1], $arguments[0], $arguments[1]);
        }
        return $this;
    }

    /**
     * creates a multirow insert query
     * @param array $columns  Array of columns to use
     * @param array $params   Multilevel array of values
     * @param string $ignore  Adds an 'ignore' to the insert query
     * @param string $after   A final statment such as 'on duplicate key...'
     * @return boolean
     * @throws Exception
     */
    public function insertMultiRow(array $columns, array $params, $ignore = false, $after = ""){
        $ncols = count($columns);
        $table = $this->table;
        if((bool)$ignore && strlen($after) > 0){
            throw new Exception("Can't do an 'ignore' and 'duplicate key update' in the same query.");
        }

        $ign = (bool)$ignore ? "ignore" : "";

        $sql  = "insert $ign into $table";
        $sql .= " (" . implode(",", $columns) . ") ";
        $sql .= " values ";
        $data = array();
        foreach($params as $p){
            $this->_validMultiInsertValue($p, $ncols);
            $data[] = "(" . implode(",", array_pad(array(), $ncols, "?")) . ")";
        }
        $sql .= implode(",", $data);
        $sql .= " $after";
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($params));
        $p  = iterator_to_array($it, false);
        $this->beginTransaction();
        try{
            $result = $this->query($sql, $p);
            $this->commitTransaction();
            return $result;
        }catch(Exception $e){
            $this->rollBackTransaction();
        }
        return $this;
    }

    /**
     * Inserts data into a table using key => value pairs
     * @param array $data               A column => value array to insert
     * @param array $raw_data           Raw data to process.
     *                                  Formats:
     *                                      "<column>" => array("value" => "some_value", "functions" => "<func_name>")
     *                                      "<column>" => array("value" => "some_value", "functions" => array("<func_name>", "<func_name>"))
     * @param callable $onComplete      A function to call when the insert finishes.
     *                                  The insert id will be passed as a parameter.
     * @return DBOTable
     * @throws Exception
     */
    public function insert(array $data, array $raw_data = array(), callable $onComplete = null){
        $this->_insert($data, $raw_data, false);
        $id = $this->getInsertID();
        if($onComplete !== null && is_callable($onComplete)){
            call_user_func_array($onComplete, array($id));
        }
        return $id;
    }

    /**
     * Inserts data into a table using key => value pairs
     * @param array $data               A column => value array to insert
     * @param array $raw_data           Raw data to process.
     *                                  Formats:
     *                                      "<column>" => array("value" => "some_value", "functions" => "<func_name>")
     *                                      "<column>" => array("value" => "some_value", "functions" => array("<func_name>", "<func_name>"))
     * @param callable $onComplete      A function to call when the insert finishes.
     *                                  The insert id will be passed as a parameter.
     * @return DBOTable
     * @throws Exception
     */
    public function insertIgnore(array $data, array $raw_data = array(), callable $onComplete = null){
        $this->_insert($data, $raw_data, true);

        $id = $this->getInsertID();
        if($onComplete !== null && is_callable($onComplete)){
            call_user_func_array($onComplete, array($id));
        }
        return $id;
    }

    /**
     * Insert ignores data into a table using key => value pairs
     * @param array $data               A column => value array to insert
     * @param array $duplicateKey       An array of keys or key values to do an update with
     * @param array $raw_data           Raw data to process.
     *                                  Formats:
     *                                      "<column>" => array("value" => "some_value", "functions" => "<func_name>")
     *                                      "<column>" => array("value" => "some_value", "functions" => array("<func_name>", "<func_name>"))
     * @param callable $onComplete      A function to call when the insert finishes.
     *                                  The insert id will be passed as a parameter.
     * @return DBOTable
     * @throws Exception
     */
    public function insertDuplicateKey(array $data, array $duplicateKey, array $raw_data = array(), callable $onComplete = null){
        $this->_insert($data, $raw_data, false, $duplicateKey);

        $id = $this->getInsertID();
        if($onComplete !== null && is_callable($onComplete)){
            call_user_func_array($onComplete, array($id));
        }
        return $id;
    }

    /**
     * Deleates data from a table using key => value pairs
     * @param array $data A column => value array to insert
     * @param callable $onComplete A function to call when the insert finishes.
     * @return DBOTable
     * @throws Exception
     */
    public function delete(array $data, callable $onComplete = null){
        $keys   = array_keys($data);
        $values = array_values($data);
        $this->_testColumns($keys);
        $cols   = $this->_formatColumns($cols);

        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $values);

        $this->query("delete from `$this->table` where " . $where, $values);

        if($onComplete !== null && is_callable($onComplete)){
            $obj = $onComplete->bindTo($this, $this);
            call_user_func_array($obj, array());
        }
        return $this;
    }

    /**
     * Updates a Table
     * @param array $setters
     * @param array $filters
     */
    public function update(array $setters, array $filters, callable $onComplete = null){
        $keys1 = array_keys($setters);
        $vals1 = array_values($setters);
        $this->_testColumns($keys1);
        $keys1 = $this->_formatColumns($keys1);

        $keys2 = array_keys($filters);
        $vals2 = array_values($filters);
        $this->_testColumns($keys2);
        $keys2 = $this->_formatColumns($keys2);

        $table = $this->_buildTableSyntax();

        $set = implode(" = ?, ", $keys1) . " = ?";

        $where = implode(" = ? and ", $keys2) . " = ?";
        $where = $this->_buildWhere($where, $vals2);

        $this->query("update $table set $set where $where", array_merge($vals1, $vals2));
        if($onComplete !== null && is_callable($onComplete)){
            $affected = $this->getAffectedRows();
            $obj      = $onComplete->bindTo($this, $this);
            call_user_func_array($obj, array($affected));
        }
    }

    /**
     * Gets all rows from a table (Use with care)
     * @return DBOTable
     */
    public function getAllRows(array $params = array()){
        $table   = $this->_buildTableSyntax();
        $selCols = $this->_buildColumns();

        $this->internalQuery["select"] = "select $selCols from $table";
        $this->go($params);
        return $this;
    }

    /**
     * Orders the rows in the simple qurery builder
     * @param type $column
     * @param type $direction
     * @return DBOTable
     * @throws Exception
     */
    public function orderRows($column, $direction = "asc"){
        if(!$this->_validName($column)){
            throw new Exception("Invalid order by column name '$column'");
        }
        $direction                    = !in_array($direction, array("asc", "desc")) ? "asc" : $direction;
        $this->internalQuery["order"] = "order by $column $direction";
        return $this;
    }

    /**
     * Filter the rows in the simple query builder
     * @param type $filter
     * @return DBOTable
     */
    public function filterRows($filter){
        $this->internalQuery["where"] = "where " . $filter;
        return $this;
    }

    /**
     * Filter the rows using an array in the simple query builder
     * @param array $columns
     * @return DBOTable
     */
    public function arrayFilterRows(array $columns){
        $cols = array_keys($columns);
        $this->_testColumns($cols);
        $cols = $this->_formatColumns($cols);

        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $columns);

        $this->internalQuery["where"]  = "where " . $where;
        $this->internalQuery["params"] = array_values($columns);
        return $this;
    }

    /**
     * Executes the simple query builder
     * @return DBOTable
     */
    private function go(){
        $select        = $this->internalQuery["select"];
        $where         = $this->internalQuery["where"];
        $group         = $this->internalQuery["group"];
        $order         = $this->internalQuery["order"];
        $this->getAll("$select $where $group $order", $this->internalQuery["params"]);
        $this->columns = array();
        return $this;
    }

    /**
     * Tests a table to see if a row exists using a filter.
     * @param string $filter Where clause
     * @param array $params
     * @return boolean
     * @throws Exception
     */
    public function rowExists($filter, array $params = array()){
        $table = $this->_buildTableSyntax();
        $has   = (bool)$this->getOne("select 1 from $table where $filter limit 1", $params);

        $this->joins = array();
        return $has;
    }

    /**
     * Order results
     * @param array $order
     * @return DBOTable
     */
    public function orderBy(array $order){
        $this->orderByCol = array();
        foreach($order as $k => $v){
            if(is_int($k)){
                $this->_testColumns(array($v));
                $this->orderByCol[$v] = "asc";
            }else{
                $this->_testColumns(array($k));
                $this->orderByCol[$k] = in_array(strtolower($v), array("asc", "desc")) ? $v : "asc";
            }
        }
        return $this;
    }

    /**
     * Group results
     * @param array $order
     * @return DBOTable
     */
    public function groupBy(array $order){
        $this->groupByCol = array();
        foreach($order as $k => $v){
            if(is_int($k)){
                $this->_testColumns(array($v));
                $this->groupByCol[$v] = "asc";
            }else{
                $this->_testColumns(array($k));
                $this->groupByCol[$k] = in_array(strtolower($v), array("asc", "desc")) ? $v : "asc";
            }
        }
        return $this;
    }

    /**
     * Sets the number of rows to return
     * @param int $limit
     * @return DBOTable
     */
    public function setLimit($limit){
        $this->limit = (int)$limit;
        return $this;
    }

    /**
     * Tests a table to see if a row exists using an array.
     * @param array $columns
     * @return boolean
     * @throws Exception
     */
    public function has(array $columns, callable $doesHave = null, callable $doesNotHave = null){
        $cols  = array_keys($columns);
        $vals  = array_values($columns);
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $table = $this->_buildTableSyntax();

        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $vals);

        $row = $this->_getRow($q   = "select * from $table where " . $where . " limit 1", $vals);
        $has = is_array($row) && count($row) > 0 ? true : false;

        if($has && $doesHave !== null && is_callable($doesHave)){
            $obj = $doesHave->bindTo($this, $this);
            return call_user_func_array($obj, array($row));
        }elseif(!$has && $doesNotHave !== null && is_callable($doesNotHave)){
            $obj = $doesNotHave->bindTo($this, $this);
            return call_user_func_array($obj, array($row));
        }

        $this->joins = array();
        return $has;
    }

    /**
     * Executes a user callback if the table contains a match
     * @param array $columns A column => value array to search for
     * @param callable $callback A user callback
     * @return DBOTable
     */
    public function ifHas(array $columns, callable $callback){
        if($this->has($columns)){
            $obj = $callback->bindTo($this, $this);
            call_user_func_array($obj, array($columns));
        }
        return $this;
    }

    /**
     * Executes a user callback if the table does not contain a match
     * @param array $columns A column => value array to search for
     * @param callable $callback A user callback
     * @return DBOTable
     */
    public function ifHasNot(array $columns, callable $callback){
        if(!$this->has($columns)){
            $obj = $callback->bindTo($this, $this);
            call_user_func_array($obj, array($columns));
        }
        return $this;
    }

    /**
     * With each item found run a user defined callback on the row
     * @param array $columns
     * @param callable $foundRows
     * @param callable $foundNothing
     * @return DBOTable
     */
    public function with(array $columns, callable $foundRows, callable $foundNothing = null){
        $cols  = array_keys($columns);
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $vals  = array_values($columns);
        $table = $this->_buildTableSyntax();

        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $vals);

        $selCols = $this->_buildColumns();
        $order   = $this->_buildOrder();
        $order   = !empty($order) ? "order by $order" : "";

        $group = $this->_buildGroup();
        $group = !empty($group) ? "group by $group" : "";

        $limit = (int)$this->limit > 0 ? "limit $this->limit" : "";

        $rows             = $this->_getAll("select $selCols from $table where $where $group $order $limit", $vals);
        $this->columns    = array();
        $this->joins      = array();
        $this->orderByCol = array();
        $this->groupByCol = array();
        $this->limit      = 0;
        if(count($rows) > 0){
            if(is_callable($foundRows)){
                foreach($rows as $row){
                    $obj = $foundRows->bindTo($this, $this);
                    call_user_func_array($obj, array($row));
                }
            }
        }else{
            if(is_callable($foundNothing)){
                $obj = $foundNothing->bindTo($this, $this);
                call_user_func_array($obj, array());
            }
        }
        return $this;
    }

    /**
     * With each item found in a Stored Routine run a user defined callback on the row
     * @param string $call Stored routine name
     * @param array $params Array of routine parameters
     * @param callable $foundRows Callback to run on the found rows
     * @param callable $foundNothing Callback to run if no rows were found
     * @return DBOTable
     * @throws Exception
     */
    public function withCall($call, array $params, callable $foundRows, callable $foundNothing = null){
        if(!$this->_validName($call)){
            throw new Exception("Invalid Stored Routine '$call'");
        }

        $qs   = array_pad(array(), count($params), "?");
        $rows = $this->_getAll("call $call(" . implode(",", $qs) . ")", $params);
        if(count($rows) > 0){
            if(is_callable($foundRows)){
                foreach($rows as $row){
                    $obj = $foundRows->bindTo($this, $this);
                    call_user_func_array($obj, array($row));
                }
            }
        }else{
            if(is_callable($foundNothing)){
                $obj = $foundNothing->bindTo($this, $this);
                call_user_func_array($obj, array());
            }
        }
        return $this;
    }

    /**
     * Get a row from the database and run a callback on it
     * @param array $columns
     * @param callable $foundRows
     * @param callable $foundNothing
     * @return DBOTable
     */
    public function get(array $columns, callable $foundRows = null, callable $foundNothing = null){
        $cols  = array_keys($columns);
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $vals  = array_values($columns);
        $table = $this->_buildTableSyntax();

        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $vals);

        $selCols = $this->_buildColumns();
        $order   = $this->_buildOrder();
        $order   = !empty($order) ? "order by $order" : "";
        $group   = $this->_buildGroup();
        $group   = !empty($group) ? "order by $order" : "";

        $row = $this->_getRow("select $selCols from $table where $where $group $order limit 1", $vals);
        $this->setArray($row);

        $this->columns    = array();
        $this->joins      = array();
        $this->orderByCol = array();
        if(count($row) > 0 && is_callable($foundRows)){
            $obj = $foundRows->bindTo($this, $this);
            call_user_func_array($obj, array($row));
        }elseif(is_callable($foundNothing)){
            $obj = $foundNothing->bindTo($this, $this);
            call_user_func_array($obj, array());
        }
        return $this;
    }

    /**
     * Get the total number of columns
     * @param array $columns
     * @return int
     */
    public function getTotal(array $columns){
        $cols  = array_keys($columns);
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $vals  = array_values($columns);
        $table = $this->_buildTableSyntax();
        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $vals);

        return (int)$this->getOne("select count(*) from $table where " . $where . " limit 1", $vals);
    }

    /**
     * Gets the sum of the columns
     * @param string $column
     * @param array $columns
     * @return type
     */
    public function getSum($column, array $columns){
        $cols  = array_keys($columns);
        $this->_testColumns(array($column));
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $vals  = array_values($columns);
        $table = $this->_buildTableSyntax();
        $where = implode(" = ? and ", $cols) . " = ?";
        $where = $this->_buildWhere($where, $vals);

        return $this->getOne("select sum($column) from $table where " . $where . " limit 1", $vals);
    }

    /**
     * Gets a list of items from a table based on the primary key
     * @param mixed $id
     * @param boolean $uniq
     * @return array|boolean
     */
    public function getItemById($id, $uniq = true){
        $id     = (int)$id;
        $table  = $this->_buildTableSyntax();
        $column = $this->_getPrimary();
        $extra  = $uniq ? "limit 1" : "";

        $order = $this->_buildOrder();
        $order = !empty($order) ? "order by $order" : "";

        $selCols = $this->_buildColumns();
        $query   = "select $selCols from $table where $column = ? $order $extra";
        if($uniq){
            $array = $this->_getRow($query, array($id));
        }else{
            $array = $this->_getAll($query, array($id));
        }
        $this->setArray($array);
        $this->joins      = array();
        $this->columns    = array();
        $this->orderByCol = array();
        return $this;
    }

    /**
     * Adds a table to join on from the initial table or previous join() calls
     * @param string $table
     * @param array $on
     * @return DBOTable
     * @throws Exception
     */
    public function join($table, array $on){
        $joins = $this->_buildJoin($on);

        $this->joins[$table . "|join"] = $joins;
        return $this;
    }

    /**
     * Adds a table to left join on from the initial table or previous join() calls
     * @param type $table
     * @param array $on
     * @return DBOTable
     */
    public function leftJoin($table, array $on){
        $joins = $this->_buildJoin($on);

        $this->joins[$table . "|left join"] = $joins;
        return $this;
    }

    /**
     * Sets the returned Columns
     * @param array $columns
     * @return DBOTable
     */
    public function setColumns(array $columns){
        $keys          = array_keys($columns);
        $cols          = array_values($columns);
        $this->_testColumns($cols);
        $cols          = $this->_formatColumns($cols);
        $this->columns = array_combine($keys, $cols);
        return $this;
    }

    /**
     * Appends a special column such as a sum().
     * Note: this column isn't validiated.
     * Use with care.
     * @param type $columnName
     * @return DBOTable
     */
    public function appendSpecialCol($columnName){
        $this->columns[] = $columnName;
        return $this;
    }

    /**
     * Gets Rows based on the array passed in
     * @param array $columns
     * @param bool $uniq
     * @param array $orderBy
     * @return DBOTable
     * @throws Exception
     */
    public function getItemsByColumn(array $columns, $uniq = false){
        $cols  = array_keys($columns);
        $vals  = array_values($columns);
        $this->_testColumns($cols);
        $cols  = $this->_formatColumns($cols);
        $where = array();
        foreach($cols as $col){
            $where[] = "$col = ?";
        }

        $orderStr = $this->_buildOrder();
        if(!empty($orderStr)){
            $orderStr = "order by $orderStr";
        }

        $table   = $this->_buildTableSyntax();
        $selCols = $this->_buildColumns();
        if((bool)$uniq){
            $array = $this->_getRow("select $selCols from $table where " . implode(" and ", $where) . " $orderStr limit 1", $vals);
        }else{
            $array = $this->_getAll("select $selCols from $table where " . implode(" and ", $where) . " $orderStr", $vals);
        }
        $this->setArray($array);
        $this->joins      = array();
        $this->columns    = array();
        $this->orderByCol = array();
        return $this;
    }

    /**
     * Formats a column or an array of database columns using a callback
     * @param string|array $columns
     * @param \Modules\Database\callable $formatter
     * @return DBOTable
     */
    public function formatColumn($columns, callable $formatter){
        if(!is_array($columns)){
            $columns = array($columns);
        }
        foreach(ModuleShare::$array as $key => $val){
            if(is_array($val)){
                foreach($val as $k => $v){
                    if(in_array($k, $columns)){
                        ModuleShare::$array[$key][$k] = $formatter($v);
                    }
                }
            }else{
                if(in_array($key, $columns)){
                    ModuleShare::$array[$key] = $formatter($val);
                }
            }
        }
        return $this;
    }

    public function count(){
        return count($this->toArray());
    }

    /**
     * Builds and executes an insert
     * @param array $data       The data to be processed
     * @param array $raw_data   The raw data to processed such as functions
     * @param bool $ignore      Whether or not to run the insert as an ignore
     * @param array $columns    The on duplicate key columns to process
     */
    private function _insert(array $data, array $raw_data, $ignore, array $columns = array()){
        $dkeys   = array_keys($data);
        $rkeys   = array_keys($raw_data);
        $values  = array_values($data);
        $rvalues = array_values($raw_data);

        $dups = array();
        $keys = array_merge($dkeys, $rkeys);

        $this->_testColumns($keys);

        $q = array_pad(array(), count($data), "?");

        // Process Raw Data
        if(count($rvalues) > 0){
            foreach($rvalues as $item){
                if(isset($item["value"])){
                    $values[] = $item["value"];
                    if(isset($item["functions"]) && is_array($item["functions"])){
                        $q[] = implode("(", $item["functions"]) . "(?" . str_repeat(")", count($item["functions"]));
                    }else if(isset($item["functions"]) && is_string($item["functions"])){
                        $q[] = $item["functions"] . "(?)";
                    }
                }elseif(!isset($item["value"]) && isset($item["functions"])){
                    if(isset($item["functions"]) && is_array($item["functions"])){
                        $q[] = implode("(", $item["functions"]) . "(" . str_repeat(")", count($item["functions"]));
                    }else if(isset($item["functions"]) && is_string($item["functions"])){
                        $q[] = $item["functions"] . "()";
                    }
                }
            }
        }

        $ignoreStr = (bool)$ignore ? "ignore" : "";
        $dupKeyStr = "";
        if(count($columns) > 0){
            $dupKeyStr = " on duplicate key update ";
            foreach($columns as $key => $val){
                if(is_int($key)){
                    $this->_testColumns(array($val));
                    $dups[] = "$val = values($val)";
                }else{
                    $this->_testColumns(array($key));
                    $dups[]   = "$key = ?";
                    $values[] = $val;
                }
            }
            $dupKeyStr .= implode(",", $dups);
        }
        $this->query("insert $ignoreStr into `$this->table` (`" . implode("`,`", $keys) . "`) values (" . implode(",", $q) . ") $dupKeyStr", $values);
    }

    /**
     * Builds the columns to display
     * @return string
     */
    protected function _buildColumns(){
        $selCols = implode(",", $this->columns);
        if(empty($selCols)){
            $selCols = "*";
        }
        return $selCols;
    }

    /**
     * Builds the order clause
     * @return string
     */
    protected function _buildOrder(){
        $dir = array();
        if(empty($this->orderByCol)){
            return "";
        }
        foreach($this->orderByCol as $col => $dirc){
            $c     = $this->_formatColumns(array($col));
            $dir[] = "$c[0] " . (in_array($dirc, array("asc", "desc")) ? $dirc : "asc");
        }
        return implode(", ", $dir);
    }

    /**
     * Builds the group clause
     * @return string
     */
    protected function _buildGroup(){
        $dir = array();
        if(empty($this->groupByCol)){
            return "";
        }
        foreach($this->groupByCol as $col => $dirc){
            $c     = $this->_formatColumns(array($col));
            $dir[] = "$c[0] " . (in_array($dirc, array("asc", "desc")) ? $dirc : "asc");
        }
        return implode(", ", $dir);
    }

    /**
     * Creates a database table syntax. Example: tableA on tableB using(columnA)
     * @return type
     */
    protected function _buildTableSyntax(){
        $str = $this->table;
        foreach($this->joins as $tblJoin => $join){
            list($table, $joinType) = explode("|", $tblJoin);
            $str .= " $joinType $table ";
            $stritm = array();
            $i      = false;
            foreach($join as $j){
                $extra = "";
                if(strpos($j, "using(") === false && !$i){
                    $extra = "on";
                }
                $i        = true;
                $stritm[] = " $extra $j ";
            }
            $str .= implode(" and ", $stritm);
        }
        return $str;
    }

    protected function _buildWhere($where, $values){
        $groups = explode("?", $where);
        foreach($values as $offset => $value){
            if(is_null($value)){
                $groups[$offset] = str_replace("=", "is", $groups[$offset]);
            }
        }
        return implode("?", $groups);
    }

    /**
     * Gets data where column value equals value
     * @param string $column The column to use
     * @param mixed $value The value of the column
     * @return array
     * @throws Exception
     */
    protected function _getItemsByColumn($column, $value, $uniq = false){
        if(!$this->_validName($column)){
            throw new Exception("Invalid column format '$column'.");
        }
        $selCols = $this->_buildColumns();
        if(!(bool)$uniq){
            $array = $this->_getAll("select $selCols from `$this->table` where `$column` = ?", array($value));
        }else{
            $array = $this->_getRow("select $selCols from `$this->table` where `$column` = ? limit 1", array($value));
        }
        $this->columns = array();
        $this->setArray($array);
    }

    protected function _buildJoin(array $on){
        $keys  = array_keys($on);
        $vals  = array_values($on);
        $joins = array();
        foreach($on as $k => $v){
            if(is_int($k) && $this->_validName($v)){
                $joins[] = "using({$vals[$k]})";
            }else{
                if(!$this->_validName($k)){
                    throw new Exception("Invalid name '$k'");
                }
                if(!$this->_validName($v)){
                    throw new Exception("Invalid name '$v'");
                }
                $joins[] = "$k = {$on[$k]}";
            }
        }
        return $joins;
    }

    protected function _formatColumns(array $columns){
        $final = array();
        foreach($columns as $col){
            $newCol  = explode(".", $col);
            $final[] = "`" . implode("`.`", $newCol) . "`";
        }
        return $final;
    }

    /**
     * Gets the primary key of a table
     * @return string|boolean
     */
    private function _getPrimary(){
        if(array_key_exists($this->table, $this->table_primary_keys)){
            return $this->table_primary_keys[$this->table];
        }
        if(!$this->_validName($this->table)){
            return false;
        }
        $key = $this->getOne("select COLUMN_NAME from information_schema.COLUMNS where COLUMN_KEY = 'pri' and TABLE_NAME = ? limit 1", array($this->table));

        $this->table_primary_keys[$this->table] = $key;
        return $key;
    }

}
