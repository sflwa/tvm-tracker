/**
 * TV & Movie Tracker - TV Module
 * Version: 1.7.3 - UI Update: Detail page with poster, status, and stats
 * Author: South Florida Web Advisors
 */
jQuery(function($) {
    const TVModule = {
        currentMonth: new Date(),
        calendarEvents: [],
        activeCalendarDate: null,
        activeUnwatchedId: null,

        init: function() {
            setTimeout(() => {
                if (window.location.hash === '#tv-unwatched') this.switchToFilter('released');
                else if (window.location.hash === '#tv-calendar') this.switchToFilter('calendar');
            }, 200);

            $(document).on('tvm_settings_ready', () => {
                if ($('#tvm-episode-results').is(':visible')) {
                    const seriesId = $('#tvm-sync-episodes').data('id') || $('#tvm-unwatched-dropdown').val();
                    if (seriesId) this.loadEpisodes(seriesId, $('.tvm-season-tab.active').data('season'));
                }
            });

            $(document).on('tvm_tab_switch', (e, tab) => {
                if (tab === 'watchlist' && window.current_media_type === 'tv') {
                    const currentFilter = $('.tvm-filter-btn.active').data('filter');
                    if (currentFilter === 'calendar') {
                        this.renderCalendarView();
                    } else {
                        this.load();
                    }
                }
            });

            $(document).on('tvm_filter_change', (e, filter) => {
                if (window.current_media_type === 'tv') {
                    if (filter === 'calendar') {
                        this.activeUnwatchedId = null; 
                        this.renderCalendarView();
                    } else {
                        this.applyFilter();
                    }
                }
            });

            $(document).on('click', '.tvm-cal-nav', (e) => {
                const dir = $(e.currentTarget).data('dir');
                this.currentMonth.setMonth(this.currentMonth.getMonth() + (dir === 'next' ? 1 : -1));
                this.activeCalendarDate = null;
                this.renderCalendarView();
            });

            $(document).on('click', '.tvm-calendar-day', (e) => {
                const date = $(e.currentTarget).data('date');
                if (date) {
                    this.activeCalendarDate = date;
                    this.showDayDetails(date);
                }
            });

            $(document).on('click', '.tvm-tv-trigger', (e) => {
                const id = $(e.currentTarget).data('id');
                const filter = $('.tvm-filter-btn.active').data('filter');
                
                if (filter === 'released' && $(window).width() >= 800) {
                    this.activeUnwatchedId = id;
                    this.showInlineEpisodes(id);
                    $('html, body').animate({ scrollTop: $('#tvm-unwatched-inline-container').offset().top - 100 }, 500);
                } else {
                    this.showSeriesDetails(id);
                }
            });

            $(document).on('click', '#tvm-back-to-grid', (e) => {
                e.preventDefault();
                $('#tvm-tv-detail-view').hide();
                $('#tvm-watchlist-grid, .tvm-filters-container').show();
            });

            $(document).on('click', '.tvm-season-tab', (e) => {
                const $tab = $(e.currentTarget);
                $('.tvm-season-tab').removeClass('active').css({'background': '#f5f5f5', 'color': '#666'});
                $tab.addClass('active').css({'background': '#2271b1', 'color': '#fff'});
                $('.tvm-season-content-group').hide();
                $(`#tvm-season-group-${$tab.data('season')}`).show();
            });

            $(document).on('click', '.tvm-mark-season-watched', async (e) => {
                const $btn = $(e.currentTarget);
                const $group = $btn.closest('.tvm-season-content-group');
                const unwatchedItems = $group.find('.tvm-ep-watch[data-watched="true"]');
                
                if (unwatchedItems.length === 0) return;
                if (!confirm(`Mark ${unwatchedItems.length} episodes as watched?`)) return;
                
                $btn.prop('disabled', true).text('Processing...');
                for (let i = 0; i < unwatchedItems.length; i++) {
                    await $.post(tvm_app.ajax_url, { action: 'tvm_toggle_episode_watched', episode_id: $(unwatchedItems[i]).data('id'), watched: 'true', nonce: tvm_app.nonce });
                }
                
                this.load(); 
                const seriesId = $('#tvm-sync-episodes').data('id') || $('#tvm-unwatched-dropdown').val();
                this.loadEpisodes(seriesId, $('.tvm-season-tab.active').data('season'));
            });

            $(document).on('click', '.tvm-ep-watch', (e) => {
                const $btn = $(e.currentTarget);
                $.post(tvm_app.ajax_url, { 
                    action: 'tvm_toggle_episode_watched', 
                    episode_id: $btn.data('id'), 
                    watched: $btn.data('watched'), 
                    nonce: tvm_app.nonce 
                }, () => {
                    const filter = $('.tvm-filter-btn.active').data('filter');
                    if (filter === 'calendar') {
                        this.renderCalendarView();
                    } else if (filter === 'released' && this.activeUnwatchedId) {
                        $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_watchlist', nonce: tvm_app.nonce }, (res) => {
                            if (res.success) {
                                window.tvm_tv_cache = res.data.items;
                                this.showInlineEpisodes(this.activeUnwatchedId);
                            }
                        });
                    } else {
                        const seriesId = $('#tvm-sync-episodes').data('id') || $('#tvm-unwatched-dropdown').val();
                        if (seriesId) this.loadEpisodes(seriesId, $('.tvm-season-tab.active').data('season'));
                        this.load();
                    }
                });
            });

            $(document).on('change', '#tvm-unwatched-dropdown', (e) => {
                const id = $(e.target).val();
                this.activeUnwatchedId = id;
                if (id) this.showInlineEpisodes(id);
                else $('#tvm-unwatched-inline-container').hide();
            });

            $(document).on('click', '.tvm-dropdown-nav', (e) => {
                const dir = $(e.currentTarget).data('dir');
                const $d = $('#tvm-unwatched-dropdown');
                const idx = $d.prop('selectedIndex');
                const total = $d.find('option').length;
                let next = (dir === 'next') ? (idx >= total - 1 ? 1 : idx + 1) : (idx <= 1 ? total - 1 : idx - 1);
                $d.prop('selectedIndex', next).trigger('change');
            });

            $(document).on('click', '.tvm-delete-item', function(e) {
                e.stopPropagation();
                if (confirm('Remove this series?')) {
                    $.post(tvm_app.ajax_url, { action: 'tvm_delete_item', post_id: $(this).data('id'), nonce: tvm_app.nonce }, () => TVModule.load());
                }
            });

            $(document).on('click', '#tvm-sync-episodes', (e) => {
                const $btn = $(e.currentTarget);
                $btn.prop('disabled', true).text('Syncing...');
                $.post(tvm_app.ajax_url, { action: 'tvm_sync_series', post_id: $btn.data('id'), nonce: tvm_app.nonce }, (res) => {
                    $btn.prop('disabled', false).text('Sync Episodes');
                    if (res.success) { alert(res.data); this.load(); this.loadEpisodes($btn.data('id')); }
                });
            });
        },

        switchToFilter: function(filterName) {
            $('.tvm-filter-btn').removeClass('active');
            $(`.tvm-filter-btn[data-filter="${filterName}"]`).addClass('active');
            if (filterName === 'calendar') this.renderCalendarView();
            else this.load();
        },

        load: function() {
            if ($('.tvm-filter-btn.active').data('filter') === 'calendar') { this.renderCalendarView(); return; }
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
            
            if (filter === 'released') items = items.filter(i => i.has_aired_unwatched === true);
            else if (filter === 'upcoming') items = items.filter(i => i.has_upcoming === true);
            else if (filter === 'watched') items = items.filter(i => i.aired_unwatched_count === 0);

            if (streamOnly) {
                items = items.filter(i => i.has_streaming === true);
            }

            if (search) items = items.filter(i => i.title.toLowerCase().includes(search));

            items.sort((a, b) => a.title.localeCompare(b.title));
            TVM_Core.updateCounter(items.length);
            this.render(items, filter === 'released');
        },

        renderCalendarView: function() {
            const monthStr = `${this.currentMonth.getFullYear()}-${String(this.currentMonth.getMonth() + 1).padStart(2, '0')}`;
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { action: 'tvm_get_calendar_month', month: monthStr, nonce: tvm_app.nonce }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    this.calendarEvents = res.data;
                    this.buildCalendarGrid();
                }
            });
        },

        buildCalendarGrid: function() {
            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const firstDay = new Date(year, month, 1).getDay();
            const today = new Date().toISOString().split('T')[0];

            let html = `
            <div class="tvm-calendar-container">
                <div class="tvm-calendar-header">
                    <span class="dashicons dashicons-arrow-left-alt2 tvm-cal-nav" data-dir="prev" style="cursor:pointer;"></span>
                    <span>${monthNames[month]} ${year}</span>
                    <span class="dashicons dashicons-arrow-right-alt2 tvm-cal-nav" data-dir="next" style="cursor:pointer;"></span>
                </div>
                <div class="tvm-calendar-grid">
                    <div class="tvm-calendar-day-label">SUN</div><div class="tvm-calendar-day-label">MON</div>
                    <div class="tvm-calendar-day-label">TUE</div><div class="tvm-calendar-day-label">WED</div>
                    <div class="tvm-calendar-day-label">THU</div><div class="tvm-calendar-day-label">FRI</div>
                    <div class="tvm-calendar-day-label">SAT</div>`;

            for (let i = 0; i < firstDay; i++) html += `<div class="tvm-calendar-day empty"></div>`;

            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = dateStr === today ? 'today' : '';
                const dayEvents = this.calendarEvents.filter(e => e.air_date === dateStr);
                
                let listHtml = '', dotsHtml = '';
                dayEvents.forEach(ev => {
                    const iconClass = ev.is_watched ? 'dashicons-visibility' : 'dashicons-hidden';
                    const iconColor = ev.is_watched ? '#46b450' : '#ccc';
                    listHtml += `<div class="tvm-cal-ep-item"><span style="font-weight:700; color:#2271b1;">${ev.series}</span><div style="display:flex; align-items:center; gap:4px;"><span>(${ev.display})</span><span class="dashicons ${iconClass} tvm-ep-watch" data-id="${ev.id}" data-watched="${!ev.is_watched}" style="cursor:pointer; font-size:14px; width:14px; height:14px; color:${iconColor};"></span></div></div>`;
                    dotsHtml += `<div class="tvm-calendar-dot ${ev.is_watched ? 'watched' : ''}"></div>`;
                });

                html += `<div class="tvm-calendar-day ${isToday}" data-date="${dateStr}"><span class="tvm-calendar-date">${day}</span><div class="tvm-cal-desktop-list">${listHtml}</div><div class="tvm-calendar-dots">${dotsHtml}</div></div>`;
            }

            html += `</div><div id="tvm-calendar-details" style="margin-top:25px;"></div></div>`;
            $('#tvm-watchlist-grid').removeClass('tvm-locked-grid').html(html);

            if (this.activeCalendarDate) {
                this.showDayDetails(this.activeCalendarDate);
            }
        },

        showDayDetails: function(date) {
            const dayEvents = this.calendarEvents.filter(e => e.air_date === date);
            if (dayEvents.length === 0) {
                $('#tvm-calendar-details').empty();
                return;
            }
            let html = `<h3 style="margin-bottom:15px; font-size:20px;">Airing on ${date}</h3>`;
            dayEvents.forEach(ev => {
                const iconClass = ev.is_watched ? 'dashicons-visibility' : 'dashicons-hidden';
                const iconColor = ev.is_watched ? '#46b450' : '#ccc';
                html += `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; padding:20px; background:#fff; border:1px solid #eee; border-radius:12px; margin-bottom:15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <div style="flex:1; padding-right:20px;">
                        <strong style="color:#2271b1; font-size:16px; display:block; margin-bottom:4px;">${ev.series}</strong>
                        <span style="font-size:14px; color:#1d2327; font-weight:700;">${ev.display} - ${ev.title}</span>
                        <div style="margin-top:10px; font-size:13px; line-height:1.5; color:#666;">${ev.overview || 'No description available.'}</div>
                    </div>
                    <span class="dashicons ${iconClass} tvm-ep-watch" data-id="${ev.id}" data-watched="${!ev.is_watched}" style="cursor:pointer; font-size:28px; width:28px; height:28px; color:${iconColor};"></span>
                </div>`;
            });
            $('#tvm-calendar-details').html(html);
        },

        render: function(items, isUnwatchedView) {
            const currentShows = items.filter(i => ['Returning Series', 'Unknown'].includes(i.status));
            const futureShows  = items.filter(i => ['In Production'].includes(i.status));
            const oldShows     = items.filter(i => ['Ended', 'Canceled'].includes(i.status));

            const buildGrid = (groupItems) => {
                if (groupItems.length === 0) return '';
                return groupItems.map(item => {
                    const posterContent = (item.poster_path && item.poster_path !== "") 
                        ? `<img src="https://image.tmdb.org/t/p/w185${item.poster_path}" style="width:100%; display:block;">`
                        : `<div class="tvm-placeholder-poster"><span class="dashicons dashicons-desktop"></span><span class="placeholder-text">No Poster</span></div>`;

                    const badgeHtml = isUnwatchedView ? `<div class="tvm-badge-stats">${item.aired_unwatched_count}</div>` : '';

                    return `
                    <div class="tvm-movie-card ${this.activeUnwatchedId == item.id ? 'tvm-unwatched-active' : ''}">
                        <div class="tvm-overlay-controls"><span class="dashicons dashicons-trash tvm-delete-item" data-id="${item.id}" style="color:#ff4d4d;"></span></div>
                        <div class="tvm-poster-wrapper">
                            ${badgeHtml}
                            <div class="tvm-tv-trigger" data-id="${item.id}" style="cursor:pointer;">
                                ${posterContent}
                            </div>
                        </div>
                        <h5 style="margin:8px 0; font-size:10px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                    </div>`;
                }).join('');
            };

            const buildSection = (title, groupItems, count) => {
                if (groupItems.length === 0) return '';
                return `
                <div class="tvm-section-group" style="margin-bottom:40px; clear:both;">
                    <h3 style="border-bottom:2px solid #eee; padding-bottom:10px; margin-bottom:20px; font-size:18px; color:#2271b1; display:flex; justify-content:space-between; align-items:center;">
                        ${title} <span style="font-size:12px; background:#e7f3ff; color:#2271b1; padding:2px 10px; border-radius:12px;">${count}</span>
                    </h3>
                    <div class="tvm-locked-grid">${buildGrid(groupItems)}</div>
                </div>`;
            };

            let finalHtml = '';
            if (isUnwatchedView && $(window).width() < 800) {
                const selectedAttr = (id) => (this.activeUnwatchedId == id) ? 'selected' : '';
                finalHtml = `<div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; margin-bottom:20px;"><div class="tvm-unwatched-nav-container"><span class="dashicons dashicons-arrow-left-alt2 tvm-dropdown-nav" data-dir="prev" style="cursor:pointer; font-size:30px; width:30px; height:30px; color:#2271b1;"></span><select id="tvm-unwatched-dropdown" style="flex:1; max-width:400px; height:45px; border-radius:8px; border:1px solid #ddd; font-weight:600;"><option value="">-- Choose a Series (${items.length}) --</option>${items.map(i => `<option value="${i.id}" ${selectedAttr(i.id)}>(${i.aired_unwatched_count}) ${i.title}</option>`).join('')}</select><span class="dashicons dashicons-arrow-right-alt2 tvm-dropdown-nav" data-dir="next" style="cursor:pointer; font-size:30px; width:30px; height:30px; color:#2271b1;"></span></div><div id="tvm-unwatched-inline-container" style="display:${this.activeUnwatchedId ? 'block' : 'none'}; margin-top:20px;"></div></div>`;
            } else {
                finalHtml += buildSection('Current Shows', currentShows, currentShows.length);
                finalHtml += buildSection('Future Shows', futureShows, futureShows.length);
                finalHtml += buildSection('Old Shows', oldShows, oldShows.length);

                if (isUnwatchedView) {
                    finalHtml += `<div id="tvm-unwatched-inline-container" style="display:${this.activeUnwatchedId ? 'block' : 'none'}; margin-top:30px; border-top:2px solid #eee; padding-top:20px;"></div>`;
                }
            }

            $('#tvm-watchlist-grid').removeClass('tvm-locked-grid').html(finalHtml || '<p style="text-align:center; padding:40px;">No series found.</p>');
            if (isUnwatchedView && this.activeUnwatchedId) this.showInlineEpisodes(this.activeUnwatchedId);
        },

        showInlineEpisodes: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            if (!item) {
                $('#tvm-unwatched-inline-container').hide();
                return;
            }
            $('#tvm-unwatched-inline-container').show().html(`<div style="padding-top:10px;"><div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;"><h3 style="margin:0;">${item.title}</h3><span style="font-size:12px; color:#999; font-style:italic;">Last Sync: ${item.last_sync}</span></div><div id="tvm-season-nav" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px;"></div><div id="tvm-episode-results">Loading episodes...</div></div>`);
            this.loadEpisodes(id);
        },

        showSeriesDetails: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            if (!item) return;

            $('#tvm-watchlist-grid, .tvm-filters-container').hide();
            $('#tvm-tv-detail-view').show();

            const posterUrl = item.poster_path ? `https://image.tmdb.org/t/p/w342${item.poster_path}` : '';
            const statusColor = (['Ended', 'Canceled'].includes(item.status)) ? '#d63638' : '#46b450';
            const totalAired = item.watched_count + item.aired_unwatched_count;
            const progressPercent = totalAired > 0 ? Math.round((item.watched_count / totalAired) * 100) : 0;

            const headerHtml = `
            <div class="tvm-series-detail-header" style="display:flex; gap:30px; margin-bottom:30px; background:#fff; padding:25px; border-radius:12px; border:1px solid #eee; box-shadow:0 4px 6px rgba(0,0,0,0.02);">
                <div class="tvm-detail-poster" style="flex:0 0 180px;">
                    ${item.poster_path 
                        ? `<img src="${posterUrl}" style="width:100%; border-radius:8px; display:block; box-shadow:0 10px 20px rgba(0,0,0,0.15);">`
                        : `<div class="tvm-placeholder-poster" style="height:270px;"><span class="dashicons dashicons-desktop" style="font-size:48px;"></span></div>`}
                </div>
                <div class="tvm-detail-info" style="flex:1;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
                        <div>
                            <h2 style="margin:0 0 10px 0; font-size:28px; color:#1d2327;">${item.title}</h2>
                            <span style="background:${statusColor}; color:#fff; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">${item.status}</span>
                        </div>
                        <button id="tvm-sync-episodes" class="button button-primary" data-id="${id}" style="border-radius:6px; font-weight:600;">Sync Metadata</button>
                    </div>
                    
                    <div class="tvm-detail-stats" style="display:grid; grid-template-columns:repeat(4, 1fr); gap:15px; margin-top:20px; border-top:1px solid #f0f0f0; padding-top:20px;">
                        <div class="stat-box">
                            <span style="display:block; font-size:10px; color:#999; text-transform:uppercase; font-weight:700; margin-bottom:5px;">Progress</span>
                            <strong style="font-size:18px; color:#2271b1;">${progressPercent}%</strong>
                        </div>
                        <div class="stat-box">
                            <span style="display:block; font-size:10px; color:#999; text-transform:uppercase; font-weight:700; margin-bottom:5px;">Watched</span>
                            <strong style="font-size:18px; color:#46b450;">${item.watched_count}</strong>
                        </div>
                        <div class="stat-box">
                            <span style="display:block; font-size:10px; color:#999; text-transform:uppercase; font-weight:700; margin-bottom:5px;">Remaining</span>
                            <strong style="font-size:18px; color:#d63638;">${item.aired_unwatched_count}</strong>
                        </div>
                        <div class="stat-box">
                            <span style="display:block; font-size:10px; color:#999; text-transform:uppercase; font-weight:700; margin-bottom:5px;">Upcoming</span>
                            <strong style="font-size:18px; color:#dba617;">${item.upcoming_count}</strong>
                        </div>
                    </div>
                    <div style="margin-top:15px; font-size:11px; color:#bbb; font-style:italic;">Last Library Sync: ${item.last_sync}</div>
                </div>
            </div>
            <div id="tvm-season-nav" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:25px; border-bottom:1px solid #eee; padding-bottom:15px;"></div>
            <div id="tvm-episode-results">Loading episodes...</div>`;

            $('#tvm-series-content').html(headerHtml);
            this.loadEpisodes(id);
        },

        loadEpisodes: function(id, restoreSeason = null) {
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_episodes', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    const filter = $('.tvm-filter-btn.active').data('filter');
                    let episodes = res.data;
                    if (filter === 'released') episodes = episodes.filter(ep => !ep.is_watched && !ep.is_future);
                    const grouped = {};
                    episodes.forEach(ep => { if (!grouped[ep.season]) grouped[ep.season] = []; grouped[ep.season].push(ep); });
                    const seasons = Object.keys(grouped).sort((a, b) => a - b);
                    let navHtml = '', contentHtml = '';
                    seasons.forEach((sNum, index) => {
                        const isActive = (restoreSeason) ? (sNum == restoreSeason) : (index === 0);
                        navHtml += `<button class="tvm-season-tab ${isActive ? 'active' : ''}" data-season="${sNum}" style="border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:13px; transition:0.2s; ${isActive ? 'background:#2271b1; color:#fff; font-weight:700;' : 'background:#f5f5f5; color:#666; font-weight:600;'}">Season ${sNum}</button>`;
                        contentHtml += `<div id="tvm-season-group-${sNum}" class="tvm-season-content-group" style="display:${isActive ? 'flex' : 'none'}; flex-direction:column; gap:12px;"><div style="display:flex; justify-content:flex-end; margin-bottom:10px;"><button class="tvm-mark-season-watched button button-primary button-small" style="font-size:10px; font-weight:700;">Mark Full Season Watched</button></div>`;
                        grouped[sNum].forEach(ep => {
                            const statusColor = ep.is_watched ? '#46b450' : '#ddd';
                            contentHtml += `<div class="tvm-episode-row" style="border-left-color: ${statusColor};"><div style="display:flex; justify-content:space-between; align-items:flex-start;"><div style="flex:1;"><div style="font-weight:800; color:#1d2327; font-size:16px;">E${ep.number} - ${ep.title.replace(/^S\d+E\d+\s-\s/i, '')}</div><div style="font-size:12px; color:#999; margin-top:4px;">Air Date: ${ep.air_date}</div></div><div style="display:flex; align-items:center; gap:15px;"><div class="tvm-episode-sources" style="display:flex; gap:6px;">${ep.is_future ? '<span style="font-size:10px; color:#2271b1; background:#e7f3ff; padding:4px 8px; border-radius:4px; font-weight:700;">Upcoming</span>' : this.renderSources(ep.sources)}</div>${ep.is_future ? '<span class="dashicons dashicons-clock" style="color:#bbb; font-size:24px;"></span>' : `<span class="dashicons ${ep.is_watched ? 'dashicons-visibility' : 'dashicons-hidden'} tvm-ep-watch" data-id="${ep.id}" data-watched="${!ep.is_watched}" style="cursor:pointer; color:${statusColor}; font-size:24px;"></span>`}</div></div><div class="tvm-ep-overview" style="margin-top:12px; font-size:13px; line-height:1.5; color:#666;">${ep.overview || 'No description available.'}</div></div>`;
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
            const sdata = window.tvm_settings_data || {};
            const userv = (sdata.user_services || []).map(Number);
            const preg = (sdata.primary_region || 'US').toString().trim().toUpperCase();
            const mlist = sdata.master_sources || [];
            if (userv.length === 0 && mlist.length === 0) return `<span style="font-size:10px; color:#999; font-style:italic;">Loading sources...</span>`;
            let html = '', count = 0;
            sources.forEach(s => {
                const sid = Number(s.source_id);
                const sType = (s.type || '').toString().trim().toLowerCase();
                const sRegion = (s.region || '').toString().trim().toUpperCase();
                if (['rent', 'buy', 'purchase'].includes(sType)) return;
                if (!userv.includes(sid)) return;
                if (sType === 'free' || (sType === 'sub' && sRegion === preg)) {
                    const m = mlist.find(master => Number(master.id) === sid);
                    if (m && m.logo_100px) { 
                        html += `<img src="${m.logo_100px}" title="${s.name}" style="width:48px; height:48px; border-radius:6px; border:1px solid #eee; object-fit:contain;">`; 
                        count++; 
                    }
                }
            });
            return count > 0 ? html : '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
        }
    };
    TVModule.init();
});
