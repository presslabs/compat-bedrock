<?php
/*
Plugin Name:  Presslabs Bedrock Compat
Plugin URI:   https://github.com/presslabs/compat-bedrock
Description:  A WordPress plugin that allows the site to run in presslabs environment.
Version:      0.1.0
Author:       Presslabs
Author URI:   https://presslabs.com/
License:      MIT License
*/

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! class_exists( '\Presslabs\CompatBedrock\CompatBedrockPlugin' ) ) {
    trigger_error( 'Presslabs CompatBedrock WordPress mu-plugin is not fully installed! Please install with Composer.', E_USER_ERROR );
}

use \Presslabs\CompatBedrock\CompatBedrockPlugin;

$compat_bedrock_plugin = new CompatBedrockPlugin();
$compat_bedrock_plugin->install();

// other presslabs plugins
$presslabs_entrypoint = "/www/presslabs/000-init-presslabs.php";
if ( file_exists( $presslabs_entrypoint ) ) {
    require_once '/www/presslabs/dropins/init.php';
    require_once $presslabs_entrypoint;
}
