/**
 * TV & Movie Tracker - Dedicated Unwatched Loader
 * Version 1.2.1 - 100% Standalone (No Handoff)
 * Author: South Florida Web Advisors
 */
jQuery(function($) {
    const UnwatchedStandalone = {
        init: function() {
            if (tvm_app.current_view === 'tv-unwatched') {
                // Completely block core module interference for this view
                $(document).off('tvm_tab_switch');
                $(document).off('click', '.tvm-tv-trigger'); 
                
                this.loadGrid();
                this.bindIsolatedEvents();
            }
        },

        bindIsolatedEvents: function() {
            // Localized listener only for items within this specific grid
            $(document).on('click', '#tvm-watchlist-grid .tvm-surgical-trigger', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const id = $(e.currentTarget).data('id');
                const title = $(e.currentTarget).data('title');
                this.fetchEpisodesSurgically(id, title);
            });

            // Localized watch toggle for the unwatched list
            $(document).on('click', '#tvm-unwatched-inline-container .tvm-surgical-watch', (e) => {
                const $btn = $(e.currentTarget);
                this.toggleWatchStatus($btn);
            });
        },

        loadGrid: function() {
            const $grid = $('#tvm-watchlist-grid');
            $grid.html('<div style="padding:40px; text-align:center;">🚀 Building Unwatched Grid...</div>');

            $.post(tvm_app.ajax_url, { 
                action: 'tvm_get_tv_unwatched_surgical', 
                nonce: tvm_app.nonce 
            }, (res) => {
                if (res.success) {
                    this.renderGrid(res.data.items);
                }
            });
        },

        renderGrid: function(items) {
            let html = '';
            items.forEach(item => {
                const poster = item.poster_path ? `https://image.tmdb.org/t/p/w185${item.poster_path}` : '';
                html += `
                <div class="tvm-movie-card">
                    <div class="tvm-poster-wrapper">
                        <div class="tvm-badge-stats">${item.aired_unwatched_count}</div>
                        <div class="tvm-surgical-trigger" data-id="${item.id}" data-title="${item.title}" style="cursor:pointer;">
                            <img src="${poster}" style="width:100%; display:block;">
                        </div>
                    </div>
                    <h5 style="margin:8px 0; font-size:10px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                </div>`;
            });

            $('#tvm-watchlist-grid').addClass('tvm-locked-grid').css('opacity', '1').html(html);

            if (!$('#tvm-unwatched-inline-container').length) {
                $('#tvm-watchlist-grid').after('<div id="tvm-unwatched-inline-container" style="display:none; clear:both; margin-top:30px; border-top:2px solid #eee; padding-top:20px;"></div>');
            }
        },

        fetchEpisodesSurgically: function(id, title) {
            const $container = $('#tvm-unwatched-inline-container');
            $container.show().html(`<div style="padding:20px; text-align:center;">🔍 Loading Unwatched for ${title}...</div>`);
            
            $('html, body').animate({ scrollTop: $container.offset().top - 50 }, 500);

            $.post(tvm_app.ajax_url, { 
                action: 'tvm_get_unwatched_episodes_surgical', 
                series_id: id,
                nonce: tvm_app.nonce 
            }, (res) => {
                if (res.success && res.data.length > 0) {
                    this.renderEpisodeList(title, res.data);
                } else {
                    $container.html('<p style="padding:20px; text-align:center;">All caught up!</p>');
                }
            });
        },

        renderEpisodeList: function(title, episodes) {
            let html = `<div>
                <h3 style="margin-bottom:20px; color:#2271b1;">${title}</h3>
                <div class="tvm-surgical-episode-list">`;

            episodes.forEach(ep => {
                html += `
                <div class="tvm-episode-row" id="ep-row-${ep.id}" style="border-left:5px solid #2271b1; margin-bottom:12px; background:#fff; padding:15px; border-radius:8px; border-top:1px solid #eee; border-right:1px solid #eee; border-bottom:1px solid #eee;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div style="flex:1;">
                            <div style="font-weight:800; color:#1d2327; font-size:15px;">S${ep.season}E${ep.number} - ${ep.title.replace(/^S\d+E\d+\s-\s/i, '')}</div>
                            <div style="font-size:11px; color:#999; margin-top:3px;">Aired: ${ep.air_date}</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <div class="tvm-surgical-sources" style="display:flex; gap:6px;">${ep.sources_html}</div>
                            <span class="dashicons dashicons-hidden tvm-surgical-watch" 
                                  data-id="${ep.id}" 
                                  style="cursor:pointer; color:#ccc; font-size:26px; width:26px; height:26px;"></span>
                        </div>
                    </div>
                    <div style="margin-top:10px; font-size:13px; color:#666; line-height:1.4;">${ep.overview || ''}</div>
                </div>`;
            });

            html += `</div></div>`;
            $('#tvm-unwatched-inline-container').html(html);
        },

        toggleWatchStatus: function($btn) {
            const epId = $btn.data('id');
            $btn.css('opacity', '0.5');

            $.post(tvm_app.ajax_url, { 
                action: 'tvm_toggle_episode_watched', 
                episode_id: epId, 
                watched: 'true', 
                nonce: tvm_app.nonce 
            }, (res) => {
                if (res.success) {
                    $(`#ep-row-${epId}`).fadeOut(300, function() {
                        $(this).remove();
                        if ($('.tvm-episode-row').length === 0) {
                            $('#tvm-unwatched-inline-container').html('<p style="padding:20px; text-align:center;">Series Completed!</p>');
                        }
                    });
                }
            });
        }
    };

    UnwatchedStandalone.init();
});
