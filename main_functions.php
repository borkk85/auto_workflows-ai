<?php    
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';



function get_amazon_converted_Id($encode_base) {
    global $wpdb;
    $sql = "SELECT meta_key FROM `{$wpdb->postmeta}` WHERE `meta_value` LIKE %s";
    $prepared_sql = $wpdb->prepare($sql, '%' . $wpdb->esc_like($encode_base) . '%');
    $result = $wpdb->get_row($prepared_sql);

    if (null !== $result) {
        return $result->meta_key;
    } else {

        error_log('No matching meta_key found for encoded base: ' . $encode_base);
        return null;
    }
}

function image_exists($image_url, $post_id) {
    $args = array(
        'post_type' => 'attachment',
        'post_parent' => $post_id,
        'meta_query' => array(
            array(
                'key' => '_wp_attached_file',
                'value' => basename($image_url),
                'compare' => 'LIKE'
            )
        )
    );
    $existing_images = get_posts($args);
    return !empty($existing_images);
}
/****************** Category creation logic *********************************/

function create_product_categories($categories) {
    if (empty($categories)) {
        return false;
    }

    $categories = html_entity_decode($categories, ENT_QUOTES, 'UTF-8');
    $category_hierarchy = array_filter(array_map('trim', explode('›', $categories)));
    $parent_id = 0;
    $last_term_id = 0;

    foreach ($category_hierarchy as $category_name) {
        if (empty($category_name)) continue;

        $category_slug = sanitize_title($category_name);
        $existing_term = term_exists($category_slug, 'product_categories', $parent_id);

        if (!$existing_term) {
            $new_term = wp_insert_term($category_name, 'product_categories', array(
                'slug' => $category_slug,
                'parent' => $parent_id
            ));

            if (is_wp_error($new_term)) {
                error_log('Error creating product category: ' . $new_term->get_error_message());
                continue; 
            }

            $last_term_id = $new_term['term_id'];
        } else {
            $last_term_id = $existing_term['term_id'];
        }

        $parent_id = $last_term_id;
    }

    return $last_term_id;
}



/****************check Amazone product baseName from meta to remove reapeated blog***************************/   
     
function check_if_post_already_exist_in_database($link){
    
    $path = parse_url($link, PHP_URL_PATH);
    $amazone_prod_basename = basename($path);

    global $wpdb;

     $results = $wpdb->get_results(
     $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE meta_key = '_Amazone_produt_baseName' AND meta_value = %s",$amazone_prod_basename));

     if($results){
            foreach($results as $results_post){
              $post_id =   $results_post->post_id;
            }
     }
  return $post_category = get_the_category($post_id);
    
}

//************** en crypt ***************
function en_crypt_uniquie($simple_string){
    
 $encoded = str_rot13($simple_string);
 return  $encoded ."\n";
    
}


function en_derypt_uniquie($decryption){
    

$decryption_iv = '1234567891011121';
  
// Store the decryption key
$decryption_key = "GeeksforGeeks";
  
// Use openssl_decrypt() function to decrypt the data
$decryption=openssl_decrypt ($encryption, $ciphering, $decryption_key, $options, $decryption_iv);
  
// Display the decrypted string
return  $decryption;
    
}


function translate_content_with_google($content, $target_language = 'sv') {
    $api_key = 'AIzaSyCs4UkBK43F8sLBS40eTnhkqMvZKIc5EVU';
    $url = 'https://translation.googleapis.com/language/translate/v2';
    $args = array(
        'body' => json_encode(array(
            'q' => $content,
            'target' => $target_language,
            'format' => 'text'
        )),
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'method' => 'POST',
        'data_format' => 'body',
    );

    $response = wp_remote_post($url, $args);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['data']['translations'][0]['translatedText'])) {
        return $data['data']['translations'][0]['translatedText'];
    }

    return false;
}


function create_manual_blog_using_given_title_create_blog($amazone_prod_basename, $blog_post_titles, $blog_post_descriptions, $manual_link, $discount_price, $org_price, $product_data, $brand_name, $image_url, $category_hierarchy, $product_category_id) {
    // Existing code

    $prime_block_link = get_option('prime_block_link');
    $prime_block_area = get_option('prime_block_area');
    $sek_fixed_rate = get_option('sek_fixed_rate');
    
    // Initialize variables to store price sources
    $price_sources = array(
        'discount_price_source' => 'manual', // Default source for manual entries
        'original_price_source' => 'manual'  // Default source for manual entries
    );
    
    // Process discount and original prices
    $discount_price = round($discount_price);
    $original_price = round($org_price);
    
    // Generate links
    if (isset($amazone_prod_basename) && !empty($amazone_prod_basename)) {
        $encoded = en_crypt_uniquie($amazone_prod_basename); 
        $dynamic_url = site_url() . '/?sn=' . $encoded;
        $dynamic_esc_url = esc_url($dynamic_url);
        $link_to_use = $dynamic_esc_url;
    } else {
        $esc_link = esc_url($manual_link);
        $link_to_use = $esc_link;
    }
    
    // Create HTML blocks
    $price_block = '<div class="price_block"><a target="_blank" href="'. $link_to_use .'" rel="nofollow sponsored noopener"><span class="original-price">'.$original_price.' SEK </span> <span class="discount-price">'.$discount_price.' SEK</span></a></div>';
    $prime_block = '<p><a class="prime_block" href="'.site_url().'/?dm='.$link_to_use.'"  rel="nofollow sponsored">'.$prime_block_area.'</a></p>';
    $commesion_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';
    
    // Date block
    date_default_timezone_set("Europe/Stockholm"); 
    $date_block = '<p> **Price last checked '.date('Y-m-d H:i'). ' CET </p>';

    $amazon_link_block = '<div class="product-link_wrap" data-href="'. $link_to_use .'" target="_blank" rel="nofollow sponsored"><div class="button-block"><div class="button-image"><img src="https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png" alt="Product Image" class="webpexpress-processed"></div><div class="product-title"><p class="product-name">'. esc_html($blog_post_titles).'</p></div><div class="prices-container"><span class="original-price">'. esc_html($original_price) .' SEK</span><span class="discount-price">' . esc_html($discount_price) .' SEK</span></div><div class="button-container"><button class="product-button"><span>Go to Product</span><svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24"><path d="M15.5,11.3L9.9,5.6c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l4.9,4.9l-4.9,4.9c-0.2,0.2-0.3,0.4-0.3,0.7c0,0.6,0.4,1,1,1c0.3,0,0.5-0.1,0.7-0.3l5.7-5.7c0,0,0,0,0,0C15.9,12.3,15.9,11.7,15.5,11.3z"></path></svg></button></div></div></div>';
    
    // Create blog post
    $blog_post = array(
        'post_title' => $blog_post_titles,
        'post_content' => $blog_post_descriptions . "\n\n" . 
              $amazon_link_block . "\n\n" . 
              $date_block . "\n\n" . 
              $commesion_text . "\n\n" . 
              $prime_block,
        'post_excerpt' => $price_block,
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type' => 'post'
    );
        $newblog_post_id=wp_insert_post( $blog_post );
        
        
        if (!is_wp_error($newblog_post_id)) {

            
            if (isset($_POST['update_both_prices']) && $_POST['update_both_prices'] == '0') {
                update_post_meta($newblog_post_id, '_update_both_prices', '0');
            } else {                
                update_post_meta($newblog_post_id, '_update_both_prices', '1');
            }
            // Add the existing metadata
            if (isset($amazone_prod_basename) && !empty($amazone_prod_basename)) {
                add_post_meta($newblog_post_id, '_Amazone_produt_baseName', $amazone_prod_basename, true);
                add_post_meta($newblog_post_id, $amazone_prod_basename, $encoded, true);
                add_post_meta($newblog_post_id, 'dynamic_amazone_link', $dynamic_esc_url);
                add_post_meta($newblog_post_id, 'dynamic_link', $link_to_use);
            } else {
                add_post_meta($newblog_post_id, '_Amazone_produt_link', $manual_link, true); 
            }
            
            // Add new price tracking metadata
            update_post_meta($newblog_post_id, '_discount_price', $discount_price, true);
            update_post_meta($newblog_post_id, '_original_price', $original_price, true);
            update_post_meta($newblog_post_id, '_price_sources', $price_sources);
            
            // For manual entries, default to false (0) for updating both prices
            // This means by default, only the discount price will be updated for manual entries
            update_post_meta($newblog_post_id, '_update_both_prices', 0);
            
            // Add last price check timestamp
            update_post_meta($newblog_post_id, '_last_price_check', current_time('timestamp'));
       
             if ($image_url && !image_exists($image_url, $newblog_post_id)) {
                $image_id = media_sideload_image($image_url, $newblog_post_id, $blog_post_titles, 'id');
                if (!is_wp_error($image_id)) {
                    set_post_thumbnail($newblog_post_id, $image_id);
                }
        
                add_post_meta($newblog_post_id, '_discount_price', $discount_price, true);
                add_post_meta( $newblog_post_id, 'amazon_fr',$amazon_fr, true );
                add_post_meta( $newblog_post_id, 'amazon_nl',$amazon_nl, true );
             
             
                   if ($original_price > $discount_price) {
                $discount_percentage = (($original_price - $discount_price) / $original_price) * 100;
                $discount_percentage = round($discount_percentage);
                error_log('Discount percentage: ' . $discount_percentage);
                add_post_meta($newblog_post_id, '_discount_percentage', $discount_percentage, true);
                $discount_tag_title = $discount_percentage . '% off';
                    error_log("Discount Tag Title to search/create: {$discount_tag_title}");
                
                   $term = term_exists($discount_tag_title, 'post_tag');
                    if (!$term) {
                        $term = wp_insert_term($discount_tag_title, 'post_tag');
                        error_log("Creating new tag: {$discount_tag_title}");
                    } else {
                        error_log("Found existing tag for: {$discount_tag_title}");
                    }
                    
                    if (!is_wp_error($term)) {
                        
                        wp_set_post_terms($newblog_post_id, [$discount_tag_title], 'post_tag', true);
                        error_log("Assigned tag '{$discount_tag_title}' to post {$newblog_post_id}");
                    } else {
                        error_log('Discount Tag Error: ' . $term->get_error_message());
                    }
                } else {
                    error_log('No discount to apply or prices are incorrect.');
                }
             
             
                $store_type_taxonomy = 'store_type'; 
                $product_categories_taxonomy = 'product_categories';  
                
                
                $brand_term_exists = term_exists($brand_name, $store_type_taxonomy);
                if (!$brand_term_exists) {
                    $brand_term = wp_insert_term($brand_name, $store_type_taxonomy);
                    if (is_wp_error($brand_term)) {
                        error_log('Error creating store type term: ' . $brand_term->get_error_message());
                    }
                }
                
                if (isset($brand_term) && !is_wp_error($brand_term)) {
                    $brand_term_id = $brand_term_exists ? $brand_term_exists['term_id'] : $brand_term['term_id'];
                    wp_set_post_terms($newblog_post_id, [$brand_name], $store_type_taxonomy, false);
                } else if ($brand_term_exists) {
                    $brand_term_id = $brand_term_exists['term_id'];
                    wp_set_post_terms($newblog_post_id, [$brand_name], $store_type_taxonomy, false);
                }
                
                
                if (!empty($categories)) {
                    $product_category_id = create_product_categories($categories);
                    if ($product_category_id) {
                        wp_set_post_terms($newblog_post_id, [$product_category_id], 'product_categories', false);
                    } else {
                        error_log('Failed to create or find product category for: ' . $categories);
                    }
                }
                
             
             echo '<p style="color:green">New Post has bean created Plese <a href="'.get_the_permalink($newblog_post_id).'">Visit</a> </p>';
             

           }else{

            return $newblog_post_id->get_error_message();

           }
                   
       
     }
} 

function create_blog_using_given_title_create_blog($amazone_product_basename,$blog_post_titles,$blog_post_descriptions,$links, $discount_price, $org_price, $product_data, $categories, $image_url){
   
     
        //Prime block
        $prime_block_link = get_option('prime_block_link');
        $prime_block_area = get_option('prime_block_area');
        
        $sek_fixed_rate = get_option('sek_fixed_rate');
        
        //getting first two prices from the loop price data
        
        //Amazon link block
        $encoded = en_crypt_uniquie($amazone_product_basename); 
        
        $discount_price = $discount_price;
        $discount_price = round($discount_price);
        error_log('Discount price: ' . $discount_price);
        
        $original_price = $org_price;
        $original_price = round($original_price);
        error_log('Org price: ' . $original_price);
        
        $dynamic_url = site_url() . '/?sn=' . $encoded;
        $dynamic_esc_url = esc_url($dynamic_url);
         
         $price_block = '<div class="price_block"><a target="_blank" href="'. $dynamic_esc_url .'" rel="nofollow sponsored noopener"><span class="original-price">'.$original_price.' SEK' . '&nbsp;</span> <span class="discount-price">'.$discount_price.' SEK</span></a></div>';
       
        $prime_block = '<p><a class="prime_block" href="'.site_url().'/?dm='.en_crypt_uniquie($prime_block_link).'"  rel="nofollow sponsored">'.$prime_block_area.'</a></p>';
        
        //commesion block
        $commesion_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';
        
        
        //date block
        date_default_timezone_set("Europe/Stockholm"); 
        $date_block = '<p> **Price last checked '.date('Y-m-d H:i'). ' CET </p>';

               $amazon_link_block = '<div class="product-link_wrap" data-href="'. $dynamic_esc_url .'" target="_blank" rel="nofollow sponsored"><div class="button-block"><div class="button-image"><img src="https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png" alt="Product Image" class="webpexpress-processed"></div><div class="product-title"><p class="product-name">'. esc_html($blog_post_titles).'</p></div><div class="prices-container"><span class="original-price">'. esc_html($original_price) .'&nbsp;</span><span class="discount-price">' . esc_html($discount_price) .' SEK</span></div><div class="button-container"><button class="product-button"><span>Go to Product</span><svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24"><path d="M15.5,11.3L9.9,5.6c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l4.9,4.9l-4.9,4.9c-0.2,0.2-0.3,0.4-0.3,0.7c0,0.6,0.4,1,1,1c0.3,0,0.5-0.1,0.7-0.3l5.7-5.7c0,0,0,0,0,0C15.9,12.3,15.9,11.7,15.5,11.3z"></path></svg></button></div></div></div>';

        
        $blog_post = array(
                'post_title' => $blog_post_titles,
                'post_content' => $blog_post_descriptions.$amazon_link_block.$date_block.$commesion_text.$prime_block,
                'post_excerpt' => $price_block,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_type' => 'post'
        );
         
        $newblog_post_id=wp_insert_post( $blog_post );
        
        
           if(!is_wp_error($newblog_post_id)){
              
                       
             add_post_meta( $newblog_post_id, '_Amazone_produt_link',$links, true ); 
             add_post_meta( $newblog_post_id, '_Amazone_produt_baseName',$amazone_product_basename, true );
             add_post_meta( $newblog_post_id, $amazone_product_basename, $encoded, true );
             add_post_meta($newblog_post_id, 'dynamic_amazone_link', $dynamic_esc_url);
             

            if ($image_url && !image_exists($image_url, $newblog_post_id)) {
            $image_id = media_sideload_image($image_url, $newblog_post_id, $blog_post_titles, 'id');
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($newblog_post_id, $image_id);
            } else {
                error_log('Image Sideload Error: ' . $image_id->get_error_message());
            }
            }
            add_post_meta($newblog_post_id, '_discount_price', $discount_price, true);
            add_post_meta( $newblog_post_id, 'amazon_fr',$amazon_fr, true );
            add_post_meta( $newblog_post_id, 'amazon_nl',$amazon_nl, true );
            
             
            // Add discount calculation and tag creation
            if ($original_price > $discount_price) {
                $discount_percentage = round((($original_price - $discount_price) / $original_price) * 100);
                add_post_meta($newblog_post_id, '_discount_percentage', $discount_percentage, true);
                $discount_tag_title = $discount_percentage . '% off';
                
                $term = term_exists($discount_tag_title, 'post_tag');
                if (!$term) {
                    $term = wp_insert_term($discount_tag_title, 'post_tag');
                }
                
                if (!is_wp_error($term)) {
                    wp_set_post_terms($newblog_post_id, [$discount_tag_title], 'post_tag', true);
                }
            }
    
            // Add category handling
            if (!empty($categories)) {
                    $product_category_id = create_product_categories($categories);
                    if ($product_category_id) {
                        wp_set_post_terms($newblog_post_id, [$product_category_id], 'product_categories', false);
                    } else {
                        error_log('Failed to create or find product category for: ' . $categories);
                    }
                }
    
            // Add store type (Amazon)
                $store_type_taxonomy = 'store_type'; 
                $product_categories_taxonomy = 'product_categories';  
                
                
                $brand_term_exists = term_exists($brand_name, $store_type_taxonomy);
                if (!$brand_term_exists) {
                    $brand_term = wp_insert_term($brand_name, $store_type_taxonomy);
                    if (is_wp_error($brand_term)) {
                        error_log('Error creating store type term: ' . $brand_term->get_error_message());
                    }
                }
                
                if (isset($brand_term) && !is_wp_error($brand_term)) {
                    $brand_term_id = $brand_term_exists ? $brand_term_exists['term_id'] : $brand_term['term_id'];
                    wp_set_post_terms($newblog_post_id, [$brand_name], $store_type_taxonomy, false);
                } else if ($brand_term_exists) {
                    $brand_term_id = $brand_term_exists['term_id'];
                    wp_set_post_terms($newblog_post_id, [$brand_name], $store_type_taxonomy, false);
                }


             echo '<p style="color:green">New Post has bean created Plese <a href="'.get_the_permalink($newblog_post_id).'">Visit</a> </p>';
             
             
           }else{

            return $newblog_post_id->get_error_message();

           }
                   
       
     }

     
     function check_amazone_product_name_exist_and_create_blog($amazone_product_basename, $blog_post_titles, $blog_post_descriptions, $links, $price_data, $categories, $image_url)
     {
         // Existing code for prime block, etc.
         $prime_block_link = get_option('prime_block_link');
         $prime_block_area = get_option('prime_block_area');
         $sek_fixed_rate = get_option('sek_fixed_rate');
         
         // Initialize variables to track price sources
         $price_sources = array();
         $discount_price = 0;
         $original_price = 0;
         
         error_log('SEK Fixed Rate: ' . $sek_fixed_rate);
         
         // Process price data
         if ($price_data) {
             $prices = [];
             $sources = [];
             
             // Extract prices and track their sources
             foreach ($price_data as $key => $price_value) {
                 $price_value_cleaned = preg_replace('/[^\d,\.]/', '', $price_value);
                 $price_value_cleaned = str_replace(',', '.', $price_value_cleaned);
                 $price_float = floatval($price_value_cleaned);
                 
                 error_log("Cleaned price for {$key}: {$price_value_cleaned}");
                 error_log("Converted price for {$key}: {$price_float}");
                 
                 if ($price_float > 0) {
                     $prices[] = $price_float;
                     $sources[] = $key; // Store the source (e.g., amazon.se, amazon.de)
                 }
             }
             
             // Sort prices but keep track of their sources
             array_multisort($prices, SORT_ASC, $sources);
             error_log('Sorted prices: ' . implode(', ', $prices));
             
             if (count($prices) >= 2) {
                 $discount_price = $prices[0];
                 $original_price = $prices[1];
                 
                 // Store the sources for each price
                 $price_sources = array(
                     'discount_price_source' => $sources[0],
                     'original_price_source' => $sources[1]
                 );
                 
                 // Create price block for display as before
                 $encoded = en_crypt_uniquie($amazone_product_basename);
                 $dynamic_url = site_url() . '/?sn=' . $encoded;
                 $dynamic_esc_url = esc_url($dynamic_url);
                 
                 // Price block for display, rounded for aesthetics
                 $price_block = '<div class="price_block"><a target="_blank" href="'. $dynamic_esc_url .'" rel="nofollow sponsored noopener"><span class="original-price">'.round($original_price).' SEK' . '&nbsp;</span> <span class="discount-price">'.round($discount_price).' SEK</span></a></div>';
             }
         }
         
         // Rest of the existing code for prime_block, commesion_text, etc.
         $prime_block = '<p><a class="prime_block" target="_blank" href="'.site_url().'/?dm='.en_crypt_uniquie($prime_block_link).'"  rel="nofollow sponsored">'.$prime_block_area.'</a></p>';
         
         // Commesion block
         $commesion_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';
         
         // Date block
         date_default_timezone_set("Europe/Stockholm"); 
         $date_block = '<p> **Price last checked '.date('Y-m-d H:i'). ' CET </p>';
     
         $amazon_link_block = '<div class="product-link_wrap" data-href="'. $dynamic_esc_url .'" target="_blank" rel="nofollow sponsored"><div class="button-block"><div class="button-image"><img src="https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png" alt="Product Image" class="webpexpress-processed"></div><div class="product-title"><p class="product-name">'. esc_html($blog_post_titles).'</p></div><div class="prices-container"><span class="original-price">'. esc_html(round($original_price)) .' SEK</span><span class="discount-price">' . esc_html(round($discount_price)) .' SEK</span></div><div class="button-container"><button class="product-button"><span>Go to Product</span><svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24"><path d="M15.5,11.3L9.9,5.6c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l4.9,4.9l-4.9,4.9c-0.2,0.2-0.3,0.4-0.3,0.7c0,0.6,0.4,1,1,1c0.3,0,0.5-0.1,0.7-0.3l5.7-5.7c0,0,0,0,0,0C15.9,12.3,15.9,11.7,15.5,11.3z"></path></svg></button></div></div></div>';
     

        
    //    $prime_block = '<p><a class="prime_block" target="_blank" href="'.site_url().'/?dm='.en_crypt_uniquie($prime_block_link).'"  rel="nofollow sponsored">'.$prime_block_area.'</a></p>';
        
    //     //commesion block
    //     $commesion_text = '<p>**Adealsweden makes commission on any purchases through the links.</p>';
        
    //     //date block
    //     date_default_timezone_set("Europe/Stockholm"); 
    //     $date_block = '<p> **Price last checked '.date('Y-m-d H:i'). ' CET </p>';
        
        
            // $amazon_link_block = '<div class="product-link_wrap" data-href="'. $dynamic_esc_url .'" target="_blank" rel="nofollow sponsored"><div class="button-block"><div class="button-image"><img src="https://www.adealsweden.com/wp-content/uploads/2023/12/amazon_se_logo_RGB_REV-1.png" alt="Product Image" class="webpexpress-processed"></div><div class="product-title"><p class="product-name">'. esc_html($blog_post_titles).'</p></div><div class="prices-container"><span class="original-price">'. esc_html($original_price) .'&nbsp;</span><span class="discount-price">' . esc_html($discount_price) .' SEK</span></div><div class="button-container"><button class="product-button"><span>Go to Product</span><svg xmlns="http://www.w3.org/2000/svg" enable-background="new 0 0 24 24" viewBox="0 0 24 24"><path d="M15.5,11.3L9.9,5.6c-0.4-0.4-1-0.4-1.4,0s-0.4,1,0,1.4l4.9,4.9l-4.9,4.9c-0.2,0.2-0.3,0.4-0.3,0.7c0,0.6,0.4,1,1,1c0.3,0,0.5-0.1,0.7-0.3l5.7-5.7c0,0,0,0,0,0C15.9,12.3,15.9,11.7,15.5,11.3z"></path></svg></button></div></div></div>';


            $blog_post = array(
                'post_title' => $blog_post_titles,
                'post_content' => $blog_post_descriptions . "\n\n" . 
                      $amazon_link_block . "\n\n" . 
                      $date_block . "\n\n" . 
                      $commesion_text . "\n\n" . 
                      $prime_block,
                'post_excerpt' => $price_block,
                'post_status' => 'publish',
                'post_author' => 1,
                'post_type' => 'post'
            );
         
        $newblog_post_id=wp_insert_post( $blog_post );

       if (!is_wp_error($newblog_post_id)) {
        
        add_post_meta($newblog_post_id, '_Amazone_produt_link', $links, true); 
        add_post_meta($newblog_post_id, '_Amazone_produt_baseName', $amazone_product_basename, true);
        add_post_meta($newblog_post_id, $amazone_product_basename, $encoded, true);
        update_post_meta($newblog_post_id, '_discount_price', $discount_price, true);
        add_post_meta($newblog_post_id, 'dynamic_link', $dynamic_esc_url);
        
        update_post_meta($newblog_post_id, '_original_price', $original_price, true);
        update_post_meta($newblog_post_id, '_price_sources', $price_sources);
        
        update_post_meta($newblog_post_id, '_update_both_prices', 1);
        
        update_post_meta($newblog_post_id, '_last_price_check', current_time('timestamp'));
             if ($image_url && !image_exists($image_url, $newblog_post_id)) {
                    $image_id = media_sideload_image($image_url, $newblog_post_id, $blog_post_titles, 'id');
                    if (!is_wp_error($image_id)) {
                        set_post_thumbnail($newblog_post_id, $image_id);
                    } else {
                        error_log('Image Sideload Error: ' . $image_id->get_error_message());
                    }
             }
                error_log('Discount price: ' . $discount_price);
                error_log('Original price: ' . $original_price);
                
             if ($original_price > $discount_price) {
            $discount_percentage = (($original_price - $discount_price) / $original_price) * 100;
            $discount_percentage = round($discount_percentage);
            error_log('Discount percentage: ' . $discount_percentage);
            add_post_meta($newblog_post_id, '_discount_percentage', $discount_percentage, true);
            $discount_tag_title = $discount_percentage . '% off';
                error_log("Discount Tag Title to search/create: {$discount_tag_title}");
            
               $term = term_exists($discount_tag_title, 'post_tag');
                if (!$term) {
                    $term = wp_insert_term($discount_tag_title, 'post_tag');
                    error_log("Creating new tag: {$discount_tag_title}");
                } else {
                    error_log("Found existing tag for: {$discount_tag_title}");
                }
                
                if (!is_wp_error($term)) {
                    
                    wp_set_post_terms($newblog_post_id, [$discount_tag_title], 'post_tag', true);
                    error_log("Assigned tag '{$discount_tag_title}' to post {$newblog_post_id}");
                } else {
                    error_log('Discount Tag Error: ' . $term->get_error_message());
                }
            } else {
                error_log('No discount to apply or prices are incorrect.');
            }
            
            
            // Category handling logic: 
            
                $store_type_taxonomy = 'store_type';  
                $amazon_term_name = 'amazon';  
                $product_categories_taxonomy = 'product_categories';  
                
                
                $amazon_term = term_exists($amazon_term_name, $store_type_taxonomy);
                if (!$amazon_term) {
                    $amazon_term = wp_insert_term($amazon_term_name, $store_type_taxonomy);
                }
                if (!is_wp_error($amazon_term)) {
                    $amazon_term_id = $amazon_term['term_id'];
                    wp_set_post_terms($newblog_post_id, [$amazon_term_name], $store_type_taxonomy, false);
                }
                
               
                if (!empty($categories)) {
                    $product_category_id = create_product_categories($categories);
                    if ($product_category_id) {
                        wp_set_post_terms($newblog_post_id, [$product_category_id], 'product_categories', false);
                    } else {
                        error_log('Failed to create or find product category for: ' . $categories);
                    }
                }
                
             
           }
           else
           {

            return $newblog_post_id->get_error_message();

           }
                   
       
     }

/**************************************** Generate short url ***************************/
function generate_short_url_using_api($amaz_url){
    
    $curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "https://api.apilayer.com/short_url/hash",
  CURLOPT_HTTPHEADER => array(
    "Content-Type: text/plain",
    "apikey: GAa0LGlu0iFlm7zshsR9Oa6Ojb9lGbvl"
  ),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS =>$amaz_url
));

$response = curl_exec($curl);

curl_close($curl);

$url_data = json_decode($response);
 return $url_data->short_url;   
}     
/********************************Generate conent from AI*********************************/
//Generate blog title

 
function generate_content_from_AI($blogs_title)
{
     
       // API key
        $apiKey             = get_option('api_key');
        $max_tokens         = (float) get_option('max_tokens'); 
        $temperature        = (float) get_option('temperature'); 
        $frequency_penalty  = (float) get_option('frequency_penalty'); 
        $presence_penalty   = (float) get_option('presence_penalty'); 
        
        $prompt_for_ai =   get_option('prompt_for_ai');
        
        $ai_incructions = $prompt_for_ai.': '.$blogs_title;
   
       // mail('benknackan@gmail.com', 'Promt when sending to ai', $ai_incructions);
        if('gpt-3.5-turbo'==get_option('ai_model')){
             
             
           $ai_model=get_option('ai_model');
      
           $url = "https://api.openai.com/v1/chat/completions";

           $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];
           $data = array(
            "model" => $ai_model,
            "messages" => [
            array(
                "role" => "system",
                "content" => "You are a helpful assistant."
            ),
            array(
                "role" => "user",
                "content" => $ai_incructions
            )
       ],
);
     $ch = curl_init();
        $json_data = json_encode($data);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);

       
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code === 200) {

            $response_data  = json_decode($response, true);
            $generated_text = $response_data['choices'][0]['message']['content'];
            return $generated_text; 
        } 
        else {
        return "HTTP Error: " . $http_code;
    }

        curl_close($ch);         
}else{
   
            // echo'other model';
         $url = 'https://api.openai.com/v1/engines/'.get_option('ai_model').'/completions'; 
          
          $data = [
            'prompt' => $ai_incructions,
            'temperature' =>$temperature,
            'max_tokens' => $max_tokens,
            'top_p' => 1,
            'frequency_penalty' => $frequency_penalty,
            'presence_penalty' => $presence_penalty,
        ];    

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ];
    
       
        $json_data = $data; //json_encode($data);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Add this line to bypass SSL certificate verification

       $response = curl_exec($ch);


     $response_data = json_decode($response, true);

     $generated_text = $response_data['choices'][0]['text'];
     return $generated_text;


curl_close($ch);
      }
 }

 //convert lanuage 
 function translatetext_from_sw_to_eng($text){
    $curlSession = curl_init(); 
    curl_setopt($curlSession, CURLOPT_URL, 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=sv&tl=en&dt=t&q='.urlencode($text));
    curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curlSession);
    $jsonData = json_decode($response);
    curl_close($curlSession);

    if(isset($jsonData[0][0][0])){
        return $jsonData[0][0][0];
    }else{
        return false;
    }
}
//Generate blog description


?>