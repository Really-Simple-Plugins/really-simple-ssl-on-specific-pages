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
      add_filter('home_url', array($this, 'conditional_ssl_home_url'),10,4);
      add_action('wp', array($this, 'redirect_to_ssl'), 40,3);
    }

    if (is_ssl() && $this->autoreplace_insecure_links) {
      add_action('template_include', array($this, 'replace_insecure_links_buffer'), 0);
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

  	//when nothing found, return default, which depends on exclusion settings.
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
    }


  }

  /**
   * Just before the page is sent to the visitor's browser, all homeurl links are replaced with https.
   *
   * @since  1.0
   *
   * @access public
   *
   */

   public function replace_insecure_links_buffer($template) {
     ob_start(array($this, 'replace_insecure_links'));
     return $template;
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

   /**
    * Just before the page is sent to the visitor's browser, all homeurl links are replaced with https.
    *
    * @since  1.0
    *
    * @access public
    *
    */

  public function replace_insecure_links($buffer) {

    $search_array = array("src='http://",'src="http://');
    $search_array = apply_filters('rlrsssl_replace_url_args', $search_array);
    $ssl_array = str_replace ( "http://" , "https://", $search_array);
    //now replace these links
    $buffer = str_replace ($search_array, $ssl_array , $buffer);

    //replace all http links except hyperlinks
    //all tags with src attr are already fixed by str_replace
    $pattern = array(
      '/url\([\'"]?\K(http:\/\/)(?=[^)]+)/i',
      '/<link .*?href=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
      '/<meta property="og:image" .*?content=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
      '/<form [^>]*?action=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
      //'/<(?:img|iframe) .*?src=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
      //'/<script [^>]*?src=[\'"]\K(http:\/\/)(?=[^\'"]+)/i',
    );
    $buffer = preg_replace($pattern, 'https://', $buffer);
    $buffer = $buffer.'<!-- Really Simple SSL mixed content fixer active -->';

    return apply_filters("rsssl_fixer_output", $buffer);;
  }

}}
