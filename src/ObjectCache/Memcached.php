<?php
namespace Presslabs\CompatBedrock\ObjectCache;

use Presslabs\CompatBedrock\DNSDiscovery;


class Memcached implements ObjectCache {

	private static $instances = [];
	public $thirty_days;
	public $now;
	public $flush_number;

	// An array to save big MC requests.
	private $big_requests = array();

	/**
	 * Holds the Memcached object.
	 *
	 * @var Memcached
	 */
	public $m;

	/**
	 * Holds the non-Memcached objects.
	 *
	 * @var array
	 */
	public $cache = array();

	/**
	 * List of global groups.
	 *
	 * @var array
	 */
	public $global_groups = array( 'users', 'userlogins', 'usermeta', 'site-options', 'site-lookup', 'blog-lookup', 'blog-details', 'rss' );

	/**
	 * List of groups not saved to Memcached.
	 *
	 * @var array
	 */
	public $no_mc_groups = array();

	/**
	 * Prefix used for global groups.
	 *
	 * @var string
	 */
	public $global_prefix = '';


	/**
	 * Prefix used for non-global groups.
	 *
	 * @var string
	 */
	public $blog_prefix = '';

	/**
	 * The list of Memcached servers.
	 */
	public $servers = array();

	/**
	 * The Memcached objects to each server.
	 */
	public $mc_servers = array();

	/**
	 * Key salt is set by the WP_CACHE_KEY_SALT constant.
	 */
	private $key_salt = '';

	/**
	 * Add more stats and write them to file per request.
	 * This should not be enabled in production.
	 */
	public $debug_detailed_stats = false;

	/**
	 * Instantiate the Memcached class.
	 *
	 * Instantiates the Memcached class and returns adds the servers specified
	 * in the global array.
	 *
	 * @link    http://www.php.net/manual/en/memcached.construct.php
	 *
	 * @param   null    $persistent_id      To create an instance that persists between requests, use persistent_id to specify a unique ID for the instance.
	 */
	public function __construct($persistent_id = null) {
		global $memcached_servers, $blog_id, $table_prefix;

		/**
		 * Use the salt for easy cache invalidation and for
		 * multi single WP installs on the same server.
		 */
		if ( defined( 'WP_CACHE_KEY_SALT' ) )
			$this->key_salt = WP_CACHE_KEY_SALT;

        if ( isset( $_ENV['MEMCACHED_DISCOVERY_HOST'] ) ) {
            $this->servers = array_map(function ($server) {
                return array($server['host'], (int)$server['port'] ?: 11211);
            }, \DNSDiscovery::cachedDiscover(MEMCACHED_DISCOVERY_HOST));

            if (count($this->servers) == 0) {
                error_log("Cache backend is unavailable.");
                // TODO: raise exception, die or use only runtime cache.
            }
            sort($this->servers);

        } elseif ( defined('MEMCACHED_HOST') && MEMCACHED_HOST ) {
            $server = explode(':', constant('MEMCACHED_HOST'));
            if (count($server) == 1) {
                $server[] = '11211';
            }
            $this->servers = array( $server );
        } elseif ( isset( $_ENV['MEMCACHED_HOST'] ) ) {
            $server = explode(':', $_ENV['MEMCACHED_HOST']);
            if (count($server) == 1) {
                $server[] = '11211';
            }
            $this->servers = array( $server );
        } else {
            if ( isset( $memcached_servers ) ) {
                $this->servers = $memcached_servers;
            } else {
                $this->servers = array( array( '127.0.0.1', 11211 ) );
            }
        }

		$this->m = new \Memcached();
		$this->addServers( $this->servers );

		if ( count($this->servers) == 1 ) {
			$this->mc_servers = [ $this->m ];
		} else {
			// save memcached instance for each server
			// in order to access each of them and not leetting
			// the hashing deciding the server
			foreach ( $this->servers as $server ) {
				$m = new \Memcached();
				$m->addServer($server[0], $server[1]);

				$this->mc_servers[] = $m;
			}
		}

		// Assign global and blog prefixes for use with keys
		if (function_exists('is_multisite')) {
			if (is_multisite() || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE')) {
				$this->global_prefix = '';
			} else {
				$this->global_prefix = $table_prefix;
			}
			$this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix ) ;
		}

		// Setup cacheable values for handling expiration times
		$this->thirty_days = 60 * 60 * 24 * 30;
		$this->now         = time();

		// compress and use igbinary serialization by default
		$this->setOptions(array(
			\Memcached::OPT_COMPRESSION => true,
			\Memcached::OPT_DISTRIBUTION => \Memcached::DISTRIBUTION_CONSISTENT,
			\Memcached::OPT_TCP_NODELAY => true,
		));

		if (\Memcached::HAVE_IGBINARY) {
			$this->setOption(\Memcached::OPT_SERIALIZER, \Memcached::SERIALIZER_IGBINARY);
		}

		$this->init_stats();
	}

	protected function __clone() { }

	public function __wakeup()
	{
		throw new \Exception("Cannot unserialize Memcache service.");
	}

	public static function getInstance(): Memcached
	{
		$cls = static::class;
		if (!isset(self::$instances[$cls])) {
			self::$instances[$cls] = new static();
		}

		return self::$instances[$cls];
	}

	/**
	 * Set a Memcached options.
	 *
	 * @link    http://www.php.net/manual/en/memcached.setoptions.php
	 *
	 * @param   array      $options    Options array, with option name as key and value as the new value for the option.
	 * @return  bool                   Returns TRUE on success or FALSE on failure.
	 */
	public function setOptions($options)
	{
		return $this->m->setOptions($options);
	}

	/**
	 * Adds a value to cache.
	 *
	 * If the specified key already exists, the value is not stored and the function
	 * returns false.
	 *
	 * @link    http://www.php.net/manual/en/memcached.add.php
	 *
	 * @param   string      $key            The key under which to store the value.
	 * @param   mixed       $value          The value to store.
	 * @param   string      $group          The group value appended to the $key.
	 * @param   int         $expire     The expiration time, defaults to 0.
	 * @return  bool                        Returns TRUE on success or FALSE on failure.
	 */
	public function add( $key, $value, $group = '', $expire = 0 ) {
		/*
		 * Ensuring that wp_suspend_cache_addition is defined before calling, because sometimes an advanced-cache.php
		 * file will load object-cache.php before wp-includes/functions.php is loaded. In those cases, if wp_cache_add
		 * is called in advanced-cache.php before any more of WordPress is loaded, we get a fatal error because
		 * wp_suspend_cache_addition will not be defined until wp-includes/functions.php is loaded.
		 */
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}

		$this->increment_stat( 'add' );
		$derived_key = $this->buildKey( $key, $group );
		$expiration  = $this->sanitize_expiration( $expire );

		// Add does not set the value if the key exists; mimic that here
		if ( isset( $this->cache[$derived_key] ) ) {
			$this->increment_stat( 'add_skip' );
			return false;
		}

		// If group is a non-Memcached group, save to runtime cache, not Memcached
		if ( in_array( $group, $this->no_mc_groups ) ) {
			$this->add_to_internal_cache( $derived_key, $value );

			return true;
		}

		$this->stats_process( "add", [ $derived_key => $value ], $expiration );
		$this->increment_stat( 'add_mc' );

		// Save to Memcached
		$result = $this->m->add( $derived_key, $value, $expiration );

		// Store in runtime cache if add was successful
		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			$this->add_to_internal_cache( $derived_key, $value );
		elseif ( \Memcached::RES_NOTSTORED !== $this->getResultCode() )
			$this->logError( 'add', $derived_key );
		
		return $result;
	}

	/**
	 * Add a single server to the list of Memcached servers.
	 *
	 * @link http://www.php.net/manual/en/memcached.addserver.php
	 *
	 * @param   string      $host           The hostname of the memcache server.
	 * @param   int         $port           The port on which memcache is running.
	 * @param   int         $weight         The weight of the server relative to the total weight of all the servers in the pool.
	 * @return  bool                        Returns TRUE on success or FALSE on failure.
	 */
	public function addServer( $host, $port, $weight = 0 ) {
		$host = is_string( $host ) ? $host : '127.0.0.1';
		$port = is_numeric( $port ) && $port > 0 ? $port : 11211;
		$weight = is_numeric( $weight ) && $weight > 0 ? $weight : 1;

		return $this->m->addServer( $host, $port, $weight );
	}

	/**
	 * Adds an array of servers to the pool.
	 *
	 * Each individual server in the array must include a domain and port, with an optional
	 * weight value: $servers = array( array( '127.0.0.1', 11211, 0 ) );
	 *
	 * @link    http://www.php.net/manual/en/memcached.addservers.php
	 *
	 * @param   array       $servers        Array of server to register.
	 * @return  bool                        True on success; false on failure.
	 */
	public function addServers( $servers ) {
		if ( ! is_object( $this->m ) )
			return false;

		return $this->m->addServers( $servers );
	}

	/**
	 * Decrement a numeric item's value.
	 *
	 * @link http://www.php.net/manual/en/memcached.decrement.php
	 *
	 * @param string    $key    The key under which to store the value.
	 * @param int       $offset The amount by which to decrement the item's value.
	 * @param string    $group  The group value appended to the $key.
	 * @return int|bool         Returns item's new value on success or FALSE on failure.
	 */
	public function decr( $key, $offset = 1, $group = '' ) {
		$derived_key = $this->buildKey( $key, $group );
		$this->increment_stat( 'decr' );

		// Decrement values in no_mc_groups
		if ( in_array( $group, $this->no_mc_groups ) ) {

			// Only decrement if the key already exists and value is 0 or greater (mimics memcached behavior)
			if ( isset( $this->cache[$derived_key] ) && $this->cache[$derived_key] >= 0 ) {

				// If numeric, subtract; otherwise, consider it 0 and do nothing
				if ( is_numeric( $this->cache[$derived_key] ) )
					$this->cache[$derived_key] -= (int) $offset;
				else
					$this->cache[$derived_key] = 0;

				// Returned value cannot be less than 0
				if ( $this->cache[$derived_key] < 0 )
					$this->cache[$derived_key] = 0;

				return $this->cache[$derived_key];
			} else {
				return false;
			}
		}

		$result = $this->m->decrement( $derived_key, $offset );

		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			$this->add_to_internal_cache( $derived_key, $result );
		elseif ( \Memcached::RES_NOTFOUND !== $this->getResultCode() )
			$this->logError( 'decr', $derived_key );

		return $result;
	}

	/**
	 * Remove the item from the cache.
	 *
	 * Remove an item from memcached with identified by $key after $time seconds. The
	 * $time parameter allows an object to be queued for deletion without immediately
	 * deleting. Between the time that it is queued and the time it's deleted, add,
	 * replace, and get will fail, but set will succeed.
	 *
	 * @link http://www.php.net/manual/en/memcached.delete.php
	 *
	 * @param   string      $key        The key under which to store the value.
	 * @param   string      $group      The group value appended to the $key.
	 * @param   int         $time       The amount of time the server will wait to delete the item in seconds.
	 * @return  bool                    Returns TRUE on success or FALSE on failure.
	 */
	public function delete( $key, $group = '', $time = 0 ) {
		$derived_key = $this->buildKey( $key, $group );
		$this->increment_stat( 'delete' );

		// Remove from no_mc_groups array
		if ( in_array( $group, $this->no_mc_groups ) ) {
			if ( isset( $this->cache[$derived_key] ) )
				unset( $this->cache[$derived_key] );

			return true;
		}

		$result = $this->m->delete( $derived_key, $time );

		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			unset( $this->cache[$derived_key] );
		elseif ( \Memcached::RES_NOTFOUND !== $this->getResultCode() )
		{
			$this->logError( 'delete', $derived_key );
		}
		return $result;
	}


	/**
	 * Delete multiple values to cache at once.
	 *
	 * @link    http://www.php.net/manual/en/memcached.setmulti.php
	 *
	 * @param   array           $items          An array of key/value pairs to store on the server.
	 * @param   string          $group          Group(s) to merge with key(s) in $items.
	 * @param   int             $time           The amount of time the server will wait to delete the items.
	 * @return  bool                            Returns TRUE on success or FALSE on failure.
	 */
	public function delete_multiple( $keys, $group = '', $time = 0 ) {
		// Build final keys and replace $items keys with the new keys
		$derived_keys_map = $this->buildKeys( $keys, $group );
		$response = array();

		$this->increment_stat( 'delete_multiple' );

		foreach ( $derived_keys_map as $derived_key => $key ) {
			unset($this->cache[$derived_key]);
			$response[$key] = true;
		}

		// If group is a non-Memcached group, save to runtime cache, not Memcached
		if (in_array($group, $this->no_mc_groups)) {
			return $response;
		}

		// Save to memcached
		$derived_keys = array_keys( $derived_keys_map );
		$results = $this->m->deleteMulti( $derived_keys, $time );

		foreach ( $derived_keys_map as $derived_key => $key ) {
			if ( true !== $results[$derived_key] ) {
				$response[$key] = false;
				if ( \Memcached::RES_NOTFOUND !== $results[$derived_key] )
					error_log( "error while performing memcached *delete_multiple* for \"$key\" error code: " . $results[$derived_key] );
			}
		}

		return $response;
	}

	/**
	 * Invalidate all items in the cache.
	 *
	 * @link http://www.php.net/manual/en/memcached.flush.php
	 *
	 * @param   int     $delay      Number of seconds to wait before invalidating the items.
	 * @return  bool                Returns TRUE on success or FALSE on failure.
	 */
	public function flush( $delay = 0 ) {
		$this->increment_stat( 'flush' );

		$result = $this->m->flush( $delay );

		// Only reset the runtime cache if memcached was properly flushed
		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			$this->cache = array();
		else
			$this->logError( 'flush', '' );

		return $result;
	}
	/**
	 * Only flush the current request/process cache.
	 * This is useful for long running processes like WP CLI and Action Scheduler and highly customized sites
	 */

	public function flush_runtime() {
		$this->increment_stat( 'flush_runtime' );

		$this->cache = array();

		return true;
	}

	public function flush_group( $group = '' ) {
		$this->increment_stat( 'flush_group' );
		if ( empty( $group ) ) {
			$group = 'default';
		}

		$no = $this->new_flush_number();
		$this->set_flush_number( $no, $group );

		// remove cached flush number
		unset( $this->flush_number[ $group ] );

		return true;
	}

	public function supports( $feature ) {
		switch ( $feature ) {
			case 'add_multiple':
			case 'set_multiple':
			case 'get_multiple':
			case 'delete_multiple':
			case 'flush_runtime':
			case 'flush_group':
				return true;

			default:
				return false;
		}
	}

	/**
	 * Retrieve object from cache.
	 *
	 * Gets an object from cache based on $key and $group. In order to fully support the $cache_cb and $cas_token
	 * parameters, the runtime cache is ignored by this function if either of those values are set. If either of
	 * those values are set, the request is made directly to the memcached server for proper handling of the
	 * callback and/or token. Note that the $cas_token variable cannot be directly passed to the function. The
	 * variable need to be first defined with a non null value.
	 *
	 * If using the $cache_cb argument, the new value will always have an expiration of time of 0 (forever). This
	 * is a limitation of the Memcached PECL extension.
	 *
	 * @link http://www.php.net/manual/en/memcached.get.php
	 *
	 * @param   string          $key        The key under which to store the value.
	 * @param   string          $group      The group value appended to the $key.
	 * @param   bool            $force      Whether or not to force a cache invalidation.
	 * @param   null|bool       $found      Variable passed by reference to determine if the value was found or not.
	 * @return  bool|mixed                  Cached object value.
	 */
	public function get( $key, $group = '', $force = false, &$found = null, &$cas_token = null ) {
		$derived_key = $this->buildKey( $key, $group );
		$this->increment_stat( 'get' );

		// Assume object is not found
		$found = false;

		if ( isset( $this->cache[$derived_key] ) && ! $force ) {
			$found = true;
			$value = $this->cache[$derived_key];
		} elseif ( in_array( $group, $this->no_mc_groups ) ) {
			return false;
		} else {
			if ( func_num_args() > 4 ) {
				$result = $this->m->get( $derived_key, null, \Memcached::GET_EXTENDED );
				$cas_token = $result['cas'];
				$value = $result['value'];
			} else {
				$value = $this->m->get( $derived_key );
			}

			$this->increment_stat( 'get_mc' );

			if ( \Memcached::RES_SUCCESS === $this->getResultCode() ) {
				$this->add_to_internal_cache( $derived_key, $value );
				$found = true;
			} elseif ( \Memcached::RES_NOTFOUND !== $this->getResultCode() ) {
				$this->logError( 'get', $derived_key );
			}
		}

		if ($found) {
			$this->increment_stat( 'get_hit' );
		} else {
			$this->increment_stat( 'get_miss' );
		}

		$this->stats_process( 'get', [ $derived_key => $value ], 0 );

		return is_object( $value ) ? clone $value : $value;
	}

	/**
	 * Gets multiple values from memcached in one request.
	 *
	 * See the buildKeys method definition to understand the $keys/$groups parameters.
	 *
	 * @link http://www.php.net/manual/en/memcached.getmulti.php
	 *
	 * @param   array           $keys       Array of keys to retrieve.
	 * @param   string|array    $groups     If string, used for all keys. If arrays, corresponds with the $keys array.
	 * @return  bool|array                  Returns the array of found items or FALSE on failure.
	 */
	public function get_multiple( $keys, $group = '', $force = false ) {
		$derived_keys_map = $this->buildKeys($keys, $group);
		$derived_keys = array_keys( $derived_keys_map );

		$this->increment_stat( 'get_multiple', count($derived_keys) );

		$values = array();
		$need_to_get = array();

		$no_mc = in_array( $group, $this->no_mc_groups, true );

		// Pull out values from runtime cache, or mark for retrieval
		foreach ( $derived_keys as $key ) {
			if ( isset( $this->cache[$key] ) && ( ! $force || $no_mc ) ) {
				$values[$key] = $this->cache[$key];
			} else if ($no_mc) {
				$value[$key] = false;
			} else {
				array_push($need_to_get, $key);
			}
		}

		// Get those keys not found in the runtime cache
		if ( ! empty( $need_to_get ) ) {
			$result = $this->m->getMulti( $need_to_get );
			$this->increment_stat( 'get_multiple_mc', count($need_to_get) );
		}
		// Merge with values found in runtime cache
		if ( isset( $result ) && \Memcached::RES_SUCCESS === $this->getResultCode() ) {
			$values = array_merge( $values, $result );

			// Add the values to the runtime cache
			$this->cache = array_merge( $this->cache, $result );

		} elseif ( isset( $result ) && \Memcached::RES_NOTFOUND !== $this->getResultCode() ) {
			$this->logError( 'get_multiple', implode( ",", $derived_keys ) );
		}

		$hit = 0;
		$total = 0;
		// Return values for each key from input param
		$ordered_values = array();
		foreach ( $derived_keys_map as $derived_key => $key ) {
			++$total;
			if ( isset( $values[ $derived_key ] ) ) ++$hit;

			$ordered_values[$key] = $values[ $derived_key ] ?? false;
		}

		$this->increment_stat( 'get_multiple_hit', $hit );
		$this->increment_stat( 'get_multiple_miss', $total - $hit );

		$this->stats_process( 'get_multiple', $ordered_values, 0 );

		return $ordered_values;
	}

	/**
	 * Retrieve a Memcached option value.
	 *
	 * @link http://www.php.net/manual/en/memcached.getoption.php
	 *
	 * @param   int         $option     One of the Memcached::OPT_* constants.
	 * @return  mixed                   Returns the value of the requested option, or FALSE on error.
	 */
	public function getOption( $option ) {
		return $this->m->getOption( $option );
	}

	/**
	 * Return the result code of the last option.
	 *
	 * @link http://www.php.net/manual/en/memcached.getresultcode.php
	 *
	 * @return  int     Result code of the last Memcached operation.
	 */
	public function getResultCode() {
		return $this->m->getResultCode();
	}

	/**
	 * Return the message describing the result of the last operation.
	 *
	 * @link    http://www.php.net/manual/en/memcached.getresultmessage.php
	 *
	 * @return  string      Message describing the result of the last Memcached operation.
	 */
	public function getResultMessage() {
		return $this->m->getResultMessage();
	}

	/**
	 * Get server information by key.
	 *
	 * @link    http://www.php.net/manual/en/memcached.getserverbykey.php
	 *
	 * @param   string      $server_key     The key identifying the server to store the value on.
	 * @return  array                       Array with host, post, and weight on success, FALSE on failure.
	 */
	public function getServerByKey( $server_key ) {
		return $this->m->getServerByKey( $server_key );
	}

	/**
	 * Get the list of servers in the pool.
	 *
	 * @link    http://www.php.net/manual/en/memcached.getserverlist.php
	 *
	 * @return  array       The list of all servers in the server pool.
	 */
	public function getServerList() {
		return $this->m->getServerList();
	}

	/**
	 * Get server pool statistics.
	 *
	 * @link    http://www.php.net/manual/en/memcached.getstats.php
	 *
	 * @return  array       Array of server statistics, one entry per server.
	 */
	public function getStats() {
		return $this->m->getStats();
	}

	/**
	 * Get server pool memcached version information.
	 *
	 * @link    http://www.php.net/manual/en/memcached.getversion.php
	 *
	 * @return  array       Array of server versions, one entry per server.
	 */
	public function getVersion() {
		return $this->m->getVersion();
	}

	/**
	 * Increment a numeric item's value.
	 *
	 * @link http://www.php.net/manual/en/memcached.increment.php
	 *
	 * @param   string      $key        The key under which to store the value.
	 * @param   int         $offset     The amount by which to increment the item's value.
	 * @param   string      $group      The group value appended to the $key.
	 * @return  int|bool                Returns item's new value on success or FALSE on failure.
	 */
	public function incr( $key, $offset = 1, $group = '' ) {
		$derived_key = $this->buildKey( $key, $group );
		$this->increment_stat( 'incr' );

		// Increment values in no_mc_groups
		if ( in_array( $group, $this->no_mc_groups ) ) {

			// Only increment if the key already exists and the number is currently 0 or greater (mimics memcached behavior)
			if ( isset( $this->cache[$derived_key] ) &&  $this->cache[$derived_key] >= 0 ) {

				// If numeric, add; otherwise, consider it 0 and do nothing
				if ( is_numeric( $this->cache[$derived_key] ) )
					$this->cache[$derived_key] += (int) $offset;
				else
					$this->cache[$derived_key] = 0;

				// Returned value cannot be less than 0
				if ( $this->cache[$derived_key] < 0 )
					$this->cache[$derived_key] = 0;

				return $this->cache[$derived_key];
			} else {
				return false;
			}
		}

		$result = $this->m->increment( $derived_key, $offset );

		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			$this->add_to_internal_cache( $derived_key, $result );
		elseif ( \Memcached::RES_NOTFOUND !== $this->getResultCode() )
			$this->logError( 'incr', $derived_key );

		return $result;
	}

	/**
	 * Replaces a value in cache.
	 *
	 * This method is similar to "add"; however, is does not successfully set a value if
	 * the object's key is not already set in cache.
	 *
	 * @link    http://www.php.net/manual/en/memcached.replace.php
	 *
	 * @param   string      $key            The key under which to store the value.
	 * @param   mixed       $value          The value to store.
	 * @param   string      $group          The group value appended to the $key.
	 * @param   int         $expire         The expiration time, defaults to 0.
	 * @return  bool                        Returns TRUE on success or FALSE on failure.
	 */
	public function replace( $key, $value, $group = '', $expire = 0 ) {
		$derived_key = $this->buildKey( $key, $group );
		$expiration  = $this->sanitize_expiration( $expire );
		$this->increment_stat( 'replace' );

		// If group is a non-Memcached group, save to runtime cache, not Memcached
		if ( in_array( $group, $this->no_mc_groups ) ) {

			// Replace won't save unless the key already exists; mimic this behavior here
			if ( ! isset( $this->cache[$derived_key] ) )
				return false;

			$this->cache[$derived_key] = $value;
			return true;
		}

		// Save to Memcached
		$result = $this->m->replace( $derived_key, $value, $expiration );

		// Store in runtime cache if add was successful
		if ( \Memcached::RES_SUCCESS === $this->getResultCode() )
			$this->add_to_internal_cache( $derived_key, $value );
		elseif ( \Memcached::RES_NOTSTORED !== $this->getResultCode() )
			$this->logError( 'replace', $derived_key );

		return $result;
	}


	/**
	 * Sets a value in cache.
	 *
	 * The value is set whether or not this key already exists in memcached.
	 *
	 * @link http://www.php.net/manual/en/memcached.set.php
	 *
	 * @param   string      $key        The key under which to store the value.
	 * @param   mixed       $value      The value to store.
	 * @param   string      $group      The group value appended to the $key.
	 * @param   int         $expiration The expiration time, defaults to 0.
	 * @return  bool                    Returns TRUE on success or FALSE on failure.
	 */
	public function set( $key, $value, $group = '', $expire = 0 ) {
		$derived_key = $this->buildKey($key, $group);
		$expiration  = $this->sanitize_expiration($expire);
		$this->increment_stat( 'set' );

		// If group is a non-Memcached group, save to runtime cache, not Memcached
		if (in_array($group, $this->no_mc_groups)) {
			$this->add_to_internal_cache($derived_key, $value);
			return true;
		}

		$this->stats_process( "set", [$derived_key => $value], $expiration );

		// Save to Memcached
		$result = $this->m->set( $derived_key, $value, $expiration );

		// Store in runtime cache if add was successful
		$result_code = $this->getResultCode();
		if (\Memcached::RES_SUCCESS === $result_code) {
			$this->add_to_internal_cache($derived_key, $value);

		} elseif ( \Memcached::RES_NOTSTORED !== $result_code ) {
			$this->logError( 'set', $derived_key );
		}

		return $result;
	}

	/**
	 * Set multiple values to cache at once.
	 *
	 * By sending an array of $items to this function, all values are saved at once to
	 * memcached, reducing the need for multiple requests to memcached. The $items array
	 * keys and values are what are stored to memcached. The keys in the $items array
	 * are merged with the $groups array/string value via buildKeys to determine the
	 * final key for the object.
	 *
	 * @link    http://www.php.net/manual/en/memcached.setmulti.php
	 *
	 * @param   array           $items          An array of key/value pairs to store on the server.
	 * @param   string          $group          Group(s) to merge with key(s) in $items.
	 * @param   int             $expiration     The expiration time, defaults to 0.
	 * @return  bool                            Returns TRUE on success or FALSE on failure.
	 */
	public function set_multiple( $items, $group = '', $expire = 0 ) {
		// Build final keys and replace $items keys with the new keys
		$derived_keys_map  = $this->buildKeys( array_keys( $items ), $group );
		$derived_keys  = array_keys( $derived_keys_map );
		$expiration    = $this->sanitize_expiration( $expire );
		$derived_items = array_combine( $derived_keys, $items );

		$this->increment_stat( 'set_multiple' );

		// If group is a non-Memcached group, save to runtime cache, not Memcached
		if (in_array($group, $this->no_mc_groups)) {
			foreach ( $derived_items as $derived_key => $value ) {
				$this->add_to_internal_cache($derived_key, $value);
			}
			return true;
		}

		$this->stats_process( "set_multiple", $derived_items, $expiration );

		// Save to memcached
		$result = $this->m->setMulti( $derived_items, $expiration );

		// Store in runtime cache if add was successful
		if ( \Memcached::RES_SUCCESS === $this->getResultCode() ) {
			$this->cache = array_merge( $this->cache, $derived_items );
		} else {
			$this->logError( 'set_multiple', implode( ",", $derived_keys ) );
		}

		$ordered_keys = array();
		foreach ( $derived_keys_map as $derived_key => $key ) {
			$ordered_keys[$key] = $result;
		}

		return $ordered_keys;
	}

	/**
	 * Add multiple values to cache at once.
	 *
	 * By sending an array of $items to this function, all values are saved at once to
	 * memcached, reducing the need for multiple requests to memcached. The $items array
	 * keys and values are what are stored to memcached. The keys in the $items array
	 * are merged with the $groups array/string value via buildKeys to determine the
	 * final key for the object.
	 *
	 * @link    http://www.php.net/manual/en/memcached.setmulti.php
	 *
	 * @param   array           $items          An array of key/value pairs to store on the server.
	 * @param   string          $group          Group(s) to merge with key(s) in $items.
	 * @param   int             $expire         The expiration time, defaults to 0.
	 * @return  bool                            Returns TRUE on success or FALSE on failure.
	 */
	public function add_multiple( $items, $group = '', $expire = 0 ) {

		$this->increment_stat( 'add_multiple' );

		$values = array();
		foreach ( $items as $key => $value ) {
			$values[ $key ] = $this->add( $key, $value, $group, $expire );
		}

		return $values;
	}

	/**
	 * Set a Memcached option.
	 *
	 * @link    http://www.php.net/manual/en/memcached.setoption.php
	 *
	 * @param   int         $option     Option name.
	 * @param   mixed       $value      Option value.
	 * @return  bool                Returns TRUE on success or FALSE on failure.
	 */
	public function setOption( $option, $value ) {
		return $this->m->setOption( $option, $value );
	}


	/**
	 * Simple wrapper for saving object to the internal cache.
	 *
	 * @param   string      $derived_key    Key to save value under.
	 * @param   mixed       $value          Object value.
	 */
	public function add_to_internal_cache( $derived_key, $value ) {
		if ( is_object( $value ) ) {
			$value = clone $value;
		}

		$this->cache[$derived_key] = $value;
	}


	/**
	 * Add global groups.
	 *
	 * @author  Ryan Boren   This function comes straight from the original WP Memcached Object cache
	 * @link    http://wordpress.org/extend/plugins/memcached/
	 *
	 * @param   array       $groups     Array of groups.
	 * @return  void
	 */
	public function add_global_groups( $groups ) {
		if ( ! is_array( $groups ) )
			$groups = [ $groups ];

		$this->global_groups = array_merge( $this->global_groups, $groups);
		$this->global_groups = array_unique( $this->global_groups );
	}

	/**
	 * Add non-persistent groups.
	 *
	 * @author  Ryan Boren   This function comes straight from the original WP Memcached Object cache
	 * @link    http://wordpress.org/extend/plugins/memcached/
	 *
	 * @param   array       $groups     Array of groups.
	 * @return  void
	 */
	public function add_non_persistent_groups( $groups ) {
		if ( ! is_array( $groups ) )
			$groups = [ $groups ];

		$this->no_mc_groups = array_merge( $this->no_mc_groups, $groups );
		$this->no_mc_groups = array_unique( $this->no_mc_groups );
	}

	/**
	 * Switch blog prefix, which changes the cache that is accessed.
	 *
	 * @param  int     $blog_id    Blog to switch to.
	 * @return void
	 */
	public function switch_to_blog( $blog_id ) {
		global $table_prefix;
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix );
	}

	/**
	 * Clears the cache in current session
	 */
	public function close()
	{
		$this->cache = array();
		return true;
	}



	/**
	 * Ensure that a proper expiration time is set.
	 *
	 * Memcached treats any value over 30 days as a timestamp. If a developer sets the expiration for greater than 30
	 * days or less than the current timestamp, the timestamp is in the past and the value isn't cached. This function
	 * detects values in that range and corrects them.
	 *
	 * @param  string|int    $expiration    The dirty expiration time.
	 * @return string|int                   The sanitized expiration time.
	 */
	public function sanitize_expiration( $expiration ) {
		if ( $expiration > $this->thirty_days && $expiration <= $this->now ) {
			$expiration = $expiration + $this->now;
		}

		return $expiration;
	}

	private function logError( $operation, $derived_key ) {
		trigger_error( "error while performing memcached $operation for \"$derived_key\": " . $this->getResultMessage(), E_USER_WARNING );
	}

	/**
	 * Builds a key for the cached object using the blog_id, key, and group values.
	 *
	 * @author  Ryan Boren   This function is inspired by the original WP Memcached Object cache.
	 * @link    http://wordpress.org/extend/plugins/memcached/
	 *
	 * @param   string      $key        The key under which to store the value.
	 * @param   string      $group      The group value appended to the $key.
	 * @return  string
	 */
	public function buildKey( $key, $group = '' ) {
		if ( empty( $group ) )
			$group = 'default';

		$key = urlencode( $key );
		$group = urlencode( $group );
		$prefix = $this->buildKeyPrefix( $group );

		// allow keys grater than MAX_KEY_LENGTH (250) to pass.
		// the key prefix usually is less than 30 chars.
		if ( strlen( $key ) > 220 ) {
			$key = hash( 'md5', $key );
		}

		return $prefix . $key;
	}

	/**
	 * Creates an array of keys from passed key(s) and group(s).
	 *
	 * @param   string|array    $keys       Key(s) to merge with group(s).
	 * @param   string          $group      Group to merge with key(s).
	 * @return  array                       Array that combines keys and groups into a single set of memcached keys.
	 */
	public function buildKeys( array $keys, $group = '' ) {
		$derived_keys = array();

		foreach ( $keys as $key ) {
			$derived_keys[ $this->buildKey( $key, $group ) ] = $key;
		}

		return $derived_keys;
	}

	private function buildKeyPrefix( $group ) {
		$prefix = $this->key_salt;

		if ( false !== array_search( $group, $this->global_groups ) )
			$prefix .= $this->global_prefix;
		else
			$prefix .= $this->blog_prefix;

		$prefix .= $this->get_group_flush_number( $group );
		$prefix .= ":$group:";

		return $prefix;
	}

	/**
	 * Prefix of keys used to keep cache version of flush groups.
	 */
	private $flush_key_prefix = "flush_number";

	private function get_flush_number_key( $group ) {
		$flush_no_key = $this->flush_key_prefix . ':' . $group;
		if ( false === array_search( $group, $this->global_groups ) ) {
			// no global group add blog prefix
			$flush_no_key = $this->blog_prefix . $flush_no_key;
		}

		return $this->key_salt . ':' . $flush_no_key;
	}

	public function get_group_flush_number( $group ) {

		if ( ! isset( $this->flush_number[ $group ] ) ) {
			$this->flush_number[ $group ] = $this->get_flush_number( $group );
		}

		return $this->flush_number[ $group ];
	}

	// Gets number from all default servers, replicating if needed
	function get_max_flush_number( $group ) {
		$key = $this->get_flush_number_key( $group );

		$values = array();
		foreach ( $this->mc_servers as $i => $mc ) {
			$values[ $i ] = $mc->get( $key );
		}

		$max = max( $values );

		if ( ! $max > 0 ) {
			return false;
		}

		// Replicate to servers not having the max.
		foreach ( $this->mc_servers as $i => $mc ) {
			if ( $values[ $i ] < $max ) {
				$mc->set( $key, $max, 0 );
			}
		}

		return $max;
	}

	function set_flush_number( $value, $group ) {
		$key = $this->get_flush_number_key( $group );
		foreach ( $this->mc_servers as $mc ) {
			$mc->set( $key, $value, 0 );
		}
	}

	function new_flush_number() {
		return intval( microtime( true ) * 1e6 );
	}

	function get_flush_number( $group ) {
		$flush_number = $this->get_max_flush_number( $group );

		// No flush number set one
		if ( empty( $flush_number ) ) {
			$flush_number = $this->new_flush_number();
			$this->set_flush_number( $flush_number, $group );
		}

		return $flush_number;
	}

	/**
	 * Stats
	 */

	public $stats = array();

	function increment_stat( $field, $num = 1 ) {
		if ( ! isset( $this->stats[ $field ] ) ) {
			$this->stats[ $field ] = $num;
		} else {
			$this->stats[ $field ] += $num;
		}
	}

	private function stats_process($cmd, $items, $expiration) {

		if ( ! $this->debug_detailed_stats ) {
			return;
		}

		$total_size = 0;
		$top_size_cache = array();

		foreach ($items as $key => $value) {
			$value_size = strlen(serialize($value));
			$key_size = strlen($key);
			$item_size = ($value_size + $key_size);
			$total_size += $item_size;

			$top_size_cache[$key] = $item_size;
		}

		arsort($top_size_cache);

		if ( $total_size > 512 * 1024 ) {
			$tb = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			array_push($this->big_requests, array(
				"cmd" => $cmd,
				"op_size" => $total_size,
				"count_keys" => count( $items ),
				"top_keys" => array_slice($top_size_cache, 0 , 3),
				"tb" => $tb,
			));
		}
	}

	public function stats_write_to_file() {
		if (count($this->big_requests) == 0) {
			return;
		}

		$logs = array(
			"time" => date("c"),
			"big_requests" => $this->big_requests,
			"stats" => $this->stats_array(),
			"request_uri" => $_SERVER['REQUEST_URI'],
			"method" => $_SERVER['REQUEST_METHOD'],
		);

		file_put_contents("/tmp/object_cache_stats.log", json_encode( $logs ),  FILE_APPEND|LOCK_EX);
	}

	private function init_stats() {
		$this->stats["get_hit"] = 0;
		$this->stats["get_miss"] = 0;

		$this->big_requests = array();

		if ( defined("PL_OBJECT_CACHE_DEBUG") && PL_OBJECT_CACHE_DEBUG ) {
			$this->debug_detailed_stats = true;
			add_action("shutdown", [$this, "stats_write_to_file"] );
		}
	}

	private function stats_top_size_keys() {
		$top_size_cache = array();
		foreach ($this->cache as $key => $value) {
			$size = strlen(serialize($value)) / KB_IN_BYTES;
			$top_size_cache[$key] = $size;
		}

		// sort keys in order to select top
		arsort($top_size_cache);

		return array_slice($top_size_cache, 0, 10);
	}

	public function stats_array() {
		$stats = array();

		foreach ($this->stats as $op => $value) {
			$stats["count_$op"] = $value;
		}

		$top_size_cache = $this->stats_top_size_keys();
		foreach ($top_size_cache as $key => $size) {
			$stats["size_$key"] = $size;
		}

		return $stats;
	}

	public function stats_html() {
		$output = '<div style="margin: 20px">';
		$output .= '<ul>';
		foreach ($this->stats as $op => $value) {
			$output .= "<li> $op = $value </li>";
		}
		$output .= '</ul>';

		$top_size_cache = $this->stats_top_size_keys();

		$output .= '<ul>';
		foreach ($top_size_cache as $key => $size) {
			$size = number_format($size, 2);
			$output .= "<li><strong>$key</strong> - " . $size . 'k )</li>';
		}

		$output .= '</ul>';

		$output .= '</div>';

		return $output;
	}

	public function stats() {
		echo $this->stats_html();
	}
}
