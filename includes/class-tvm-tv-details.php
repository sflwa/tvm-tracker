/**
 * TV & Movie Tracker - TV Module
 * Version 1.1.0 - Added Sync & Streaming Support
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

            // Handle Episode Sync
            $(document).on('click', '#tvm-sync-episodes', (e) => {
                const $btn = $(e.currentTarget);
                const id = $btn.data('id');
                $btn.prop('disabled', true).text('Syncing...');
                
                $.post(tvm_app.ajax_url, { 
                    action: 'tvm_sync_series', 
                    post_id: id, 
                    nonce: tvm_app.nonce 
                }, (res) => {
                    $btn.prop('disabled', false).text('Sync Episodes');
                    if (res.success) {
                        alert(res.data);
                        this.loadEpisodes(id); // Refresh view
                    }
                });
            });

            // Handle Episode Watch Toggle
            $(document).on('click', '.tvm-ep-watch', (e) => {
                const $btn = $(e.currentTarget);
                const epId = $btn.data('id');
                const watched = $btn.data('watched');
                
                $.post(tvm_app.ajax_url, { 
                    action: 'tvm_toggle_episode_watched', 
                    episode_id: epId, 
                    watched: watched, 
                    nonce: tvm_app.nonce 
                }, () => {
                    this.loadEpisodes($btn.closest('#tvm-tv-detail-view').find('#tvm-sync-episodes').data('id'));
                    this.load(); // Refresh stats
                });
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
                    <button id="tvm-sync-episodes" class="button button-primary" data-id="${id}">Sync Episodes</button>
                </div>
                <div id="tvm-episode-results">Loading episodes...</div>
            `);
            this.loadEpisodes(id);
        },

        loadEpisodes: function(id) {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_episodes', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    let epHtml = '<div class="tvm-episode-list" style="display:flex; flex-direction:column; gap:15px;">';
                    res.data.forEach(ep => {
                        const statusColor = ep.is_watched ? '#46b450' : '#ddd';
                        const sourceIcons = this.renderSources(ep.sources);
                        
                        epHtml += `
                        <div class="tvm-episode-row" style="padding:20px; background:#fff; border:1px solid #eee; border-radius:12px; border-left:5px solid ${statusColor};">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                                <div style="flex:1;">
                                    <div style="font-weight:800; color:#1d2327; font-size:16px;">S${ep.season} E${ep.number} - ${ep.title}</div>
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
                            <div style="font-size:13px; line-height:1.5; color:#666;">${ep.overview || 'No description available.'}</div>
                        </div>`;
                    });
                    $('#tvm-episode-results').html(epHtml + '</div>');
                }
            });
        },

        renderSources: function(sources) {
            if (!sources || !Array.isArray(sources)) return '';
            
            const userServices = window.tvm_settings_data.user_services || [];
            const primaryRegion = (window.tvm_settings_data.primary_region || 'US').toUpperCase();
            let html = '';

            sources.forEach(s => {
                const sid = parseInt(s.source_id);
                const type = s.type;
                const reg = s.region.toUpperCase();

                // Rule 3: No Rent or Buy
                if (['rent', 'buy', 'purchase'].includes(type)) return;

                // Rule 1 & 2: Paid (Primary Only) or Free (Any)
                if (userServices.includes(sid)) {
                    if (type === 'free' || (type === 'sub' && reg === primaryRegion)) {
                        // Find logo from master sources
                        const master = window.tvm_settings_data.master_sources.find(m => m.id == sid);
                        const logo = master ? master.logo_100px : '';
                        if (logo) {
                            html += `<img src="${logo}" title="${s.name} (${reg})" style="width:28px; height:28px; border-radius:4px; object-fit:contain; border:1px solid #eee;">`;
                        }
                    }
                }
            });
            return html;
        }
    };
    TVModule.init();
});
