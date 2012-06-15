<?php 

/*
 * Standard database class that includes memcaching
 * 
 */

class db
{
	
	// Enter the host/ user and pass word for db connectivity here
	const HOST = '';
	const USER = '';
	const PASS = '';
	
	// Enter the live and dev database names here
	const LIVE_DB 	= ''; 
	const DEV_DB 	= ''; 
	
	// So that we don't confuse cached queries from different sites
	const memcachePrefix = '';
	
	// Set the public database so that we can check, read and manipulate later
	public $database;
	
	// Bool to show debug info 
	public $debug;
	
	// DB Connection
	public $mysqli;
	
	/*
	 * Create the connection to the DB
	 * 
	 * $debug :: Whether to dispaly debug data.
	 * $live :: Connect to the LIVE or DEV database.
	 * 
	 */
	public function __construct($debug = false, $live = false)
	{
		
		// Set the public debug var
		$this->debug 		= $debug;
		
		// new memCached Object
		// memCached must be installed and on port 11211 (or change the port number here)
		$this->memcache = new Memcache;
		$this->memcache->connect('localhost', 11211);
		
		// Connect to the correct DB
		if ($live) {
			$this->database = LIVE_DB;
		} else {
			$this->database = DEV_DB;
		}
		
		// Make the mysqli connection
		$this->mysqli = mysqli_connect(self::HOST, self::USER, self::PASS, $this->database) OR DIE ('DB Error');
		$this->mysqli->set_charset("utf8");
		
	}
	
	/*
	 * For managing flood control 
	 * 
	 * CREATE TABLE `floodcontrol` (
		  `ip` varchar(16) NOT NULL,
		  `time` int(10) NOT NULL,
		  PRIMARY KEY (`ip`),
		  KEY `time` (`time`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1;
	 * 
	 */
	public function floodControl($delay = 120)
	{
		
		// Get the users IP address
		$ip = $this->getRealIpAddr();
		
		// Is there a flood entry that meets the requirement for control?
		$select = "SELECT `ip` 
						FROM `floodcontrol`
						WHERE `ip` = '".$ip."' 
						AND (`time` + ".$delay.") > ".time();
		
		$res 	= self::ex($select);
		
		// If yes return false - user will be controlled.
		if ($res) {
			
			return true;
			
		} else {
		
			$flood = "REPLACE INTO `floodcontrol` (  `ip` ,  `time` ) 
							VALUES ( '".$ip."', ".time()." );";
			self::ex($flood);
			
		}
		
		return false;
		
	}
	
	/*
	 * Should a parse of the flood control table be needed
	 */
	public function parseFlood()
	{
		
		$oneHour = 60 * 60;
		
		$delete = "DELETE FROM `floodcontrol` WHERE (`time` + " . $oneHour . ") < " . time();
		self::ex($delete);
		
		return true;
		
	}
	
	/*
	 * For timing queries
	 */
	private function microtime_float() 
	{
		
    	list($usec, $sec) = explode(" ", microtime());
    	return ((float)$usec + (float)$sec);
    	
	}
	
	/*
	 * Change the connected DB 
	 */
	public function changeDB($datab) 
	{
		
		$this->database = $datab;
		
		if (!$this->mysqli->select_db($this->database)) {
			die('Db Select Error');
		}
		
	}
	
	/*
	 * show Debug
	 * 
	 * Simple function to echo debug data
	 * 
	 * $text :: any text
	 * $colour :: colour of the text
	 * 
	 */
	public function showDebug($text, $colour = 'green') 
	{
		
		if (!$this->debug) {
			return false;
		}
		
		if ($this->debug) {
			echo '<br /><span style="color: ' .$colour. '; font-family: helvetica, impact, sans-serif;">' . $text . '</span>';
		}
		
	}
	
	/*
	 * Execute a query
	 * 
	 * 
	 * $query :: The query to execute
	 * $loation :: The location for easy debugging 
	 * $readCache :: Check for a cached result?
	 * $writeCache :: Write the result into the cache? [[ MUST BE IN SECONDS!! ]]
	 */
	public function ex($query, $location = false, $readCache = true, $writeCache = 1800) 
	{
		
		// Grab the required function
		$statement 	= explode(' ', trim($query));
		$command 	= strtolower($statement[0]);
		
		self::showDebug('**** NEW QUERY RECEIVED BY DBI CLASS ****', 'orange');
		self::showDebug('QUERY :: '  . $query, 'blue');
		if (!$location) { $location = 'UNKNOWN'; }
		self::showDebug('LOCATION :: ' . $location, 'blue');
		
		switch($command) {
			
			case 'insert':
   			case 'replace':
			case 'update':
			case 'delete':
			case 'truncate':
				return $this -> putStuff($query);
			break;
			
			case 'select':
				
				// Do we want to read the cache?
				if ($readCache) {
					
					self::showDebug('CHECK CACHE :: YES', 'green');
					
					// Create a Cache ID Hash
					$cacheMD5 = self::memcachePrefix . md5($query);
					self::showDebug('CACHE MD5 :: ' . $cacheMD5, 'blue');
					
					$cached_result 			= $this->memcache->get($cacheMD5);
					
					if ($cached_result) {
						
						self::showDebug('CACHED RESULT FOUND :: YES', 'green');
						self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
		
						return $cached_result;
						
					} else {
						
						self::showDebug('CACHED RESULT FOUND :: NO', 'red');
						
					}
					
				} else {
					
					self::showDebug('CHECK CACHE :: NO', 'red');
					
				}
				
				$res =  $this -> grabStuff($query, $writeCache);
				
				return $res;
				
			break;	
			case 'explain':
			case 'show':
			case 'optimize':
				
				$res =  $this -> grabStuff($query, false);
				
				if ($this->debug) {
					
					self::showDebug('Here is the resulting data array:', 'blue');
					
					if($this->debug) {
						echo '<br />';
					}
					
					print_r($res);
					
				}
				
				return $res;
			break;	
			
			case 'alter':
				return $this -> alterStuff($query);
			break;
			
			default:
			 	die ('In Location &quot;'.$location .'&quot; <br /> You have used a SQL function that is not supported: &quot;' . $command . '&quot;');
			break;
		}
		
	}
	
	/*
	 * Select, explain, show and optimize queries
	 *
	 * $query :: The query to execute
	 *
	 */
	private function grabStuff($query, $writeCache)
	{

		// Execute the query.
		$time_start	= self::microtime_float();
    	
		$result 	= $this->mysqli->query($query);
						
		$time_end 	= self::microtime_float();
		$time 		= round($time_end - $time_start, 6);
		
		if ($this->debug) {
			
			self::showDebug('QUERY EXECUTION TIME :: ' . $time, 'blue');
			
			if ($this->mysqli->error) {
				
				self::showDebug('QUERY ERROR :: YES :: ' .  $this->mysqli->error, 'red');
				
			}
		
		}
		
		if ($result->num_rows > 0) {
			
			if ($this->debug) {
				self::showDebug('ROWS RETURNED :: ' . $result->num_rows, 'blue');
			}
			
			// Create and array of the results
			$data = array();
			while($row 	= $result->fetch_array(MYSQLI_ASSOC)) {
    			$data[] = $row;
				unset($row);
			}		
			
			// Free up result
			$result->free_result();
			
			if ($writeCache) {
				
				if ($writeCache == 'm') {
					$seconds_cache 	= (24 - (int)date('G', time())) * 3600;
				} else {
					$seconds_cache 	= $writeCache;
				}
				
				if ($this->debug) {
					self::showDebug('CACHE THE NEW RESULT :: YES', 'green');
					self::showDebug('CACHE LENGTH :: ' . $seconds_cache . ' SECONDS :: ' . floor($seconds_cache/60) . ' MINUTES :: ' . floor($seconds_cache/3600) . ' HOURS', 'blue');
				}
				
				if ($this->memcache->set(self::memcachePrefix . md5($query), $data, MEMCACHE_COMPRESSED, $seconds_cache)) {
					
					if ($this->debug) {
						self::showDebug('CACHE SET :: YES', 'green');
					}
					
				} else {
					
					if ($this->debug) {
						self::showDebug('CACHE SET :: FAIL', 'red');
					}
					
				}
				
			} else {
				
				if ($this->debug) {
					self::showDebug('CACHE THE NEW RESULT :: NO', 'red');
				}
				
			}
			
			self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
			return $data;
			
		} else {
			
			if ($this->debug) {
				self::showDebug('ROWS RETURNED :: ZERO', 'blue');
				self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
			}
			
			return false;
			
		}
	
	}
	
	
	/*
	 * Alter query
	 * 
	 * $query :: The query to execute
	 *
	 */
	private function alterStuff($query)
	{
		
		$result = $this->mysqli->query($query);
		
		if (!$result && $this->debug) {
			self::showDebug($this->mysqli->error);
		}
		
		return $result;
		
	}
	
	/*
	 * insert
	 * 
	 * $query :: The query to execute
	 * 
	 */
	private function putStuff($query)
	{
		$result = $this->mysqli->query($query);
		
		if ($this->mysqli->error && $this->debug) {
			self::showDebug('QUERY ERROR :: YES :: ' .  $this->mysqli->error, 'red');
		}
		
		if ($result) {
			
			$statement = explode(' ', trim($query));

			if (strtolower($statement[0]) == 'insert') {
				
				// Returns zero if there was no previous query on the connection or if the query did not update an AUTO_INCREMENT value.
				if ($this->mysqli->insert_id == 0) {
					
					if ($this->debug) {
						self::showDebug('INSERT ID :: NONE', 'blue');
						self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
					}
					return true;
					
				} else {
					
					if ($this->debug) {
						self::showDebug('INSERT ID :: ' .  $this->mysqli->insert_id, 'blue');
						self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
					}	
					return $this->mysqli->insert_id;
				}
			
			} else {
				
				if ($this->debug) {
					self::showDebug('ROWS RETURNED :: ZERO', 'blue');
					self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
				}
				return true;
				
			}
			
		} else {
			
			if ($this->debug) {
				self::showDebug('RESULT :: NO', 'red');
				self::showDebug('**** END DBI ACTIVITY ****<br /><br />', 'orange');
			}
			return false;
		}
	
	}
	
	/*
	 * 
	 */
	public function getRealIpAddr()
	{
	    // Check ip from share internet 
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		
		// To check ip is pass from proxy 	
	    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {

	    	$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
	    
	    } else {

	    	$ip = $_SERVER['REMOTE_ADDR'];
	    
	    }
	    
	    return $ip;
	}
	
}
