<?php
/**
 * Post Type Registration & Admin UI Columns
 *
 * @package TV_Movie_Tracker
 * @version 1.0.2
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
			'date'     => $columns['date'], // Keep original date column at end
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
	 * Make the Air Date column sortable.
	 */
	public function make_columns_sortable( $columns ) {
		$columns['air_date'] = 'air_date';
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
				$query->set( 'order', 'ASC' );
			}
		}
	}
}
