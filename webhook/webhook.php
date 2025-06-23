<?php

//include file
// Define the absolute path to WordPress
define('WP_USE_THEMES', false);
require_once dirname(__FILE__, 5) . '/wp-config.php';
require_once dirname(__FILE__, 5) . '/wp-load.php';

// Include necessary WordPress functions
require_once ABSPATH . 'wp-admin/includes/plugin.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ( is_plugin_active( 'auto_workflows-ai/auto_workflow_ai.php' ) ) {

// Takes raw data from the request
$json = file_get_contents('php://input');

if (empty($json)) {
    error_log('Webhook received but no data found.');
    http_response_code(400); // Bad Request
    exit;
}
error_log('Received webhook data: ' . $json);

mail('benknackan@gmail.com', 'Full return form webhook trubo', $json);  
 
/*************************Get content from amazone in json***************************************/
 
if($json){
    $jsonString = $json;
  
    // Decode the JSON string into an associative array
    $data = json_decode($jsonString, true);

    $ai_blog_content = $data['name'];
    $link = $data['link'];
    $image_url = $data['image_url'];
    $categories = $data['category'];
    $path = parse_url($link, PHP_URL_PATH);
    $amazone_prod_basename = basename($path);

    // Get the Amazon product base name of the path 
    $post_category = check_if_post_already_exist_in_database($link);
    
    // FIXED: Check if array is empty OR if not in active-deals
    if(empty($post_category) || $post_category[0]->slug != 'active-deals'){

        /*********************************Generate content from AI **************************************/

        $ai_content  = str_replace(utf8_encode('å'), "är", $ai_blog_content); 
        $ai_content  = str_replace(utf8_encode('Å'), "A", $ai_content); 
        $ai_content  = str_replace(utf8_encode('Ö'), "O", $ai_content);
        $ai_content  = str_replace(utf8_encode('ä'), "ae", $ai_content);
        $ai_content  = str_replace(utf8_encode('à'), "a", $ai_content);
        $ai_content  = str_replace(utf8_encode('ö'), "o", $ai_content);
        $ai_content  = str_replace(utf8_encode('ü'), "är", $ai_content);
       
        $ai_content = generate_content_from_AI($ai_content);
        $ai_content = str_replace('"', " ", $ai_content);
       
        mail('benknackan@gmail.com', 'Full return form ai', $ai_content);
       
        // Safe array parsing 
        if(str_contains($ai_content, 'Title')){
            $ai_content_sp = explode('Description:', trim($ai_content));
            if (count($ai_content_sp) >= 2) {
                $blog_post_title = $ai_content_sp[0];
                $blog_post_title = str_replace('Title:', '', $blog_post_title);
                $blog_post_description = $ai_content_sp[1];
            } else {
                $blog_post_title = $ai_blog_content;
                $blog_post_description = "Product description for: " . $ai_blog_content;
            }
        } else {
            $ai_content_parts = explode(':', trim($ai_content));
            if (count($ai_content_parts) >= 3) {
                $title = $ai_content_parts[1];
                $blog_post_title = str_replace('Description', '', $title);
                $blog_post_description = $ai_content_parts[2];
            } else {
                $blog_post_title = $ai_blog_content;
                $blog_post_description = "Product description for: " . $ai_blog_content;
            }
        }
      
        // Create dynamic blog 
        if($blog_post_title){      
            $check = check_amazone_product_name_exist_and_create_blog(
                $amazone_prod_basename,
                $blog_post_title,
                $blog_post_description,
                $link, 
                $data['price_data'], 
                $categories, 
                $image_url
            );
        }
    } else {
        echo 'Already exist';
        exit;
    }
}

}

function check_if_post_exists_by_basename($basename)
{
    global $wpdb;

    $query = $wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_Amazone_produt_baseName' AND meta_value = %s LIMIT 1",
        $basename
    );

    $result = $wpdb->get_var($query);

    if ($result) {
        return intval($result);
    }

    return false;
}
