<?php

/**
 * File: price-rules.php
 * Description: Handles price update rules and logic
 */


function process_price_update_api($post_id, $price_data)
{
    // Get current post data
    $current_discount_price = get_post_meta($post_id, '_discount_price', true);
    $current_original_price = get_post_meta($post_id, '_original_price', true);
    $update_both_prices = get_post_meta($post_id, '_update_both_prices', true);

    delete_post_meta($post_id, '_force_price_update');

    // Get settings with improved defaults
    $options = get_option('price_updates_options', array());
    $price_change_low_threshold = isset($options['price_change_low_threshold']) ? floatval($options['price_change_low_threshold']) : 20;
    $price_change_high_threshold = isset($options['price_change_high_threshold']) ? floatval($options['price_change_high_threshold']) : 70;
    $significant_decrease = isset($options['significant_decrease']) ? floatval($options['significant_decrease']) : 5;
    $archive_category = isset($options['archive_category']) ? intval($options['archive_category']) : 0;

    if (empty($price_data) || count($price_data) === 0) {
        error_log('No prices found for post ' . $post_id . '. Archiving.');
        return archive_post($post_id, 'No prices available from any Amazon store', $archive_category);
    }

    // Extract prices from the price data
    $prices = [];
    $sources = [];

    $disable_price_updates = get_post_meta($post_id, '_disable_price_updates', true);
    if ($disable_price_updates == '1') {
        error_log('Price updates are disabled for post ' . $post_id . '. Skipping update.');
        return 'updates_disabled';
    }

    foreach ($price_data as $key => $price_value) {
        // Improved price cleaning to handle various formats
        $price_value_cleaned = preg_replace('/[^\d,\.]/', '', $price_value);
        $price_value_cleaned = str_replace(',', '.', $price_value_cleaned);
        $price_float = floatval($price_value_cleaned);

        if ($price_float > 0) {
            $prices[] = $price_float;
            $sources[] = $key; // Store the source (e.g., amazon.se, amazon.de)
        }
    }

    error_log('Extracted prices for post ' . $post_id . ': ' . json_encode($prices));
    error_log('Price sources for post ' . $post_id . ': ' . json_encode($sources));

    if (empty($prices)) {
        error_log('Product not available. Archiving post ' . $post_id);
        return archive_post($post_id, 'Product no longer available', $archive_category);
    }


    array_multisort($prices, SORT_ASC, $sources);

    $new_discount_price = $prices[0];
    $new_original_price = isset($prices[1]) ? $prices[1] : $prices[0];


    $price_sources = array(
        'discount_price_source' => $sources[0],
        'original_price_source' => isset($sources[1]) ? $sources[1] : $sources[0]
    );


    if ($new_discount_price >= $new_original_price) {
        error_log('Discount price (' . $new_discount_price . ') no longer lower than original price (' . $new_original_price . '). Archiving post ' . $post_id);
        return archive_post($post_id, 'Discount price no longer lower than original price', $archive_category);
    }


    if ($new_original_price <= $current_discount_price) {
        error_log('Original price (' . $new_original_price . ') now lower than previous discount price (' . $current_discount_price . '). Archiving post ' . $post_id);
        return archive_post($post_id, 'Original price now lower than previous discount price', $archive_category);
    }


    $discount_price_change = 0;
    if ($current_discount_price > 0) {
        $discount_price_change = (($new_discount_price - $current_discount_price) / $current_discount_price) * 100;
    }


    $original_price_change = 0;
    if ($current_original_price > 0) {
        $original_price_change = (($new_original_price - $current_original_price) / $current_original_price) * 100;
    }

    error_log('Price changes for post ' . $post_id . ': Discount ' . $discount_price_change . '%, Original ' . $original_price_change . '%');


    $relevant_price_change = $discount_price_change;
    if ($update_both_prices && abs($original_price_change) > abs($discount_price_change)) {
        $relevant_price_change = $original_price_change;
    }


    if ($discount_price_change <= -$significant_decrease) {

        update_post_meta($post_id, '_reshare_post', 1);
        error_log('Price decreased significantly (' . $discount_price_change . '%). Marked for resharing: post ' . $post_id);
    }


    if ($relevant_price_change > $price_change_high_threshold) {
        error_log('Price increase (' . $relevant_price_change . '%) exceeds high threshold (' . $price_change_high_threshold . '%). Archiving post ' . $post_id);
        return archive_post($post_id, 'Price increase too large (increased by ' . round($relevant_price_change, 1) . '%)', $archive_category);
    }


    if ($relevant_price_change >= $price_change_low_threshold && $relevant_price_change <= $price_change_high_threshold) {
        // Store pending data for review with more context
        $pending_data = array(
            'discount_price' => $new_discount_price,
            'original_price' => $new_original_price,
            'price_sources' => $price_sources,
            'previous_discount_price' => $current_discount_price,
            'previous_original_price' => $current_original_price,
            'discount_price_change' => $discount_price_change,
            'original_price_change' => $original_price_change,
            'timestamp' => current_time('timestamp')
        );

        update_post_meta($post_id, '_pending_price_data', $pending_data);
        update_post_meta($post_id, '_needs_price_validation', 1);

        // Send enhanced notification
        send_price_change_notification($post_id, $pending_data);

        error_log('Price change (' . $relevant_price_change . '%) within manual verification range (' . $price_change_low_threshold . '% to ' . $price_change_high_threshold . '%). Marked for approval: post ' . $post_id);
        return 'needs_approval';
    }


    update_post_meta($post_id, '_discount_price', $new_discount_price);

    if ($update_both_prices) {
        update_post_meta($post_id, '_original_price', $new_original_price);
    }

    update_post_meta($post_id, '_price_sources', $price_sources);
    update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

    // Update discount percentage
    if ($new_original_price > $new_discount_price) {
        $discount_percentage = (($new_original_price - $new_discount_price) / $new_original_price) * 100;
        $discount_percentage = round($discount_percentage);
        update_post_meta($post_id, '_discount_percentage', $discount_percentage);

        // Update discount tag
        $discount_tag_title = $discount_percentage . '% off';
        wp_set_post_terms($post_id, [$discount_tag_title], 'post_tag', false);
    }

    // Update HTML content
    update_post_html_with_new_prices($post_id);

    // UPDATE POST TIMESTAMP - ADD THIS
    wp_update_post(array(
        'ID' => $post_id,
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1)
    ));

    // Determine return status based on price decrease
    if ($discount_price_change <= -$significant_decrease) {
        return 'price_decreased';
    }

    error_log('Price updated automatically for post ' . $post_id . ' (change: ' . $relevant_price_change . '%)');
    return 'price_updated';
}


function archive_post($post_id, $reason, $archive_category_id)
{
    // Record archiving metadata
    update_post_meta($post_id, '_archive_reason', $reason);
    update_post_meta($post_id, '_archived_date', current_time('timestamp'));


    if ($archive_category_id > 0) {
        wp_set_post_categories($post_id, array($archive_category_id), false);
    }


    $post = get_post($post_id);

    if (!$post) {
        return 'post_not_found';
    }


    $content = $post->post_content;
    $excerpt = $post->post_excerpt;

    $new_content = preg_replace(
        '/<span class="original-price">(.*?)<\/span><span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price"><s>$1</s></span><span class="discount-price"><s>$2</s></span>',
        $content
    );

    $new_excerpt = preg_replace(
        '/<span class="original-price">(.*?)<\/span> <span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price"><s>$1</s></span> <span class="discount-price"><s>$2</s></span>',
        $excerpt
    );

    // Add notice at top of content
    $archive_notice = '<div style="background-color: #f8d7da; padding: 10px; margin-bottom: 15px;">
        <p><strong>This deal is no longer available.</strong></p>
        <p>Reason: ' . esc_html($reason) . '</p>
    </div>';

    $new_content = $archive_notice . $new_content;

    // Update the post
    wp_update_post(array(
        'ID' => $post_id,
        'post_content' => $new_content,
        'post_excerpt' => $new_excerpt
    ));

    return 'archived';
}


function update_post_html_with_new_prices($post_id)
{
    $post = get_post($post_id);

    if (!$post) {
        return false;
    }

    $discount_price = get_post_meta($post_id, '_discount_price', true);
    $original_price = get_post_meta($post_id, '_original_price', true);

    $content = $post->post_content;
    $excerpt = $post->post_excerpt;

    // Round prices for display
    $rounded_discount = round($discount_price);
    $rounded_original = round($original_price);

    // Update price in the content and excerpt
    $new_content = preg_replace(
        '/<span class="original-price">(.*?)<\/span><span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price">' . $rounded_original . ' SEK</span><span class="discount-price">' . $rounded_discount . ' SEK</span>',
        $content
    );

    $new_excerpt = preg_replace(
        '/<span class="original-price">(.*?)<\/span> <span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price">' . $rounded_original . ' SEK</span> <span class="discount-price">' . $rounded_discount . ' SEK</span>',
        $excerpt
    );

    // Update the post
    $updated_post = array(
        'ID' => $post_id,
        'post_content' => $new_content,
        'post_excerpt' => $new_excerpt
    );

    return wp_update_post($updated_post);
}

function update_price_check_timestamp($post_id)
{

    update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

    error_log(sprintf(
        'Price check timestamp updated for post %d at %s',
        $post_id,
        date('Y-m-d H:i:s', current_time('timestamp'))
    ));
}


function enhance_save_price_settings_meta_box_data($post_id)
{
    if (isset($_POST['discount_price']) || isset($_POST['original_price'])) {
        update_price_check_timestamp($post_id);
    }
}

function enhance_webhook_price_updates($post_id, $price_data)
{

    if ($post_id && !empty($price_data)) {
        update_price_check_timestamp($post_id);
    }
}

function log_price_update_error($post_id, $error_message, $context = array())
{
    // Store error information in post meta
    $errors = get_post_meta($post_id, '_price_update_errors', true);
    if (!is_array($errors)) {
        $errors = array();
    }

    // Add this error
    $errors[] = array(
        'message' => $error_message,
        'context' => $context,
        'timestamp' => current_time('timestamp')
    );

    if (count($errors) > 5) {
        $errors = array_slice($errors, -5);
    }

    update_post_meta($post_id, '_price_update_errors', $errors);


    error_log(sprintf('Price update error for post %d: %s', $post_id, $error_message));
}
