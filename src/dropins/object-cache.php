<?php
/*
  Plugin Name: Presslabs Object Cache
  Plugin URI: https://presslabs.com
  Description: WordPress Object Cache plugin for integrating MemcacheD on Presslabs. The backend is based on https://github.com/tollmanz/wordpress-pecl-memcached-object-cache .
  Version: 2.0
  Author: Presslabs
  Author URI: https://presslabs.com
*/


/**
 * Sets up Object Cache Global and assigns it.
 *
 * @global  WP_Object_Cache     $wp_object_cache    WordPress Object Cache
 * @return  void
 */
function wp_cache_init() {
	global $wp_object_cache;

	// we default to Memcached - but we can use other providers as well
	$backend = defined('PL_OBJECT_CACHE_PROVIDER') ? PL_OBJECT_CACHE_PROVIDER : 'Memcached';

	// create  a new class on every init.
	$backend = '\\Presslabs\\CompatBedrock\\ObjectCache\\' . $backend;
	$wp_object_cache = new $backend();

	// redefine WP_Object_Cache class for tests
	if ( !class_exists( 'WP_Object_Cache' ) )
		class_alias($backend, 'WP_Object_Cache');
}


/**
 * Adds a value to cache.
 *
 * If the specified key already exists, the value is not stored and the function
 * returns false.
 *
 * @link http://www.php.net/manual/en/memcached.add.php
 *
 * @param string    $key        The key under which to store the value.
 * @param mixed     $value      The value to store.
 * @param string    $group      The group value appended to the $key.
 * @param int       $expire The expiration time, defaults to 0.
 * @return bool                 Returns TRUE on success or FALSE on failure.
 */
function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $value, $group, $expire );
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
 * @param array         $items      An array of key/value pairs to store on the server.
 * @param string        $group      Group to merge with key(s) in $items.
 * @param int           $expire     The expiration time, defaults to 0.
 * @return bool                     Returns TRUE on success or FALSE on failure.
 */
function wp_cache_add_multiple( array $items, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $items, $group, $expire );
}


/**
 * Decrement a numeric item's value.
 *
 * Same as wp_cache_decrement. Original WordPress caching backends use wp_cache_decr. I
 * want both spellings to work.
 *
 * @link http://www.php.net/manual/en/memcached.decrement.php
 *
 * @param string    $key    The key under which to store the value.
 * @param int       $offset The amount by which to decrement the item's value.
 * @param string    $group  The group value appended to the $key.
 * @return int|bool         Returns item's new value on success or FALSE on failure.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
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
 * @param string    $key    The key under which to store the value.
 * @param string    $group  The group value appended to the $key.
 * @param int       $time   The amount of time the server will wait to delete the item in seconds.
 * @return bool             Returns TRUE on success or FALSE on failure.
 */
function wp_cache_delete( $key, $group = '', $time = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group, $time );
}


/**
 * Remove the items from the cache.
 *
 * @link http://www.php.net/manual/en/memcached.delete.php
 *
 * @param string    $key    The key under which to store the value.
 * @param string    $group  The group value appended to the $key.
 * @param int       $time   The amount of time the server will wait to delete the item in seconds.
 * @return bool             Returns TRUE on success or FALSE on failure.
 */
function wp_cache_delete_multiple( $keys, $group = '', $time = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group, $time );
}


/**
 * Invalidate all items in the cache.
 *
 * @link http://www.php.net/manual/en/memcached.flush.php
 *
 * @param int       $delay  Number of seconds to wait before invalidating the items.
 * @return bool             Returns TRUE on success or FALSE on failure.
 */
function wp_cache_flush( $delay = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->flush( $delay );
}


/**
 * Retrieve object from cache.
 *
 * Gets an object from cache based on $key and $group.
 *
 * Note that the $deprecated and $found args are only here for compatibility with the native wp_cache_get function.
 *
 * @link http://www.php.net/manual/en/memcached.get.php
 *
 * @param string        $key        The key under which to store the value.
 * @param string        $group      The group value appended to the $key.
 * @param bool          $force      Whether or not to force a cache invalidation.
 * @param null|bool     $found      Variable passed by reference to determine if the value was found or not.
 * @return bool|mixed               Cached object value.
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;

	return $wp_object_cache->get( $key, $group, $force, $found );
}


/**
 * Gets multiple values from memcached in one request.
 *
 * See the buildKeys method definition to understand the $keys/$groups parameters.
 *
 * @link http://www.php.net/manual/en/memcached.getmulti.php
 *
 * @param array  $keys       Array of keys to retrieve.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool   $force Optional. Whether to force an update of the local cache
 *                      from the persistent cache. Default false.
 * @return bool|array               Returns the array of found items or FALSE on failure.
 */
function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	global $wp_object_cache;

	return $wp_object_cache->get_multiple( $keys, $group, $force );
}


/**
 * Increment a numeric item's value.
 *
 * This is the same as wp_cache_increment, but kept for back compatibility. The original
 * WordPress caching backends use wp_cache_incr. I want both to work.
 *
 * @link http://www.php.net/manual/en/memcached.increment.php
 *
 * @param string    $key    The key under which to store the value.
 * @param int       $offset The amount by which to increment the item's value.
 * @param string    $group  The group value appended to the $key.
 * @return int|bool         Returns item's new value on success or FALSE on failure.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}


/**
 * Replaces a value in cache.
 *
 * This method is similar to "add"; however, is does not successfully set a value if
 * the object's key is not already set in cache.
 *
 * @link http://www.php.net/manual/en/memcached.replace.php
 *
 * @param string    $key        The key under which to store the value.
 * @param mixed     $value      The value to store.
 * @param string    $group      The group value appended to the $key.
 * @param int       $expire The expiration time, defaults to 0.
 * @return bool                 Returns TRUE on success or FALSE on failure.
 */
function wp_cache_replace( $key, $value, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->replace( $key, $value, $group, $expire );
}


/**
 * Sets a value in cache.
 *
 * The value is set whether or not this key already exists in memcached.
 *
 * @link http://www.php.net/manual/en/memcached.set.php
 *
 * @param string    $key        The key under which to store the value.
 * @param mixed     $value      The value to store.
 * @param string    $group      The group value appended to the $key.
 * @param int       $expire The expiration time, defaults to 0.
 * @return bool                 Returns TRUE on success or FALSE on failure.
 */
function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $value, $group, $expire );
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
 * @param array         $items      An array of key/value pairs to store on the server.
 * @param string        $group      Group to merge with key(s) in $items.
 * @param int           $expire The expiration time, defaults to 0.
 * @return bool                     Returns TRUE on success or FALSE on failure.
 */
function wp_cache_set_multiple( $items, $group = '', $expire = 0 ) {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $items, $group, $expire );
}


/**
 * Switch blog prefix, which changes the cache that is accessed.
 *
 * @param  int     $blog_id    Blog to switch to.
 * @return void
 */
function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;
	return $wp_object_cache->switch_to_blog( $blog_id );
}



/**
 * Adds a group or set of groups to the list of non-persistent groups.
 *
 * @param   string|array    $groups     A group or an array of groups to add.
 * @return  void
 */
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}


/**
 * Adds a group or set of groups to the list of non-Memcached groups.
 *
 * @param   string|array    $groups     A group or an array of groups to add.
 * @return  void
 */
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}


/**
 * Closes the cache.
 *
 * This function has ceased to do anything since WordPress 2.5. The
 * functionality was removed along with the rest of the persistent cache. This
 * does not mean that plugins can't implement this function when they need to
 * make sure that the cache is cleaned up after WordPress no longer needs it.
 *
 * @since 2.0.0
 *
 * @return  bool    Always returns True
 */
function wp_cache_close() {
	return true;
}


/**
 * Get server pool statistics.
 *
 * @link http://www.php.net/manual/en/memcached.getstats.php
 *
 * @return array    Array of server statistics, one entry per server.
 */
function wp_cache_get_stats() {
	global $wp_object_cache;
	return $wp_object_cache->stats();
}

/**
 *  Flush the in memory cache.
 */

function wp_cache_flush_runtime() {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}


function wp_cache_flush_group( $group = '' ) {
	global $wp_object_cache;
	return $wp_object_cache->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	global $wp_object_cache;
	return $wp_object_cache->supports( $feature );
}

