/**
 * TVM Tracker Frontend Interactivity (AJAX and UI)
 *
 * Handles:
 * 1. Toggling of season and episode collapsible sections (with UX enhancements).
 * 2. AJAX requests for adding/removing a show from the tracker.
 * 3. AJAX requests for marking an episode as watched/unwatched.
 *
 * @since 1.3.1
 */

jQuery(document).ready(function($) {
    'use strict';

    // Check if the localized AJAX object is available
    if (typeof tvmTrackerAjax === 'undefined' || !tvmTrackerAjax.ajax_url) {
        console.error('TVM Tracker: AJAX localization data is missing.');
        return;
    }

    // --- Utility Functions ---

    /**
     * Shows a temporary status message to the user near the target element.
     * @param {string} message The message to display.
     * @param {string} type 'success' or 'error'.
     * @param {jQuery} $targetElement The element near which the message should be displayed.
     */
    function showLocalStatusMessage(message, type, $targetElement) {
        // Clear any existing local messages near the target
        $targetElement.siblings('.tvm-local-status').remove();

        const $messageBox = $('<span class="tvm-local-status ' + type + '"></span>');
        $messageBox.html(message).insertAfter($targetElement).fadeIn(200);

        // Auto-fade after 3 seconds
        setTimeout(function() {
            $messageBox.fadeOut(500, function() {
                $(this).remove();
            });
        }, 3000);
    }


    // --- Global Status Message (Used for Top-Level Actions like Add Show) ---
    function showGlobalStatusMessage(message, type) {
        const $container = $('.tvm-tracker-container');
        let $messageBox = $container.find('.tvm-global-status');

        if ($messageBox.length === 0) {
            // Create a status box at the top of the container if it doesn't exist
            $messageBox = $('<div class="tvm-global-status"></div>').prependTo($container);
        }

        $messageBox.html(message).removeClass('success error').addClass(type).fadeIn(300).delay(3000).fadeOut(500);
    }


    // --- Season/Episode Collapse/Expand Logic ---

    // Handler for both season and episode headers
    $('.tvm-tracker-container').on('click', '.tvm-season-header, .tvm-episode-header', function(e) {
        // Prevent action if a child interactive element was clicked (buttons, anchors, or the episode toggle button)
        if ($(e.target).is('button, a, .tvm-episode-toggle')) {
            return;
        }

        const $header = $(this);
        // Find the closest season or episode container
        const $container = $header.closest('.tvm-season, .tvm-episode'); 
        // Find the content to toggle within that container
        const $content = $container.find('>.tvm-season-content, >.tvm-episode-content');

        // Toggle the 'is-open' class on the container element
        const isOpening = !$container.hasClass('is-open');
        
        // UX Improvement: If opening a season, close others
        if ($container.hasClass('tvm-season') && isOpening) {
            $('.tvm-season.is-open').not($container).removeClass('is-open').find('>.tvm-season-content').slideUp(300);
        } else if ($container.hasClass('tvm-episode') && isOpening) {
            // If opening an episode, close other open episodes in the same season
            $container.siblings('.tvm-episode.is-open').removeClass('is-open').find('>.tvm-episode-content').slideUp(300);
        }

        // Toggle the current item
        $container.toggleClass('is-open'); 
        $content.slideToggle(300);
    });

    // --- Show Tracking Toggle (Add/Remove from Tracker) ---
    // Delegated click handler for the tracker toggle button
    $('.tvm-tracker-container').on('click', '#tvm-tracker-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        // Read current state from data attribute
        const isTracking = $button.data('is-tracked') === true;
        const titleId = $button.data('title-id');
        const titleName = $button.data('title-name');
        const totalEpisodes = $button.data('total-episodes');
        
        // Data to send via AJAX
        const data = {
            action: 'tvm_tracker_toggle_show',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            title_name: titleName,
            total_episodes: totalEpisodes,
            is_tracking: isTracking ? 'true' : 'false' // Current state being checked
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    // Success: Update UI state
                    const newTrackingState = response.data.action === 'removed' ? false : true;
                    const newButtonText = newTrackingState ? 'Tracking' : 'Add to Tracker';
                    const newButtonClass = newTrackingState ? 'tvm-button-remove' : 'tvm-button-add';

                    $button.text(newButtonText)
                           .removeClass('tvm-button-add tvm-button-remove')
                           .addClass(newButtonClass)
                           .data('is-tracked', newTrackingState);

                    showGlobalStatusMessage(response.data.message, 'success');
                } else {
                    // Error: Display error message from PHP
                    showGlobalStatusMessage(response.data.message || 'An unknown server error occurred.', 'error');
                }
            })
            .fail(function() {
                 showGlobalStatusMessage('AJAX Request Failed. Check your API key or network connection.', 'error');
            })
            .always(function() {
                $button.prop('disabled', false);
            });
    });

    // --- Episode Watch Status Toggle (Details Page Button) ---
    // Handles button clicks on the detail page episodes
    $('.tvm-tracker-container').on('click', '.tvm-episode-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = $button.data('title-id');
        const episodeId = $button.data('episode-id');
        // Read current state from data attribute
        const isCurrentlyWatched = $button.data('is-watched') === true;
        
        // Determine the action: we want the *new* state.
        const newStateWatched = !isCurrentlyWatched;
        
        // Data to send via AJAX
        const data = {
            action: 'tvm_tracker_toggle_episode',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            episode_id: episodeId,
            is_watched: newStateWatched ? 'true' : 'false'
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    const newButtonClass = newStateWatched ? 'tvm-button-watched' : 'tvm-button-unwatched';
                    const newButtonText = newStateWatched ? 'Watched' : 'Unwatched';

                    // Update button UI
                    $button.text(newButtonText)
                           .removeClass('tvm-button-watched tvm-button-unwatched')
                           .addClass(newButtonClass)
                           .data('is-watched', newStateWatched);
                    
                    // Show message near the button/episode
                    showLocalStatusMessage(response.data.message || 'Updated status.', 'success', $button);

                } else {
                    // Error: Show message near the button/episode
                    showLocalStatusMessage(response.data.message || 'An unknown server error occurred.', 'error', $button);
                }
            })
            .fail(function() {
                 showLocalStatusMessage('AJAX Request Failed.', 'error', $button);
            })
            .always(function() {
                $button.prop('disabled', false);
            });
    });
    
    // --- Episode Watch Status Toggle (Unwatched Page Button - Mark Watched) ---
    $('.tvm-tracker-container').on('click', '.tvm-unwatched-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = $button.data('title-id');
        const episodeId = $button.data('episode-id');
        
        // Data to send via AJAX (always marking watched=true on this page)
        const data = {
            action: 'tvm_tracker_toggle_episode',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            episode_id: episodeId,
            is_watched: 'true'
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showGlobalStatusMessage(response.data.message || 'Episode marked watched.', 'success');
                    // Success: Remove the episode item from the Unwatched list display
                    $button.closest('.tvm-unwatched-item').fadeOut(300, function() {
                        $(this).remove();
                        // Check if the list is empty and display a message if so (optional UX)
                        if ($('.tvm-unwatched-item').length === 0) {
                            $('.tvm-unwatched-list').after('<p class="tvm-empty-list">' + 'You are caught up on all unwatched episodes!' + '</p>');
                        }
                    });
                } else {
                    showGlobalStatusMessage(response.data.message || 'An unknown server error occurred.', 'error');
                }
            })
            .fail(function() {
                 showGlobalStatusMessage('AJAX Request Failed.', 'error');
            })
            .always(function() {
                $button.prop('disabled', false);
            });
    });
});
