<?php
/*
Plugin Name: Gravity Forms Edit Entries
Plugin URI: https://github.com/jr00ck/gravity-forms-edit-entries
Description: Allows editing Gravity Forms entries on your site using shortcodes. Uses [gf-edit-entries] shortcode. Also provides a link to edit an entry using [gf-edit-entries-link] shortcode.
Version: 1.6
Author: FreeUp
Author URI: http://freeupwebstudio.com
Author Email: jeremy@freeupwebstudio.com
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
			$value = null; // needed?
			if( $field['type'] == 'name' ) { // currently only handles "normal" name format (more options: http://www.eugenoprea.com/code-snippets/gravity-forms-populate-name-field/)
				$value = array_filter( array( (strval($field['id'] . '.3') ) => $entry[$field['id'] . '.3'],( strval( $field['id'] . '.6') ) => $entry[$field['id'] . '.6'],) );
			} elseif ( $field['type'] == 'address' ) { // may only handle U.S. addresses for now
				$value = array_filter( array( 
					(strval($field['id'] . '.1') ) => $entry[$field['id'] . '.1'],
					(strval( $field['id'] . '.2') ) => $entry[$field['id'] . '.2'],
					(strval( $field['id'] . '.3') ) => $entry[$field['id'] . '.3'],
					(strval( $field['id'] . '.4') ) => $entry[$field['id'] . '.4'],
					(strval( $field['id'] . '.5') ) => $entry[$field['id'] . '.5'],
					(strval( $field['id'] . '.6') ) => $entry[$field['id'] . '.6']
					) );
			} elseif ( $field['type'] == 'checkbox' ) {
				// only pull the field values from the entry that match the form field we are evaluating
				$field_values = array();
				foreach ($entry as $key => $value) {
					$entry_key = explode('.', $key);
					if($entry_key[0] == $field['id']){
						$field_values[] = $value;
					}
				}

				foreach ( $field['choices'] as &$choice ) {
					$choice['isSelected'] = ( in_array($choice['value'], $field_values, true) ) ? true : '';
				}

			} else {
				$value = $entry[$field['id']];
			}
			
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
	            $preview .= sprintf("<div id='preview_file_%d'><input type='hidden' name='delete_file' /><a href='%s' target='_blank' alt='%s' title='%s'>%s</a><a href='%s' target='_blank' alt='" . __("Download file", "gravityforms") . "' title='" . __("Download file", "gravityforms") . "'><img src='%s' style='margin-left:10px;'/></a><a href='javascript:void(0);' alt='" . __("Delete file", "gravityforms") . "' title='" . __("Delete file", "gravityforms") . "' onclick='DeleteFile(%d, this);' ><img src='%s' style='margin-left:10px;'/></a></div>", $file_index, $file_url, $file_url, $file_url, GFCommon::truncate_url($file_url), $file_url, GFCommon::get_base_url() . "/images/download.png", $id, GFCommon::get_base_url() . "/images/delete.png");
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

	// save ID of temp_entry to delete it later
	$delete_entry_id = $tmp_entry['id'];
	// set entry ID to overwrite the original entry
	$tmp_entry['id'] = $orig_entry['id'];
	// initialize deletefiles variable
	$deletefiles = array();

	// take care of certain fields that need special handling
	foreach ($form['fields'] as $field) {
		// handle file uploads
		if($field['type'] == 'fileupload'){
			// haven't uploaded any new files, save any existing files to new entry before overwriting original entry (currently only supports one file per field)
			if(!$tmp_entry[$field['id']]){
				if($_POST['delete_file'] != $field['id']){
					$tmp_entry[$field['id']] = $orig_entry[$field['id']];
				}
			} else {
				// new file(s) uploaded, we need to copy the files because the originals will be deleted with the entry by Gravity Forms
				$file_path = get_file_path_from_gf_entry($tmp_entry[$field['id']]);
				$tmp_file_path = $file_path . '.tmp';
				copy($file_path, $tmp_file_path);
			}

			// if user has deleted this file upload, save to list to delete later
			if($_POST['delete_file'] == $field['id']){
				$delete_file_path = get_file_path_from_gf_entry($orig_entry[$field['id']]);
				$deletefiles[] = $delete_file_path;
			}
		} elseif($field['adminOnly']){
			// set adminOnly fields back to what they were before user updated non-admin fields
			$tmp_entry[$field['id']] = $orig_entry[$field['id']];
		}
	}
	// save entry details before performing update so we don't lose that data
	$tmp_entry = gfee_preserve_entry_details($orig_entry, $tmp_entry);

	// perform update entry with tmp_entry which overwrites the original entry
	$update_success = GFAPI::update_entry($tmp_entry);

	if($update_success === true){
		// delete temporary entry
		$delete_success = GFAPI::delete_entry($delete_entry_id);

		// delete any lingering files that shouldn't be around anymore
		foreach ($deletefiles as $filename) {
			if(file_exists($filename)){
				unlink($filename);
			}	
		}
		
		// original file(s) should be deleted by Gravity Forms or us, need to rename temp file back to original name
		if($tmp_file_path){
			rename($tmp_file_path, $file_path);
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
