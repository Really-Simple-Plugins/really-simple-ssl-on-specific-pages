<?php

defined('ABSPATH') or die("you do not have acces to this page!");

if ( ! class_exists( 'rsssl_multisite' ) ) {
  class rsssl_multisite {
    private static $_this;

    public $option_group = "rsssl_network_options";
    public $page_slug = "really-simple-ssl";
    public $section = "rsssl_network_options_section";
    public $selected_networkwide_or_per_site;

    public $wp_redirect;
    public $htaccess_redirect;
    public $do_not_edit_htaccess;
    public $autoreplace_mixed_content;
    public $javascript_redirect;
    public $hide_menu_for_subsites;

    //settings for pro compatibility
    public $cert_expiration_warning = false;
    public $mixed_content_admin = false;
    public $ssl_enabled_networkwide = false;
    public $hsts = false;

    private $pro_url = "https://www.really-simple-ssl.com/pro-multisite";

  function __construct() {
    if ( isset( self::$_this ) )
        wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

    self::$_this = $this;


  }

  static function this() {
    return self::$_this;
  }





  /**
   * On plugin activation, we can check if it is networkwide or not.
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function activate($networkwide) {
    //if networkwide, we ask, if not, we set it as selected.
    if (!$networkwide) {
        $this->selected_networkwide_or_per_site = true;
        $this->ssl_enabled_networkwide = false;
        $this->save_options();
    }

  }



/*

    Check if the plugin is network activated.

*/


    public function plugin_network_wide_active(){
      if ( ! function_exists( 'is_plugin_active_for_network' ) )
        require_once( ABSPATH . '/wp-admin/includes/plugin.php' );

      if ( is_plugin_active_for_network(rsssl_pp_plugin) ){
        return true;
      } else {
        return false;
      }
    }


  //change deprecated function depending on version.

  public function get_sites_bw_compatible(){
    global $wp_version;
    $sites = ($wp_version >= 4.6 ) ? get_sites() : wp_get_sites();
    return $sites;
  }

  /*
        The new get_sites function returns an object.

  */

  public function switch_to_blog_bw_compatible($site){
    global $wp_version;
    if ($wp_version >= 4.6 ) {
      switch_to_blog( $site->blog_id );
    } else {
      switch_to_blog( $site[ 'blog_id' ] );
    }
  }

  public function deactivate(){

    $sites = $this->get_sites_bw_compatible();
    foreach ( $sites as $site ) {
      $this->switch_to_blog_bw_compatible($site);
      RSSSL()->really_simple_ssl->deactivate_ssl();
      restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
    }

}






/**
 * Checks if we are on a subfolder install. (domain.com/site1 )
 *
 * @since  2.2
 *
 * @access protected
 *
 */

public function is_multisite_subfolder_install() {
  if (!is_multisite()) return FALSE;
  //we check this manually, as the SUBDOMAIN_INSTALL constant of wordpress might return false for domain mapping configs
  $is_subfolder = FALSE;
  $sites = $this->get_sites_bw_compatible();
  foreach ( $sites as $site ) {
    $this->switch_to_blog_bw_compatible($site);
    if ($this->is_subfolder(home_url())) {
      $is_subfolder=TRUE;
    }
    restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
    if ($is_subfolder) return true;
  }

  return $is_subfolder;
}

/**
 * Test if a domain has a subfolder structure
 *
 * @since  2.2
 *
 * @param string $domain
 *
 * @access private
 *
 */

public function is_subfolder($domain) {

    //remove slashes of the http(s)
    $domain = preg_replace("/(http:\/\/|https:\/\/)/","",$domain);
    if (strpos($domain,"/")!==FALSE) {
      return true;
    }
    return false;
}

public function is_per_site_activated_multisite_subfolder_install() {
  if (is_multisite() && $this->is_multisite_subfolder_install() && !$this->ssl_enabled_networkwide){
    return true;
  }

  return false;
}












} //class closure
}
