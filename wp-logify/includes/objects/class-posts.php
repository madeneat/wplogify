<?php
/**
 * Contains the Posts class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use DateTime;
use Exception;
use WP_Error;
use WP_Post;

/**
 * Class WP_Logify\Posts
 *
 * Provides tracking of events related to posts.
 */
class Posts {

	/**
	 * Array to remember properties between different events.
	 *
	 * @var array
	 */
	private static $properties = array();

	/**
	 * Array to remember metadata between different events.
	 *
	 * @var array
	 */
	private static $eventmetas = array();

	/**
	 * Keep track of terms added to a post in a single request.
	 *
	 * @var array
	 */
	private static array $terms = array();

	// =============================================================================================
	// Hooks.

	/**
	 * Set up hooks for the events we want to log.
	 */
	public static function init() {
		// Track post creation and update.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 3 );
		add_action( 'pre_post_update', array( __CLASS__, 'on_pre_post_update' ), 10, 2 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
		add_action( 'update_post_meta', array( __CLASS__, 'on_update_post_meta' ), 10, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );

		// Track post deletion.
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ), 10, 2 );

		// Track attachment of terms and posts.
		add_action( 'added_term_relationship', array( __CLASS__, 'on_added_term_relationship' ), 10, 3 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_wp_after_insert_post' ), 10, 4 );
		add_action( 'deleted_term_relationships', array( __CLASS__, 'on_deleted_term_relationships' ), 10, 3 );
	}

	// =============================================================================================
	// Event handlers.

	/**
	 * Log the creation and update of a post.
	 *
	 * @param int     $post_id The ID of the post being saved.
	 * @param WP_Post $post The post object.
	 * @param bool    $update Whether this is an update or a new post.
	 */
	public static function on_save_post( int $post_id, WP_Post $post, bool $update ) {
		global $wpdb;

		// Ignore updates. We track post updates by tracking the creation of revisions, which
		// enables us to link to the compare revisions page.
		if ( $update ) {
			return;
		}

		debug( 'on_save_post' );

		// Check if we're updating or creating.
		$creating = wp_is_post_revision( $post_id ) === false;

		// If we're updating, the $post variable refers to the new revision rather than the parent post.
		if ( ! $creating ) {
			// Record the ID of the new revision.
			$revision_id = $post_id;

			// Load the parent object.
			$post_id = $post->post_parent;
			$post    = self::load( $post_id );

			// Replace changed content with object references.
			if ( ! empty( self::$properties['post_content'] ) ) {
				// For the old value, link to the revision (or show a deleted tag).
				self::$properties['post_content']->val = new Object_Reference( 'revision', $revision_id );
				// For the new value, link to the edit page (or show a deleted tag).
				self::$properties['post_content']->new_val = new Object_Reference( 'post', $post_id, $post->post_title );
			}
		}

		// Check if we need to use the 'Created' verb.
		$created = $creating || $post->post_status === 'auto-draft';

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $post->post_type ) . ( $created ? ' Created' : ' Updated' );

		// Log the event.
		Logger::log_event( $event_type, $post, null, self::$properties );
	}

	/**
	 * Make a note of the last modified datetime before the post is updated.
	 *
	 * @param int   $post_id The ID of the post being updated.
	 * @param array $data    The data for the post.
	 */
	public static function on_pre_post_update( int $post_id, array $data, ) {
		debug( 'on_pre_post_update' );

		global $wpdb;

		// Record the current last modified date.
		Property::update_array( self::$properties, 'post_modified', $wpdb->posts, self::get_last_modified_datetime( $post_id ) );
	}

	/**
	 * Track post update. This handler allows us to capture changed properties before the
	 * save_post handler is called, as that hook doesn't provide us with the before state.
	 *
	 * @param int     $post_id      Post ID.
	 * @param WP_Post $post_after   Post object following the update.
	 * @param WP_Post $post_before  Post object before the update.
	 */
	public static function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) {
		debug( 'on_post_updated' );

		global $wpdb;

		// Add changes.
		foreach ( $post_before as $key => $value ) {
			// Skip the dates in the posts table, they're incorrect.
			if ( in_array( $key, array( 'post_date', 'post_date_gmt', 'post_modified_gmt' ), true ) ) {
				continue;
			}

			// Process old value into the correct type.
			$old_val = Types::process_database_value( $key, $value );

			// Special handling for the last modified datetime.
			if ( $key === 'post_modified' ) {
				$new_val = self::get_last_modified_datetime( $post_id );
			} else {
				$new_val = Types::process_database_value( $key, $post_after->{$key} );
			}

			// Compare old and new values.
			if ( ! Types::are_equal( $old_val, $new_val ) ) {
				// Record change.
				Property::update_array( self::$properties, $key, $wpdb->posts, $old_val, $new_val );
			}
		}
	}

	/**
	 * Track post meta update.
	 *
	 * @param int    $meta_id    The ID of the meta data.
	 * @param int    $post_id    The ID of the post.
	 * @param string $meta_key   The key of the meta data.
	 * @param mixed  $meta_value The new value of the meta data.
	 */
	public static function on_update_post_meta( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ) {
		debug( 'on_update_post_meta' );

		global $wpdb;

		// Get the current value.
		$current_value = get_post_meta( $post_id, $meta_key, true );

		// Process values into correct types.
		$val     = Types::process_database_value( $meta_key, $current_value );
		$new_val = Types::process_database_value( $meta_key, $meta_value );

		// Note the change, if any.
		if ( ! Types::are_equal( $val, $new_val ) ) {
			Property::update_array( self::$properties, $meta_key, $wpdb->postmeta, $val, $new_val );
		}
	}

	/**
	 * Fires when a post is transitioned from one status to another.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition_post_status( string $new_status, string $old_status, WP_Post $post ) {
		global $wpdb;

		// Ensure this is not a revision.
		if ( wp_is_post_revision( $post ) ) {
			return;
		}

		// This event is triggered even when the status hasn't change, but we only need to log an
		// event if the status has changed.
		if ( $new_status === $old_status ) {
			return;
		}

		// Some status changes we don't care about.
		if ( in_array( $new_status, array( 'auto-draft', 'inherit' ), true ) ) {
			return;
		}

		debug( 'on_transition_post_status' );

		// Get the event type.
		$post_type  = self::get_post_type_singular_name( $post->post_type );
		$verb       = self::get_status_transition_verb( $old_status, $new_status );
		$event_type = "$post_type $verb";

		// Update the properties to correctly show the status change.
		$props = array();
		Property::update_array( $props, 'post_status', $wpdb->posts, $old_status, $new_status );

		// If the post is scheduled for the future, let's show this information.
		$metas = array();
		if ( $new_status === 'future' ) {
			$scheduled_publish_datetime = DateTimes::create_datetime( $post->post_date );
			Eventmeta::update_array( $metas, 'when_to_publish', $scheduled_publish_datetime );
		}

		// Log the event.
		Logger::log_event( $event_type, $post, $metas, $props );
	}

	/**
	 * Fires before a post is deleted, at the start of wp_delete_post().
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post   Post object.
	 */
	public static function on_before_delete_post( int $post_id, WP_Post $post ) {
		// Ignore revisions.
		if ( wp_is_post_revision( $post ) ) {
			return;
		}

		debug( 'on_before_delete_post' );

		// Get the attached terms.
		$attached_terms = self::get_attached_terms( $post_id );

		// If there weren't any, bail.
		if ( empty( $attached_terms ) ) {
			return;
		}

		// Add them to the eventmetas. One for each taxonomy.
		foreach ( $attached_terms as $taxonomy => $term_refs ) {
			// Get the taxonomy object.
			$taxonomy_obj = get_taxonomy( $taxonomy );

			// Create and add the event meta.
			$meta_key = 'attached_' . strtolower( $taxonomy_obj->labels->name );
			Eventmeta::update_array( self::$eventmetas, $meta_key, $term_refs );
		}
	}

	/**
	 * Log the deletion of a post.
	 *
	 * This method is called immediately before the post is deleted from the database.
	 *
	 * @param int     $post_id The ID of the post that was deleted.
	 * @param WP_Post $post The post object that was deleted.
	 */
	public static function on_delete_post( int $post_id, WP_Post $post ) {
		// Ensure this is not a revision.
		if ( wp_is_post_revision( $post ) ) {
			return;
		}

		debug( 'on_delete_post' );

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $post->post_type ) . ' Deleted';

		// Get all the post's properties, including metadata.
		$props = self::get_properties( $post );

		// Log the event.
		Logger::log_event( $event_type, $post, self::$eventmetas, $props );
	}

	/**
	 * Fires immediately after an object-term relationship is added.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $tt_id     Term taxonomy ID.
	 * @param string $taxonomy  Taxonomy slug.
	 */
	public static function on_added_term_relationship( int $post_id, int $tt_id, string $taxonomy ) {
		// Ignore revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		debug( 'on_added_term_relationship' );

		// Remember the newly attached term.
		$term                                = Terms::get_by_term_taxonomy_id( $tt_id );
		self::$terms[ $taxonomy ]['added'][] = Object_Reference::new_from_wp_object( $term );
	}

	/**
	 * Fires immediately after an object-term relationship is added.
	 *
	 * @param int    $post_id  The post ID.
	 * @param array  $tt_ids   An array of term taxonomy IDs.
	 * @param string $taxonomy The taxonomy slug.
	 */
	public static function on_deleted_term_relationships( int $post_id, array $tt_ids, string $taxonomy ) {
		// Ignore revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		debug( 'on_deleted_term_relationships' );

		// Convert the term_taxonomy IDs to Object_Reference objects.
		foreach ( $tt_ids as $tt_id ) {
			$term                                  = Terms::get_by_term_taxonomy_id( $tt_id );
			self::$terms[ $taxonomy ]['removed'][] = Object_Reference::new_from_wp_object( $term );
		}
	}

	/**
	 * Fires immediately after an object-term relationship is added.
	 *
	 * @param int      $post_id     Post ID.
	 * @param WP_Post  $post        Post object.
	 * @param bool     $update      Whether this is an existing post being updated.
	 * @param ?WP_Post $post_before Null for new posts, the WP_Post object prior to the update for updated posts.
	 */
	public static function on_wp_after_insert_post( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ) {
		// Ignore revisions.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		debug( 'on_wp_after_insert_post' );

		// Log the addition or removal of any taxonomy terms.
		if ( self::$terms ) {
			// Loop through the taxonomies and create a log entry for each.
			foreach ( self::$terms as $taxonomy => $term_changes ) {
				// Get some useful information.
				$terms_were_added   = empty( $term_changes['added'] ) ? 0 : count( $term_changes['added'] );
				$terms_were_removed = empty( $term_changes['removed'] ) ? 0 : count( $term_changes['removed'] );
				$total              = $terms_were_added + $terms_were_removed;

				// Get the taxonomy object and name.
				$taxonomy_obj  = get_taxonomy( $taxonomy );
				$taxonomy_name = $total === 1 ? $taxonomy_obj->labels->singular_name : $taxonomy_obj->labels->name;

				// Get event type verb
				if ( $terms_were_added && $terms_were_removed ) {
					$verb = 'Updated';
				} elseif ( $terms_were_added ) {
					$verb = 'Added';
				} elseif ( $terms_were_removed ) {
					$verb = 'Removed';
				} else {
					// This shouldn't occur.
					continue;
				}

				// Get the event type.
				$post_type_name = self::get_post_type_singular_name( $post->post_type );
				$event_type     = "$post_type_name $taxonomy_name $verb";

				// Collect eventmetas.
				$metas = array();

				// Show the added terms in the eventmetas.
				if ( $terms_were_added ) {
					$meta_key = 'added_' . strtolower( $taxonomy_name );
					Eventmeta::update_array( $metas, $meta_key, $term_changes['added'] );
				}

				// Show the removed terms in the eventmetas.
				if ( $terms_were_removed ) {
					$meta_key = 'removed_' . strtolower( $taxonomy_name );
					Eventmeta::update_array( $metas, $meta_key, $term_changes['removed'] );
				}

				// Log the event.
				Logger::log_event( $event_type, $post, $metas );
			}
		}
	}

	// =============================================================================================
	// Methods common to all object types.

	/**
	 * Check if a post exists.
	 *
	 * @param int $post_id The ID of the post.
	 * @return bool True if the post exists, false otherwise.
	 */
	public static function exists( int $post_id ): bool {
		global $wpdb;
		$sql   = $wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE ID = %d', $wpdb->posts, $post_id );
		$count = (int) $wpdb->get_var( $sql );
		return $count > 0;
	}

	/**
	 * Get a post by ID.
	 *
	 * @param int $post_id The ID of the post.
	 * @return ?WP_Post The post object or null if the post doesn't exist.
	 */
	public static function load( int $post_id ): ?WP_Post {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		return $post;
	}

	/**
	 * Get the core properties of a post.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return array The core properties of the post.
	 */
	public static function get_core_properties( WP_Post|int $post ): array {
		global $wpdb;

		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		// Define the core properties by key.
		$core_properties = array( 'ID', 'post_type', 'post_author', 'post_title', 'post_status', 'post_date', 'post_modified' );

		// Build the array of properties.
		$properties = array();
		foreach ( $core_properties as $key ) {

			// Get the value.
			switch ( $key ) {
				case 'post_author':
					$value = new Object_Reference( 'user', $post->post_author );
					break;

				case 'post_date':
					$value = self::get_created_datetime( $post );
					break;

				case 'post_modified':
					$value = self::get_last_modified_datetime( $post );
					break;

				default:
					// Process database values into correct types.
					$value = Types::process_database_value( $key, $post->{$key} );
					break;
			}

			// Construct the new Property object and add it to the properties array.
			Property::update_array( $properties, $key, $wpdb->posts, $value );
		}

		return $properties;
	}

	/**
	 * If the post hasn't been deleted, get a link to its edit page; otherwise, get a span with
	 * the old title as the link text.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @param string      $old_title The old title of the post.
	 * @param bool        $override Whether to override the title with the old title.
	 * @return string The link or span HTML tag.
	 */
	public static function get_tag( WP_Post|int $post, string $old_title, bool $override = false ): string {
		// Check if the post exists.
		if ( self::exists( $post ) ) {

			// Load the post if necessary.
			if ( is_int( $post ) ) {
				$post = self::load( $post );
			}

			// If the post is trashed, we can't reach its edit page, so instead we'll link to the list of trashed posts.
			if ( $post->post_status === 'trash' ) {
				$url = admin_url( 'edit.php?post_status=trash&post_type=post' );
			} else {
				$url = self::get_edit_url( $post );
			}

			// Get the link text.
			$text = ( $override && ! empty( $old_title ) ) ? $old_title : $post->post_title;

			return "<a href='$url' class='wp-logify-post-link'>$text</a>";
		}

		// The post no longer exists. Construct the 'deleted' span element.
		$post_id = is_int( $post ) ? $post : $post->ID;
		$text    = empty( $old_title ) ? "Post $post_id" : $old_title;
		return "<span class='wp-logify-deleted-object'>$text (deleted)</span>";
	}

	// =============================================================================================
	// Methods for getting information about posts.

	/**
	 * Get the URL for a post's edit page.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return string The edit page URL.
	 */
	public static function get_edit_url( WP_Post|int $post ) {
		$post_id = is_int( $post ) ? $post : $post->ID;
		return admin_url( "post.php?post=$post_id&action=edit" );
	}

	/**
	 * Get the URL for a revision comparison page.
	 *
	 * @param int $revision_id The ID of the revision.
	 * @return string The revision comparison page URL.
	 */
	public static function get_revision_url( int $revision_id ) {
		return admin_url( "revision.php?revision={$revision_id}" );
	}

	/**
	 * Get the HTML for the link to the object's edit page.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return string The link HTML tag.
	 */
	public static function get_edit_link( WP_Post|int $post ) {
		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		// Get the URL for the post's edit page.
		$url = self::get_edit_url( $post );

		// Return the link.
		return "<a href='$url' class='wp-logify-post-link'>$post->post_title</a>";
	}

	/**
	 * Get the HTML for a link to the revision comparison page.
	 *
	 * @param ?int $revision_id The ID of the revision.
	 * @return string The HTML of the link or span tag.
	 */
	public static function get_revision_tag( ?int $revision_id ) {
		// Handle the null case.
		if ( $revision_id === null ) {
			return '';
		}

		// Check if the revision exists.
		if ( self::exists( $revision_id ) ) {
			// Get the URL for the revision comparison page.
			$url = self::get_revision_url( $revision_id );

			// Construct the link.
			return "<a href='$url' class='wp-logify-post-link'>Compare revisions</a>";
		}

		// The revision no longer exists. Construct the 'deleted' span element.
		return "<span class='wp-logify-deleted-object'>(Revision deleted)</span>";
	}

	/**
	 * Get the singular name of a custom post type.
	 *
	 * @param string $post_type The post type.
	 * @return string The singular name of the post type.
	 */
	public static function get_post_type_singular_name( string $post_type ): string {
		// Get the post type object.
		$post_type_object = get_post_type_object( $post_type );

		// Return the singular name.
		return $post_type_object->labels->singular_name;
	}

	/**
	 * Get the datetime a post was created.
	 *
	 * This function ignores the post_date and post_date_gmt fields in the parent post record, which
	 * seem to show the last time the post was updated, not the time it was created.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return DateTime The datetime the post was created.
	 */
	public static function get_created_datetime( WP_Post|int $post ): DateTime {
		global $wpdb;

		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		// Construct the SQL.
		$sql = $wpdb->prepare(
			"SELECT MIN(post_date) FROM %i WHERE (ID = %d OR post_parent = %d) AND post_date != '0000-00-00 00:00:00'",
			$wpdb->posts,
			$post->ID,
			$post->ID
		);

		// Get the created datetime.
		$created_datetime = $wpdb->get_var( $sql );
		return DateTimes::create_datetime( $created_datetime );
	}

	/**
	 * Get the datetime a post was last modified.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return DateTime The datetime the post was last modified.
	 */
	public static function get_last_modified_datetime( WP_Post|int $post ): DateTime {
		global $wpdb;

		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		// Construct the SQL.
		$sql = $wpdb->prepare(
			"SELECT MAX(post_modified) FROM %i WHERE (ID = %d OR post_parent = %d) AND post_modified != '0000-00-00 00:00:00'",
			$wpdb->posts,
			$post->ID,
			$post->ID
		);

		// Get the last modified datetime.
		$last_modified_datetime = $wpdb->get_var( $sql );
		return DateTimes::create_datetime( $last_modified_datetime );
	}

	/**
	 * Get the properties of a post.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return array The properties of the post.
	 */
	public static function get_properties( WP_Post|int $post ): array {
		global $wpdb;

		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		$properties = array();

		// Add the base properties.
		foreach ( $post as $key => $value ) {
			// Skip the dates in the posts table, they're incorrect.
			if ( in_array( $key, array( 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ), true ) ) {
				continue;
			}

			// Process database values into correct types.
			$value = Types::process_database_value( $key, $value );

			// Construct the new Property object and add it to the properties array.
			Property::update_array( $properties, $key, $wpdb->posts, $value );
		}

		// Add the meta properties.
		$postmeta = get_post_meta( $post->ID );
		foreach ( $postmeta as $key => $value ) {
			// Process database values into correct types.
			$value = Types::process_database_value( $key, $value );

			// Construct the new Property object and add it to the properties array.
			Property::update_array( $properties, $key, $wpdb->postmeta, $value );
		}

		return $properties;
	}

	/**
	 * Get all terms attached to the specified post as object references.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return array The attached terms as an array of arrays of object references.
	 * @throws Exception If an error occurs.
	 */
	public static function get_attached_terms( WP_Post|int $post ): array {
		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = self::load( $post );
		}

		// Initialize the result.
		$term_refs = array();

		// Get all the relevant taxonomy names.
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			// Get the terms in this taxonomy that are attached to the post.
			$terms = get_the_terms( $post, $taxonomy );

			// Check for error.
			if ( $terms instanceof WP_Error ) {
				throw new Exception( "Error getting terms attached to post $post->ID." );
			}

			// If we got some terms, convert them to object references.
			if ( $terms ) {
				$term_refs[ $taxonomy ] = array();
				foreach ( $terms as $term ) {
					$term_refs[ $taxonomy ][] = new Object_Reference( 'term', $term->term_id, $term->name );
				}
			}
		}

		return $term_refs;
	}

	/**
	 * Get the verb for a post status transition.
	 *
	 * @param string $old_status The old post status.
	 * @param string $new_status The new post status.
	 * @return string The verb for the status transition.
	 */
	private static function get_status_transition_verb( string $old_status, string $new_status ) {
		// If transitioning out of trash, use a special verb.
		if ( $old_status === 'trash' ) {
			return 'Restored';
		}

		// Generate the event type verb from the new status.
		switch ( $new_status ) {
			case 'publish':
				return 'Published';

			case 'draft':
				return 'Drafted';

			case 'pending':
				return 'Pending';

			case 'private':
				return 'Privatized';

			case 'trash':
				return 'Trashed';

			case 'auto-draft':
				return 'Auto-drafted';

			case 'inherit':
				return 'Inherited';

			case 'future':
				return 'Scheduled';

			case 'request-pending':
				return 'Request Pending';

			case 'request-confirmed':
				return 'Request Confirmed';

			case 'request-failed':
				return 'Request Failed';

			case 'request-completed':
				return 'Request Completed';

			default:
				return 'Status Changed';
		}
	}
}
