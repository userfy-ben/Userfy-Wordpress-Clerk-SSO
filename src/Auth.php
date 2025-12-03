<?php

namespace Userfy\User;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class Auth {
	private string $plugin_name;
	private string $version;
	private array $options;
	private array $clerk_config;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->options     = get_option( Settings::OPTION_NAME, [] );
		$this->clerk_config = [
			'frontend_api'    => $_ENV['CLERK_FRONTEND_API'] ?? '',
			'publishable_key' => $_ENV['CLERK_PUBLISHABLE_KEY'] ?? '',
			'secret_key'      => $_ENV['CLERK_SECRET_KEY'] ?? '',
		];

		// 1. LOGIN: Redirect wp-login.php to our custom Clerk login page.
		add_action( 'login_init', [ $this, 'redirect_to_clerk_login' ] ); // This stays to catch /wp-login.php
		// 2. LOGOUT: Create a custom logout page and intercept the logout process.
		add_filter( 'logout_url', [ $this, 'filter_logout_url' ], 10, 2 );
		add_action( 'login_init', [ $this, 'handle_clerk_logout' ] ); // Handles custom logout actions.
		// 3. AUTHENTICATION: Check for a Clerk session on every page load.
		add_action( 'init', [ $this, 'handle_clerk_authentication' ] );
		// 4. VIRTUAL PAGES: Intercept specific URLs to render login/logout pages without shortcodes.
		add_action( 'template_redirect', [ $this, 'handle_virtual_pages' ] );
		// 5. SHORTCODES: Add any additional shortcodes.
		add_shortcode( 'uf_sso_details', [ $this, 'render_sso_details_shortcode' ] );
	}

	/**
	 * Redirects the standard WordPress login page to the custom Clerk login page.
	 */
	public function redirect_to_clerk_login() {
		global $pagenow;

		// Conditions to bypass the redirect:
		// - SSO is disabled.
		// - We are not on wp-login.php.
		// - The user is already logged in.
		// - The action is not 'login' (e.g., 'logout', 'lostpassword').
		// - This is a fallback from a failed SSO attempt.
		$action = $_REQUEST['action'] ?? 'login';
		if ( empty( $this->options['enable_sso'] ) || 'wp-login.php' !== $pagenow || is_user_logged_in() || 'login' !== $action || isset( $_GET['sso_fallback'] ) ) {
			return;
		}

		// The page with our [uf_clerk_login] shortcode.
		$login_url = home_url( '/uf_sso_login/' );

		// Preserve the original redirect destination.
		if ( ! empty( $_REQUEST['redirect_to'] ) ) {
			$login_url = add_query_arg( 'redirect_to', urlencode( $_REQUEST['redirect_to'] ), $login_url );
		}

		wp_safe_redirect( $login_url );
		exit;
	}

	/**
	 * Renders the Clerk Sign In component for the virtual login page.
	 */
	public function render_clerk_login_shortcode(): string {
		// Get the publishable key from settings.
		if ( empty( $this->clerk_config['publishable_key'] ) ) {
			return '<p>Clerk SSO is not configured correctly. Missing Publishable Key.</p>';
		}

		// Clerk Frontend API and ClerkJS URLs
		$publishable_key    = $this->clerk_config['publishable_key'];
		$clerk_frontend_api = rtrim( $this->clerk_config['frontend_api'], '/' );
		$clerk_js_url       = "{$clerk_frontend_api}/npm/@clerk/clerk-js@5/dist/clerk.browser.js";

		// Server-side check: If Clerk has set its cookie but WP hasn't logged the user in yet,
		// it means the login is in the final processing stage.
		$is_completing_login = isset( $_COOKIE['clerk_active_session'] ) && ! is_user_logged_in();

		ob_start();
		?>
		<script async crossorigin="anonymous"
				data-clerk-publishable-key="<?php echo esc_attr( $publishable_key ); ?>"
				src="<?php echo esc_url( $clerk_js_url ); ?>"
				type="text/javascript"></script>

		<?php if ( $is_completing_login ) : ?>
			<p>Completing login, please wait...</p>
		<?php else : ?>
			<div id="clerk-sign-in"></div>
			<script>
				window.addEventListener("load", async function () {
					await Clerk.load();
	
					// Get the redirect URL from the query string.
					const urlParams = new URLSearchParams(window.location.search);
					const redirectUrl = urlParams.get('redirect_to') || window.location.href;
	
					const signInDiv = document.getElementById("clerk-sign-in");
					if (signInDiv) {
						Clerk.mountSignIn(signInDiv, { forceRedirectUrl: redirectUrl });
					}
				});
			</script>
		<?php endif; ?>

		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the client-side logout handler for the virtual logout page.
	 */
	public function render_clerk_logout_shortcode(): string {
		if ( empty( $this->clerk_config['publishable_key'] ) || empty( $this->clerk_config['frontend_api'] ) ) {
			return '<p>Clerk SSO is not configured correctly.</p>';
		}

		$publishable_key    = $this->clerk_config['publishable_key'];
		$clerk_frontend_api = rtrim( $this->clerk_config['frontend_api'], '/' );
		$clerk_js_url       = "{$clerk_frontend_api}/npm/@clerk/clerk-js@5/dist/clerk.browser.js";

		// A simple, predictable URL for our custom logout action.
		$wp_logout_action_url = home_url( 'wp-login.php?action=clerk_logout' );

		ob_start();
		?>
		<p>You are being logged out. Please wait...</p>

		<script async crossorigin="anonymous"
				data-clerk-publishable-key="<?php echo esc_attr( $publishable_key ); ?>"
				src="<?php echo esc_url( $clerk_js_url ); ?>"
				type="text/javascript"></script>

		<script>
			window.addEventListener("load", async function () {
				try {
					console.log('Clerk Logout: Loading Clerk...');
					await Clerk.load();
					console.log('Clerk Logout: Clerk loaded. Calling signOut...');
					
					// Let the Clerk SDK handle the sign-out and the subsequent redirect.
					// This ensures the Clerk session is cleared before the redirect occurs.
					// We use a callback to ensure the redirect happens *after* sign-out is complete.
					await Clerk.signOut(() => {
						console.log('Clerk Logout: signOut complete. Redirecting to WordPress logout...');
						window.location.href = '<?php echo esc_url_raw( $wp_logout_action_url ); ?>';
					});
				} catch (error) {
					console.error('Clerk Logout: An error occurred during sign out.', error);
				}
			});
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the logged-in user's details from Clerk.
	 * Shortcode: [uf_sso_details]
	 */
	public function render_sso_details_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>You must be logged in to view your details.</p>';
		}

		$wp_user_id = get_current_user_id();
		$clerk_user_id = get_user_meta( $wp_user_id, 'uf_clerk_user_id', true );

		if ( empty( $clerk_user_id ) ) {
			return '<p>Could not find a linked Clerk user ID for your WordPress account.</p>';
		}

		try {
			$clerk_user_details = $this->get_clerk_user_details( $clerk_user_id );

			if ( ! $clerk_user_details ) {
				return '<p>Your details could not be retrieved from Clerk at this time.</p>';
			}

			ob_start();
			?>
			<h3>Your Clerk User Details</h3>
			<pre style="white-space: pre-wrap; word-wrap: break-word; background: #f5f5f5; padding: 15px; border: 1px solid #ccc; border-radius: 4px;"><?php
				echo esc_html( print_r( $clerk_user_details, true ) );
			?></pre>
			<?php
			return ob_get_clean();
		} catch ( \Exception $e ) {
			error_log( 'Clerk Details Shortcode Error: ' . $e->getMessage() );
			return '<p>An error occurred while fetching your details.</p>';
		}
	}

	/**
	 * Handles the unified logout for both WordPress and Clerk.
	 */
	public function handle_clerk_logout() {
		// When the JS from our logout page redirects here, we log the user out of WordPress.
		// This avoids any nonce issues from the client-side.
		if ( isset( $_GET['action'] ) && 'clerk_logout' === $_GET['action'] ) {
			wp_logout();
			wp_safe_redirect( home_url() );
			exit;
		}
	}

	/**
	 * Filters all WordPress logout URLs to point to our custom handler.
	 */
	public function filter_logout_url( string $logout_url, string $redirect ): string {
		// Point all logout links to our new client-side logout page.
		return home_url( '/uf_sso_logout/' );
	}

	/**
	 * Displays an error message on the login form if SSO failed.
	 */
	public function show_login_error_message() {
		if ( isset( $_GET['clerk_sso_failed'] ) ) {
			$message = 'Single Sign-On failed. Please try again or use your WordPress username and password.';
			echo '<div id="login_error">' . esc_html( $message ) . '</div>';
		}
	}

	/**
	 * Intercepts requests for our virtual pages and renders them.
	 */
	public function handle_virtual_pages() {
		// Get the request path from the server request URI.
		$request_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

		if ( 'uf_sso_login' === $request_path ) {
			$this->render_virtual_page(
				__( 'Log In', 'uf-user' ),
				$this->render_clerk_login_shortcode()
			);
			exit;
		}

		if ( 'uf_sso_logout' === $request_path ) {
			$this->render_virtual_page(
				__( 'Log Out', 'uf-user' ),
				$this->render_clerk_logout_shortcode()
			);
			exit;
		}
	}

	/**
	 * Renders a basic HTML page structure around the provided content.
	 *
	 * @param string $title The title of the page.
	 * @param string $content The HTML content for the body.
	 */
	private function render_virtual_page( string $title, string $content ) {
		status_header( 200 ); // Set a 200 OK status to prevent 404.
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( $title ); ?> &ndash; <?php bloginfo( 'name' ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body class="uf-sso-virtual-page">
			<div class="uf-sso-container" style="width: 100%; max-width: 400px; margin: 5% auto;">
				<?php echo $content; // Content is generated by our shortcode functions and is safe. ?>
			</div>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Checks for a Clerk session on page load and logs the user into WordPress.
	 */
	public function handle_clerk_authentication(): void {
		// Don't run if SSO is disabled or if the user is already logged into WordPress. The is_user_logged_in() check is sufficient.
		if ( empty( $this->options['enable_sso'] ) || is_user_logged_in() ) {
			return;
		}

		// Don't run if keys are not set.
		if ( empty( $this->clerk_config['secret_key'] ) ) {
			return;
		}

		$session_token = $_COOKIE['__session'] ?? null;
		if ( ! $session_token ) { // No token, nothing to do.
			return;
		}

		$clerk_frontend_api = rtrim( $this->clerk_config['frontend_api'], '/' );
		if ( empty( $clerk_frontend_api ) ) {
			error_log( 'Clerk SSO Error: Frontend API URL is not set.' );
			return;
		}

		try {
			$jwks_url = $clerk_frontend_api . '/.well-known/jwks.json';
			$claims = $this->validateClerkToken( $session_token, $jwks_url );

			// Token is valid. Get the Clerk user ID.
			$user_id = $claims['sub'] ?? null;
			if ( ! $user_id ) {
				throw new \Exception( 'Clerk user ID (sub) not found in token.' );
			}

			// Sync and log in the user to WordPress.
			$this->login_user( $user_id );

			// --- Final Redirect ---
			// Now that the user is logged into WordPress, redirect them to their destination.
			if ( ! empty( $_REQUEST['redirect_to'] ) ) {
				// Highest priority: a redirect_to parameter in the URL.
				$redirect_url = $_REQUEST['redirect_to']; // wp_safe_redirect will validate it.
			} elseif ( ! empty( $this->options['login_redirect_page_id'] ) && ( $page_id = absint( $this->options['login_redirect_page_id'] ) ) ) {
				// Second priority: the redirect page set in plugin settings.
				$redirect_url = get_permalink( $page_id );
				if ( ! $redirect_url ) {
					$redirect_url = home_url(); // Fallback if page ID is invalid.
				}
			} else {
				// Default: the homepage.
				$redirect_url = home_url();
			}
			wp_safe_redirect( $redirect_url );
			exit;
		} catch ( \Exception $e ) {
			// Validation failed. Log the error and redirect to the standard login page for fallback.
			error_log( 'Clerk Authentication Error: ' . $e->getMessage() );

			// Clear the invalid cookie to prevent loops.
			setcookie( '__session', '', time() - 3600, '/' );

			// Redirect to standard WP login with a fallback flag.
			wp_safe_redirect( wp_login_url() . '?sso_fallback=true' );
			exit;
		}
	}

	private function get_clerk_user_details( string $user_id ): ?array {
		$api_url = "https://api.clerk.com/v1/users/{$user_id}";
		$ch = curl_init($api_url);
		
		// Set the Authorization Header with your Clerk Secret Key
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			"Authorization: Bearer {$this->clerk_config['secret_key']}",
			"Content-Type: application/json"
		]);
		
		// Set to return the response as a string
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Execute the request
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		if ($http_code === 200) {
			// Successful API call
			return json_decode($response, true);
		} else {
			// Handle API error (e.g., 404 User Not Found, 401 Unauthorized)
			error_log("Clerk API Error: HTTP {$http_code} - {$response}");
			return null;
		}
	}

	private function getClerkJWKS( string $jwksUrl ): array {
		// A simple file-based cache for MAMP development
		$cache_file = '/tmp/clerk_jwks_cache.json';

		// Check if cache file exists and is recent (e.g., 1 hour old)
		if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
			return json_decode(file_get_contents($cache_file), true);
		}

		// Fetch new JWKS from Clerk
		$jwks_response = @file_get_contents($jwksUrl); // Using @ to suppress errors for clean handling

		if ($jwks_response === false) {
			throw new \Exception( 'Failed to fetch Clerk JWKS from ' . $jwksUrl );
		}
		
		$jwks_data = json_decode($jwks_response, true);
		
		if (empty($jwks_data['keys'])) {
			throw new \UnexpectedValueException('Clerk JWKS file is invalid or empty.');
		}
		
		// Save to cache
		file_put_contents($cache_file, json_encode($jwks_data));
		
		return $jwks_data;
	}

	/**
	 * Verifies the Clerk session token against the public key set.
	 * * @param string $token The JWT string from the __session cookie.
	 */
	private function validateClerkToken( string $token, string $jwksUrl ): array {
		$jwks_data = $this->getClerkJWKS($jwksUrl);

		// Convert JWKS into the format expected by the library.
		$key_set = JWK::parseKeySet($jwks_data);
		
		try {
			// Decode and verify the token. This handles signature, expiration, etc.
			$decoded = JWT::decode(
				$token, 
				$key_set,
				['RS256']
			);
			// Convert the returned object into an array for easier access
			return (array) $decoded;

		} catch (ExpiredException $e) {
			throw new \Exception('Session token has expired.', 401);
		} catch (BeforeValidException $e) {
			throw new \Exception('Session token is not yet valid.', 401);
		} catch (SignatureInvalidException $e) {
			// If the signature is invalid, the key might have rotated.
			// Clear the cache and advise a retry (in a real app, this is often a failure).
			@unlink( '/tmp/clerk_jwks_cache.json' );
			throw new \Exception('Token signature invalid. Key rotation may have occurred.', 401);
		} catch (\Exception $e) {
			// Catch all other JWT exceptions (e.g., malformed token, invalid algorithm)
			throw new \Exception('Token validation failed: ' . $e->getMessage(), 401);
		}
	}

	private function get_user_organizations( string $user_id ): array {
		$api_url = "https://api.clerk.com/v1/users/{$user_id}/organization_memberships";
		$ch = curl_init($api_url);
		
		// Set the Authorization Header with your Clerk Secret Key
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			"Authorization: Bearer {$this->clerk_config['secret_key']}",
			"Content-Type: application/json"
		]);
		
		// Set cURL options
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		
		// Execute the request
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		if ($http_code === 200) {
			// The response is a paginated list of OrganizationMembership objects
			$result = json_decode($response, true);
			
			// We typically care most about the 'data' array
			return $result['data'] ?? [];
		} else {
			error_log("Clerk Organizations API Error: HTTP {$http_code} - {$response}");
			return []; // Return an empty array on failure
		}
	}

	private function login_user( string $clerk_user_id ): void {
		// 1. Get full user details from Clerk API.
		$user_data = $this->get_clerk_user_details( $clerk_user_id );
		if ( ! $user_data ) {
			throw new \Exception( "Could not fetch details for Clerk user {$clerk_user_id}" );
		}

		// 2. Prepare a simplified user array for our sync function.
		$primary_email = '';
		foreach ( $user_data['email_addresses'] as $email ) {
			if ( $email['id'] === $user_data['primary_email_address_id'] ) {
				$primary_email = $email['email_address'];
				break;
			}
		}
		if ( empty( $primary_email ) ) {
			throw new \Exception( "Primary email not found for Clerk user {$clerk_user_id}" );
		}

		$user_to_sync = [
			'id'            => $user_data['id'],
			'first_name'    => $user_data['first_name'],
			'last_name'     => $user_data['last_name'],
			'email_address' => $primary_email,
			];

		// 3. Pass to the sync class to create/update/login the WP user.
		$sync = new \Userfy\User\UserSync();
		$sync->sync_and_login_user( $user_to_sync );
	}

}
