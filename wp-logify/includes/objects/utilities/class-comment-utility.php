<?php
/**
 * Contains the Comment_Utility class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use Exception;
use WP_Comment;

/**
 * Class WP_Logify\Comment_Utility
 *
 * Provides tracking of events related to comments.
 */
class Comment_Utility extends Object_Utility {

	// =============================================================================================
	// Implementations of base class methods.

	/**
	 * Set up hooks for the events we want to log.
	 */
	public static function init() {
	}

	/**
	 * Check if a comment exists.
	 *
	 * @param int|string $comment_id The ID of the comment.
	 * @return bool True if the comment exists, false otherwise.
	 */
	public static function exists( int|string $comment_id ): bool {
		global $wpdb;
		$sql   = $wpdb->prepare( 'SELECT COUNT(comment_ID) FROM %i WHERE comment_ID = %d', $wpdb->comments, $comment_id );
		$count = (int) $wpdb->get_var( $sql );
		return $count > 0;
	}

	/**
	 * Get a comment by ID.
	 *
	 * @param int|string $comment_id The ID of the comment.
	 * @return ?WP_Comment The comment object or null if not found.
	 */
	public static function load( int|string $comment_id ): ?WP_Comment {
		// Load the comment.
		$comment = get_comment( $comment_id );

		// Return the comment or null if it doesn't exist.
		return $comment ?? null;
	}

	/**
	 * Generate a title for a comment from the comment's content.
	 *
	 * @param int|string $comment_id The ID of the comment.
	 * @return string The comment title generated from the comment content.
	 */
	public static function get_name( int|string $comment_id ): ?string {
		// Load the comment.
		$comment = self::load( $comment_id );

		// If the comment doesn't exist, return null.
		if ( ! $comment ) {
			return null;
		}

		// Specify a maximum title length.
		$max_title_length = 50;

		// Check the length.
		if ( strlen( $comment->comment_content ) <= $max_title_length ) {
			return $comment->comment_content;
		}

		// If the comment is too long, truncate it.
		return substr( $comment->comment_content, 0, $max_title_length - 3 ) . '...';
	}

	/**
	 * Get the core properties of a comment.
	 *
	 * @param int|string $comment_id The ID of the comment.
	 * @return array The core properties of the comment.
	 * @throws Exception If the comment no longer exists.
	 */
	public static function get_core_properties( int|string $comment_id ): array {
		global $wpdb;

		// Load the comment.
		$comment = self::load( $comment_id );

		// Handle the case where the comment no longer exists.
		if ( ! $comment ) {
			throw new Exception( "Comment $comment_id not found." );
		}

		// Build the array of properties.
		$properties = array();

		// ID.
		Property::update_array( $properties, 'ID', $wpdb->comments, $comment->comment_ID );

		// Comment author.
		$author = new Object_Reference( 'user', $comment->user_id );
		Property::update_array( $properties, 'Author', $wpdb->comments, $author );

		// Comment content.
		Property::update_array( $properties, 'Content', $wpdb->comments, $comment->comment_content );

		// Date.
		$date = Datetimes::create_datetime( $comment->comment_date );
		Property::update_array( $properties, 'Date', $wpdb->comments, $date );

		return $properties;
	}

	/**
	 * Get a comment tag.
	 *
	 * @param int|string $comment_id The ID of the comment.
	 * @param ?string    $old_title  The comment title at the time of the event.
	 * @return string The link or span HTML tag.
	 */
	public static function get_tag( int|string $comment_id, ?string $old_title ): string {
		// Load the comment.
		$comment = self::load( $comment_id );

		// If the comment exists, get a link.
		if ( $comment ) {
			// Get the comment name.
			$name = self::get_name( $comment_id );

			// Get the comment URL.
			$url = get_comment_link( $comment_id );

			// Return the link.
			return "<a href='$url' class='wp-logify-object'>$name</a>";
		}

		// Make a backup title.
		if ( ! $old_title ) {
			$old_title = "Comment $comment_id";
		}

		// The comment no longer exists. Construct the 'deleted' span element.
		return "<span class='wp-logify-deleted-object'>$old_title (deleted)</span>";
	}
}