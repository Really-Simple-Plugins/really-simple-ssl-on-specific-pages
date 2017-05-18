<?php
defined('ABSPATH') or die("you do not have acces to this page!");

if ( ! class_exists( 'rsssl_front_end' ) ) {
  class rsssl_front_end {
    private static $_this;
    public $site_has_ssl                    = FALSE;
    public $autoreplace_insecure_links      = TRUE;
    public $http_urls                       = array();
    public $ssl_pages                       = array();
    public $exclude_pages                   = FALSE;
    public $permanent_redirect              = FALSE;
    public $home_ssl;

  function __construct() {
    if ( isset( self::$_this ) )
        wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

    self::$_this = $this;
    $this->get_options();
  }

  static function this() {
    return self::$_this;
  }

  /**
   * Javascript redirect, when ssl is true.
   * Mixed content replacement when ssl is true and fixer is enabled.
   *
   * @since  2.2
   *
   * @access public
   *
   */

  public function force_ssl() {

    if ($this->ssl_enabled) {
      if (!(defined('rsssl_pp_backend_http') && rsssl_pp_backend_http)) {
        if (!defined('FORCE_SSL_ADMIN')) define('FORCE_SSL_ADMIN', true);
        if (!defined('FORCE_SSL_LOGIN')) define('FORCE_SSL_LOGIN', true);
      }

      add_filter('home_url', array($this, 'conditional_ssl_home_url'),10,4);
      add_action('wp', array($this, 'redirect_to_ssl'), 40,3);
    }

  }

  public function conditional_ssl_home_url($url, $path) {
  	$page = get_page_by_path( $path , OBJECT, get_post_types() );
  	if (!empty($page))  {
  		if (!$this->is_ssl_page($page->ID)) {
  			return str_replace( 'https://', 'http://', $url );
  		}
  		if ($this->is_ssl_page($page->ID)) {
  			return str_replace( 'http://', 'https://', $url );
  		}
  	}

    //when excluded ssl, homepage not ssl, in case of homepage it should return http.
    //when dedault, homepage ssl, in case of homepage it should return https.

    if (is_home() || is_front_page()) {
      if ($this->home_ssl){
        return str_replace( 'http://', 'https://', $url );
      } else {
        return str_replace( 'https://', 'http://', $url );
      }
    }

    //if we're here, it's not a page, post, or homepage. give back a default just in case.
  	//return default, which depends on exclusion settings.
    if ($this->exclude_pages) {
  	    return str_replace( 'http://', 'https://', $url );
    } else {
        return str_replace( 'https://', 'http://', $url );
    }
  }

 public function redirect_to_ssl() {

  if (($this->is_ssl_page()) && !is_ssl()) {
		$redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $redirect_type = $this->permanent_redirect ? "301" : "302";
    wp_redirect($redirect_url, $redirect_type);
    exit;
	}
}

  /*
    checks if current page, post or other posttype is supposed to be on SSL.

    if exclude url enabled, true for all pages EXCEPT in the pages list
    if not exclude url enabled, only true for pages in the pages list.
  */

  private function is_ssl_page($post_id=null){
    //when pages are excluded from SSL, default SSL
    $sslpage = FALSE;
    if ($this->exclude_pages) {
        $sslpage = TRUE;
    }

    if (empty($post_id)) {
      global $post;
      if ($post) $post_id = $post->ID;
    }

    $sslpage = false;
    if ($post_id) {
      if (in_array($post_id, $this->ssl_pages)) $sslpage = TRUE;
    }

    if ($this->exclude_pages)
        $sslpage = !$sslpage;

    return $sslpage;
  }


  /**
   * Get the options for this plugin
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function get_options(){
    $options = get_option('rlrsssl_options');

    if (isset($options)) {
      $this->site_has_ssl                 = isset($options['site_has_ssl']) ? $options['site_has_ssl'] : FALSE;
      $this->exclude_pages                = isset($options['exclude_pages']) ? $options['exclude_pages'] : FALSE;
      $this->permanent_redirect           = isset($options['permanent_redirect']) ? $options['permanent_redirect'] : FALSE;
      $this->autoreplace_insecure_links   = isset($options['autoreplace_insecure_links']) ? $options['autoreplace_insecure_links'] : TRUE;
      $this->ssl_enabled                  = isset($options['ssl_enabled']) ? $options['ssl_enabled'] : $this->site_has_ssl;
      $this->ssl_pages                    = isset($options['ssl_pages']) ? $options['ssl_pages'] : array();
      //with exclude pages from ssl, homepage is default https.
      $this->home_ssl                     = isset($options['home_ssl']) ? $options['home_ssl'] : $this->exclude_pages;
    }

  }


   /**
    * Checks if we are currently on ssl protocol, but extends standard wp with loadbalancer check.
    *
    * @since  2.0
    *
    * @access public
    *
    */

   public function is_ssl_extended(){
     if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
       $loadbalancer = TRUE;
     }
     else {
       $loadbalancer = FALSE;
     }

     if (is_ssl() || $loadbalancer){
       return true;
     } else {
       return false;
     }
   }



}}
