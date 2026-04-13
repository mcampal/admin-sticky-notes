<?php
/**
 * Plugin Name: WP Admin Sticky Notes
 * Plugin URI:  https://github.com/mcampal/wp-admin-sticky-notes
 * Description: Place persistent sticky notes anywhere in the WordPress admin.
 * Version:     1.0.0
 * Author:      mcampal
 * License:     GPL-2.0-or-later
 * Text Domain: wp-admin-sticky-notes
 */

defined( 'ABSPATH' ) || exit;

define( 'WASN_VERSION',    '1.0.0' );
define( 'WASN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WASN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WASN_PLUGIN_DIR . 'includes/class-notes-cpt.php';
require_once WASN_PLUGIN_DIR . 'includes/class-rest-api.php';

/**
 * Static bootstrap class — keeps all plugin-level logic out of the global
 * function namespace while staying dependency-free (no autoloader needed).
 */
final class WASN_Plugin {

	public static function bootstrap(): void {
		add_action( 'init',                  array( __CLASS__, 'init' ) );
		add_action( 'admin_menu',            array( 'WASN_Notes_CPT', 'add_admin_menu' ) );
		add_action( 'rest_api_init',         array( 'WASN_REST_API',  'register_routes' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the CPT and its meta on the init hook.
	 */
	public static function init(): void {
		WASN_Notes_CPT::register_cpt();
		WASN_Notes_CPT::register_meta();
	}

	/**
	 * Enqueue admin assets on every wp-admin page.
	 */
	public static function enqueue_assets(): void {
		// Load for any authenticated user — the JS and REST layer enforce
		// finer-grained caps (manage_options to create/edit/delete).
		if ( ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_style(
			'wasn-admin-notes',
			WASN_PLUGIN_URL . 'assets/css/admin-notes.css',
			array(),
			WASN_VERSION
		);

		wp_enqueue_script(
			'wasn-admin-notes',
			WASN_PLUGIN_URL . 'assets/js/admin-notes.js',
			array(),
			WASN_VERSION,
			true
		);

		$screen  = get_current_screen();
		$raw_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		wp_localize_script(
			'wasn-admin-notes',
			'wasnData',
			array(
				'restUrl'       => esc_url_raw( rest_url( 'wasn/v1' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'currentUrl'    => esc_url_raw( self::clean_page_url( $raw_uri ) ),
				'screenId'      => $screen ? $screen->id : '',
				// Cast to int so wp_localize_script sends 1/0, not "1"/"".
				'canManage'     => (int) self::user_can_manage(),
			)
		);
	}

	/**
	 * Whether the current user may create/edit/delete notes.
	 *
	 * Checks manage_options first (the correct WordPress primitive). Falls back
	 * to a direct role membership check because WooCommerce's role-initialisation
	 * routine can leave the administrator role record in the database without the
	 * manage_options capability entry, causing current_user_can() to return false
	 * even for a legitimate site administrator.
	 */
	public static function user_can_manage(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$user = wp_get_current_user();
		return $user->ID && in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * Strip one-time / transient query params before storing the page URL.
	 * Nonces and message flags change on every request and would break matching.
	 */
	private static function clean_page_url( string $raw_uri ): string {
		return remove_query_arg(
			array( '_wpnonce', '_wp_http_referer', 'message', 'updated', 'deleted', 'trashed', 'untrashed', 'locked', 'ids' ),
			$raw_uri
		);
	}

	public static function activate(): void {
		WASN_Notes_CPT::register_cpt();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

WASN_Plugin::bootstrap();
register_activation_hook( __FILE__,   array( 'WASN_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WASN_Plugin', 'deactivate' ) );
