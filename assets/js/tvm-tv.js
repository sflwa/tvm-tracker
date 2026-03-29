/**
 * TV & Movie Tracker - TV Module
 * Version: 1.1.8 - Finalized Source Display
 * Author: South Florida Web Advisors
 */
jQuery(function($) {
    const TVModule = {
        init: function() {
            $(document).on('tvm_tab_switch', (e, tab) => {
                if (tab === 'watchlist' && window.current_media_type === 'tv') this.load();
            });

            $(document).on('tvm_filter_change', () => {
                if (window.current_media_type === 'tv') this.applyFilter();
            });

            $(document).on('click', '.tvm-tv-trigger', (e) => this.showSeriesDetails($(e.currentTarget).data('id')));

            $(document).on('click', '#tvm-back-to-grid', (e) => {
                e.preventDefault();
                $('#tvm-tv-detail-view').hide();
                $('#tvm-watchlist-grid, .tvm-filters-container').show();
            });

            $(document).on('click', '#tvm-sync-episodes', (e) => {
                const $btn = $(e.currentTarget);
                const id = $btn.data('id');
                $btn.prop('disabled', true).text('Syncing...');
                $.post(tvm_app.ajax_url, { action: 'tvm_sync_series', post_id: id, nonce: tvm_app.nonce }, (res) => {
                    $btn.prop('disabled', false).text('Sync Episodes');
                    if (res.success) { alert(res.data); this.loadEpisodes(id); }
                });
            });

            $(document).on('click', '.tvm-ep-watch', (e) => {
                const $btn = $(e.currentTarget);
                $.post(tvm_app.ajax_url, { 
                    action: 'tvm_toggle_episode_watched', 
                    episode_id: $btn.data('id'), 
                    watched: $btn.data('watched'), 
                    nonce: tvm_app.nonce 
                }, () => {
                    this.loadEpisodes($('#tvm-sync-episodes').data('id'));
                    this.load();
                });
            });
        },

        load: function() {
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_watchlist', nonce: tvm_app.nonce }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    window.tvm_tv_cache = res.data.items;
                    this.updateStats(res.data.stats);
                    this.applyFilter();
                }
            });
        },

        updateStats: function(s) {
            $('#tvm-stats-display').html(`TV: ${s.series} Series • ${s.episodes} Episodes • ${s.watched} Watched • ${s.percent}%`);
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
                html += `
                <div class="tvm-movie-card">
                    <div class="tvm-poster-wrapper">
                        <div class="tvm-badge-stats">${item.ep_watched}/${item.ep_count}</div>
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
                    <button id="tvm-sync-episodes" class="button button-primary" data-id="${id}">Sync Episodes</button>
                </div>
                <div id="tvm-episode-results">Loading episodes...</div>
            `);
            this.loadEpisodes(id);
        },

        loadEpisodes: function(id) {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_episodes', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    let epHtml = '<div class="tvm-episode-list" style="display:flex; flex-direction:column; gap:12px;">';
                    res.data.forEach(ep => {
                        const statusColor = ep.is_watched ? '#46b450' : '#ddd';
                        const sourceIcons = this.renderSources(ep.sources);
                        let cleanTitle = ep.title.replace(/^S\d+E\d+\s-\s/i, '');
                        
                        epHtml += `
                        <div class="tvm-episode-row" style="border-left-color: ${statusColor};">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div style="flex:1;">
                                    <div style="font-weight:800; color:#1d2327; font-size:16px;">S${ep.season} E${ep.number} - ${cleanTitle}</div>
                                    <div style="font-size:12px; color:#999; margin-top:4px;">Air Date: ${ep.air_date || 'TBA'}</div>
                                </div>
                                <div style="display:flex; align-items:center; gap:15px;">
                                    <div class="tvm-episode-sources" style="display:flex; gap:6px;">${sourceIcons}</div>
                                    <span class="dashicons ${ep.is_watched ? 'dashicons-visibility' : 'dashicons-hidden'} tvm-ep-watch" 
                                          data-id="${ep.id}" 
                                          data-watched="${!ep.is_watched}" 
                                          style="cursor:pointer; color:${statusColor}; font-size:24px; width:24px; height:24px;"></span>
                                </div>
                            </div>
                            <div class="tvm-ep-overview" style="margin-top:12px; font-size:13px; line-height:1.5; color:#666;">${ep.overview || 'No description available.'}</div>
                        </div>`;
                    });
                    $('#tvm-episode-results').html(epHtml + '</div>');
                }
            });
        },

        renderSources: function(sources) {
            if (!sources || !Array.isArray(sources) || sources.length === 0) {
                return '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
            }
            const settings = window.tvm_settings_data || {};
            const userServices = settings.user_services || [];
            const primaryRegion = (settings.primary_region || 'US').toUpperCase();
            const masterList = settings.master_sources || [];
            let html = '';
            let validCount = 0;

            sources.forEach(s => {
                const sid = parseInt(s.source_id);
                if (['rent', 'buy', 'purchase'].includes(s.type)) return;
                if (userServices.includes(sid)) {
                    if (s.type === 'free' || (s.type === 'sub' && s.region.toUpperCase() === primaryRegion)) {
                        const master = masterList.find(m => m.id == sid);
                        if (master && master.logo_100px) {
                            html += `<img src="${master.logo_100px}" title="${s.name}" style="width:28px; height:28px; border-radius:4px; border:1px solid #eee; object-fit:contain;">`;
                            validCount++;
                        }
                    }
                }
            });
            return (validCount > 0) ? html : '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
        }
    };
    TVModule.init();
});
