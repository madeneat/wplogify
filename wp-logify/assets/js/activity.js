// js/wp-logify-activity.js

(function ($) {
    function sendActivity() {
        $.ajax({
            type: 'POST',
            url: wpLogifyActivity.ajax_url,
            data: {
                action: 'track_user_activity',
                nonce: wpLogifyActivity.nonce
            },
            success: function (response) {
                console.log('User activity tracked', response);
            },
            error: function (error) {
                console.error('Error tracking user activity', error);
            }
        });
    }

    $(document).ready(function () {
        // Send initial activity on page load
        sendActivity();

        // Send activity every 15 seconds
        setInterval(sendActivity, 15000);
    });
})(jQuery);
