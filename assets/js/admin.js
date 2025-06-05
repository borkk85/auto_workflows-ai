(function($) {
    'use strict';
    
    // When the document is ready
    $(document).ready(function() {
        // Handle the toggle price update flag checkbox
        $('.toggle-price-update').on('change', function() {
            var postId = $(this).data('post-id');
            var value = $(this).is(':checked') ? 1 : 0;
            
            $.ajax({
                url: autoWorkflowsAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'toggle_price_update_flag',
                    post_id: postId,
                    value: value,
                    nonce: autoWorkflowsAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification if needed
                        console.log(autoWorkflowsAdmin.toggleSuccess);
                    } else {
                        console.error(autoWorkflowsAdmin.toggleError);
                    }
                },
                error: function(error) {
                    console.error(autoWorkflowsAdmin.toggleError, error);
                }
            });
        });
    });
    
})(jQuery);