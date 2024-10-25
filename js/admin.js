jQuery(document).ready(function($) {
    function generateFilename(imageUrl, cell) {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'generate_image_filename',
                image_url: imageUrl
            },
            success: function(response) {
                if (response.success) {
                    // Update the cell with the generated filename
                    cell.html('<code>' + response.data.filename + '</code>');
                    
                    // Update the corresponding filename input
                    const row = cell.closest('tr');
                    const filenameInput = row.find('input[name="custom_filename"]');
                    if (filenameInput.length && !filenameInput.val()) {
                        filenameInput.val(response.data.filename);
                    }
                } else {
                    let errorMessage = response.data && response.data.message ? response.data.message : 'Generation failed';
                    let errorDetails = response.data && response.data.details ? response.data.details : '';
                    let displayError = errorMessage + (errorDetails ? ': ' + errorDetails : '');
                    
                    cell.html('<em class="error" title="' + displayError + '">Generation failed</em>');
                    console.error('GUCI Error:', {
                        message: errorMessage,
                        details: errorDetails,
                        response: response
                    });
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                let errorMessage = textStatus + ': ' + errorThrown;
                cell.html('<em class="error" title="' + errorMessage + '">Generation failed</em>');
                console.error('GUCI Ajax Error:', {
                    status: jqXHR.status,
                    statusText: jqXHR.statusText,
                    responseText: jqXHR.responseText,
                    error: errorThrown
                });
            }
        });
    }

    // Find cells with "Generating..." and trigger filename generation with delay
    $('.ai-filename-cell').each(function(index) {
        const cell = $(this);
        if (cell.find('.generating').length) {
            const imageUrl = cell.data('image-url');
            console.log('Processing image:', imageUrl);
            // Add delay to avoid overwhelming the API
            setTimeout(function() {
                generateFilename(imageUrl, cell);
            }, index * 2000); // 2 second delay between each request
        }
    });

    // Check if scan is in progress
    if ($('.scan-progress-wrapper').length > 0) {
        // Refresh the page every 5 seconds to update progress
        setTimeout(function() {
            window.location.reload();
        }, 5000);
    }
});
