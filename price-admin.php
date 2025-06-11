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


function display_pending_scraper_updates() {
    $pending_updates = get_option('pending_price_updates', array());
    
    // Clean up old entries automatically
    $cleaned = cleanup_old_pending_updates();
    if ($cleaned > 0) {
        $pending_updates = get_option('pending_price_updates', array());
        echo '<div class="notice notice-info inline"><p>Automatically cleaned up ' . $cleaned . ' old pending updates.</p></div>';
    }

    if (empty($pending_updates)) {
        echo '<p>No pending price update requests.</p>';
        return;
    }

    $total_pending = count($pending_updates);
    $show_limit = 20; // Show only first 20 by default
    $showing_limited = $total_pending > $show_limit;
    
    ?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin: 0;">Pending Scraper Updates (<?php echo $total_pending; ?> total)</h3>
        <div>
            <?php if ($showing_limited): ?>
                <button type="button" class="button" onclick="toggleAllPendingUpdates()">
                    <span id="toggle-text">Show All</span>
                </button>
            <?php endif; ?>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=clear_all_pending_updates&_wpnonce=' . wp_create_nonce('clear_all_pending_updates'))); ?>" 
               class="button button-secondary" 
               onclick="return confirm('Clear all pending updates? This cannot be undone.')">
                Clear All
            </a>
        </div>
    </div>
    
    <?php if ($showing_limited): ?>
        <div class="notice notice-info inline">
            <p>Showing first <?php echo $show_limit; ?> of <?php echo $total_pending; ?> pending updates. 
               <strong>Note:</strong> Large numbers usually indicate stale data that should be cleared.</p>
        </div>
    <?php endif; ?>
    
    <div id="pending-updates-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd;">
        <table class="wp-list-table widefat fixed striped" style="margin: 0;">
            <thead style="position: sticky; top: 0; background: white; z-index: 1;">
                <tr>
                    <th style="width: 40%;">Post</th>
                    <th style="width: 20%;">Requested At</th>
                    <th style="width: 20%;">Time Elapsed</th>
                    <th style="width: 20%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $count = 0;
                foreach ($pending_updates as $post_id => $timestamp): 
                    $count++;
                    $is_hidden = $showing_limited && $count > $show_limit;
                    
                    $post = get_post($post_id);
                    if (!$post) {
                        // Post does not exist anymore - remove from pending
                        unset($pending_updates[$post_id]);
                        continue;
                    }

                    $time_elapsed = human_time_diff($timestamp, current_time('timestamp'));
                    $is_old = (current_time('timestamp') - $timestamp) > (6 * HOUR_IN_SECONDS);
                    
                    // Prepare CSS classes and styles
                    $row_classes = 'pending-row';
                    if ($is_hidden) {
                        $row_classes .= ' hidden-row';
                    }
                    
                    $row_style = '';
                    if ($is_hidden) {
                        $row_style .= 'display: none;';
                    }
                    if ($is_old) {
                        $row_style .= 'background-color: #ffeaa7;';
                    }
                ?>
                    <tr class="<?php echo esc_attr($row_classes); ?>" style="<?php echo esc_attr($row_style); ?>">
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">
                                <?php echo esc_html(get_the_title($post_id)); ?>
                            </a>
                            <?php if ($is_old): ?>
                                <span style="color: #e17055; font-size: 12px;">(Possibly stale)</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)); ?></td>
                        <td>
                            <?php echo esc_html($time_elapsed); ?> ago
                            <?php if ($is_old): ?>
                                <br><span style="color: #e17055; font-size: 11px;">Old request</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=force_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('force_price_update'))); ?>" 
                               class="button button-small">Retry</a>
                            <a href="<?php echo esc_url(admin_url('admin-post.php?action=cancel_price_update&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('cancel_price_update'))); ?>" 
                               class="button button-small">Cancel</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($showing_limited): ?>
        <script>
        function toggleAllPendingUpdates() {
            var hiddenRows = document.querySelectorAll('.hidden-row');
            var toggleText = document.getElementById('toggle-text');
            var isShowing = toggleText.textContent === 'Show All';
            
            for (var i = 0; i < hiddenRows.length; i++) {
                hiddenRows[i].style.display = isShowing ? 'table-row' : 'none';
            }
            
            toggleText.textContent = isShowing ? 'Show Less' : 'Show All';
        }
        </script>
    <?php endif; ?>
    
    <div style="margin-top: 15px;">
        <p class="description">
            <strong>Tips:</strong> 
            Requests older than 6 hours are highlighted as potentially stale.<br>
            Large numbers of pending updates usually indicate the scraper is not running.<br>
            Use Clear All to reset if you have many stale requests.
        </p>
    </div>
    
    <?php
    
    // Update the pending_updates option to remove non-existent posts
    update_option('pending_price_updates', $pending_updates);
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
