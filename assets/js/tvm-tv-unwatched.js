/**
 * TV & Movie Tracker - Dedicated Unwatched Loader
 * Version 1.1.8 - Direct Integration with Original Inline Expansion
 */
jQuery(function($) {
    const SurgicalLoader = {
        init: function() {
            if (tvm_app.current_view === 'tv-unwatched') {
                // Stop the 12-second core load but keep the TVModule events alive
                $(document).off('tvm_tab_switch'); 
                this.performSurgicalLoad();
            }
        },

        performSurgicalLoad: function() {
            const $grid = $('#tvm-watchlist-grid');
            $grid.html('<div style="padding:40px; text-align:center;">🚀 Loading Unwatched Queue...</div>');

            $.post(tvm_app.ajax_url, { 
                action: 'tvm_get_tv_unwatched_surgical', 
                nonce: tvm_app.nonce 
            }, (res) => {
                if (res.success) {
                    // Populate global cache so the live TVModule can find the data
                    window.tvm_tv_cache = res.data.items;
                    this.render(res.data.items);
                }
            });
        },

        render: function(items) {
            let html = '';
            items.forEach(item => {
                const poster = item.poster_path ? `https://image.tmdb.org/t/p/w185${item.poster_path}` : '';
                html += `
                <div class="tvm-movie-card">
                    <div class="tvm-poster-wrapper">
                        <div class="tvm-badge-stats">${item.aired_unwatched_count}</div>
                        <div class="tvm-tv-trigger" data-id="${item.id}" style="cursor:pointer;">
                            <img src="${poster}" style="width:100%; display:block;">
                        </div>
                    </div>
                    <h5 style="margin:8px 0; font-size:10px; text-align:center; color:#333; font-weight:600;">${item.title}</h5>
                </div>`;
            });

            // 1. Build the fast grid
            $('#tvm-watchlist-grid').addClass('tvm-locked-grid').html(html);

            // 2. Ensure the expansion container exists at the BOTTOM of the page
            if (!$('#tvm-unwatched-inline-container').length) {
                $('#tvm-watchlist-grid').after('<div id="tvm-unwatched-inline-container" style="display:none; clear:both; margin-top:30px; border-top:2px solid #eee; padding-top:20px;"></div>');
            }

            // 3. Update the counter badge
            if (window.TVM_Core) TVM_Core.updateCounter(items.length);
        }
    };

    SurgicalLoader.init();
});
