<?php

$filter_vars;

  /**
    * Initialization. Add our script if needed on this page.
  */

  add_action('wp_ajax_rsssl_submit_form', 'rsssl_submit_form');

  add_action('admin_enqueue_scripts', 'init_ajax_filter');
  add_action('admin_print_footer_scripts', 'insert_ajax');


function init_ajax_filter($hook) {
  global $filter_vars;
  global $rsssl_admin_page;
  if( $hook != $rsssl_admin_page )
      return;

  $filter_vars = array(
    "post_type"     => 'post',
    "add_ssl_page"  => -1,
  );

 	wp_enqueue_script('ajax-form-loader',plugin_dir_url( __FILE__ ) . 'js/ajax-filter.js',array('jquery'),'1.0',false	);
 	// Add some parameters for the JS.
 	wp_localize_script('ajax-form-loader','filter_vars', $filter_vars);
 }


 function rsssl_submit_form() {
    check_ajax_referer( 'rsssl_nonce', 'rsssl_nonce' );
    global $filter_vars;
    global $really_simple_ssl;

    $really_simple_ssl->get_admin_options();
    $filter_vars = json_decode(stripslashes($_POST["filter_vars"]));
    if ($filter_vars->add_ssl_page!=-1 && !in_array($filter_vars->add_ssl_page,$really_simple_ssl->ssl_pages)) $really_simple_ssl->ssl_pages[] = $filter_vars->add_ssl_page;
    if (property_exists($filter_vars, 'remove_ssl_page')) {
      if(($key = array_search($filter_vars->remove_ssl_page, $really_simple_ssl->ssl_pages)) !== false) {
        unset($really_simple_ssl->ssl_pages[$key]);
      }
    }



    $really_simple_ssl->save_options();
    $pagelist = "";

    foreach($really_simple_ssl->ssl_pages as $page_id) {
      $pagelist .= '<tr><td width="20px"><img class="rsssl_remove_ssl_page icons" id="'.$page_id.'" src="'.$really_simple_ssl->plugin_url.'img/delete-icon.png" alt="delete"></td><td width="*">'.get_the_title($page_id).'</td></tr>';
    }
    $pagelist = '<table class="wp-list-table widefat fixed striped">'.$pagelist."</table>";
    $dropdown = "";
    $args = array(
      'post_type' => $filter_vars->post_type,
      'posts_per_page' => -1,
    );

    $the_query = new WP_Query( $args );

    if ( $the_query->have_posts() ) {
      while ( $the_query->have_posts() ) {
        $the_query->the_post();
        $dropdown .= '<option value="'.get_the_ID().'">'.get_the_title();
       }
       $dropdown = '<select name="rsssl_selectedpage" id="rsssl_selectedpage"><option value=-1>Select a page</option>'.$dropdown."</select>";
    } else {
      $dropdown = "No pages found";
    }

    wp_reset_postdata();
    $output = array('dropdown' => $dropdown,'pagelist' => $pagelist);
    //now, create json object
    $obj = new stdClass();
    $obj = $output;
    echo json_encode($obj);
    wp_die();
 }


function insert_ajax() {

  $screen = get_current_screen();
  if ($screen->base != "settings_page_rlrsssl_really_simple_ssl") return;

  $ajax_nonce = wp_create_nonce( "rsssl_nonce" );
  ?>
  <script type='text/javascript'>
    jQuery(document).ready(function($) {
      $("#rsssl-posttype").change(function(e){
        filter_vars["post_type"] = this.value;
        submit_form(filter_vars);
      });

      $("#rsssl-posttype").change(function(e){
        $('#rsssl_selectedpage').prop('disabled', true);
      });

      $("#rsssl_add_ssl_page").click(function(){
        filter_vars["add_ssl_page"] = parseInt($("#rsssl_selectedpage").val());
        submit_form(filter_vars);
        filter_vars["add_ssl_page"] =-1;
      });

      $('#rsssl-pagelist').on("click", ".rsssl_remove_ssl_page", function (e) {
        filter_vars["remove_ssl_page"] = $(this).attr('id');
        submit_form(filter_vars);
        filter_vars["remove_ssl_page"]=-1;
      });

      submit_form(filter_vars);
      function submit_form(new_filter) {
        new_filter = JSON.stringify(new_filter);
        var data = {
          'action': 'rsssl_submit_form',
          'filter_vars': new_filter,
          'rsssl_nonce': '<?php echo $ajax_nonce; ?>',
        };
        $.post(ajaxurl, data, function(response) {
            var obj;
            if (!response) {
              output = "Scan not completed, please try again";
            } else {
             obj = jQuery.parseJSON( response );
             $('#rsssl-posts').html(obj['dropdown']);
             //$('#rsssl_selectedpage').prop('disabled', false);
             $('#rsssl-pagelist').fadeIn('slow').html(obj['pagelist']);
            }
        });
     }
   });

   </script>
   <?php
}
