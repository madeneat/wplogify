<?php
/**
 * Contains the Object_Reference class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use Exception;
use InvalidArgumentException;

/**
 * Represents a reference to an WordPress object that can be created, updated, or deleted.
 */
class Object_Reference implements Encodable {

	/**
	 * The type of the object, e.g. 'post', 'user', 'term'.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * The ID of the object.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The name of the object.
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * The object itself.
	 * This is a private field. To access publically, call getObject(), which will lazy-load the
	 * object as needed.
	 *
	 * @var mixed
	 */
	private mixed $object;

	/**
	 * Constructor.
	 *
	 * @param string      $type The type of the object.
	 * @param int|string  $id The ID of the object, which could be an integer or a string.
	 * @param string|bool $name The name of the object, or a bool to specify setting it automatically from the object.
	 *                          - If a string, the name will be assigned this value.
	 *                          - If true, the name will be extracted from the existing object.
	 *                          - If false, the name won't be set.
	 */
	public function __construct( string $type, int|string $id, string|bool $name = true ) {
		// Set the object type.
		$this->type = $type;

		// Set the object id.
		$this->id = $id;

		if ( is_string( $name ) ) {
			$this->name = $name;
		} elseif ( $name === true ) {
			$this->name = $this->get_name();
		}
	}

	/**
	 * Get the class name for the object's type.
	 */
	public function get_class(): string {
		return 'WP_Logify\\' . ucfirst( $this->type ) . 's';
	}

	/**
	 * Load an object.
	 *
	 * @throws Exception If the object cannot be loaded or if the object type is unknown.
	 */
	public function load() {
		// Check we know which object to load.
		if ( empty( $this->type ) || empty( $this->id ) ) {
			throw new Exception( 'Cannot load an object without knowing its type and ID.' );
		}

		// Call the appropriate get method.
		$method = array( self::get_class(), 'get_' . $this->type );
		return call_user_func( $method, $this->id );
	}

	/**
	 * Load the object it hasn't already been loaded.
	 *
	 * @return mixed The object.
	 */
	public function get_object() {
		if ( ! isset( $this->object ) ) {
			$this->load();
		}

		return $this->object;
	}

	/**
	 * Get the name or title of the object.
	 *
	 * @return string The name or title of the object.
	 * @throws Exception If the object type is unknown.
	 */
	public function get_name() {
		switch ( $this->type ) {
			case 'post':
				return $this->get_object()->title;

			case 'user':
				return Users::get_name( $this->id );

			case 'term':
				return $this->get_object()->name;

			default:
				throw new Exception( 'Unknown object type.' );
		}
	}

	// =============================================================================================
	// Encodable interface methods.

	/**
	 * Convert the object reference to a array suitable for encoding as JSON.
	 *
	 * @param object $obj The Object_Reference to convert.
	 * @return array The array representation of the Object_Reference.
	 * @throws InvalidArgumentException If the object is not an instance of Object_Reference.
	 */
	public static function encode( object $obj ): array {
		// Check the type.
		if ( ! $obj instanceof Object_Reference ) {
			throw new InvalidArgumentException( 'The object must be an instance of Object_Reference.' );
		}

		return array(
			'Object_Reference' => array(
				'type' => $obj->type,
				'id'   => $obj->id,
				'name' => $obj->name,
			),
		);
	}

	/**
	 * Check if the value expresses a valid Object_Reference.
	 *
	 * @param array   $ary The value to check.
	 * @param ?object $obj The Object_Reference object to populate if valid.
	 * @return bool If the JSON contains a valid date-time string.
	 */
	public static function can_decode( array $ary, ?object &$obj ): bool {
		// Check it looks right.
		if ( count( $ary ) !== 1 || empty( $ary['Object_Reference'] ) || ! is_array( $ary['Object_Reference'] ) ) {
			return false;
		}

		// Check the array of properties has the right number and type of values.
		$fields = $ary['Object_Reference'];
		if ( count( $fields ) !== 3
			|| ! key_exists( 'type', $fields ) || ! is_string( $fields['type'] )
			|| ! key_exists( 'id', $fields ) || ( ! is_int( $fields['id'] ) && ! is_string( $fields['id'] ) )
			|| ! key_exists( 'name', $fields ) || ! is_string( $fields['name'] )
		) {
			return false;
		}

		// Create the new object.
		$obj = new self( $fields['type'], $fields['id'], $fields['name'] );

		return true;
	}

	/**
	 * Gets the link or span element showing the object name.
	 *
	 * @return string The HTML for the link or span element.
	 * @throws Exception If the object type is invalid or the object ID is null.
	 */
	public function get_tag() {
		// Check for non-empty object type and ID.
		if ( empty( $this->type ) || empty( $this->id ) ) {
			throw new Exception( 'The object type and ID must both be set to generate a tag.' );
		}

		// Construct string to use in place of a link to the object if it's been deleted.
		// This is only here temporarily until I write the get_tag() methods for the Comments, Themes, and Plugins classes.
		$deleted_string = ( empty( $this->name ) ? ( ucfirst( $this->type ) . ' ' . $this->id ) : $this->name ) . ' (deleted)';

		// Generate the link based on the object type.
		switch ( $this->type ) {
			case 'post':
				// Return the post tag.
				return Posts::get_tag( $this->id, $this->name );

			case 'user':
				// Return the user tag.
				return Users::get_tag( $this->id, $this->name );

			case 'term':
				// Return the term tag.
				return Terms::get_tag( $this->id, $this->name );

			case 'comment':
				// Return the comment tag.
				return 'The Comment';
				// return Comments::get_tag( $this->id, $this->name );

			case 'theme':
				// Attempt to load the theme.
				$theme = wp_get_theme( $this->id );

				// Check if the theme was deleted.
				if ( ! $theme->exists() ) {
					return $deleted_string;
				}

				// Return a link to the theme.
				return "<a href='/wp-admin/theme-editor.php?theme={$theme->stylesheet}'>{$theme->name}</a>";

			case 'plugin':
				// Attempt to load the plugin.
				$plugins = get_plugins();

				// Check if the plugin was deleted.
				if ( ! array_key_exists( $this->id, $plugins ) ) {
					return $deleted_string;
				}

				// Link to the plugins page.
				return "<a href='/wp-admin/plugins.php'>{$plugins[$this->id]['Name']}</a>";
		}

		// If the object type is invalid, throw an exception.
		throw new Exception( "Invalid object type: $this->type" );
	}
}