<?php

include_once dirname( REDIRECTION_FILE ).'/models/database.php';

class Red_Fixer {
	public function get_status() {
		global $wpdb;

		$options = red_get_options();

		$db = new RE_Database();
		$groups = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_groups" ), 10 );
		$bad_group = $this->get_missing();
		$monitor_group = $options['monitor_post'];
		$valid_monitor = Red_Group::get( $monitor_group ) || $monitor_group === 0;
		$valid_rest = true;

		$result = array(
			$this->get_rest_status(),
			array_merge( array( 'id' => 'db', 'name' => __( 'Database tables', 'redirection' ) ), $db->get_status() ),
			array(
				'name' => __( 'Valid groups', 'redirection' ),
				'id' => 'groups',
				'message' => $groups === 0 ? __( 'No valid groups, so you will not be able to create any redirects', 'redirection' ) : __( 'Valid groups detected', 'redirection' ),
				'status' => $groups === 0 ? 'problem' : 'good',
			),
			array(
				'name' => __( 'Valid redirect group', 'redirection' ),
				'id' => 'redirect_groups',
				'message' => count( $bad_group ) > 0 ? __( 'Redirects with invalid groups detected', 'redirection' ) : __( 'All redirects have a valid group', 'redirection' ),
				'status' => count( $bad_group ) > 0 ? 'problem' : 'good',
			),
			array(
				'name' => __( 'Post monitor group', 'redirection' ),
				'id' => 'monitor',
				'message' => $valid_monitor === false ? __( 'Post monitor group is invalid', 'redirection' ) : __( 'Post monitor group is valid' ),
				'status' => $valid_monitor === false ? 'problem' : 'good',
			),
		);

		return $result;
	}

	public function get_rest_status() {
		$status = array(
			'name' => __( 'WordPress REST API', 'redirection' ),
			'id' => 'rest',
			'status' => 'good',
			'message' => sprintf( __( 'WordPress REST API is working at %s', 'redirection' ), red_get_rest_api() ),
		);

		$result = $this->check_api( red_get_rest_api() );

		if ( is_wp_error( $result ) ) {
			$status['status'] = 'problem';
			$status['message'] = $result->get_error_message();
		}

		return $status;
	}

	public function fix( $status ) {
		foreach ( $status as $item ) {
			if ( $item['status'] !== 'good' ) {
				$fixer = 'fix_'.$item['id'];
				$result = $this->$fixer();

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return $this->get_status();
	}

	private function get_missing() {
		global $wpdb;

		return $wpdb->get_results( "SELECT {$wpdb->prefix}redirection_items.id FROM {$wpdb->prefix}redirection_items LEFT JOIN {$wpdb->prefix}redirection_groups ON {$wpdb->prefix}redirection_items.group_id = {$wpdb->prefix}redirection_groups.id WHERE {$wpdb->prefix}redirection_groups.id IS NULL" );
	}

	public function fix_rest() {
		// First check the default REST API
		$result = $this->check_api( get_rest_url() );

		if ( is_wp_error( $result ) ) {
			// Try directly at index.php?rest_route
			$rest_api = home_url( '/index.php?rest_route=/' );
			$result = $this->check_api( $rest_api );

			if ( is_wp_error( $result ) ) {
				$rest_api = admin_url( 'admin-ajax.php' );
				$response = wp_remote_get( $rest_api );

				if ( ! is_wp_error( $result ) && isset( $response['body'] ) && $response['body'] === '0' ) {
					red_set_options( array( 'rest_api' => 2 ) );
					return true;
				}

				red_set_options( array( 'rest_api' => 0 ) );
				return false;
			}

			// It worked! Save the URL
			red_set_options( array( 'rest_api' => 1 ) );
			return true;
		}

		// Working
		red_set_options( array( 'rest_api' => 0 ) );
		return true;
	}

	private function check_api( $url ) {
		$response = wp_remote_get( $url, array( 'cookies' => $_COOKIE ) );
		$http_code = wp_remote_retrieve_response_code( $response );

		$specific = 'REST API returns an error code';
		if ( $http_code === 200 ) {
			$json = @json_decode( $response['body'] );

			if ( $json || $response['body'] === '0' ) {
				return true;
			}

			$specific = 'REST API returned invalid JSON data. This is probably an error page of some kind and indicates it has been disabled';
		} elseif ( $http_code === 301 || $http_code === 302 ) {
			$specific = 'REST API is being redirected. This indicates it has been disabled.';
		} elseif ( $http_code === 404 ) {
			$specific = 'REST API is returning 404 error. This indicates it has been disabled.';
		}

		return new WP_Error( 'redirection', $specific.' ('.( $http_code ? $http_code : '40x' ) .' - '.$url.')' );
	}

	private function fix_db() {
		$db = new RE_Database();

		try {
			$db->create_tables();
		} catch ( Exception $e ) {
			return new WP_Error( __( 'Failed to fix database tables', 'redirection' ) );
		}

		return true;
	}

	private function fix_groups() {
		if ( Red_Group::create( 'new group', 1 ) === false ) {
			return new WP_Error( __( 'Unable to create group', 'redirection' ) );
		}

		return true;
	}

	private function fix_redirect_groups() {
		global $wpdb;

		$missing = $this->get_missing();

		foreach ( $missing as $row ) {
			$wpdb->update( $wpdb->prefix.'redirection_items', array( 'group_id' => $this->get_valid_group() ), array( 'id' => $row->id ) );
		}
	}

	private function fix_monitor() {
		red_set_options( array( 'monitor_post' => $this->get_valid_group() ) );
	}

	private function get_valid_group() {
		$groups = Red_Group::get_all();

		return $groups[ 0 ]['id'];
	}
}
