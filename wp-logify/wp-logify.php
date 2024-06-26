<?php
/**
 * Plugin Name: WP Logify
 * Plugin URI: https://wplogify.com
 * Description: WP Logify features advanced tracking to ensure awareness of all changes made to your WordPress website, including who made them and when.
 * Version: 1.10.0
 * Author: Made Neat
 * Author URI: https://madeneat.com.au
 * License: GPL2
 *
 * WP Logify is a plugin that logs all changes made to your WordPress website, including who made them
 * and when. It features advanced tracking to ensure awareness of all changes made to your WordPress
 * website.
 *
 * This plugin was created by Made Neat, a web development agency based in Australia. We specialize in
 * creating custom WordPress websites and plugins for businesses of all sizes. If you need help with
 * your WordPress website, please get in touch with us at https://madeneat.com.au.
 *
 * This plugin is released under the GPL2 license. You are free to use, modify, and distribute this
 * plugin as you see fit. We hope you find it useful!
 *
 * @package WP_Logify
 */

namespace WP_Logify;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include files.
$plugin_path = plugin_dir_path( __FILE__ );
require_once "$plugin_path/includes/functions.php";
require_once "$plugin_path/includes/class-admin.php";
require_once "$plugin_path/includes/class-cron.php";
require_once "$plugin_path/includes/class-datetimes.php";
require_once "$plugin_path/includes/class-log-page.php";
require_once "$plugin_path/includes/class-logger.php";
require_once "$plugin_path/includes/class-plugin.php";
require_once "$plugin_path/includes/class-posts.php";
require_once "$plugin_path/includes/class-settings.php";
require_once "$plugin_path/includes/class-users.php";
require_once "$plugin_path/includes/class-widget.php";

// Register plugin hooks.
add_action( 'plugins_loaded', array( 'WP_Logify\Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'WP_Logify\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Logify\Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'WP_Logify\Plugin', 'uninstall' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( 'WP_Logify\Plugin', 'add_action_links' ) );
