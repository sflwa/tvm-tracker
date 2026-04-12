/**
 * TV & Movie Tracker - Search Logic
 * Version 1.1.0 - List View & Media Toggles
 */
jQuery(function($) {

    // --- SEARCH & ADD (ENTER KEY SUPPORT) ---
    $(document).on('keypress', '#tvm-frontend-search-input', function(e) {
        if (e.which === 13) { 
            e.preventDefault(); 
            $('#tvm-frontend-search-btn').click(); 
        }
    });

    $(document).on('click', '#tvm-frontend-search-btn', function() {
        const query = $('#tvm-frontend-search-input').val();
        if (!query) return;
        const btn = $(this);
        btn.prop('disabled', true).text('...');
        
        $.post(tvm_app.ajax_url, { 
            action: 'tvm_search_tmdb_alpha', 
            query: query, 
            nonce: tvm_app.nonce 
        }, function(response) {
            if (response.success) {
                let html = '';
                response.data.forEach(item => {
                    if (item.media_type !== 'movie' && item.media_type !== 'tv') return;
                    
                    const poster = item.poster_path ? `https://image.tmdb.org/t/p/w185${item.poster_path}` : '';
                    const imgHtml = poster ? `<img src="${poster}" style="width:100%; border-radius:8px; display:block;">` : 
                                            `<div style="width:100%; aspect-ratio:2/3; background:#f0f0f0; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #ddd;"><span class="dashicons dashicons-format-video" style="font-size:30px; color:#ccc; width:30px; height:30px;"></span></div>`;
                    
                    const btnLabel = item.is_tracked ? 'In Vault' : 'Track';
                    const btnAttr = item.is_tracked ? 'disabled' : '';
                    const btnStyle = item.is_tracked ? 'background:#eee; color:#999; border:none; cursor:default;' : '';

                    // Extract Year
                    const dateRaw = item.release_date || item.first_air_date || '';
                    const year = dateRaw ? dateRaw.substring(0, 4) : 'TBA';
                    const typeLabel = item.media_type === 'tv' ? 'TV Show' : 'Movie';

                    html += `
                    <div class="tvm-search-row" data-type="${item.media_type}">
                        <div class="tvm-search-poster">${imgHtml}</div>
                        <div class="tvm-search-meta">
                            <h4 style="margin:0 0 5px 0; font-size:16px;">${item.title || item.name}</h4>
                            <div style="font-size:12px; color:#666; font-weight:600;">
                                <span class="tvm-search-year">${year}</span> &bull; 
                                <span class="tvm-search-type-tag">${typeLabel}</span>
                            </div>
                        </div>
                        <div class="tvm-search-action">
                            <button class="tvm-frontend-import-btn button button-primary" 
                                    data-id="${item.id}" 
                                    data-type="${item.media_type}" 
                                    ${btnAttr} 
                                    style="${btnStyle}">${btnLabel}</button>
                        </div>
                    </div>`;
                });
                $('#tvm-frontend-results').html(html || '<p style="text-align:center; padding:40px;">No results found.</p>');
                
                // Trigger default filter view (All)
                $('.tvm-search-filter.active').click();
            }
            btn.prop('disabled', false).text('Search');
        });
    });

    // Toggle logic
    $(document).on('click', '.tvm-search-filter', function() {
        const type = $(this).data('type');
        $('.tvm-search-filter').removeClass('active');
        $(this).addClass('active');

        if (type === 'all') {
            $('.tvm-search-row').show();
        } else {
            $('.tvm-search-row').hide();
            $(`.tvm-search-row[data-type="${type}"]`).show();
        }
    });

    $(document).on('click', '.tvm-frontend-import-btn', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Processing...');
        
        $.post(tvm_app.ajax_url, { 
            action: 'tvm_import_item', 
            tmdb_id: btn.data('id'), 
            type: btn.data('type'), 
            nonce: tvm_app.nonce 
        }, function(response) {
            if (response.success) {
                btn.text('In Vault').css({'background':'#eee', 'color':'#999', 'border':'none'}).prop('disabled', true);
            } else {
                alert('Import failed: ' + (response.data || 'Unknown error'));
                btn.prop('disabled', false).text('Track');
            }
        });
    });
});
