<?php
/**
 * Plugin Name: Really Simple SSL on specific pages
 * Plugin URI: https://www.really-simple-ssl.com
 * Description: Lightweight plugin without any setup to make your site ssl proof
 * Version: 1.0.6
 * Text Domain: really-simple-ssl-specific-pages
 * Domain Path: /languages
 * Author: Rogier Lankhorst
 * Author URI: https://www.rogierlankhorst.com
 * License: GPL2
 */

/*  Copyright 2014  Rogier Lankhorst  */

defined('ABSPATH') or die("you do not have acces to this page!");

require_once( dirname( __FILE__ ) .  '/class-front-end.php' );
require_once( dirname( __FILE__ ) .  '/class-mixed-content-fixer.php' );

$rsssl_front_end            = new rsssl_front_end;
$rsssl_mixed_content_fixer  = new rsssl_mixed_content_fixer;

add_action("wp_loaded", array($rsssl_front_end, "force_ssl"),20);

if (is_admin()) {
  require_once( dirname( __FILE__ ) .  '/class-licensing.php' );
  require_once( dirname( __FILE__ ) .  '/class-admin.php' );
  require_once( dirname( __FILE__ ) .  '/class-cache.php' );
  require_once( dirname( __FILE__ ) .  '/class-url.php' );
  require_once( dirname( __FILE__ ) .  '/ajax.php' );
  require_once( dirname( __FILE__ ) .  '/class-page-option.php' );

  $rssslpp_licensing    = new rssslpp_licensing;
  $rsssl_url            = new rsssl_url;
  $rsssl_cache          = new rsssl_cache;
  $really_simple_ssl    = new rsssl_admin;
  $page_option          = new rsssl_page_option;

  add_action("plugins_loaded", array($really_simple_ssl, "init"),10);
}
