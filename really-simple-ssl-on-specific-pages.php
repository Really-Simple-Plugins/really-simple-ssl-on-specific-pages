<?php
/**
 * Plugin Name: Really Simple SSL on specific pages
 * Plugin URI: https://www.really-simple-ssl.com
 * Description: Lightweight plugin without any setup to make your site ssl proof
 * Version: 2.0.8
 * Text Domain: really-simple-ssl-specific-pages
 * Domain Path: /languages
 * Author: Rogier Lankhorst
 * Author URI: https://www.rogierlankhorst.com
 * License: GPL2
 */

/*  Copyright 2014  Rogier Lankhorst  */

defined('ABSPATH') or die("you do not have access to this page!");

class REALLY_SIMPLE_SSL_PP
{

    private static $instance;
    public $rssl_front_end;
    public $rssl_mixed_content_fixer;
    public $rsssl_cache;
    public $really_simple_ssl;
    public $rsssl_help;
    public $rssslpp_licensing;
    public $page_option;
    public $rsssl_url;
    public $rsssl_server;

    private function __construct()
    {
    }

    public static function instance()
    {
        if (!isset(self::$instance) && !(self::$instance instanceof REALLY_SIMPLE_SSL_PP)) {

            self::$instance = new REALLY_SIMPLE_SSL_PP;
            self::$instance->setup_constants();
            self::$instance->includes();

            // Backwards compatibility for add-ons
            global $rsssl_front_end, $rsssl_mixed_content_fixer;

            self::$instance->rsssl_front_end = new rsssl_front_end();

            $rsssl_front_end = self::$instance->rsssl_front_end;

            self::$instance->rsssl_mixed_content_fixer = new rsssl_mixed_content_fixer();

            $rsssl_mixed_content_fixer = self::$instance->rsssl_mixed_content_fixer;

            if (is_admin()) {
                self::$instance->rsssl_cache = new rsssl_cache();
                self::$instance->rssslpp_licensing = new rssslpp_licensing();
                self::$instance->rsssl_url = new rsssl_url();
                self::$instance->really_simple_ssl = new rsssl_admin();
                self::$instance->page_option = new rsssl_page_option();
                self::$instance->rsssl_help = new rsssl_help();
                self::$instance->rsssl_server = new rsssl_server();
                if (is_multisite()) {
                    self::$instance->rsssl_multisite = new rsssl_multisite();
                }

                // Backwards compatibility for add-ons
                global $rsssl_cache, $rsssl_url, $really_simple_ssl, $rsssl_help, $page_option, $rssslpp_licensing, $rsssl_server;
                $rssslpp_licensing = self::$instance->rssslpp_licensing;
                $rsssl_url = self::$instance->rsssl_url;
                $rsssl_cache = self::$instance->rsssl_cache;
                $really_simple_ssl = self::$instance->really_simple_ssl;
                $page_option = self::$instance->page_option;
                $rsssl_help = self::$instance->rsssl_help;
                $rsssl_server = self::$instance->rsssl_server;
            }

            self::$instance->hooks();

        }

        return self::$instance;
    }

    private function setup_constants()
    {
        require_once(ABSPATH.'wp-admin/includes/plugin.php');
        $plugin_data = get_plugin_data(__FILE__);
        define('rsssl_pp_url', plugin_dir_url(__FILE__));
        define('rsssl_pp_path', plugin_dir_path(__FILE__));
        define('rsssl_pp_plugin', plugin_basename(__FILE__));
        define('rsssl_pp_version', $plugin_data['Version']);
    }

    private function includes()
    {
        require_once(rsssl_pp_path . '/class-front-end.php');
        require_once(rsssl_pp_path . '/class-mixed-content-fixer.php');

        if (is_admin()) {
            require_once(rsssl_pp_path . '/class-admin.php');
            require_once(rsssl_pp_path . '/class-cache.php');
            require_once(rsssl_pp_path . '/class-help.php');
            require_once(rsssl_pp_path . '/class-licensing.php');
            require_once(rsssl_pp_path . '/class-admin.php');
            require_once(rsssl_pp_path . '/class-cache.php');
            require_once(rsssl_pp_path . '/class-url.php');
            require_once(rsssl_pp_path . '/class-page-option.php');
            require_once(rsssl_pp_path . 'class-server.php');

            if (is_multisite()) {
                require_once(rsssl_pp_path . 'class-multisite.php');
            }
        }
    }

    private function hooks()
    {
        if (is_admin()) {
            add_action("plugins_loaded", array(self::$instance->really_simple_ssl, "init"), 10);
        }
        add_action("wp_loaded", array(self::$instance->rsssl_front_end, "force_ssl"), 20);
    }
}

add_action('admin_notices', 'rsssl_pp_admin_notices');
function rsssl_pp_admin_notices()
{
    if (class_exists('REALLY_SIMPLE_SSL')) {
        ?>
        <div id="message" class="error fade notice">
            <h1><?php echo __("Plugin conflict", "really-simple-ssl-pro"); ?></h1>
            <p><?php echo __("Really Simple SSL per page cannot run. Really Simple SSL needs to be deactivated for Really Simple SSL per page to work correctly.", "really-simple-ssl-pp"); ?></p>
            </p></div>
        <?php
    }
}
add_action('plugins_loaded', 'rsssl_load_per_page', 8);
function rsssl_load_per_page(){
    if (!class_exists('REALLY_SIMPLE_SSL') && !function_exists('RSSSL')) {
        function RSSSL()
        {
            return REALLY_SIMPLE_SSL_PP::instance();
        }

        add_action('plugins_loaded', 'RSSSL', 9);
    }
}



