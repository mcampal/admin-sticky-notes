<?php
/**
 * REST API endpoints for WP Admin Sticky Notes.
 *
 * Base: /wp-json/wasn/v1/
 *
 * GET    /notes               – list notes for current screen/URL
 * POST   /notes               – create a note
 * PUT    /notes/{id}          – update a note
 * DELETE /notes/{id}          – delete a note
 * POST   /notes/{id}/dismiss  – toggle dismissed state for current user
 */

defined( 'ABSPATH' ) || exit;

class WASN_REST_API {

	const REST_NAMESPACE = 'wasn/v1';

	public static function register_routes() {
		// List / create.
		register_rest_route(
			self::REST_NAMESPACE,
			'/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_notes' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
					'args'                => array(
						'screen_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page_url'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_note' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => self::note_args( true ),
				),
			)
		);

		// Update / delete single note.
		register_rest_route(
			self::REST_NAMESPACE,
			'/notes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_note' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
					'args'                => self::note_args( false ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_note' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);

		// Dismiss / un-dismiss a note for the current user.
		register_rest_route(
			self::REST_NAMESPACE,
			'/notes/(?P<id>\d+)/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'toggle_dismiss' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public static function can_read(): bool {
		return is_user_logged_in();
	}

	public static function can_manage(): bool {
		return WASN_Plugin::user_can_manage();
	}

	// -------------------------------------------------------------------------
	// Shared arg schema
	// -------------------------------------------------------------------------

	private static function note_args( bool $required ): array {
		return array(
			'content'   => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
			'color'     => array(
				'required'          => $required,
				'type'              => 'string',
				'enum'              => WASN_Notes_CPT::ALLOWED_COLORS,
				'default'           => 'yellow',
			),
			'screen_id' => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page_url'  => array(
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'selector'  => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'pos_x'     => array(
				'required'          => false,
				'type'              => 'number',
				'default'           => 0,
			),
			'pos_y'     => array(
				'required'          => false,
				'type'              => 'number',
				'default'           => 0,
			),
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /notes — return notes for the given screen_id and/or page_url.
	 *
	 * Matching strategy:
	 *   - Both provided → AND (must match exactly; prevents notes from one
	 *     product page bleeding onto another that shares the same screen_id).
	 *   - Only one provided → match on that single field.
	 *   - Neither provided → return all notes (management page is server-rendered
	 *     and skips this endpoint; this path exists for future tooling/debug use).
	 */
	public static function get_notes( WP_REST_Request $request ): WP_REST_Response {
		$screen_id = $request->get_param( 'screen_id' );
		$page_url  = $request->get_param( 'page_url' );

		$meta_query = array();

		if ( $screen_id && $page_url ) {
			$meta_query = array(
				'relation' => 'AND',
				array(
					'key'   => WASN_Notes_CPT::META_SCREEN_ID,
					'value' => $screen_id,
				),
				array(
					'key'   => WASN_Notes_CPT::META_PAGE_URL,
					'value' => $page_url,
				),
			);
		} elseif ( $page_url ) {
			$meta_query = array(
				array(
					'key'   => WASN_Notes_CPT::META_PAGE_URL,
					'value' => $page_url,
				),
			);
		} elseif ( $screen_id ) {
			$meta_query = array(
				array(
					'key'   => WASN_Notes_CPT::META_SCREEN_ID,
					'value' => $screen_id,
				),
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => 'admin_sticky_note',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			)
		);

		// Prime the WP user object cache for all unique authors in one query
		// so prepare_note() does not issue a separate DB hit per note.
		if ( $posts ) {
			$author_ids = array_unique( array_map( 'intval', wp_list_pluck( $posts, 'post_author' ) ) );
			get_users( array( 'include' => $author_ids, 'fields' => 'all' ) );
		}

		$user_id   = get_current_user_id();
		$dismissed = self::get_dismissed_ids( $user_id );

		$data = array_map(
			function( $post ) use ( $dismissed ) {
				return self::prepare_note( $post, $dismissed );
			},
			$posts
		);

		return rest_ensure_response( $data );
	}

	/**
	 * POST /notes — create a new note.
	 */
	public static function create_note( WP_REST_Request $request ): WP_REST_Response {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'admin_sticky_note',
				'post_status'  => 'publish',
				'post_title'   => wp_trim_words( $request->get_param( 'content' ), 10 ),
				'post_content' => $request->get_param( 'content' ),
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => $post_id->get_error_message() ), 500 );
		}

		self::save_meta( $post_id, $request );

		return new WP_REST_Response( self::prepare_note( get_post( $post_id ), array() ), 201 );
	}

	/**
	 * PUT /notes/{id} — update an existing note.
	 */
	public static function update_note( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'admin_sticky_note' !== $post->post_type ) {
			return new WP_REST_Response( array( 'message' => __( 'Note not found.', 'wp-admin-sticky-notes' ) ), 404 );
		}

		$content = $request->get_param( 'content' );
		// Use strict null check so empty string or "0" still updates correctly.
		if ( null !== $content ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => wp_trim_words( $content, 10 ),
					'post_content' => $content,
				)
			);
		}

		self::save_meta( $post_id, $request );

		$dismissed = self::get_dismissed_ids( get_current_user_id() );
		return new WP_REST_Response( self::prepare_note( get_post( $post_id ), $dismissed ), 200 );
	}

	/**
	 * DELETE /notes/{id} — permanently delete a note.
	 */
	public static function delete_note( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'admin_sticky_note' !== $post->post_type ) {
			return new WP_REST_Response( array( 'message' => __( 'Note not found.', 'wp-admin-sticky-notes' ) ), 404 );
		}

		wp_delete_post( $post_id, true );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $post_id ), 200 );
	}

	/**
	 * POST /notes/{id}/dismiss — toggle dismissed state for the current user.
	 */
	public static function toggle_dismiss( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		$post    = get_post( $post_id );

		if ( ! $post || 'admin_sticky_note' !== $post->post_type ) {
			return new WP_REST_Response( array( 'message' => __( 'Note not found.', 'wp-admin-sticky-notes' ) ), 404 );
		}

		$user_id   = get_current_user_id();
		$dismissed = self::get_dismissed_ids( $user_id );

		if ( in_array( $post_id, $dismissed, true ) ) {
			$dismissed = array_values( array_diff( $dismissed, array( $post_id ) ) );
			$state     = false;
		} else {
			$dismissed[] = $post_id;
			$state       = true;
		}

		update_user_meta( $user_id, '_wasn_dismissed_notes', $dismissed );

		return new WP_REST_Response( array( 'id' => $post_id, 'dismissed' => $state ), 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Save/update all post meta fields from a request.
	 */
	private static function save_meta( int $post_id, WP_REST_Request $request ): void {
		$meta_map = array(
			'screen_id' => WASN_Notes_CPT::META_SCREEN_ID,
			'page_url'  => WASN_Notes_CPT::META_PAGE_URL,
			'selector'  => WASN_Notes_CPT::META_SELECTOR,
			'pos_x'     => WASN_Notes_CPT::META_POS_X,
			'pos_y'     => WASN_Notes_CPT::META_POS_Y,
			'color'     => WASN_Notes_CPT::META_COLOR,
		);

		foreach ( $meta_map as $param => $meta_key ) {
			$value = $request->get_param( $param );
			if ( null !== $value ) {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Shape a WP_Post into the JSON structure returned to the frontend.
	 *
	 * @param WP_Post $post
	 * @param int[]   $dismissed_ids Note IDs the current user has dismissed.
	 */
	private static function prepare_note( WP_Post $post, array $dismissed_ids ): array {
		$author = get_userdata( $post->post_author );
		return array(
			'id'          => $post->ID,
			'content'     => $post->post_content,
			'color'       => get_post_meta( $post->ID, WASN_Notes_CPT::META_COLOR, true ) ?: 'yellow',
			'screen_id'   => get_post_meta( $post->ID, WASN_Notes_CPT::META_SCREEN_ID, true ),
			'page_url'    => get_post_meta( $post->ID, WASN_Notes_CPT::META_PAGE_URL, true ),
			'selector'    => get_post_meta( $post->ID, WASN_Notes_CPT::META_SELECTOR, true ),
			'pos_x'       => (float) get_post_meta( $post->ID, WASN_Notes_CPT::META_POS_X, true ),
			'pos_y'       => (float) get_post_meta( $post->ID, WASN_Notes_CPT::META_POS_Y, true ),
			'author_name' => $author ? $author->display_name : '',
			// Use GMT + explicit Z suffix so JS new Date() parses it as UTC,
			// avoiding off-by-one-day errors for users behind UTC.
			'date'        => str_replace( ' ', 'T', $post->post_date_gmt ) . 'Z',
			'dismissed'   => in_array( $post->ID, $dismissed_ids, true ),
		);
	}

	/**
	 * Return the array of note IDs the given user has dismissed.
	 *
	 * @param int $user_id
	 * @return int[]
	 */
	private static function get_dismissed_ids( int $user_id ): array {
		$raw = get_user_meta( $user_id, '_wasn_dismissed_notes', true );
		return is_array( $raw ) ? array_map( 'intval', $raw ) : array();
	}
}
