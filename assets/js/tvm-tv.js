/**
 * TV & Movie Tracker - TV Module
 * Version: 1.4.0 - Calendar View Integration
 */
jQuery(function($) {
    const TVModule = {
        currentMonth: new Date(),
        calendarEvents: [],

        init: function() {
            if (window.location.hash === '#tv-calendar') {
                this.switchToCalendar();
            }

            $(document).on('tvm_tab_switch', (e, tab) => {
                if (tab === 'watchlist' && window.current_media_type === 'tv') this.load();
            });

            $(document).on('tvm_filter_change', (e, filter) => {
                if (window.current_media_type === 'tv') {
                    if (filter === 'calendar') this.renderCalendarView();
                    else this.applyFilter();
                }
            });

            $(document).on('click', '.tvm-cal-nav', (e) => {
                const dir = $(e.currentTarget).data('dir');
                this.currentMonth.setMonth(this.currentMonth.getMonth() + (dir === 'next' ? 1 : -1));
                this.renderCalendarView();
            });

            $(document).on('click', '.tvm-calendar-day', (e) => {
                const date = $(e.currentTarget).data('date');
                if (date) this.showDayDetails(date);
            });

            // Re-using stable triggers from v1.3.9
            $(document).on('click', '.tvm-tv-trigger', (e) => this.showSeriesDetails($(e.currentTarget).data('id')));
            $(document).on('change', '#tvm-unwatched-dropdown', (e) => {
                const id = $(e.target).val();
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
        },

        switchToCalendar: function() {
            window.current_media_type = 'tv';
            $('.tvm-type-tab').removeClass('active');
            $('.tvm-type-tab[data-type="tv"]').addClass('active');
            setTimeout(() => {
                $('.tvm-filter-btn').removeClass('active');
                $('.tvm-filter-btn[data-filter="calendar"]').addClass('active');
                this.renderCalendarView();
            }, 100);
        },

        renderCalendarView: function() {
            const year = this.currentMonth.getFullYear();
            const month = this.currentMonth.getMonth();
            const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
            
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { 
                action: 'tvm_get_calendar_month', 
                month: monthStr, 
                nonce: tvm_app.nonce 
            }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    this.calendarEvents = res.data;
                    this.buildCalendarGrid(year, month);
                }
            });
        },

        buildCalendarGrid: function(year, month) {
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
                    <div class="tvm-calendar-day-label">Sun</div><div class="tvm-calendar-day-label">Mon</div>
                    <div class="tvm-calendar-day-label">Tue</div><div class="tvm-calendar-day-label">Wed</div>
                    <div class="tvm-calendar-day-label">Thu</div><div class="tvm-calendar-day-label">Fri</div>
                    <div class="tvm-calendar-day-label">Sat</div>`;

            // Fill empty start days
            for (let i = 0; i < firstDay; i++) html += `<div class="tvm-calendar-day empty"></div>`;

            // Fill actual days
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const isToday = dateStr === today ? 'today' : '';
                const dayEvents = this.calendarEvents.filter(e => e.air_date === dateStr);
                
                let dots = '';
                dayEvents.forEach(ev => {
                    dots += `<div class="tvm-calendar-dot ${ev.is_watched ? 'watched' : ''}" title="${ev.series}: ${ev.display}"></div>`;
                });

                html += `
                <div class="tvm-calendar-day ${isToday}" data-date="${dateStr}">
                    <span class="tvm-calendar-date">${day}</span>
                    <div class="tvm-calendar-dots">${dots}</div>
                </div>`;
            }

            html += `</div><div id="tvm-calendar-details" style="margin-top:20px;"></div></div>`;
            $('#tvm-watchlist-grid').removeClass('tvm-locked-grid').html(html);
        },

        showDayDetails: function(date) {
            const dayEvents = this.calendarEvents.filter(e => e.air_date === date);
            if (dayEvents.length === 0) {
                $('#tvm-calendar-details').html('<p style="text-align:center; color:#999;">No episodes airing on this day.</p>');
                return;
            }

            let html = `<h4 style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">Airing on ${date}</h4>`;
            dayEvents.forEach(ev => {
                html += `
                <div style="display:flex; justify-content:space-between; align-items:center; padding:10px; background:#f9f9f9; border-radius:6px; margin-bottom:8px;">
                    <div>
                        <strong style="color:#2271b1;">${ev.series}</strong><br>
                        <span style="font-size:12px; color:#666;">${ev.display} - ${ev.title}</span>
                    </div>
                    <span class="dashicons ${ev.is_watched ? 'dashicons-visibility' : 'dashicons-hidden'}" style="color:${ev.is_watched ? '#46b450' : '#ccc'};"></span>
                </div>`;
            });
            $('#tvm-calendar-details').html(html);
        },

        load: function() {
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { action: 'tvm_get_tv_watchlist', nonce: tvm_app.nonce }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    window.tvm_tv_cache = res.data.items;
                    const activeFilter = $('.tvm-filter-btn.active').data('filter');
                    if (activeFilter === 'calendar') this.renderCalendarView();
                    else this.applyFilter();
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
                html += `
                <div style="background:#fff; padding:20px; border-radius:12px; border:1px solid #eee; margin-bottom:20px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span class="dashicons dashicons-arrow-left-alt2 tvm-dropdown-nav" data-dir="prev" style="cursor:pointer; font-size:30px; width:30px; height:30px; color:#2271b1;"></span>
                        <select id="tvm-unwatched-dropdown" style="flex:1; max-width:400px; height:45px; border-radius:8px; border:1px solid #ddd; font-weight:600;">
                            <option value="">-- Choose a Series (${items.length}) --</option>
                            ${items.map(i => `<option value="${i.id}">(${i.aired_unwatched_count}) ${i.title}</option>`).join('')}
                        </select>
                        <span class="dashicons dashicons-arrow-right-alt2 tvm-dropdown-nav" data-dir="next" style="cursor:pointer; font-size:30px; width:30px; height:30px; color:#2271b1;"></span>
                    </div>
                    <div id="tvm-unwatched-inline-container" style="display:none; margin-top:20px;"></div>
                </div>`;
                $('#tvm-watchlist-grid').removeClass('tvm-locked-grid').html(html);
            } else {
                html = items.map(item => `
                    <div class="tvm-movie-card">
                        <div class="tvm-overlay-controls"><span class="dashicons dashicons-trash tvm-delete-item" data-id="${item.id}" style="color:#ff4d4d;"></span></div>
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

        // Detailed loading logic for episodes remains the same as v1.3.9...
        showSeriesDetails: function(id) {
            const item = window.tvm_tv_cache.find(i => i.id == id);
            $('#tvm-watchlist-grid, .tvm-filters-container').hide();
            $('#tvm-tv-detail-view').show();
            $('#tvm-series-content').html(`<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;"><div style="display:flex; align-items:baseline; gap:12px;"><h3 style="margin:0;">${item.title}</h3><span style="font-size:12px; color:#999; font-style:italic;">Last Sync: ${item.last_sync}</span></div><button id="tvm-sync-episodes" class="button button-primary" data-id="${id}">Sync Episodes</button></div><div id="tvm-season-nav" style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:25px; border-bottom:1px solid #eee; padding-bottom:15px;"></div><div id="tvm-episode-results">Loading episodes...</div>`);
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
            const userv = sdata.user_services || [];
            const preg = (sdata.primary_region || 'US').toUpperCase();
            const mlist = sdata.master_sources || [];
            let html = '', count = 0;
            sources.forEach(s => {
                if (['rent', 'buy', 'purchase'].includes(s.type)) return;
                if (userv.includes(parseInt(s.source_id))) {
                    if (s.type === 'free' || (s.type === 'sub' && s.region.toUpperCase() === preg)) {
                        const m = mlist.find(master => master.id == s.source_id);
                        if (m && m.logo_100px) { html += `<img src="${m.logo_100px}" title="${s.name}" style="width:48px; height:48px; border-radius:6px; border:1px solid #eee; object-fit:contain;">`; count++; }
                    }
                }
            });
            return count > 0 ? html : '<span style="font-size:10px; color:#999; text-transform:uppercase; background:#f5f5f5; padding:4px 8px; border-radius:4px; font-weight:700;">No Sources</span>';
        }
    };
    TVModule.init();
});
