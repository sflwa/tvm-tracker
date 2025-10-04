<?php
/**
 * Renders the streaming sources list (Used by detail and unwatched pages).
 *
 * NOTE: This function handles both the V1 API format (for the main detail source list) 
 * and the V2.0 DB format (for episode source lists).
 *
 * @param array $title_sources Sources specific to the title (can be API output or DB output).
 * @param array $source_map Master map of all sources (Needed for V1 API lookup).
 * @param array $enabled_sources Array of enabled source IDs.
 * @param bool $is_small_icons Whether to use the small episode icon size.
 */
if ( ! function_exists( 'tvm_tracker_render_sources_list' ) ) {
    function tvm_tracker_render_sources_list( $title_sources, $source_map, $enabled_sources, $is_small_icons = false ) {
        if ( ! is_array( $title_sources ) || empty( $title_sources ) ) {
            return;
        }

        $class = $is_small_icons ? 'tvm-episode-source-logo' : 'tvm-source-logo';
        $unique_source_ids = [];
        
        // Determine if the data is V2.0 DB data by checking for a known V2.0 key
        $is_db_format = isset($title_sources[0]['logo_url']) && isset($title_sources[0]['source_name']);
        
        // First pass: Collect unique sources, prioritizing US region and filtering by enabled sources
        foreach ($title_sources as $source) {
            
            // Determine keys based on the detected data format
            $source_id = absint($source['source_id'] ?? $source['id'] ?? 0); 
            $region = sanitize_text_field($source['region'] ?? '');

            // 1. Must be enabled by the user (CRITICAL CHECK)
            if (!in_array($source_id, $enabled_sources, true)) continue;

            // 2. If we haven't seen this source, or if we have and the new one is US, update/add it
            if (!isset($unique_source_ids[$source_id]) || $region === 'US') {
                
                if ($is_db_format) {
                    // V2.0 DB FORMAT: Data is already fully joined (name, logo, url are present)
                    $unique_source_ids[$source_id] = [
                        'logo_url'    => $source['logo_url'],
                        'web_url'     => $source['web_url'],
                        'source_name' => $source['source_name'],
                        'region'      => $region,
                    ];
                } else {
                    // V1 API FORMAT (Used on Detail Page Title Sources): Requires $source_map lookup
                    if (isset($source_map[$source_id])) {
                        $source_details = $source_map[$source_id];
                        $unique_source_ids[$source_id] = [
                            'logo_url'    => $source_details['logo_100px'],
                            'web_url'     => $source['web_url'], 
                            'source_name' => $source_details['name'],
                            'region'      => $region,
                        ];
                    }
                }
            }
        }

        // Second pass: Render the unique list
        foreach ($unique_source_ids as $source) {
            // Access V2.0 standard keys (logo_url, source_name) which are guaranteed by the loop above.
            $logo_url = esc_url($source['logo_url'] ?? '#'); 
            $web_url = esc_url($source['web_url'] ?? '#');
            $source_name = sanitize_text_field($source['source_name'] ?? 'Source'); 

            if (!empty($logo_url) && $web_url !== '#') {
                echo '<a href="' . $web_url . '" target="_blank">';
                echo '<img src="' . $logo_url . '" alt="' . esc_attr($source_name) . esc_attr__(' logo', 'tvm-tracker') . '" class="' . esc_attr($class) . '">';
                echo '</a>';
            }
        }
    }
}
