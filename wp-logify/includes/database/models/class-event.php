<?php
/**
 * Contains the Event class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use DateTime;
use Exception;

/**
 * This class represents a logged event.
 */
class Event {

	/**
	 * The ID of the event.
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * The date and time of the event in the site time zone, stored as a string.
	 *
	 * @var DateTime
	 */
	public DateTime $when_happened;

	/**
	 * The ID of the user who did the action.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * The name of the user who did the action.
	 *
	 * @var string
	 */
	public string $user_name;

	/**
	 * The role of the user.
	 *
	 * @var string
	 */
	public string $user_role;

	/**
	 * The IP address of the user.
	 *
	 * @var string
	 */
	public string $user_ip;

	/**
	 * The location of the user.
	 *
	 * @var ?string
	 */
	public ?string $user_location;

	/**
	 * The user agent string.
	 *
	 * @var ?string
	 */
	public ?string $user_agent;

	/**
	 * The type of the event, e.g. 'Post Created'.
	 *
	 * @var string
	 */
	public string $event_type;

	/**
	 * The type of object associated with the event, e.g. 'post', 'user', 'term'.
	 *
	 * @var ?string
	 */
	public ?string $object_type;

	/**
	 * The ID of the object associated with the event.
	 *
	 * This will be an integer matching a database primary key in the case of a post, user, term,
	 * etc., but will be null in the case of a theme or plugin, which are identified by their names.
	 *
	 * @var ?int
	 */
	public ?int $object_id;

	/**
	 * The name of the object associated with the event.
	 *
	 * For a theme or plugin, this serves as the unique identifier for the object.
	 * For other objects, this is the name of the object at the time of the event and will only be
	 * used if the object has been deleted.
	 *
	 * @var ?string
	 */
	public ?string $object_name;

	/**
	 * Properties relating to the event, including current values ans changes.
	 *
	 * @var ?array
	 */
	public ?array $properties;

	/**
	 * Metadata relating to the event.
	 *
	 * @var ?array
	 */
	public ?array $eventmetas;

	/**
	 * The object.
	 * This is not stored in the database.
	 *
	 * @var ?object
	 */
	private ?object $object;

	/**
	 * The object reference.
	 * This is not stored in the database.
	 *
	 * @var ?Object_Reference
	 */
	private ?Object_Reference $object_ref;

	// =============================================================================================
	/**
	 * Event Constructor.
	 *
	 * Initializes an empty Event object.
	 */
	public function __construct() {
		// Empty constructor.
	}

	// =============================================================================================
	// Methods for getting information about the object.

	/**
	 * Get the object associated with the event.
	 *
	 * @return ?object The object, or null if there is no object.
	 */
	public function get_object(): ?object {
		// Check if it's already been set.
		if ( isset( $this->object ) ) {
			return $this->object;
		}

		// Handle the case where the event has no object.
		if ( $this->object_type === null ) {
			return null;
		}

		// Get the object and remember it.
		$this->object = $this->get_object_ref()->get_object();

		return $this->object;
	}

	/**
	 * Get the Object_Reference for the object associated with the event.
	 *
	 * @return ?Object_Reference The Object_Reference for the object, or null if there is no object.
	 */
	public function get_object_ref(): ?Object_Reference {
		// Check if it's already been set.
		if ( isset( $this->object_ref ) ) {
			return $this->object_ref;
		}

		// Handle the case where the event has no object.
		if ( $this->object_type === null ) {
			return null;
		}

		// Construct the object reference and remember it.
		$this->object_ref = new Object_Reference( $this->object_type, $this->object_id, $this->object_name );

		return $this->object_ref;
	}

	/**
	 * Get the HTML for an object tag.
	 *
	 * @return string The object tag.
	 */
	public function get_object_tag(): string {
		// Make sure the object reference has been created.
		$object_ref = $this->get_object_ref();

		// Return the tag, or empty string if there is no object reference.
		return $object_ref === null ? '' : $object_ref->get_tag();
	}

	// =============================================================================================
	// Methods for getting and setting properties.

	/**
	 * Check if the event properties includes a property with the specified key.
	 *
	 * @param string $prop_key The property key.
	 * @return bool True if the property exists, false otherwise.
	 */
	public function has_prop( string $prop_key ) {
		return key_exists( $prop_key, $this->properties );
	}

	/**
	 * Get an event property.
	 *
	 * @param string $prop_key The property key.
	 * @return ?Property The property or null if not set.
	 */
	public function get_prop( string $prop_key ): ?Property {
		return empty( $this->properties[ $prop_key ] ) ? null : $this->properties[ $prop_key ];
	}

	/**
	 * Set an event property.
	 *
	 * @param string  $prop_key The property key.
	 * @param ?string $table_name The table name the property came from.
	 * @param mixed   $val The old or current value.
	 * @param mixed   $new_val The new value.
	 */
	public function set_prop( string $prop_key, ?string $table_name, mixed $val, mixed $new_val = null ) {
		// If the properties array is not set, create it.
		if ( ! isset( $this->properties ) ) {
			$this->properties = array();
		}

		// If the property with this key already exists, update it.
		if ( self::has_prop( $prop_key ) ) {
			$prop             = $this->properties[ $prop_key ];
			$prop->table_name = $table_name;
			$prop->val        = $val;
			$prop->new_val    = $new_val;
		} else {
			// The property with this key doesn't already exist in the properties array, so create it.
			$this->properties[ $prop_key ] = new Property( $prop_key, $table_name, $val, $new_val );
		}
	}

	/**
	 * Set multiple properties at once.
	 *
	 * @param array $props The properties to set.
	 */
	public function set_props( array $props ) {
		foreach ( $props as $prop ) {
			$this->set_prop( $prop->key, $prop->table_name, $prop->val, $prop->new_val );
		}
	}

	/**
	 * Add the object's core properties to the event properties.
	 */
	// public function set_core_props() {
	// $this->set_props( $this->get_object_reference()->get_core_properties() );
	// }

	/**
	 * Get the current or old value of a property.
	 *
	 * @param string $prop_key The property key.
	 * @return mixed The current or old value.
	 */
	public function get_prop_val( string $prop_key ): mixed {
		// Check if the property exists.
		if ( ! $this->has_prop( $prop_key ) ) {
			return null;
		}

		return $this->properties[ $prop_key ]->val;
	}

	/**
	 * Set the current or old value of a property.
	 *
	 * @param string $prop_key The property key.
	 * @param mixed  $val The current or old property value.
	 * @throws Exception If the property with the specified key is not found.
	 */
	public function set_prop_val( string $prop_key, mixed $val ) {
		// Check if the property exists.
		if ( ! $this->has_prop( $prop_key ) ) {
			throw new Exception( "Property with key $prop_key not found." );
		}

		$this->properties[ $prop_key ]->val = $val;
	}

	/**
	 * Get the new value of a property.
	 *
	 * @param string $prop_key The property key.
	 * @return mixed The new value of the property.
	 */
	public function get_prop_new_val( string $prop_key ): mixed {
		// Check if the property exists.
		if ( ! $this->has_prop( $prop_key ) ) {
			return null;
		}

		return $this->properties[ $prop_key ]->new_val;
	}

	/**
	 * Set the new value of a property.
	 *
	 * @param string $prop_key The property key.
	 * @param mixed  $new_val The new value of the property.
	 * @throws Exception If the property with the specified key is not found.
	 */
	public function set_prop_new_val( string $prop_key, mixed $new_val ) {
		// Check if the property exists.
		if ( ! $this->has_prop( $prop_key ) ) {
			throw new Exception( "Property with key $prop_key not found." );
		}

		$this->properties[ $prop_key ]->new_val = $new_val;
	}

	// =============================================================================================
	// Methods for getting and setting eventmetas.

	/**
	 * Check if the eventmetas includes one with the specified key.
	 *
	 * @param string $meta_key The eventmeta key.
	 * @return bool True if the eventmeta exists, false otherwise.
	 */
	public function has_meta( string $meta_key ) {
		return isset( $this->eventmetas ) && key_exists( $meta_key, $this->eventmetas );
	}

	/**
	 * Get an eventmeta.
	 *
	 * @param string $meta_key The eventmeta key.
	 * @return mixed The eventmeta object or null if not set.
	 */
	public function get_meta( string $meta_key ): mixed {
		return empty( $this->eventmetas[ $meta_key ] ) ? null : $this->eventmetas[ $meta_key ];
	}

	/**
	 * Get an eventmeta value.
	 *
	 * @param string $meta_key The eventmeta key.
	 * @return mixed The eventmeta value or null if not set.
	 */
	public function get_meta_val( string $meta_key ): mixed {
		$eventmeta = $this->get_meta( $meta_key );
		return $eventmeta ? $eventmeta->meta_value : null;
	}

	/**
	 * Set an eventmeta.
	 *
	 * @param Eventmeta $eventmeta The eventmeta.
	 */
	public function set_meta( Eventmeta $eventmeta ) {
		// Create the eventmeta array if necessary.
		if ( ! isset( $this->eventmetas ) ) {
			$this->eventmetas = array();
		}

		// Set the meta value.
		$this->eventmetas[ $eventmeta->meta_key ] = $eventmeta;
	}

	/**
	 * Set multiple eventmetas.
	 *
	 * @param array $metas The eventmetas to set.
	 */
	public function set_metas( array $metas ) {
		foreach ( $metas as $eventmeta ) {
			$this->set_meta( $eventmeta );
		}
	}

	/**
	 * Set an eventmeta data value.
	 *
	 * @param string $meta_key The eventmeta key.
	 * @param mixed  $meta_value The eventmeta value.
	 */
	public function set_meta_val( string $meta_key, mixed $meta_value ) {
		// Create the eventmeta array if necessary.
		if ( ! isset( $this->eventmetas ) ) {
			$this->eventmetas = array();
		}

		// Set the meta value.
		$this->eventmetas[ $meta_key ] = new Eventmeta( $this->id, $meta_key, $meta_value );
	}
}
