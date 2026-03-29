<?php
/**
 * Post Type Registration & Admin UI Columns
 * Version 1.0.3 - Enhanced Admin Filtering
 *
 * @package TV_Movie_Tracker
 * @version 1.0.3
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

		// Hook: Default Admin Sorting
		add_action( 'pre_get_posts', array( $this, 'force_episode_order' ) );

		// Hook: Custom Admin Columns for Episodes
		add_filter( 'manage_tvm_episode_posts_columns', array( $this, 'add_episode_columns' ) );
		add_action( 'manage_tvm_episode_posts_custom_column', array( $this, 'render_episode_columns' ), 10, 2 );
		add_filter( 'manage_edit-tvm_episode_sortable_columns', array( $this, 'make_columns_sortable' ) );

		// Hook: Admin Filters
		add_action( 'restrict_manage_posts', array( $this, 'add_episode_filters' ) );
		add_action( 'parse_query', array( $this, 'filter_episodes_by_parent' ) );
	}

	/**
	 * Adds a dropdown to filter episodes by their Parent Show
	 */
	public function add_episode_filters( $post_type ) {
		if ( 'tvm_episode' !== $post_type ) {
			return;
		}

		$parent_id = isset( $_GET['tvm_parent_filter'] ) ? absint( $_GET['tvm_parent_filter'] ) : 0;

		// Get all TV shows to populate the filter dropdown
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
					echo '<a href="' . get_edit_post_link( $parent_id ) . '"><strong>' . get_the_title( $parent_id ) . '</strong></a>';
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
	 * Make the Air Date and Parent column sortable.
	 */
	public function make_columns_sortable( $columns ) {
		$columns['air_date'] = 'air_date';
		$columns['parent']   = 'parent';
		return $columns;
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
