<?php

use Roots\WPConfig\Config;
use function Env\env;


/**
 * Disable script concatenation. Useful pre HTTP/2
 * No longer needed and causes more problems in HTTP/2 context
 */
Config::define('CONCATENATE_SCRIPTS', false);

Config::define('WP_CACHE', true);

Config::define('WP_MEMORY_LIMIT', env('WP_MEMORY_LIMIT') ?? '40M');
Config::define('WP_MAX_MEMORY_LIMIT', env('WP_MAX_MEMORY_LIMIT') ?? '256M');

Config::define('EMPTY_TRASH_DAYS', env('EMPTY_TRASH_DAYS') ?? 30);

// live debugging parameters
Config::define('WP_DEBUG', env('WP_DEBUG') ?? false);
Config::define('SAVEQUERIES', env('SAVEQUERIES') ?? false);
Config::define('SCRIPT_DEBUG', env('SCRIPT_DEBUG') ?? false);
Config::define('WP_DEBUG_DISPLAY', false);
Config::define('WP_DEBUG_LOG', false);

Config::define('FS_METHOD', 'direct');
Config::define('YARPP_CACHE_TYPE', 'postmeta');

Config::define('COOKIE_DOMAIN', env('COOKIE_DOMAIN') ?? '');

Config::define('WP_AUTO_UPDATE_CORE', false);

define( 'PL_COMPAT_BEDROCK_WP_CONFIG_LOADED', true );
