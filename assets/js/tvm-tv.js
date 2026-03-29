/**
 * TV & Movie Tracker - TV Module
 * Version 1.0.1 - Tab Signal Fix
 */
jQuery(function($) {
    const TVModule = {
        init: function() {
            $(document).on('tvm_tab_switch', (e, tab) => {
                if (tab === 'watchlist' && window.current_media_type === 'tv') {
                    this.load();
                }
            });
            $(document).on('tvm_filter_change', () => {
                if (window.current_media_type === 'tv') this.applyFilter();
            });
            $(document).on('click', '.tvm-tv-trigger', (e) => {
                this.showSeriesDetails($(e.currentTarget).data('id'));
            });
        },

        load: function() {
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { 
                action: 'tvm_get_tv_watchlist', 
                nonce: tvm_app.nonce 
            }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    window.tvm_tv_cache = res.data.items;
                    this.updateStats(res.data.stats);
                    this.applyFilter();
                }
            });
        },

        updateStats: function(s) {
            const html = `TV: ${s.series} Series • ${s.episodes} Episodes • ${s.watched} Watched • ${s.percent}%`;
            $('#tvm-stats-display').html(html);
        },

        applyFilter: function() {
            const filter = $('.tvm-filter-btn.active').data('filter') || 'all';
            const search = $('#tvm-vault-search-input').val().toLowerCase();
            let items = [...(window.tvm_tv_cache || [])];

            if (filter === 'watched') items = items.filter(i => i.is_watched);
            else if (filter === 'released') items = items.filter(i => i.ep_watched < i.ep_count);
            else if (filter === 'upcoming') items = items.filter(i => i.has_upcoming);

            if (search) items = items.filter(i => i.title.toLowerCase().includes(search));
            items.sort((a, b) => a.title.localeCompare(b.title));

            TVM_Core.updateCounter(items.length);
            this.render(items);
        },

        render: function(items) {
            let html = '';
            items.forEach(item => {
                const statBadge = `<div class="tvm-badge-stats">${item.ep_watched}/${item.ep_count}</div>`;
                html += `
                <div class="tvm-movie-card">
                    <div class="tvm-poster-wrapper">
                        ${statBadge}
                        <div class="tvm-tv-trigger" data-id="${item.id}" style="cursor:pointer;">
                            <img src="https://image.tmdb.org/t/p/w185${item.poster_path}" style="width:100%; display:block;">
                        </div>
                    </div>
                    <h5 style="margin:8px 0; font-size:11px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                </div>`;
            });
            $('#tvm-watchlist-grid').html(html || '<p style="grid-column:1/-1; text-align:center; padding:40px;">No series found.</p>');
        },

        showSeriesDetails: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            $('#tvm-watchlist-grid, .tvm-filters-container').hide();
            $('#tvm-tv-detail-view').show();
            $('#tvm-series-content').html(`
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h3 style="margin:0;">${item.title}</h3>
                    <button id="tvm-sync-episodes" class="button" data-id="${id}">Sync Episodes</button>
                </div>
                <div id="tvm-episode-results">Loading episodes...</div>
            `);
            this.loadEpisodes(id);
        },

        loadEpisodes: function(id) {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_episodes', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    let epHtml = '<div class="tvm-episode-list" style="display:flex; flex-direction:column; gap:10px;">';
                    res.data.forEach(ep => {
                        const statusColor = ep.is_watched ? '#46b450' : '#ddd';
                        epHtml += `
                        <div class="tvm-episode-row" style="display:flex; align-items:center; justify-content:space-between; padding:12px; background:#f9f9f9; border-radius:8px; border-left:4px solid ${statusColor};">
                            <div><strong>S${ep.season} E${ep.number}</strong> <span style="margin-left:10px;">${ep.title}</span></div>
                            <span class="dashicons ${ep.is_watched ? 'dashicons-visibility' : 'dashicons-hidden'} tvm-ep-watch" data-id="${ep.id}" data-watched="${!ep.is_watched}" style="cursor:pointer; color:${statusColor};"></span>
                        </div>`;
                    });
                    $('#tvm-episode-results').html(epHtml + '</div>');
                }
            });
        }
    };
    TVModule.init();
});