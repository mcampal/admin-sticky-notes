<?php
/**
 * Registers the 'admin_sticky_note' Custom Post Type and its meta fields.
 */

defined( 'ABSPATH' ) || exit;

class WASN_Notes_CPT {

	/**
	 * Meta keys used by the plugin.
	 */
	const META_SCREEN_ID = '_wasn_screen_id';
	const META_PAGE_URL  = '_wasn_page_url';
	const META_SELECTOR  = '_wasn_selector';
	const META_POS_X     = '_wasn_pos_x';
	const META_POS_Y     = '_wasn_pos_y';
	const META_COLOR     = '_wasn_color';

	/** @var string[] Allowed note colours. */
	const ALLOWED_COLORS = array( 'yellow', 'red', 'green', 'blue' );

	/**
	 * Register the CPT. Called directly from the init hook in the main file
	 * and from the activation hook.
	 */
	public static function register_cpt() {
		$labels = array(
			'name'               => _x( 'Sticky Notes', 'post type general name', 'wp-admin-sticky-notes' ),
			'singular_name'      => _x( 'Sticky Note', 'post type singular name', 'wp-admin-sticky-notes' ),
			'menu_name'          => __( 'Sticky Notes', 'wp-admin-sticky-notes' ),
			'add_new'            => __( 'Add New', 'wp-admin-sticky-notes' ),
			'add_new_item'       => __( 'Add New Note', 'wp-admin-sticky-notes' ),
			'edit_item'          => __( 'Edit Note', 'wp-admin-sticky-notes' ),
			'new_item'           => __( 'New Note', 'wp-admin-sticky-notes' ),
			'view_item'          => __( 'View Note', 'wp-admin-sticky-notes' ),
			'search_items'       => __( 'Search Notes', 'wp-admin-sticky-notes' ),
			'not_found'          => __( 'No notes found.', 'wp-admin-sticky-notes' ),
			'not_found_in_trash' => __( 'No notes found in Trash.', 'wp-admin-sticky-notes' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false, // We add our own menu entry below.
			'show_in_rest'       => true,  // Needed for REST access.
			'capability_type'    => 'post',
			'capabilities'       => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'edit_posts',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'delete_posts'       => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			),
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'author', 'custom-fields' ),
			'rewrite'            => false,
		);

		register_post_type( 'admin_sticky_note', $args );
	}

	/**
	 * Register post meta so they are exposed via the REST API.
	 */
	public static function register_meta() {
		$string_meta = array(
			self::META_SCREEN_ID => 'screen id of the admin page',
			self::META_PAGE_URL  => 'URL of the admin page',
			self::META_SELECTOR  => 'CSS selector of the anchor element',
			self::META_COLOR     => 'note colour (yellow|red|green|blue)',
		);

		foreach ( $string_meta as $key => $description ) {
			register_post_meta(
				'admin_sticky_note',
				$key,
				array(
					'type'          => 'string',
					'description'   => $description,
					'single'        => true,
					'default'       => '',
					'show_in_rest'  => true,
					'auth_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}

		foreach ( array( self::META_POS_X, self::META_POS_Y ) as $key ) {
			register_post_meta(
				'admin_sticky_note',
				$key,
				array(
					'type'          => 'number',
					'description'   => 'coordinate',
					'single'        => true,
					'default'       => 0,
					'show_in_rest'  => true,
					'auth_callback' => function() {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}
	}

	/**
	 * Add "Sticky Notes" under Settings in the admin menu.
	 * Hooked to admin_menu from the main plugin file.
	 */
	public static function add_admin_menu() {
		// Guard here so the menu entry never appears for non-managers.
		// We pass 'read' to add_options_page so WordPress's own capability
		// check does not block us when manage_options is missing from the role
		// definition (a known WooCommerce side-effect on some installs).
		if ( ! WASN_Plugin::user_can_manage() ) {
			return;
		}
		add_options_page(
			__( 'Sticky Notes', 'wp-admin-sticky-notes' ),
			__( 'Sticky Notes', 'wp-admin-sticky-notes' ),
			'read',
			'wasn-sticky-notes',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render the Settings → Sticky Notes management page.
	 *
	 * Note: delete-button JS lives in admin-notes.js (already enqueued on all
	 * admin pages) and wires up via .wasn-delete-note elements.
	 * Color badge CSS lives in admin-notes.css.
	 */
	public static function render_admin_page() {
		if ( ! WASN_Plugin::user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-admin-sticky-notes' ) );
		}

		// Cap at 200 rows; add pagination if this ever becomes a problem.
		// no_found_rows skips the COUNT(*) query since we don't paginate here.
		$notes = get_posts(
			array(
				'post_type'      => 'admin_sticky_note',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sticky Notes', 'wp-admin-sticky-notes' ); ?></h1>
			<p><?php esc_html_e( 'All sticky notes currently placed in the admin.', 'wp-admin-sticky-notes' ); ?></p>

			<?php if ( empty( $notes ) ) : ?>
				<p><?php esc_html_e( 'No notes yet. Visit any admin page and click "➕ Add Note" to create one.', 'wp-admin-sticky-notes' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Note', 'wp-admin-sticky-notes' ); ?></th>
							<th><?php esc_html_e( 'Color', 'wp-admin-sticky-notes' ); ?></th>
							<th><?php esc_html_e( 'Page', 'wp-admin-sticky-notes' ); ?></th>
							<th><?php esc_html_e( 'Author', 'wp-admin-sticky-notes' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wp-admin-sticky-notes' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'wp-admin-sticky-notes' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $notes as $note ) : ?>
							<?php
							$color    = get_post_meta( $note->ID, self::META_COLOR, true ) ?: 'yellow';
							$page_url = get_post_meta( $note->ID, self::META_PAGE_URL, true );
							$author   = get_userdata( $note->post_author );
							?>
							<tr id="wasn-note-row-<?php echo esc_attr( $note->ID ); ?>">
								<td><?php echo esc_html( wp_trim_words( $note->post_content, 20 ) ); ?></td>
								<td>
									<span class="wasn-color-badge wasn-color-<?php echo esc_attr( $color ); ?>">
										<?php echo esc_html( ucfirst( $color ) ); ?>
									</span>
								</td>
								<td>
									<?php if ( $page_url ) : ?>
										<a href="<?php echo esc_url( home_url( $page_url ) ); ?>">
											<?php echo esc_html( $page_url ); ?>
										</a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td><?php echo $author ? esc_html( $author->display_name ) : '&mdash;'; ?></td>
								<td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $note ) ); ?></td>
								<td>
									<button class="button button-small wasn-delete-note"
										data-id="<?php echo esc_attr( $note->ID ); ?>">
										<?php esc_html_e( 'Delete', 'wp-admin-sticky-notes' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
