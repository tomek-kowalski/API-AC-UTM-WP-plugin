<?php
/**
 * Plugin Name: UTM-AC-Processor
 * Plugin URI: https://kowalski-consulting.com
 * Description: Setting API Endpoint, collecting UTM and adding them to API data retrieved by AC webhook from data sent by Everwebinar form back to AC as a complete data collection.  
 * Version: 1.0.0
 * Author: Tomasz Kowalski
 * Author URI: https://kowalski-consulting.com
 * Text Domain:utm-ac
 * License: GPL2
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}


define('ROOT_DIR', plugin_dir_path(__FILE__));

try {
    require_once(ROOT_DIR . 'ajax/ajax.php');
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}


 class Utm {
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_activecampaign_webhook'], 10);
        add_action('init', [$this, 'acform_cpt'], 10);
        add_action('init', [$this, 'utm_cpt'], 10);
        add_action('init', [$this, 'acdata_cpt'], 10);
        add_action('pre_get_posts', [$this,'hide_post_type_when_logged_out'],10);
        add_action('wp_enqueue_scripts',[$this, 'enqueue_custom_scripts']);
        add_action('wp_ajax_save_utm_data', [$this, 'save_utm_data']);
        add_action('wp_ajax_nopriv_save_utm_data', [$this, 'save_utm_data']);
        add_action('wp_ajax_submit_acdata_form', array($this, 'submit_acdata_form'));
        add_action('wp_ajax_nopriv_submit_acdata_form', array($this, 'submit_acdata_form'));
        add_action('wp_ajax_attach_strings_to_form_fields', array($this, 'attach_strings_to_form_fields'));
        add_action('wp_ajax_nopriv_attach_strings_to_form_fields', array($this, 'attach_strings_to_form_fields'));
        add_action('rest_api_init', function() {
            register_rest_route('custom/v1', '/save_utm_data', array(
              'methods' => 'POST',
              'callback' => 'save_utm_data',
              'permission_callback' => '__return_true',
            ));
          });
    }

    public function register_activecampaign_webhook() {
        register_rest_route(
            'acform/v1',      // Namespace
            'receive-callback', // Endpoint
            array(
                'methods'  => 'POST',
                'callback' => [$this, 'acform_callback'],
                'permission_callback' => '__return_true',
            )
        );
    }

    public function acform_callback($request_data) {
  
        $json_data = $request_data->get_body();
    

        $parameters = json_decode($json_data, true);

        $decoded_data = [];
        $decoded_params = explode('&', $json_data);
        foreach ($decoded_params as $param) {
            $param_parts = explode('=', $param);
            $key = urldecode($param_parts[0]);
            $value = urldecode($param_parts[1]);
            $decoded_data[$key] = $value;
        }

        $id    = $decoded_data['contact[id]'];
        $name  = $decoded_data['contact[first_name]'];
        $email = $decoded_data['contact[email]'];
        

        $data = array(
            'status' => 'OK',
            'received_data' => array(
                'id'          => $id,
                'first_name'  => $name,
                'email'       => $email,
            ),
            'message' => 'You have reached the server',
        );

        $post_args = array(
            'post_title'   => wp_strip_all_tags($id),
            'post_content' => 'id: ' . wp_strip_all_tags($id) . '<br>fullname: ' . $name . '<br>email: ' . $email,
            'post_status'  => 'publish',
            'post_type'    => 'apidata',
        );

        if (isset($parameters['callback'])) {
            $callback = $parameters['callback'];
            $callbackUrl = $callback['url'];
            $requestType = $callback['requestType'];
            $detailedResults = $callback['detailed_results'];
            $params = $callback['params'];
            $headers = $callback['headers'];

            $callbackData = array(
            'id'         => $id,
            'first_name' => $name,
            'email'      => $email,
            );

            $callbackParams = array();
            foreach ($params as $param) {
            $key = $param['key'];
            $value = $param['value'];
            $callbackParams[$key] = $value;
            }

            $callbackHeaders = array();
            foreach ($headers as $header) {
                $key = $header['key'];
                $value = $header['value'];
                $callbackHeaders[$key] = $value;
            }

            $response = wp_remote_post($callbackUrl, array(
                'method'  => $requestType,
                'body'    => $callbackData,
                'headers' => $callbackHeaders,
                'timeout' => 30,
            ));
        }

        $post_var = wp_insert_post($post_args);
        

        if($post_var) {
            $data['message'] = ' Post was successful';
        }
    
    
        return $data;
    }

    public function acform_cpt() {

        $args = array(
            'public'             =>true,
            'publicly_queryable' =>true,
            'label'              =>__('API Data','utm-ac'),
            'menu_icon'          =>'dashicons-analytics',
            'supports'           => array('title','editor'),
            'show_in_rest'       => true,
            'rest_base'          => 'apidata'
        );

        register_post_type('apidata',$args);

    }

    public function utm_cpt() {

        $args = array(
            'public'             =>true,
            'publicly_queryable' =>true,
            'label'              =>__('UTM','utm-ac'),
            'menu_icon'          =>'dashicons-image-filter',
            'supports'           => array('title','editor'),
            'show_in_rest'       => true,
            'rest_base'          => 'utms'
        );

        register_post_type('utm',$args);

    }

    public function acdata_cpt() {

        $args = array(
            'public'             => true,
            'publicly_queryable' => true,
            'label'              =>__('ACdata','utm-ac'),
            'menu_icon'          => 'dashicons-database-export',
            'supports'           => array('title','editor'),
            'show_in_rest'       => true,
            'rest_base'          => 'acdata'
        );

        register_post_type('acdata',$args);

    }
    
    function enqueue_custom_scripts() {
        global $post;
        $excluded_post_types = array('acdata','apidata');
        if(!is_admin() || !is_page("acform")  || ($post && !(in_array($post->post_type, $excluded_post_types)))) {
        wp_enqueue_script('auto', get_stylesheet_directory_uri() . '/assets/js/auto.js', array('jquery'), null, true);
        }

        wp_localize_script('auto', 'ajaxParams', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }
    
    public function save_utm_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_send_json_error('Invalid nonce');
        }
    
        $title = sanitize_text_field($_POST['title']);
        $content = sanitize_text_field($_POST['content']);
    
        $content = str_replace(['{', '}', '„', '”'], '', $content);
    
        $pairs = explode(',', $content);
    
        $acdata_content = '';
        foreach ($pairs as $pair) {
            list($key, $value) = explode(':', $pair);
            $key = trim(str_replace(['"', '”'],'',$key));
            $value = trim(str_replace(['"', '”'],'',$value));
            $acdata_content .= $key . ': ' . $value . "\n";
        }
    
        $args = array(
            'post_title'   => $title,
            'post_content' => $acdata_content,
            'post_type'    => 'utm',
            'post_status'  => 'publish'
        );
    
        $post_id = wp_insert_post($args);
    
        if ($post_id) {
            wp_send_json_success('UTM data saved successfully', $post_id);
        } else {
            wp_send_json_error('Error saving UTM data');
        }
    }

 
    public function hide_post_type_when_logged_out($query) {
        if (!is_user_logged_in() && $query->is_main_query()) {
            $post_type = $query->get('post_type');
            if ($post_type === 'apidata') {
                $query->set('post_type', ''); 
            }
            if ($post_type === 'utm') {
                $query->set('post_type', ''); 
            }
            if ($post_type === 'acdata') {
                $query->set('post_type', ''); 
            }
        }
    }
}

$utm = new Utm();



 