<?php

// Add custom column to posts list
add_filter('manage_posts_columns', 'add_price_update_column');
function add_price_update_column($columns) {
    $columns['price_update_flag'] = 'Fixed Original Price';
    return $columns;
}

// Populate the custom column
add_action('manage_posts_custom_column', 'populate_price_update_column', 10, 2);
function populate_price_update_column($column_name, $post_id)
{
    if ($column_name == 'price_update_flag') {
        $update_both = get_post_meta($post_id, '_update_both_prices', true);
        $update_both = $update_both ? $update_both : '0'; // Default to 0 if not set

        echo '<div class="price-update-flag">';
        echo '<input type="checkbox" ' . checked($update_both, '1', false) . ' 
               data-post-id="' . esc_attr($post_id) . '" 
               class="toggle-price-update" />';
        echo '</div>';
    }
}

// Add a filter to the post list
add_action('restrict_manage_posts', 'add_price_update_filter');
function add_price_update_filter()
{
    global $typenow;

    if ($typenow == 'post') {
        $update_filter = isset($_GET['price_update']) ? $_GET['price_update'] : '';
        ?>
        <select name="price_update" id="price_update" class="postform">
            <option value="">Price Update Setting: All</option>
            <option value="0" <?php selected($update_filter, '0'); ?>>Fixed Original Price</option>
            <option value="1" <?php selected($update_filter, '1'); ?>>Update Both Prices</option>
        </select>
        <?php
    }
}


// Add the filter logic
add_filter('parse_query', 'filter_posts_by_price_update');
function filter_posts_by_price_update($query)
{
    global $pagenow, $typenow;

    if (!is_admin() || $pagenow != 'edit.php' || $typenow != 'post') {
        return $query;
    }

    if (isset($_GET['price_update']) && $_GET['price_update'] !== '') {

        $meta_query = $query->get('meta_query') ? $query->get('meta_query') : array();

        $meta_query[] = array(
            'key' => '_update_both_prices',
            'value' => sanitize_text_field($_GET['price_update']),
            'compare' => '='
        );

        $query->set('meta_query', $meta_query);
    }

    return $query;
}

// Add Ajax handler for toggling the flag
add_action('wp_ajax_toggle_price_update_flag', 'toggle_price_update_flag_callback');
function toggle_price_update_flag_callback()
{
    // Check nonce for security
    check_ajax_referer('toggle_price_update_flag_nonce', 'nonce');

    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $new_value = isset($_POST['value']) ? intval($_POST['value']) : 0;

    if ($post_id > 0) {
        update_post_meta($post_id, '_update_both_prices', $new_value);
        wp_send_json_success(array('message' => 'Price update flag updated'));
    } else {
        wp_send_json_error(array('message' => 'Invalid post ID'));
    }

    wp_die();
}

// Update bulk actions to include price update flag options
add_filter('bulk_actions-edit-post', 'register_price_update_bulk_actions');
function register_price_update_bulk_actions($bulk_actions) {
    $bulk_actions['enable_price_update'] = __('Set: Update Both Prices', 'auto_workflows');
    $bulk_actions['disable_price_update'] = __('Set: Fixed Original Price', 'auto_workflows');
    return $bulk_actions;
}

// Handle the bulk action
add_filter('handle_bulk_actions-edit-post', 'handle_price_update_bulk_actions', 10, 3);
function handle_price_update_bulk_actions($redirect_to, $action, $post_ids) {
    if ($action !== 'enable_price_update' && $action !== 'disable_price_update') {
        return $redirect_to;
    }
    
    $update_value = ($action === 'enable_price_update') ? 1 : 0;
    
    foreach ($post_ids as $post_id) {
        update_post_meta($post_id, '_update_both_prices', $update_value);
    }
    
    $redirect_to = add_query_arg('bulk_price_update_posts', count($post_ids), $redirect_to);
    return $redirect_to;
}

// Add an admin notice for the bulk action
add_action('admin_notices', 'price_update_bulk_action_admin_notice');
function price_update_bulk_action_admin_notice()
{
    if (!empty($_REQUEST['bulk_price_update_posts'])) {
        $updated_count = intval($_REQUEST['bulk_price_update_posts']);

        $message = sprintf(
            _n(
                'Price update flag changed for %s post.',
                'Price update flag changed for %s posts.',
                $updated_count,
                'auto_workflows'
            ),
            number_format_i18n($updated_count)
        );

        echo '<div class="updated"><p>' . $message . '</p></div>';
    }
}

// Add meta box for price settings
add_action('add_meta_boxes', 'add_price_settings_meta_box');
function add_price_settings_meta_box()
{
    add_meta_box(
        'price_settings_meta_box',
        'Price Settings',
        'price_settings_meta_box_callback',
        'post',
        'side',
        'high'
    );
}

// Meta box callback function
function price_settings_meta_box_callback($post)
{
    // Add nonce for security
    wp_nonce_field('price_settings_meta_box_nonce', 'price_settings_nonce');

    // Get the current values
    $discount_price = get_post_meta($post->ID, '_discount_price', true);
    $original_price = get_post_meta($post->ID, '_original_price', true);
    $update_both_prices = get_post_meta($post->ID, '_update_both_prices', true);
    $disable_price_updates = get_post_meta($post->ID, '_disable_price_updates', true);
    $price_sources = get_post_meta($post->ID, '_price_sources', true);
    $last_price_check = get_post_meta($post->ID, '_last_price_check', true);

    // Format sources for display
    $discount_source = isset($price_sources['discount_price_source']) ? $price_sources['discount_price_source'] : 'unknown';
    $original_source = isset($price_sources['original_price_source']) ? $price_sources['original_price_source'] : 'unknown';

    // Format timestamp
    $last_check_date = $last_price_check ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_price_check) : 'Never';

    ?>
    <div class="price-settings-container">
        <div class="price-setting">
            <label for="discount_price">Discount Price (SEK):</label>
            <input type="number" id="discount_price" name="discount_price" value="<?php echo esc_attr($discount_price); ?>" step="0.01" />
            <p class="description">Source: <?php echo esc_html($discount_source); ?></p>
        </div>

        <div class="price-setting">
            <label for="original_price">Original Price (SEK):</label>
            <input type="number" id="original_price" name="original_price" value="<?php echo esc_attr($original_price); ?>" step="0.01" />
            <p class="description">Source: <?php echo esc_html($original_source); ?></p>
        </div>

        <div class="price-update-setting">
            <label for="disable_price_updates">
                <input type="checkbox" id="disable_price_updates" name="disable_price_updates" value="1" <?php checked($disable_price_updates, '1'); ?> />
                Disable automatic price updates
            </label>
            <p class="description">Check this to keep prices fixed and prevent automatic updates.</p>
        </div>

        <div class="price-update-setting" <?php echo $disable_price_updates == '1' ? 'style="opacity: 0.5;"' : ''; ?>>
            <label for="update_both_prices">
                <input type="checkbox" id="update_both_prices" name="update_both_prices" value="1" <?php checked($update_both_prices, '0'); ?> <?php echo $disable_price_updates == '1' ? 'disabled' : ''; ?> />
                Keep comparison price (original price) fixed
            </label>
            <p class="description">When checked, only the discount price will be updated automatically. The original price will remain fixed.</p>
        </div>

        <div class="price-last-check">
            <p><strong>Last Price Check:</strong> <?php echo esc_html($last_check_date); ?></p>
        </div>
    </div>

    <script>
        jQuery(document).ready(function($) {

            $('#disable_price_updates').change(function() {
                if ($(this).is(':checked')) {
                    $('#update_both_prices').prop('disabled', true).closest('.price-update-setting').css('opacity', 0.5);
                } else {
                    $('#update_both_prices').prop('disabled', false).closest('.price-update-setting').css('opacity', 1);
                }
            });
        });
    </script>
<?php
}

// Save meta box data
add_action('save_post', 'save_price_settings_meta_box_data');
function save_price_settings_meta_box_data($post_id)
{
    // Check if nonce is set
    if (!isset($_POST['price_settings_nonce'])) {
        return;
    }

    // Verify the nonce
    if (!wp_verify_nonce($_POST['price_settings_nonce'], 'price_settings_meta_box_nonce')) {
        return;
    }

    // If this is an autosave, we don't want to do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    // Check the user's permissions
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $disable_price_updates = isset($_POST['disable_price_updates']) ? '1' : '0';
    update_post_meta($post_id, '_disable_price_updates', $disable_price_updates);

    if ($disable_price_updates == '0') {
        $update_both_prices = isset($_POST['update_both_prices']) ? '1' : '0';
        update_post_meta($post_id, '_update_both_prices', $update_both_prices);
    }


    // Save discount price
    if (isset($_POST['discount_price'])) {
        $discount_price = floatval($_POST['discount_price']);
        update_post_meta($post_id, '_discount_price', $discount_price);
    }

    // Save original price
    if (isset($_POST['original_price'])) {
        $original_price = floatval($_POST['original_price']);
        update_post_meta($post_id, '_original_price', $original_price);
    }


    // Save update both prices flag
    $update_both_prices = isset($_POST['update_both_prices']) ? '1' : '0';
    update_post_meta($post_id, '_update_both_prices', $update_both_prices);

    // If prices have changed, update the discount percentage
    if (isset($_POST['original_price']) && isset($_POST['discount_price'])) {
        $discount_price = floatval($_POST['discount_price']);
        $original_price = floatval($_POST['original_price']);

        if ($original_price > $discount_price) {
            $discount_percentage = (($original_price - $discount_price) / $original_price) * 100;
            $discount_percentage = round($discount_percentage);
            update_post_meta($post_id, '_discount_percentage', $discount_percentage);

            // Update the discount tag
            $discount_tag_title = $discount_percentage . '% off';
            wp_set_post_terms($post_id, [$discount_tag_title], 'post_tag', true);
        }
    }

    if (isset($_POST['discount_price']) || isset($_POST['original_price'])) {
        update_price_check_timestamp($post_id);
    }
}

function display_pending_scraper_updates()
{
    $pending_updates = get_option('pending_price_updates', array());

    if (empty($pending_updates)) {
        echo '<p>No pending price update requests.</p>';
        return;
    }

?>
    <h3>Pending Scraper Updates</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Post</th>
                <th>Requested At</th>
                <th>Time Elapsed</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pending_updates as $post_id => $timestamp): ?>
                <?php
                $post = get_post($post_id);
                if (!$post) continue;

                $time_elapsed = human_time_diff($timestamp, current_time('timestamp'));
                ?>
                <tr>
                    <td><a href="<?php echo get_edit_post_link($post_id); ?>"><?php echo get_the_title($post_id); ?></a></td>
                    <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp); ?></td>
                    <td><?php echo $time_elapsed; ?> ago</td>
                    <td>
                        <a href="<?php echo admin_url('admin-post.php?action=force_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('force_price_update')); ?>" class="button">Retry</a>
                        <a href="<?php echo admin_url('admin-post.php?action=cancel_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('cancel_price_update')); ?>" class="button">Cancel</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php
}

// Add handler for cancel action
add_action('admin_post_cancel_price_update', 'handle_cancel_price_update');
function handle_cancel_price_update()
{
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'cancel_price_update')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('edit_posts')) {
        wp_die('Permission denied');
    }

    $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

    if ($post_id > 0) {
        remove_from_pending_updates($post_id);
    }

    wp_redirect(admin_url('options-general.php?page=ai_integration&tab=price_updates&updated=cancelled'));
    exit;
}
