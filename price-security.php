<?php
/**
 * API Key Management Admin Interface
 * 
 */

add_action('ai_integration_tab_content', 'api_security_tab_content');
function api_security_tab_content() {
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
    
    if ($active_tab != 'price_updates') {
        return;
    }
    
    if (isset($_POST['regenerate_api_key']) && wp_verify_nonce($_POST['api_security_nonce'], 'api_security_action')) {
        $new_api_key = wp_generate_password(32, false, false);
        update_option('price_update_api_key', $new_api_key);
        echo '<div class="updated fade"><p><strong>New API key generated successfully.</strong></p></div>';
    }
    
    if (isset($_POST['clear_rate_limits']) && wp_verify_nonce($_POST['api_security_nonce'], 'api_security_action')) {
        // Clear all rate limit transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_price_update_rate_%'");
        echo '<div class="updated fade"><p><strong>All rate limits cleared.</strong></p></div>';
    }
    
    $api_key = get_option('price_update_api_key');
    
    ?>
    <hr>
    <h2>API Security Settings</h2>
    
    <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;">
        <h3 style="margin-top: 0;">🔒 API Key Authentication</h3>
        <p>The price update endpoint is now secured with API key authentication. The scraper must include the API key in the request header.</p>
    </div>
    
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Current API Key</th>
            <td>
                <div style="font-family: monospace; background: #f1f1f1; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px; word-break: break-all;">
                    <?php echo $api_key ? esc_html($api_key) : 'No API key generated'; ?>
                </div>
                <?php if ($api_key): ?>
                    <button type="button" class="button" onclick="copyApiKey()">📋 Copy to Clipboard</button>
                    <script>
                    function copyApiKey() {
                        const apiKey = '<?php echo esc_js($api_key); ?>';
                        navigator.clipboard.writeText(apiKey).then(() => {
                            alert('API key copied to clipboard!');
                        });
                    }
                    </script>
                <?php endif; ?>
            </td>
        </tr>
        
        <tr valign="top">
            <th scope="row">Usage Instructions</th>
            <td>
                <p>The scraper must include this header in all requests to the update-price endpoint:</p>
                <div style="font-family: monospace; background: #f1f1f1; padding: 10px; border: 1px solid #ddd;">
                    X-API-Key: <?php echo $api_key ? esc_html($api_key) : 'YOUR_API_KEY_HERE'; ?>
                </div>
            </td>
        </tr>
        
        <tr valign="top">
            <th scope="row">Rate Limiting</th>
            <td>
                <p><strong>Current Limit:</strong> 200 requests per hour per IP address</p>
                <p>This prevents abuse while allowing normal scraping operations.</p>
            </td>
        </tr>
    </table>
    
    <form method="post" action="">
        <?php wp_nonce_field('api_security_action', 'api_security_nonce'); ?>
        <h3>API Key Management</h3>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Generate New API Key</th>
                <td>
                    <input type="submit" name="regenerate_api_key" class="button button-secondary" value="🔄 Generate New API Key" 
                           onclick="return confirm('This will invalidate the current API key. The scraper will need to be updated with the new key. Continue?')" />
                    <p class="description">⚠️ <strong>Warning:</strong> This will invalidate the current API key. Update the scraper with the new key immediately.</p>
                </td>
            </tr>
            
            <tr valign="top">
                <th scope="row">Clear Rate Limits</th>
                <td>
                    <input type="submit" name="clear_rate_limits" class="button button-secondary" value="🗑️ Clear All Rate Limits" />
                    <p class="description">Clear rate limiting for all IP addresses (useful for testing).</p>
                </td>
            </tr>
        </table>
    </form>
    
    <hr>
    
    <h3>API Security Status</h3>
    <?php display_api_security_status(); ?>
    
    <hr>
    
    <h3>Recent API Activity</h3>
    <?php display_recent_api_activity(); ?>
    
    <?php
}


function display_api_security_status() {
    $api_key = get_option('price_update_api_key');
    
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Security Feature</th>
                <th>Status</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>API Key Authentication</strong></td>
                <td><?php echo $api_key ? '<span style="color: green;">✓ Enabled</span>' : '<span style="color: red;">✗ Disabled</span>'; ?></td>
                <td><?php echo $api_key ? 'API key is configured and active' : 'No API key generated'; ?></td>
            </tr>
            <tr>
                <td><strong>Rate Limiting</strong></td>
                <td><span style="color: green;">✓ Enabled</span></td>
                <td>200 requests per hour per IP address</td>
            </tr>
            <tr>
                <td><strong>Request Logging</strong></td>
                <td><span style="color: green;">✓ Enabled</span></td>
                <td>All API requests are logged to error log</td>
            </tr>
            <tr>
                <td><strong>Public Endpoints</strong></td>
                <td><span style="color: orange;">⚠ Limited</span></td>
                <td>Only /price-update-urls and /test endpoints are public</td>
            </tr>
        </tbody>
    </table>
    <?php
}

function display_recent_api_activity() {
    
    global $wpdb;
    
    $rate_limit_data = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_price_update_rate_%' 
         ORDER BY option_name DESC 
         LIMIT 10"
    );
    
    if (empty($rate_limit_data)) {
        echo '<p>No recent API activity found.</p>';
        return;
    }
    
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>IP Address</th>
                <th>Requests This Hour</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rate_limit_data as $data): ?>
                <?php
                $ip_hash = str_replace('_transient_price_update_rate_', '', $data->option_name);
                $requests = intval($data->option_value);
                $status = $requests >= 200 ? 'Rate Limited' : 'Active';
                $status_color = $requests >= 200 ? 'red' : 'green';
                ?>
                <tr>
                    <td><code><?php echo esc_html(substr($ip_hash, 0, 12)) . '...'; ?></code></td>
                    <td><?php echo esc_html($requests); ?>/200</td>
                    <td><span style="color: <?php echo $status_color; ?>;"><?php echo esc_html($status); ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description">IP addresses are hashed for privacy. Full activity logs are available in the server error log.</p>
    <?php
}