/**
 * TV & Movie Tracker - Core Orchestrator
 * Version 2.0.5 - Expanded Stats Visualization
 */
jQuery(function($) {
    window.TVM_Core = {
        updateCounter: function(count) {
            if (!$('#tvm-results-count').length) {
                $('.tvm-vault-controls').prepend(`<span id="tvm-results-count" style="font-size:11px; font-weight:700; color:#2271b1; background:#e7f3ff; padding:2px 8px; border-radius:10px; margin-right:5px;">${count} shown</span>`);
            } else { $('#tvm-results-count').text(`${count} shown`); }
        },
        showLoading: function() { $('#tvm-watchlist-grid').css('opacity', '0.5'); },
        hideLoading: function() { $('#tvm-watchlist-grid').css('opacity', '1'); },
        
        initSettings: function() {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_settings', nonce: tvm_app.nonce }, function(res) {
                if (res.success && res.data.raw) {
                    window.tvm_settings_data = res.data.raw;
                    $(document).trigger('tvm_settings_ready');
                }
            });
        },

        loadStatsPage: function() {
            $('#tvm-stats-container').html('<p style="text-align:center; padding:40px;">Crunching numbers...</p>');
            $.post(tvm_app.ajax_url, { action: 'tvm_get_library_stats', nonce: tvm_app.nonce }, function(res) {
                if (res.success) {
                    const s = res.data;
                    
                    let statusHtml = s.tv.statuses.map(st => `
                        <div style="display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px solid #f0f0f0;">
                            <span style="font-weight:600; color:#666;">${st.status}</span>
                            <strong style="color:#2271b1;">${st.total}</strong>
                        </div>
                    `).join('');

                    let yearHtml = s.movies.years.map(yr => {
                        const yrPercent = yr.total > 0 ? Math.round((yr.watched / yr.total) * 100) : 0;
                        return `
                        <div style="margin-bottom:12px;">
                            <div style="display:flex; justify-content:space-between; font-size:11px; margin-bottom:4px;">
                                <strong>${yr.release_year}</strong>
                                <span>${yr.watched} / ${yr.total} watched</span>
                            </div>
                            <div style="background:#f0f0f0; height:4px; border-radius:2px; overflow:hidden;">
                                <div style="background:#2271b1; height:100%; width:${yrPercent}%;"></div>
                            </div>
                        </div>`;
                    }).join('');

                    const html = `
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:30px; margin-top:20px;">
                        <div>
                            <div style="background:#fff; padding:30px; border-radius:12px; border:1px solid #eee; text-align:center; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom:20px;">
                                <span class="dashicons dashicons-desktop" style="font-size:40px; width:40px; height:40px; color:#46b450; margin-bottom:15px;"></span>
                                <h3 style="margin-top:0;">TV Progress</h3>
                                <div style="font-size:32px; font-weight:800; color:#1d2327; margin:10px 0;">${s.tv.percent}%</div>
                                <p style="color:#666; font-weight:600;">${s.tv.watched} of ${s.tv.episodes} Episodes</p>
                                <div style="background:#f0f0f0; height:8px; border-radius:4px; overflow:hidden;">
                                    <div style="background:#46b450; height:100%; width:${s.tv.percent}%;"></div>
                                </div>
                            </div>
                            <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee;">
                                <h4 style="margin:0 0 15px 0;">Series Status Breakdown</h4>
                                ${statusHtml}
                            </div>
                        </div>

                        <div>
                            <div style="background:#fff; padding:30px; border-radius:12px; border:1px solid #eee; text-align:center; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom:20px;">
                                <span class="dashicons dashicons-video-alt3" style="font-size:40px; width:40px; height:40px; color:#2271b1; margin-bottom:15px;"></span>
                                <h3 style="margin-top:0;">Movie Library</h3>
                                <div style="font-size:32px; font-weight:800; color:#1d2327; margin:10px 0;">${s.movies.percent}%</div>
                                <p style="color:#666; font-weight:600;">${s.movies.watched} of ${s.movies.total} Movies</p>
                                <div style="background:#f0f0f0; height:8px; border-radius:4px; overflow:hidden;">
                                    <div style="background:#2271b1; height:100%; width:${s.movies.percent}%;"></div>
                                </div>
                            </div>
                            <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; max-height:400px; overflow-y:auto;">
                                <h4 style="margin:0 0 15px 0;">Movies by Release Year</h4>
                                ${yearHtml}
                            </div>
                        </div>
                    </div>`;
                    $('#tvm-stats-container').html(html);
                }
            });
        }
    };

    // Routing Logic
    $(document).on('click', '.tvm-nav-link', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.tvm-nav-link').removeClass('active'); 
        $(this).addClass('active');
        $('#tvm-view-watchlist, #tvm-view-search, #tvm-view-settings, #tvm-view-stats').hide();
        
        if (tab === 'search') $('#tvm-view-search').show();
        else if (tab === 'settings') $('#tvm-view-settings').show();
        else if (tab === 'stats') {
            $('#tvm-view-stats').show();
            TVM_Core.loadStatsPage();
        } else $('#tvm-view-watchlist').show();
        
        $(document).trigger('tvm_tab_switch', [tab]);
    });

    $(document).on('click', '.tvm-type-tab', function() {
        $('.tvm-type-tab').removeClass('active');
        $(this).addClass('active');
        const mediaType = $(this).data('type');
        window.current_media_type = mediaType;
        
        if (mediaType === 'tv') {
            $('.tvm-calendar-toggle').show();
        } else {
            $('.tvm-calendar-toggle').hide();
            if ($('.tvm-filter-btn.active').data('filter') === 'calendar') {
                $('.tvm-filter-btn').removeClass('active');
                $('.tvm-filter-btn[data-filter="all"]').addClass('active');
            }
        }

        $('#tvm-tv-detail-view').hide();
        $('#tvm-watchlist-grid, .tvm-filters-container').show();
        $(document).trigger('tvm_tab_switch', ['watchlist']);
    });

    $(document).on('click', '.tvm-filter-btn', function() {
        const filter = $(this).data('filter');
        $('.tvm-filter-btn').removeClass('active'); 
        $(this).addClass('active');
        $(document).trigger('tvm_filter_change', [filter]);
    });

    $(document).on('input', '#tvm-vault-search-input', () => $(document).trigger('tvm_filter_change'));
    
    $(document).on('change', '#tvm-stream-only-toggle', function() {
        const currentFilter = $('.tvm-filter-btn.active').data('filter') || 'all';
        $(document).trigger('tvm_filter_change', [currentFilter]);
    });

    const closeModal = () => $('#tvm-details-modal').hide();
    $('#tvm-close-modal').on('click', closeModal);
    $(document).on('click', '#tvm-details-modal', function(e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', e => { if (e.key === "Escape") closeModal(); });

    if (!window.current_media_type) {
        window.current_media_type = 'tv';
    }
    
    $(window).on('load', function() {
        TVM_Core.initSettings();
        setTimeout(() => {
            if (window.current_media_type === 'tv') {
                $('.tvm-calendar-toggle').show();
                $('.tvm-type-tab[data-type="tv"]').addClass('active');
            } else {
                $('.tvm-calendar-toggle').hide();
                $('.tvm-type-tab[data-type="movie"]').addClass('active');
                $('.tvm-type-tab[data-type="tv"]').removeClass('active');
            }
            $(document).trigger('tvm_tab_switch', ['watchlist']);
        }, 200);
    });
});
