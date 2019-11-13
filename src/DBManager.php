<?
/**
 * Wrapper class to work with MYSQL DB
 *
 * @author Admin <support@1000.menu>
 * @author Anatoli <freeworknet@yandex.ru>
 */


namespace XrTools\DBM;

class DBManager{
	/** @var \PDO $instancePDO Instance of the "PDO" class */
	private $instancePDO = null;
	
	/** @var \XrTools\DBM\MemcachedManager $instanceMM Reference to an  instance of the "MemcachedManager" class */
	private $instanceMM = null;
	
	/** @var array $db_params Settings for database connection */
	private $db_params = [];
	
	/** @var string $resultMessage */
	private $resultMessage = '';
	
	/**
	 * DBManager constructor.
	 * The cache requires an instance of the class "MemcachedManager"
	 *
	 * @param array                              $db_params
	 * @param \XrTools\DBM\MemcachedManager|NULL $instanceMM
	 */
	public function __construct(array $db_params, MemcachedManager &$instanceMM = null){
		$this->db_params = $db_params;
		
		$this->instanceMM = $instanceMM instanceof MemcachedManager ? $instanceMM : null;
	}
	
	/**
	 * Return the message describing the result of the last operation
	 *
	 * @return string
	 */
	public function getResultMessage(){
		return $this->resultMessage;
	}
	
	/**
	 * Key generation for queries to the database with a WHERE clause {col IN (?,?,...).
	 * Can be generated either question marks (?), or names.
	 * Example:
	 * <pre>
	 *        // default (question marks):
	 *        $data = array('foo', 'bar');
	 *        $result = mysql_do('INSERT INTO some_table (col_name) VALUES ('.arr_in($data).')', $data);
	 *        // Query: INSERT INTO some_table (col_name) VALUES (?,?)
	 *
	 *        // named indexes
	 *        $data = array(':foo' => 'bar', ':not_foo' => 'not_bar');
	 *        $result = mysql_do('INSERT INTO some_table (col_name) VALUES ('.arr_in($data).')', $data);
	 *        // Query: INSERT INTO some_table (col_name) VALUES (:foo, :not_foo)
	 * </pre>
	 *
	 * @param  array          $arr      Data array
	 * @param  boolean|string $prefix   If FALSE (default), then question marks (?) are generated if data array
	 *                                  is numerically indexed [0=>…, 1=>…], otherwise array keys names are
	 *                                  used.<br> IF STRING is passed, then it is used as prefix to every array
	 *                                  key number (not key name itself, but it's numerical position)
	 * @param  boolean        $force_q  Force function to generate question marks (?) even if data array is not
	 *                                  numerically indexed (see $prefix = FALSE)
	 *
	 * @return string                   Generated string
	 */
	public function arrIn($arr = array(), $prefix = false, $force_q = false){
		$return = '';
		
		if(is_array($arr) && !empty($arr)){
			
			// if we use names and need a prefix to the keys
			if($prefix !== false){
				for ($i = 0, $c = count($arr); $i < $c; $i++){
					$return .= ($i ? ',' : '') . $prefix . $i;
				}
				
			}// if we use a numbered field
			elseif(isset($arr[0]) || $force_q){
				$return = implode(',', array_fill(1, count($arr), '?'));
				
			}// if we use names
			else
				$return = implode(',', array_keys($arr));
		}
		return $return;
	}
	
	/**
	 * Indexes an array by the specified element key in the array.
	 * Example: <br>
	 * <pre>
	 *    $original_array  = [ 0 => ['id'=>1, 'name'=>'test 1'], 1 => ['id'=>2, 'name'=>'test 2'] ] <br>
	 *    $result_array    = arr_index( $original_array, 'id' ) <br>
	 *                   => [ <b>1</b> => ['id'=>1, 'name'=>'test 1'], <b>2</b> => ['id'=>2, 'name'=>'test 2'] ]
	 * </pre>
	 *
	 * @param  array  $arr    Array to index
	 * @param  string $by_key Items array key name to index the $arr by
	 *
	 * @return array           Indexed array
	 */
	public function arrIndex($arr, $by_key){
		if(!$by_key || !is_array($arr)){
			return $arr;
		}
		
		$ret = array();
		
		foreach ($arr as $item){
			
			if(!isset($item[$by_key])){
				return $arr;
			}
			
			$ret[$item[$by_key]] = $item;
		}
		
		return $ret;
	}
	
	/**
	 * Grouping the array by the selected index with the ability to filter
	 * @param  array   $arr       Array
	 * @param  string  $index     Selected key name to group array by
	 * @param  array   $selective Selective mode. Filter result array by selected keys
	 * @param  array   $params    Settings:
	 *                             <ul>
	 *                             		<li> <strong> direct_value </strong> bool (false)
	 *                             		 - Works only in Selective mode!
	 *                             		 Use direct value instead of array as item in the result array (e.g. when only one key in $selective).
	 *                             </ul>
	 * @return array             Groupped array
	 */
	public function arrayGroupBy($arr = array(), $index = '', $selective = array(), $params = array()){
		
		$result = array();
		
		if(empty($arr)){
			return $result;
		}
		
		// if you want to save only selected keys
		$save_full_row = empty($selective) || !is_array($selective);
		
		// if you don't need an array, just a value in selective
		$direct_value = !empty($params['direct_value']);
		
		// go through the array and group
		foreach ($arr as $row){
			if(!isset($row[$index])){
				break;
			}
			
			if($save_full_row){
				$result[$row[$index]][] = $row;
			}
			// If we save only selected columns
			else {
				$tmp = array();
				foreach ($selective as $col){
					if(!isset($row[$col]))
						continue;
					
					// If set to a direct recording (without a massive)
					if($direct_value){
						$tmp = $row[$col];
						break;
					}
					
					$tmp[$col] = $row[$col];
				}
				$result[$row[$index]][] = $tmp;
			}
		}
		
		return $result;
	}
	
	
	/**
	 * Creating a database connection instance.
	 * To view service messages call getResultMessage() (more detailed: @see \XrTools\DBM\DBManager::getResultMessage())
	 *
	 * @return bool
	 */
	protected function setInstancePDO(){
		
		if(!is_null($this->instancePDO)){
			return true;
		}
		
		// Check database parameters.
		if( empty($this->db_params) ||
			empty($this->db_params['name']) ||
			empty($this->db_params['login']) ||
			empty($this->db_params['password']) ||
			empty($this->db_params['host'])
		){
			$this->resultMessage = "DBManager: the database parameters are not set correctly!";
			
			return false;
		}
		
		// Connection to the database.
		try{
			$this->instancePDO = new \PDO(
				"mysql:host={$this->db_params['host']}; dbname={$this->db_params['name']}; charset=utf8",
				$this->db_params['login'],
				$this->db_params['password'],
				[
					\PDO::ATTR_EMULATE_PREPARES => false,
					\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
				]
			);
			
			return true;
			
		}catch(\PDOException $e){
			$this->resultMessage = "DBManager: failed database connection. Error: {$e->getMessage()} ({$e->getCode()}).";
			
			return false;
		}
	}
	
	/**
	 * The database query.
	 *
	 * @param  string $sql    SQL query string
	 * @param  array  $values SQL query values array (prepared statements)
	 * @param  array  $params Settings:
	 *                        	<ul>
	 *                        		<li> <strong> bind_type </strong>
	 *                                - Check for binding type in query values (expected $values format:
	 * 								  [item	=> ['name' => 'item_name', 'type' => 'str|int'], … ])
	 *                        	</ul>
	 *
	 * @return array          Result array [<b>status</b> bool, <b>message</b> string, <b>affected</b> int,
	 *                        <b>insert_id</b> int].
	 */
	public function do(string $sql = '', array $values = [], array $params = []){
		// prepare result array
		$return = [
			'status' => false,
			'message' => 'DBManager: Query <br>' . $sql,
			'affected' => 0
		];
		
		// lazy connection
		if(!$this->setInstancePDO()){
			
			$return['message'] = $this->getResultMessage();
			
			return $return;
		}
		
		// We reset the messages of the last operation
		$this->resultMessage = '';
		
		try{
			// if is values
			if($values){
				$return['message'] .= '<br> Bound values: <pre>' . print_r($values, true) . '</pre>';
				
				$result = $this->instancePDO->prepare($sql);
				
				// if type binding is used
				if(!empty($params['bind_type'])){
					
					foreach($values as $a_val){
						if($a_val['type'] == 'str')
							$result->bindValue(':' . $a_val['name'], $a_val['val'], \PDO::PARAM_STR);
						elseif($a_val['type'] == 'int')
							$result->bindValue(':' . $a_val['name'], $a_val['val'], \PDO::PARAM_INT);
					}
					
					$result->execute();
					
				}else{
					$result->execute($values);
				}
				
				$return['affected'] = $result->rowCount();
				
			}else{
				$result             = $this->instancePDO->exec($sql);
				$return['affected'] = $result;
			}
			
			$return['insert_id'] = $this->instancePDO->lastInsertId();
			$return['status']    = true;
			
		}catch(\PDOException $e){
			$return['message'] .= '<br>' . $e->getMessage() . "({$e->getCode()})";
		}
		
		return $return;
	}
	
	/**
	 * Returns an array of rows from a database on a SQL query
	 * To view service messages call getResultMessage() (more detailed: @see \XrTools\DBM\DBManager::getResultMessage())
	 *
	 * @param  string       $sql      SQL query string
	 * @param  array        $values   SQL query vals array (prepared statements). If empty, prepared statements are not used
	 * @param  array|string $params   If string, then it is used as shortcut to    $param['arr_index'] - see below.<br>
	 *                                If array, then it is used as settings with these options:
	 *                                <ul>
	 *                                  <li> <strong> debug </strong> bool (false)
	 * 										 - Debug mode
	 *                                  <li> <strong> arr_index </strong> string
	 * 										 - Use arr_index() function on result array (indexing array by selected column name)
	 *                                  <li> <strong> cache </strong> bool (false)
	 * 										 - Try to load cached data before querying the SQL server
	 *                                  <li> <strong> renew_cache </strong> bool (false)
	 * 										 - Flush data in cache and build new cache
	 *                                  <li> <strong> cache_time </strong> integer
	 *                                  	 - Cache expiration time (if cache is on)
	 *                                  <li> Cache modes (if cache is on):
	 *                                  	<ul>
	 *                                  	  <li> Storing the whole list in one cache entry:
	 *                                  		<ul>
	 *                                  		  <li> <strong> cache_key </strong> string
	 *                                 				   - Cache key name
	 *                                  		</ul>
	 *                                  	  <li> Storing each row of the list in separate
	 *                                  		   cache entry (works only with prepared statements):
	 *                                  		<ul>
	 *                                 			  <li> <strong> ! cache_prefix </strong> string
	 *                                  			   - Cache keys names prefix
	 *                                 			  <li> <strong> ! cache_bycol </strong> string
	 *                                  			   - Column name associated with $values values
	 *                                 			  <li> <strong> cache_bycol_sql </strong> string
	 *                                  			   - Column name associated with $values values in
	 *                                  				SQL query (default: cache_bycol)
	 *                                  		  <li> <strong> cache_bycol_group </strong> array
	 *                                  			   - Selective columns in cache entries (default:
	 *                                  			     NULL - every query column is selected)
	 *                                  		  <li> <strong> cache_bycol_group_value </strong> bool (default: false)
	 *                                  			   - Use direct value in cache entry instead of
	 *                                  			     array. E.g. if array has only one index.
	 *                                  		  <li> <strong> cache_key_num </strong> bool (false)
	 *                                  			   - Use numerical filter on $values (also true when cache_bycol == 'id')
	 *                                  		</ul>
	 *                                 		 </ul>
	 *                                </ul>
	 *
	 * @return array|boolean    Returned data array or FALSE when failed
	 */
	public function getArr(string $sql, array $values = [], array $params = []){
		if(!$sql){
			return false;
		}
		
		// We reset the messages of the last operation
		$this->resultMessage = 'DBManager: Query <br>' . $sql;
		
		// variable for cache
		$found_in_cache = false;
		$cache          = false;
		$cache_list     = false;
		
		// if requested to ship the first cache
		// (selection by criteria + issuance of objects from the database if not found in the cache)
		if(!empty($params['cache']) && !empty($this->instanceMM)){
			
			// If it is caching the elements in the array individually (i.e. each object has its own key)
			if($values && is_array($values) && !empty($params['cache_prefix']) && !empty($params['cache_bycol'])){
				
				$cache = true;
				
				$this->resultMessage .= 'Cache query keys: <br>';
				
				// to query a database of items that are not in the cache
				$db_check = [];
				
				// for the query from the cache
				$mc_keys        = [];
				$found_in_cache = [];
				
				// collect the keys (check the numbers if you need)
				$check_num = $params['cache_bycol'] == 'id' || !empty($params['cache_key_num']);
				
				foreach($values as $val){
					if(!$check_num || is_num($val, true)){
						$tmp           = $params['cache_prefix'] . $val;
						$mc_keys[$val] = $tmp;
						
						$this->resultMessage .= $tmp . '<br>';
					}
				}
				
				// if the ID for the keys could not be collected`
				if(!$mc_keys){
					$this->resultMessage = 'DBManager: Cache keys init failed<br>' . $this->resultMessage;
					
					return false;
				}
				
				// Read cache
				$cached = empty($params['renew_cache']) ? $this->instanceMM->get(array_values($mc_keys), true) : false;
				
				if($cached){
					
					foreach($mc_keys as $val => $mc_key){
						// found in the cache
						if(isset($cached[$mc_key]) && $cached[$mc_key] !== false){
							$found_in_cache[$val] = $cached[$mc_key];
						}// need to request from the database
						else{
							$db_check[] = $val;
						}
					}
					// if you no longer need to query the database
					if(!$db_check){
						
						$this->resultMessage = 'DBManager: Cached results found. Skip bd query<br>' . $this->resultMessage;
						
						return empty($params['arr_index']) ?
							array_values($found_in_cache) :
							($params['arr_index'] == $params['cache_bycol']
								? $found_in_cache
								: $this->arrIndex($found_in_cache, $params['arr_index']));
						
					}
				}// if the cache is empty, request everything from the database
				else{
					$db_check = array_keys($mc_keys);
				}
				
				// we transfer the data to the main variables
				$values = $db_check;
				
				// we add to the query a selection according to the specified criteria
				// (the query must have a WHERE at the end - for security,
				// so that in the opposite case it gives an error and does not return unfiltered data)
				$sql .= ' ' . (!empty($params['cache_bycol_sql']) ? $params['cache_bycol_sql'] : '`' .
						$params['cache_bycol'] . '`') . ' IN (' . $this->arrIn($values) . ')';
			}// if it is caching the entire list under one key
			elseif(!empty($params['cache_key']) && is_string($params['cache_key'])){
				$cache_list = true;
				
				// if it is part of a multi-list with multiple keys keep another key in which
				// we store the Postfix for the rest of the keys
				// (works as a version of the whole list)
				if(!empty($params['cache_version_key']) && !empty($params['cache_time'])){
					// download the list version
					$list_version = $this->instanceMM->get($params['cache_version_key']);
					
					// create a new list version key and disable the cache
					if(!$list_version){
						$list_version = time();
						
						// the entry version of the list into the cache
						$this->instanceMM->set($params['cache_version_key'], $list_version, $params['cache_time']);
					}
					
					// add version to key names
					$params['cache_key'] .= '_' . $list_version;
				}
				
				$cached = empty($params['renew_cache']) ? $this->instanceMM->get($params['cache_key'], true) : false;
				if($cached !== false){
					
					$this->resultMessage .= 'Cached list results found under key "' . $params['cache_key'] . '". Skip query <br>' . $sql;
					
					return empty($params['arr_index']) ? $cached : $this->arrIndex($cached, $params['arr_index']);
				}
			}
		}elseif(!empty($params['cache']) && empty($this->instanceMM)){
			$this->resultMessage .= 'Cache mode is enabled, but there is no instance of class "MemcachedManager" ';
		}
		
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		$return = false;
		
		try{
			if($values){
				
				$this->resultMessage .= '<br> Bound values: <pre>' . print_r($values, true) . '</pre>';
				
				$result = $this->instancePDO->prepare($sql);
				$result->execute($values);
				
			}else{
				$result = $this->instancePDO->query($sql);
			}
			
			if($cache){
				$db_data = false;
			}
			
			if($result->rowCount()){
				
				// Record the result
				$return = $result->fetchAll(\PDO::FETCH_ASSOC);
				
				// In case we cache each row, replenish the array to check for
				// missing elements in the cache in the next step
				if($cache){
					// If you want to group the list
					if(!empty($params['cache_bycol_group'])){
						$db_data = $this->arrayGroupBy($return,
												 $params['cache_bycol'],
												 $params['cache_bycol_group'],
												 [
													 // If you do not need to return an array
													 // (e.g. when only one key is selected in selective)
													 'direct_value' => !empty($params['cache_bycol_group_value'])
												 ]
						);
						
						// In this case, it is necessary to rewrite the result
						// so that it is correctly supplemented with elements from the cache
						// because the results are already grouped in the cache
						$return = $db_data;
					}// If you do not need to group, just rename the keys to make it easier to search in the cache
					else{
						$db_data = $this->arrIndex($return, $params['cache_bycol']);
					}
				}
			}else{
				$return = [];
			}
			
			// Add as a result found items from the cache
			if(!empty($found_in_cache)){
				
				
				$this->resultMessage .= '<br> FOUND IN CACHE: <pre>' . print_r($values, true) . '</pre>';
				
				foreach($found_in_cache as $key => $val){
					if(empty($params['cache_bycol_group'])){
						$return[] = $val;
					}else{
						$return[$key] = $val;
					}
				}
			}
			
			if(!empty($params['arr_index']) && empty($params['cache_bycol_group'])){
				$return = $this->arrIndex($return, $params['arr_index']);
			}
			
			// if the cache is set, write to it what was found in the database
			if(!empty($params['cache_time'])){
				$to_cache = [];
				
				// save to the list only downloaded from the database
				if($cache){
					foreach($db_check as $val){
						$mc_key = $mc_keys[$val];
						
						if($db_data[$val] === false){
							continue;
						}
						
						$to_cache[$mc_key] = $db_data[$val];
					}
				}elseif($cache_list && $return !== false){
					$to_cache[$params['cache_key']] = $return;
				}
				
				if($to_cache){
					
					$this->resultMessage .= '<br> Saving to memcached: <br><pre>' . print_r($values, true) . '</pre>';
					
					$this->instanceMM->set($to_cache, false, $params['cache_time'], true);
				}
			}
		}catch(\PDOException $e){
			
			$this->resultMessage .= '<br><br>' . $e->getMessage();
		}
		
		return $return;
	}
	
	/**
	 * Returns an array of the first row from the database on a SQL query
	 * To view service messages call getResultMessage() (more detailed: @see \XrTools\DBM\DBManager::getResultMessage())
	 *
	 * @param  string $sql     SQL query string
	 * @param  array  $values  SQL query vals array (prepared statements). If empty, prepared
	 *                         statements are not used
	 * @param  array  $params  Settings:
	 *                         	<ul>
	 *                         	  <li> <strong> debug </strong> bool (false)
	 *                         	  		- Debug mode
	 *                         	  <li> <strong> cache </strong> bool (false)
	 *                         	  		- Try to load cached data before querying the SQL server
	 *                         	  <li> <strong> cache_time </strong> integer
	 *                         	  		- Cache expiration time (if cache is on)
	 *                         	  <li> <strong> cache_key </strong> string
	 *                         	  		- Cache key name
	 *                         	  <li> <strong> renew_cache </strong> boolean
	 *                         	  		- Flush cache and rebuild new. Default: false
	 *                         	</ul>
	 *
	 * @return array|boolean    Returned data array or FALSE when failed
	 */
	function getList(string $sql, array $values = [], array $params = []){
		if(!$sql){
			return false;
		}
		
		// We reset the messages of the last operation
		$this->resultMessage = '';
		
		$use_cache = !empty($params['cache']) && !empty($params['cache_key']);
		
		if($use_cache && !empty($this->instanceMM)){
			$cached = empty($params['renew_cache']) ? $this->instanceMM->get($params['cache_key'], true) : false;
			if($cached !== false){
				$this->resultMessage .= 'DBManager: Cached list results found under key "' . $params['cache_key'] . '". Skip query <br>' . $sql;
				
				return $cached;
			}
		}elseif($use_cache && empty($this->instanceMM)){
			$this->resultMessage .= 'DBManager: Cache mode is enabled, but there is no instance of class "MemcachedManager"<br>';
		}
		
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		$return  = false;
		$this->resultMessage .= 'DBManager: Query <br>' . $sql;
		
		
		try{
			if($values){
				$this->resultMessage .= '<br> Bound values: <pre>' . print_r($values, true) . '</pre>';
				
				
				$result = $this->instancePDO->prepare($sql);
				$result->execute($values);
				
			}else{
				$result = $this->instancePDO->query($sql);
			}
			
			if($result->rowCount()){
				$return = $result->fetch(\PDO::FETCH_ASSOC);
			}
			
			// if the cache is enabled, write what was found in the database
			if($use_cache && !empty($params['cache_time'])  && !empty($this->instanceMM)){
				
				// save downloaded from the database
				$to_cache = $return ? $return : [];
				
				$this->resultMessage .= '<br> Saving to memcached key: ' . $params['cache_key'] . '<br> data: <pre>' . print_r($to_cache, true) . '</pre>';
				
				
				$this->instanceMM->set($params['cache_key'], $to_cache, $params['cache_time'], true);
			}
		}catch(\PDOException $e){
			
			$this->resultMessage .= '<br><br>' . $e->getMessage();
			
		}
		
		return $return;
	}
	
	/**
	 * Returns a single cell from the database on a SQL query
	 * To view service messages call getResultMessage() (more detailed: @see \XrTools\DBM\DBManager::getResultMessage())
	 *
	 * @param  string $sql     SQL query string
	 * @param  array  $values  SQL query vals array (prepared statements). If empty, prepared
	 *                         statements are not used
	 * @param  array  $params  Settings:
	 *                         <ul>
	 *                         	<li> <strong> debug </strong> bool (false)
	 *                         		- Debug mode
	 *                         	<li> <strong> cache </strong> bool (false)
	 *                         		- Try to load cached data before querying the SQL server
	 *                         	<li> <strong> cache_time </strong> integer
	 *                         		- Cache expiration time (if cache is on)
	 *                         	<li> <strong> cache_key </strong> string
	 *                         		- Cache key name
	 *                         	<li> <strong> renew_cache </strong> boolean
	 *                         		- Flush cache and rebuild new. Default: false
	 *                         </ul>
	 *
	 * @return string|boolean Returned cell string or FALSE on error
	 */
	function getVal(string $sql, array $values = [], array $params = []){
		if(!$sql){
			return false;
		}
		
		// We reset the messages of the last operation
		$this->resultMessage = '';
	
		$use_cache = !empty($params['cache']) && !empty($params['cache_key']);
		
		if($use_cache && !empty($this->instanceMM)){
			$cached = empty($params['renew_cache']) ? $this->instanceMM->get($params['cache_key']) : false;
			if($cached !== false){
				
				$this->resultMessage .= 'DBManager: Cached result found under key "' . $params['cache_key'] . '". Skip query <br>' . $sql;
				
				return $cached;
			}
		}elseif($use_cache && empty($this->instanceMM)){
			$this->resultMessage .= 'DBManager: Cache mode is enabled, but there is no instance of class "MemcachedManager"<br>';
		}
		
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		$return  = false;
		$this->resultMessage .= 'DBManager: Query <br>' . $sql;
		
		
		try{
			if($values && is_array($values)){
				
				$this->resultMessage .= '<br> Bound values: <pre>' . print_r($values, true) . '</pre>';
				
				$result = $this->instancePDO->prepare($sql);
				$result->execute($values);
				
			}else{
				$result = $this->instancePDO->query($sql);
			}
			
			if($result->rowCount()){
				$row    = $result->fetch(\PDO::FETCH_NUM);
				$return = $row[0];
			}
			
			if($use_cache && !empty($params['cache_time']) && !empty($this->instanceMM)){
				
				// save downloaded from the database
				if($return !== false){
					
					$this->instanceMM->set($params['cache_key'], $return, $params['cache_time']);
					
					$this->resultMessage .= '<br> Saving to memcached key: ' . $params['cache_key'] . '<br> data: <pre>' . print_r($return, true) . '</pre>';
					
				}else{
					$this->resultMessage .= '<br> Skip saving to cache (FALSE result)';
				}
			}
			
		}catch(\PDOException $e){
			
			$this->resultMessage .= '<br> ' . '<br>' . $e->getMessage();
		}
		
		return $return;
	}
	
	/**
	 * MySQL transaction start
	 */
	function transactionStart(){
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		return $this->instancePDO->beginTransaction();
	}
	
	/**
	 * MySQL transaction rollback
	 */
	function rollBack(){
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		return $this->instancePDO->rollBack();
	}
	
	/**
	 * MySQL transaction commit
	 */
	function commit(){
		// lazy connection
		if(!$this->setInstancePDO()){
			return false;
		}
		
		return $this->instancePDO->commit();
	}
}
