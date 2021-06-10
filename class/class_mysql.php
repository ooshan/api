<?php
class Mysql{
    protected static $_mysqli;
    protected static $_instance;
    protected static $_query;
    protected static $_lastQuery;
    protected static $_db;
    protected static $_dbs;
    protected static $_parenthesisopen=array();
    protected static $_parenthesisclose=array();
    protected static $_cached=false;
    protected static $_join=array();
    protected static $_where=array();
    protected static $_orderBy=array();
    protected static $_groupBy=array();
    protected static $_bindParams=array('');
	protected static $_transaction_in_progress;
    public static $count=0;
    protected static $_stmtError;

    public function __construct($host,$username=NULL,$password=NULL,$db=NULL,$port=NULL,$charset=NULL)
    {
		if(is_array($host)){
			foreach($host as $key => $val){
				self::$_mysqli[$key]=self::connect($val["host"],$val["username"],$val["password"],$val["database"],$val["port"],$val["charset"]);
        self::$_dbs[$key]=$val["database"];
			}
			self::$_instance=key(self::$_mysqli);
		} else {
          self::$_mysqli=self::connect($host,$username,$password,$db,$port,$charset);
		}
    }

    protected static function connect($host=NULL,$username=NULL,$password=NULL,$db=NULL,$port=NULL,$charset=NULL){
  		if($port==NULL) { $port=ini_get('mysqli.default_port'); }
          if($charset==NULL) { $charset='utf8'; }
  			$mysqli=mysqli_init();
  			$mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
  			$mysqli->real_connect($host,$username,$password,$db,$port);
              if ($mysqli->connect_errno) {
  				self::error('Connection Error<br />Host='.$host.'<br />Username='.$username.'<br />Password='.$password.'<br />Db='.$db.'<br />Port='.$port,$mysqli->connect_errno,$mysqli->connect_error);
        } else { self::$_db=$db; $mysqli->set_charset($charset); /*$mysqli->query("SET @@session.wait_timeout=15");*/ }
  		return $mysqli;
  	}

    protected static function reset($first=1,$second=1,$third=1)
    {
		if($first==1){
          self::$_join=array();
          self::$_orderBy=array();
          self::$_groupBy=array();
		}
		if($second==1){
          self::$_bindParams=array('');
          self::$_query=NULL;
          self::$count=0;
		}
		if($third==1){
          self::$_where=array();
          self::$_parenthesisopen=array();
          self::$_parenthesisclose=array();
		}
    }

    public static function instance($name){
		self::$_instance=$name;
    self::changedb(self::$_dbs[$name]);
	}

    public static function cache(){
		self::$_cached=true;
	}

	public static function changedb($dbname){
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->select_db($dbname);} else {self::$_mysqli->select_db($dbname);}
	}

	public static function openParenthesis(){
		self::$_parenthesisopen[]=sizeof(self::$_where);
	}

	public static function closeParenthesis(){
		self::$_parenthesisclose[]=sizeof(self::$_where);
	}

    protected static function clearcache($table){
	  if(MemcacheHost!='MemcacheHost' && MemcacheHost!='') {
		 $history=Memcaching::get(self::$_db.'_mycached');
     $key = array_search('FROM '.$table,$history);
     if($key){
       Memcaching::delete(md5(self::$_db.$history[$key]));
       unset($history[$key]);
       Memcaching::set(self::$_db.'_mycached',$history[$key],0);
     }
	  } else if(RedisHost!='RedisHost' && RedisHost!=''){
		  $history=Rediso::read(self::$_db.'_mycached');
      $key = array_search('FROM '.$table,$history);
      if($key){
        Rediso::delete(md5(self::$_db.$history[$key]));
        unset($history[$key]);
        Rediso::remove(self::$_db.'_mycached',$history[$key]);
      }
	  }
    }

    /**
     * Pass in a raw query and an array containing the parameters to bind to the prepaird statement.
     *
     * @param string $query      Contains a user-provided query.
     * @param array  $bindParams All variables to bind to the SQL statment.
     * @param bool   $sanitize   If query should be filtered before execution
     *
     * @return array Contains the returned rows from the query.
     */
    public static function rawQuery($query,$bindParams=null,$sanitize=true)
    {
        self::$_query=$query;
        if ($sanitize){ self::$_query=filter_var($query,FILTER_SANITIZE_STRING,FILTER_FLAG_NO_ENCODE_QUOTES); }
        $stmt = self::_prepareQuery();

		self::$_lastQuery=self::replacePlaceHolders(self::$_query,$bindParams);

        if (is_array($bindParams)===true) {
            $params=array('');
            foreach($bindParams as $prop=>$val) {
                $params[0].=self::_determineType($val);
                array_push($params,$bindParams[$prop]);
            }

            call_user_func_array(array($stmt,'bind_param'),self::refValues($params));

        }

        if($stmt!=NULL){
          $stmt->execute();
		}
        self::$_stmtError=$stmt->error;
        self::reset();

        return self::_dynamicBindResults($stmt);
    }

    /**
     *
     * @param string $query   Contains a user-provided select query.
     * @param int    $RowCount The number of rows total to return.
     *
     * @return array Contains the returned rows from the query.
     */
    public static function query($query,$RowCount=null)
    {
        self::$_query=filter_var($query,FILTER_SANITIZE_STRING);
        $stmt=self::_buildQuery($RowCount);
        $stmt->execute();
        self::$_stmtError=$stmt->error;
        self::reset();

        return self::_dynamicBindResults($stmt);
    }

    /**
     * A convenient SELECT * function.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $RowCount   The number of rows total to return.
     *
     * @return array Contains the returned rows from the select query.
     */
    public static function get($tableName,$columns='*',$numrowsonly=0,$RowCount=null)
    {
        if(empty($columns)){ $columns='*'; }

        $column=is_array($columns) ? implode(', ',$columns) : $columns;
        self::$_query="SELECT ".$column." FROM ".$tableName;
        $stmt=self::_buildQuery($RowCount);

        $stmt->execute();
       self::reset();
	   if($numrowsonly==1){
			$result=$stmt->get_result();
			$stmt->close();
			return $result->num_rows;
		}
        self::$_stmtError=$stmt->error;

        return self::_dynamicBindResults($stmt);
    }

    /**
     * A convenient SELECT * function to get one record.
     *
     * @param string  $tableName The name of the database table to work with.
     *
     * @return array Contains the returned rows from the select query.
     */
    public static function getOne($tableName,$columns='*')
    {
        $res = self::get($tableName,$columns,0,1);
        if (is_object($res)){ return $res; }
        if (isset($res[0])){ return $res[0]; }

        return null;
    }


    public static function numrows($tableName){
		return self::get($tableName,'*',1);
	}

    /**
     *
     * @param <string $tableName The name of the table.
     * @param array $insertData Data containing information for inserting into the DB.
     *
     * @return boolean Boolean indicating whether the insert query was completed succesfully.
     */
    public static function insert($tableName,$insertData)
    {
		self::reset(1,0,1);
        self::$_query="INSERT into ".$tableName;
        $stmt=self::_buildQuery(null,$insertData);
        $stmt->execute();
        self::$_stmtError=$stmt->error;
        self::clearcache($tableName);
        self::reset();

        return ($stmt->affected_rows > 0 ? self::getInsertId() : false);
    }

    /**
     * Update query. Be sure to first call the "where" method.
     *
     * @param string $tableName The name of the database table to work with.
     * @param array  $tableData Array of data to update the desired row.
     *
     * @return boolean
     */
    public static function update($tableName,$tableData)
    {
        self::reset(1,0,0);
        self::$_query="UPDATE ".$tableName." SET ";

        $stmt = self::_buildQuery(null,$tableData);
        $status = $stmt->execute();
        self::clearcache($tableName);
        self::reset();
        self::$_stmtError=$stmt->error;
        self::$count=$stmt->affected_rows;

        return $status;
    }

    /**
     * Delete query. Call the "where" method first.
     *
     * @param string  $tableName The name of the database table to work with.
     * @param integer $RowCount   The number of rows to delete.
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public static function delete($tableName,$RowCount=null)
    {
        self::$_query="DELETE FROM ".$tableName;

        $stmt = self::_buildQuery($RowCount);
        $stmt->execute();
        self::$_stmtError=$stmt->error;
        self::reset();

        return ($stmt->affected_rows>0);
    }

    /**
     * This method allows you to specify multiple (method chaining optional) AND WHERE statements for SQL queries.
     *
     * @uses $MySqliDb->where('id', 7)->where('title', 'MyTitle');
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return MysqliDb
     */
    public static function where($whereProp,$whereValue=null,$operator=null)
    {
        if ($operator) { $whereValue=array($operator=>$whereValue); }
        self::$_where[]=array("AND",$whereValue,$whereProp);
    }

    /**
     * This method allows you to specify multiple (method chaining optional) OR WHERE statements for SQL queries.
     *
     * @uses $MySqliDb->orWhere('id', 7)->orWhere('title', 'MyTitle');
     *
     * @param string $whereProp  The name of the database field.
     * @param mixed  $whereValue The value of the database field.
     *
     * @return MysqliDb
     */
    public static function orWhere($whereProp,$whereValue=null,$operator=null)
    {
        if ($operator) { $whereValue=array($operator=>$whereValue); }

        self::$_where[]=array("OR",$whereValue,$whereProp);
    }
    /**
     * This method allows you to concatenate joins for the final SQL statement.
     *
     * @uses $MySqliDb->join('table1', 'field1 <> field2', 'LEFT')
     *
     * @param string $joinTable The name of the table.
     * @param string $joinCondition the condition.
     * @param string $joinType 'LEFT', 'INNER' etc.
     *
     * @return MysqliDb
     */
    public static function join($joinTable,$joinCondition,$joinType='LEFT')
    {
        $allowedTypes=array('LEFT','RIGHT','OUTER','INNER','LEFT OUTER','RIGHT OUTER');
        $joinType=strtoupper(trim($joinType));
        $joinTable=filter_var($joinTable,FILTER_SANITIZE_STRING);

        if ($joinType&&!in_array($joinType,$allowedTypes)) { die ('Wrong JOIN type: '.$joinType); }

        self::$_join[$joinType." JOIN ".$joinTable]=$joinCondition;
    }
    /**
     * This method allows you to specify multiple (method chaining optional) ORDER BY statements for SQL queries.
     *
     * @uses $MySqliDb->orderBy('id', 'desc')->orderBy('name', 'desc');
     *
     * @param string $orderByField The name of the database field.
     * @param string $orderByDirection Order direction.
     *
     * @return MysqliDb
     */
    public static function orderBy($orderByField,$orderbyDirection="DESC")
    {
        $allowedDirection=array("ASC","DESC","RAND()");
        $orderbyDirection=strtoupper(trim($orderbyDirection));
        $orderByField=preg_replace("/[^-a-z0-9\.\(\),_]+/i",'',$orderByField);

        if (empty($orderbyDirection)||!in_array($orderbyDirection,$allowedDirection)){ die ('Wrong order direction: '.$orderbyDirection); }

        self::$_orderBy[$orderByField]=$orderbyDirection;
    }

    /**
     * This method allows you to specify multiple (method chaining optional) GROUP BY statements for SQL queries.
     *
     * @uses $MySqliDb->groupBy('name');
     *
     * @param string $groupByField The name of the database field.
     *
     * @return MysqliDb
     */
    public static function groupBy($groupByField)
    {
        $groupByField=preg_replace("/[^-a-z0-9\.\(\),_]+/i",'',$groupByField);

        self::$_groupBy[]=$groupByField;
    }

    /**
     * This methods returns the ID of the last inserted item
     *
     * @return integer The last inserted item ID.
     */
    public static function getInsertId()
    {
		if(is_array(self::$_mysqli)){return self::$_mysqli[self::$_instance]->insert_id;} else {return self::$_mysqli->insert_id;}
    }

	public static function getNextId($tablo){
		self::where('table_name',$tablo);
		$query=self::getone("information_schema.tables","Auto_increment");
		return $query['Auto_increment'];
	}


    /**
     * Escape harmful characters which might affect a query.
     *
     * @param string $str The string to escape.
     *
     * @return string The escaped string.
     */
    public static function escape($str)
    {
		if(is_array(self::$_mysqli)){return self::$_mysqli[self::$_instance]->real_escape_string($str);} else {return self::$_mysqli->real_escape_string($str);}
    }

    /**
     * Method to call mysqli->ping() to keep unused connections open on
     * long-running scripts, or to reconnect timed out connections (if php.ini has
     * global mysqli.reconnect set to true). Can't do this directly using object
     * since _mysqli is protected.
     *
     * @return bool True if connection is up
     */
    public static function ping() {
		if(is_array(self::$_mysqli)){return self::$_mysqli[self::$_instance]->ping();} else {return self::$_mysqli->ping();}
    }

    /**
     * This method is needed for prepared statements. They require
     * the data type of the field to be bound with "i" s", etc.
     * This function takes the input, determines what type it is,
     * and then updates the param_type.
     *
     * @param mixed $item Input to determine the type.
     *
     * @return string The joined parameter types.
     */
    protected static function _determineType($item)
    {
        switch (gettype($item)) {
            case 'NULL':
            case 'string':
                return 's';
                break;

            case 'boolean':
            case 'integer':
                return 'i';
                break;

            case 'blob':
                return 'b';
                break;

            case 'double':
                return 'd';
                break;
        }
        return '';
    }

    /**
     * Helper function to add variables into bind parameters array
     *
     * @param string Variable value
     */
    protected static function _bindParam($value) {
        self::$_bindParams[0].=self::_determineType($value);
        array_push(self::$_bindParams,$value);
    }

    /**
     * Helper function to add variables into bind parameters array in bulk
     *
     * @param Array Variable with values
     */
    protected static function _bindParams($values) {
        foreach ($values as $value){ self::_bindParam($value); }
    }

    /**
     * Helper function to add variables into bind parameters array and will return
     * its SQL part of the query according to operator in ' $operator ?' or
     * ' $operator ($subquery) ' formats
     *
     * @param Array Variable with values
     */
    protected static function _buildPair($operator,$value) {
        if (!is_object($value)){
            self::_bindParam($value);
            return ' '.$operator.' ? ';
        }

        $subQuery=$value->getSubQuery();
        self::_bindParams($subQuery['params']);

        return " ".$operator." (".$subQuery['query'].")";
    }

    /**
     * Abstraction method that will compile the WHERE statement,
     * any passed update data, and the desired rows.
     * It then builds the SQL query.
     *
     * @param int   $RowCount   The number of rows total to return.
     * @param array $tableData Should contain an array of data for updating the database.
     *
     * @return mysqli_stmt Returns the $stmt object.
     */
    protected static function _buildQuery($RowCount=null,$tableData=null)
    {
        self::_buildJoin();
        self::_buildTableData($tableData);
        self::_buildWhere();
        self::_buildGroupBy();
        self::_buildOrderBy();
        self::_buildLimit($RowCount);

        self::$_lastQuery=self::replacePlaceHolders(self::$_query,self::$_bindParams);

        $stmt=self::_prepareQuery();

        if (count(self::$_bindParams)>1){ call_user_func_array(array($stmt, 'bind_param'), self::refValues(self::$_bindParams)); }

        return $stmt;
    }

    /**
     * This helper method takes care of prepared statements' "bind_result method
     * , when the number of variables to pass is unknown.
     *
     * @param mysqli_stmt $stmt Equal to the prepared statement object.
     *
     * @return array The results of the SQL fetch.
     */
    protected static function _dynamicBindResults(mysqli_stmt $stmt)
    {


	if(self::$_cached==true){
	self::$_cached=false;
    $keyi=md5(self::$_db.self::$_lastQuery);
	if(MemcacheHost!='MemcacheHost' && MemcacheHost!='') {
		  if(sizeof(Memcaching::getServerList())>0){
		    $data=Memcaching::get($keyi);
		      if(isset($data) && empty($data)){
			  $data=self::_dynamicBindResults($stmt);
			  Memcaching::set($keyi,$data);
			  $history=Memcaching::get(self::$_db.'_mycached');
			    if(isset($history) && empty($history)){
			       Memcaching::set(self::$_db.'_mycached',array(self::$_lastQuery));
				} else {
					$history[]=self::$_lastQuery;
					Memcaching::set(self::$_db.'_mycached',$history,0);
				}
		      }
		   }	 else { error(self::$_lastQuery,'memcache connect error'); }
		} else if (RedisHost!='RedisHost' && RedisHost!='') {
			if(Rediso::ping()){
			  $data=Rediso::get($keyi);
			  if(isset($data) && empty($data)){
			    $data=self::_dynamicBindResults($stmt);
			    Rediso::set($keyi,$data,0);
			    Rediso::add(self::$_db.'_mycached',self::$_lastQuery);
		      }
			} else { error(self::$_lastQuery,'redis connect error'); }
		}
          return $data;

		}


        $parameters=array();
        $results=array();

        if($stmt!=NULL){
          $meta=$stmt->result_metadata();
		}
        // if $meta is false yet sqlstate is true, there's no sql error but the query is
        // most likely an update/insert/delete which doesn't produce any results
        if(!$meta&&$stmt->sqlstate) {
            return array();
        }

        $row = array();
		if($meta!=NULL){
          while ($field=$meta->fetch_field()) {
            $row[$field->name]=null;
            $parameters[]=& $row[$field->name];
          }
		}
        if (version_compare(phpversion(),'5.4','<')){ $stmt->store_result(); }


        call_user_func_array(array($stmt,'bind_result'),$parameters);

        if($stmt!=NULL){
          while($stmt->fetch()){
            $x=array();
            foreach ($row as $key=>$val) {
                $x[$key]=$val;
            }
            self::$count++;
            array_push($results,$x);
          }
		}

        return $results;
    }


    /**
     * Abstraction method that will build an JOIN part of the query
     */
    protected static function _buildJoin(){
        if (empty(self::$_join)){ return; }

        foreach (self::$_join as $prop=>$value){ self::$_query.=" ".$prop." ON ".$value; }
    }

    /**
     * Abstraction method that will build an INSERT or UPDATE part of the query
     */
    protected static function _buildTableData($tableData) {
        if (!is_array($tableData)){ return; }

        $isInsert=strpos(self::$_query,'INSERT');
        $isUpdate=strpos(self::$_query,'UPDATE');

        if ($isInsert!==false){
            self::$_query.='(`'.implode(array_keys($tableData),'`, `').'`)';
            self::$_query.=' VALUES(';
        }

        foreach ($tableData as $column=>$value) {
            if ($isUpdate!==false){ self::$_query.="`".$column."` = "; }

            // Subquery value
            if (is_object($value)){
                self::$_query.=self::_buildPair("",$value).", ";
                continue;
            }

            // Simple value
            if (!is_array($value)){
                self::_bindParam($value);
                self::$_query.='?, ';
                continue;
            }

            // Function value
            $key=key($value);
            $val=$value[$key];
            switch($key){
                case '[I]':
                    self::$_query.=$column.$val.", ";
                    break;
                case '[F]':
                    self::$_query.=$val[0].", ";
                    if (!empty($val[1])){ self::_bindParams($val[1]); }
                    break;
                case '[N]':
                    if ($val==null){ self::$_query.= "!".$column.", "; }
                    else
                        self::$_query.="!".$val.", ";
                    break;
                default:
                    die ("Wrong operation");
            }
        }
        self::$_query=rtrim(self::$_query,', ');
        if ($isInsert!==false)
            self::$_query.=')';
    }

    /**
     * Abstraction method that will build the part of the WHERE conditions
     */
    protected static function _buildWhere(){
        if (empty (self::$_where)){ return; }

        //Prepair the where portion of the query
        self::$_query.=' WHERE ';

        // Remove first AND/OR concatenator
        self::$_where[0][0]='';
		$count=0;
        foreach (self::$_where as $cond){
            list ($concat,$wValue,$wKey)=$cond;

			if(in_array($count,self::$_parenthesisopen)){ $op='('; } else { $op=''; }

            self::$_query.=" ".$concat." ".$op.$wKey;

            // Empty value (raw where condition in wKey)
            if ($wValue===null){ continue; }

            // Simple = comparison
            if (!is_array($wValue)){ $wValue=array('='=>$wValue); }

            $key=key($wValue);
            $val=$wValue[$key];
            switch (strtolower($key)) {
                case '0':
                    self::_bindParams($wValue);
                    break;
                case 'not in':
                case 'not In':
                case 'in':
                case 'In':
                    $comparison=' '.$key.' (';
                    if (is_object($val)){
                        $comparison.=self::_buildPair("", $val);
                    } else {
                        foreach($val as $v){
                            $comparison.=' ?,';
                            self::_bindParam($v);
                        }
                    }
                    self::$_query.=rtrim($comparison,',').' ) ';
                    break;
                case 'not between':
                case 'between':
                    self::$_query.=' '.$key." ? AND ? ";
                    self::_bindParams($val);
                    break;
                case 'not exists':
                case 'not exIsts':
                case 'exists':
                case 'exIsts':
                    self::$_query.=' '.$key.self::_buildPair("",$val);
                    break;
                default:
                    self::$_query.=self::_buildPair($key,$val);
            }
            $count++;
		if(in_array($count,self::$_parenthesisclose)){ self::$_query.=')'; }
		}
    }

    /**
     * Abstraction method that will build the GROUP BY part of the WHERE statement
     *
     */
    protected static function _buildGroupBy(){
        if (empty(self::$_groupBy)){ return; }

        self::$_query.=" GROUP BY ";
        foreach (self::$_groupBy as $key=>$value){ self::$_query.=$value.", "; }

        self::$_query=rtrim(self::$_query,', ')." ";
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int   $RowCount   The number of rows total to return.
     */
    protected static function _buildOrderBy(){
        if(empty(self::$_orderBy)){ return; }

        self::$_query.=" ORDER BY ";
        foreach (self::$_orderBy as $prop=>$value){ self::$_query.=$prop." ".$value.", "; }

        self::$_query=rtrim(self::$_query,', ')." ";
    }

    /**
     * Abstraction method that will build the LIMIT part of the WHERE statement
     *
     * @param int   $RowCount   The number of rows total to return.
     */
    protected static function _buildLimit($RowCount) {
        if (!isset ($RowCount)){ return; }
        if (is_array($RowCount)){ self::$_query.=' LIMIT '.(int)$RowCount[0].', '.(int)$RowCount[1]; }
        else { self::$_query.=' LIMIT '.(int)$RowCount; }
    }

    /**
     * Method attempts to prepare the SQL query
     * and throws an error if there was a problem.
     *
     * @return mysqli_stmt
     */
    protected static function _prepareQuery()
    {
		if(is_array(self::$_mysqli)){$stmt = self::$_mysqli[self::$_instance]->stmt_init();} else {$stmt = self::$_mysqli->stmt_init();}
        if ($stmt!=NULL&&!$stmt->prepare(self::$_query)){
			self::error(self::$_query);
        }
        return $stmt;
    }

    /**
     * @param array $arr
     *
     * @return array
     */
    protected static function refValues($arr)
    {
        if (strnatcmp(phpversion(),'5.3')>=0) {
            $refs = array();
            foreach ($arr as $key=>$value) {
                $refs[$key]=&$arr[$key];
            }
            return $refs;
        }
        return $arr;
    }

    /**
     * Function to replace ? with variables from bind variable
     * @param string $str
     * @param Array $vals
     *
     * @return string
     */
    protected static function replacePlaceHolders($str,$vals) {
        $i=1;
        $newStr="";

        while($pos=strpos($str,"?")){
            $val=$vals[$i++];
            if(is_object($val)){ $val='[object]'; }
            $newStr.=substr($str,0,$pos).$val;
            $str=substr($str,$pos+1);
        }
        $newStr.=$str;
        return $newStr;
    }

    /**
     * Method returns last executed query
     *
     * @return string
     */
    public static function getLastQuery(){
        return self::$_lastQuery;
    }

    /**
     * Method returns mysql error
     *
     * @return string
     */
    public static function getLastError(){
		if(is_array(self::$_mysqli)){$error = self::$_mysqli[self::$_instance]->error;} else {$error = self::$_mysqli->error;}
        return trim (self::$_stmtError." ".$error);
    }

    /**
     * Mostly internal method to get query and its params out of subquery object
     * after get() and getAll()
     *
     * @return array
     */
    public static function getSubQuery() {

        array_shift(self::$_bindParams);
        $val=array('query'=>self::$_query,'params'=>self::$_bindParams);
        self::reset();
        return $val;
    }

    /* Helper functions */
    /**
     * Method returns generated interval function as a string
     *
     * @param string interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string Initial date
     *
     * @return string
     */
    public static function interval($diff,$func="NOW()"){
        $types=array("s"=>"second","m"=>"minute","h"=>"hour","d"=>"day","M"=>"month","Y"=>"year");
        $incr='+';
        $items='';
        $type='d';

        if ($diff&&preg_match('/([+-]?) ?([0-9]+) ?([a-zA-Z]?)/',$diff,$matches)){
            if(!empty($matches[1])){ $incr = $matches[1]; }
            if(!empty($matches[2])){ $items = $matches[2]; }
            if(!empty($matches[3])){ $type = $matches[3]; }
            if(!in_array($type,array_keys($types))){ trigger_error("invalid interval type in '{$diff}'"); }
            $func.=" ".$incr." interval ".$items." ".$types[$type]." ";
        }
        return $func;

    }
    /**
     * Method returns generated interval function as an insert/update function
     *
     * @param string interval in the formats:
     *        "1", "-1d" or "- 1 day" -- For interval - 1 day
     *        Supported intervals [s]econd, [m]inute, [h]hour, [d]day, [M]onth, [Y]ear
     *        Default null;
     * @param string Initial date
     *
     * @return array
     */
    public static function now($diff=null,$func="NOW()"){
        return array("[F]"=>array(self::interval($diff,$func)));
    }

    /**
     * Method generates incremental function call
     * @param int increment amount. 1 by default
     */
    public static function inc($num=1){
        return array("[I]"=>"+".(int)$num);
    }

    /**
     * Method generates decrimental function call
     * @param int increment amount. 1 by default
     */
    public static function dec($num=1) {
        return array("[I]"=>"-".(int)$num);
    }

    /**
     * Method generates change boolean function call
     * @param string column name. null by default
     */
    public static function not($col=null) {
        return array("[N]"=>(string)$col);
    }

    /**
     * Method generates user defined function call
     * @param string user function body
     */
    public static function func($expr,$bindParams=null) {
        return array("[F]"=>array($expr,$bindParams));
    }

    /**
     * Begin a transaction
     *
     * @uses mysqli->autocommit(false)
     * @uses register_shutdown_function(array($this, "_transaction_shutdown_check"))
     */
    public static function startTransaction(){
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->autocommit(false);} else {self::$_mysqli->autocommit(false);}
        self::$_transaction_in_progress=true;
        register_shutdown_function(array('mysql',"_transaction_status_check"));
    }

    /**
     * Transaction commit
     *
     * @uses mysqli->commit();
     * @uses mysqli->autocommit(true);
     */
    public static function commit(){
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->commit();} else {self::$_mysqli->commit();}
        self::$_transaction_in_progress=false;
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->autocommit(true);} else {self::$_mysqli->autocommit(true);}
    }

    /**
     * Transaction rollback function
     *
     * @uses mysqli->rollback();
     * @uses mysqli->autocommit(true);
     */
    public static function rollback(){
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->rollback();} else {self::$_mysqli->rollback();}
        self::$_transaction_in_progress=false;
		if(is_array(self::$_mysqli)){self::$_mysqli[self::$_instance]->autocommit(true);} else {self::$_mysqli->autocommit(true);}
    }

    /**
     * Shutdown handler to rollback uncommited operations in order to keep
     * atomic operations sane.
     *
     * @uses mysqli->rollback();
     */
    public static function _transaction_status_check() {
        if(!self::$_transaction_in_progress){ return; }
        self::rollback();
    }

    public static function error($sql='',$errorno=Null,$error=Null){
		if(is_array(self::$_mysqli)){$errn=self::$_mysqli[self::$_instance]->errno;} else {$errn=self::$_mysqli->errno;}
		if(is_array(self::$_mysqli)){$err=self::$_mysqli[self::$_instance]->error;} else {$err=self::$_mysqli->error;}

		if($errorno){ $errn=$errorno; }
		if($error){ $err=$error; }
        $text= $sql.'<br /><br />Error No: '.$errn.'<br />Error: '.$err.'<br />-----------------------------------------------------------------------------------<br />';
		if(!Errors::report('Mysql Error',$text,1)){
			//E_USER_ERROR    E_USER_WARNING     E_USER_NOTICE
			trigger_error($text,E_USER_NOTICE);
		}
    }
}

$mysql = new Mysql('localhost','salmaner','2r*57Esv','salmaner');

//birden fazla bağlantı için aşağıdakini kullanın, bağlantılar arası gecis yapmak için Mysql::instance('mysqlGlobal')
/*$mysql = new Mysql(array("conn"=>array("host"=>"192.168.1.122","username"=>"phpserver","password"=>"yyyyy","database"=>"remax_db"),"conn121"=>array("host"=>"192.168.1.121","username"=>"phpserver","password"=>"xxxx","database"=>"remax_db"),"connGlobal"=>array("host"=>"192.168.1.121","username"=>"phpserverGlobal","password"=>"zzzz","database"=>"globaldata")));
*/
?>
