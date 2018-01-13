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
    add_action('plugins_loaded', array($this, 'init'), 20, 3);

    add_action( 'admin_notices', array($this, 'bulk_action_admin_notice') );
  }

  static function this() {
    return self::$_this;
  }

  public function init(){

      $args = array(
          'public'   => true,
      );
      $post_types = get_post_types( $args);

      foreach ( $post_types  as $post_type ) {
          add_filter( 'bulk_actions-edit-'.$post_type, array($this, 'register_bulk_actions') );
          add_filter( 'handle_bulk_actions-edit-'.$post_type, array($this, 'bulk_action_handler'), 10, 3 );

          add_filter( 'manage_'.$post_type.'_posts_columns', array($this, 'set_edit_field_columns') );
          add_action( 'manage_'.$post_type.'_posts_custom_column' , array($this,'https_column'), 10, 2 );

      }
  }


  public function register_bulk_actions($bulk_actions) {

      $bulk_actions['rsssl_disable_https_bulk'] = __("Make HTTP","really-simple-ssl-on-specific-pages");
      $bulk_actions['rsssl_enable_https_bulk'] = __("Make HTTPS","really-simple-ssl-on-specific-pages");
      return $bulk_actions;
  }



  public function set_edit_field_columns($columns) {
      $columns['https'] = __( 'HTTPS', 'really-simple-ssl-on-specific-pages' );

      return $columns;
  }

  public function https_column( $column, $post_id ) {
      if ($column!=='https') return;
      global $really_simple_ssl;

      $https = in_array($post_id, $really_simple_ssl->ssl_pages);
      $https =  ($really_simple_ssl->exclude_pages) ? !$https : $https;

      if ($https) {
          $img = __( 'on', 'really-simple-ssl-on-specific-pages' );//'<img class="umc-sync-icon" title="' . __("Datum doorgegeven", "really-simple-ssl-on-specific-pages"). '" src="'.  'assets/img/check-icon.png" >';
      } else {
          $img = '-'; //'<img class="umc-sync-icon" title="' . __("Datum doorgegeven", "really-simple-ssl-on-specific-pages"). '" src="'.  'assets/img/cross-icon.png" >';
      }
      echo $img;
  }


  function bulk_action_handler( $redirect_to, $doaction, $post_ids ) {
      global $really_simple_ssl;
      if ( ($doaction !== 'rsssl_enable_https_bulk') && ($doaction !== 'rsssl_disable_https_bulk') ) {
          return $redirect_to;
      }
      $exclude =  $really_simple_ssl->exclude_pages;
      $enable = ($doaction === 'rsssl_enable_https_bulk') ? true : false;
      $disable = ($doaction === 'rsssl_disable_https_bulk') ? true : false;
      //set pages to https. if exclude_pages_from https is enabled, this means the array should not contain these items

      if (($enable && !$exclude) || ($disable && $exclude)){
          //add these to array
         foreach ( $post_ids as $post_id ) {
             if ( !in_array($post_id, $really_simple_ssl->ssl_pages)) $really_simple_ssl->ssl_pages[] = $post_id;
         }
      }

      if (($enable && $exclude) || ($disable && !$exclude)){
          //remove these from array
          foreach ( $post_ids as $post_id ) {
              if(($key = array_search($post_id, $really_simple_ssl->ssl_pages)) !== false) {
                  unset($really_simple_ssl->ssl_pages[$key]);
              }
          }
      }

      $really_simple_ssl->save_options();

      $redirect_to = add_query_arg( 'change_type', $doaction, $redirect_to );
      $redirect_to = add_query_arg( 'changed_items', count( $post_ids ), $redirect_to );
      return $redirect_to;
  }



      function bulk_action_admin_notice() {
          if ( ! empty( $_REQUEST['changed_items'] ) ) {
              $count = intval( $_REQUEST['changed_items'] );
              $action = intval( $_REQUEST['change_type'] );
              if ($action == 'rsssl_enable_https_bulk') {
                  $string = sprintf(__('Enabled https for %s items','really-simple-ssl-on-specific-pages'), $count);
            } else {
                  $string = sprintf(__('Disabled https for %s items','really-simple-ssl-on-specific-pages'), $count);
              }
              printf( '<div id="message" class="updated fade">' .$string. '</div>', $count );
          }
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

    if (get_the_ID() == (int)get_option( 'page_on_front' ) || get_the_ID() == (int)get_option( 'page_for_posts' )) {
      if (RSSSL()->rsssl_front_end->home_ssl) {
        echo __("This is a homepage, which is set in the settings to be loaded over https.", "really-simple-ssl-on-specific-pages");
      } else {
        echo __("This is a homepage, which is set in the settings to be loaded over http.", "really-simple-ssl-on-specific-pages");
      }
    } else {
      echo '<input type="checkbox" '.$checked.' name="rsssl_page_on_https" value="'.$value.'" />'.$option_label.'<br />';
    }
}

// save data from checkboxes

public function save_option() {

    // check if this isn't an auto save
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
        return;

    // security check
    if (!isset($_POST['rsssl_nonce']) || !wp_verify_nonce( $_POST['rsssl_nonce'], 'rsssl_nonce' ) )
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
