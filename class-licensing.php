<?php
defined('ABSPATH') or die("you do not have acces to this page!");
if (!defined('REALLY_SIMPLE_SSL_URL')) define( 'REALLY_SIMPLE_SSL_URL', 'https://www.really-simple-ssl.com'); // you should use your own CONSTANT name, and be sure to replace it throughout this file
define( 'REALLY_SIMPLE_SSL_PER_PAGE', 'Really Simple SSL per page' ); // you should use your own CONSTANT name, and be sure to replace it throughout this file

if( !class_exists( 'EDD_SL_Plugin_Updater' ) ) {
	// load our custom updater
	include( dirname( __FILE__ ) . '/EDD_SL_Plugin_Updater.php' );
}

if (!class_exists("rssslpp_licensing")) {
class rssslpp_licensing {
private static $_this;

function __construct() {
	if ( isset( self::$_this ) )
			wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

	self::$_this = $this;
	add_action( 'admin_init', array($this, 'plugin_updater'), 0 );
	add_action('admin_init', array($this, 'register_option'));
	add_action('admin_init', array($this, 'deactivate_license'));
	add_action('admin_init', array($this, 'activate_license'));
	add_action('wp_ajax_rsssl_pp_dismiss_license_notice', array($this,'dismiss_license_notice') );
		add_action("admin_notices", array($this, 'show_notice_license'));
}

static function this() {
	return self::$_this;
}


public function show_notice_license(){
add_action('admin_print_footer_scripts', array($this, 'dismiss_license_notice_script'));
 $dismissed	= get_option( 'rsssl_pp_license_notice_dismissed' );
 if (!$this->license_is_valid() && !$dismissed) { ?>
	 <?php if (!is_multisite()) {?>
			<div id="message" class="error fade notice is-dismissible rsssl-pp-dismiss-notice">
		    <p>
		      <?php echo __("You haven't activated your Really Simple SSL per page license yet. To get all future updates, enter your license on the settings page.","really-simple-ssl-pp");?>
					<a href="options-general.php?page=rlrsssl_really_simple_ssl&tab=license"><?php echo __("Go to the settings page","really-simple-ssl-pp");?></a>
					or <a target="blank" href="https://www.really-simple-ssl.com/premium">purchase a license</a>
				</p>
			</div>
		<?php } ?>
<?php
	}
}

/**
 * Process the ajax dismissal of the success message.
 *
 * @since  2.0
 *
 * @access public
 *
 */

public function dismiss_license_notice() {
	check_ajax_referer( 'rsssl-pp-dismiss-license-notice', 'nonce' );
	update_option( 'rsssl_pp_license_notice_dismissed', true);
	wp_die();
}

public function dismiss_license_notice_script() {
  $ajax_nonce = wp_create_nonce( "rsssl-pp-dismiss-license-notice" );
  ?>
  <script type='text/javascript'>
    jQuery(document).ready(function($) {

      $(".rsssl-pp-dismiss-notice.notice.is-dismissible").on("click", ".notice-dismiss", function(event){
            var data = {
              'action': 'rsssl_pp_dismiss_license_notice',
              'nonce': '<?php echo $ajax_nonce; ?>'
            };

            $.post(ajaxurl, data, function(response) {

            });
        });
    });
  </script>
  <?php
}

public function plugin_updater() {
	// retrieve our license key from the DB
	$license_key = trim( get_option( 'rsssl_per_page_license_key' ) );

	// setup the updater
	$edd_updater = new EDD_SL_Plugin_Updater( REALLY_SIMPLE_SSL_URL, dirname(__FILE__)."/really-simple-ssl-on-specific-pages.php", array(
			'version' 	=> rsssl_pp_version, 				// current version number
			'license' 	=> $license_key, 		// license key (used get_option above to retrieve from DB)
			'item_name' => REALLY_SIMPLE_SSL_PER_PAGE, 	// name of this plugin
			'author' 	=> 'Rogier Lankhorst'  // author of this plugin
		)
	);

}

public function add_license_tab($tabs){
	$tabs['license'] = __("License","really-simple-ssl-pp");
	return $tabs;
}

public function add_license_page(){
	$license 	= get_option( 'rsssl_per_page_license_key' );
	$status 	= get_option( 'rsssl_per_page_license_status' );

	?>
		<form method="post" action="options.php">
			<?php wp_nonce_field( 'rsssl_per_page_nonce', 'rsssl_per_page_nonce' ); ?>
			<?php settings_fields('rsssl_per_page_license'); ?>

			<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row" valign="top">
							<?php _e('Really Simple SSL per page license Key'); ?>
						</th>
						<td>
							<input id="rsssl_per_page_license_key" name="rsssl_per_page_license_key" type="text" class="regular-text" value="<?php esc_attr_e( $license ); ?>" />
							<?php if( false !== $license ) { ?>
										<?php if( $status !== false && $status == 'valid' ) { ?>
											<span style="color:green;"><?php _e('active'); ?></span>
											<input type="submit" class="button-secondary" name="rsssl_license_per_page_deactivate" value="<?php _e('Deactivate License'); ?>"/>
										<?php } else {?>
											<span style="color:red;">Click save to activate your license</span>
										<?php } ?>
									</td>
								</tr>
							<?php } else {
								?>
								<label class="description" for="rsssl_per_page_license_key"><?php _e('Enter your license key'); ?></label>
								<?php
							}?>


						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(); ?>

		</form>

	<?php
}

public function register_option() {
	// creates our settings in the options table
	register_setting('rsssl_per_page_license', 'rsssl_per_page_license_key', array($this, 'sanitize_license') );
}

public function sanitize_license( $new ) {
	$old = get_option( 'rsssl_per_page_license_key' );
	if( $old && $old != $new ) {
		delete_option( 'rsssl_per_page_license_status' ); // new license has been entered, so must reactivate
	}
	return $new;
}



/************************************
* this illustrates how to activate
* a license key
*************************************/

public function activate_license() {
	// listen for our activate button to be clicked
	if( isset( $_POST['rsssl_per_page_license_key'] ) && !isset($_POST['rsssl_license_per_page_deactivate'])) {

		// run a quick security check
	 	if( ! check_admin_referer( 'rsssl_per_page_nonce', 'rsssl_per_page_nonce' ) )
			return; // get out if we didn't click the Activate button

		// retrieve the license from the database
		$license = trim( get_option( 'rsssl_per_page_license_key' ) );


		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'activate_license',
			'license' 	=> $license,
			'item_name' => urlencode( REALLY_SIMPLE_SSL_PER_PAGE ), // the name of our product in EDD
			'url'       => home_url()
		);
		// Call the custom API.
		$response = wp_remote_post( REALLY_SIMPLE_SSL_URL, array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// $license_data->license will be either "valid" or "invalid"
		update_option( 'rsssl_per_page_license_status', $license_data->license );

	}
}


/***********************************************
* Illustrates how to deactivate a license key.
* This will descrease the site count
***********************************************/

public function deactivate_license() {

	// listen for our activate button to be clicked
	if( isset( $_POST['rsssl_license_per_page_deactivate'] ) ) {

		// run a quick security check
	 	if( ! check_admin_referer( 'rsssl_per_page_nonce', 'rsssl_per_page_nonce' ) )
			return; // get out if we didn't click the Activate button

		// retrieve the license from the database
		$license = trim( get_option( 'rsssl_per_page_license_key' ) );

		// data to send in our API request
		$api_params = array(
			'edd_action'=> 'deactivate_license',
			'license' 	=> $license,
			'item_name' => urlencode( REALLY_SIMPLE_SSL_PER_PAGE ), // the name of our product in EDD
			'url'       => home_url()
		);

		// Call the custom API.
		$response = wp_remote_post( REALLY_SIMPLE_SSL_URL, array( 'timeout' => 15, 'sslverify' => false, 'body' => $api_params ) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) )
			return false;

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		// $license_data->license will be either "deactivated" or "failed"
		if( $license_data->license == 'deactivated' )
			delete_option( 'rsssl_per_page_license_status' );
			delete_option('rsssl_pp_license_notice_dismissed');

	}
}



public function license_is_valid() {

	global $wp_version;
	$status	= get_option( 'rsssl_per_page_license_status' );
	if ($status && $status=="valid") return true;

	return false;

}
}} //class closure
