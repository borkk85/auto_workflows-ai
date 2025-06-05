<?php    

if(isset($_POST['put_ai_manul_post'])){
    
    $product_category_id    = $_POST['product_category'];
    $brand_id               = $_POST['brand_selection'];
    $brand_term             = get_term($brand_id, 'brands');
    $brand_name             = $brand_term->name;
    $image_url              = $_POST['brand_image_url'];
    $category_hierarchy     = $_POST['category_hierarchy'];
    $manual_link            = $_POST['amazon_url'];
    $org_price              = $_POST['org_amazon_price'];
    $discount_price         = $_POST['disc_amazon_price'];
    $ai_blog_content        = $_POST['manual_post_title'];
    
    // Get the Amazone product base name of the path 
    $post_category = check_if_post_already_exist_in_database($manual_link);
    error_log('Brand ID' . $brand_id);
     $specific_term_id = 79;
     if ($brand_id == $specific_term_id) {
         
        $path = parse_url($manual_link, PHP_URL_PATH);
        $amazone_prod_basename = basename($path);
     } else {
         
    $manual_post_link = $manual_link; 
    }
    // $path = parse_url($manual_link, PHP_URL_PATH);
    // $amazone_prod_basename = basename($path);
   
    if($post_category[0]->slug!='deals'){


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
      $deals_category_id = get_category_by_slug('deals')->term_id;
    

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
                 
        $check=create_manual_blog_using_given_title_create_blog($amazone_prod_basename,$manual_post_link,$blog_post_title,$blog_post_description,$manual_link, $discount_price, $org_price, $product_data, $brand_name, $image_url, $category_hierarchy, $product_category_id);
            
        }else{
            echo '<p style="color:red" target="_blank">This post Already exist</a>';
            
        }
    
}


?>