<?php 

/**
 * Send enhanced price change notification
 */
function send_price_change_notification($post_id, $price_data) {
    $options = get_option('price_updates_options', array());
    $notification_email = isset($options['notification_email']) ? $options['notification_email'] : get_option('admin_email');
    
    $post_title = get_the_title($post_id);
    $post_url = get_edit_post_link($post_id, '');
    $admin_url = admin_url('options-general.php?page=ai_integration&tab=price_updates');
    
    $discount_change_formatted = number_format(floatval($price_data['discount_price_change']), 2);
    $discount_change_formatted = ($price_data['discount_price_change'] >= 0 ? '+' : '') . $discount_change_formatted . '%';
    
    $original_change_formatted = number_format(floatval($price_data['original_price_change']), 2);
    $original_change_formatted = ($price_data['original_price_change'] >= 0 ? '+' : '') . $original_change_formatted . '%';
    
    $html_message = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #f8f9fa; padding: 15px; border-bottom: 3px solid #007bff; }
            .content { padding: 20px 0; }
            table { width: 100%; border-collapse: collapse; margin: 15px 0; }
            th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background-color: #f2f2f2; }
            .price-increase { color: #dc3545; }
            .price-decrease { color: #28a745; }
            .buttons { margin: 20px 0; }
            .button { display: inline-block; padding: 10px 15px; background-color: #007bff; color: white; text-decoration: none; border-radius: 4px; margin-right: 10px; }
            .button.secondary { background-color: #6c757d; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>Price Change Approval Required</h2>
            </div>
            <div class="content">
                <p>A product requires price update approval:</p>
                
                <h3><a href="' . esc_url($post_url) . '">' . esc_html($post_title) . '</a></h3>
                
                <table>
                    <tr>
                        <th></th>
                        <th>Current Price</th>
                        <th>New Price</th>
                        <th>Change</th>
                    </tr>
                    <tr>
                        <td><strong>Discount Price</strong></td>
                        <td>' . number_format($price_data['previous_discount_price'], 2) . ' SEK</td>
                        <td>' . number_format($price_data['discount_price'], 2) . ' SEK</td>
                        <td class="' . ($price_data['discount_price_change'] < 0 ? 'price-decrease' : 'price-increase') . '">' . $discount_change_formatted . '</td>
                    </tr>
                    <tr>
                        <td><strong>Original Price</strong></td>
                        <td>' . number_format($price_data['previous_original_price'], 2) . ' SEK</td>
                        <td>' . number_format($price_data['original_price'], 2) . ' SEK</td>
                        <td class="' . ($price_data['original_price_change'] < 0 ? 'price-decrease' : 'price-increase') . '">' . $original_change_formatted . '</td>
                    </tr>
                </table>
                
                <p><strong>Price Source:</strong> ' . esc_html($price_data['price_sources']['discount_price_source']) . '</p>
                
                <div class="buttons">
                    <a href="' . esc_url($admin_url) . '" class="button">Review All Pending Changes</a>
                </div>
                
                <p>This notification was sent because the price change exceeds the threshold set in your price update settings.</p>
            </div>
        </div>
    </body>
    </html>';
    
    
    $text_message = "Price Change Approval Required\n\n";
    $text_message .= "Product: " . $post_title . "\n";
    $text_message .= "Current Discount Price: " . $price_data['previous_discount_price'] . " SEK\n";
    $text_message .= "New Discount Price: " . $price_data['discount_price'] . " SEK\n";
    $text_message .= "Discount Price Change: " . $discount_change_formatted . "\n\n";
    $text_message .= "Current Original Price: " . $price_data['previous_original_price'] . " SEK\n";
    $text_message .= "New Original Price: " . $price_data['original_price'] . " SEK\n";
    $text_message .= "Original Price Change: " . $original_change_formatted . "\n\n";
    $text_message .= "Please approve or reject this change:\n";
    $text_message .= $admin_url . "\n";
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
    );
    
    $subject = 'Price Change Approval Required: ' . $post_title;
    
    // Send the email
    $sent = wp_mail($notification_email, $subject, $html_message, $headers);
    
    // Log whether the email was sent successfully
    if ($sent) {
        error_log('Price change notification email sent for post ' . $post_id);
    } else {
        error_log('Failed to send price change notification email for post ' . $post_id);
    }
    
    // Also add to admin dashboard notifications
    add_price_update_admin_notice($post_id, $price_data);
    
    return $sent;
}

/**
 * Add admin dashboard notification for price changes
 */
function add_price_update_admin_notice($post_id, $price_data) {
    // Get existing notices or initialize empty array
    $notices = get_option('price_update_admin_notices', array());
    
    // Add this notice
    $notices[$post_id] = array(
        'post_id' => $post_id,
        'title' => get_the_title($post_id),
        'price_data' => $price_data,
        'timestamp' => current_time('timestamp'),
        'read' => false
    );
    
    // Store the updated notices
    update_option('price_update_admin_notices', $notices);
}

function display_price_update_admin_notices() {
    // Only show on admin dashboard and our custom page
    $screen = get_current_screen();
    if (!($screen->id === 'dashboard' || $screen->id === 'settings_page_ai_integration')) {
        return;
    }
    
    $notices = get_option('price_update_admin_notices', array());
    
    // If there are no notices, don't display anything
    if (empty($notices)) {
        return;
    }
    
    // Count unread notices
    $unread_count = 0;
    foreach ($notices as $notice) {
        if (!$notice['read']) {
            $unread_count++;
        }
    }
    
    if ($unread_count === 0) {
        return; // No unread notices to display
    }
    
    ?>
    <div class="notice notice-warning is-dismissible price-update-admin-notice">
        <h3>Price Updates Requiring Approval</h3>
        <p>
            There <?php echo $unread_count === 1 ? 'is' : 'are'; ?> <strong><?php echo $unread_count; ?></strong> 
            product price <?php echo $unread_count === 1 ? 'update' : 'updates'; ?> that require your approval.
        </p>
        <p>
            <a href="<?php echo admin_url('options-general.php?page=ai_integration&tab=price_updates'); ?>" class="button button-primary">
                Review Price Updates
            </a>
            <button class="button dismiss-all-notices">Mark All As Read</button>
        </p>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        $('.dismiss-all-notices').on('click', function(e) {
            e.preventDefault();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mark_price_notices_read',
                    nonce: '<?php echo wp_create_nonce('mark_price_notices_read'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('.price-update-admin-notice').fadeOut();
                    }
                }
            });
        });
    });
    </script>
    <?php
}


add_action('wp_ajax_mark_price_notices_read', 'mark_price_notices_read_callback');
function mark_price_notices_read_callback() {
    check_ajax_referer('mark_price_notices_read', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => 'Permission denied'));
        return;
    }
    
    $notices = get_option('price_update_admin_notices', array());
    
    foreach ($notices as $post_id => $notice) {
        $notices[$post_id]['read'] = true;
    }
    
    update_option('price_update_admin_notices', $notices);
    
    wp_send_json_success(array('message' => 'All notices marked as read'));
}

// Hook the notice display function to admin_notices
add_action('admin_notices', 'display_price_update_admin_notices');

add_action('ai_integration_tab_content', 'price_updates_tab_content');
function price_updates_tab_content() {
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    
    if ($active_tab != 'price_updates') {
        return;
    }
    
    // Save settings if submitted
    if (isset($_POST['submit_price_updates'])) {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        check_admin_referer('price_updates_options', 'price_updates_nonce');
        
        $options = isset($_POST['price_updates_options']) ? $_POST['price_updates_options'] : array();
        update_option('price_updates_options', $options);
        echo '<div class="updated fade"><p><strong>Price update settings saved.</strong></p></div>';
    }
    
    // Get saved options with better defaults
    $options = get_option('price_updates_options', array());
    $update_frequency = isset($options['update_frequency']) ? intval($options['update_frequency']) : 24;
    $price_change_low_threshold = isset($options['price_change_low_threshold']) ? floatval($options['price_change_low_threshold']) : 20;
    $price_change_high_threshold = isset($options['price_change_high_threshold']) ? floatval($options['price_change_high_threshold']) : 70;
    $significant_decrease = isset($options['significant_decrease']) ? floatval($options['significant_decrease']) : 5;
    $archive_category = isset($options['archive_category']) ? intval($options['archive_category']) : 0;
    $notification_email = isset($options['notification_email']) ? $options['notification_email'] : get_option('admin_email');
    
    ?>
    <div class="wrap">
        <h2>Price Updates Settings</h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('price_updates_options', 'price_updates_nonce'); ?>
            
            <h3>Update Schedule</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Update Frequency (hours)</th>
                    <td>
                        <input type="number" name="price_updates_options[update_frequency]" value="<?php echo esc_attr($update_frequency); ?>" min="1" max="72" />
                        <p class="description">How often to check for price updates (in hours). Default is 24 hours.</p>
                    </td>
                </tr>
            </table>
            
            <h3>Price Change Rules</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Price Change Range</th>
                    <td>
                        <div style="display: flex; align-items: center; margin-bottom: 10px;">
                            <input type="number" name="price_updates_options[price_change_low_threshold]" value="<?php echo esc_attr($price_change_low_threshold); ?>" min="0" max="100" step="0.1" style="width: 80px;" />
                            <span style="margin: 0 10px;">% to</span>
                            <input type="number" name="price_updates_options[price_change_high_threshold]" value="<?php echo esc_attr($price_change_high_threshold); ?>" min="0" max="100" step="0.1" style="width: 80px;" />
                            <span style="margin-left: 10px;">%</span>
                        </div>
                        <p class="description">
                            <strong>Price changes will be handled as follows:</strong><br>
                            • Below <?php echo esc_html($price_change_low_threshold); ?>%: Prices automatically updated<br>
                            • <?php echo esc_html($price_change_low_threshold); ?>% to <?php echo esc_html($price_change_high_threshold); ?>%: Manual approval required<br>
                            • Above <?php echo esc_html($price_change_high_threshold); ?>%: Post automatically archived
                        </p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row">Significant Price Decrease (%)</th>
                    <td>
                        <input type="number" name="price_updates_options[significant_decrease]" value="<?php echo esc_attr($significant_decrease); ?>" min="0" max="50" step="0.1" />
                        <p class="description">Percentage decrease considered significant enough to trigger resharing. Default is 5%.</p>
                    </td>
                </tr>
                
                <tr valign="top">
                    <th scope="row">Archive Category</th>
                    <td>
                        <?php
                        $categories = get_categories(array(
                            'hide_empty' => false,
                            'orderby' => 'name',
                            'order' => 'ASC'
                        ));
                        
                        if (!empty($categories)) {
                            echo '<select name="price_updates_options[archive_category]">';
                            echo '<option value="0">Select a category</option>';
                            
                            foreach ($categories as $category) {
                                echo '<option value="' . esc_attr($category->term_id) . '" ' . selected($archive_category, $category->term_id, false) . '>';
                                echo esc_html($category->name);
                                echo '</option>';
                            }
                            
                            echo '</select>';
                            echo '<p class="description">Select the category to move archived posts to.</p>';
                        } else {
                            echo '<p>No categories found. Please create a category for archived posts.</p>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <h3>Notifications</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Notification Email</th>
                    <td>
                        <input type="email" name="price_updates_options[notification_email]" value="<?php echo esc_attr($notification_email); ?>" class="regular-text" />
                        <p class="description">Email address for notifications about price changes requiring approval.</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_price_updates" class="button-primary" value="Save Price Update Settings" />
            </p>
        </form>
        
        <hr>
    
    <h2>Products Needing Approval</h2>
    <?php display_pending_approval_products(); ?>
    
    <hr>
    
    <h2>Pending Scraper Updates</h2>
    <?php display_pending_scraper_updates(); ?>
    
    <hr>
    
    <h2>Price Update Errors</h2>
    <?php display_price_update_errors(); ?>
    
    <hr>
    
    <h2>Recent Updates</h2>
    <?php display_recent_price_updates(); ?>
   
    
    </div>
    <?php
}



