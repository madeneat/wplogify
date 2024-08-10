<?php
/**
 * Contains the Post_Manager class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use DateTime;
use Exception;
use WP_Error;
use WP_Post;

/**
 * Class WP_Logify\Post_Manager
 *
 * Provides tracking of events related to posts.
 */
class Post_Manager extends Object_Manager {

	/**
	 * Check if a post exists.
	 *
	 * @param int|string $post_id The ID of the post.
	 * @return bool True if the post exists, false otherwise.
	 */
	public static function exists( int|string $post_id ): bool {
		global $wpdb;
		$sql   = $wpdb->prepare( 'SELECT COUNT(ID) FROM %i WHERE ID = %d', $wpdb->posts, $post_id );
		$count = (int) $wpdb->get_var( $sql );
		return $count > 0;
	}

	/**
	 * Get a post by ID.
	 *
	 * @param int|string $post_id The ID of the post.
	 * @return ?WP_Post The post object or null if not found.
	 */
	public static function load( int|string $post_id ): ?WP_Post {
		// Get the post.
		$post = get_post( $post_id );

		// Return the post or null if it doesn't exist.
		return $post ?? null;
	}

	/**
	 * Get the name of a post.
	 *
	 * @param int|string $object_key The ID of the post.
	 * @return ?string The name of the post or null if the post doesn't exist.
	 */
	public static function get_name( int|string $object_key ): ?string {
		// Load the post.
		$post = self::load( $object_key );

		// Return the post title or null if the post doesn't exist.
		return $post->post_title ?? null;
	}

	/**
	 * Get the core properties of a post.
	 *
	 * @param int|string $post_id The ID of the post.
	 * @return Property[] The core properties of the post.
	 * @throws Exception If the post doesn't exist.
	 */
	public static function get_core_properties( int|string $post_id ): array {
		global $wpdb;

		// Load the post.
		$post = self::load( $post_id );

		// Handle the case where the post doesn't exist.
		if ( ! $post ) {
			throw new Exception( "Post $post_id not found." );
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
	 * @param int|string $post_id   The ID of the post.
	 * @param ?string    $old_title The fallback title of the post if it's been deleted.
	 * @return string The link or span HTML tag.
	 */
	public static function get_tag( int|string $post_id, ?string $old_title ): string {
		// Load the post.
		$post = self::load( $post_id );

		// If the post exists, get a link.
		if ( $post ) {
			// If the post is trashed, we can't reach its edit page, so instead we'll link to the
			// list of trashed posts.
			if ( $post->post_status === 'trash' ) {
				$url = admin_url( 'edit.php?post_status=trash&post_type=post' );
			} else {
				$url = admin_url( "post.php?post=$post_id&action=edit" );
			}

			// Get the link text.
			return "<a href='$url' class='wp-logify-object'>$post->post_title</a>";
		}

		// The post no longer exists. Construct the 'deleted' span element.
		$title = $old_title ? $old_title : "Post $post_id";
		return "<span class='wp-logify-deleted-object'>$title (deleted)</span>";
	}

	// =============================================================================================
	// Methods for getting information about posts.

	/**
	 * Get the HTML for a link to the revision comparison page.
	 *
	 * @param int $revision_id The ID of the revision.
	 * @return string The HTML of the link or span tag.
	 */
	public static function get_revision_tag( int $revision_id ) {
		// Check if the revision exists.
		if ( self::exists( $revision_id ) ) {
			// Get a link to the revision comparison page.
			$url = admin_url( "revision.php?revision={$revision_id}" );
			return "<a href='$url' class='wp-logify-object'>Compare revisions</a>";
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
	public static function get_status_transition_verb( string $old_status, string $new_status ) {
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
