jQuery(($) => {
    // console.log('Admin.js loaded'); // Debug: Check if the script is loaded

    let table = $('#wp-logify-activity-log').DataTable({
        processing: true,
        language: {
            processing: 'Please wait...'
        },
        serverSide: true,
        ajax: {
            url: wpLogifyLogPage.ajaxurl,
            type: "POST",
            data: (d) => {
                d.action = 'wp_logify_fetch_logs';
                console.log('Sending AJAX request with data:', d); // Debug: Check data sent with the request
            },
            error: (xhr, error, code) => {
                console.log('AJAX error:', error);
                console.log('AJAX error code:', code);
            }
        },
        columns: [
            {
                data: "id",
                width: '70px'
            },
            { data: "date_time" },
            { data: "user" },
            { data: "user_ip" },
            { data: "event_type" },
            { data: "object" },
            {
                className: 'details-control',
                orderable: false,
                defaultContent: '<span class="wp-logify-show-details">Show</span>',
                width: '100px'
            }
        ],
        order: [[1, 'desc']],
        searching: true,
        paging: true,
        pageLength: 20,
        lengthMenu: [10, 20, 50, 100]
    });

    // Custom search box
    $('#wp-logify-search-box').on('keyup', function () {
        table.search(this.value).draw();
    });

    // Add event listener for opening and closing details
    table.on('click', 'tr', function (e) {
        // Ignore link clicks.
        if ($(e.target).is('a, a *')) {
            return;
        }

        // Get the row.
        let tr = $(this);

        // Ignore clicks on the header row.
        if (tr.parent().is('thead')) {
            return;
        }

        let row = table.row(tr);

        if (row.child.isShown()) {
            // This row is already open - close it.
            row.child.hide();
            tr.find('td.details-control span').text('Show');
        }
        else {
            // Open this row.
            row.child(row.data().details, 'details-row').show();
            tr.find('td.details-control span').text('Hide');
        }
    });
});
