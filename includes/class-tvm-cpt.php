<?php
/**
 * Post Type Registration & Admin UI Columns
 * Version 1.0.6 - Library Admin Filters (Type & Status)
 *
 * @package TV_Movie_Tracker
 * @version 1.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_CPT {

	/**
	 * Register TVM related post types and hooks.
	 */
	public function register_post_types() {
		// 1. Library Items
		register_post_type( 'tvm_item', array(
			'labels'      => array( 'name' => __( 'My Library', 'tvm-tracker' ) ),
			'public'      => true,
			'has_archive' => true,
			'menu_icon'   => 'dashicons-format-video',
			'supports'    => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'show_in_rest' => true,
		) );

		// 2. Episodes
		register_post_type( 'tvm_episode', array(
			'labels'      => array( 'name' => __( 'Episodes', 'tvm-tracker' ) ),
			'public'      => true,
			'show_ui'     => true,
			'menu_icon'   => 'dashicons-list-view',
			'supports'    => array( 'title', 'editor', 'custom-fields' ),
			'show_in_rest' => true,
		) );

		// --- Hooks for Episodes Lister ---
		add_filter( 'manage_tvm_episode_posts_columns', array( $this, 'add_episode_columns' ) );
		add_action( 'manage_tvm_episode_posts_custom_column', array( $this, 'render_episode_columns' ), 10, 2 );
		add_filter( 'manage_edit-tvm_episode_sortable_columns', array( $this, 'make_episode_columns_sortable' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_episode_filters' ) );
		add_action( 'parse_query', array( $this, 'filter_episodes_by_parent' ) );
		add_action( 'pre_get_posts', array( $this, 'force_episode_order' ) );

		// --- Hooks for Library Item Lister ---
		add_filter( 'manage_tvm_item_posts_columns', array( $this, 'add_item_columns' ) );
		add_action( 'manage_tvm_item_posts_custom_column', array( $this, 'render_item_columns' ), 10, 2 );
		add_filter( 'manage_edit-tvm_item_sortable_columns', array( $this, 'make_item_columns_sortable' ) );
		add_action( 'restrict_manage_posts', array( $this, 'add_library_filters' ) );
		add_action( 'parse_query', array( $this, 'filter_library_by_meta' ) );
	}

	/**
	 * Define columns for the Library Item lister.
	 */
	public function add_item_columns( $columns ) {
		$new_columns = array(
			'cb'         => $columns['cb'],
			'title'      => $columns['title'],
			'media_type' => __( 'Type', 'tvm-tracker' ),
			'status'     => __( 'Status', 'tvm-tracker' ),
			'date'       => $columns['date'],
		);
		return $new_columns;
	}

	/**
	 * Render Library Item columns.
	 */
	public function render_item_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'media_type':
				$type = get_post_meta( $post_id, '_tvm_media_type', true );
				$label = ( 'tv' === $type ) ? 'TV Show' : 'Movie';
				$icon  = ( 'tv' === $type ) ? 'dashicons-desktop' : 'dashicons-video-alt3';
				echo '<span class="dashicons ' . $icon . '" style="font-size:17px; vertical-align:text-bottom; margin-right:5px; color:#646970;"></span> ' . esc_html( $label );
				break;

			case 'status':
				$status = get_post_meta( $post_id, '_tvm_status', true ) ?: 'Unknown';
				$color  = ( in_array( strtolower( $status ), array( 'ended', 'canceled', 'released' ) ) ) ? '#d63638' : '#46b450';
				
				printf(
					'<span style="background:%s; color:#fff; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase;">%s</span>',
					$color,
					esc_html( $status )
				);
				break;
		}
	}

	/**
	 * Make Library Item columns sortable.
	 */
	public function make_item_columns_sortable( $columns ) {
		$columns['media_type'] = 'media_type';
		$columns['status']     = 'status';
		return $columns;
	}

	/**
	 * Add dropdown filters for the Library Item list (Type and Status)
	 */
	public function add_library_filters( $post_type ) {
		if ( 'tvm_item' !== $post_type ) {
			return;
		}

		// 1. Filter by Media Type
		$current_type = isset( $_GET['tvm_type_filter'] ) ? sanitize_text_field( $_GET['tvm_type_filter'] ) : '';
		echo '<select name="tvm_type_filter">';
		echo '<option value="">' . __( 'All Types', 'tvm-tracker' ) . '</option>';
		printf( '<option value="movie" %s>%s</option>', selected( $current_type, 'movie', false ), __( 'Movies Only', 'tvm-tracker' ) );
		printf( '<option value="tv" %s>%s</option>', selected( $current_type, 'tv', false ), __( 'TV Shows Only', 'tvm-tracker' ) );
		echo '</select>';

		// 2. Filter by Status (Dynamic from Meta)
		global $wpdb;
		$statuses = $wpdb->get_col( "SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = '_tvm_status' AND meta_value != ''" );
		
		if ( ! empty( $statuses ) ) {
			$current_status = isset( $_GET['tvm_status_filter'] ) ? sanitize_text_field( $_GET['tvm_status_filter'] ) : '';
			echo '<select name="tvm_status_filter">';
			echo '<option value="">' . __( 'All Statuses', 'tvm-tracker' ) . '</option>';
			foreach ( $statuses as $status ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $status ), selected( $current_status, $status, false ), esc_html( $status ) );
			}
			echo '</select>';
		}
	}

	/**
	 * Modifies the admin query to respect Library Type and Status filters
	 */
	public function filter_library_by_meta( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || 'tvm_item' !== $query->get( 'post_type' ) ) {
			return;
		}

		$meta_query = array();

		if ( ! empty( $_GET['tvm_type_filter'] ) ) {
			$meta_query[] = array(
				'key'     => '_tvm_media_type',
				'value'   => sanitize_text_field( $_GET['tvm_type_filter'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $_GET['tvm_status_filter'] ) ) {
			$meta_query[] = array(
				'key'     => '_tvm_status',
				'value'   => sanitize_text_field( $_GET['tvm_status_filter'] ),
				'compare' => '=',
			);
		}

		if ( ! empty( $meta_query ) ) {
			if ( count( $meta_query ) > 1 ) {
				$meta_query['relation'] = 'AND';
			}
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Define which columns appear in the Episode list.
	 */
	public function add_episode_columns( $columns ) {
		$new_columns = array(
			'cb'       => $columns['cb'],
			'title'    => $columns['title'],
			'parent'   => __( 'Parent Show', 'tvm-tracker' ),
			'air_date' => __( 'Air Date', 'tvm-tracker' ),
			'date'     => $columns['date'], 
		);
		return $new_columns;
	}

	/**
	 * Fill the columns with data from Post Meta.
	 */
	public function render_episode_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'parent':
				$parent_id = get_post_meta( $post_id, '_tvm_parent_id', true );
				if ( $parent_id ) {
					echo '<a href="' . get_edit_post_link( absint($parent_id) ) . '"><strong>' . get_the_title( $parent_id ) . '</strong></a>';
				} else {
					echo '<span style="color:#d63638;">' . __( 'Orphaned', 'tvm-tracker' ) . '</span>';
				}
				break;

			case 'air_date':
				$date = get_post_meta( $post_id, '_tvm_air_date', true );
				if ( $date ) {
					$is_future = strtotime( $date ) > time();
					$color     = $is_future ? '#2271b1' : '#646970';
					$weight    = $is_future ? 'bold' : 'normal';
					
					echo '<span style="color:' . $color . '; font-weight:' . $weight . ';">';
					echo date( 'M j, Y', strtotime( $date ) );
					echo $is_future ? ' <small>(Upcoming)</small>' : '';
					echo '</span>';
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Make the Air Date and Parent columns sortable for episodes.
	 */
	public function make_episode_columns_sortable( $columns ) {
		$columns['air_date'] = 'air_date';
		$columns['parent']   = 'parent';
		return $columns;
	}

	/**
	 * Adds a dropdown to filter episodes by their Parent Show
	 */
	public function add_episode_filters( $post_type ) {
		if ( 'tvm_episode' !== $post_type ) {
			return;
		}

		$parent_id = isset( $_GET['tvm_parent_filter'] ) ? absint( $_GET['tvm_parent_filter'] ) : 0;

		$shows = get_posts( array(
			'post_type'      => 'tvm_item',
			'posts_per_page' => -1,
			'meta_key'       => '_tvm_media_type',
			'meta_value'     => 'tv',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		echo '<select name="tvm_parent_filter">';
		echo '<option value="">' . __( 'All Shows', 'tvm-tracker' ) . '</option>';
		foreach ( $shows as $show ) {
			printf(
				'<option value="%d" %s>%s</option>',
				$show->ID,
				selected( $parent_id, $show->ID, false ),
				get_the_title( $show->ID )
			);
		}
		echo '</select>';
	}

	/**
	 * Modifies the admin query to respect the parent show filter
	 */
	public function filter_episodes_by_parent( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() ) {
			return;
		}

		if ( 'tvm_episode' === $query->get( 'post_type' ) && ! empty( $_GET['tvm_parent_filter'] ) ) {
			$query->set( 'meta_query', array(
				array(
					'key'     => '_tvm_parent_id',
					'value'   => absint( $_GET['tvm_parent_filter'] ),
					'compare' => '=',
				),
			) );
		}
	}

	/**
	 * Ensure the default order is correct.
	 */
	public function force_episode_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'tvm_episode' === $query->get( 'post_type' ) ) {
			if ( ! isset( $_GET['orderby'] ) ) {
				$query->set( 'orderby', 'title' );
				$query->set( 'order'  , 'ASC' );
			}
		}
	}
}
