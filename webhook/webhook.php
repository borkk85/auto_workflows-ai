<?php

//include file
require_once ('../../../../wp-config.php');
include ABSPATH. '/wp-load.php';
//include(WP_PLUGIN_DIR.'/auto_workflows-ai/main_functions.php');
include_once ABSPATH . 'wp-admin/includes/plugin.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ( is_plugin_active( 'auto_workflows-ai/auto_workflow_ai.php' ) ) {


// Takes raw data from the request
 $json = file_get_contents('php://input');

error_log('Received webhook data: ' . $json);
//  $json  = '{
//     "name": "Amosfun Lot de 20 boîtes de rangement pour dents de lait, protection contre le changement de dents pour enfants, collier pour enfants, porte-dents de lait, récipient en plastique pour bébé, fée",
//     "link": "https://www.amazon.se/-/en/dp/B0CPBC9YF3/",
//     "price_data": {
//         "amazon.se": "165.92",
//         "amazon.de": "146.31"
//     },
//     "image_url": "https://m.media-amazon.com/images/I/61E2HNoz2CL._AC_SY300_SX300_.jpg",
//     "category-type": "Baby-Products, Gift-Sets",
// }';

mail('benknackan@gmail.com', 'Full return form webhook trubo', $json);  
 
 /*************************Get content from amazone in json***************************************/
 
  if($json){
    $jsonString = $json;
  
// Decode the JSON string into an associative array
    $data = json_decode($jsonString, true);

   $ai_blog_content=$data['name'];
 
    $link=$data['link'];
    $image_url = $data['image_url'];
    error_log('Received image data: ' . $image_url);
    $categories = $data['category'];
    error_log('Received category data: ' . $categories);
    $price_data = $data['price_data'];


    $path = parse_url($link, PHP_URL_PATH);
    $amazone_prod_basename = basename($path);

// Get the Amazone product base name of the path 
$existing_post_id = check_if_post_exists_by_basename($amazone_prod_basename);
    
if($existing_post_id) {
    // Post exists - process price update
    error_log('Found existing post for ' . $amazone_prod_basename . '. Updating prices.');
    
    $result = process_price_update_api($existing_post_id, $price_data);
    
    // Log the result
    error_log('Price update result: ' . $result);
    
    // Return a success response
    echo json_encode(array(
        'status' => 'success',
        'message' => 'Prices updated',
        'result' => $result
    ));

    update_price_check_timestamp($existing_post_id);
    
    exit;
} else {
    // Post doesn't exist - continue with regular post creation
    $post_category = check_if_post_already_exist_in_database($link);
    
    if($post_category[0]->slug!='active-deals'){


/*********************************Generate conent from AI **************************************/

    //$blog_post_title = translatetext_from_sw_to_eng($blog_title); 
      
      
      $ai_content  = str_replace(utf8_encode('�'), "�r", $ai_blog_content); 
      $ai_content  = str_replace(utf8_encode('�'), "A", $ai_content); 
      $ai_content  = str_replace(utf8_encode('�'), "O", $ai_content);
      $ai_content  = str_replace(utf8_encode('�'), "ae", $ai_content);
      $ai_content  = str_replace(utf8_encode('�'), "a", $ai_content);
      $ai_content  = str_replace(utf8_encode('�'), "o", $ai_content);
      $ai_content  = str_replace(utf8_encode('�'), "�r", $ai_content);
     
      $ai_content  = generate_content_from_AI($ai_content);
      $ai_content  = str_replace('"', " ", $ai_content);
      
 
       
       //$ai_content  = str_replace("(English):", " ", $ai_content);
       
      mail('benknackan@gmail.com', 'Full return form ai', $ai_content);
     
     
        if(str_contains($ai_content, 'Title')){
            
            $ai_content_sp             = explode('Description:',trim($ai_content));
            $blog_post_title           = $ai_content_sp[0];
            $blog_post_title           = str_replace('Title:', '', $blog_post_title);
            $blog_post_description     = $ai_content_sp[1];
            
        }else{
     
            $ai_content             = explode(':',trim($ai_content));
            $title                  = $ai_content[1];
            $blog_post_title        = str_replace('Description', '', $title);
            $blog_post_description  = $ai_content[2];
        }
      
    
   // $blog_post_description = create_description_from_AI($blog_post_title);
  
    //create dynamic blog 
     if($blog_post_title){      
        $check=check_amazone_product_name_exist_and_create_blog($amazone_prod_basename,$blog_post_title,$blog_post_description,$link, $data['price_data'], $categories, $image_url);
     }
        }else{
            echo 'Already exist';
            exit;
        }
    }
}

}

function check_if_post_exists_by_basename($basename) {
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