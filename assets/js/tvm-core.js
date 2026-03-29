/**
 * TV & Movie Tracker - Core Orchestrator
 * Version 2.0.0 - Fix Stream Toggle Media Reset
 */
jQuery(function($) {
    window.TVM_Core = {
        updateCounter: function(count) {
            if (!$('#tvm-results-count').length) {
                $('.tvm-vault-controls').prepend(`<span id="tvm-results-count" style="font-size:11px; font-weight:700; color:#2271b1; background:#e7f3ff; padding:2px 8px; border-radius:10px; margin-right:5px;">${count} shown</span>`);
            } else { $('#tvm-results-count').text(`${count} shown`); }
        },
        showLoading: function() { $('#tvm-watchlist-grid').css('opacity', '0.5'); },
        hideLoading: function() { $('#tvm-watchlist-grid').css('opacity', '1'); }
    };

    // Routing Logic
    $(document).on('click', '.tvm-nav-link', function(e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.tvm-nav-link').removeClass('active'); 
        $(this).addClass('active');
        $('#tvm-view-watchlist, #tvm-view-search, #tvm-view-settings').hide();
        if (tab === 'search') $('#tvm-view-search').show();
        else if (tab === 'settings') $('#tvm-view-settings').show();
        else $('#tvm-view-watchlist').show();
        $(document).trigger('tvm_tab_switch', [tab]);
    });

    $(document).on('click', '.tvm-type-tab', function() {
        $('.tvm-type-tab').removeClass('active');
        $(this).addClass('active');
        const mediaType = $(this).data('type');
        window.current_media_type = mediaType;
        
        // UI Logic: Only show Calendar button for TV
        if (mediaType === 'tv') {
            $('.tvm-calendar-toggle').show();
        } else {
            $('.tvm-calendar-toggle').hide();
            // If we were on calendar view and switched to movies, reset filter to 'all'
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
        
        // Pass the filter name as a secondary argument for modules to pick up
        $(document).trigger('tvm_filter_change', [filter]);
    });

    $(document).on('input', '#tvm-vault-search-input', () => $(document).trigger('tvm_filter_change'));
    
    // FIX: Maintain current media type context when toggling stream-only filter
    $(document).on('change', '#tvm-stream-only-toggle', function() {
        const currentFilter = $('.tvm-filter-btn.active').data('filter') || 'all';
        $(document).trigger('tvm_filter_change', [currentFilter]);
    });

    const closeModal = () => $('#tvm-details-modal').hide();
    $('#tvm-close-modal').on('click', closeModal);
    $(document).on('click', '#tvm-details-modal', function(e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', e => { if (e.key === "Escape") closeModal(); });

    // STARTUP ROUTE - Preserve current media type if already set
    if (!window.current_media_type) {
        window.current_media_type = 'tv';
    }
    
    $(window).on('load', function() {
        setTimeout(() => {
            // Default visibility for startup
            if (window.current_media_type === 'tv') {
                $('.tvm-calendar-toggle').show();
            } else {
                $('.tvm-calendar-toggle').hide();
            }
            $(document).trigger('tvm_tab_switch', ['watchlist']);
        }, 200);
    });
});
