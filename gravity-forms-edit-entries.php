<?php
/*
Plugin Name: Gravity Forms Edit Entries
Plugin URI: https://github.com/jr00ck/gravity-forms-edit-entries
Description: Allows editing Gravity Forms entries on your site using shortcodes. Uses [gf-edit-entries] shortcode. Also provides a link to edit an entry using [gf-edit-entries-link] shortcode.
Version: 1.9.1
Author: FreeUp
Author URI: http://freeupwebstudio.com
Author Email: jeremy@freeupwebstudio.com
GitHub Plugin URI: https://github.com/jr00ck/gravity-forms-edit-entries
*/

// Add the entry_id to the form as a hidden field. This triggers an update to an existing entry
add_filter( 'gform_form_tag', 'gfee_form_tag', 50, 2 );

// actions for when the form is submitted with an existing entry ID to edit
if(!empty($_POST['gfee_entry_id'])){

	// after entry is saved, use that new entry to update the initial entry we are trying edit, then delete this temporary entry
	add_action( 'gform_after_submission', 'gfee_after_submission', 10, 2 );

	// disable all notifications when editing an entry
	add_filter("gform_disable_notification", "gfee_disable_notification", 10, 4);
}

//shortcode [gf-edit-entries]
add_shortcode('gf-edit-entries', 'gf_edit_entries_shortcode');

function gf_edit_entries_shortcode( $params ) {

	extract( shortcode_atts( array(
                    'form_id'		=> 0,
                    'entry_id'		=> '',
                    'exclude_fields'=> null,
                    'error'			=> 'There was an error retrieving this entry. Please try again later.',
                    'key'			=> 0,
                    'value'			=> ''
                ), $params ) );

	// 
	if( is_numeric($_GET['entry_id']) || $entry_id ){
		GFEE::set_entry_id( $entry_id ? $entry_id : $_GET['entry_id'] );
		GFEE::get_entry_by_id(GFEE::$entry_id);
	} elseif ( $_GET['value'] || $value ) {
		GFEE::get_entry_by_value($form_id, $key, $value);
	}

	// if we have an existing entry at this point, we need to prepoluate the form values
	if(GFEE::$entry_id){
		// actions for when the form is loaded
		if(empty($_POST)){
			//populate fields
			add_filter("gform_pre_render", "gfee_pre_render");

			// populate file field
			add_filter( 'gform_field_content', 'gfee_field_content', 10, 5 );
		}
	}

	GFEE::set_exclude_fields($exclude_fields);
}


if (! class_exists('GFEE') ) :

class GFEE {

	public static $entry_id;
	public static $entry;
	public static $exclude_fields;

	public static function get_entry_by_value($form_id = 0, $key = 0, $value = null){

		if($value=="user_login") {
			global $current_user;
			get_currentuserinfo();
			$value = $current_user->user_login;
		}
		// setup search criteria
		$search_criteria['field_filters'][] = array( 'key' => $key, 'value' => $value );
		$search_criteria["status"] = "active";

		// run query
		$entry = GFAPI::get_entries($form_id, $search_criteria);
		self::set_entry($entry);
	}

	public static function get_entry_by_id($entry_id){

		$entry = GFAPI::get_entry($entry_id);
		self::set_entry($entry);

	}

	public static function set_entry($entry){
		self::$entry = $entry[0] ? $entry[0] : $entry;
		self::set_entry_id(self::$entry['id']);
	}
	
	public static function set_entry_id($entry_id){
		self::$entry_id = $entry_id;
	}

	public static function set_exclude_fields($exclude_fields){
		self::$exclude_fields = explode(',', $exclude_fields);
	}

}

function gfee_pre_render( $form ) {

	$entry 			= GFEE::$entry;
	$entry_id 		= GFEE::$entry_id;
	$exclude_fields = GFEE::$exclude_fields;

	if(!$entry)
		return $form;

	foreach ( $form['fields'] as &$field ) {
		if(in_array($field['id'], $exclude_fields)){

			$field['cssClass'] = 'gform_hidden';
			$field['isRequired'] = false;

		} else {

			$value = null;

			if( $field['type'] == 'checkbox' ) { // handle checkbox fields

				// only pull the field values from the entry that match the form field we are evaluating
				$field_values = array();
				
				foreach ($entry as $key => $value) {

					$entry_key = explode('.', $key);

					if($entry_key[0] == $field['id']){
						$field_values[] = $value;
					}
				}

				foreach ( $field->choices as &$choice ) {
					$choice['isSelected'] = ( in_array($choice['value'], $field_values, true) ) ? true : '';
				}

			} elseif ( is_array( $field->inputs ) ) { // handle other multi-input fields (address, name, time, etc.)
				
				// for time field, parse entry string to get individual parts of time string
				if( $field['type'] == 'time' ) {

					// separate time string from entry into individual parts
					list($HH, $time_end_part) = explode(':', $entry[strval($field['id'])]);
					list($MM, $AMPM) = explode(' ', $time_end_part);

					// save the time parts into individual array elements within the entry for our loop
					$entry[$field['id'] . '.1'] = $HH;
					$entry[$field['id'] . '.2'] = $MM;
					$entry[$field['id'] . '.3'] = $AMPM;
				}

				// loop each field input and set the default value from the entry
				foreach ($field->inputs as $key => &$input) {

					$input['defaultValue'] = $entry[strval($input['id'])];
				}

			} else { // handle remaining single input fields
				$value = $entry[$field['id']];
			}
			
			// if we have a value for the field from the provided entry, set the default value for the field
			if(!empty($value)) {
				$field['defaultValue'] = $value;
			}
		}
	}

	?>
	<script type="text/javascript">

		<?php // output entry values into json object for use for other purposes
		gfee_output_json($entry);
		?>
		
		function DeleteFile(fieldId, deleteButton){
		    if(confirm(<?php _e("'Would you like to delete this file? \'Cancel\' to stop. \'OK\' to delete'", "gravityforms"); ?>)){

		    	jQuery(deleteButton).parent().find('input[type=hidden]').val(fieldId);
		    	jQuery(deleteButton).parent().hide('slow');

	            return true;

		    }
		}

	</script>
	<?php 

	return $form;
}

function gfee_output_json($entry){

	echo 'var gfee_entry = ' . json_encode($entry);
}

// creates file upload field
function gfee_field_content($content, $field, $value, $lead_id, $form_id){

	if('fileupload' == $field['type']) {
		$id 			= $field['id'];
		$multiple_files	= $field['multipleFiles'];
		$file_list_id 	= "gform_preview_" . $form_id . "_". $id;

		$file_urls = $multiple_files ? json_decode($value) : array($value);
        $preview .= sprintf("<div id='preview_existing_files_%d' data-formid='%d' class='ginput_preview'>", $id, $form_id);

        if($file_urls){
	        foreach($file_urls as $file_index => $file_url){
	        	// remove url protocol?
	            $file_url = esc_attr($file_url);
	            $preview .= sprintf("<div id='preview_file_%d'><input type='hidden' name='delete_file_%d' /><a href='%s' target='_blank' alt='%s' title='%s'>%s</a><a href='%s' target='_blank' alt='" . __("Download file", "gravityforms") . "' title='" . __("Download file", "gravityforms") . "'><img src='%s' style='margin-left:10px;'/></a><a href='javascript:void(0);' alt='" . __("Delete file", "gravityforms") . "' title='" . __("Delete file", "gravityforms") . "' onclick='DeleteFile(%d, this);' ><img src='%s' style='margin-left:10px;'/></a></div>", $file_index, $id, $file_url, $file_url, $file_url, GFCommon::truncate_url($file_url), $file_url, GFCommon::get_base_url() . "/images/download.png", $id, GFCommon::get_base_url() . "/images/delete.png");
	        }

	        $preview .="</div>";

			return $content . $preview;
		}
	}

	return $content;
}

function gfee_after_submission( $tmp_entry, $form ) {

	// get the original entry we want to edit/update
	GFEE::set_entry_id($_POST['gfee_entry_id']); // NEED TO BEEF UP SECURITY HERE AS THIS VALUE CAN BE MODIFIED BY USER TO EDIT SOMEONE ELSE'S ENTRY
	$orig_entry_id = GFEE::$entry_id;
	$orig_entry = GFAPI::get_entry($orig_entry_id);
	
	// initialize deletefiles variable
	$deletefiles = array();

	// take care of certain fields that need special handling
	foreach ($form['fields'] as $field) {
		// don't touch admin-only fields since front-end can't modify those
		if(!$field['adminOnly']){
			
			// handle file uploads
			if($field['type'] == 'fileupload'){

				// if user has deleted this file upload, save to list to delete later
				if($_POST['delete_file_' . $field['id']] == $field['id']){

					$delete_file_path = get_file_path_from_gf_entry($orig_entry[$field['id']]);
					$deletefiles[] = $delete_file_path;
					// save new file upload field data
					$orig_entry[$field['id']] = $tmp_entry[$field['id']];
				}

				// this currently only supports one file per field
				if($tmp_entry[$field['id']]){

					// new file(s) uploaded, we need to copy the files because the originals will be deleted with the temp entry by Gravity Forms
					$file_path = get_file_path_from_gf_entry($tmp_entry[$field['id']]);
					$tmp_file_path = $file_path . '.tmp';
					copy($file_path, $tmp_file_path);
					$temp_files[] = $tmp_file_path;

					// save new file upload field data
					$orig_entry[$field['id']] = $tmp_entry[$field['id']];
				}

			// handle checkboxes, address, and name fields
			} elseif( $field['type'] == 'checkbox' || $field['type'] == 'address' || $field['type'] == 'name' ) {

				foreach ($field->inputs as $key => $input) {
					// loop each field input and save it
					$orig_entry[strval($input['id'])] = $tmp_entry[strval($input['id'])];
				}				

			} else {
				// save updated field data to original entry
				$orig_entry[$field['id']] = $tmp_entry[$field['id']];
			}
		}
	}

	// perform update entry with original entry data fields updated
	$update_success = GFAPI::update_entry($orig_entry);

	if($update_success === true){

		// delete temporary entry
		$delete_success = GFAPI::delete_entry($tmp_entry['id']);

		// delete any lingering files that shouldn't be around anymore
		foreach ($deletefiles as $filename) {
			if(file_exists($filename)){
				unlink($filename);
			}	
		}

		// original file(s) should be deleted by Gravity Forms or us, need to rename temp file back to original name
		if(!empty($temp_files)){

			foreach ($temp_files as $temp_file_path) {
				// remove ".tmp" extension, if present
				$reverted_file_path = preg_replace('/\.tmp$/', '', $temp_file_path);

				// rename file
				rename($temp_file_path, $reverted_file_path);
			}
		}
	}
}

// preserves entry details so they are not lost
function gfee_preserve_entry_details($orig_entry, $tmp_entry){
	// all non-numeric data keys will be preserved (all fields have numeric keys)
	foreach ($orig_entry as $key => $value) {
		if(!is_numeric($key)){
			$tmp_entry[$key] = $value;
		}
	}

	return $tmp_entry;
}

// disables all notifications when in edit mode
function gfee_disable_notification($is_disabled, $notification, $form, $entry){

    return true;
}

function gfee_form_tag( $form_tag, $form ) {
	$form_tag .= '<input type="hidden" name="gfee_entry_id" value="' . GFEE::$entry_id . '" class="gform_hidden" />';
	return $form_tag;
}

// given a file path formatted from a gravity forms entry, returns a file path suitable to use in PHP
function get_file_path_from_gf_entry($gf_file_path){
	$file_url = stripslashes($gf_file_path);
	$wp_upload_dir = wp_upload_dir();
	$file_gf_dir = explode('uploads', $file_url);
	$file_path = $wp_upload_dir['basedir'] . rtrim($file_gf_dir[1], '"]');
	
	return $file_path;
}

endif;
