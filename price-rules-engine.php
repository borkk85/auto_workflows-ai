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

    // If meta fields are empty, try to extract from HTML (legacy posts)
    if (empty($current_discount_price) || empty($current_original_price)) {
        $post = get_post($post_id);
        if ($post) {
            $extracted_prices = extract_prices_from_html($post->post_content, $post->post_excerpt);

            if (empty($current_discount_price) && $extracted_prices['discount'] > 0) {
                $current_discount_price = $extracted_prices['discount'];
                // Save to meta for future use
                update_post_meta($post_id, '_discount_price', $current_discount_price);
                error_log('Extracted discount price from HTML for post ' . $post_id . ': ' . $current_discount_price);
            }

            if (empty($current_original_price) && $extracted_prices['original'] > 0) {
                $current_original_price = $extracted_prices['original'];
                // Save to meta for future use
                update_post_meta($post_id, '_original_price', $current_original_price);
                error_log('Extracted original price from HTML for post ' . $post_id . ': ' . $current_original_price);
            }
        }
    }

    delete_post_meta($post_id, '_force_price_update');

    // Get settings with improved defaults
    $options = get_option('price_updates_options', array());
    $price_change_low_threshold = isset($options['price_change_low_threshold']) ? floatval($options['price_change_low_threshold']) : 20;
    $price_change_high_threshold = isset($options['price_change_high_threshold']) ? floatval($options['price_change_high_threshold']) : 70;
    $significant_decrease = isset($options['significant_decrease']) ? floatval($options['significant_decrease']) : 5;
    $archive_category = isset($options['archive_category']) ? intval($options['archive_category']) : 0;

    // Check for empty price data first
    if (empty($price_data) || count($price_data) === 0) {
        error_log('No prices found for post ' . $post_id . '. Archiving.');
        return archive_post($post_id, 'Out of stock', $archive_category);
    }

    $disable_price_updates = get_post_meta($post_id, '_disable_price_updates', true);
    if ($disable_price_updates == '1') {
        error_log('Price updates are disabled for post ' . $post_id . '. Skipping update.');
        return 'updates_disabled';
    }

    // SEK-priority price extraction
    $sek_price = null;
    $sek_source = null;
    $other_prices = [];
    $other_sources = [];

    // Separate SEK from other currencies
    foreach ($price_data as $key => $price_value) {
        // Skip "Unavailable" entries
        if (strtolower($price_value) === 'unavailable' || strtolower($price_value) === 'not found') {
            continue;
        }

        $price_value_cleaned = preg_replace('/[^\d,\.]/', '', $price_value);
        $price_value_cleaned = str_replace(',', '.', $price_value_cleaned);
        $price_float = floatval($price_value_cleaned);

        if ($price_float > 0) {
            if ($key === 'amazon.se') {
                $sek_price = $price_float;
                $sek_source = $key;
            } else {
                $other_prices[] = $price_float;
                $other_sources[] = $key;
            }
        }
    }

    // Check if we have any valid prices
    if (!$sek_price && empty($other_prices)) {
        error_log('No valid prices found for post ' . $post_id . '. Archiving.');
        return archive_post($post_id, 'Out of stock', $archive_category);
    }

    // Priority logic: Use SEK if available, otherwise fall back to others
    if ($sek_price) {
        $new_discount_price = $sek_price;
        $discount_source = $sek_source;

        // For original price, use lowest non-SEK price if available, otherwise use SEK
        if (!empty($other_prices)) {
            array_multisort($other_prices, SORT_ASC, $other_sources);
            $new_original_price = $other_prices[0];
            $original_source = $other_sources[0];
        } else {
            $new_original_price = $sek_price;
            $original_source = $sek_source;
        }
    } else {
        // No SEK price available, use lowest other price
        array_multisort($other_prices, SORT_ASC, $other_sources);
        $new_discount_price = $other_prices[0];
        $new_original_price = isset($other_prices[1]) ? $other_prices[1] : $other_prices[0];
        $discount_source = $other_sources[0];
        $original_source = isset($other_sources[1]) ? $other_sources[1] : $other_sources[0];
    }

    $price_sources = array(
        'discount_price_source' => $discount_source,
        'original_price_source' => $original_source
    );

    // Log the price selection for debugging
    error_log('SEK-priority pricing for post ' . $post_id . ': Discount=' . $new_discount_price . ' (' . $discount_source . '), Original=' . $new_original_price . ' (' . $original_source . ')');
    error_log('Previous prices for post ' . $post_id . ': Discount=' . $current_discount_price . ', Original=' . $current_original_price);

    // Validation checks
    if ($new_discount_price >= $new_original_price) {
        error_log('Discount price (' . $new_discount_price . ') no longer lower than original price (' . $new_original_price . '). Archiving post ' . $post_id);
        return archive_post($post_id, 'Discount no longer available', $archive_category);
    }

    // Calculate current and new deal ratios
    $current_deal_ratio = 0;
    if ($current_original_price > 0) {
        $current_deal_ratio = $current_discount_price / $current_original_price;
    }

    $new_deal_ratio = $new_discount_price / $new_original_price;

    // Archive if new deal is poor (discount > 70% of original = less than 30% off)
    if ($new_deal_ratio > 0.70) {
        error_log('New deal ratio (' . round($new_deal_ratio * 100, 1) . '%) indicates poor deal. Archiving post ' . $post_id);
        return archive_post($post_id, 'Discount no longer available', $archive_category);
    }

    // Calculate price changes (only if we have previous prices)
    $discount_price_change = 0;
    if ($current_discount_price > 0) {
        $discount_price_change = (($new_discount_price - $current_discount_price) / $current_discount_price) * 100;
    }

    $original_price_change = 0;
    if ($current_original_price > 0) {
        $original_price_change = (($new_original_price - $current_original_price) / $current_original_price) * 100;
    }

    error_log('Price changes for post ' . $post_id . ': Discount ' . $discount_price_change . '%, Original ' . $original_price_change . '%');
    error_log('Deal ratios for post ' . $post_id . ': Previous ' . round($current_deal_ratio * 100, 1) . '%, New ' . round($new_deal_ratio * 100, 1) . '%');

    // If no previous prices available, treat as new post and auto-update
    if ($current_discount_price <= 0 || $current_original_price <= 0) {
        error_log('No previous price data for post ' . $post_id . '. Treating as new post and auto-updating.');

        // Update prices and continue with standard logic
        update_post_meta($post_id, '_discount_price', $new_discount_price);
        update_post_meta($post_id, '_original_price', $new_original_price);
        update_post_meta($post_id, '_price_sources', $price_sources);
        update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

        // Update HTML and timestamp
        update_post_html_with_new_prices($post_id);
        wp_update_post(array(
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));

        return 'price_updated';
    }

    if ($discount_price_change <= 0 && $new_deal_ratio <= 0.70) {
        // Discount price is same or better AND deal is still good (30%+ off)
        error_log('Smart auto-approval for post ' . $post_id . ': Discount unchanged/better (' . $discount_price_change . '%), deal still good (' . round((1 - $new_deal_ratio) * 100) . '% off)');

        // Auto-update prices
        update_post_meta($post_id, '_discount_price', $new_discount_price);

        if ($update_both_prices) {
            update_post_meta($post_id, '_original_price', $new_original_price);
        }

        update_post_meta($post_id, '_price_sources', $price_sources);
        update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

        // Update discount percentage and tag
        $discount_percentage = update_discount_percentage_and_tag($post_id);

        if ($discount_percentage > 0) {
            error_log("Smart auto-approval: Updated discount tag for post {$post_id} to '{$discount_percentage}% off'");
        }

        // Update HTML and timestamp
        update_post_html_with_new_prices($post_id);
        wp_update_post(array(
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));

        // Check if we should mark for resharing
        if ($discount_price_change <= -$significant_decrease) {
            update_post_meta($post_id, '_reshare_post', 1);
            return 'price_decreased';
        }

        return 'price_updated';
    }

    // Enhanced decision logic based on both old and new states
    $relevant_price_change = $discount_price_change;
    if ($update_both_prices && abs($original_price_change) > abs($discount_price_change)) {
        $relevant_price_change = $original_price_change;
    }

    // Special case: Small changes should auto-update even if normally in approval range
    // Example: 50/100 to 55/100 (5% discount price increase) -> auto-update
    if (abs($discount_price_change) <= 10 && abs($original_price_change) <= 10) {
        error_log('Small price change detected (' . $discount_price_change . '% discount, ' . $original_price_change . '% original). Auto-updating post ' . $post_id);

        // Auto-update small changes
        update_post_meta($post_id, '_discount_price', $new_discount_price);
        if ($update_both_prices) {
            update_post_meta($post_id, '_original_price', $new_original_price);
        }
        update_post_meta($post_id, '_price_sources', $price_sources);
        update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

        // Update discount percentage and HTML
        // if ($new_original_price > $new_discount_price) {
        //     $discount_percentage = (($new_original_price - $new_discount_price) / $new_original_price) * 100;
        //     $discount_percentage = round($discount_percentage);
        //     update_post_meta($post_id, '_discount_percentage', $discount_percentage);
        //     $discount_tag_title = $discount_percentage . '% off';
        //     wp_set_post_terms($post_id, [$discount_tag_title], 'post_tag', false);
        // }

        $discount_percentage = update_discount_percentage_and_tag($post_id);

        if ($discount_percentage > 0) {
            error_log("Automatic update: Updated discount tag for post {$post_id} to '{$discount_percentage}% off'");
        }

        update_post_html_with_new_prices($post_id);
        wp_update_post(array(
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));

        return 'price_updated';
    }

    // Mark for resharing if significant decrease
    if ($discount_price_change <= -$significant_decrease) {
        update_post_meta($post_id, '_reshare_post', 1);
        error_log('Price decreased significantly (' . $discount_price_change . '%). Marked for resharing: post ' . $post_id);
    }

    // Archive if price increase too high
    if ($relevant_price_change > $price_change_high_threshold) {
        error_log('Price increase (' . $relevant_price_change . '%) exceeds high threshold (' . $price_change_high_threshold . '%). Archiving post ' . $post_id);
        return archive_post($post_id, 'Price increase', $archive_category);
    }

    // Send to approval if in middle range (and not a small change)
    if ($relevant_price_change >= $price_change_low_threshold && $relevant_price_change <= $price_change_high_threshold) {
        $pending_data = array(
            'discount_price' => $new_discount_price,
            'original_price' => $new_original_price,
            'price_sources' => $price_sources,
            'previous_discount_price' => $current_discount_price,
            'previous_original_price' => $current_original_price,
            'discount_price_change' => $discount_price_change,
            'original_price_change' => $original_price_change,
            'current_deal_ratio' => $current_deal_ratio,
            'new_deal_ratio' => $new_deal_ratio,
            'timestamp' => current_time('timestamp')
        );

        update_post_meta($post_id, '_pending_price_data', $pending_data);
        update_post_meta($post_id, '_needs_price_validation', 1);

        send_price_change_notification($post_id, $pending_data);

        error_log('Price change (' . $relevant_price_change . '%) within manual verification range (' . $price_change_low_threshold . '% to ' . $price_change_high_threshold . '%). Marked for approval: post ' . $post_id);
        return 'needs_approval';
    }

    // Auto-update for acceptable changes
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

        // Update discount tag - REPLACE not append
        $discount_tag_title = $discount_percentage . '% off';
        wp_set_post_terms($post_id, [$discount_tag_title], 'post_tag', false);
    }

    // Update HTML content and timestamp
    update_post_html_with_new_prices($post_id);
    wp_update_post(array(
        'ID' => $post_id,
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1)
    ));

    // Determine return status
    if ($discount_price_change <= -$significant_decrease) {
        return 'price_decreased';
    }

    error_log('Price updated automatically for post ' . $post_id . ' (change: ' . $relevant_price_change . '%)');
    return 'price_updated';
}


// FIXED archive_post function - Replace the entire function in price-rules-engine.php
function archive_post($post_id, $reason, $archive_category_id)
{
    // Record archiving metadata
    update_post_meta($post_id, '_archive_reason', $reason);
    update_post_meta($post_id, '_archived_date', current_time('timestamp'));

    $post = get_post($post_id);

    if (!$post) {
        return 'post_not_found';
    }

    $content = $post->post_content;
    $excerpt = $post->post_excerpt;

    // FIX #1: Check if archive notice already exists to prevent duplicates
    $archive_notice_exists = strpos($content, '⚠️ Deal No Longer Available') !== false;

    if (!$archive_notice_exists) {
        // Strike through prices in content (only if not already done)
        $new_content = preg_replace(
            '/<span class="original-price">(.*?)<\/span><span class="discount-price">(.*?)<\/span>/',
            '<span class="original-price"><s>$1</s></span><span class="discount-price"><s>$2</s></span>',
            $content
        );

        // Strike through prices in excerpt (only if not already done)
        $new_excerpt = preg_replace(
            '/<span class="original-price">(.*?)<\/span> <span class="discount-price">(.*?)<\/span>/',
            '<span class="original-price"><s>$1</s></span> <span class="discount-price"><s>$2</s></span>',
            $excerpt
        );

        // Create user-friendly archive notice based on reason
        $user_message = '';
        switch ($reason) {
            case 'Out of stock':
                $user_message = 'This product is currently out of stock.';
                break;
            case 'Price increase':
                $user_message = 'This deal is no longer available due to price changes.';
                break;
            case 'Discount no longer available':
                $user_message = 'This discount is no longer available.';
                break;
            default:
                $user_message = 'This deal is no longer available.';
        }

        // Add clean notice at top of content (only once)
        $archive_notice = '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #dc3545;">
            <p style="margin: 0; font-weight: bold;">⚠️ Deal No Longer Available</p>
            <p style="margin: 5px 0 0 0;">' . esc_html($user_message) . '</p>
        </div>';

        $new_content = $archive_notice . $new_content;

        // Update the post content
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
            'post_excerpt' => $new_excerpt,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', 1)
        ));
    }

    // FIX #2: Properly handle category changes - REMOVE from active-deals and ADD to archive

    // Get current categories
    $current_categories = wp_get_post_categories($post_id);

    // Remove active-deals category (find by slug)
    $active_deals_cat = get_category_by_slug('active-deals');
    if ($active_deals_cat) {
        $current_categories = array_diff($current_categories, array($active_deals_cat->term_id));
        error_log('Removed active-deals category from post ' . $post_id);
    }

    // Add archive category if specified
    if ($archive_category_id > 0) {
        $current_categories[] = $archive_category_id;
        error_log('Added archive category ' . $archive_category_id . ' to post ' . $post_id);
    }

    // Update categories (this replaces all categories)
    wp_set_post_categories($post_id, $current_categories);

    error_log('Archived post ' . $post_id . ' with reason: ' . $reason . ' - removed from active-deals');

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

    // Convert to floats and round for display - this fixes the round() error
    $rounded_discount = round(floatval($discount_price));
    $rounded_original = round(floatval($original_price));

    // Update price in the content
    $new_content = preg_replace(
        '/<span class="original-price">(.*?)<\/span><span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price">' . $rounded_original . ' SEK</span><span class="discount-price">' . $rounded_discount . ' SEK</span>',
        $content
    );

    // Update price in the excerpt
    $new_excerpt = preg_replace(
        '/<span class="original-price">(.*?)<\/span> <span class="discount-price">(.*?)<\/span>/',
        '<span class="original-price">' . $rounded_original . ' SEK</span> <span class="discount-price">' . $rounded_discount . ' SEK</span>',
        $excerpt
    );

    // Update timestamp in content (price last checked)
    date_default_timezone_set("Europe/Stockholm");
    $new_date_block = '<p> **Price last checked ' . date('Y-m-d H:i') . ' CET </p>';

    // Replace existing date block
    $new_content = preg_replace(
        '/<p>\s*\*\*Price last checked.*?<\/p>/',
        $new_date_block,
        $new_content
    );

    // Update the post
    $updated_post = array(
        'ID' => $post_id,
        'post_content' => $new_content,
        'post_excerpt' => $new_excerpt
    );

    $result = wp_update_post($updated_post);

    if ($result) {
        error_log('Updated HTML prices for post ' . $post_id . ': ' . $rounded_discount . ' SEK / ' . $rounded_original . ' SEK');
    }

    return $result;
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

function update_post_timestamp_on_price_change($post_id)
{
    wp_update_post(array(
        'ID' => $post_id,
        'post_modified' => current_time('mysql'),
        'post_modified_gmt' => current_time('mysql', 1)
    ));

    error_log('Updated post timestamp for post ' . $post_id);
}


function update_discount_tag_replace_not_append($post_id, $discount_percentage)
{
    // Get all current tags
    $current_tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));

    // Remove any existing discount tags (anything ending with "% off")
    $clean_tags = array_filter($current_tags, function ($tag) {
        return !preg_match('/\d+% off$/i', $tag);
    });

    // Add new discount tag
    $discount_tag = $discount_percentage . '% off';
    $clean_tags[] = $discount_tag;

    // Update tags (replace all - false parameter)
    wp_set_post_terms($post_id, $clean_tags, 'post_tag', false);

    error_log("Replaced discount tag for post {$post_id}: {$discount_tag}");
}

function extract_prices_from_html($post_content, $post_excerpt)
{
    $prices = array('original' => 0, 'discount' => 0);

    // Try to extract from post content first
    $content_to_search = $post_content . ' ' . $post_excerpt;

    // Pattern 1: Standard price spans
    // <span class="original-price">100 SEK</span>
    // <span class="discount-price">50 SEK</span>
    if (preg_match('/<span class="original-price"[^>]*>([^<]+)<\/span>/', $content_to_search, $original_matches)) {
        $original_text = trim($original_matches[1]);
        // Extract numbers from "100 SEK" or "100.50 SEK" or "100"
        if (preg_match('/(\d+(?:\.\d+)?)/', $original_text, $original_number)) {
            $prices['original'] = floatval($original_number[1]);
        }
    }

    if (preg_match('/<span class="discount-price"[^>]*>([^<]+)<\/span>/', $content_to_search, $discount_matches)) {
        $discount_text = trim($discount_matches[1]);
        // Extract numbers from "50 SEK" or "50.25 SEK" or "50"
        if (preg_match('/(\d+(?:\.\d+)?)/', $discount_text, $discount_number)) {
            $prices['discount'] = floatval($discount_number[1]);
        }
    }

    // Pattern 2: Strikethrough prices (archived posts)
    // <span class="original-price"><s>100 SEK</s></span>
    if ($prices['original'] == 0) {
        if (preg_match('/<span class="original-price"[^>]*><s[^>]*>([^<]+)<\/s><\/span>/', $content_to_search, $original_matches)) {
            $original_text = trim($original_matches[1]);
            if (preg_match('/(\d+(?:\.\d+)?)/', $original_text, $original_number)) {
                $prices['original'] = floatval($original_number[1]);
            }
        }
    }

    if ($prices['discount'] == 0) {
        if (preg_match('/<span class="discount-price"[^>]*><s[^>]*>([^<]+)<\/s><\/span>/', $content_to_search, $discount_matches)) {
            $discount_text = trim($discount_matches[1]);
            if (preg_match('/(\d+(?:\.\d+)?)/', $discount_text, $discount_number)) {
                $prices['discount'] = floatval($discount_number[1]);
            }
        }
    }

    // Pattern 3: Double strikethrough (some archived posts)
    // <span class="original-price"><s><s>100 SEK</s></s></span>
    if ($prices['original'] == 0) {
        if (preg_match('/<span class="original-price"[^>]*><s[^>]*><s[^>]*>([^<]+)<\/s><\/s><\/span>/', $content_to_search, $original_matches)) {
            $original_text = trim($original_matches[1]);
            if (preg_match('/(\d+(?:\.\d+)?)/', $original_text, $original_number)) {
                $prices['original'] = floatval($original_number[1]);
            }
        }
    }

    if ($prices['discount'] == 0) {
        if (preg_match('/<span class="discount-price"[^>]*><s[^>]*><s[^>]*>([^<]+)<\/s><\/s><\/span>/', $content_to_search, $discount_matches)) {
            $discount_text = trim($discount_matches[1]);
            if (preg_match('/(\d+(?:\.\d+)?)/', $discount_text, $discount_number)) {
                $prices['discount'] = floatval($discount_number[1]);
            }
        }
    }

    return $prices;
}
