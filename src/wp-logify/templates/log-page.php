<?php
/**
 * Log page template.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

?>

<div class="wrap">
	<h1>WP Logify Events Log</h1>

	<div id="wp-logify-object-type-filter">

		<span>Filter events by object type:</span>

		<div class='wp-logify-show-object-type-events wp-logify-object-type-all'>
			<input type='checkbox' id='wp-logify-show-all-events' value='all' checked='checked'>
			<label for='wp-logify-show-all-events'>All</label>
		</div>

		<div id="wp-logify-object-type-checkboxes">
			<?php
			foreach ( Logger::VALID_OBJECT_TYPES as $object_type ) {
				$object_type_display = $object_type === 'option' ? 'Setting' : ucfirst( $object_type );
				echo "<div class='wp-logify-show-object-type-events wp-logify-object-type-$object_type'>\n";
				echo "<input type='checkbox' id='wp-logify-show-$object_type-events' value='$object_type' checked='checked'>\n";
				echo "<label for='wp-logify-show-$object_type-events'>$object_type_display</label>\n";
				echo "</div>\n";
			}
			?>
		</div>

	</div>

	<table id="wp-logify-activity-log" class="widefat fixed table-wp-logify" cellspacing="0">
		<thead>
			<tr>
				<th class="column-id">ID</th>
				<th>Date</th>
				<th>User</th>
				<th>Source IP</th>
				<th>Event</th>
				<th>Object</th>
				<!-- <th>Details</th> -->
			</tr>
		</thead>
		<tbody>
			<!-- Data will be loaded via AJAX -->
		</tbody>
	</table>
</div>
