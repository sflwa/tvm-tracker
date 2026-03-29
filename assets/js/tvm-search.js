/**
 * TV & Movie Tracker - Search Logic
 * Version 1.0.2 - Handshake Fix
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
                                            `<div style="width:100%; aspect-ratio:2/3; background:#f0f0f0; display:flex; align-items:center; justify-content:center; border-radius:8px; border:1px solid #ddd;"><span class="dashicons dashicons-format-video" style="font-size:40px; color:#ccc; width:40px; height:40px;"></span></div>`;
                    
                    const btnLabel = item.is_tracked ? 'In Vault' : 'Track';
                    const btnAttr = item.is_tracked ? 'disabled' : '';
                    const btnStyle = item.is_tracked ? 'background:#eee; color:#999; border:none; cursor:default;' : '';

                    html += `
                    <div class="tvm-search-card" style="text-align:center;">
                        <div style="position:relative; overflow:hidden; border-radius:8px;">${imgHtml}</div>
                        <h5 style="font-size:11px; margin:10px 0; min-height:28px; display:flex; align-items:center; justify-content:center;">${item.title || item.name}</h5>
                        <button class="tvm-frontend-import-btn button button-small" 
                                data-id="${item.id}" 
                                data-type="${item.media_type}" 
                                data-watched="false" 
                                ${btnAttr} 
                                style="width:100%; ${btnStyle}">${btnLabel}</button>
                    </div>`;
                });
                $('#tvm-frontend-results').html(html || '<p style="grid-column:1/-1; text-align:center;">No results found.</p>');
            }
            btn.prop('disabled', false).text('Search');
        });
    });

    $(document).on('click', '.tvm-frontend-import-btn', function() {
        const btn = $(this);
        
        // 1. Set visual processing state immediately
        btn.prop('disabled', true).text('Processing...');
        
        $.post(tvm_app.ajax_url, { 
            action: 'tvm_import_item', // Mapped to TVM_Importer
            tmdb_id: btn.data('id'), 
            type: btn.data('type'), 
            nonce: tvm_app.nonce 
        }, function(response) {
            if (response.success) {
                // 2. Lock state on success
                btn.text('In Vault').css({'background':'#eee', 'color':'#999', 'border':'none'}).prop('disabled', true);
            } else {
                // 3. Revert on error
                alert('Import failed: ' + (response.data || 'Unknown error'));
                btn.prop('disabled', false).text('Track');
            }
        }).fail(function() {
            alert('Server error: 400. Contact developer.');
            btn.prop('disabled', false).text('Track');
        });
    });

});
