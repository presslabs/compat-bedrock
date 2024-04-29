<?php
namespace Presslabs\CompatBedrock\ObjectCache;

interface ObjectCache
{
    public function add_global_groups( $groups );
    public function add_non_persistent_groups( $groups );
    public function switch_to_blog( $blog_id );
    public function get( $key, $group = '', $force = false, &$found = null );
    public function set( $key, $value, $group = '', $expire = 0 );
    public function add( $key, $value, $group = '', $expire = 0 );
    public function get_multiple( $keys, $group = '', $force = false );
    public function set_multiple( $keys, $group = '', $force = false );
    public function add_multiple( $keys, $group = '', $force = false );
    public function delete_multiple( $keys, $group = '', $time = 0 );
    public function replace( $key, $value, $group = '', $expire = 0) ;
    public function delete( $key, $group = '' );
    public function flush();
    public function flush_runtime();
    public function flush_group( $group = '' );
    public function close();
    public function incr( $key, $offset = 1, $group = '' );
    public function decr( $key, $offset = 1, $group = '' );
    public function stats();
    public function supports( $feature );
}
