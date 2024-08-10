<?php
/**
 * Contains the Object_Reference class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use Exception;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_Theme;
use WP_User;

/**
 * Represents a reference to an WordPress object that can be created, updated, or deleted.
 */
class Object_Reference {

	/**
	 * The type of the object, e.g. 'post', 'user', 'term', etc.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * The ID of the object.
	 *
	 * This will be an integer for object types with an integer ID, like posts, users, terms, and
	 * comments, and a string for those object types identified by a unique string value like a name
	 * or filename, such as option, plugin, and theme.
	 *
	 * @var null|int|string
	 */
	public null|int|string $key = null;

	/**
	 * The name of the object.
	 *
	 * @var ?string
	 */
	public ?string $name = null;

	/**
	 * The object itself.
	 * This is a private field. To access publically, call get_object(), which will lazy-load the
	 * object as needed.
	 *
	 * @var mixed
	 */
	private mixed $object = null;

	/**
	 * Constructor.
	 *
	 * @param string           $type The type of the object.
	 * @param null|int|string  $key  The object unique identified (int or string).
	 * @param null|string|bool $name The name of the object, or a bool to specify setting it
	 *                               automatically from the object.
	 *                               - If a string, the name will be assigned this value.
	 *                               - If true, the name will be extracted from the existing object.
	 *                               - If null or false, the name won't be set.
	 */
	public function __construct( string $type, null|int|string $key, null|string|bool $name = true ) {
		// Set the object type.
		$this->type = $type;

		// Set the object id.
		$this->key = $key;

		if ( is_string( $name ) ) {
			$this->name = $name;
		} elseif ( $name ) {
			$this->name = $this->get_name();
		}
	}

	/**
	 * Create a new Object_Reference from a WordPress object.
	 *
	 * @param object $wp_object The WordPress object.
	 * @return self The new Object_Reference.
	 * @throws Exception If the object type is unknown or unsupported.
	 */
	public static function new_from_wp_object( object $wp_object ): Object_Reference {
		$type = null;
		$key  = null;
		$name = null;

		if ( $wp_object instanceof WP_Post ) {
			$type = 'post';
			$key  = $wp_object->ID;
			$name = $wp_object->post_title;
		} elseif ( $wp_object instanceof WP_User ) {
			$type = 'user';
			$key  = $wp_object->ID;
			$name = User_Manager::get_name( $key );
		} elseif ( $wp_object instanceof WP_Term ) {
			$type = 'term';
			$key  = $wp_object->term_id;
			$name = $wp_object->name;
		} elseif ( $wp_object instanceof WP_Theme ) {
			$type = 'theme';
			$key  = null;
			$name = $wp_object->name;
		} elseif ( $wp_object instanceof WP_Comment ) {
			$type = 'comment';
			$key  = $wp_object->comment_ID;
			$name = Comment_Manager::get_name( $key );
		} else {
			throw new Exception( 'Unknown or unsupported object type.' );
		}

		return new Object_Reference( $type, $key, $name );
	}

	/**
	 * Load the object it hasn't already been loaded.
	 *
	 * @return mixed The object.
	 */
	public function get_object() {
		// If the object hasn't been loaded yet, load it.
		if ( ! isset( $this->object ) ) {
			$this->load();
		}

		// Return the object.
		return $this->object;
	}

	/**
	 * Get the name of the object manager class for this object, with the additional check that an
	 * object manager class exists for this object type.
	 *
	 * @return string The manager class name.
	 * @throws Exception If the object type is unknown.
	 */
	public function get_manager_class_name() {
		// Get the fully-qualified name of the manager class for this object type.
		$class = '\WP_Logify\\' . ucfirst( $this->type ) . '_Manager';

		// If the class doesn't exist, throw an exception.
		if ( ! class_exists( $class ) ) {
			throw new Exception( "Invalid object type: $this->type" );
		}

		return $class;
	}

	/**
	 * Call a method on the manager class for this object.
	 *
	 * @param string $method The method to call.
	 * @param array  ...$args The arguments to pass to the method.
	 * @return mixed The result of the method call.
	 */
	private function call_manager_method( string $method, ...$args ): mixed {
		// Get the name of the manager class.
		$manager_class = $this->get_manager_class_name();

		// Call the method on the manager class.
		return $manager_class::$method( $this->key, ...$args );
	}

	/**
	 * Check if the object exists.
	 *
	 * @return bool True if the object exists, false otherwise.
	 */
	private function exists(): bool {
		// Call the method on the manager class.
		return $this->call_manager_method( 'exists', $this->key );
	}

	/**
	 * Load the object.
	 *
	 * @return mixed The object or null if not found.
	 */
	private function load(): mixed {
		// Call the method on the manager class.
		return $this->call_manager_method( 'load', $this->key );
	}

	/**
	 * Get the name or title of the object.
	 *
	 * @return string The name or title of the object, or Unknown if not found.
	 */
	public function get_name() {
		// Call the method on the manager class.
		return $this->call_manager_method( 'get_name', $this->key );
	}

	/**
	 * Get the core properties of the object.
	 *
	 * @return array The core properties of the object.
	 * @throws Exception If the object type is unknown.
	 */
	public function get_core_properties(): ?array {
		// Call the method on the manager class.
		return $this->call_manager_method( 'get_core_properties', $this->key );
	}

	/**
	 * Gets the link or span element showing the object name.
	 *
	 * @return string The HTML for the link or span element.
	 * @throws Exception If the object type is invalid or the object ID is null.
	 */
	public function get_tag() {
		// Call the method on the manager class.
		return $this->call_manager_method( 'get_tag', $this->key, $this->name );
	}
}