/**
 * AJAX Handler for TMDb Import
 */
jQuery(document).ready(function($) {
    $('.tvm-import-btn').on('click', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const $spinner = $row.find('.spinner');
        const tmdbId = $btn.data('id');
        const mediaType = $btn.data('type');

        // Disable button and show spinner
        $btn.prop('disabled', true).text('Importing...');
        $spinner.addClass('is-active');

        $.ajax({
            url: tvm_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'tvm_import_item',
                nonce: tvm_admin.nonce,
                tmdb_id: tmdbId,
                type: mediaType
            },
            success: function(response) {
                if (response.success) {
                    $btn.removeClass('button-primary').addClass('button-disabled').text('Imported');
                    alert(response.data.message);
                } else {
                    alert('Error: ' + response.data);
                    $btn.prop('disabled', false).text('Import to Library');
                }
            },
            error: function() {
                alert('Server Error: Could not complete import.');
                $btn.prop('disabled', false).text('Import to Library');
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });
});
