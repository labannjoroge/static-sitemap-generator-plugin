<?php
/*
  Plugin Name: Static Sitemap Generator
  Plugin URI:  https://labanthegreat.dev/static-sitemap-generator
  Description: This plugin generates static sitemaps for your website
  Version: 1.0.0
  Author: LabanTheGreat
  Author URI:  https://labanthegreat.dev/
  Text Domain: sitemap
*/

/**
 * Returns the file used to load the sitemap plugin.
 *
 * This function provides the path to the current file, which serves as the entry point for the sitemap plugin.
 *
 * @return string The path and file of the sitemap plugin entry point.
 */
function prox_get_init_file() {
    return __FILE__;
}

// Don't do anything if this file was called directly.
if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) && ! class_exists( 'Static_sitemap_generator_loader', false ) ) {
    require_once(plugin_dir_path( __FILE__ ) . '/includes/sitemap-loader.php');
    require_once(plugin_dir_path( __FILE__ ) . '/includes/sitemap-generator.php');
    require_once(plugin_dir_path( __FILE__ ) . '/includes/sitemap-cron.php');
}


