<?php
defined('ABSPATH') or die("you do not have acces to this page!");
if (!class_exists('rsssl_admin')) {
  class rsssl_admin extends rsssl_front_end {

  private static $_this;

  //true when siteurl and homeurl are defined in wp-config and can't be changed
  public $wpconfig_siteurl_not_fixed          = FALSE;
  public $no_server_variable                  = FALSE;
  public $errors                              = Array();

  public $do_wpconfig_loadbalancer_fix        = FALSE;
  public $ssl_enabled                         = FALSE;

  //for pro compatibility
  public $do_not_edit_htaccess                = FALSE;

  //multisite variables
  public $ssl_enabled_networkwide           = FALSE;
  public $selected_networkwide_or_per_site  = FALSE;

  //general settings
  public $capability                        = 'manage_options';

  public $ssl_test_page_error;

  public $plugin_dir                        = "really-simple-ssl-on-specific-pages";
  public $plugin_filename                   = "really-simple-ssl-on-specific-pages.php";
  public $ABSpath;

  //this option is needed for compatibility with the per page plugin.
  public $hsts                              = FALSE;

  public $ssl_success_message_shown         = FALSE;
  public $debug							                = TRUE;

  public $debug_log;

  public $plugin_conflict                   = ARRAY();
  public $plugin_url;
  public $plugin_version;
  public $plugin_db_version;
  public $plugin_upgraded;
  public $mixed_content_fixer_status        = 0;
  public $ssl_type                          = "NA";
                                            //possible values:
                                            //"NA":     test page did not return valid response
                                            //"SERVER-HTTPS-ON"
                                            //"SERVER-HTTPS-1"
                                            //"SERVERPORT443"
                                            //"LOADBALANCER"
                                            //"CDN"
  private $ad = false;
  private $pro_url = "https://www.really-simple-ssl.com/pro";

  function __construct() {
    if ( isset( self::$_this ) )
        wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

    self::$_this = $this;

    $this->get_options();
    $this->get_admin_options();
    $this->ABSpath = $this->getABSPATH();
    $this->get_plugin_version();
    $this->get_plugin_upgraded(); //call always, otherwise db version will not match anymore.

    register_activation_hook(  dirname( __FILE__ )."/".$this->plugin_filename, array($this,'activate') );
    register_deactivation_hook(dirname( __FILE__ )."/".$this->plugin_filename, array($this,'deactivate') );
  }

  static function this() {
    return self::$_this;
  }


  /*
   * This function is needed for compatibility with Really Simple SSL pro
   * */

  public function contains_hsts() {
    if (!file_exists($this->ABSpath.".htaccess")) {
      $this->trace_log(".htaccess not found in ".$this->ABSpath);
      $result = $this->hsts; //just return the setting.
    } else {
      $htaccess = file_get_contents($this->ABSpath.".htaccess");

      preg_match("/Strict-Transport-Security/", $htaccess, $check);
      if(count($check) === 0){
        $result = false;
      } else {
        $result = true;
      }
    }

    return $result;
  }

  /**
   * Initializes the admin class
   *
   * @since  2.2
   *
   * @access public
   *
   */

  public function init() {

    $is_on_settings_page = $this->is_settings_page();
    $this->get_plugin_url();//has to be after the domain list was built.

    /*
      Detect configuration when:
      - SSL activation just confirmed.
      - on settings page
      - No SSL detected -> check again
    */

    if ($this->clicked_activate_ssl() || !$this->ssl_enabled || !$this->site_has_ssl || $is_on_settings_page) {
      $this->detect_configuration();

      //flush caches when just activated ssl, or when ssl is enabled
      if ($this->clicked_activate_ssl() || ($this->ssl_enabled)) {
        global $rsssl_cache;
        add_action('admin_init', array($rsssl_cache,'flush'),40);
      }

      if (!$this->wpconfig_ok()) {
        //if we were to activate ssl, this could result in a redirect loop. So warn first.
        add_action("admin_notices", array($this, 'show_notice_wpconfig_needs_fixes'));
        if (is_multisite()) add_action('network_admin_notices', array($this, 'show_notice_wpconfig_needs_fixes'), 10);

        $this->ssl_enabled = false;
        $this->save_options();
      } elseif ($this->ssl_enabled) {
        add_action('init',array($this,'configure_ssl'),20);
      }
    }

    if (is_multisite() && !$this->selected_networkwide_or_per_site) {
      add_action('network_admin_notices', array($this, 'show_notice_activate_networkwide'), 10);
    }

    //when ssl and not enabled by user, ask for activation.
    if (!$this->ssl_enabled && (!is_multisite() || $this->selected_networkwide_or_per_site) ) {
      $this->trace_log("ssl not enabled, show notice");
      add_action("admin_notices", array($this, 'show_notice_activate_ssl'),10);
    }

    add_action('plugins_loaded', array($this,'check_plugin_conflicts'),30);

    //add the settings page for the plugin
    add_action('admin_menu', array($this,'setup_admin_page'),30);

    //check if the uninstallfile is safely renamed to php.
    $this->check_for_uninstall_file();

    //callbacks for the ajax dismiss buttons
    add_action('wp_ajax_dismiss_success_message', array($this,'dismiss_success_message_callback') );

    //handle notices
    add_action('admin_notices', array($this,'show_notices'));

  }


  /*
    checks if the user just clicked the "activate ssl" button.
  */

  private function clicked_activate_ssl() {
    if (!isset( $_POST['rsssl_nonce'] ) || !wp_verify_nonce( $_POST['rsssl_nonce'], 'rsssl_nonce' )) return false;

    if (isset($_POST['rsssl_do_activate_ssl'])) {
      $this->ssl_enabled=true;
      $this->save_options();
      return true;
    }

    if (is_multisite() && isset($_POST['rsssl_do_activate_ssl_networkwide'])) {

      $sites = $this->get_sites_bw_compatible();

      foreach ( $sites as $site ) {
        $this->switch_to_blog_bw_compatible($site);
        $this->ssl_enabled = true;
        $this->save_options();
        restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
      }

      $this->selected_networkwide_or_per_site = true;
      $this->ssl_enabled_networkwide = true;
      $this->save_options();
      return true;
    }

    if (is_multisite() && isset($_POST['rsssl_do_activate_ssl_per_site'])) {
      $this->ssl_enabled_networkwide = false;
      $this->selected_networkwide_or_per_site = true;
      $this->save_options();
      return true;
    }


    return false;
  }

  public function wpconfig_ok(){
    if (($this->do_wpconfig_loadbalancer_fix || $this->no_server_variable || $this->wpconfig_siteurl_not_fixed) && !$this->wpconfig_is_writable() ) {
      return false;
    } else {
      return true;
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


  /**
   * On plugin activation, we can check if it is networkwide or not.
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function activate($networkwide) {
    if (!is_multisite()) return;
    //if networkwide, we ask, if not, we set it as selected.
    if (!$networkwide) {
        $this->trace_log("per site activation");
        $this->ssl_enabled_networkwide = FALSE;
        $this->selected_networkwide_or_per_site = TRUE;
        $this->save_options();
    }

  }

  /**
   * Give the user an option to activate network wide or not.
   *
   * @since  2.3
   *
   * @access public
   *
   */

  public function show_notice_activate_networkwide(){
    if (is_main_site(get_current_blog_id()) && $this->wpconfig_ok()) {
      ?>
      <div id="message" class="updated fade notice activate-ssl">
        <h1><?php _e("Choose your preferred setup","really-simple-ssl");?></h1>
      <p>
        <form action="" method="post">
          <?php wp_nonce_field( 'rsssl_nonce', 'rsssl_nonce' );?>
          <input type="submit" class='button button-primary' value="<?php _e("Activate SSL networkwide","really-simple-ssl");?>" id="rsssl_do_activate_ssl_networkwide" name="rsssl_do_activate_ssl_networkwide">
          <input type="submit" class='button button-primary' value="<?php _e("Activate SSL per site","really-simple-ssl");?>" id="rsssl_do_activate_ssl_per_site" name="rsssl_do_activate_ssl_per_site">
        </form>
      </p>
      <p>
        <?php _e("Networkwide activation does not check if a site has an SSL certificate, but you can select the pages you want on SSL for each site separately.","really-simple-ssl");?>
      </p>
      </div>
      <?php
    }
  }

  /*
      This message is shown when no ssl is not enabled by the user yet
  */

  public function show_notice_activate_ssl(){
    //for multisite, show no ssl message only on main blog.
    if (is_multisite() && !is_main_site(get_current_blog_id()) && !$this->site_has_ssl) return;
    if (!$this->wpconfig_ok()) return;
    if (!current_user_can($this->capability)) return;

    if (!$this->site_has_ssl) {  ?>
      <div id="message" class="error fade notice activate-ssl">
      <p><?php _e("No SSL was detected. If you do have an ssl certificate, try to change your current url in the browser address bar to https.","really-simple-ssl");?></p>

    <?php } else {?>

    <div id="message" class="updated fade notice activate-ssl">
      <h1><?php _e("Almost ready to enable SSL for some pages!","really-simple-ssl");?></h1>
    <?php
  }?>
  <?php _e("Some things can't be done automatically. Before you start, please check for: ",'really-simple-ssl');?>
  <p>
    <ul>
      <li><?php _e('Http references in your .css and .js files: change any http:// into //','really-simple-ssl');?></li>
      <li><?php _e('Images, stylesheets or scripts from a domain without an ssl certificate: remove them or move to your own server.','really-simple-ssl');?></li>
    </ul>
  </p>

  <?php if ($this->site_has_ssl) {?>
      <form action="" method="post">
        <?php wp_nonce_field( 'rsssl_nonce', 'rsssl_nonce' );?>
        <input type="submit" class='button button-primary' value="Enable SSL per page!" id="rsssl_do_activate_ssl" name="rsssl_do_activate_ssl">
      </form>
  <?php } ?>
  </div>
  <?php }

  /**
    * @since 2.3
    * Shows option to buy pro

  */

  public function show_pro(){
    if (!$this->ad) return;
    ?>
  <p ><?php _e('For an extensive scan of your website, with a list of items to fix, and instructions how to do it, Purchase Really Simple SSL Pro, which includes:','really-simple-ssl');?>
    <ul class="rsssl_bullets">
      <li><?php _e('Full website scan for mixed content in .css and .js files','really-simple-ssl');?></li>
      <li><?php _e('Full website scan for any resource that is loaded from another domain, and cannot load over ssl','really-simple-ssl');?></li>
      <li><?php _e('Full website scan to find external css or js files with mixed content.','really-simple-ssl');?></li>
    </ul></p>
    <a target="_blank" href="<?php echo $this->pro_url;?>" class='button button-primary'>Learn about Really Simple SSL PRO</a>
    <?php
  }

  public function wpconfig_is_writable() {
    $wpconfig_path = $this->find_wp_config_path();
    if (is_writable($wpconfig_path))
      return true;
    else
      return false;
  }

  /*
  *     Check if the uninstall file is renamed to .php
  */

  protected function check_for_uninstall_file() {
    if (file_exists(dirname( __FILE__ ) .  '/force-deactivate.php')) {
      $this->errors["DEACTIVATE_FILE_NOT_RENAMED"] = true;
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

  public function get_admin_options(){
    $options = get_option('rlrsssl_options');
    if (isset($options)) {
      $this->ssl_success_message_shown          = isset($options['ssl_success_message_shown']) ? $options['ssl_success_message_shown'] : FALSE;
      $this->plugin_db_version                  = isset($options['plugin_db_version']) ? $options['plugin_db_version'] : "1.0";
      $this->debug                              = isset($options['debug']) ? $options['debug'] : FALSE;
    }

    if (is_multisite()) {
      //set rewrite_rule_per_site is deprecated, moving to the ssl_enabled_networkwide property.
      $network_options = get_site_option('rlrsssl_network_options');
      if (isset($network_options) ) {
        $this->ssl_enabled_networkwide  = isset($network_options['ssl_enabled_networkwide']) ? $network_options['ssl_enabled_networkwide'] : FALSE;
        $this->selected_networkwide_or_per_site  = isset($network_options['selected_networkwide_or_per_site']) ? $network_options['selected_networkwide_or_per_site'] : FALSE;
      }
    }
  }

  /**
   * check if the plugin was upgraded to a new version
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function get_plugin_upgraded() {
  	if ($this->plugin_db_version!=$this->plugin_version) {
  		$this->plugin_db_version = $this->plugin_version;
  		$this->plugin_upgraded = true;
      $this->save_options();
  	}
    $this->plugin_upgraded = false;
  }

  /**
   * Log events during plugin execution
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function trace_log($msg) {
    if (!$this->debug) return;
    $this->debug_log = $this->debug_log."<br>".$msg;
    //$this->debug_log = strstr($this->debug_log,'** Detecting configuration **');
    error_log($msg);
  }

  /**
   * Configures the site for ssl
   *
   * @since  2.2
   *
   * @access public
   *
   */

  public function configure_ssl() {
      if (!current_user_can($this->capability)) return;
      $this->trace_log("** Configuring SSL **");
      if ($this->site_has_ssl) {
        //in a configuration of loadbalancer without a set server variable https = 0, add code to wpconfig
        if ($this->do_wpconfig_loadbalancer_fix)
            $this->wpconfig_loadbalancer_fix();
      }
  }

  /**
   * Check to see if we are on the settings page, action hook independent
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function is_settings_page() {
    if (!isset($_SERVER['QUERY_STRING'])) return false;

    parse_str($_SERVER['QUERY_STRING'], $params);
    if (array_key_exists("page", $params) && ($params["page"]=="rlrsssl_really_simple_ssl")) {
        return true;
    }
    return false;
  }



  /**
   * Retrieves the current version of this plugin
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function get_plugin_version() {
      if ( ! function_exists( 'get_plugins' ) )
          require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
      $plugin_folder = get_plugins( '/' . plugin_basename( dirname( __FILE__ ) ) );

      $this->plugin_version = $plugin_folder[$this->plugin_filename]['Version'];
  }

  /**
   * Find the path to wp-config
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function find_wp_config_path() {
    //limit nr of iterations to 20
    $i=0;
    $maxiterations = 20;
    $dir = dirname(__FILE__);
    do {
        $i++;
        if( file_exists($dir."/wp-config.php") ) {
            return $dir."/wp-config.php";
        }
    } while( ($dir = realpath("$dir/..")) && ($i<$maxiterations) );
    return null;
  }


  /**
   * Check if the wpconfig is already fixed
   *
   * @since  2.2
   *
   * @access public
   *
   */

  public function wpconfig_has_fixes() {
    $wpconfig_path = $this->find_wp_config_path();
    if (empty($wpconfig_path)) return false;
    $wpconfig = file_get_contents($wpconfig_path);

    //only one of two fixes possible.
    if (strpos($wpconfig, "//Begin Really Simple SSL Load balancing fix")!==FALSE ) {
      return true;
    }

    if (strpos($wpconfig, "//Begin Really Simple SSL Server variable fix")!==FALSE ) {
      return true;
    }

    return false;
  }

  /**
   * In case of load balancer without server https on, add fix in wp-config
   *
   * @since  2.1
   *
   * @access public
   *
   */


  public function wpconfig_loadbalancer_fix() {
      if (!current_user_can($this->capability)) return;

      $wpconfig_path = $this->find_wp_config_path();
      if (empty($wpconfig_path)) return;
      $wpconfig = file_get_contents($wpconfig_path);
      $this->wpconfig_loadbalancer_fix_failed = FALSE;
      //only if loadbalancer AND NOT SERVER-HTTPS-ON should the following be added. (is_ssl = false)
      if (strpos($wpconfig, "//Begin Really Simple SSL Load balancing fix")===FALSE ) {
        if (is_writable($wpconfig_path)) {
          $rule  = "\n"."//Begin Really Simple SSL Load balancing fix"."\n";
          $rule .= '$server_opts = array("HTTP_CLOUDFRONT_FORWARDED_PROTO" => "https", "HTTP_CF_VISITOR"=>"https", "HTTP_X_FORWARDED_PROTO"=>"https", "HTTP_X_FORWARDED_SSL"=>"on", "HTTP_X_FORWARDED_SSL"=>"1");'."\n";
          $rule .= 'foreach( $server_opts as $option => $value ) {'."\n";
          $rule .=   'if ( (isset($_ENV["HTTPS"]) && ( "on" == $_ENV["HTTPS"] )) || (isset( $_SERVER[ $option ] ) && ( strpos( $_SERVER[ $option ], $value ) !== false )) ) {'."\n";
          $rule .=     '$_SERVER[ "HTTPS" ] = "on";'."\n";
          $rule .=     'break;'."\n";
          $rule .=   '}'."\n";
          $rule .= '}'."\n";
          $rule .= "//END Really Simple SSL"."\n";

          $insert_after = "<?php";
          $pos = strpos($wpconfig, $insert_after);
          if ($pos !== false) {
              $wpconfig = substr_replace($wpconfig,$rule,$pos+1+strlen($insert_after),0);
          }

          file_put_contents($wpconfig_path, $wpconfig);
          if ($this->debug) {$this->trace_log("wp config loadbalancer fix inserted");}
        } else {
          if ($this->debug) {$this->trace_log("wp config loadbalancer fix FAILED");}
          $this->wpconfig_loadbalancer_fix_failed = TRUE;
        }
      } else {
        if ($this->debug) {$this->trace_log("wp config loadbalancer fix already in place, great!");}
      }
      $this->save_options();

  }

  /**
   * Checks if we are on a subfolder install. (domain.com/site1 )
   *
   * @since  2.2
   *
   * @access protected
   *
   */

  protected function is_multisite_subfolder_install() {
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
   * Removing changes made to the wpconfig
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function remove_wpconfig_edit() {

    $wpconfig_path = $this->find_wp_config_path();
    if (empty($wpconfig_path)) return;
    $wpconfig = file_get_contents($wpconfig_path);

    //check for permissions
    if (!is_writable($wpconfig_path)) {
      if ($this->debug) $this->trace_log("could not remove wpconfig edits, wp-config.php not writable");
      $this->errors['wpconfig not writable'] = TRUE;
      return;
    }

    //remove edits
    $wpconfig = preg_replace("/\/\/Begin\s?Really\s?Simple\s?SSL.*?\/\/END\s?Really\s?Simple\s?SSL/s", "", $wpconfig);
    $wpconfig = preg_replace("/\n+/","\n", $wpconfig);
    file_put_contents($wpconfig_path, $wpconfig);

    //in multisite environment, with per site activation, re-add
    if (is_multisite() && !$this->ssl_enabled_networkwide) {

      if ($this->do_wpconfig_loadbalancer_fix)
        $this->wpconfig_loadbalancer_fix();

    }
}

  /**
   * Save the plugin options
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function save_options() {
    //any options added here should also be added to function options_validate()
    $options = array(
      'site_has_ssl'                      => $this->site_has_ssl,
      'exclude_pages'                     => $this->exclude_pages,
      'permanent_redirect'                => $this->permanent_redirect,
      'ssl_success_message_shown'         => $this->ssl_success_message_shown,
      'autoreplace_insecure_links'        => $this->autoreplace_insecure_links,
      'plugin_db_version'                 => $this->plugin_db_version,
      'debug'                             => $this->debug,
      'ssl_enabled'                       => $this->ssl_enabled,
      'ssl_pages'                         => $this->ssl_pages,
      'home_ssl'                          => $this->home_ssl,
    );

    update_option('rlrsssl_options',$options);

    //save multisite options
    if (is_multisite()) {
      $network_options = array(
          'ssl_enabled_networkwide'  => $this->ssl_enabled_networkwide,
          'selected_networkwide_or_per_site'  => $this->selected_networkwide_or_per_site,
      );

      update_site_option('rlrsssl_network_options', $network_options);
    }
  }

  /**
   * Load the translation files
   *
   * @since  1.0
   *
   * @access public
   *
   */

  public function load_translation()
  {
      load_plugin_textdomain('really-simple-ssl', FALSE, dirname(plugin_basename(__FILE__)).'/languages/');
  }

  /**
   * Handles deactivation of this plugin
   *
   * @since  2.0
   *
   * @access public
   *
   */

  public function deactivate($networkwide) {
    $this->site_has_ssl                         = FALSE;
    $this->ssl_success_message_shown            = FALSE;
    $this->autoreplace_insecure_links           = TRUE;
    $this->ssl_enabled                          = FALSE;
    $this->save_options();


    if ($networkwide) {
      $this->ssl_enabled_networkwide              = FALSE;
      $this->selected_networkwide_or_per_site     = FALSE;

      $sites = $this->get_sites_bw_compatible();
      foreach ( $sites as $site ) {
        $this->switch_to_blog_bw_compatible($site);
        $this->ssl_enabled = false;
        $this->save_options();
        restore_current_blog(); //switches back to previous blog, not current, so we have to do it each loop
      }

    }

    $this->remove_wpconfig_edit();
  }

  private function check_for_siteurl_in_wpconfig(){

    $wpconfig_path = $this->find_wp_config_path();

    if (empty($wpconfig_path)) return;

    $wpconfig = file_get_contents($wpconfig_path);
    $homeurl_pattern = '/(define\(\s*\'WP_HOME\'\s*,\s*\'http\:\/\/)/';
    $siteurl_pattern = '/(define\(\s*\'WP_SITEURL\'\s*,\s*\'http\:\/\/)/';

    $this->wpconfig_siteurl_not_fixed = FALSE;
    if (preg_match($homeurl_pattern, $wpconfig) || preg_match($siteurl_pattern, $wpconfig) ) {
        $this->wpconfig_siteurl_not_fixed = TRUE;
        $this->trace_log("siteurl or home url defines found in wpconfig");
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
    $server_var = FALSE;
		$server_opts = array(
      'HTTP_X_FORWARDED_PROTO'=>'https',
      'HTTP_CLOUDFRONT_FORWARDED_PROTO' => 'https',
      'HTTP_CF_VISITOR'=>'https',
      'HTTP_X_FORWARDED_SSL'=>'on',
      'HTTP_X_FORWARDED_SSL'=>'1'
    );

		foreach( $server_opts as $option => $value ) {
			if ( (isset($_ENV['HTTPS']) && ( 'on' == $_ENV['HTTPS'] ))
        || (isset( $_SERVER[ $option ] ) && ( strpos( $_SERVER[ $option ], $value ) !== false ) )) {
				$server_var = TRUE;
				break;
			}
		}

    if (is_ssl() || $server_var){
      return true;
    } else {
      return false;
    }
  }



  /**
   * Checks for SSL by opening a test page in the plugin directory
   *
   * @since  2.0
   *
   * @access public
   *
   */

   public function detect_configuration() {
     global $rsssl_url;
     $this->trace_log("** Detecting configuration **");
     $this->trace_log("plugin version: ".$this->plugin_version);
     $old_ssl_setting = $this->site_has_ssl;
     $filecontents = "";

     //if current page is on ssl, we can assume ssl is available, even when an errormsg was returned
     if($this->is_ssl_extended()){
       $this->trace_log("Already on SSL, start detecting configuration");
       $this->site_has_ssl = TRUE;
     } else {
       //we're not on SSL, or no server vars were returned, so test with the test-page.
       //plugin url: domain.com/wp-content/etc
       $plugin_url = str_replace ( "http://" , "https://" , $this->plugin_url);
       $testpage_url = trailingslashit($plugin_url)."ssl-test-page.php";
       $this->trace_log("Opening testpage to check for ssl: ".$testpage_url);
       $filecontents = $rsssl_url->get_contents($testpage_url);

       if($rsssl_url->error_number!=0) {
         $errormsg = $rsssl_url->get_curl_error($rsssl_url->error_number);
         $this->site_has_ssl = FALSE;
         $this->trace_log("No ssl detected. the ssl testpage returned an error: ".$errormsg);
       } else {
         $this->site_has_ssl = TRUE;
         $this->trace_log("SSL test page loaded successfully");
       }
     }

     if ($this->site_has_ssl) {
       //check the type of ssl, either by parsing the returned string, or by reading the server vars.
       if ((strpos($filecontents, "#CLOUDFRONT#") !== false) || (isset($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO']) && ($_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO'] == 'https'))) {
         $this->ssl_type = "CLOUDFRONT";
       } elseif ((strpos($filecontents, "#CLOUDFLARE#") !== false) || (isset($_SERVER['HTTP_CF_VISITOR']) && ($_SERVER['HTTP_CF_VISITOR'] == 'https'))) {
         $this->ssl_type = "CLOUDFLARE";
       } elseif ((strpos($filecontents, "#LOADBALANCER#") !== false) || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'))) {
         $this->ssl_type = "LOADBALANCER";
       } elseif ((strpos($filecontents, "#CDN#") !== false) || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && ($_SERVER['HTTP_X_FORWARDED_SSL'] == 'on' || $_SERVER['HTTP_X_FORWARDED_SSL'] == '1'))) {
         $this->ssl_type = "CDN";
       } elseif ((strpos($filecontents, "#SERVER-HTTPS-ON#") !== false) || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on')) {
         $this->ssl_type = "SERVER-HTTPS-ON";
       } elseif ((strpos($filecontents, "#SERVER-HTTPS-1#") !== false) || (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == '1')) {
         $this->ssl_type = "SERVER-HTTPS-1";
       } elseif ((strpos($filecontents, "#SERVERPORT443#") !== false) || (isset($_SERVER['SERVER_PORT']) && ( '443' == $_SERVER['SERVER_PORT'] ))) {
         $this->ssl_type = "SERVERPORT443";
       } elseif ((strpos($filecontents, "#ENVHTTPS#") !== false) || (isset($_ENV['HTTPS']) && ( 'on' == $_ENV['HTTPS'] ))) {
          $this->ssl_type = "ENVHTTPS";
       } elseif ((strpos($filecontents, "#NO KNOWN SSL CONFIGURATION DETECTED#") !== false)) {
         //if we are here, SSL was detected, but without any known server variables set.
         //So we can use this info to set a server variable ourselfes.
         if (!$this->wpconfig_has_fixes()) {
           $this->no_server_variable = TRUE;
         }
         $this->trace_log("No server variable detected ");
         $this->ssl_type = "NA";
       } else {
         //no valid response, so set to NA
         $this->ssl_type = "NA";
       }

       //check for is_ssl()
       if ( (!$this->is_ssl_extended() &&
            (strpos($filecontents, "#SERVER-HTTPS-ON#") === false) &&
            (strpos($filecontents, "#SERVER-HTTPS-1#") === false) &&
            (strpos($filecontents, "#SERVERPORT443#") === false)) || (!is_ssl() && $this->is_ssl_extended())) {
         //when is_ssl would return false, we should add some code to wp-config.php
         if (!$this->wpconfig_has_fixes()) {
           $this->trace_log("is_ssl() will return false: wp-config fix needed");
           $this->do_wpconfig_loadbalancer_fix = TRUE;
         }
       }

       $this->trace_log("ssl type: ".$this->ssl_type);
     }
     $this->check_for_siteurl_in_wpconfig();


     $this->save_options();
   }

  /**
   * Get the url of this plugin
   *
   * @since  2.0
   *
   * @access public
   *
   */

   public function get_plugin_url(){
     $this->plugin_url = trailingslashit(plugin_dir_url( __FILE__ ));
     //do not force to ssl yet, we need it also in non ssl situations.

     //in some case we get a relative url here, so we check that.
     //we compare to urls replaced to https, in case one of them is still on http.
 	   if (strpos(str_replace("http://","https://",$this->plugin_url),str_replace("http://","https://",home_url()))===FALSE) {
       //make sure we do not have a slash at the start
       $this->plugin_url = ltrim($this->plugin_url,"/");
       $this->plugin_url = trailingslashit(home_url()).$this->plugin_url;
     }

     //for subdomains or domain mapping situations, we have to convert the plugin_url from main site to the subdomain url.
     if (is_multisite() && ( !is_main_site(get_current_blog_id()) ) && (!$this->is_multisite_subfolder_install()) ) {
       $mainsiteurl = str_replace("http://","https://",network_site_url());

       $home = str_replace("http://","https://",home_url());
       $this->plugin_url = str_replace($mainsiteurl,home_url(), $this->plugin_url);

       //return http link if original url is http.
       if (strpos(home_url(), "https://")===FALSE) $this->plugin_url = str_replace("https://","http://",$this->plugin_url);
     }

 	   if ($this->debug) {$this->trace_log("pluginurl: ".$this->plugin_url);}
   }


  /**
  *
  *  @since 2.2
  *  Check if the mixed content fixer is functioning on the front end, by scanning the source of the one of the ssl pages for the fixer comment.
  *
  */

  public function mixed_content_fixer_detected(){
    global $rsssl_url;
    if (!empty($this->ssl_pages)) {
        $ssl_page = reset($this->ssl_pages);
        $url = get_permalink($ssl_page);
        //check if the mixed content fixer is active
        $status = 0;
        $web_source = "";
        //check if the mixed content fixer is active
        $response = wp_remote_get( $url );

        if( is_array($response) ) {
          $status = wp_remote_retrieve_response_code( $response );
          $web_source = wp_remote_retrieve_body($response);
        }

        if ($status!=200 || (strpos($web_source, "data-rsssl=") === false)) {
          $this->trace_log("Check for Mixed Content detection failed, http statuscode ".$status);
          return false;
        } else {
          $this->trace_log("Mixed content fixer was successfully detected on the front end.");
          return true;
        }

    }
    return false;

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

  private function is_subfolder($domain) {

      //remove slashes of the http(s)
      $domain = preg_replace("/(http:\/\/|https:\/\/)/","",$domain);
      if (strpos($domain,"/")!==FALSE) {
        return true;
      }
      return false;
  }

/**
*     Show warning when wpconfig could not be fixed
*
*     @since 2.2
*
*/

public function show_notice_wpconfig_needs_fixes(){ ?>
  <div id="message" class="error fade notice">
  <h1><?php echo __("System detection encountered issues","really-simple-ssl");?></h1>

  <?php if ($this->do_wpconfig_loadbalancer_fix) { ?>
      <p><?php echo __("Your wp-config.php has to be edited, but is not writable.","really-simple-ssl");?></p>
      <p><?php echo __("Because your site is behind a loadbalancer and is_ssl() returns false, you should add the following line of code to your wp-config.php.","really-simple-ssl");?>

    <br><br><code>
        //Begin Really Simple SSL Load balancing fix <br>
        if (isset($_SERVER["HTTP_X_FORWARDED_PROTO"] ) &amp;&amp; "https" == $_SERVER["HTTP_X_FORWARDED_PROTO"] ) {<br>
        &nbsp;&nbsp;$_SERVER["HTTPS"] = "on";<br>
      }<br>
        //END Really Simple SSL
    </code><br>
    </p>
    <p><?php echo __("Or set your wp-config.php to writable and reload this page.", "really-simple-ssl");?></p>
    <?php
  }

  if ($this->no_server_variable) { ?>
      <p><?php echo __('Because your server does not pass a variable with which Wordpress can detect SSL, Wordpress would create redirect loops on SSL.','really-simple-ssl');?></p>
      <p><?php echo __("Consider moving your entire site to SSL with the Really Simple SSL plugin in the Wordpress repository.", "really-simple-ssl");?></p>
<?php } ?>

</div>
<?php
}


  /**
   * Show notices
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function show_notices()
{

  if (isset($this->errors["DEACTIVATE_FILE_NOT_RENAMED"])) {
    ?>
    <div id="message" class="error fade notice is-dismissible rlrsssl-fail">
      <h1>
        <?php _e("Major security issue!","really-simple-ssl");?>
      </h1>
      <p>
    <?php _e("The 'force-deactivate.php' file has to be renamed to .txt. Otherwise your ssl can be deactived by anyone on the internet.","really-simple-ssl");?>
    </p>
    <a href="options-general.php?page=rlrsssl_really_simple_ssl"><?php echo __("Check again","really-simple-ssl");?></a>
    </div>
    <?php
  }

  /*
      SSL success message
  */

  if ($this->ssl_enabled && $this->site_has_ssl && !$this->ssl_success_message_shown) {
        add_action('admin_print_footer_scripts', array($this, 'insert_dismiss_success'));
        ?>
        <div id="message" class="updated fade notice is-dismissible rlrsssl-success">
          <p>
            <?php echo __("SSL activated!","really-simple-ssl");?>
          </p>
        </div>
        <?php
  }

  //some notices for ssl situations
  if ($this->site_has_ssl) {
      if (sizeof($this->plugin_conflict)>0) {
        //pre Woocommerce 2.5
        if (isset($this->plugin_conflict["WOOCOMMERCE_FORCEHTTP"]) && $this->plugin_conflict["WOOCOMMERCE_FORCEHTTP"] && isset($this->plugin_conflict["WOOCOMMERCE_FORCESSL"]) && $this->plugin_conflict["WOOCOMMERCE_FORCESSL"]) {
          ?>
          <div id="message" class="error fade notice"><p>
          <?php _e("Really Simple SSL has a conflict with another plugin.","really-simple-ssl");?><br>
          <?php _e("The force http after leaving checkout in Woocommerce will create a redirect loop.","really-simple-ssl");?><br>
          <a href="admin.php?page=wc-settings&tab=checkout"><?php _e("Show me this setting","really-simple-ssl");?></a>
          </p></div>
          <?php
        }
      }
    }
}

  /**
   * Insert some ajax script to dismis the ssl success message, and stop nagging about it
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function insert_dismiss_success() {
  $ajax_nonce = wp_create_nonce( "really-simple-ssl-dismiss" );
  ?>
  <script type='text/javascript'>
    jQuery(document).ready(function($) {
      $(".rlrsssl-success.notice.is-dismissible").on("click", ".notice-dismiss", function(event){
            var data = {
              'action': 'dismiss_success_message',
              'security': '<?php echo $ajax_nonce; ?>'
            };

            $.post(ajaxurl, data, function(response) {

            });
        });
    });
  </script>
  <?php
}

  /**
   * Process the ajax dismissal of the success message.
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function dismiss_success_message_callback() {
  $this->ssl_success_message_shown = TRUE;
  $this->save_options();
  wp_die();
}


/**
 * Adds the admin options page
 *
 * @since  2.0
 *
 * @access public
 *
 */

public function add_settings_page() {
 if (!current_user_can($this->capability)) return;
 global $rsssl_admin_page;
 $rsssl_admin_page = add_options_page(
   __("SSL settings","really-simple-ssl"), //link title
   __("SSL","really-simple-ssl"), //page title
   $this->capability, //capability
   'rlrsssl_really_simple_ssl', //url
   array($this,'settings_page')); //function

   // Adds my_help_tab when my_admin_page loads
   add_action('load-'.$rsssl_admin_page, array($this,'admin_add_help_tab'));
}

  /**
   * Admin help tab
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function admin_add_help_tab() {
    $screen = get_current_screen();
    // Add my_help_tab if current screen is My Admin Page
    $screen->add_help_tab( array(
        'id'	=> "really-simple-ssl-documentation",
        'title'	=> __("Documentation","really-simple-ssl"),
        'content'	=> '<p>' . __("On <a href='https://www.really-simple-ssl.com'>www.really-simple-ssl.com</a> you can find a lot of articles and documentation about installing this plugin, and installing SSL in general.","really-simple-ssl") . '</p>',
    ) );
}

  /**
   * Create tabs on the settings page
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function admin_tabs( $current = 'homepage' ) {
      $tabs = array(
        'configuration' => __("Configuration","really-simple-ssl"),
        'settings'=>__("Settings","really-simple-ssl"),
        'debug' => __("Debug","really-simple-ssl")
      );

      $tabs = apply_filters("rsssl_tabs", $tabs);

      echo '<h2 class="nav-tab-wrapper">';

      foreach( $tabs as $tab => $name ){
          $class = ( $tab == $current ) ? ' nav-tab-active' : '';
          echo "<a class='nav-tab$class' href='?page=rlrsssl_really_simple_ssl&tab=$tab'>$name</a>";
      }
      echo '</h2>';
  }

  /**
   * Build the settings page
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function settings_page() {
  if (!current_user_can($this->capability)) return;

  if ( isset ( $_GET['tab'] ) ) $this->admin_tabs($_GET['tab']); else $this->admin_tabs('configuration');
  if ( isset ( $_GET['tab'] ) ) $tab = $_GET['tab']; else $tab = 'configuration';

  switch ( $tab ){
      case 'configuration' :
      /*
              First tab, configuration
      */
      ?>
        <h2><?php echo __("Detected setup","really-simple-ssl");?></h2>
        <table class="really-simple-ssl-table">

          <?php if ($this->site_has_ssl) { ?>
          <tr>
            <td><?php echo $this->ssl_enabled ? $this->img("success") : $this->img("error");?></td>
            <td><?php
                  if ($this->ssl_enabled) {
                    _e("SSL is enabled on your site.","really-simple-ssl")."&nbsp;";
                  } else {
                    _e("SSL iss not enabled yet","really-simple-ssl")."&nbsp;";
                  }
                ?>
              </td><td></td>
          </tr>
          <tr>
            <?php

            ?>
            <td><?php echo !empty($this->ssl_pages) ? $this->img("success") : $this->img("error");?></td>
            <td><?php
                  if (empty($this->ssl_pages)) {
                    _e("You do not have any pages with SSL enabled yet. Start adding them on the Add SSL pages tab","really-simple-ssl")."&nbsp;";
                  } else {
                    _e("Great! you already have some pages on SSL","really-simple-ssl")."&nbsp;";
                  }
                ?>
              </td><td></td>
          </tr>
          <?php }
          /* check if the mixed content fixer is working */

          if ($this->ssl_enabled && !empty($this->ssl_pages) && $this->autoreplace_insecure_links && $this->site_has_ssl) { ?>
          <tr>
            <td><?php echo $this->mixed_content_fixer_detected() ? $this->img("success") : $this->img("error");?></td>
            <td><?php
                  if ($this->mixed_content_fixer_detected()) {
                    _e("Mixed content fixer was successfully detected on the front-end","really-simple-ssl")."&nbsp;";
                  } elseif ($this->mixed_content_fixer_status!=0) {
                    _e("The mixed content is activated, but the frontpage could not be loaded for verification. The following error was returned: ","really-simple-ssl")."&nbsp;";
                    echo $this->mixed_content_fixer_status;
                  } else {
                    _e('The mixed content fixer is activated, but was not detected on the frontpage. Please follow these steps to check if the mixed content fixer is working.',"really-simple-ssl").":&nbsp;";
                    echo '&nbsp;<a target="_blank" href="https://www.really-simple-ssl.com/knowledge-base/how-to-check-if-the-mixed-content-fixer-is-active/">';
                    _e('Instructions', 'really-simple-ssl');
                    echo '</a>';
                  }
                ?>
              </td><td></td>
          </tr>
          <?php } ?>
          <tr>
            <td><?php echo ($this->site_has_ssl && $this->wpconfig_ok()) ? $this->img("success") : $this->img("error");?></td>
            <td><?php
                    if ( !$this->wpconfig_ok())  {
                      _e("Failed activating SSL","really-simple-ssl")."&nbsp;";
                    } elseif (!$this->site_has_ssl) {
                      _e("No SSL detected.","really-simple-ssl")."&nbsp;";
                    }
                    else {
                      _e("An SSL certificate was detected on your site. ","really-simple-ssl");
                    }
                ?>
              </td><td></td>
          </tr>
        </table>
        <?php do_action("rsssl_configuration_page");?>
    <?php
      break;
      case 'settings' :
      /*
        Second tab, Settings
      */
    ?>
        <form action="options.php" method="post">
        <?php
            settings_fields('rlrsssl_options');
            do_settings_sections('rlrsssl');
        ?>

        <input class="button button-primary" name="Submit" type="submit" value="<?php echo __("Save","really-simple-ssl"); ?>" />
        </form>
      <?php
        break;

      case 'debug' :
      /*
        third tab: debug
      */
         ?>
    <div>
      <?php
      if ($this->debug) {
        echo "<h2>".__("Log for debugging purposes","really-simple-ssl")."</h2>";
        echo "<p>".__("Send me a copy of these lines if you have any issues. The log will be erased when debug is set to false","really-simple-ssl")."</p>";
        echo "<div class='debug-log'>";
        echo $this->debug_log;
        echo "</div>";
        $this->debug_log.="<br><b>-----------------------</b>";
        $this->save_options();
      }
      else {
        _e("To view results here, enable the debug option in the settings tab.","really-simple-ssl");
      }

       ?>
    </div>
    <?php
    break;
  }
  //possibility to hook into the tabs.
  do_action("show_tab_{$tab}");
     ?>
<?php
}

  /**
   * Returns a succes, error or warning image for the settings page
   *
   * @since  2.0
   *
   * @access public
   *
   * @param string $type the type of image
   *
   * @return html string
   */

public function img($type) {
  if ($type=='success') {
    return "<img class='rsssl-icons' src='".$this->plugin_url."img/check-icon.png' alt='success'>";
  } elseif ($type=="error") {
    return "<img class='rsssl-icons' src='".$this->plugin_url."img/cross-icon.png' alt='error'>";
  } else {
    return "<img class='rsssl-icons' src='".$this->plugin_url."img/warning-icon.png' alt='warning'>";
  }
}


  /**
   * Add some css for the settings page
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function enqueue_assets($hook){
  global $rsssl_admin_page;
  if( $hook != $rsssl_admin_page )
      return;

  wp_register_style( 'rlrsssl-css', $this->plugin_url . 'css/main.css');
  wp_enqueue_style( 'rlrsssl-css');

}

  /**
   * Initialize admin errormessage, settings page
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function setup_admin_page(){
  if (current_user_can($this->capability)) {
    add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

    add_action('admin_init', array($this, 'load_translation'),20);

    global $rssslpp_licensing;
    add_action('show_tab_license', array($rssslpp_licensing, 'add_license_page'));
    add_filter('rsssl_tabs', array($rssslpp_licensing,'add_license_tab'),20,3 );
    add_action('rsssl_configuration_page', array($this, 'configuration_page_more'));

    //settings page, form creation and settings link in the plugins page
    add_action('admin_menu', array($this, 'add_settings_page'),40);

    add_action('admin_init', array($this, 'create_form'),40);

    $plugin = rsssl_pp_plugin;

    add_filter("plugin_action_links_$plugin", array($this,'plugin_settings_link'));

  }
}




public function configuration_page_more(){
  if (!$this->ad) return;
  if (!$this->site_has_ssl) {
    $this->show_pro();
  } else { ?>
    <p><?php _e('Still having issues with mixed content? Try scanning your site with Really Simple SSL Pro. ', "really-simple-ssl")?><a href="<?php echo $this->pro_url?>">Get Pro</a></p>
  <?php }
}

  /**
   * Create the settings page form
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function create_form(){

      register_setting( 'rlrsssl_options', 'rlrsssl_options', array($this,'options_validate') );
      add_settings_section('rlrsssl_settings', __("Settings","really-simple-ssl"), array($this,'section_text'), 'rlrsssl');

      //only show option to enable or disable mixed content and redirect when ssl is detected
      if($this->site_has_ssl) {
        add_settings_field('id_autoreplace_insecure_links', __("Auto replace mixed content","really-simple-ssl"), array($this,'get_option_autoreplace_insecure_links'), 'rlrsssl', 'rlrsssl_settings');
      }

      add_settings_field('id_debug', __("Debug","really-simple-ssl"), array($this,'get_option_debug'), 'rlrsssl', 'rlrsssl_settings');
      add_settings_field('id_exclude_pages', __("Exclude pages from SSL","really-simple-ssl"), array($this,'get_option_exclude_pages'), 'rlrsssl', 'rlrsssl_settings');
      add_settings_field('id_permanent_redirect', __("Redirect permanently","really-simple-ssl"), array($this,'get_option_permanent_redirect'), 'rlrsssl', 'rlrsssl_settings');
      add_settings_field('id_home_ssl', __("Homepage on SSL","really-simple-ssl"), array($this,'get_option_home_ssl'), 'rlrsssl', 'rlrsssl_settings');

    }

  /**
   * Insert some explanation above the form
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function section_text() {

  }

  /**
   * Check the posted values in the settings page for validity
   *
   * @since  2.0
   *
   * @access public
   *
   */

public function options_validate($input) {
  //fill array with current values, so we don't lose any
  $newinput = array();
  $newinput['site_has_ssl']                       = $this->site_has_ssl;

  $newinput['ssl_success_message_shown']          = $this->ssl_success_message_shown;
  $newinput['plugin_db_version']                  = $this->plugin_db_version;
  $newinput['ssl_enabled']                        = $this->ssl_enabled;
  $newinput['ssl_enabled_networkwide']            = $this->ssl_enabled_networkwide;
  $newinput['selected_networkwide_or_per_site']   = $this->selected_networkwide_or_per_site;
  $newinput['ssl_pages']                          = $this->ssl_pages;

  if (!empty($input['autoreplace_insecure_links']) && $input['autoreplace_insecure_links']=='1') {
    $newinput['autoreplace_insecure_links'] = TRUE;
  } else {
    $newinput['autoreplace_insecure_links'] = FALSE;
  }

  if (!empty($input['debug']) && $input['debug']=='1') {
    $newinput['debug'] = TRUE;
  } else {
    $newinput['debug'] = FALSE;
    $this->debug_log = "";
  }
  if (!empty($input['exclude_pages']) && $input['exclude_pages']=='1') {
    $newinput['exclude_pages'] = TRUE;
  } else {
    $newinput['exclude_pages'] = FALSE;
  }
  if (!empty($input['permanent_redirect']) && $input['permanent_redirect']=='1') {
    $newinput['permanent_redirect'] = TRUE;
  } else {
    $newinput['permanent_redirect'] = FALSE;
  }

  if (!empty($input['home_ssl']) && $input['home_ssl']=='1') {
    $newinput['home_ssl'] = TRUE;
  } else {
    $newinput['home_ssl'] = FALSE;
  }

  return $newinput;
}

/**
 * Insert option into settings form
 * deprecated
 * @since  2.0
 *
 * @access public
 *
 */

public function get_option_debug() {
$options = get_option('rlrsssl_options');
echo '<input id="rlrsssl_options" name="rlrsssl_options[debug]" size="40" type="checkbox" value="1"' . checked( 1, $this->debug, false ) ." />";
}

public function get_option_exclude_pages() {
$options = get_option('rlrsssl_options');
echo '<input id="rlrsssl_options" name="rlrsssl_options[exclude_pages]" size="40" type="checkbox" value="1"' . checked( 1, $this->exclude_pages, false ) ." />";
RSSSL()->rsssl_help->get_help_tip(__("If you enable this option, the pages you select in the SSL pages tab will NOT be loaded over https, but over http. They are excluded from SSL.", "really-simple-ssl"));
}

public function get_option_permanent_redirect() {
$options = get_option('rlrsssl_options');
echo '<input id="rlrsssl_options" name="rlrsssl_options[permanent_redirect]" size="40" type="checkbox" value="1"' . checked( 1, $this->permanent_redirect, false ) ." />";
RSSSL()->rsssl_help->get_help_tip(__("For your SEO a 301 permanent redirect is best. It is not turned on by default, as it might make it difficult to switch when you are still configuring.", "really-simple-ssl"));
}

public function get_option_home_ssl() {
$options = get_option('rlrsssl_options');
echo '<input id="rlrsssl_options" name="rlrsssl_options[home_ssl]" size="40" type="checkbox" value="1"' . checked( 1, $this->home_ssl, false ) ." />";
RSSSL()->rsssl_help->get_help_tip(__("The homepage is often a special case, so it's best to define explicitly here if you want the homepage on SSL or not.", "really-simple-ssl"));
}

  /**
   * Insert option into settings form
   *
   * @since  2.1
   *
   * @access public
   *
   */

  public function get_option_autoreplace_insecure_links() {
    $options = get_option('rlrsssl_options');
    echo "<input id='rlrsssl_options' name='rlrsssl_options[autoreplace_insecure_links]' size='40' type='checkbox' value='1'" . checked( 1, $this->autoreplace_insecure_links, false ) ." />";
  }
    /**
     * Add settings link on plugins overview page
     *
     * @since  2.0
     *
     * @access public
     *
     */

  public function plugin_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=rlrsssl_really_simple_ssl">'.__("Settings","really-simple-ssl").'</a>';
    array_unshift($links, $settings_link);
    return $links;
  }

  public function check_plugin_conflicts() {

    //not necessary anymore after woocommerce 2.5
    if (class_exists('WooCommerce') && defined( 'WOOCOMMERCE_VERSION' ) && version_compare( WOOCOMMERCE_VERSION, '2.5', '<' ) ) {
      $woocommerce_force_ssl_checkout = get_option("woocommerce_force_ssl_checkout");
      $woocommerce_unforce_ssl_checkout = get_option("woocommerce_unforce_ssl_checkout");
      if (isset($woocommerce_force_ssl_checkout) && $woocommerce_force_ssl_checkout!="no") {
        $this->plugin_conflict["WOOCOMMERCE_FORCESSL"] = TRUE;
      }

      //setting force ssl in certain pages with woocommerce will result in redirect errors.
      if (isset($woocommerce_unforce_ssl_checkout) && $woocommerce_unforce_ssl_checkout!="no") {
        $this->plugin_conflict["WOOCOMMERCE_FORCEHTTP"] = TRUE;
        if ($this->debug) {$this->trace_log("Force HTTP when leaving the checkout set in woocommerce, disable this setting to prevent redirect loops.");}
      }
    }

  }



/**
 * Get the absolute path the the www directory of this site, where .htaccess lives.
 *
 * @since  2.0
 *
 * @access public
 *
 */

public function getABSPATH(){
 $path = ABSPATH;
 if($this->is_subdirectory_install()){
   $siteUrl = site_url();
   $homeUrl = home_url();
   $diff = str_replace($homeUrl, "", $siteUrl);
   $diff = trim($diff,"/");
     $pos = strrpos($path, $diff);
     if($pos !== false){
       $path = substr_replace($path, "", $pos, strlen($diff));
       $path = trim($path,"/");
       $path = "/".$path."/";
     }
   }
   return $path;
 }

 /**
  * Find if this wordpress installation is installed in a subdirectory
  *
  * @since  2.0
  *
  * @access protected
  *
  */

protected function is_subdirectory_install(){
   if(strlen(site_url()) > strlen(home_url())){
     return true;
   }
   return false;
}




} }//class closure
