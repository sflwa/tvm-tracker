/**
 * TV & Movie Tracker - Settings Logic
 * Version 1.2.2 - Cache Recovery Support
 */
jQuery(function($) {
    window.tvm_settings_data = { 
        master_sources: [], 
        user_regions: [], 
        user_services: [],
        primary_region: 'US' 
    };

    $(document).on('tvm_tab_switch', function(e, tab) {
        if (tab === 'settings') loadTVMSettings();
    });

    function loadTVMSettings() {
        const $container = $('#tvm-settings-form-container');
        $container.html('<p style="padding:40px; text-align:center; color:#666;">Syncing streaming sources...</p>');
        
        $.post(tvm_app.ajax_url, { 
            action: 'tvm_get_settings', 
            nonce: tvm_app.nonce 
        }, function(res) {
            if (res.success && res.data.raw) {
                window.tvm_settings_data = res.data.raw;
                if (window.tvm_settings_data.master_sources.length === 0) {
                    $container.html('<p style="padding:40px; text-align:center; color:#ff4d4d;">Warning: No streaming services found. Please check your API key in Settings.</p>');
                } else {
                    renderSettingsUI();
                }
            }
        });
    }

    function renderSettingsUI() {
        const regions = [
            {code: 'US', name: 'United States'}, {code: 'CA', name: 'Canada'}, {code: 'GB', name: 'United Kingdom'},
            {code: 'AU', name: 'Australia'}, {code: 'DE', name: 'Germany'}, {code: 'FR', name: 'France'},
            {code: 'BR', name: 'Brazil'}, {code: 'MX', name: 'Mexico'}, {code: 'ES', name: 'Spain'}
        ];

        let html = `<div style="padding:25px; background:#fff; border-radius:12px; border:1px solid #eee; margin-bottom:30px;">
            <label style="display:block; font-weight:700; margin-bottom:5px;">Primary Region</label>
            <select id="tvm-primary-region-select" style="width:100%; max-width:320px; padding:10px; border-radius:8px; border:1px solid #ddd;">`;
        regions.forEach(r => {
            const selected = (window.tvm_settings_data.primary_region === r.code) ? 'selected' : '';
            html += `<option value="${r.code}" ${selected}>${r.name} (${r.code})</option>`;
        });
        html += `</select></div>`;

        html += `<div style="margin-bottom:30px;"><h4>Active Regions (VPN)</h4><div class="tvm-settings-grid">`;
        regions.forEach(r => {
            const isActive = window.tvm_settings_data.user_regions.includes(r.code);
            html += renderSettingCard(r.code, r.name, `https://flagcdn.com/w80/${r.code.toLowerCase()}.png`, isActive, 'region');
        });
        html += `</div></div>`;

        // Service Lists
        const subs = window.tvm_settings_data.master_sources.filter(s => s.type === 'sub');
        const free = window.tvm_settings_data.master_sources.filter(s => s.type === 'free');

        html += `<div style="margin-bottom:30px;"><h4>Subscription Services (${subs.length})</h4><div class="tvm-settings-grid">`;
        subs.forEach(s => html += renderSettingCard(s.id, s.name, s.logo_100px, window.tvm_settings_data.user_services.includes(parseInt(s.id)), 'service'));
        html += `</div></div>`;

        html += `<div style="margin-bottom:30px;"><h4>Free Services (${free.length})</h4><div class="tvm-settings-grid">`;
        free.forEach(s => html += renderSettingCard(s.id, s.name, s.logo_100px, window.tvm_settings_data.user_services.includes(parseInt(s.id)), 'service'));
        html += `</div></div>`;

        html += `<button id="tvm-save-settings-btn" class="button button-primary button-large" style="width:100%; height:50px;">Save Preferences</button>`;
        $('#tvm-settings-form-container').html(html);
    }

    function renderSettingCard(id, name, img, active, type) {
        const color = active ? '#46b450' : '#ddd';
        return `
        <div class="tvm-setting-card ${active ? 'active' : ''}" data-id="${id}" data-type="${type}" style="border:2px solid ${color}; border-radius:10px; padding:10px; background:#fff; cursor:pointer; text-align:center; position:relative;">
            <div style="position:absolute; top:-8px; right:-8px; background:${color}; color:#fff; border-radius:50%; width:20px; height:20px; display:flex; align-items:center; justify-content:center; border:2px solid #fff;">
                <span class="dashicons ${active ? 'dashicons-yes' : 'dashicons-no-alt'}" style="font-size:14px; width:14px; height:14px;"></span>
            </div>
            <img src="${img}" style="width:40px; height:40px; object-fit:contain; margin-bottom:5px;">
            <div style="font-size:10px; font-weight:700;">${name}</div>
        </div>`;
    }

    $(document).on('click', '.tvm-setting-card', function() {
        const id = $(this).data('id'), type = $(this).data('type');
        if (type === 'region') {
            window.tvm_settings_data.user_regions = window.tvm_settings_data.user_regions.includes(id) ? window.tvm_settings_data.user_regions.filter(r => r !== id) : [...window.tvm_settings_data.user_regions, id];
        } else {
            const sid = parseInt(id);
            window.tvm_settings_data.user_services = window.tvm_settings_data.user_services.includes(sid) ? window.tvm_settings_data.user_services.filter(s => s !== sid) : [...window.tvm_settings_data.user_services, sid];
        }
        renderSettingsUI();
    });

    $(document).on('click', '#tvm-save-settings-btn', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');
        $.post(tvm_app.ajax_url, { 
            action: 'tvm_save_settings', 
            regions: window.tvm_settings_data.user_regions, 
            services: window.tvm_settings_data.user_services, 
            primary_region: $('#tvm-primary-region-select').val(),
            nonce: tvm_app.nonce 
        }, () => {
            $btn.text('Saved!');
            setTimeout(() => { location.reload(); }, 1000);
        });
    });

    // Styles
    if (!$('#tvm-settings-styles').length) {
        $('head').append(`<style id="tvm-settings-styles">.tvm-settings-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 15px; }.tvm-setting-card.active { background: #f0fdf4 !important; }</style>`);
    }
});