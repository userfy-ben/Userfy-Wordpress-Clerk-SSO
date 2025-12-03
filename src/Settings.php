<?php

namespace Userfy\User;

class Settings {

	const OPTION_GROUP = 'userfy_settings_group';
	const OPTION_NAME = 'userfy_sso_options';
	const PAGE_SLUG = 'userfy-sso-settings';

	private string $plugin_name;
	private string $version;

	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Add the options page to the admin menu.
	 */
	public function add_options_page() {
		add_menu_page(
			'Userfy',
			'Userfy',
			'manage_options',
			'userfy',
			null,
			'dashicons-admin-users',
			65
		);

		add_submenu_page(
			'userfy',
			__( 'Single Sign-On', 'uf-user' ),
			__( 'Single Sign-On', 'uf-user' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'create_admin_page' ]
		);
	}

	/**
	 * Register the settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[ $this, 'sanitize' ]
		);

		add_settings_section(
			'sso_settings_section',
			__( 'Clerk.com API Settings', 'uf-user' ),
			null,
			self::PAGE_SLUG
		);

		add_settings_field(
			'enable_sso',
			__( 'Enable Clerk SSO', 'uf-user' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'sso_settings_section',
			[ 'id' => 'enable_sso', 'label' => __( 'Redirect login page to Clerk.com', 'uf-user' ) ]
		);

		add_settings_field(
			'fallback_to_wp_login',
			__( 'Enable Login Fallback', 'uf-user' ),
			[ $this, 'render_checkbox_field' ],
			self::PAGE_SLUG,
			'sso_settings_section',
			[ 'id' => 'fallback_to_wp_login', 'label' => __( 'If Clerk login fails or is cancelled, show the standard WordPress login form.', 'uf-user' ) ]
		);

		add_settings_field(
			'login_redirect_page_id',
			__( 'Log In Redirect Page', 'uf-user' ),
			[ $this, 'render_page_dropdown_field' ],
			self::PAGE_SLUG,
			'sso_settings_section',
			[ 'id' => 'login_redirect_page_id', 'desc' => 'Select a page to redirect users to after they log in. If left blank, they will be sent to the homepage.' ]
		);

	}

	/**
	 * Render the settings page wrapper.
	 */
	public function create_admin_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize each setting field as needed.
	 */
	public function sanitize( $input ): array {
		$sanitized_input = [];
		if ( isset( $input['login_redirect_page_id'] ) ) {
			$sanitized_input['login_redirect_page_id'] = absint( $input['login_redirect_page_id'] );
		}
		$sanitized_input['enable_sso'] = isset( $input['enable_sso'] ) ? 1 : 0;
		$sanitized_input['fallback_to_wp_login'] = isset( $input['fallback_to_wp_login'] ) ? 1 : 0;

		return $sanitized_input;
	}

	public function render_text_field( $args ) {
		$options = get_option( self::OPTION_NAME );
		$value = $options[ $args['id'] ] ?? '';
		printf(
			'<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text" />',
			esc_attr( $args['type'] ),
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}

	public function render_checkbox_field( $args ) {
		$options = get_option( self::OPTION_NAME );
		$checked = $options[ $args['id'] ] ?? 0;
		printf(
			'<input type="checkbox" id="%s" name="%s[%s]" value="1" %s /> <label for="%s">%s</label>',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] ),
			checked( 1, $checked, false ),
			esc_attr( $args['id'] ),
			esc_html( $args['label'] )
		);
	}

	public function render_page_dropdown_field( $args ) {
		$options = get_option( self::OPTION_NAME );
		$selected_page_id = $options[ $args['id'] ] ?? '';

		$pages = get_pages();

		printf( '<select id="%s" name="%s[%s]">',
			esc_attr( $args['id'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $args['id'] )
		);

		// Add a default option
		printf( '<option value="" %s>%s</option>',
			selected( $selected_page_id, '', false ),
			esc_html__( '— Default (Homepage) —', 'uf-user' )
		);

		foreach ( $pages as $page ) {
			printf( '<option value="%d" %s>%s</option>',
				esc_attr( $page->ID ),
				selected( $selected_page_id, $page->ID, false ),
				esc_html( $page->post_title )
			);
		}

		echo '</select>';

		if ( ! empty( $args['desc'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['desc'] ) );
		}
	}
}
