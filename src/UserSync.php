<?php

namespace Userfy\User;

use Clerk\Backend\Model\User as ClerkUser;


class UserSync {

	/**
	 * Finds a WordPress user by their Clerk ID, creates one if not found,
	 * then updates their data and logs them in.
	 *
	 * @param ClerkUser $clerk_user The user object from the Clerk SDK.
	 */
	public function sync_and_login_user( $user ) {
		// Find user by Clerk ID stored in user meta.
		$wp_user = $this->find_wp_user_by_clerk_id( $user['id'] );

		// If not found by Clerk ID, try to find by email as a fallback.
		if ( ! $wp_user ) {
			$wp_user = get_user_by( 'email', $user['email_address'] );
		}

		$user_data = [
			'user_email'    => $user['email_address'],
			'user_login'    => $user['email_address'], // Using email as username per request
			'first_name'    => $user['first_name'],
			'last_name'     => $user['last_name'],
			'display_name'  => trim( $user['first_name'] . ' ' . $user['last_name'] ),
			'user_pass'     => wp_generate_password(), // Set a random, unusable password
		];

		if ( $wp_user ) {
			// User exists, update them.
			$user_data['ID'] = $wp_user->ID;
			$user_id = wp_update_user( $user_data );
		} else {
			// User does not exist, create them.
			$user_id = wp_insert_user( $user_data );
		}

		if ( is_wp_error( $user_id ) ) {
			error_log( 'UF_User Sync Error: ' . $user_id->get_error_message() );
			return;
		}

		// --- DATA SYNCING ---

		// Always store/update the Clerk ID. This is our primary link.
		update_user_meta( $user_id, 'uf_clerk_user_id', $user['id'] );

		// --- LOGIN ---
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
	}

	/**
	 * Finds a WP user by the Clerk User ID stored in meta.
	 *
	 * @param string $clerk_id
	 * @return \WP_User|false
	 */
	private function find_wp_user_by_clerk_id( string $clerk_id ) {
		$users = get_users( [
			'meta_key'   => 'uf_clerk_user_id',
			'meta_value' => $clerk_id,
			'number'     => 1,
			'count_total' => false
		] );

		return $users[0] ?? false;
	}
}
