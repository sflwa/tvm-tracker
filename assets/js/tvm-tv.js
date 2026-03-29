/**
 * TV & Movie Tracker - TV Module
 * Version: 1.3.6 - Dropdown Unwatched Strategy
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

            // Standard Drill Down (for All/Watched/Upcoming)
            $(document).on('click', '.tvm-tv-trigger', (e) => {
                this.showSeriesDetails($(e.currentTarget).data('id'));
            });

            // Dropdown Trigger (for Unwatched)
            $(document).on('change', '#tvm-unwatched-dropdown', (e) => {
                const id = $(e.target).val();
                if (id) this.showInlineEpisodes(id);
                else $('#tvm-unwatched-inline-container').hide();
            });

            $(document).on('click', '#tvm-back-to-grid', (e) => {
                e.preventDefault();
                $('#tvm-tv-detail-view').hide();
                $('#tvm-watchlist-grid, .tvm-filters-container').show();
            });

            $(document).on('click', '.tvm-season-tab', (e) => {
                const $tab = $(e.currentTarget);
                const seasonNum = $tab.data('season');
                $('.tvm-season-tab').removeClass('active').css({'background': '#f5f5f5', 'color': '#666'});
                $tab.addClass('active').css({'background': '#2271b1', 'color': '#fff'});
                $('.tvm-season-content-group').hide();
                $(`#tvm-season-group-${seasonNum}`).show();
            });

            $(document).on('click', '.tvm-mark-season-watched', async (e) => {
                const $btn = $(e.currentTarget);
                const unwatchedItems = $btn.closest('.tvm-season-content-group').find('.tvm-ep-watch[data-watched="true"]');
                if (unwatchedItems.length === 0 || !confirm(`Mark ${unwatchedItems.length} episodes watched?`)) return;
                $btn.prop('disabled', true).text('Processing...');
                for (let i = 0; i < unwatchedItems.length; i++) {
                    await $.post(tvm_app.ajax_url, { action: 'tvm_toggle_episode_watched', episode_id: $(unwatchedItems[i]).data('id'), watched: 'true', nonce: tvm_app.nonce });
                }
                this.load(); 
                const seriesId = $('#tvm-sync-episodes').data('id') || $('#tvm-unwatched-dropdown').val();
                this.loadEpisodes(seriesId, $('.tvm-season-tab.active').data('season'));
            });

            $(document).on('click', '#tvm-sync-episodes', (e) => {
                const $btn = $(e.currentTarget);
                const id = $btn.data('id');
                $btn.prop('disabled', true).text('Syncing...');
                $.post(tvm_app.ajax_url, { action: 'tvm_sync_series', post_id: id, nonce: tvm_app.nonce }, (res) => {
                    $btn.prop('disabled', false).text('Sync Episodes');
                    if (res.success) { alert(res.data); this.load(); this.loadEpisodes(id); }
                });
            });

            $(document).on('click', '.tvm-ep-watch', (e) => {
                const $btn = $(e.currentTarget);
                $.post(tvm_app.ajax_url, { action: 'tvm_toggle_episode_watched', episode_id: $btn.data('id'), watched: $btn.data('watched'), nonce: tvm_app.nonce }, () => {
                    const seriesId = $('#tvm-sync-episodes').data('id') || $('#tvm-unwatched-dropdown').val();
                    if (seriesId) this.loadEpisodes(seriesId, $('.tvm-season-tab.active').data('season'));
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
                    this.applyFilter();
                }
            });
        },

        applyFilter: function() {
            const filter = $('.tvm-filter-btn.active').data('filter') || 'all';
            const search = $('#tvm-vault-search-input').val().toLowerCase();
            const streamOnly = $('#tvm-stream-only-toggle').is(':checked');
            let items = [...(window.tvm_tv_cache || [])];
            
            if (filter === 'watched') items = items.filter(i => i.ep_watched > 0);
            else if (filter === 'released') items = items.filter(i => i.has_aired_unwatched === true);
            else if (filter === 'upcoming') items = items.filter(i => i.has_upcoming === true);

            if (streamOnly) items = items.filter(i => i.has_streaming === true);
            if (search) items = items.filter(i => i.title.toLowerCase().includes(search));

            items.sort((a, b) => a.title.localeCompare(b.title));
            TVM_Core.updateCounter(items.length);

            this.render(items, filter === 'released');
        },

        render: function(items, isUnwatchedView) {
            let html = '';
            
            if (isUnwatchedView) {
                // NORTH STAR: Dropdown for Unwatched episodes
                html += `
                <div style="background:#fff; padding:30px; border-radius:12px; border:1px solid #eee; margin-bottom:20px;">
                    <label style="display:block; font-weight:700; margin-bottom:10px; color:#1d2327;">Select Show to View Unwatched Episodes:</label>
                    <select id="tvm-unwatched-dropdown" style="width:100%; max-width:400px; height:45px; border-radius:8px; border:1px solid #ddd;">
                        <option value="">-- Choose a Series (${items.length}) --</option>
                        ${items.map(i => `<option value="${i.id}">(${i.ep_count - i.ep_watched}) ${i.title}</option>`).join('')}
                    </select>
                    <div id="tvm-unwatched-inline-container" style="display:none; margin-top:30px;"></div>
                </div>`;
                // Use .html() but DO NOT apply .tvm-locked-grid to the container for this view
                $('#tvm-watchlist-grid').removeClass('tvm-locked-grid').html(html);
            } else {
                // Restore standard 6-column grid for All/Watched/Upcoming
                html = items.map(item => `
                    <div class="tvm-movie-card">
                        <div class="tvm-poster-wrapper">
                            <div class="tvm-badge-stats">${item.ep_watched}/${item.ep_count}</div>
                            <div class="tvm-tv-trigger" data-id="${item.id}" style="cursor:pointer;">
                                <img src="https://image.tmdb.org/t/p/w185${item.poster_path}" style="width:100%; display:block;">
                            </div>
                        </div>
                        <h5 style="margin:8px 0; font-size:11px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                    </div>`).join('');
                
                $('#tvm-watchlist-grid').addClass('tvm-locked-grid').html(html || '<p style="grid-column:1/-1; text-align:center; padding:40px;">No series found.</p>');
            }
        },

        showInlineEpisodes: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            $('#tvm-unwatched-inline-container').show().html(`
                <div style="border-top:2px solid #eee; padding-top:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                         <h3 style="margin:0;">${item.title}</h3>
                         <span style="font-size:12px; color:#999; font-style:italic;">Last Sync: ${item.last_sync}</span>
                    </div>
                    <div id="tvm-season-nav" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px;"></div>
                    <div id="tvm-episode-results">Loading episodes...</div>
                </div>
            `);
            this.loadEpisodes(id);
        },

        showSeriesDetails: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            $('#tvm-watchlist-grid, .tvm-filters-container').hide();
            $('#tvm-tv-detail-view').show();
            $('#tvm-series-content').html(`
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div style="display:flex; align-items:baseline; gap:12px;">
                        <h3 style="margin:0;">${item.title}</h3>
                        <span style="font-size:12px; color:#999; font-style:italic;">Last Sync: ${item.last_sync}</span>
                    </div>
                    <button id="tvm-sync-episodes" class="button button-primary" data-id="${id}">Sync Episodes</button>
                </div>
                <div id="tvm-season-nav" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:25px; border-bottom:1px solid #eee; padding-bottom:15px;"></div>
                <div id="tvm-episode-results">Loading episodes...</div>
            `);
            this.loadEpisodes(id);
        },

        loadEpisodes: function(id, restoreSeason = null) {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_episodes', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    const filter = $('.tvm-filter-btn.active').data('filter');
                    let episodes = res.data;
                    if (filter === 'released') {
                        episodes = episodes.filter(ep => !ep.is_watched && !ep.is_future);
                    }

                    const grouped = {};
                    episodes.forEach(ep => {
                        if (!grouped[ep.season]) grouped[ep.season] = [];
                        grouped[ep.season].push(ep);
                    });

                    const seasons = Object.keys(grouped).sort((a, b) => a - b);
                    let navHtml = '';
                    let contentHtml = '';
                    seasons.forEach((sNum, index) => {
                        const isActive = (restoreSeason) ? (sNum == restoreSeason) : (index === 0);
                        const tabStyle = isActive ? 'background:#2271b1; color:#fff; font-weight:700;' : 'background:#f5f5f5; color:#666; font-weight:600;';
                        navHtml += `<button class="tvm-season-tab ${isActive ? 'active' : ''}" data-season="${sNum}" style="border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; transition:0.2s; ${tabStyle}">Season ${sNum}</button>`;
                        contentHtml += `<div id="tvm-season-group-${sNum}" class="tvm-season-content-group" style="display:${isActive ? 'flex' : 'none'}; flex-direction:column; gap:12px;">`;
                        
                        contentHtml += `
                            <div style="display:flex; justify-content:flex-end; margin-bottom:10px;">
                                <button class="tvm-mark-season-watched button button-small" style="font-size:10px; font-weight:700; color:#2271b1; border-color:#2271b1;">Mark Full Season Watched</button>
                            </div>`;

                        grouped[sNum].forEach(ep => {
                            const statusColor = ep.is_watched ? '#46b450' : '#ddd';
                            const sourceContent = ep.is_future ? '<span style="font-size:10px; color:#2271b1; text-transform:uppercase; background:#e7f3ff; padding:4px 8px; border-radius:4px; font-weight:700;">Upcoming</span>' : this.renderSources(ep.sources);
                            const watchAction = ep.is_future ? '<span class="dashicons dashicons-clock" style="color:#bbb; font-size:24px; width:24px; height:24px; cursor:default;"></span>' : `<span class="dashicons ${ep.is_watched ? 'dashicons-visibility' : 'dashicons-hidden'} tvm-ep-watch" data-id="${ep.id}" data-watched="${!ep.is_watched}" style="cursor:pointer; color:${statusColor}; font-size:24px; width:24px; height:24px;"></span>`;
                            contentHtml += `
                            <div class="tvm-episode-row" style="border-left-color: ${statusColor};">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div style="flex:1;">
                                        <div style="font-weight:800; color:#1d2327; font-size:16px;">E${ep.number} - ${ep.title.replace(/^S\d+E\d+\s-\s/i, '')}</div>
                                        <div style="font-size:12px; color:#999; margin-top:4px;">Air Date: ${ep.air_date}</div>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:15px;">
                                        <div class="tvm-episode-sources" style="display:flex; gap:6px;">${sourceContent}</div>
                                        ${watchAction}
                                    </div>
                                </div>
                                <div class="tvm-ep-overview" style="margin-top:12px; font-size:13px; line-height:1.5; color:#666;">${ep.overview || 'No description available.'}</div>
                            </div>`;
                        });
                        contentHtml += `</div>`;
                    });
                    $('#tvm-season-nav').html(seasons.length > 1 ? navHtml : '');
                    $('#tvm-episode-results').html(contentHtml);
                }
            });
        },

        renderSources: function(sources) {
            if (!sources || !Array.isArray(sources) || sources.length === 0) return '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
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
                            html += `<img src="${master.logo_100px}" title="${s.name}" style="width:48px; height:48px; border-radius:6px; border:1px solid #eee; object-fit:contain;">`;
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
