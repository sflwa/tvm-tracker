<?php
/**
 * Renders the streaming sources list (Used by detail and unwatched pages).
 *
 * NOTE: This function needs to be included/defined before it is called by
 * view-render-seasons-episodes.php.
 *
 * @param array $title_sources Sources specific to the title.
 * @param array $source_map Master map of all sources.
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

        // First pass: Collect unique sources, prioritizing US region
        foreach ($title_sources as $source) {
            $source_id = absint($source['source_id']);
            $region = sanitize_text_field($source['region'] ?? '');

            // 1. Must be enabled by the user
            if (!in_array($source_id, $enabled_sources, true)) continue;

            // 2. If we haven't seen this source, or if we have and the new one is US, update/add it
            if (!isset($unique_source_ids[$source_id]) || $region === 'US') {
                $unique_source_ids[$source_id] = $source;
            }
        }

        // Second pass: Render the unique list
        foreach ($unique_source_ids as $source) {
            $source_id = absint($source['source_id']);
            $logo_url = $source_map[$source_id]['logo_100px'] ?? '';
            $web_url = esc_url($source['web_url'] ?? '#');
            $source_name = sanitize_text_field($source['name']);

            if (!empty($logo_url) && $web_url !== '#') {
                echo '<a href="' . $web_url . '" target="_blank">';
                echo '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($source_name) . esc_attr__(' logo', 'tvm-tracker') . '" class="' . esc_attr($class) . '">';
                echo '</a>';
            }
        }
    }
}
