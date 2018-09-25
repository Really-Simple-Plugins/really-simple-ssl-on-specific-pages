<?php
defined('ABSPATH') or die("you do not have acces to this page!");
if (!class_exists('rsssl_page_option')) {
    class rsssl_page_option
    {

        private static $_this;

        function __construct()
        {
            if (isset(self::$_this))
                wp_die(sprintf(__('%s is a singleton class and you cannot create a second instance.', 'really-simple-ssl'), get_class($this)));

            self::$_this = $this;
            // register the meta box
            add_action('add_meta_boxes', array($this, 'register_https_option'));
            add_action('save_post', array($this, 'save_option'));
            add_action('wp_loaded', array($this, 'init'), 20, 3);

            add_action('plugins_loaded', array($this, 'switch_page'));

            add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        }

        static function this()
        {
            return self::$_this;
        }

        public function init()
        {

            $args = array(
                'public' => true,
            );
            $post_types = get_post_types($args);

            foreach ($post_types as $post_type) {
                add_filter('bulk_actions-edit-' . $post_type, array($this, 'register_bulk_actions'));
                add_filter('handle_bulk_actions-edit-' . $post_type, array($this, 'bulk_action_handler'), 10, 3);

                add_filter('manage_' . $post_type . '_posts_columns', array($this, 'set_edit_field_columns'));
                add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'https_column'), 10, 2);

            }
        }


        public function register_bulk_actions($bulk_actions)
        {

            $bulk_actions['rsssl_disable_https_bulk'] = __("Make HTTP", "really-simple-ssl-on-specific-pages");
            $bulk_actions['rsssl_enable_https_bulk'] = __("Make HTTPS", "really-simple-ssl-on-specific-pages");
            return $bulk_actions;
        }


        public function set_edit_field_columns($columns)
        {
            $columns['https'] = __('HTTPS', 'really-simple-ssl-on-specific-pages');

            return $columns;
        }

        public function https_column($column, $post_id)
        {
            if ($column !== 'https') return;
            $https = RSSSL()->rsssl_front_end->is_ssl_page($post_id);

            $switch = $https ? 0 : 1;
            $nonce = wp_create_nonce('rsssl_nonce');
            $post_type = isset($_GET['post_type']) ? sanitize_title($_GET['post_type']) : false;
            $args = array(
                'post_type' => $post_type,
                'rsssl_nonce' => $nonce,
                'rsssl_switch_post_id' => $post_id,
                'enable_https' => $switch
            );


            $url = add_query_arg($args, admin_url('edit.php'));

            if ($https) {
                $img = '<a href="'.$url.'"><img class="rsssl-icon rsssl-https-icon" title="' . __("This page is currently on HTTPS. Click to switch to HTTP", "really-simple-ssl-on-specific-pages") . '" src="' . rsssl_pp_url . 'img/https.png" ></a>';
            } else {
                $img = '<a href="'.$url.'"><img class="rsssl-icon rsssl-http-icon" title="' . __("This page is currently on HTTP. Click to switch to HTTPS", "really-simple-ssl-on-specific-pages") . '" src="' . rsssl_pp_url . 'img/http.png" ></a>';
            }

            echo $img;
        }


        function bulk_action_handler($redirect_to, $doaction, $post_ids)
        {
            global $really_simple_ssl;
            if (($doaction !== 'rsssl_enable_https_bulk') && ($doaction !== 'rsssl_disable_https_bulk')) {
                return $redirect_to;
            }

            $exclude = $really_simple_ssl->exclude_pages;
            $enable = ($doaction === 'rsssl_enable_https_bulk') ? true : false;
            $disable = ($doaction === 'rsssl_disable_https_bulk') ? true : false;

            //set pages to https. if exclude_pages_from https is enabled, this means the array should not contain these items
            if (($enable && !$exclude) || ($disable && $exclude)) {
                //add these to array
                foreach ($post_ids as $post_id) {
                    //handle frontpage differently
                    if (!RSSSL()->rsssl_front_end->is_home($post_id)) {
                        update_post_meta($post_id, "rsssl_ssl_page", true);
                    }

                }
            }

            //set pages to http
            if (($enable && $exclude) || ($disable && !$exclude)) {
                //remove these
                foreach ($post_ids as $post_id) {
                    //handle frontpage differently
                    if (!RSSSL()->rsssl_front_end->is_home($post_id)) {
                        update_post_meta($post_id, "rsssl_ssl_page", false);
                    }
                }
            }

            //handle frontpage differently
            foreach ($post_ids as $post_id) {
                if (RSSSL()->rsssl_front_end->is_home($post_id)) {
                    $options = get_option('rlrsssl_options');
                    if ($enable) {
                        $options['home_ssl'] = true;
                    }

                    if ($disable) {
                        $options['home_ssl'] = false;
                    }

                    update_option('rlrsssl_options', $options);
                }
            }

            $redirect_to = add_query_arg('change_type', $doaction, $redirect_to);
            $redirect_to = add_query_arg('changed_items', count($post_ids), $redirect_to);
            return $redirect_to;
        }


        function bulk_action_admin_notice()
        {
            if (!empty($_REQUEST['changed_items'])) {
                $count = intval($_REQUEST['changed_items']);
                $action = $_REQUEST['change_type'];

                if ($action == 'rsssl_enable_https_bulk') {
                    $string = sprintf(__('Enabled https for %s items', 'really-simple-ssl-on-specific-pages'), $count);
                } else {
                    $string = sprintf(__('Disabled https for %s items', 'really-simple-ssl-on-specific-pages'), $count);
                }
                printf('<div id="message" class="rsssl-bulk-message updated fade">' . $string . '</div>', $count);
            }
        }

        public function register_https_option()
        {
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
        public function option_html($post_id)
        {

            wp_nonce_field('rsssl_nonce', 'rsssl_nonce');


            $value = 0;
            $checked = "";
            global $really_simple_ssl;
            $really_simple_ssl->get_admin_options();

            global $post;
            $current_page_id = $post->ID;
            $option_label = __("This page on https", "really-simple-ssl-pro");

            if (RSSSL()->rsssl_front_end->is_ssl_page($current_page_id)) {
                $value = 1;
                $checked = "checked";
            }


            echo '<input type="checkbox" ' . $checked . ' name="rsssl_page_on_https" value="' . $value . '" />' . $option_label . '<br />';

        }

// save data from checkboxes

        public function save_option()
        {
            global $really_simple_ssl;
            // check if this isn't an auto save
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
                return;

            // security check
            if (!isset($_POST['rsssl_nonce']) || !wp_verify_nonce($_POST['rsssl_nonce'], 'rsssl_nonce'))
                return;

            global $post;
            $current_page_id = $post->ID;

            $enable_https = isset($_POST['rsssl_page_on_https']) ? true : false;

            if (!RSSSL()->rsssl_front_end->is_home($current_page_id) && $really_simple_ssl->exclude_pages) {
                $enable_https = !$enable_https;
            }

            if (RSSSL()->rsssl_front_end->is_home($current_page_id)) {
                $options = get_option('rlrsssl_options');
                $options['home_ssl'] = $enable_https;
                update_option('rlrsssl_options', $options);
            }

            // now store data in custom fields based on checkboxes selected
            update_post_meta($current_page_id, "rsssl_ssl_page", $enable_https);

        }


        public function switch_page()
        {
            global $really_simple_ssl;
            // security check
            if (!isset($_GET['rsssl_nonce']) || !wp_verify_nonce($_GET['rsssl_nonce'], 'rsssl_nonce'))
                return;
            if (!isset($_GET['rsssl_switch_post_id'])) return;
            if (!isset($_GET['enable_https'])) return;
            $current_page_id = intval($_GET['rsssl_switch_post_id']);

            $enable_https = (intval($_GET['enable_https'])==1) ? true : false;

            $post_type = isset($_GET['post_type']) ? sanitize_title($_GET['post_type']) : false;

            if (!RSSSL()->rsssl_front_end->is_home($current_page_id) && $really_simple_ssl->exclude_pages) {
                $enable_https = !$enable_https;
            }

            if (RSSSL()->rsssl_front_end->is_home($current_page_id)) {
                $options = get_option('rlrsssl_options');
                $options['home_ssl'] = $enable_https;
                update_option('rlrsssl_options', $options);
            }

            // now store data in custom fields based on checkboxes selected
            update_post_meta($current_page_id, "rsssl_ssl_page", $enable_https);

            $args = array(
                'post_type' => $post_type,
            );

             wp_redirect(add_query_arg($args, admin_url('edit.php')));
             exit;



        }
    }//class closure
}
