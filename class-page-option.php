<?php
defined('ABSPATH') or die("you do not have acces to this page!");
if (!class_exists('rsssl_page_option')) {
  class rsssl_page_option  {

  private static $_this;

  function __construct() {
    if ( isset( self::$_this ) )
        wp_die( sprintf( __( '%s is a singleton class and you cannot create a second instance.','really-simple-ssl' ), get_class( $this ) ) );

    self::$_this = $this;
    // register the meta box
    add_action( 'add_meta_boxes', array($this, 'register_https_option') );
    add_action( 'save_post', array($this, 'save_option' ));

  }

  static function this() {
    return self::$_this;
  }


public function register_https_option() {
    add_meta_box(
        'rsssl',          // this is HTML id of the box on edit screen
        __('SSL settings', "really-simple-ssl-pro"),    // title of the box
        array($this, 'option_html'),   // function to be called to display the checkboxes, see the function below
        null,//'post',        // on which edit screen the box should appear
        'side',      // part of page where the box should appear
        'default'      // priority of the box
    );
}

// display the metabox
public function option_html( $post_id ) {

    wp_nonce_field( 'rsssl_nonce', 'rsssl_nonce' );


    $value = 0;
    $checked="";
    global $really_simple_ssl;
    $really_simple_ssl->get_admin_options();

    global $post;
    $current_page_id = $post->ID;

    if ($really_simple_ssl->exclude_pages) {
      $option_label = __("Exclude this page from https","really-simple-ssl-pro");
    } else {
      $option_label = __("This page on https","really-simple-ssl-pro");
    }


    if (in_array($current_page_id, $really_simple_ssl->ssl_pages)) {
      $value = 1;
      $checked = "checked";
    }
    echo '<input type="checkbox" '.$checked.' name="rsssl_page_on_https" value="'.$value.'" />'.$option_label.'<br />';
}

// save data from checkboxes

public function save_option() {

    // check if this isn't an auto save
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        return;

    // security check
    if ( !wp_verify_nonce( $_POST['rsssl_nonce'], 'rsssl_nonce' ) )
        return;

    global $really_simple_ssl;
    $really_simple_ssl->get_admin_options();
    global $post;
    $current_page_id = $post->ID;


    // now store data in custom fields based on checkboxes selected
    if ( isset( $_POST['rsssl_page_on_https'] ) ) {
      if ( !in_array($current_page_id, $really_simple_ssl->ssl_pages)) $really_simple_ssl->ssl_pages[] = $current_page_id;
    } else {
      if(($key = array_search($current_page_id, $really_simple_ssl->ssl_pages)) !== false) {
        unset($really_simple_ssl->ssl_pages[$key]);
      }
    }

    $really_simple_ssl->save_options();

  }
}//class closure
}
