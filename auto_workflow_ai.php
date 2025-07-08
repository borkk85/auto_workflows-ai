<?php
/*
Plugin Name: AI Automation Workflows & SEO blog content creation. 
Plugin URI: https://example.com
Description: AI Automation Workflows & SEO blog content creation plugin.
Version: 1.0.0
Author: Abid Hussain

License: GPL2
Text Domain: automation_workflows
*/



add_action('admin_enqueue_scripts', 'webhook_init');
function webhook_init()
{

    wp_register_style('webhook', plugin_dir_url(__FILE__) . 'webhook/webhook.css', false, time());
    wp_enqueue_style('webhook');

    //wp_enqueue_style('webhook',plugins_url('/webhook/webhook.css',__FILE__));
}

add_action('admin_enqueue_scripts', 'auto_workflows_admin_scripts');
function auto_workflows_admin_scripts($hook)
{
    if ($hook != 'edit.php' && $hook != 'post.php' && $hook != 'post-new.php') {
        return;
    }

    wp_register_script(
        'auto-workflows-admin-js',
        plugins_url('/assets/js/admin.js', __FILE__),
        array('jquery'),
        time(), // Use current time as version for development, change to a fixed version for production
        true
    );

    wp_localize_script(
        'auto-workflows-admin-js',
        'autoWorkflowsAdmin',
        array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('toggle_price_update_flag_nonce'),
            'toggleSuccess' => __('Price update flag changed successfully', 'auto_workflows'),
            'toggleError' => __('Error changing price update flag', 'auto_workflows')
        )
    );

    wp_enqueue_script('auto-workflows-admin-js');

    wp_register_style(
        'auto-workflows-admin-css',
        plugins_url('/assets/css/admin.css', __FILE__),
        array(),
        time()
    );

    wp_enqueue_style('auto-workflows-admin-css');
}

include(WP_PLUGIN_DIR . '/auto_workflows-ai/main_functions.php');
include(WP_PLUGIN_DIR . '/auto_workflows-ai/price-rules-engine.php');
include(WP_PLUGIN_DIR . '/auto_workflows-ai/price-updates.php');
include(WP_PLUGIN_DIR . '/auto_workflows-ai/price-notifications.php');
include(WP_PLUGIN_DIR . '/auto_workflows-ai/price-admin.php');
include(WP_PLUGIN_DIR . '/auto_workflows-ai/price-security.php');
//AI integration page into dashboard settings Tab
add_action('admin_menu', 'add_my_custom_settings_page');

function add_my_custom_settings_page()
{
    add_options_page(
        'AI Integration',
        'AI Integration',
        'manage_options',
        'ai_integration',
        'ai_integration_page_content'
    );
}

function ai_integration_page_content()
{

    // Check if the form is submitted and if our fields are set
    if (isset($_POST['api_key']) && isset($_POST['ai_model'])) {

        // Sanitize the input fields to ensure data is safe to save
        $api_key = sanitize_text_field($_POST['api_key']);
        $ai_model = sanitize_text_field($_POST['ai_model']);

        $prime_block_link = sanitize_text_field($_POST['prime_block_link']);
        $prime_block_area = sanitize_text_field($_POST['prime_block_area']);

        $sek_fixed_rate = sanitize_text_field($_POST['sek_fixed_rate']);

        $max_tokens = sanitize_text_field($_POST['max_tokens']);
        $temperature = sanitize_text_field($_POST['temperature']);
        $frequency_penalty = sanitize_text_field($_POST['frequency_penalty']);
        $presence_penalty = sanitize_text_field($_POST['presence_penalty']);

        $prompt_for_ai = sanitize_text_field($_POST['prompt_for_ai']);

        // Save the data into the database using WordPress's update_option() function
        update_option('api_key', $api_key);
        update_option('ai_model', $ai_model);

        update_option('prime_block_link', $prime_block_link);
        update_option('prime_block_area', $prime_block_area);

        update_option('sek_fixed_rate', $sek_fixed_rate);

        update_option('max_tokens', $max_tokens);
        update_option('temperature', $temperature);
        update_option('frequency_penalty', $frequency_penalty);
        update_option('presence_penalty', $presence_penalty);

        update_option('prompt_for_ai', $prompt_for_ai);

        // Output a success message when given data successfully saved
        echo '<div id="message" class="updated fade"><p><strong>Options saved.</strong></p></div>';
    }
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
?>

    <div class="wrap">
        <h2 class="nav-tab-wrapper">
            <a href="?page=ai_integration&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">AI Integration</a>
            <?php do_action('ai_integration_tabs');  ?>
        </h2>
        <?php
        if ($active_tab == 'general') {
        ?>
            <style>
                .prime_block_area {
                    font-size: 16px;
                    margin-bottom: 30px;
                }

                .prime_block_area label {
                    margin-bottom: 4px !important;
                    padding-bottom: 34px;
                }

                .main_ai_wrapper_left {
                    width: 50%;
                    float: left;
                }

                .main_ai_wrapper_right {
                    width: 45%;
                    float: left;
                    border-left: 1px solid gray;
                    padding-left: 17px;
                }

                @media screen and (max-width: 600px) {
                    .main_ai_wrapper_left {
                        width: 100%;
                        float: left;
                    }

                    .main_ai_wrapper_right {
                        width: 100%;
                        float: left;
                        border-left: 1px solid gray;
                        padding-left: 17px;
                    }
                }
            </style>
            <div class="main_ai_wrapper_left">
                <h1>AI Integration</h1>
                <p>Please put here API key and AI model.</p>

                <form method="post">

                    <div class="block_item">
                        <label for="api_key" class="api_key_label">API key:</label>
                        <input type="password" id="api_key" name="api_key" value="<?php echo  get_option('api_key'); ?>">
                    </div><br>

                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">AI model:</label>
                        <input type="text" class="ai_model" name="ai_model" value="<?php echo get_option('ai_model'); ?>">
                    </div>
                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">Max Token:</label>
                        <input type="number" class="ai_model" name="max_tokens" value="<?php echo get_option('max_tokens'); ?>">
                    </div>
                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">Temperature:</label>
                        <input type="text" class="ai_model" name="temperature" value="<?php echo get_option('temperature'); ?>">
                    </div>
                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">Frequency Penalty:</label>
                        <input type="text" class="ai_model" name="frequency_penalty" value="<?php echo get_option('frequency_penalty'); ?>">
                    </div>
                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">Presence Penalty:</label>
                        <input type="text" class="ai_model" name="presence_penalty" value="<?php echo get_option('presence_penalty'); ?>">
                    </div>
                    <div class="block_item">
                        <label for="ai_model" class="ai_model_label">Prompt for AI:</label>
                        <textarea id="prompt_for_ai" name="prompt_for_ai" rows="7" cols="90"><?php echo get_option('prompt_for_ai'); ?></textarea>
                    </div>

                    <h2>Amazon Settings:</h2>
                    <div class="prime_block_area">

                        <label>SEK Fixed Rate</label><BR />
                        <input type="text" style="width: 300px;" name="sek_fixed_rate" value="<?php echo  get_option('sek_fixed_rate'); ?>" /><BR />

                    </div>
                    <div class="prime_block_area">

                        <label>Amazon Prime link</label><BR />
                        <input type="text" style="width: 300px;" name="prime_block_link" value="<?php echo  get_option('prime_block_link'); ?>" /><BR />

                    </div>
                    <div class="prime_block_area">

                        <label>Amazon Prime Block Text</label><br />
                        <textarea id="prime_block_area" name="prime_block_area" rows="7" cols="90"><?php echo get_option('prime_block_area'); ?></textarea>
                    </div>


                    <input type="submit" id="submit" value="Save Settings">
                </form>
            </div>
            <div class="main_ai_wrapper_right">
                <h1>Create Post from AI using title</h1>
                <?php

                include(WP_PLUGIN_DIR . '/auto_workflows-ai/put_manual_ai_post.php');

                $brands = get_terms([
                    'taxonomy' => 'brands',
                    'hide_empty' => false,
                ]);

                ?>
                <form method="post">
                    <div class="prime_block_area">
                        <label>Add Title</label><BR />
                        <textarea id="prime_block_area" name="manual_post_title" rows="4" cols="70"></textarea>
                    </div>
                    <div class="prime_block_area">
                        <label>Amazon Product link</label><BR />
                        <input type="text" name="amazon_url" value="">
                    </div>
                    <div class="prime_block_area">
                        <label>Featured Image link</label><BR />
                        <input type="text" name="brand_image_url" value="">
                    </div>

                    <div class="prime_block_area">
                        <label for="product_category">Check if Category Exists:</label>
                        <br />
                        <?php
                        wp_dropdown_categories(array(
                            'taxonomy'         => 'product_categories',
                            'name'             => 'product_category',
                            'orderby'          => 'name',
                            'order'            => 'ASC',
                            'show_count'       => 0,
                            'hide_empty'       => 0,
                            'child_of'         => 0,
                            'echo'             => 1,
                            'hierarchical'     => 1,
                            'depth'            => 3,
                            'show_option_none' => 'Select Category',
                        ));
                        ?>
                    </div>
                    <div class="prime_block_area">
                        <label for="category_hierarchy">Category:</label><BR />
                        <input type="text" name="category_hierarchy" id="category_hierarchy" placeholder="Parent Category > Child Category > Grandchild Category">
                    </div>
                    <div class="prime_block_area">
                        <label>Original Price</label><BR />
                        <input type="text" name="org_amazon_price" value="">
                    </div>
                    <div class="prime_block_area">
                        <label>Discount Price</label><BR />
                        <input type="text" name="disc_amazon_price" value="">
                    </div>
                    <div class="prime_block_area">
                        <label for="update_both_prices">
                            <input type="checkbox" id="update_both_prices" name="update_both_prices" value="0" checked>
                            Keep comparison price (original price) fixed
                        </label>
                        <p class="description">When checked, only the discount price will be updated automatically. The original price will remain fixed.</p>
                    </div>
                    <input type="submit" name="put_ai_manul_post" value="Create post">
                </form>
            </div>

        <?php
        } else {
            do_action('ai_integration_tab_content');
        }
        ?>
    </div>
<?php
}

//******************* Redirect ************************//
if (isset($_GET['sm'])) {


    $encryption  = $_GET['sm'];

    $ciphering = "AES-128-CTR";
    $options = 0;
    $decryption_iv = '1234567891011121';
    // Store the decryption key
    $decryption_key = "GeeksforGeeks";
    // Use openssl_decrypt() function to decrypt the data
    $decryption = openssl_decrypt($encryption, $ciphering, $decryption_key, $options, $decryption_iv);
    $url = 'https://www.amazon.se/-/en/dp/' . $decryption . '?&tag=benknackan9-21';
    echo "<script>window.location.href = '{$url}';</script>";
    exit;
?>


<?php

}

if (isset($_GET['sn'])) {
    $encryption  = get_amazon_converted_Id($_GET['sn']);
    $decoded = str_rot13(str_rot13($encryption));
    $url = 'https://www.amazon.se/-/en/dp/' . $decoded . '?&tag=benknackan9-21';
    echo "<script>window.location.href = '{$url}';</script>";
    exit;
?>


<?php

}
//******************* Redirect prime block ************************//

if (isset($_GET['dm'])) {


    $encryption  = get_option('prime_block_link');

    $decoded = str_rot13(str_rot13($encryption));
    $url = 'http://www.amazon.se/tryprimefree?tag=' . $decoded;
    echo "<script>window.location.href = '{$url}';</script>";
    exit;
?>


<?php

}

if (isset($_GET['pm'])) {


    $encryption  = $_GET['pm'];

    $ciphering = "AES-128-CTR";
    $options = 0;
    $decryption_iv = '1234567891011121';
    // Store the decryption key
    $decryption_key = "GeeksforGeeks";
    // Use openssl_decrypt() function to decrypt the data
    $decryption = openssl_decrypt($encryption, $ciphering, $decryption_key, $options, $decryption_iv);
    $url = 'http://www.amazon.se/tryprimefree?tag=' . $decryption;
    echo "<script>window.location.href = '{$url}';</script>";
    exit;
?>


<?php

}
