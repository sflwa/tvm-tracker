/**
 * TVM Tracker Frontend Interactivity (AJAX and UI)
 *
 * Handles:
 * 1. Toggling of season and episode collapsible sections.
 * 2. AJAX requests for adding/removing a show/movie from the tracker.
 * 3. AJAX requests for marking an episode/movie as watched/unwatched.
 * 4. AJAX requests for loading unwatched episode details on the Unwatched View.
 * 5. Bulk status updates for seasons/series.
 * 6. Movie tab switching (My Tracker > Movies).
 *
 * @since 1.4.0
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
        if ($(e.target).is('button, a, .tvm-episode-toggle, #tvm-movie-toggle-watched, .tvm-bulk-episode-toggle')) {
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
    // Listens to the common class 'tvm-toggle-show-btn' 
    $('.tvm-tracker-container').on('click', '.tvm-toggle-show-btn', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        // CRITICAL FIX: Get data directly from the clicked button attribute
        const isTracking = $button.data('is-tracked') === true;
        const titleId = $button.data('title-id');
        const titleName = $button.data('title-name');
        const totalEpisodes = $button.data('total-episodes');
        const itemType = $button.data('item-type');
        const releaseDate = $button.data('release-date');
        // Ensure this is read as a string 'true' or 'false' for reliable PHP processing
        const isMovieWatched = $button.attr('data-is-movie-watched') === 'true' ? 'true' : 'false'; 
        
        const data = {
            action: 'tvm_tracker_toggle_show',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            title_name: titleName,
            total_episodes: totalEpisodes,
            is_tracking: isTracking ? 'true' : 'false',
            item_type: itemType, 
            release_date: releaseDate,
            is_movie_watched: isMovieWatched 
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    const newTrackingState = response.data.action === 'removed' ? false : true;
                    
                    // If the action was ADDED, reload the page to switch from Add buttons to Toggle/Remove buttons
                    // This is necessary for movies to reflect the initial 'is_watched' status.
                    if (response.data.action === 'added') {
                        // Show a temporary success message and reload instantly for clean state transition
                        showGlobalStatusMessage(response.data.message + '. Reloading...', 'success');
                        window.location.reload(); 
                        return;
                    }

                    // For series/movie removal, update the button text locally
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

    // --- Movie Watched Status Toggle (Details Page Button & Movies to Watch List) ---
    // Listens for clicks on the details page movie toggle OR the "Mark Watched" button on the list page
    $('.tvm-tracker-container').on('click', '#tvm-movie-toggle-watched, .tvm-movie-list-item .tvm-button-watched', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = parseInt($button.data('title-id'));
        
        // Determine current state based on where the button is clicked
        // If it has data-is-watched, use that. Otherwise, assume 'want to see' on the list page (false)
        const isCurrentlyWatched = $button.data('is-watched') === true;
        const newStateWatched = !isCurrentlyWatched;
        
        const data = {
            action: 'tvm_tracker_toggle_movie_watched',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            is_watched: newStateWatched ? 'true' : 'false'
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showGlobalStatusMessage(response.data.message || 'Movie status updated.', 'success');
                    
                    // CRITICAL: Reload to reflect the movie moving between the "Want to See" and "Watched" tabs/sections
                    window.location.reload(); 

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


    // --- Episode Watch Status Toggle (Individual Episode Button) ---
    $('.tvm-tracker-container').on('click', '.tvm-episode-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = parseInt($button.data('title-id'));
        const episodeId = parseInt($button.data('episode-id'));
        
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
                    
                    // NOTE: For detail page, we don't reload, but rely on the small button UI change.
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
    
    // --- BULK Episode Watch Status Toggle (Season/Series) ---
    $('.tvm-tracker-container').on('click', '.tvm-bulk-episode-toggle', function(e) {
        e.preventDefault();
        const $button = $(this);
        $button.prop('disabled', true).text('Processing...');

        const titleId = parseInt($button.data('title-id'));
        const seasonNumber = $button.data('season-number') || 0; // 0 for series
        const isWatched = $button.data('is-watched') === true; // The desired NEW status

        const data = {
            action: 'tvm_tracker_toggle_bulk_episodes',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            season_number: seasonNumber,
            is_watched: isWatched ? 'true' : 'false'
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    showGlobalStatusMessage(response.data.message || 'Status updated. Reloading...', 'success');
                    // CRITICAL: Reload to reflect mass change across all episodes/seasons
                    window.location.reload(); 
                } else {
                    showGlobalStatusMessage(response.data.message || 'Bulk update failed.', 'error');
                }
            })
            .fail(function() {
                 showGlobalStatusMessage('AJAX Request Failed.', 'error');
            })
            .always(function() {
                // If reload didn't happen (error), re-enable button
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
                            // If the list is empty, clear the container and remove the selected poster entirely
                            $resultContainer.html('<p class="tvm-empty-list">' + 'All ' + type + ' episodes viewed for this show.' + '</p>');
                            $selectedPoster.fadeOut(300, function() {
                                $(this).remove();
                            });
                            return; 
                        } else {
                            // 3. Reload the list to update counts and sorting.
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
     */
    function loadUnwatchedEpisodeDetails(titleId, type) {
        const $detailCard = $('#tvm-episode-results');
        const $selectedPoster = $('.tvm-unwatched-poster-selector[data-title-id="' + titleId + '"]');

        $detailCard.html('<div class="tvm-loading-spinner">' + 'Loading episode details...' + '</div>');
        $('.tvm-unwatched-poster-selector').removeClass('is-selected');
        $selectedPoster.addClass('is-selected');
        
        const data = {
            action: 'tvm_tracker_load_unwatched_episode',
            nonce: tvmTrackerAjax.nonce,
            title_id: titleId,
            type: type 
        };

        $.post(tvmTrackerAjax.ajax_url, data)
            .done(function(response) {
                if (response.success) {
                    $detailCard.html(response.data.html);
                } else {
                    $detailCard.html('<p class="tvm-empty-list">' + (response.data.message || 'All unwatched episodes viewed for this show.') + '</p>');
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
        
        loadUnwatchedEpisodeDetails(titleId, type);
    });

    // --- Movie Tracker Tab Switching ---
    $('.tvm-tracker-container').on('click', '.tvm-movie-tab-btn', function(e) {
        e.preventDefault();
        const $button = $(this);
        const tab = $button.data('tab');

        // 1. Update the button active state
        $('.tvm-movie-tab-btn').removeClass('is-active');
        $button.addClass('is-active');

        // 2. Update the content visibility
        $('.tvm-tab-content').removeClass('is-active');
        $('#tvm-tab-' + tab).addClass('is-active');

        // 3. Update URL without triggering full reload for persistent state
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('tvm_movie_tab', tab);
        history.pushState(null, '', currentUrl.toString());
    });
});
