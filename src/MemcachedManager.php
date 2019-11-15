<?php
/**
 * Wrapper class to work with Memcached
 *
 * @author Admin <support@1000.menu>
 * @author Anatoli <freeworknet@yandex.ru>
 */

namespace XrTools\DBM;

class MemcachedManager{
	
	/** @var \Memcached $instanceMemcached */
	private $instanceMemcached = null;
	
	/**
	 * @var array $serverSettings [[string $host, int $port, [int $weight = 0]],...] memcached params for add servers
	 * <p> more detailed: @see Memcached::addServers() </p>
	 */
	private $serverSettings = [];
	
	/** @var string $lastError */
	private $lastError = '';
	
	
	/**
	 * @param array $serverSettings [string $host, int $port, [int $weight = 0]]
	 *
	 * @uses \XrTools\DBM\MemcachedManager::$serverSettings
	 */
	public function __construct(array $serverSettings){
		$this->serverSettings = $serverSettings;
	}
	
	/**
	 * Saving data to cache
	 *
	 * @param  string|array $key   Cache key or keys-values list
	 * @param  mixed        $value Cache value
	 * @param  integer      $exp   Cache expiration time in seconds
	 * @param  boolean      $json  Convert value to json | on / (off)
	 *
	 * @return bool
	 */
	public function set($key, $value, int $exp = 0, bool $json = false){
		
		// check params
		if(empty($key) || (!is_array($key) && empty($value))){
			$this->lastError = 'MemcachedManager:: Incorrect parameters passed to the "set" method!';
			
			return false;
		}
		
		if(!$this->setInstanceMemcached()){
			return false;
		}
		
		//multi set
		if(is_array($key)){
			
			if($json){
				$data = [];
				foreach($key as $k => $v){
					$data[$k] = json_encode($v);
				}
				
			}else{
				$data = $key;
			}
			
			$this->instanceMemcached->setMulti($data, $exp);
			
		}else{
			
			if($json){
				$value = json_encode($value);
			}
			
			$this->instanceMemcached->set($key, $value, $exp);
		}
		
		$lastCode = $this->instanceMemcached->getResultCode();
		
		if($lastCode !== 0){
			$this->lastError = "MemcachedManager:: Write error: {$this->instanceMemcached->getResultMessage()} ({$lastCode})";
		}
		
		return $lastCode === 0;
	}
	
	/**
	 * Loading data from cache
	 *
	 * @param  string|array $key    Cache key or keys list
	 * @param  boolean      $unjson Decode JSON to array
	 *
	 * @return bool|mixed    FALSE - if error
	 */
	public function get($key, bool $unjson = false){
		
		// check params
		if(empty($key)){
			$this->lastError = 'MemcachedManager:: Incorrect parameters passed to the "get" method!';
			
			return false;
		}
		
		if(!$this->setInstanceMemcached()){
			return false;
		}
		
		$return = false;
		
		// multi
		if(is_array($key)){
			$data = $this->instanceMemcached->getMulti($key);
			
			$result_code = $this->instanceMemcached->getResultCode();
			
			if($result_code === 0){
				
				if($unjson){
					foreach($data as $k => $v){
						$return[$k] = json_decode($v, true);
					}
					
				}else{
					$return = $data;
				}
			}
			
		}// single
		else{
			$data = $this->instanceMemcached->get($key);
			
			$result_code = $this->instanceMemcached->getResultCode();
			
			if($result_code === 0){
				
				if($unjson){
					$return = json_decode($data, true);
					
				}else{
					$return = $data;
				}
			}
		}
		
		if($result_code !== 0){
			$this->lastError = "MemcachedManager:: Error reading key \"{$key}\": {$this->instanceMemcached->getResultMessage()} ({$result_code})";
		}
		
		return $return;
	}
	
	/**
	 * Remove data from cache
	 *
	 * @param  string|array $key Cache key or keys list
	 *
	 * @return bool|array
	 */
	public function del($key){
		
		// check params
		if(empty($key)){
			$this->lastError = 'MemcachedManager:: Incorrect parameters passed to the "del" method!';
			
			return false;
		}
		
		if(!$this->setInstanceMemcached()){
			return false;
		}
		
		// multi
		if(is_array($key)){
			$return = $this->instanceMemcached->deleteMulti($key);
			
		}// single
		else{
			$return = $this->instanceMemcached->delete($key);
		}
		
		$this->lastError = !$return
			? "MemcachedManager:: Delete error: {$this->instanceMemcached->getResultMessage()} ({$this->instanceMemcached->getResultCode()})"
			: '';
		
		return $return;
	}
	
	/**
	 * Remove all data from cache
	 *
	 * @return bool
	 */
	public function delAll(){
		if(!$this->setInstanceMemcached()){
			return false;
		}
		
		if(!$this->instanceMemcached->deleteMulti($this->instanceMemcached->getAllKeys())){
			$this->lastError = "MemcachedManager:: Failed remove all cache key: {$this->instanceMemcached->getResultMessage()} ({$this->instanceMemcached->getResultCode()})";
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get keys version
	 *
	 * @param  string $key Cache key
	 * @param  int    $exp Cache expiration time in seconds
	 *
	 * @return bool|string
	 */
	public function getVersion(string $key, int $exp = 3600){
		// check params
		if(empty($key)){
			$this->lastError = 'MemcachedManager:: Incorrect parameters passed to the "getVersion" method!';
			
			return false;
		}
		
		if(!$this->setInstanceMemcached()){
			return false;
		}
		
		$time = $this->get($key);
		
		if($time !== false){
			return $time;
		}
		
		$time = time() . mt_rand(1000, 9999);
		
		if($this->set($key, $time, $exp)){
			return $time;
		}
		
		return false;
	}
	
	/**
	 * Return last error
	 *
	 * @return string
	 */
	public function getLastError(){
		return $this->lastError;
	}
	
	/**
	 * Return last code
	 *
	 * @return null
	 */
	public function getLastCode(){
		return $this->instanceMemcached->getResultCode();
	}
	
	/**
	 * Creating new Memcached instance and adding server.
	 *
	 * @uses \XrTools\DBM\MemcachedManager::$instanceMemcached
	 *
	 * @return bool
	 */
	protected function setInstanceMemcached(){
		
		if(!empty($this->instanceMemcached)){
			return true;
			
		}// check params
		elseif(empty($this->serverSettings)){
			$this->lastError = 'MemcachedManager:: The server parameters are not set correctly!';
			
			return false;
		}
		
		$this->instanceMemcached = new \Memcached();
		
		if(!$this->instanceMemcached->addServers($this->serverSettings)){
			$this->lastError         = 'MemcachedManager:: Memcached connection failed with params:<br>' . print_arr($this->serverSettings);
			$this->instanceMemcached = null;
			
			return false;
		}
		
		return true;
	}
}
