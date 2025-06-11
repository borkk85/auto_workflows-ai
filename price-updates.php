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
    $options = get_option('price_updates_options');
    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;

    // Calculate cutoff time
    $cutoff_time = current_time('timestamp') - ($update_frequency * HOUR_IN_SECONDS);

    // Count posts needing updates
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key' => '_last_price_check',
                'value' => $cutoff_time,
                'compare' => '<'
            ),
            array(
                'key' => '_last_price_check',
                'compare' => 'NOT EXISTS'
            )
        ),
        'fields' => 'ids'
    );

    $query = new WP_Query($args);
    $count = count($query->posts);

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
    $options = get_option('price_updates_options', array());
    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;

    // Calculate cutoff time
    $cutoff_time = current_time('timestamp') - ($update_frequency * HOUR_IN_SECONDS);

    // Get posts needing updates
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'tax_query' => array(
            array(
                'taxonomy' => 'store_type',
                'field'    => 'slug',
                'terms'    => 'amazon',
            ),
        ),
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'relation' => 'OR',
                array(
                    'key' => '_last_price_check',
                    'value' => $cutoff_time,
                    'compare' => '<'
                ),
                array(
                    'key' => '_last_price_check',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_force_price_update',
                    'value' => '1',
                    'compare' => '='
                )
            ),
            array(
                'relation' => 'OR',
                array(
                    'key' => '_disable_price_updates',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_disable_price_updates',
                    'value' => '0',
                    'compare' => '='
                )
            )
        ),
        'fields' => 'ids'
    );

    $query = new WP_Query($args);
    $post_ids = $query->posts;

    $urls = array();

    foreach ($post_ids as $post_id) {
        $amazon_url = get_post_meta($post_id, '_Amazone_produt_link', true);
        $asin = get_post_meta($post_id, '_Amazone_produt_baseName', true);

        if (!empty($amazon_url) && !empty($asin)) {
            $urls[] = array(
                'post_id' => $post_id,
                'amazon_url' => $amazon_url,
                'asin' => $asin,
                'wordpress_url' => get_permalink($post_id)
            );
        }
    }

    // Update last check time
    update_option('price_updates_last_check', current_time('timestamp'));

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
                update_post_meta($post_id, '_discount_price', $pending_data['discount_price']);
            }


            if ($update_both && isset($pending_data['original_price'])) {
                update_post_meta($post_id, '_original_price', $pending_data['original_price']);
            }


            if (isset($pending_data['price_sources'])) {
                update_post_meta($post_id, '_price_sources', $pending_data['price_sources']);
            }


            update_post_meta($post_id, '_last_price_check', current_time('timestamp'));

            // Clear pending data
            delete_post_meta($post_id, '_pending_price_data');
            delete_post_meta($post_id, '_needs_price_validation');

            // Update HTML content
            update_post_html_with_new_prices($post_id);
        }
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=approved'));
    exit;
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

    $hourly_limit = 200; 

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

    $approval_query = new WP_Query($args);

    if ($approval_query->have_posts()) {
    ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Post Title</th>
                    <th>Current Price</th>
                    <th>New Price</th>
                    <th>Change</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                while ($approval_query->have_posts()) {
                    $approval_query->the_post();
                    $post_id = get_the_ID();

                    $pending_data = get_post_meta($post_id, '_pending_price_data', true);

                    if (!$pending_data) continue;

                    $current_price = isset($pending_data['previous_discount_price']) ? $pending_data['previous_discount_price'] : get_post_meta($post_id, '_discount_price', true);
                    $new_price = isset($pending_data['discount_price']) ? $pending_data['discount_price'] : 0;

                    $price_change = isset($pending_data['discount_price_change']) ? $pending_data['discount_price_change'] : 0;
                    $price_change_class = $price_change < 0 ? 'price-decrease' : 'price-increase';
                    $price_change_formatted = number_format(abs($price_change), 2) . '%';
                    $price_change_formatted = $price_change < 0 ? '↓ ' . $price_change_formatted : '↑ ' . $price_change_formatted;

                    $timestamp = isset($pending_data['timestamp']) ? $pending_data['timestamp'] : 0;
                    $date_formatted = $timestamp ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '';
                ?>
                    <tr>
                        <td><a href="<?php echo get_edit_post_link($post_id); ?>"><?php the_title(); ?></a></td>
                        <td><?php echo number_format($current_price, 2); ?> SEK</td>
                        <td><?php echo number_format($new_price, 2); ?> SEK</td>
                        <td class="<?php echo $price_change_class; ?>"><?php echo $price_change_formatted; ?></td>
                        <td><?php echo $date_formatted; ?></td>
                        <td>
                            <a href="<?php echo admin_url('admin-post.php?action=approve_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('approve_price_update')); ?>" class="button-primary">Approve</a>
                            <a href="<?php echo admin_url('admin-post.php?action=reject_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('reject_price_update')); ?>" class="button">Reject</a>
                        </td>
                    </tr>
                <?php
                }
                ?>
            </tbody>
        </table>
    <?php
    } else {
        echo '<p>No products currently need approval.</p>';
    }

    wp_reset_postdata();
}

/**
 * Display recent price updates
 */
function display_recent_price_updates()
{
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => 10,
        'meta_key' => '_last_price_check',
        'orderby' => 'meta_value_num',
        'order' => 'DESC',
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

function track_pending_scraper_updates($post_ids) {
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


function remove_from_pending_updates($post_id) {
    $pending_updates = get_option('pending_price_updates', array());
    
    if (isset($pending_updates[$post_id])) {
        unset($pending_updates[$post_id]);
        update_option('pending_price_updates', $pending_updates);
        error_log('Removed post ' . $post_id . ' from pending updates. Remaining: ' . count($pending_updates));
    }
}

function cleanup_old_pending_updates() {
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
function handle_clear_all_pending_updates() {
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

    // Check for errors
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
            <strong>Price Update Status:</strong>
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
    $args = array(
        'post_type' => 'post',
        'posts_per_page' => 10,
        'meta_key' => '_price_update_errors',
        'meta_compare' => 'EXISTS',
        'orderby' => 'modified',
        'order' => 'DESC',
    );

    $error_query = new WP_Query($args);

    if (!$error_query->have_posts()) {
        echo '<p>No recent price update errors found.</p>';
        return;
    }

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
