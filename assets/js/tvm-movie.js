/**
 * TV & Movie Tracker - Movie Module
 * Version 1.1.6 - Verified AJAX Handshake
 */
jQuery(function($) {
    const MovieModule = {
        init: function() {
            $(document).on('tvm_tab_switch', (e, tab) => {
                if (tab === 'watchlist' && window.current_media_type === 'movie') this.load();
            });
            $(document).on('tvm_filter_change', () => {
                if (window.current_media_type === 'movie') this.applyFilter();
            });
            $(document).on('click', '.tvm-movie-trigger', (e) => this.openModal($(e.currentTarget).data('id')));
            
            $(document).on('click', '.tvm-quick-watch', (e) => {
                e.stopPropagation();
                this.toggleWatched($(e.currentTarget));
            });

            $(document).on('click', '.tvm-quick-untrack', (e) => {
                e.stopPropagation();
                this.untrackItem($(e.currentTarget).data('tmdb'));
            });
        },

        load: function() {
            TVM_Core.showLoading();
            $.post(tvm_app.ajax_url, { action: 'tvm_get_movie_watchlist', nonce: tvm_app.nonce }, (res) => {
                TVM_Core.hideLoading();
                if (res.success) {
                    window.tvm_movie_cache = res.data.items;
                    this.updateStats(res.data.stats);
                    this.applyFilter();
                }
            });
        },

        updateStats: function(s) {
            const html = `Movies: ${s.total} Total • ${s.available} Released • ${s.watched} Watched • ${s.percent}%`;
            $('#tvm-stats-display').html(html);
        },

        applyFilter: function() {
            const filter = $('.tvm-filter-btn.active').data('filter') || 'all';
            const search = $('#tvm-vault-search-input').val().toLowerCase();
            const streamOnly = $('#tvm-stream-only-toggle').is(':checked');

            let items = [...(window.tvm_movie_cache || [])];
            if (filter === 'watched') items = items.filter(i => i.is_watched);
            else if (filter === 'released') items = items.filter(i => !i.is_watched && i.status === 'released');
            else if (filter === 'upcoming') items = items.filter(i => i.status === 'upcoming');

            if (streamOnly) items = items.filter(i => i.has_streaming === true);
            if (search) items = items.filter(i => i.title.toLowerCase().includes(search));

            items.sort((a, b) => {
                if (filter === 'upcoming') {
                    if (a.days_to_go === 'TBA') return 1;
                    if (b.days_to_go === 'TBA') return -1;
                    return parseInt(a.days_to_go) - parseInt(b.days_to_go);
                }
                return a.title.localeCompare(b.title);
            });

            TVM_Core.updateCounter(items.length);
            this.render(items);
        },

        render: function(items) {
            let html = '';
            items.forEach(item => {
                const watchIcon = item.is_watched ? 'dashicons-visibility' : 'dashicons-hidden';
                const watchColor = item.is_watched ? '#46b450' : 'rgba(255,255,255,0.8)';
                const dayBadge = (item.status === 'upcoming') ? `<div class="tvm-badge-upcoming">${item.days_to_go === 'TBA' ? 'TBA' : item.days_to_go + ' DAYS'}</div>` : '';

                const posterContent = (item.poster_path && item.poster_path !== "") 
                    ? `<img src="https://image.tmdb.org/t/p/w185${item.poster_path}" style="width:100%; height:100%; object-fit:cover; display:block;">`
                    : `<div class="tvm-placeholder-poster"><span class="dashicons dashicons-format-video"></span><span class="placeholder-text">No Poster</span></div>`;

                html += `
                <div class="tvm-movie-card">
                    <div class="tvm-poster-wrapper">
                        ${dayBadge}
                        <div class="tvm-movie-trigger" data-id="${item.id}" style="cursor:pointer; height:100%;">
                            ${posterContent}
                        </div>
                        <div class="tvm-overlay-controls">
                            <span class="dashicons ${watchIcon} tvm-quick-watch" data-tmdb="${item.tmdb_id}" data-watched="${!item.is_watched}" style="color:${watchColor};"></span>
                            <span class="dashicons dashicons-dismiss tvm-quick-untrack" data-tmdb="${item.tmdb_id}" style="color:#ff4d4d;"></span>
                        </div>
                    </div>
                    <h5 style="margin:8px 0; font-size:11px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                </div>`;
            });
            $('#tvm-watchlist-grid').html(html || '<p style="grid-column:1/-1; text-align:center; padding:40px;">No movies found.</p>');
        },

        toggleWatched: function($btn) {
            $.post(tvm_app.ajax_url, { 
                action: 'tvm_toggle_watched', 
                tmdb_id: $btn.data('tmdb'), 
                watched: $btn.data('watched').toString(), // Ensure string
                nonce: tvm_app.nonce 
            }, () => { this.load(); });
        },

        untrackItem: function(tmdb_id) {
            if (!confirm('Remove from vault?')) return;
            $.post(tvm_app.ajax_url, { 
                action: 'tvm_untrack_item', 
                tmdb_id: tmdb_id, 
                nonce: tvm_app.nonce 
            }, () => { this.load(); });
        },

        openModal: function(id) {
            const item = window.tvm_movie_cache.find(i => i.id == id);
            if (!item) return;
            $('#tvm-modal-poster').attr('src', item.poster_path ? `https://image.tmdb.org/t/p/w500${item.poster_path}` : '');
            $('#tvm-modal-overview').text('Loading...');
            $('#tvm-modal-sources').empty();
            $('#tvm-details-modal').css('display', 'flex');

            $.post(tvm_app.ajax_url, { action: 'tvm_get_movie_details', post_id: id, nonce: tvm_app.nonce }, (res) => {
                if (res.success) {
                    $('#tvm-modal-title').text(res.data.title);
                    $('#tvm-modal-overview').text(res.data.overview);
                    $('#tvm-modal-sources').html(res.data.sources);
                }
            });
        }
    };
    MovieModule.init();
});