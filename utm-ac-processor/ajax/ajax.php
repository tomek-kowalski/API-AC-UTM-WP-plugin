<?php


class Add extends Utm {
    public function __construct() {
        add_action('save_post', [$this, 'compare_and_save_on_post_creation'], 10, 3);
    }

    public function compare_and_save_on_post_creation($post_ID, $post, $update) {
        if ($post->post_type === 'apidata' && !$update) {
            $apidata_posts = get_posts(array(
                'post_type'   => 'apidata',
                'posts_per_page' => 1,
                'orderby'     => 'post_date',
                'order'       => 'DESC',
            ));
    
            if (!empty($apidata_posts)) {
                $apidata_post = $apidata_posts[0];
                $closest_utm = null;
                $closest_time_diff = PHP_INT_MAX;
    
                $utm_posts = get_posts(array(
                    'post_type' => 'utm',
                    'posts_per_page' => -1,
                    'orderby' => 'post_date',
                    'order' => 'DESC',
                ));
    
                foreach ($utm_posts as $utm_post) {
                    $apidata_related_post = get_post_meta($utm_post->ID, 'acdata_related_post', true);
    
                    if ($apidata_related_post && $apidata_related_post == $apidata_post->ID) {
                        break;
                    }
    
                    $utm_time = strtotime($utm_post->post_date);
                    $time_diff = abs(strtotime($apidata_post->post_date) - $utm_time);
    
                    if ($time_diff < $closest_time_diff) {
                        $closest_utm = $utm_post;
                        $closest_time_diff = $time_diff;
                    }
                }
    
                if ($closest_utm && $closest_time_diff <= 80) {
                    $acdata_title = $apidata_post->post_title;
                    $acdata_content = $apidata_post->post_content . "\n\n" . $closest_utm->post_content;
    
                    $acdata_args = array(
                        'post_title' => $acdata_title,
                        'post_content' => $acdata_content,
                        'post_type' => 'acdata',
                        'post_status' => 'publish'
                    );
    
                    $acdata_id = wp_insert_post($acdata_args);
    
                    if ($acdata_id) {
                        update_post_meta($acdata_id, 'acdata_related_post', $apidata_post->ID);
    
                        $form_data = array(
                            'acdata_id'   => $acdata_id,
                            'id'          => strip_tags($this->get_acdata_field_value($acdata_content, 'id')),
                            'fullname'    => strip_tags($this->get_acdata_field_value($acdata_content, 'fullname')),
                            'email'       => strip_tags($this->get_acdata_field_value($acdata_content, 'email')),
                            'utmSource'   => $this->get_acdata_field_value($acdata_content, 'utmSource'),
                            'utmMedium'   => $this->get_acdata_field_value($acdata_content, 'utmMedium'),
                            'utmCampaign' => $this->get_acdata_field_value($acdata_content, 'utmCampaign'),
                        );
    
                        // Submit the form data to the AC form
                        $time_gap = time() - strtotime($apidata_post->post_date);
                        if ($time_gap <= 80) {
                            $this->submit_form_to_acform($form_data, $acdata_content);
                        } else {
                            error_log('Time gap is larger than 100 seconds. API requests will be prevented.');
                        }
                    } else {
                        error_log('Failed to merge AC Data');
                    }
                }
            }
        }
    }
    

    private function get_acdata_field_value($content, $field_name) {
        $field_value = '';
    
        if ($field_name === 'id' || $field_name === 'fullname' || $field_name === 'email' || $field_name === 'utmSource' || $field_name === 'utmMedium' || $field_name === 'utmCampaign') {
            $pattern = '/(' . preg_quote($field_name, '/') . '):\s*([^<\n]+)/i';
    
            if (is_array($content)) {
                $content = json_encode($content);
            }
    
            if (preg_match($pattern, $content, $matches)) {
                $field_value = trim($matches[2]);
    
                if ($field_name === 'fullname') {
                    $emailPattern = '/email:\s*([^<\n]+)/i';
                    if (preg_match($emailPattern, $matches[2], $emailMatches)) {
                        $field_value = trim($emailMatches[1]);
                    }
                }
            }
        }

        return $field_value;
    }
    
    public function submit_form_to_acform($form_data, $content) {

        $id = $this->get_acdata_field_value($content, 'id');
        $delete_url = '' . $id;
        $delete_headers = array(

        );
        $delete_ch = curl_init();
        curl_setopt($delete_ch, CURLOPT_URL, $delete_url);
        curl_setopt($delete_ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($delete_ch, CURLOPT_HTTPHEADER, $delete_headers);
        curl_setopt($delete_ch, CURLOPT_RETURNTRANSFER, true);
        $delete_response = curl_exec($delete_ch);
        curl_close($delete_ch);

    
        $fullname = $this->get_acdata_field_value($content, 'fullname');
        $email = $this->get_acdata_field_value($content, 'email');
        $utmSource = $this->get_acdata_field_value($content, 'utmSource');
        $utmMedium = $this->get_acdata_field_value($content, 'utmMedium');
        $utmCampaign = $this->get_acdata_field_value($content, 'utmCampaign');
    
        $result = array(
            'fullname' => $fullname,
            'email' => $email,
            'utmSource' => $utmSource,
            'utmMedium' => $utmMedium,
            'utmCampaign' => $utmCampaign
        );
    
        // Submit the data
        $payload = array(
            'contact' => array(
                'email' => $result['email'],
                'firstName' => $result['fullname'],
                'fieldValues' => array(
                    array(
                        'field' => '4',
                        'value' => $result['utmSource']
                    ),
                    array(
                        'field' => '5',
                        'value' => $result['utmMedium']
                    ),
                    array(
                        'field' => '6',
                        'value' => $result['utmCampaign']
                    )
                )
            )
        );
    
        $json_payload = json_encode($payload);
    
        $headers = array(

        );
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, '');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
        $response = curl_exec($ch);
    
        if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
        }
    
        curl_close($ch);    
   
        return $result;
    }
    
}

$add = new Add();
