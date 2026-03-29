/**
 * TV & Movie Tracker - Core Orchestrator
 * Version 1.9.7 - Dependency Safety Check
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
        window.current_media_type = $(this).data('type');
        $('#tvm-tv-detail-view').hide();
        $('#tvm-watchlist-grid, .tvm-filters-container').show();
        $(document).trigger('tvm_tab_switch', ['watchlist']);
    });

    $(document).on('click', '.tvm-filter-btn', function() {
        $('.tvm-filter-btn').removeClass('active'); $(this).addClass('active');
        $(document).trigger('tvm_filter_change');
    });

    $(document).on('input', '#tvm-vault-search-input', () => $(document).trigger('tvm_filter_change'));
    $(document).on('change', '#tvm-stream-only-toggle', () => $(document).trigger('tvm_filter_change'));

    const closeModal = () => $('#tvm-details-modal').hide();
    $('#tvm-close-modal').on('click', closeModal);
    $(document).on('click', '#tvm-details-modal', function(e) { if (e.target === this) closeModal(); });
    $(document).on('keydown', e => { if (e.key === "Escape") closeModal(); });

    // STARTUP ROUTE
    window.current_media_type = 'tv';
    
    // Ensure the page is fully ready before triggering the first tab
    $(window).on('load', function() {
        setTimeout(() => {
            console.log('Core: Orchestrating initial load...');
            $(document).trigger('tvm_tab_switch', ['watchlist']);
        }, 200);
    });
});