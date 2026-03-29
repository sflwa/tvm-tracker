<?php
/**
 * AJAX Settings Handler
 * Version 1.0.6 - Automatic Cache Recovery
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TVM_Settings_Handler {

	public function __construct() {
		add_action( 'wp_ajax_tvm_get_settings', array( $this, 'get_settings' ) );
		add_action( 'wp_ajax_tvm_save_settings', array( $this, 'save_settings' ) );
	}

	public function get_settings() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$user_id = get_current_user_id();

		// Check if the master source cache is missing
		$master_sources = get_transient( 'tvm_global_sources' );
		
		if ( false === $master_sources || empty($master_sources) ) {
			// Cache recovery: reach out to Watchmode
			$api_key = get_option( 'tvm_watchmode_api_key' );
			if ( $api_key ) {
				$response = wp_remote_get( "https://api.watchmode.com/v1/sources/?apiKey=$api_key" );
				if ( ! is_wp_error( $response ) ) {
					$body = wp_remote_retrieve_body( $response );
					$master_sources = json_decode( $body, true );
					if ( is_array( $master_sources ) ) {
						set_transient( 'tvm_global_sources', $master_sources, DAY_IN_SECONDS );
					}
				}
			}
		}

		$data = array(
			'raw' => array(
				'master_sources' => $master_sources ?: array(),
				'user_regions'   => get_user_meta( $user_id, 'tvm_user_regions', true ) ?: array('US'),
				'user_services'  => get_user_meta( $user_id, 'tvm_user_services', true ) ?: array(),
				'primary_region' => get_user_meta( $user_id, 'tvm_primary_region', true ) ?: 'US',
			)
		);

		wp_send_json_success( $data );
	}

	public function save_settings() {
		check_ajax_referer( 'tvm_import_nonce', 'nonce' );
		$user_id = get_current_user_id();

		if ( isset( $_POST['regions'] ) ) {
			update_user_meta( $user_id, 'tvm_user_regions', array_map( 'sanitize_text_field', $_POST['regions'] ) );
		}
		if ( isset( $_POST['services'] ) ) {
			update_user_meta( $user_id, 'tvm_user_services', array_map( 'absint', $_POST['services'] ) );
		}
		if ( isset( $_POST['primary_region'] ) ) {
			update_user_meta( $user_id, 'tvm_primary_region', sanitize_text_field( $_POST['primary_region'] ) );
		}

		wp_send_json_success( 'Settings updated' );
	}
}