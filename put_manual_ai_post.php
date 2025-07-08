<?php

if (isset($_POST['put_ai_manul_post'])) {

    $product_category_id    = $_POST['product_category'];
    // Use simple brand name like your working code
    $brand_name             = 'Amazon';
    $image_url              = $_POST['brand_image_url'];
    $category_hierarchy     = $_POST['category_hierarchy'];
    $manual_link            = $_POST['amazon_url'];
    $org_price              = $_POST['org_amazon_price'];
    $discount_price         = $_POST['disc_amazon_price'];
    $ai_blog_content        = $_POST['manual_post_title'];

    // Simple basename extraction like your working code
    $path = parse_url($manual_link, PHP_URL_PATH);
    $amazone_prod_basename = basename($path);

    // Get the Amazon product base name of the path 
    $post_category = check_if_post_already_exist_in_database($manual_link);

    // FIX: Check if post_category exists and has elements before accessing
    if (empty($post_category) || $post_category[0]->slug != 'active-deals') {

        /*********************************Generate content from AI **************************************/

        $ai_content  = str_replace(utf8_encode('å'), "är", $ai_blog_content);
        $ai_content  = str_replace(utf8_encode('Å'), "A", $ai_content);
        $ai_content  = str_replace(utf8_encode('Ö'), "O", $ai_content);
        $ai_content  = str_replace(utf8_encode('ä'), "ae", $ai_content);
        $ai_content  = str_replace(utf8_encode('à'), "a", $ai_content);
        $ai_content  = str_replace(utf8_encode('ö'), "o", $ai_content);
        $ai_content  = str_replace(utf8_encode('ü'), "är", $ai_content);

        $ai_content = generate_content_from_AI($ai_content);

        // FIX: Handle AI API failures gracefully
        if (strpos($ai_content, 'Error:') === 0) {
            // AI API failed, use manual input as fallback
            $blog_post_title = $ai_blog_content;
            $blog_post_description = "Product description for: " . $ai_blog_content;
            error_log('AI API failed, using manual fallback: ' . $ai_content);
        } else {
            $ai_content = str_replace('"', " ", $ai_content);
            $deals_category_id = get_category_by_slug('active-deals')->term_id;

            // FIX: Safe content parsing with array bounds checking
            if (str_contains($ai_content, 'Title')) {
                $ai_content_sp = explode('Description:', trim($ai_content));
                if (count($ai_content_sp) >= 2) {
                    $blog_post_title = $ai_content_sp[0];
                    $blog_post_title = str_replace('Title:', '', $blog_post_title);
                    $blog_post_description = $ai_content_sp[1];
                } else {
                    // Fallback if parsing fails
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
                    // Fallback if parsing fails
                    $blog_post_title = $ai_blog_content;
                    $blog_post_description = "Product description for: " . $ai_blog_content;
                }
            }
        }

        $update_both_prices_setting = isset($_POST['update_both_prices']) ? '0' : '1';

        $check = create_manual_blog_using_given_title_create_blog(
            $amazone_prod_basename,
            $blog_post_title,
            $blog_post_description,
            $manual_link,
            $discount_price,
            $org_price,
            [], // Empty array for product_data like your working code
            $brand_name,
            $image_url,
            $category_hierarchy,
            $product_category_id,
            $update_both_prices_setting // Pass the corrected setting
        );
    } else {
        echo '<p style="color:red">This post already exists</p>';
    }
}
