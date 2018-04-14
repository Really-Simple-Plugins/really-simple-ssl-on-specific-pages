<?php
defined('ABSPATH') or die("you do not have acces to this page!");

if ( ! class_exists( 'rsssl_front_end' ) ) {
  class rsssl_front_end {
    private static $_this;
    public $site_has_ssl                    = FALSE;
    public $autoreplace_insecure_links      = TRUE;
    public $http_urls                       = array();
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
   *
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
          force_ssl_admin(true);
      }

      if (!is_admin()) {
          add_filter('home_url', array($this, 'conditional_ssl_home_url'), 10, 4);
          add_action('wp', array($this, 'redirect_to_ssl'), 40, 3);
      }
    }

  }
    
  public function conditional_ssl_home_url($url, $path, $orig_scheme, $blog_id) {

      //if this url is the homeurl or siteurl, it should be decided by the homepage setting if it is https or not.
      $home = rtrim(get_option('home'),"/");
      $check_url = rtrim($url,"/");

      if (str_replace("https://", "http://", $check_url) == $home){
          if ($this->home_ssl){
              return str_replace( 'http://', 'https://', $url );
          } else {
              return str_replace( 'https://', 'http://', $url );
          }
      }

    $page = get_page_by_path( $path , OBJECT, get_post_types() );
  	if (!empty($page))  {
  		if (!$this->is_ssl_page($page->ID, $path)) {
  			return str_replace( 'https://', 'http://', $url );
  		}
  		if ($this->is_ssl_page($page->ID, $path)) {
  			return str_replace( 'http://', 'https://', $url );
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

     if (wp_doing_ajax() || is_admin() || is_preview() || $this->is_elementer_preview()) return;

     //maybe disable force redirect to http
     $force_redirect_to_http = !( defined('RSSSL_NO_HTTP_REDIRECT') && RSSSL_NO_HTTP_REDIRECT );

     $redirect_type = $this->permanent_redirect ? "301" : "302";

     if (is_front_page() && $this->home_ssl && !is_ssl()) {
        $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $redirect_url = apply_filters("rsssl_per_page_redirect_url", $redirect_url);
        wp_redirect($redirect_url, $redirect_type);
        exit;
     }

     //if it's the homepage, and homepage should not be SSL, but it is right now, redirect to http
     if ($force_redirect_to_http && is_front_page() && !$this->home_ssl && is_ssl()) {
         $redirect_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
         $redirect_url = apply_filters("rsssl_per_page_redirect_url", $redirect_url);
         wp_redirect($redirect_url, $redirect_type);
         exit;
     }

     if ($this->is_ssl_page() && !is_ssl()) {
        $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $redirect_url = apply_filters("rsssl_per_page_redirect_url", $redirect_url);
        wp_redirect($redirect_url, $redirect_type);
        exit;
     }

     //ssl, but not an ssl page? redirect to http. Might cause loops when https redirect is enabled.
     if ($force_redirect_to_http && !$this->is_ssl_page() && is_ssl()) {
         $redirect_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
         $redirect_url = apply_filters("rsssl_per_page_redirect_url", $redirect_url);
         wp_redirect($redirect_url, $redirect_type);
         exit;
     }

}

    /*
     *
     * Elementor loads a preview in page. If the page is http, this causes Elementor to fail because of mixed content
     * So we check this, and don't redirect to http.
     *
     *
     *
     * */


  public function is_elementer_preview(){

      if (isset($_GET['elementor-preview'])){
          return true;
      } else {
          return false;
      }

  }



  /*
    checks if current page, post or other posttype is supposed to be on SSL.

    if exclude url enabled, true for all pages EXCEPT in the pages list
    if not exclude url enabled, only true for pages in the pages list.

  */

  public function is_ssl_page($post_id=null, $path=''){
      if (empty($post_id)) {
          global $post;
          if ($post) $post_id = $post->ID;
      }

    //homepage needs special treatment
    if ($this->is_home($post_id)) {
        $sslpage = $this->home_ssl;
        //if ($this->exclude_pages) $sslpage = !$sslpage;
        $sslpage = apply_filters('rsssl_per_page_is_ssl_page', $sslpage, $post_id, $path);
        return $sslpage;
    } else {

        $sslpage = false;
        if ($post_id) {
            $sslpage = get_post_meta($post_id, "rsssl_ssl_page", true);
        }

        if ($this->exclude_pages) $sslpage = !$sslpage;

        $sslpage = apply_filters('rsssl_per_page_is_ssl_page', $sslpage, $post_id, $path);
        return $sslpage;
    }

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
      //with exclude pages from ssl, homepage is default https.
      $this->home_ssl                     = isset($options['home_ssl']) ? $options['home_ssl'] : $this->exclude_pages;
    }

  }

// add_filter('rsssl_per_page_is_ssl_page', 'rsssl_check_query_var', 10, 3);
// function rsssl_check_query_var($sslpage, $post_id, $path){
//   //if the query variable ‘play’ is appended, tell the plugin to set the current page to http: $sslpage = false
//  //this only applies when the current post is the same as the checked post, otherwise this would apply when the url contains the var, but we are checking another post.
//   global $post;
//   if (isset($_GET['play'])) {
//     $sslpage = false;
//   }
//
//   //if path was set, check for the variable 'play' in the path
//   if (str_pos($path, 'play')!==false)  {
//     $sslpage = false;
//   }
//
//   return $sslpage
// }


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

   public function is_home($post_id){
       if ($post_id == (int)get_option( 'page_on_front' )) {
           return true;
       }
       return false;
   }

}}
