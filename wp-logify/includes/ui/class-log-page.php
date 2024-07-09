<?php
/**
 * Contains the Log_Page class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use Exception;
use InvalidArgumentException;
use WP_Term;

/**
 * Class WP_Logify\Log_Page
 *
 * Contains methods for formatting event log entries for display in the admin area.
 */
class Log_Page {

	/**
	 * Initialise the log page.
	 */
	public static function init() {
		add_action( 'wp_ajax_wp_logify_fetch_logs', array( __CLASS__, 'fetch_logs' ) );
	}

	/**
	 * Display the log page.
	 */
	public static function display_log_page() {
		include plugin_dir_path( __FILE__ ) . '../templates/log-page.php';
	}

	/**
	 * Get the user's preference for the number of items to show per page.
	 *
	 * @return int The number of items to show per page.
	 */
	public static function get_items_per_page(): int {
		$page_length = (int) get_user_option( 'wp_logify_events_per_page', get_current_user_id() );
		return $page_length ? $page_length : 20;
	}

	/**
	 * Fetches logs from the database based on the provided search criteria.
	 */
	public static function fetch_logs() {
		global $wpdb;

		// Get table names.
		$events_table_name = Event_Repository::$table_name;
		$user_table_name   = $wpdb->prefix . 'users';

		// These should match the columns in admin.js.
		$columns = array(
			'ID',
			'date_time',
			'user',
			'user_ip',
			'event_type',
			'object',
		);

		// -----------------------------------------------------------------------------------------
		// Extract parameters from the request.

		// Get the number of items per page. We're using the screen options rather than the length
		// argument in the request.
		$page_length = self::get_items_per_page();

		// Get the start item index. Default to 0.
		$start = isset( $_POST['start'] ) ? (int) $_POST['start'] : 0;
		if ( $start < 0 ) {
			$start = 0;
		}

		// Get the order-by column. Default to date_time.
		$order_by_column = 'date_time';
		if ( isset( $_POST['order'][0]['column'] ) ) {
			$column_number = (int) $_POST['order'][0]['column'];
			if ( array_key_exists( $column_number, $columns ) ) {
				$order_by_column = $columns[ $column_number ];
			}
		}

		// Get the order-by direction. Check it's valid. Default to DESC.
		$order_by_direction = isset( $_POST['order'][0]['dir'] ) ? strtoupper( $_POST['order'][0]['dir'] ) : 'DESC';
		if ( ! in_array( $order_by_direction, array( 'ASC', 'DESC' ), true ) ) {
			$order_by_direction = 'DESC';
		}

		// Get the search value.
		$search_value = isset( $_POST['search']['value'] ) ? wp_unslash( $_POST['search']['value'] ) : '';

		// -----------------------------------------------------------------------------------------
		// Get the total number of events in the database.
		$total_sql         = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $events_table_name );
		$num_total_records = $wpdb->get_var( $total_sql );

		// -----------------------------------------------------------------------------------------
		// Get the number of filtered records.

		// Select clause.
		$select_count = 'SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON e.user_id = u.ID';

		// Where clause.
		if ( $search_value === '' ) {
			$where      = '';
			$where_args = array();
		} else {
			$like_value = '%' . $wpdb->esc_like( $search_value ) . '%';
			$where      =
				'WHERE date_time LIKE %s
                    OR user_role LIKE %s
                    OR user_ip LIKE %s
                    OR user_location LIKE %s
                    OR user_agent LIKE %s
                    OR event_type LIKE %s
                    OR event_details LIKE %s
                    OR object_type LIKE %s
                    OR object_name LIKE %s
                    OR object_details LIKE %s
                    OR user_login LIKE %s
                    OR user_email LIKE %s
                    OR display_name LIKE %s';
			$where_args = array(
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
			);
		}

		// Construct and run the SQL statement.
		$select_args          = array( $events_table_name, $user_table_name );
		$filtered_sql         = $wpdb->prepare( "$select_count $where", ...$select_args, ...$where_args );
		$num_filtered_records = $wpdb->get_var( $filtered_sql );

		// -----------------------------------------------------------------------------------------
		// Get the requested records.

		// Select clause.
		$select_columns = '
        SELECT
            e.ID,
            e.date_time,
            e.user_id,
            e.user_name,
            e.user_role,
            e.user_ip,
            e.user_location,
            e.user_agent,
            e.event_type,
            e.event_details,
            e.object_type,
            e.object_id,
            e.object_name,
            e.object_details,
            u.user_login,
            u.user_nicename,
            u.user_email,
            u.user_status,
            u.display_name
        FROM %i e LEFT JOIN %i u ON e.user_id = u.ID';

		// Order-by clause.
		$order_by = 'ORDER BY ';
		switch ( $order_by_column ) {
			case 'user':
				$order_by .= "display_name $order_by_direction";
				break;

			case 'object':
				$order_by .= "object_name $order_by_direction";
				break;

			default:
				$order_by .= "$order_by_column $order_by_direction";
				break;
		}

		// Limit clause.
		$limit      = 'LIMIT %d OFFSET %d';
		$limit_args = array( $page_length, $start );

		// Construct and run the SQL statement.
		$results_sql = $wpdb->prepare( "$select_columns $where $order_by $limit", ...$select_args, ...$where_args, ...$limit_args );
		$results     = $wpdb->get_results( $results_sql );

		// -----------------------------------------------------------------------------------------
		// Construct the data array to return to the client.
		$data = array();
		foreach ( $results as $row ) {
			// Construct the Event object.
			$event = Event_Repository::record_to_object( $row );

			// Create a new data item.
			$item       = array();
			$item['ID'] = $event->id;

			// Date and time of the event.
			$formatted_datetime = DateTimes::format_datetime_site( $event->date_time );
			$time_ago           = human_time_diff( ( $event->date_time )->getTimestamp() ) . ' ago';
			$item['date_time']  = "<div>$formatted_datetime ($time_ago)</div>";

			// User details.
			$user_ref     = new Object_Reference( 'user', $row->user_id, $row->user_name );
			$user_tag     = $user_ref->get_element();
			$user_role    = esc_html( ucwords( $event->user_role ) );
			$item['user'] = get_avatar( $event->user_id, 32 ) . " <div class='wp-logify-user-info'>$user_tag<br><span class='wp-logify-user-role'>$user_role</span></div>";

			// Source IP.
			$item['user_ip'] = '<a href="https://whatismyipaddress.com/ip/' . esc_html( $row->user_ip ) . '" target="_blank">' . esc_html( $row->user_ip ) . '</a>';

			// Event type.
			$item['event_type'] = $row->event_type;

			// Get the HTML for the object name element.
			$object_ref     = new Object_Reference( $event->object_type, $event->object_id, $event->object_name );
			$item['object'] = $object_ref->get_element();

			// Format the details.
			$item['details'] = self::format_details( $event );

			// Add the item to the data array.
			$data[] = $item;
		}

		wp_send_json(
			array(
				'draw'            => intval( $_POST['draw'] ),
				'recordsTotal'    => $num_total_records,
				'recordsFiltered' => $num_filtered_records,
				'data'            => $data,
			)
		);
	}

	/**
	 * Formats user details for a log entry.
	 *
	 * @param Event $event The event.
	 * @return string The formatted user details as an HTML table.
	 */
	public static function format_user_details( Event $event ): string {
		// Handle the case where the user ID is empty. Should never happen.
		if ( empty( $event->user_id ) ) {
			return '';
		}

		// User tag.
		// TODO replace with Users::get_tag() method so we don't need to create an Object_Reference, which we probably don't want or need.
		$user_ref = new Object_Reference( 'user', $event->user_id, $event->user_name );
		$user_tag = $user_ref->get_element();

		// User email.
		$user       = Users::get_user( $event->user_id );
		$user_email = empty( $user->user_email ) ? 'Unknown' : esc_html( $user->user_email );

		// Role.
		$user_role = esc_html( ucwords( $event->user_role ) );

		// Get the last login datetime.
		$last_login_datetime        = Users::get_last_login_datetime( $event->user_id );
		$last_login_datetime_string = $last_login_datetime !== null ? DateTimes::format_datetime_site( $last_login_datetime, true ) : 'Unknown';

		// Get the last active datetime.
		$last_active_datetime        = Users::get_last_active_datetime( $event->user_id );
		$last_active_datetime_string = $last_active_datetime !== null ? DateTimes::format_datetime_site( $last_active_datetime, true ) : 'Unknown';

		// User location.
		$user_location = empty( $event->user_location ) ? 'Unknown' : esc_html( $event->user_location );

		// User agent.
		$user_agent = empty( $event->user_agent ) ? 'Unknown' : esc_html( $event->user_agent );

		// Construct the HTML.
		$html  = "<div class='wp-logify-user-details wp-logify-details-section'>\n";
		$html .= "<h4>User Details</h4>\n";
		$html .= "<table class='wp-logify-user-details-table'>\n";
		$html .= "<tr><th>User</th><td>$user_tag</td></tr>\n";
		$html .= "<tr><th>Email</th><td><a href='mailto:$user_email'>$user_email</a></td></tr>\n";
		$html .= "<tr><th>Role</th><td>$user_role</td></tr>\n";
		$html .= "<tr><th>ID</th><td>$event->user_id</td></tr>";
		$html .= '<tr><th>IP address</th><td>' . ( $event->user_ip ?? 'Unknown' ) . "</td></tr>\n";
		$html .= "<tr><th>Last login</th><td>$last_login_datetime_string</td></tr>\n";
		$html .= "<tr><th>Last active</th><td>$last_active_datetime_string</td></tr>\n";
		$html .= "<tr><th>Location</th><td>$user_location</td></tr>\n";
		$html .= "<tr><th>User agent</th><td>$user_agent</td></tr>\n";
		$html .= "</table>\n";
		$html .= "</div>\n";

		return $html;
	}

	/**
	 * Formats the event details of a log entry.
	 *
	 * @param Event $event The event.
	 * @return string The formatted event details as an HTML table.
	 */
	public static function format_event_details( Event $event ): string {
		// Handle the null case.
		if ( empty( $event->details ) ) {
			return '';
		}

		// Convert event details to a table of key-value pairs.
		$html  = "<div class='wp-logify-event-details wp-logify-details-section'>\n";
		$html .= "<h4>Event Details</h4>\n";
		$html .= "<table class='wp-logify-event-details-table'>\n";
		foreach ( $event->details as $key => $value ) {
			$html .= "<tr><th>$key</th><td>$value</td></tr>";
		}
		$html .= "</table>\n";
		$html .= "</div>\n";
		return $html;
	}

	/**
	 * Format object properties.
	 *
	 * @param Event $event The event.
	 * @return string The object properties formatted as an HTML table.
	 */
	public static function format_object_properties( Event $event ): string {
		// Handle the null case.
		if ( empty( $event->properties ) ) {
			return '';
		}

		// Check if we need 2 columns or 3.
		$n_cols = 2;
		foreach ( $event->properties as $property ) {
			if ( $property->new_value !== null && $property->new_value !== '' ) {
				$n_cols = 3;
				break;
			}
		}

		// Convert JSON string to a table showing the changes.
		$html  = "<div class='wp-logify-change-details wp-logify-details-section'>\n";
		$html .= "<h4>Object Details</h4>\n";

		// Start table.
		$html .= "<table class='wp-logify-change-details-table'>\n";

		// Header row.
		$html .= '<tr><th>Property</th>';
		$html .= $n_cols == 2 ? '<th>Value</th>' : '<th>Before</th><th>After</th>';
		$html .= "</tr>\n";

		// Property rows.
		foreach ( $event->properties as $property ) {
			// Start row.
			$html .= '<tr>';

			// Property name.
			$readable_prop_name = self::make_key_readable( $property->prop_name, array( 'wp', $event->object_type ) );
			$html              .= "<th>$readable_prop_name</th>";

			// Old value.
			$old_value_string = $property->prop_name === 'user_pass' ? '(hidden)' : value_to_string( $property->old_value );
			$html            .= "<td>$old_value_string</td>";

			// New value.
			if ( $n_cols == 3 ) {
				$new_value_string = $property->prop_name === 'user_pass' && ! empty( $property->new_value ) ? '(hidden)' : value_to_string( $property->new_value );
				$html            .= "<td>$new_value_string</td>";
			}

			// End row.
			$html .= "</tr>\n";
		}

		// End table.
		$html .= "</table>\n";

		$html .= "</div>\n";

		return $html;
	}

	/**
	 * Make a key readable.
	 *
	 * This function takes a key and makes it more readable by converting it to title case and
	 * replacing underscores with spaces.
	 *
	 * @param string $key The key to make readable.
	 * @param ?array $prefixes_to_ignore An array of prefixes to ignore when making the key readable. Examples: 'wp', 'user', 'post'.
	 * @return string The readable key.
	 */
	public static function make_key_readable( string $key, ?array $prefixes_to_ignore = null ): string {
		// Special cases.
		switch ( $key ) {
			case 'user_pass':
				return 'Password';

			case 'show_admin_bar_front':
				return 'Show toolbar';

			case 'user registered':
				return 'Registered (UTC)';

			case 'post_date':
				return 'Created';

			case 'post_date_gmt':
				return 'Created (UTC)';

			case 'post_modified':
				return 'Last modified';

			case 'post_modified_gmt':
				return 'Last modified (UTC)';
		}

		// Split the key into words.
		$words = explode( '_', $key );

		// Remove any ignored prefix.
		if ( ! empty( $prefixes_to_ignore ) ) {
			while ( true ) {
				if ( count( $words ) > 1 && in_array( $words[0], $prefixes_to_ignore, true ) ) {
					$words = array_slice( $words, 1 );
				} else {
					break;
				}
			}
		}

		return ucfirst( implode( ' ', $words ) );
	}

	/**
	 * Formats the details of a log entry.
	 *
	 * @param Event $event
	 * @return string The formatted details as HTML.
	 */
	public static function format_details( Event $event ): string {
		$html  = "<div class='wp-logify-details'>\n";
		$html .= self::format_user_details( $event );
		$html .= self::format_event_details( $event );
		$html .= self::format_object_properties( $event );
		$html .= "</div>\n";
		return $html;
	}

	// /**
	// * Retrieves the link to an object based on its type and ID.
	// *
	// * NB: The ID will be an integer (as a string) for posts and users, and a string for themes and
	// * plugins.
	// *
	// * @param object $event The event object from the database.
	// * @return string The link to the object.
	// * @throws InvalidArgumentException If the object type is invalid or the object ID is null.
	// */
	// public static function get_object_link( object $event ) {
	// Handle the null case.
	// if ( empty( $event->object_type ) || empty( $event->object_id ) ) {
	// return '';
	// }

	// Check for valid object ID.
	// if ( $event->object_id === null ) {
	// throw new InvalidArgumentException( 'Object ID cannot be null . ' );
	// }

	// Construct string to use in place of a link to the object if it's been deleted.
	// $deleted_string = ( empty( $event->object_name )
	// ? ( ucfirst( $event->object_type ) . ' ' . $event->object_id )
	// : $event->object_name ) . ' (deleted)';

	// Generate the link based on the object type.
	// switch ( $event->object_type ) {
	// case 'post':
	// Attempt to load the post.
	// try {
	// $post = Posts::get_post( $event->object_id );
	// } catch ( Exception ) {
	// If we didn't get a post then we'll assume it was deleted.
	// return $deleted_string;
	// }

	// The desired URL will vary according to the post status.
	// if ( $post->post_status === 'trash' ) {
	// Go to the list of trashed posts.
	// $url = admin_url( 'edit.php?post_status=trash&post_type=post' );
	// } else {
	// Go to the post's edit page.
	// $url = Posts::get_edit_url( $event->object_id );
	// }

	// Construct the HTML for the tag.
	// return "<a href='$url'>{$post->post_title}</a>";

	// case 'user':
	// Return the user tag.
	// return Users::get_tag( $event->object_id, $event->object_name );

	// case 'term':
	// Attempt to load the term.
	// $term = get_term( $event->object_id );

	// Check if the term was deleted (although there could be some other issue).
	// if ( ! $term instanceof WP_Term ) {
	// return $deleted_string;
	// }

	// Return a link to the edit page for the term.
	// return Terms::get_edit_tag( $term );

	// case 'theme':
	// Attempt to load the theme.
	// $theme = wp_get_theme( $event->object_id );

	// Check if the theme was deleted.
	// if ( ! $theme->exists() ) {
	// return $deleted_string;
	// }

	// Return a link to the theme.
	// return "<a href='/wp-admin/theme-editor.php?theme={$theme->stylesheet}'>{$theme->name}</a>";

	// case 'plugin':
	// Attempt to load the plugin.
	// $plugins = get_plugins();

	// Check if the plugin was deleted.
	// if ( ! array_key_exists( $event->object_id, $plugins ) ) {
	// return $deleted_string;
	// }

	// Link to the plugins page.
	// return "<a href='/wp-admin/plugins.php'>{$plugins[$event->object_id]['Name']}</a>";
	// }

	// If the object type is invalid, throw an exception.
	// throw new InvalidArgumentException( "Invalid object type: $event->object_type" );
	// }
}
