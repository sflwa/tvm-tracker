/**
 * TVM Tracker Frontend Interactivity (AJAX and UI)
 *
 * Handles:
 * 1. Toggling of season and episode collapsible sections.
 * 2. AJAX requests for adding/removing a show from the tracker.
 * 3. AJAX requests for marking an episode as watched/unwatched.
 * 4. AJAX requests for loading unwatched episode details on the Unwatched View.
 *
 * @since 1.3.4
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
     * Shows a localized status message near the target container.
     * This function is designed to display messages above the single results box.
     * @param {string} message The message to display.
     * @param {string} type 'success' or 'error'.
     * @param {jQuery} $containerElement The container (#tvm-episode-results) to place the message before.
     */
    function showContainerStatusMessage(message, type, $containerElement) {
        // Remove any existing local status messages first
        $('.tvm-local-global-status').remove();

        // Create the message box and insert it immediately before the container
        const $messageBox = $('<div class="tvm-global-status tvm-local-global-status"></div>')
            .addClass(type)
            .html(message)
            .hide() // Start hidden
            .insertBefore($containerElement);

        $messageBox.fadeIn(300);

        // Auto-fade after 4 seconds
        setTimeout(function() {
            $messageBox.fadeOut(500, function() {
                $(this).remove(); // Remove from DOM after fading out
            });
        }, 4000);
    }


    // --- Global Status Message (Used for Top-Level Actions like Add Show) ---
    function showGlobalStatusMessage(message, type) {
        const $container = $('.tvm-tracker-container');
        let $messageBox = $container.find('.tvm-global-status').not('.tvm-local-global-status');

        if ($messageBox.length === 0) {
            // Create a status box at the top of the container if it doesn't exist
            $messageBox = $('<div class="tvm-global-status"></div>').prependTo($container);
        }

        $messageBox.html(message).removeClass('success error').addClass(type).fadeIn(300).delay(3000).fadeOut(500);
    }


    // --- Season/Episode Collapse/Expand Logic (Details Page) ---

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

        const isOpening = !$container.hasClass('is-open');
        
        if ($container.hasClass('tvm-season') && isOpening) {
            $('.tvm-season.is-open').not($container).removeClass('is-open').find('>.tvm-season-content').slideUp(300);
        } else if ($container.hasClass('tvm-episode') && isOpening) {
            $container.siblings('.tvm-episode.is-open').removeClass('is-open').find('>.tvm-episode-content').slideUp(300);
        }

        $container.toggleClass('is-open'); 
        $content.slideToggle(300);
    });

    // --- Show Tracking Toggle (Add/Remove from Tracker) ---
    // Delegated click handler for the tracker toggle button
    $('.tvm-tracker-container').on('click', '#tvm-tracker-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const isTracking = $button.data('is-tracked') === true;
        const titleId = $button.data('title-id');
        const titleName = $button.data('title-name');
        const totalEpisodes = $button.data('total-episodes');
        const itemType = $button.data('item-type'); // NEW: Get item type
        const releaseDate = $button.data('release-date'); // NEW: Get release date (for movies)
        
        const data = {
            action: 'tvm_tracker_toggle_show',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            title_name: titleName,
            total_episodes: totalEpisodes,
            is_tracking: isTracking ? 'true' : 'false',
            item_type: itemType, // NEW
            release_date: releaseDate // NEW
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    const newTrackingState = response.data.action === 'removed' ? false : true;
                    const newButtonText = newTrackingState ? 'Tracking' : 'Add to Tracker';
                    const newButtonClass = newTrackingState ? 'tvm-button-remove' : 'tvm-button-add';

                    $button.text(newButtonText)
                           .removeClass('tvm-button-add tvm-button-remove')
                           .addClass(newButtonClass)
                           .data('is-tracked', newTrackingState);

                    showGlobalStatusMessage(response.data.message, 'success');
                } else {
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
    $('.tvm-tracker-container').on('click', '.tvm-episode-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = $button.data('title-id');
        const episodeId = $button.data('episode-id');
        const isCurrentlyWatched = $button.data('is-watched') === true;
        
        const newStateWatched = !isCurrentlyWatched;
        
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
                    
                    showGlobalStatusMessage(response.data.message || 'Updated status.', 'success');

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
    
    // --- Episode Watch Status Toggle (Unwatched Page Button - Mark Watched) ---
    $('.tvm-tracker-container').on('click', '.tvm-unwatched-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        const $episodeItem = $button.closest('.tvm-episode-item');
        $button.prop('disabled', true).text('Processing...');

        const titleId = parseInt($button.data('title-id'));
        const episodeId = parseInt($button.data('episode-id'));
        const type = $button.data('unwatched-type'); 
        
        const data = {
            action: 'tvm_tracker_toggle_episode',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            episode_id: episodeId,
            is_watched: 'true'
        };
        
        const $resultContainer = $('#tvm-episode-results');
        const $selectedPoster = $('.tvm-unwatched-poster-selector.is-selected');


        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showContainerStatusMessage(response.data.message || 'Episode marked watched.', 'success', $resultContainer);
                    
                    // 1. Remove the episode item from the list display
                    $episodeItem.fadeOut(300, function() {
                        $(this).remove();
                        
                        // 2. Check if the list is now empty and reload if necessary
                        if ($('.tvm-episode-item').length === 0) {
                            // If the list is empty, clear the container and remove the selected poster entirely (since all episodes are watched now)
                            $resultContainer.html('<p class="tvm-empty-list">' + 'All ' + type + ' episodes viewed for this show.' + '</p>');
                            $selectedPoster.fadeOut(300, function() {
                                $(this).remove();
                            });
                            // No need to try to load the "next" episode if the current list type is empty
                            return; 
                        } else {
                            // 3. Since the list still has episodes, reload the list to update counts and sorting.
                            // We need to re-select the poster to trigger the reload.
                            loadUnwatchedEpisodeDetails(titleId, type);
                        }
                    });

                } else {
                    showContainerStatusMessage(response.data.message || 'An unknown server error occurred.', 'error', $resultContainer);
                }
            })
            .fail(function() {
                 showContainerStatusMessage('AJAX Request Failed.', 'error', $resultContainer);
            })
            .always(function() {
                $button.prop('disabled', false);
            });
    });

    /**
     * Reusable function to load unwatched episode details (list of upcoming or past).
     * @param {number} titleId 
     * @param {string} type 'upcoming' or 'past'
     */
    function loadUnwatchedEpisodeDetails(titleId, type) {
        const $detailCard = $('#tvm-episode-results');
        
        // Use the combined selector to find the poster in either grid
        const $selectedPoster = $('.tvm-unwatched-poster-selector[data-title-id="' + titleId + '"]');

        // Show spinner/loading state in detail card area
        $detailCard.html('<div class="tvm-loading-spinner">' + 'Loading episode details...' + '</div>');
        
        // Remove selection highlight from all posters of the same type
        $('.tvm-unwatched-poster-selector').removeClass('is-selected');
        
        // Highlight the selected poster (remains highlighted regardless of grid)
        $selectedPoster.addClass('is-selected');
        
        // Data to send via AJAX
        const data = {
            action: 'tvm_tracker_load_unwatched_episode',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            type: type // Send the correct type parameter to the PHP handler
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    // Inject the rendered episode card HTML
                    $detailCard.html(response.data.html);
                } else {
                    // Handle failure/no more episodes
                    $detailCard.html('<p class="tvm-empty-list">' + (response.data.message || 'All unwatched episodes viewed for this show.') + '</p>');
                    
                    // If no more, remove selection highlight
                    $selectedPoster.removeClass('is-selected');
                }
            })
            .fail(function() {
                $detailCard.html('<p class="tvm-error-message">AJAX request failed to load episode data.</p>');
            });
    }

    // --- Unwatched Page Show Selector (Poster Click) ---
    $('.tvm-tracker-container').on('click', '.tvm-unwatched-poster-selector', function(e) {
        e.preventDefault();
        const $item = $(this);
        const titleId = $item.data('title-id');
        const type = $item.data('unwatched-type'); 
        
        // CRITICAL FIX: Ensure the correct type is passed to PHP
        loadUnwatchedEpisodeDetails(titleId, type);
    });
});
