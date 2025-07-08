<?php

// Register the price updates tab and settings
add_action('admin_init', 'register_price_updates_settings');
function register_price_updates_settings()
{
    register_setting('price_updates_options', 'price_updates_options');
}

// Add tab to the existing AI Integration page
add_action('ai_integration_tabs', 'add_price_updates_tab');
function add_price_updates_tab()
{
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
?>
    <a href="?page=ai_integration&tab=price_updates" class="nav-tab <?php echo $active_tab == 'price_updates' ? 'nav-tab-active' : ''; ?>">Price Updates</a>
    <?php
}



function display_update_status()
{
    global $wpdb;

    $options = get_option('price_updates_options');
    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;

    // Calculate cutoff time
    $cutoff_time = current_time('timestamp') - ($update_frequency * HOUR_IN_SECONDS);

    // UPDATED: Count posts needing updates with dual-taxonomy filter
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        LEFT JOIN {$wpdb->postmeta} pm_check ON p.ID = pm_check.post_id AND pm_check.meta_key = '_last_price_check'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        AND (
            pm_check.meta_value IS NULL 
            OR pm_check.meta_value < %s
        )
    ", $cutoff_time));

    echo '<p><strong>Products needing update:</strong> ' . $count . '</p>';
    echo '<p><strong>Update frequency:</strong> Every ' . $update_frequency . ' hours</p>';
    echo '<p><strong>Last check:</strong> ' . (get_option('price_updates_last_check') ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), get_option('price_updates_last_check')) : 'Never') . '</p>';
}

// Generate URL list for scraper
add_action('wp_ajax_generate_price_update_urls', 'generate_price_update_urls_callback');
function generate_price_update_urls_callback()
{
    check_ajax_referer('generate_price_update_urls', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied.'));
    }

    $urls = generate_price_update_urls();

    wp_send_json_success(array(
        'urls' => $urls
    ));
}

function generate_price_update_urls()
{
    global $wpdb;

    $options = get_option('price_updates_options', array());
    $priority_options = get_option('price_update_priority_options', array());

    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;
    $max_urls = isset($priority_options['max_urls_per_request']) ? intval($priority_options['max_urls_per_request']) : 100;
    $priority_recent = isset($priority_options['priority_recent_posts']) ? intval($priority_options['priority_recent_posts']) : 1;
    $prevent_duplicates = isset($priority_options['prevent_duplicate_requests']) ? intval($priority_options['prevent_duplicate_requests']) : 1;
    $min_interval_hours = isset($priority_options['min_request_interval']) ? intval($priority_options['min_request_interval']) : 2;

    // Calculate cutoff times
    $cutoff_time = current_time('timestamp') - ($update_frequency * HOUR_IN_SECONDS);
    $recent_request_cutoff = current_time('timestamp') - ($min_interval_hours * HOUR_IN_SECONDS);

    // Get currently pending posts to exclude (if prevent_duplicates is enabled)
    $pending_updates = get_option('pending_price_updates', array());
    $exclude_pending_ids = array();

    if ($prevent_duplicates && !empty($pending_updates)) {
        foreach ($pending_updates as $post_id => $timestamp) {
            // Only exclude if request was recent (within min_interval_hours)
            if ($timestamp > $recent_request_cutoff) {
                $exclude_pending_ids[] = intval($post_id);
            }
        }
    }

    // Build exclusion clause
    $exclude_clause = '';
    if (!empty($exclude_pending_ids)) {
        $exclude_clause = 'AND p.ID NOT IN (' . implode(',', $exclude_pending_ids) . ')';
    }

    // UPDATED: Base query for posts needing updates - now includes both store_type AND category filters
    $base_query = "
        SELECT DISTINCT p.ID, p.post_date, pm_link.meta_value as amazon_url, pm_asin.meta_value as asin
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        INNER JOIN {$wpdb->postmeta} pm_link ON p.ID = pm_link.post_id AND pm_link.meta_key = '_Amazone_produt_link'
        INNER JOIN {$wpdb->postmeta} pm_asin ON p.ID = pm_asin.post_id AND pm_asin.meta_key = '_Amazone_produt_baseName'
        LEFT JOIN {$wpdb->postmeta} pm_check ON p.ID = pm_check.post_id AND pm_check.meta_key = '_last_price_check'
        LEFT JOIN {$wpdb->postmeta} pm_disable ON p.ID = pm_disable.post_id AND pm_disable.meta_key = '_disable_price_updates'
        LEFT JOIN {$wpdb->postmeta} pm_validation ON p.ID = pm_validation.post_id AND pm_validation.meta_key = '_needs_price_validation'
        LEFT JOIN {$wpdb->postmeta} pm_force ON p.ID = pm_force.post_id AND pm_force.meta_key = '_force_price_update'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        AND pm_link.meta_value IS NOT NULL
        AND pm_asin.meta_value IS NOT NULL
        AND (pm_disable.meta_value IS NULL OR pm_disable.meta_value != '1')
        AND (pm_validation.meta_value IS NULL OR pm_validation.meta_value != '1')
        AND (
            pm_force.meta_value = '1'
            OR pm_check.meta_value IS NULL 
            OR pm_check.meta_value < %s
        )
        {$exclude_clause}
    ";

    // Add ordering based on priority settings
    if ($priority_recent) {
        $thirty_days_ago = current_time('timestamp') - (30 * DAY_IN_SECONDS);
        $base_query .= " ORDER BY 
            CASE WHEN pm_force.meta_value = '1' THEN 1 ELSE 2 END,
            CASE WHEN UNIX_TIMESTAMP(p.post_date) > {$thirty_days_ago} THEN 1 ELSE 2 END,
            COALESCE(pm_check.meta_value, 0) ASC,
            p.post_date DESC
        ";
    } else {
        $base_query .= " ORDER BY 
            CASE WHEN pm_force.meta_value = '1' THEN 1 ELSE 2 END,
            COALESCE(pm_check.meta_value, 0) ASC,
            p.ID ASC
        ";
    }

    // Add limit
    $base_query .= " LIMIT {$max_urls}";

    // Execute query
    $results = $wpdb->get_results($wpdb->prepare($base_query, $cutoff_time));

    $urls = array();
    $post_ids_to_track = array();

    foreach ($results as $row) {
        $urls[] = array(
            'post_id' => intval($row->ID),
            'amazon_url' => $row->amazon_url,
            'asin' => $row->asin,
            'wordpress_url' => get_permalink($row->ID)
        );

        $post_ids_to_track[] = intval($row->ID);

        // Clear force update flag if it was set
        if (get_post_meta($row->ID, '_force_price_update', true) == '1') {
            delete_post_meta($row->ID, '_force_price_update');
        }
    }

    // Track these posts as pending
    if (!empty($post_ids_to_track)) {
        track_pending_scraper_updates($post_ids_to_track);
    }

    // Update last check time
    update_option('price_updates_last_check', current_time('timestamp'));

    // Log the generation
    error_log(sprintf(
        'Generated %d URLs for price updates (max: %d, excluded pending: %d, priority recent: %s)',
        count($urls),
        $max_urls,
        count($exclude_pending_ids),
        $priority_recent ? 'yes' : 'no'
    ));

    return $urls;
}

// Handle approval/rejection
add_action('admin_post_approve_price_update', 'handle_approve_price_update');
function handle_approve_price_update()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'approve_price_update')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied');
    }

    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if ($post_id > 0) {
        // Get pending data
        $pending_data = get_post_meta($post_id, '_pending_price_data', true);

        if (!empty($pending_data)) {
            $update_both = get_post_meta($post_id, '_update_both_prices', true);

            // Always update discount price
            if (isset($pending_data['discount_price'])) {
                $new_discount_price = floatval($pending_data['discount_price']);
                update_post_meta($post_id, '_discount_price', $new_discount_price);
            }

            // Update original price if setting allows
            if ($update_both && isset($pending_data['original_price'])) {
                $new_original_price = floatval($pending_data['original_price']);
                update_post_meta($post_id, '_original_price', $new_original_price);
            }

            // Update price sources
            if (isset($pending_data['price_sources'])) {
                update_post_meta($post_id, '_price_sources', $pending_data['price_sources']);
            }

            // Update last price check timestamp
            update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

            // ✅ ADD DISCOUNT PERCENTAGE AND TAG UPDATE
            $current_discount = floatval($pending_data['discount_price']);
            $current_original = get_post_meta($post_id, '_original_price', true);

            // If we updated both prices, use the new original price
            if ($update_both && isset($pending_data['original_price'])) {
                $current_original = floatval($pending_data['original_price']);
            } else {
                $current_original = floatval($current_original);
            }

            // Calculate and update discount percentage
            if ($current_original > 0 && $current_discount > 0 && $current_original > $current_discount) {
                $discount_percentage = (($current_original - $current_discount) / $current_original) * 100;
                $discount_percentage = round($discount_percentage);

                // Update discount percentage meta
                update_post_meta($post_id, '_discount_percentage', $discount_percentage);

                // Update discount tag
                $discount_tag_title = $discount_percentage . '% off';

                // Remove old discount tags first
                $existing_tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));
                $tags_to_keep = array();

                foreach ($existing_tags as $tag_name) {
                    // Keep tags that don't match the "X% off" pattern
                    if (!preg_match('/^\d+% off$/', $tag_name)) {
                        $tags_to_keep[] = $tag_name;
                    }
                }

                // Add the new discount tag
                $tags_to_keep[] = $discount_tag_title;

                // Set the updated tags
                wp_set_post_terms($post_id, $tags_to_keep, 'post_tag', false);

                error_log("Manual approval: Updated discount tag for post {$post_id} to '{$discount_tag_title}'");
            }

            // Clear pending data
            delete_post_meta($post_id, '_pending_price_data');
            delete_post_meta($post_id, '_needs_price_validation');

            // Update HTML content with new prices
            update_post_html_with_new_prices($post_id);
        }
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=approved'));
    exit;
}

function update_discount_percentage_and_tag($post_id)
{
    $discount_price = get_post_meta($post_id, '_discount_price', true);
    $original_price = get_post_meta($post_id, '_original_price', true);

    $discount_price = floatval($discount_price);
    $original_price = floatval($original_price);

    if ($original_price > 0 && $discount_price > 0 && $original_price > $discount_price) {
        $discount_percentage = (($original_price - $discount_price) / $original_price) * 100;
        $discount_percentage = round($discount_percentage);

        // Update discount percentage meta
        update_post_meta($post_id, '_discount_percentage', $discount_percentage);

        // Update discount tag
        $discount_tag_title = $discount_percentage . '% off';

        // Remove old discount tags first
        $existing_tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));
        $tags_to_keep = array();

        foreach ($existing_tags as $tag_name) {
            // Keep tags that don't match the "X% off" pattern
            if (!preg_match('/^\d+% off$/', $tag_name)) {
                $tags_to_keep[] = $tag_name;
            }
        }

        // Add the new discount tag
        $tags_to_keep[] = $discount_tag_title;

        // Set the updated tags
        wp_set_post_terms($post_id, $tags_to_keep, 'post_tag', false);

        return $discount_percentage;
    }

    return 0;
}

add_action('admin_post_reject_price_update', 'handle_reject_price_update');
function handle_reject_price_update()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'reject_price_update')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied');
    }

    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if ($post_id > 0) {
        // ARCHIVE THE POST (this is the missing part)
        $options = get_option('price_updates_options', array());
        $archive_category = isset($options['archive_category']) ? intval($options['archive_category']) : 0;
        archive_post($post_id, 'Price update rejected - archived by admin', $archive_category);

        // Clear pending data
        delete_post_meta($post_id, '_pending_price_data');
        delete_post_meta($post_id, '_needs_price_validation');

        // Update last check time to prevent immediate re-check
        update_post_meta($post_id, '_last_price_check', current_time('timestamp'));
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=rejected'));
    exit;
}

// Generate API key on plugin activation
add_action('admin_init', 'ensure_price_update_api_key');
function ensure_price_update_api_key()
{
    if (!get_option('price_update_api_key')) {
        $api_key = wp_generate_password(32, false, false); // 32 chars, no special chars
        update_option('price_update_api_key', $api_key);
        error_log('Generated new price update API key: ' . $api_key);
    }
}

// API Key verification function
function verify_price_update_api_key($request)
{
    $provided_key = $request->get_header('X-API-Key');
    $stored_key = get_option('price_update_api_key');

    error_log('API Key verification attempt. Provided: ' . ($provided_key ? 'Yes' : 'No') . ', Stored: ' . ($stored_key ? 'Yes' : 'No'));

    if (empty($provided_key)) {
        error_log('API Key missing from request');
        return new WP_Error('no_api_key', 'API key required in X-API-Key header', array('status' => 401));
    }

    if (empty($stored_key)) {
        error_log('No API key configured in WordPress');
        return new WP_Error('no_stored_key', 'API key not configured', array('status' => 500));
    }

    if (!hash_equals($stored_key, $provided_key)) {
        error_log('Invalid API key provided: ' . substr($provided_key, 0, 8) . '...');
        return new WP_Error('invalid_api_key', 'Invalid API key', array('status' => 403));
    }

    error_log('API key verification successful');
    return true;
}


function check_price_update_rate_limit($request)
{
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_limit_key = 'price_update_rate_' . md5($client_ip);
    $requests = get_transient($rate_limit_key) ?: 0;

    $hourly_limit = 500;

    if ($requests >= $hourly_limit) {
        error_log('Rate limit exceeded for IP: ' . $client_ip . ' (Requests: ' . $requests . ')');
        return new WP_Error('rate_limit', 'Rate limit exceeded. Maximum ' . $hourly_limit . ' requests per hour.', array('status' => 429));
    }

    set_transient($rate_limit_key, ($requests + 1), HOUR_IN_SECONDS);

    return true;
}


function secure_price_update_permission($request)
{
    // Check API key first
    $api_check = verify_price_update_api_key($request);
    if (is_wp_error($api_check)) {
        return $api_check;
    }

    // Check rate limit
    $rate_check = check_price_update_rate_limit($request);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    return true;
}

add_action('rest_api_init', 'register_secure_price_update_api');
function register_secure_price_update_api()
{
    error_log('Registering secure price update REST API routes');


    $url_route = register_rest_route('auto-workflows/v1', '/price-update-urls', array(
        'methods' => 'GET',
        'callback' => 'get_price_update_urls_api',
        'permission_callback' => '__return_true',
        'args' => array()
    ));

    $update_route = register_rest_route('auto-workflows/v1', '/update-price', array(
        'methods' => 'POST',
        'callback' => 'update_price_api_secure',
        'permission_callback' => 'secure_price_update_permission', // Add security here
        'args' => array(
            'post_id' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'price_data' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_array($param);
                }
            )
        )
    ));

    register_rest_route('auto-workflows/v1', '/test', array(
        'methods' => 'GET',
        'callback' => function () {
            return new WP_REST_Response(array(
                'status' => 'success',
                'message' => 'Auto Workflows REST API is working',
                'timestamp' => current_time('timestamp'),
                'security' => 'API key required for /update-price endpoint'
            ), 200);
        },
        'permission_callback' => '__return_true'
    ));

    // Log registration results
    if ($url_route && $update_route) {
        error_log('Successfully registered all secure price update routes');
    } else {
        error_log('Failed to register some price update routes');
    }
}

function update_price_api_secure($request)
{
    try {
        $start_time = microtime(true);
        error_log('Secure price update API endpoint called');

        $params = $request->get_params();

        if (!isset($params['post_id']) || !isset($params['price_data'])) {
            error_log('Missing required parameters in price update request');
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Missing required parameters: post_id and price_data'
            ), 400);
        }

        $post_id = intval($params['post_id']);
        $price_data = $params['price_data'];

        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            error_log('Price update requested for non-existent post: ' . $post_id);
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Post does not exist',
                'post_id' => $post_id
            ), 404);
        }

        error_log('Processing secure price update for post ' . $post_id . ' with data: ' . json_encode($price_data));

        // Process the price update (using existing function)
        $result = process_price_update_api($post_id, $price_data);

        remove_from_pending_updates($post_id);
        error_log('DEBUG - scraper_errors check: ' . print_r($params['scraper_errors'] ?? 'NOT SET', true));

        // Log any errors from scraper
        if (isset($params['scraper_errors']) && !empty($params['scraper_errors'])) {
            log_price_update_error($post_id, 'Scraper reported issues', $params['scraper_errors']);
        }

        // Record the update attempt
        update_post_meta($post_id, '_last_price_update_attempt', current_time('timestamp'));

        $processing_time = round((microtime(true) - $start_time) * 1000, 2);
        error_log('Secure price update completed for post ' . $post_id . ' with result: ' . $result . ' (Processing time: ' . $processing_time . 'ms)');

        return new WP_REST_Response(array(
            'success' => true,
            'post_id' => $post_id,
            'result' => $result,
            'message' => 'Price update processed successfully',
            'processing_time_ms' => $processing_time
        ), 200);
    } catch (Exception $e) {
        error_log('Exception in secure price update API: ' . $e->getMessage());

        // Log the error if we have a post_id
        if (isset($post_id)) {
            log_price_update_error($post_id, $e->getMessage());
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Exception during price update',
            'message' => $e->getMessage(),
            'post_id' => isset($post_id) ? $post_id : null
        ), 500);
    }
}

function get_price_update_urls_api()
{
    $urls = generate_price_update_urls();
    $post_ids = array_column($urls, 'post_id');
    track_pending_scraper_updates($post_ids);

    return new WP_REST_Response($urls, 200);
}

function update_price_api($request)
{
    $params = $request->get_params();

    if (!isset($params['post_id']) || !isset($params['price_data'])) {
        return new WP_REST_Response(array('error' => 'Missing required parameters'), 400);
    }

    $post_id = intval($params['post_id']);
    $price_data = $params['price_data'];

    $post = get_post($post_id);
    if (!$post) {
        return new WP_REST_Response(array(
            'error' => 'Post does not exist',
            'post_id' => $post_id
        ), 404);
    }

    try {
        // Process the price update
        $result = process_price_update_api($post_id, $price_data);

        remove_from_pending_updates($post_id);
        error_log('DEBUG - scraper_errors check: ' . print_r($params['scraper_errors'] ?? 'NOT SET', true));

        // Log any errors from scraper
        if (isset($params['scraper_errors']) && !empty($params['scraper_errors'])) {
            log_price_update_error($post_id, 'Scraper reported issues', $params['scraper_errors']);
        }

        // Record the update attempt
        update_post_meta($post_id, '_last_price_update_attempt', current_time('timestamp'));

        return new WP_REST_Response(array(
            'post_id' => $post_id,
            'result' => $result
        ), 200);
    } catch (Exception $e) {
        // Log the error
        log_price_update_error($post_id, $e->getMessage());

        return new WP_REST_Response(array(
            'error' => 'Exception during price update',
            'message' => $e->getMessage(),
            'post_id' => $post_id
        ), 500);
    }
}

function display_pending_approval_products()
{
    // Get sorting preference
    $sort_by = isset($_GET['approval_sort']) ? sanitize_text_field($_GET['approval_sort']) : 'timestamp_desc';

    // Base query
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_needs_price_validation',
                'value' => '1',
                'compare' => '='
            )
        )
    );

    // Add ordering based on selection
    switch ($sort_by) {
        case 'timestamp_desc':
            $args['meta_key'] = '_pending_price_data';
            $args['orderby'] = 'meta_value';
            $args['order'] = 'DESC';
            break;
        case 'timestamp_asc':
            $args['meta_key'] = '_pending_price_data';
            $args['orderby'] = 'meta_value';
            $args['order'] = 'ASC';
            break;
        case 'post_date_desc':
            $args['orderby'] = 'date';
            $args['order'] = 'DESC';
            break;
        case 'post_date_asc':
            $args['orderby'] = 'date';
            $args['order'] = 'ASC';
            break;
        case 'title_asc':
            $args['orderby'] = 'title';
            $args['order'] = 'ASC';
            break;
        case 'title_desc':
            $args['orderby'] = 'title';
            $args['order'] = 'DESC';
            break;
        default:
            // Default to timestamp desc
            $args['meta_key'] = '_pending_price_data';
            $args['orderby'] = 'meta_value';
            $args['order'] = 'DESC';
    }

    $approval_query = new WP_Query($args);

    // For custom sorting by price change magnitude, we'll need to sort after query
    $posts_with_changes = array();
    if ($approval_query->have_posts()) {
        while ($approval_query->have_posts()) {
            $approval_query->the_post();
            $post_id = get_the_ID();
            $pending_data = get_post_meta($post_id, '_pending_price_data', true);

            if ($pending_data) {
                $discount_change = isset($pending_data['discount_price_change']) ? abs(floatval($pending_data['discount_price_change'])) : 0;
                $posts_with_changes[] = array(
                    'post_id' => $post_id,
                    'change_magnitude' => $discount_change,
                    'pending_data' => $pending_data
                );
            }
        }
        wp_reset_postdata();

        // Sort by change magnitude if requested
        if ($sort_by === 'change_magnitude_desc') {
            usort($posts_with_changes, function ($a, $b) {
                return $b['change_magnitude'] <=> $a['change_magnitude'];
            });
        } elseif ($sort_by === 'change_magnitude_asc') {
            usort($posts_with_changes, function ($a, $b) {
                return $a['change_magnitude'] <=> $b['change_magnitude'];
            });
        }
    }

    if (!empty($posts_with_changes)) {
    ?>
        <div style="background: #fff3cd; padding: 10px; margin-bottom: 15px; border-left: 4px solid #ffc107;">
            <strong>⚠️ Important:</strong> Posts awaiting approval are excluded from price update requests until resolved.
        </div>

        <!-- Sorting Controls -->
        <div style="background: #f8f9fa; padding: 12px; margin-bottom: 15px; border: 1px solid #dee2e6; border-radius: 4px;">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <strong>📊 Sort by:</strong>
                <form method="get" style="display: inline-flex; align-items: center; gap: 10px; margin: 0;">
                    <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>" />
                    <input type="hidden" name="tab" value="<?php echo esc_attr($_GET['tab']); ?>" />
                    <select name="approval_sort" onchange="this.form.submit()" style="padding: 4px 8px;">
                        <option value="timestamp_desc" <?php selected($sort_by, 'timestamp_desc'); ?>>⏰ Newest Requests First</option>
                        <option value="timestamp_asc" <?php selected($sort_by, 'timestamp_asc'); ?>>⏰ Oldest Requests First</option>
                        <option value="change_magnitude_desc" <?php selected($sort_by, 'change_magnitude_desc'); ?>>📈 Largest Changes First</option>
                        <option value="change_magnitude_asc" <?php selected($sort_by, 'change_magnitude_asc'); ?>>📉 Smallest Changes First</option>
                        <option value="post_date_desc" <?php selected($sort_by, 'post_date_desc'); ?>>📅 Newest Posts First</option>
                        <option value="post_date_asc" <?php selected($sort_by, 'post_date_asc'); ?>>📅 Oldest Posts First</option>
                        <option value="title_asc" <?php selected($sort_by, 'title_asc'); ?>>🔤 Title A-Z</option>
                        <option value="title_desc" <?php selected($sort_by, 'title_desc'); ?>>🔤 Title Z-A</option>
                    </select>
                    <span style="color: #666; font-size: 12px;">
                        (<?php echo count($posts_with_changes); ?> items)
                    </span>
                </form>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 18%;">Post Title</th>
                    <th style="width: 15%;">Price Comparison</th>
                    <th style="width: 10%;">Current Deal</th>
                    <th style="width: 10%;">New Deal</th>
                    <th style="width: 8%;">Change</th>
                    <th style="width: 12%;">Source</th>
                    <th style="width: 10%;">Date</th>
                    <th style="width: 17%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Use sorted posts array instead of WP_Query loop
                foreach ($posts_with_changes as $post_data) {
                    $post_id = $post_data['post_id'];
                    $pending_data = $post_data['pending_data'];

                    $post = get_post($post_id);
                    if (!$post) continue;

                    // Get price data with float conversion
                    $current_discount = isset($pending_data['previous_discount_price']) ? floatval($pending_data['previous_discount_price']) : 0;
                    $new_discount = isset($pending_data['discount_price']) ? floatval($pending_data['discount_price']) : 0;
                    $current_original = isset($pending_data['previous_original_price']) ? floatval($pending_data['previous_original_price']) : 0;
                    $new_original = isset($pending_data['original_price']) ? floatval($pending_data['original_price']) : 0;

                    // Calculate discount percentages
                    $current_discount_percent = 0;
                    if ($current_original > 0 && $current_discount > 0) {
                        $current_discount_percent = round((($current_original - $current_discount) / $current_original) * 100);
                    }

                    $new_discount_percent = 0;
                    if ($new_original > 0 && $new_discount > 0) {
                        $new_discount_percent = round((($new_original - $new_discount) / $new_original) * 100);
                    }

                    // Get price change
                    $discount_change = isset($pending_data['discount_price_change']) ? floatval($pending_data['discount_price_change']) : 0;
                    $price_change_class = $discount_change < 0 ? 'price-decrease' : 'price-increase';
                    $price_change_formatted = ($discount_change >= 0 ? '+' : '') . number_format($discount_change, 1) . '%';

                    // Get sources
                    $price_sources = isset($pending_data['price_sources']) ? $pending_data['price_sources'] : array();
                    $discount_source = isset($price_sources['discount_price_source']) ? $price_sources['discount_price_source'] : 'unknown';
                    $original_source = isset($price_sources['original_price_source']) ? $price_sources['original_price_source'] : 'unknown';

                    // Get timestamp
                    $timestamp = isset($pending_data['timestamp']) ? $pending_data['timestamp'] : 0;
                    $date_formatted = $timestamp ? date_i18n('M j, H:i', $timestamp) : '';

                    // Determine if this is a good or bad change
                    $deal_getting_better = $new_discount_percent >= $current_discount_percent;
                    $row_class = $deal_getting_better ? 'good-deal' : 'bad-deal';
                ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">
                                <?php echo esc_html($post->post_title); ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-size: 11px; line-height: 1.3;">
                                <strong>Before:</strong><br>
                                <?php echo number_format($current_discount, 0); ?> / <?php echo number_format($current_original, 0); ?> SEK<br>
                                <strong>After:</strong><br>
                                <?php echo number_format($new_discount, 0); ?> / <?php echo number_format($new_original, 0); ?> SEK
                            </div>
                        </td>
                        <td>
                            <?php if ($current_discount_percent > 0): ?>
                                <strong><?php echo $current_discount_percent; ?>% off</strong>
                            <?php else: ?>
                                <span style="color: #666;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($new_discount_percent > 0): ?>
                                <strong style="color: <?php echo $deal_getting_better ? 'green' : 'red'; ?>;">
                                    <?php echo $new_discount_percent; ?>% off
                                </strong>
                                <?php if ($deal_getting_better): ?>
                                    <span style="color: green;">📈</span>
                                <?php else: ?>
                                    <span style="color: red;">📉</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #666;">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?php echo esc_attr($price_change_class); ?>">
                            <?php echo esc_html($price_change_formatted); ?>
                        </td>
                        <td>
                            <div style="font-size: 11px;">
                                <strong>Discount:</strong> <?php echo esc_html(strtoupper(str_replace('amazon.', '', $discount_source))); ?><br>
                                <strong>Original:</strong> <?php echo esc_html(strtoupper(str_replace('amazon.', '', $original_source))); ?>
                            </div>
                        </td>
                        <td><?php echo esc_html($date_formatted); ?></td>
                        <td>
                            <?php if ($deal_getting_better): ?>
                                <div style="background: #d4edda; padding: 5px; border-radius: 3px; margin-bottom: 5px;">
                                    <small style="color: #155724; font-weight: bold;">✓ Deal Improving</small>
                                </div>
                            <?php else: ?>
                                <div style="background: #f8d7da; padding: 5px; border-radius: 3px; margin-bottom: 5px;">
                                    <small style="color: #721c24; font-weight: bold;">⚠ Deal Worsening</small>
                                </div>
                            <?php endif; ?>

                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=approve_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('approve_price_update'))); ?>"
                                class="button-primary button-small" style="margin-bottom: 2px; <?php echo $deal_getting_better ? '' : 'background: #28a745; border-color: #28a745;'; ?>">
                                ✓ Approve
                            </a><br>
                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=reject_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('reject_price_update'))); ?>"
                                class="button button-small" style="<?php echo !$deal_getting_better ? 'background: #dc3545; color: white; border-color: #dc3545;' : ''; ?>">
                                ✗ Reject & Archive
                            </a>
                        </td>
                    </tr>
                <?php
                }
                ?>
            </tbody>
        </table>

        <style>
            .good-deal {
                background-color: #f8fff8 !important;
            }

            .bad-deal {
                background-color: #fff8f8 !important;
            }

            .price-decrease {
                color: #28a745;
                font-weight: bold;
            }

            .price-increase {
                color: #dc3545;
                font-weight: bold;
            }
        </style>

        <div style="margin-top: 15px; background: #f0f8ff; padding: 15px; border-left: 4px solid #0073aa;">
            <h4 style="margin-top: 0;">📊 How to Read This Table:</h4>
            <div style="display: flex; gap: 30px;">
                <div style="flex: 1;">
                    <h5>Price Comparison:</h5>
                    <ul style="margin: 5px 0 0 20px; font-size: 13px;">
                        <li><strong>Before:</strong> Current discount / original prices</li>
                        <li><strong>After:</strong> New discount / original prices</li>
                        <li><strong>Deal %:</strong> Discount percentage (higher = better deal)</li>
                    </ul>
                </div>
                <div style="flex: 1;">
                    <h5>Visual Indicators:</h5>
                    <ul style="margin: 5px 0 0 20px; font-size: 13px;">
                        <li><span style="color: green;">📈 Green rows:</span> Deal is improving</li>
                        <li><span style="color: red;">📉 Red rows:</span> Deal is getting worse</li>
                        <li><strong>Source:</strong> Which Amazon store provided each price</li>
                    </ul>
                </div>
            </div>

            <div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                <h5 style="margin: 0 0 5px 0;">💡 Decision Guidelines:</h5>
                <ul style="margin: 5px 0 0 20px; font-size: 13px;">
                    <li><strong>Approve if:</strong> New deal is still 60%+ off OR deal is improving</li>
                    <li><strong>Reject if:</strong> New deal is less than 40% off AND deal is worsening</li>
                    <li><strong>Check sources:</strong> Ensure discount price is from SE (SEK) when possible</li>
                </ul>
            </div>
        </div>

        <div style="margin-top: 10px;">
            <p><strong>Actions:</strong></p>
            <ul>
                <li><strong>✓ Approve:</strong> Accept the new prices and update the post</li>
                <li><strong>✗ Reject & Archive:</strong> Move post to archive category with "deal no longer available" message</li>
            </ul>
        </div>
        <?php
    } else {
        echo '<p>✅ No products currently need approval.</p>';
    }
}

/**
 * Display recent price updates
 */
function display_recent_price_updates()
{
    global $wpdb;

    // UPDATED: Get recent updates with dual-taxonomy filter
    $recent_post_ids = $wpdb->get_col("
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_price_check'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        ORDER BY pm.meta_value DESC
        LIMIT 10
    ");

    if (!empty($recent_post_ids)) {
        // Use standard WP_Query with post__in for the final display
        $args = array(
            'post_type' => 'post',
            'post__in' => $recent_post_ids,
            'orderby' => 'post__in',
            'posts_per_page' => 10
        );

        $recent_updates = new WP_Query($args);

        if ($recent_updates->have_posts()) {
        ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Post Title</th>
                        <th>Current Price</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    while ($recent_updates->have_posts()) {
                        $recent_updates->the_post();
                        $post_id = get_the_ID();

                        $current_price = get_post_meta($post_id, '_discount_price', true);
                        $last_check = get_post_meta($post_id, '_last_price_check', true);
                        $last_check_formatted = $last_check ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_check) : 'Never';
                    ?>
                        <tr>
                            <td><a href="<?php echo get_edit_post_link($post_id); ?>"><?php the_title(); ?></a></td>
                            <td><?php echo number_format($current_price, 2); ?> SEK</td>
                            <td><?php echo $last_check_formatted; ?></td>
                            <td>
                                <a href="<?php echo get_permalink($post_id); ?>" class="button" target="_blank">View Post</a>
                                <a href="<?php echo admin_url('admin-post.php?action=force_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('force_price_update')); ?>" class="button">Check Now</a>
                            </td>
                        </tr>
                    <?php
                    }
                    ?>
                </tbody>
            </table>
    <?php
        } else {
            echo '<p>No recent price updates found.</p>';
        }

        wp_reset_postdata();
    } else {
        echo '<p>No recent price updates found in active-deals category.</p>';
    }
}


/**
 * Add action handler for forcing price updates
 */
add_action('admin_post_force_price_update', 'handle_force_price_update');
function handle_force_price_update()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'force_price_update')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied');
    }

    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if ($post_id > 0) {
        // Clear the last price check timestamp to force update on next cron run
        delete_post_meta($post_id, '_last_price_check');

        // Add a flag that this post should be updated on next cron run
        update_post_meta($post_id, '_force_price_update', 1);
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=forced'));
    exit;
}


add_action('admin_head', 'price_updates_admin_css');
function price_updates_admin_css()
{
    ?>
    <style>
        .price-decrease {
            color: #28a745;
            font-weight: bold;
        }

        .price-increase {
            color: #dc3545;
            font-weight: bold;
        }

        .form-table th {
            width: 250px;
        }
    </style>
<?php
}

function track_pending_scraper_updates($post_ids)
{
    if (empty($post_ids) || !is_array($post_ids)) {
        return;
    }

    $pending_updates = get_option('pending_price_updates', array());

    $cutoff = current_time('timestamp') - (24 * HOUR_IN_SECONDS);
    foreach ($pending_updates as $id => $time) {
        if ($time < $cutoff) {
            unset($pending_updates[$id]);
        }
    }

    foreach ($post_ids as $post_id) {
        $pending_updates[$post_id] = current_time('timestamp');
    }

    update_option('pending_price_updates', $pending_updates);

    error_log('Tracked ' . count($post_ids) . ' new pending updates. Total pending: ' . count($pending_updates));
}


function remove_from_pending_updates($post_id)
{
    $pending_updates = get_option('pending_price_updates', array());

    if (isset($pending_updates[$post_id])) {
        unset($pending_updates[$post_id]);
        update_option('pending_price_updates', $pending_updates);
        error_log('Removed post ' . $post_id . ' from pending updates. Remaining: ' . count($pending_updates));
    }
}

function cleanup_old_pending_updates()
{
    $pending_updates = get_option('pending_price_updates', array());
    $cutoff = current_time('timestamp') - (24 * HOUR_IN_SECONDS);
    $cleaned = 0;

    foreach ($pending_updates as $id => $time) {
        if ($time < $cutoff) {
            unset($pending_updates[$id]);
            $cleaned++;
        }
    }

    if ($cleaned > 0) {
        update_option('pending_price_updates', $pending_updates);
        error_log('Cleaned up ' . $cleaned . ' old pending updates. Remaining: ' . count($pending_updates));
    }

    return $cleaned;
}

add_action('admin_post_clear_all_pending_updates', 'handle_clear_all_pending_updates');
function handle_clear_all_pending_updates()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'clear_all_pending_updates')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Permission denied');
    }

    update_option('pending_price_updates', array());

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=pending_cleared'));
    exit;
}

add_action('admin_notices', 'price_updates_admin_notices');
function price_updates_admin_notices()
{
    // Only show on certain screens
    $screen = get_current_screen();
    if (!($screen->id === 'dashboard' || $screen->id === 'edit-post' || $screen->id === 'settings_page_ai_integration')) {
        return;
    }

    // Check for pending updates
    $pending_updates = get_option('pending_price_updates', array());
    $pending_count = count($pending_updates);

    // Check for errors - but only show if there are actually errors to report
    $error_args = array(
        'post_type' => 'post',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_price_update_errors',
        'meta_compare' => 'EXISTS',
    );

    $error_query = new WP_Query($error_args);
    $error_count = $error_query->found_posts;

    // Only show notice if we have something to report
    if ($pending_count === 0 && $error_count === 0) {
        return;
    }

?>
    <div class="notice notice-info is-dismissible">
        <p>
            <strong>Price Update Status (Active-Deals Only):</strong>
            <?php if ($pending_count > 0): ?>
                There <?php echo $pending_count === 1 ? 'is' : 'are'; ?>
                <strong><?php echo $pending_count; ?></strong> pending price update request<?php echo $pending_count === 1 ? '' : 's'; ?>.
            <?php endif; ?>

            <?php if ($error_count > 0): ?>
                There <?php echo $error_count === 1 ? 'is' : 'are'; ?>
                <strong><?php echo $error_count; ?></strong> post<?php echo $error_count === 1 ? '' : 's'; ?> with price update errors.
            <?php endif; ?>
        </p>
        <p>
            <a href="<?php echo admin_url('options-general.php?page=ai_integration&tab=price_updates'); ?>" class="button button-primary">
                View Price Update Status
            </a>
        </p>
    </div>
<?php
}

function display_price_update_errors()
{
    global $wpdb;

    // UPDATED: Get error posts that are also in active-deals category
    $error_post_ids = $wpdb->get_col("
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_price_update_errors'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        ORDER BY p.post_modified DESC
        LIMIT 10
    ");

    if (empty($error_post_ids)) {
        echo '<p>No recent price update errors found in active-deals posts.</p>';
        return;
    }

    // Use WP_Query for the final display
    $args = array(
        'post_type' => 'post',
        'post__in' => $error_post_ids,
        'orderby' => 'post__in',
        'posts_per_page' => 10
    );

    $error_query = new WP_Query($args);

?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Post</th>
                <th>Last Error</th>
                <th>Time</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($error_query->have_posts()): $error_query->the_post();
                $post_id = get_the_ID();
                $errors = get_post_meta($post_id, '_price_update_errors', true);

                if (!is_array($errors) || empty($errors)) continue;

                $latest_error = end($errors);
                $error_time = isset($latest_error['timestamp']) ? $latest_error['timestamp'] : 0;
                $error_message = isset($latest_error['message']) ? $latest_error['message'] : 'Unknown error';
            ?>
                <tr>
                    <td><a href="<?php echo get_edit_post_link($post_id); ?>"><?php the_title(); ?></a></td>
                    <td><?php echo esc_html($error_message); ?></td>
                    <td><?php echo $error_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $error_time) : 'Unknown'; ?></td>
                    <td>
                        <a href="<?php echo admin_url('admin-post.php?action=force_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('force_price_update')); ?>" class="button">Retry Update</a>
                        <a href="<?php echo admin_url('admin-post.php?action=clear_price_errors&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('clear_price_errors')); ?>" class="button">Clear Errors</a>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php

    wp_reset_postdata();
}

// Add handler for clearing errors
add_action('admin_post_clear_price_errors', 'handle_clear_price_errors');
function handle_clear_price_errors()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'clear_price_errors')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied');
    }

    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if ($post_id > 0) {
        delete_post_meta($post_id, '_price_update_errors');
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=errors_cleared'));
    exit;
}

add_action('ai_integration_tab_content', 'price_updates_admin_tools');
function price_updates_admin_tools()
{
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';

    if ($active_tab != 'price_updates') {
        return;
    }

    // Handle reset actions
    if (isset($_POST['reset_all_timestamps']) && wp_verify_nonce($_POST['admin_tools_nonce'], 'admin_tools_action')) {
        reset_all_price_check_timestamps();
        echo '<div class="updated fade"><p><strong>✅ All price check timestamps reset. Next scraper run will be clean.</strong></p></div>';
    }

    if (isset($_POST['reset_pending_only']) && wp_verify_nonce($_POST['admin_tools_nonce'], 'admin_tools_action')) {
        update_option('pending_price_updates', array());
        echo '<div class="updated fade"><p><strong>✅ Pending updates list cleared.</strong></p></div>';
    }

    if (isset($_POST['fix_missing_timestamps']) && wp_verify_nonce($_POST['admin_tools_nonce'], 'admin_tools_action')) {
        $fixed_count = fix_missing_price_check_timestamps();
        echo '<div class="updated fade"><p><strong>✅ Added timestamps to ' . $fixed_count . ' posts that were missing them.</strong></p></div>';
    }

?>
    <hr>
    <h2>🛠️ Admin Tools & Priority Settings</h2>

    <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
        <h3 style="margin-top: 0;">⚠️ Diagnostic Information</h3>
        <?php display_priority_diagnostics(); ?>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field('admin_tools_action', 'admin_tools_nonce'); ?>

        <h3>🔄 One-Time Reset Tools</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Complete Reset</th>
                <td>
                    <input type="submit" name="reset_all_timestamps" class="button button-secondary"
                        value="🔄 Reset All Price Check Timestamps"
                        onclick="return confirm('This will mark ALL Amazon posts as recently checked, preventing them from being scraped until their next scheduled update. Continue?')" />
                    <p class="description">
                        <strong>Use when:</strong> You have too many stale pending updates and want a clean slate.<br>
                        <strong>Effect:</strong> All posts will be excluded from next scraper run. Useful for fixing the 1200+ pending issue.
                    </p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Clear Pending Only</th>
                <td>
                    <input type="submit" name="reset_pending_only" class="button button-secondary"
                        value="🗑️ Clear Pending Updates List" />
                    <p class="description">
                        <strong>Use when:</strong> You want to clear the pending list but keep normal update schedule.<br>
                        <strong>Effect:</strong> Clears tracking but posts may immediately re-appear if they need updates.
                    </p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Fix Missing Data</th>
                <td>
                    <input type="submit" name="fix_missing_timestamps" class="button button-secondary"
                        value="🔧 Add Missing Timestamps" />
                    <p class="description">
                        <strong>Use when:</strong> Some posts are missing _last_price_check metadata.<br>
                        <strong>Effect:</strong> Adds current timestamp to posts missing this data.
                    </p>
                </td>
            </tr>
        </table>
    </form>

    <hr>

    <h3>⚙️ Priority Settings</h3>
    <form method="post" action="">
        <?php wp_nonce_field('priority_settings_action', 'priority_settings_nonce'); ?>

        <?php
        // Handle priority settings save
        if (isset($_POST['save_priority_settings']) && wp_verify_nonce($_POST['priority_settings_nonce'], 'priority_settings_action')) {
            $priority_options = isset($_POST['priority_options']) ? $_POST['priority_options'] : array();

            // FIXED: Explicitly handle checkboxes (they don't send values when unchecked)
            $cleaned_options = array();
            $cleaned_options['max_urls_per_request'] = isset($priority_options['max_urls_per_request']) ? intval($priority_options['max_urls_per_request']) : 100;
            $cleaned_options['min_request_interval'] = isset($priority_options['min_request_interval']) ? intval($priority_options['min_request_interval']) : 2;

            // For checkboxes: if key exists in POST data = checked (1), if not exists = unchecked (0)
            $cleaned_options['priority_recent_posts'] = isset($priority_options['priority_recent_posts']) ? 1 : 0;
            $cleaned_options['prevent_duplicate_requests'] = isset($priority_options['prevent_duplicate_requests']) ? 1 : 0;

            update_option('price_update_priority_options', $cleaned_options);
            echo '<div class="updated fade"><p><strong>Priority settings saved.</strong></p></div>';
        }

        $priority_options = get_option('price_update_priority_options', array());
        $max_urls_per_request = isset($priority_options['max_urls_per_request']) ? intval($priority_options['max_urls_per_request']) : 100;
        $priority_recent_posts = isset($priority_options['priority_recent_posts']) ? intval($priority_options['priority_recent_posts']) : 0;
        $prevent_duplicate_requests = isset($priority_options['prevent_duplicate_requests']) ? intval($priority_options['prevent_duplicate_requests']) : 0;
        $min_request_interval = isset($priority_options['min_request_interval']) ? intval($priority_options['min_request_interval']) : 2;
        ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row">Maximum URLs per Request</th>
                <td>
                    <input type="number" name="priority_options[max_urls_per_request]"
                        value="<?php echo esc_attr($max_urls_per_request); ?>" min="10" max="500" />
                    <p class="description">Limit how many URLs are returned in a single request. Prevents overwhelming the scraper.</p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Prioritize Recent Posts</th>
                <td>
                    <label>
                        <input type="checkbox" name="priority_options[priority_recent_posts]"
                            value="1" <?php checked($priority_recent_posts, 1); ?> />
                        Give priority to posts published in the last 30 days
                    </label>
                    <p class="description">Recent posts get checked first, older posts get checked later.</p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Prevent Duplicate Requests</th>
                <td>
                    <label>
                        <input type="checkbox" name="priority_options[prevent_duplicate_requests]"
                            value="1" <?php checked($prevent_duplicate_requests, 1); ?> />
                        Skip posts that are already pending or were requested recently
                    </label>
                    <p class="description">Prevents the same post from being requested multiple times.</p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row">Minimum Request Interval (hours)</th>
                <td>
                    <input type="number" name="priority_options[min_request_interval]"
                        value="<?php echo esc_attr($min_request_interval); ?>" min="1" max="24" />
                    <p class="description">Minimum time between requests for the same post, even if scraper doesn't respond.</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="save_priority_settings" class="button-primary" value="Save Priority Settings" />
        </p>
    </form>

<?php
}

function display_priority_diagnostics()
{
    global $wpdb;

    // UPDATED: Count total Amazon active-deals posts
    $total_amazon_posts = $wpdb->get_var("
        SELECT COUNT(DISTINCT p.ID) 
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        WHERE p.post_type = 'post' 
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
    ");

    // UPDATED: Count posts needing updates with dual-taxonomy filter
    $options = get_option('price_updates_options', array());
    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;
    $cutoff_time = current_time('timestamp') - ($update_frequency * HOUR_IN_SECONDS);

    $posts_needing_updates = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_last_price_check'
        LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_disable_price_updates'
        LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_needs_price_validation'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        AND (pm1.meta_value IS NULL OR pm1.meta_value < %s)
        AND (pm2.meta_value IS NULL OR pm2.meta_value != '1')
        AND (pm3.meta_value IS NULL OR pm3.meta_value != '1')
    ", $cutoff_time));

    // Count currently pending
    $pending_updates = get_option('pending_price_updates', array());
    $pending_count = count($pending_updates);

    // UPDATED: Count posts missing timestamps with dual-taxonomy filter
    $missing_timestamps = $wpdb->get_var("
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_price_check'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        AND pm.meta_value IS NULL
    ");

    echo '<table class="wp-list-table widefat fixed striped" style="max-width: 600px;">';
    echo '<tr><th>Metric</th><th>Count</th><th>Status</th></tr>';
    echo '<tr><td><strong>Total Amazon Active-Deals Posts</strong></td><td>' . $total_amazon_posts . '</td><td>📊 Reference</td></tr>';
    echo '<tr><td><strong>Posts Needing Updates</strong></td><td>' . $posts_needing_updates . '</td><td>' . ($posts_needing_updates > 200 ? '⚠️ High' : '✅ Normal') . '</td></tr>';
    echo '<tr><td><strong>Currently Pending</strong></td><td>' . $pending_count . '</td><td>' . ($pending_count > 100 ? '🔴 Too High' : '✅ OK') . '</td></tr>';
    echo '<tr><td><strong>Missing Timestamps</strong></td><td>' . $missing_timestamps . '</td><td>' . ($missing_timestamps > 0 ? '🔧 Needs Fix' : '✅ Clean') . '</td></tr>';
    echo '</table>';

    if ($pending_count > $total_amazon_posts) {
        echo '<p style="color: #d63384; font-weight: bold;">⚠️ Warning: More pending updates than total posts suggests stale data!</p>';
    }
}


function reset_all_price_check_timestamps()
{
    global $wpdb;

    $current_timestamp = current_time('timestamp');

    // UPDATED: Update only Amazon active-deals posts
    $updated = $wpdb->query($wpdb->prepare("
        UPDATE {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        SET pm.meta_value = %s
        WHERE pm.meta_key = '_last_price_check'
        AND p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
    ", $current_timestamp));

    // Clear pending updates
    update_option('pending_price_updates', array());

    // UPDATED: Clear force update flags only for active-deals posts
    $force_flags_cleared = $wpdb->query("
        DELETE pm FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        WHERE pm.meta_key = '_force_price_update'
        AND p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
    ");

    error_log("Reset timestamps for {$updated} Amazon active-deals posts, cleared pending updates, and removed {$force_flags_cleared} force update flags");

    return $updated;
}

function fix_missing_price_check_timestamps()
{
    global $wpdb;

    $current_timestamp = current_time('timestamp');

    // UPDATED: Find Amazon active-deals posts missing timestamps and add them
    $missing_posts = $wpdb->get_col("
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr1 ON p.ID = tr1.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt1 ON tr1.term_taxonomy_id = tt1.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t1 ON tt1.term_id = t1.term_id
        INNER JOIN {$wpdb->term_relationships} tr2 ON p.ID = tr2.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t2 ON tt2.term_id = t2.term_id
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_price_check'
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND tt1.taxonomy = 'store_type'
        AND t1.slug = 'amazon'
        AND tt2.taxonomy = 'category'
        AND t2.slug = 'active-deals'
        AND pm.meta_value IS NULL
    ");

    $fixed_count = 0;
    foreach ($missing_posts as $post_id) {
        add_post_meta($post_id, '_last_price_check', $current_timestamp, true);
        $fixed_count++;
    }

    error_log("Added timestamps to {$fixed_count} active-deals posts that were missing them");

    return $fixed_count;
}
