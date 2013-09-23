<?php
/*
Script Name: 	Custom Metaboxes and Fields
Contributors: 	Andrew Norcross (@norcross / andrewnorcross.com)
				Jared Atchison (@jaredatch / jaredatchison.com)
				Bill Erickson (@billerickson / billerickson.net)
				Justin Sternberg (@jtsternberg / dsgnwrks.pro)
Description: 	This will create metaboxes with custom fields that will blow your mind.
Version: 		0.9.5
*/

/**
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * **********************************************************************
 */

/************************************************************************
		You should not edit the code below or things might explode!
*************************************************************************/

// Autoload helper classes
spl_autoload_register('cmb_Meta_Box::autoload_helpers');

// for PHP versions < 5.3
if ( !defined( '__DIR__' ) ) {
	define( '__DIR__', dirname( __FILE__ ) );
}


$meta_boxes = array();
$meta_boxes = apply_filters( 'cmb_meta_boxes' , $meta_boxes );
foreach ( $meta_boxes as $meta_box ) {
	$my_box = new cmb_Meta_Box( $meta_box );
}

/**
 * Validate value of meta fields
 * Define ALL validation methods inside this class and use the names of these
 * methods in the definition of meta boxes (key 'validate_func' of each field)
 */
class cmb_Meta_Box_Validate {
	function check_text( $text ) {
		if ($text != 'hello') {
			return false;
		}
		return true;
	}
}

/**
 * Defines the url to which is used to load local resources.
 * This may need to be filtered for local Window installations.
 * If resources do not load, please check the wiki for details.
 */
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
       //winblows
    define( 'CMB_META_BOX_URL', trailingslashit( str_replace( DIRECTORY_SEPARATOR, '/', str_replace( str_replace( '/', DIRECTORY_SEPARATOR, WP_CONTENT_DIR ), WP_CONTENT_URL, dirname(__FILE__) ) ) ) );

} else {
  define('CMB_META_BOX_URL', apply_filters('cmb_meta_box_url',
    trailingslashit(str_replace(
      array(WP_CONTENT_DIR, WP_PLUGIN_DIR),
      array(WP_CONTENT_URL, WP_PLUGIN_URL),
      dirname( __FILE__ )
    ))
  ));
}

/**
 * Create meta boxes
 */
class cmb_Meta_Box {
	protected        $_meta_box;
	protected        $form_id     = 'post';
	protected static $field       = array();
	public    static $object_id   = 0;
	// Type of object being saved. (e.g., post, user, or comment)
	protected static $object_type = 'post';

	/**
	 * Get started
	 */
	function __construct( $meta_box ) {

		$allow_frontend = apply_filters( 'cmb_allow_frontend', true, $meta_box );

		if ( ! is_admin() && ! $allow_frontend )
			return;

		$this->_meta_box = $meta_box;

		self::set_object_type( $meta_box );

		$types = wp_list_pluck( $meta_box['fields'], 'type' );
		$upload = in_array( 'file', $types ) || in_array( 'file_list', $types );

		global $pagenow;

		// register our scripts and styles for cmb
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ), 8 );

		if ( self::get_object_type() == 'post' ) {
			add_action( 'admin_menu', array( $this, 'add_metaboxes' ) );
			add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'do_scripts' ) );

			$show_filters = 'cmb_Meta_Box_Show_Filters';
			foreach ( get_class_methods( $show_filters ) as $filter ) {
				add_filter( 'cmb_show_on', array( $show_filters, $filter ), 10, 2 );
			}

			if ( $upload && in_array( $pagenow, array( 'page.php', 'page-new.php', 'post.php', 'post-new.php' ) ) ) {
				add_action( 'admin_head', array( $this, 'add_post_enctype' ) );
			}

		}
		if ( self::get_object_type() == 'user' ) {

			$priority = 10;
			if ( isset( $meta_box['priority'] ) ) {
				if ( is_numeric( $meta_box['priority'] ) )
					$priority = $meta_box['priority'];
				elseif ( $meta_box['priority'] == 'high' )
					$priority = 5;
				elseif ( $meta_box['priority'] == 'low' )
					$priority = 20;
			}
			add_action( 'show_user_profile', array( $this, 'user_metabox' ), $priority );
			add_action( 'edit_user_profile', array( $this, 'user_metabox' ), $priority );

			add_action( 'personal_options_update', array( $this, 'save_user' ) );
			add_action( 'edit_user_profile_update', array( $this, 'save_user' ) );
			if ( $upload && in_array( $pagenow, array( 'profile.php', 'user-edit.php' ) ) ) {
				$this->form_id = 'your-profile';
				add_action( 'admin_head', array( $this, 'add_post_enctype' ) );
			}
		}

	}

	/**
	 * Autoloads files with classes when needed
	 * @since  0.9.5
	 * @param  string $class_name Name of the class being requested
	 */
	public static function autoload_helpers( $class_name ) {
		if ( class_exists( $class_name, false ) )
			return;

		$file = __DIR__ .'/helpers/'. $class_name .'.php';
		if ( file_exists( $file ) )
			@include( $file );
	}

	/**
	 * Registers scripts and styles for CMB
	 * @since  0.9.5
	 */
	function register_scripts() {

		global $wp_version, $user_ID, $post;
		// scripts required for cmb
		$scripts = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker', 'media-upload', 'cmb-timepicker' );
		// styles required for cmb
		$styles = array();

		// if we're 3.5 or later, user wp-color-picker
		if ( 3.5 <= $wp_version ) {
			$scripts[] = 'wp-color-picker';
			$styles[] = 'wp-color-picker';
		} else {
			// otherwise use the older 'farbtastic'
			$scripts[] = 'farbtastic';
			$styles[] = 'farbtastic';
		}
		wp_register_script( 'cmb-timepicker', CMB_META_BOX_URL . 'js/jquery.timePicker.min.js' );
		wp_register_script( 'cmb-scripts', CMB_META_BOX_URL . 'js/cmb.js', $scripts, '0.9.5' );
		wp_enqueue_media();

		if ( self::$object_id )
			$object_id = self::$object_id;
		elseif ( self::get_object_type() == 'user' && ! empty( $user_ID ) )
			$object_id = $user_ID;
		elseif ( self::get_object_type() == 'post' && ! empty( $post->ID ) )
			$object_id = $post->ID;

		// reset to id or 0
		self::$object_id = $object_id ? $object_id : 0;

		wp_localize_script( 'cmb-scripts', 'cmb_l10', array(
			'ajax_nonce'   => wp_create_nonce( 'ajax_nonce' ),
			'object_id'    => self::$object_id,
			'object_type'  => self::get_object_type(),
			'upload_file'  => 'Use this file',
			'remove_image' => 'Remove Image',
			'remove_file'  => 'Remove',
			'file'         => 'File:',
			'download'     => 'Download',
		) );

		wp_register_style( 'cmb-styles', CMB_META_BOX_URL . 'style.css', $styles );
	}

	/**
	 * Enqueues scripts and styles for CMB
	 * @since  0.9.5
	 */
	function do_scripts( $hook ) {
		// only enqueue our scripts/styles on the proper pages
		if ( $hook == 'post.php' || $hook == 'post-new.php' || $hook == 'page-new.php' || $hook == 'page.php' ) {
			wp_enqueue_script( 'cmb-scripts' );

			// default is to show cmb styles on post pages
			if ( ! isset( $this->_meta_box['cmb_styles'] ) || $this->_meta_box['cmb_styles'] != false )
				wp_enqueue_style( 'cmb-styles' );
		}
	}

	/**
	 * Add encoding attribute
	 */
	function add_post_enctype() {
		echo '
		<script type="text/javascript">
		jQuery(document).ready(function(){
			jQuery("#'. $this->form_id .'").attr("enctype", "multipart/form-data");
			jQuery("#'. $this->form_id .'").attr("encoding", "multipart/form-data");
		});
		</script>';
	}

	/**
	 * Add metaboxes (to 'post' object type)
	 */
	function add_metaboxes() {
		$this->_meta_box['context']  = empty( $this->_meta_box['context'] ) ? 'normal' : $this->_meta_box['context'];
		$this->_meta_box['priority'] = empty( $this->_meta_box['priority'] ) ? 'high' : $this->_meta_box['priority'];
		$this->_meta_box['show_on']  = empty( $this->_meta_box['show_on'] ) ? array( 'key' => false, 'value' => false ) : $this->_meta_box['show_on'];

		foreach ( $this->_meta_box['pages'] as $page ) {
			if ( apply_filters( 'cmb_show_on', true, $this->_meta_box ) )
				add_meta_box( $this->_meta_box['id'], $this->_meta_box['title'], array( $this, 'post_metabox' ), $page, $this->_meta_box['context'], $this->_meta_box['priority']) ;
		}
	}

	/**
	 * Display metaboxes for a post object
	 * @since  0.9.5
	 */
	function post_metabox() {
		if ( ! $this->_meta_box )
			return;

		self::show_form( $this->_meta_box, get_the_ID(), 'post' );

	}

	/**
	 * Display metaboxes for a user object
	 * @since  0.9.5
	 */
	function user_metabox() {
		if ( ! $this->_meta_box )
			return;

		if ( 'user' != self::set_object_type( $this->_meta_box ) )
			return;

		global $user_ID;

		wp_enqueue_script( 'cmb-scripts' );

		// default is to NOT show cmb styles on user profile page
		if ( isset( $this->_meta_box['cmb_styles'] ) && $this->_meta_box['cmb_styles'] == true  )
			wp_enqueue_style( 'cmb-styles' );

		self::show_form( $this->_meta_box, $user_ID, 'user' );

	}

	/**
	 * Display fields
	 */
	public static function show_form( $_meta_box, $object_id, $object_type = 'post' ) {

		self::$object_id =& $object_id;
		self::$object_type =& $object_type;

		// get box types
		$types = cmb_Meta_Box_types::get();

		// Use nonce for verification
		echo "\n<!-- Begin CMB Fields -->\n";
		wp_nonce_field( self::nonce(), 'wp_meta_box_nonce', false, true );
		do_action( 'cmb_before_table', $_meta_box, $object_id, $object_type );
		echo '<table class="form-table cmb_metabox">';

		foreach ( $_meta_box['fields'] as $field ) {

			if ( isset( $field['on_front'] ) && $field['on_front'] == false )
				continue;

			self::$field =& $field;

			// Set up blank or default values for empty ones
			if ( !isset( $field['name'] ) ) $field['name'] = '';
			if ( !isset( $field['desc'] ) ) $field['desc'] = '';
			if ( !isset( $field['std'] ) )  $field['std'] = '';
			// filter default value
			$field['std'] = apply_filters( 'cmb_std_filter', $field['std'], $field, $object_id, $object_type );
			if ( 'file' == $field['type'] && !isset( $field['allow'] ) )
				$field['allow'] = array( 'url', 'attachment' );
			if ( 'file' == $field['type'] && !isset( $field['save_id'] ) )
				$field['save_id'] = false;
			if ( 'multicheck' == $field['type'] )
				$field['multiple'] = true;

			// Allow an override for the field's value
			// (assuming no one would want to save 'cmb_override_val' as a value)
			$meta = apply_filters( 'cmb_override_meta_value', 'cmb_override_val', $object_id, $field, $object_type );

			// If no override, get our meta
			if ( $meta === 'cmb_override_val' )
				$meta = get_metadata( $object_type, $object_id, $field['id'], 'multicheck' != $field['type'] /* If multicheck this can be multiple values */ );


			echo '<tr class="cmb-type-'. sanitize_html_class( $field['type'] ) .' cmb_id_'. sanitize_html_class( $field['id'] ) .'">';

			if ( $field['type'] == "title" ) {
				echo '<td colspan="2">';
			} else {
				if( $_meta_box['show_names'] == true ) {
					$style = $object_type == 'post' ? ' style="width:18%"' : '';
					echo '<th'. $style .'><label for="', $field['id'], '">', $field['name'], '</label></th>';
				} else {
					echo '<label style="display:none;" for="', $field['id'], '">', $field['name'], '</label></th>';
				}
				echo '<td>';
			}

			echo empty( $field['before'] ) ? '' : $field['before'];

			call_user_func( array( $types, $field['type'] ), $field, $meta, $object_id, $object_type );

			echo empty( $field['after'] ) ? '' : $field['after'];

			echo '</td>','</tr>';
		}
		echo '</table>';
		do_action( 'cmb_after_table', $_meta_box, $object_id, $object_type );
		echo "\n<!-- End CMB Fields -->\n";

	}

	/**
	 * Save data from metabox
	 */
	function save_post( $post_id, $post )  {

		// check permissions
		if (
			// check nonce
			! isset( $_POST['wp_meta_box_nonce'] )
			|| ! wp_verify_nonce( $_POST['wp_meta_box_nonce'], self::nonce() )
			// check if autosave
			|| defined('DOING_AUTOSAVE' ) && DOING_AUTOSAVE
			// check user editing permissions
			|| ( 'page' == $_POST['post_type'] && ! current_user_can( 'edit_page', $post_id ) )
			|| ! current_user_can( 'edit_post', $post_id )
			// get the metabox post_types & compare it to this post_type
			|| ! in_array( $post->post_type, $this->_meta_box['pages'] )
		)
			return $post_id;

		self::save_fields( $this->_meta_box['fields'], $post_id );
	}

	/**
	 * Save data from metabox
	 */
	function save_user( $user_id )  {

		// check permissions
		// @todo more hardening?
		if (
			// check nonce
			! isset( $_POST['wp_meta_box_nonce'] )
			|| ! wp_verify_nonce( $_POST['wp_meta_box_nonce'], self::nonce() )
		)
			return $user_id;

		self::save_fields( $this->_meta_box['fields'], $user_id, 'user' );
	}

	/**
	 * Loops through and saves field data
	 * @since  0.9.5
	 * @param  array  $fields      Array of fields to save
	 * @param  int    $object_id   Object ID
	 * @param  string $object_type Type of object being saved. (e.g., post, user, or comment)
	 */
	public static function save_fields( $fields, $object_id, $object_type = 'post' ) {

		self::$object_id =& $object_id;
		self::$object_type =& $object_type;

		foreach ( $fields as $field ) {

			self::$field =& $field;

			$name = $field['id'];

			if ( ! isset( $field['multiple'] ) )
				$field['multiple'] = ( 'multicheck' == $field['type'] ) ? true : false;

			$old = get_metadata( $object_type, $object_id, $name, !$field['multiple'] /* If multicheck this can be multiple values */ );
			$new = isset( $_POST[$field['id']] ) ? $_POST[$field['id']] : null;

			if ( $object_type == 'post' ) {

				if (
					isset( $field['taxonomy'] )
					&& in_array( $field['type'], array( 'taxonomy_select', 'taxonomy_radio', 'taxonomy_multicheck' ) )
				)
					$new = wp_set_object_terms( $object_id, $new, $field['taxonomy'] );

			}

			switch ( $field['type'] ) {
				case 'text_datetime_timestamp':
					$new = strtotime( $new['date'] .' '. $new['time'] );

					if ( $tz_offset = self::field_timezone_offset( $object_id ) )
						$new += $tz_offset;
					break;
				case 'text_datetime_timestamp_timezone':
					$tzstring = null;

					if ( array_key_exists( 'timezone', $new ) )
						$tzstring = $new['timezone'];

					if ( empty( $tzstring ) )
						$tzstring = self::timezone_string();

					$offset = self::timezone_offset( $tzstring );

					if ( substr( $tzstring, 0, 3 ) === 'UTC' )
						$tzstring = timezone_name_from_abbr( "", $offset, 0 );

					$new = new DateTime( $new['date'] .' '. $new['time'], new DateTimeZone( $tzstring ) );
					$new = serialize( $new );

					break;
				case 'text_url':
					if ( ! empty( $new ) ) {
						$protocols = isset( $field['protocols'] ) ? (array) $field['protocols'] : null;
						$new = esc_url_raw( $new, $protocols );
					}
					break;
				case 'textarea':
				case 'textarea_small':
					$new = htmlspecialchars( $new );
					break;
				case 'textarea_code':
					$new = htmlspecialchars_decode( $new );
					break;
				case 'text_email':
					$new = trim( $new );
					$new = is_email( $new ) ? $new : '';
					break;
				case 'text_date_timestamp':
					$new = strtotime( $new );
					break;
				case 'file':
					$_id_name = $field['id'] .'_id';
					// get _id old value
					$_id_old = get_metadata( $object_type, $object_id, $_id_name, !$field['multiple'] /* If multicheck this can be multiple values */ );

					// If specified NOT to save the file ID
					if ( isset( $field['save_id'] ) && ! $field['save_id'] ) {
						$_new_id = '';
					} else {
						// otherwise get the file ID
						$_new_id = isset( $_POST[$_id_name] ) ? $_POST[$_id_name] : null;
					}

					if ( $_new_id && $_new_id != $_id_old ) {
						update_metadata( $object_type, $object_id, $_id_name, $_new_id );

					} elseif ( '' == $_new_id && $_id_old ) {
						delete_metadata( $object_type, $object_id, $_id_name, $old );
					}
					break;
			}

			// Allow validation via filter
			$new = apply_filters( 'cmb_validate_'. $field['type'], $new, $object_id, $field, $object_type );

			// Allow validation via metabox flag
			if ( isset( $field['validate_func'] ) ) {
				$ok = call_user_func( array( 'cmb_Meta_Box_Validate', $field['validate_func'] ), $new );
				// move along when meta value is invalid
				if ( $ok === false )
					continue;

			} elseif ( $field['multiple'] ) {

				delete_metadata( $object_type, $object_id, $name );
				if ( ! empty( $new ) ) {
					foreach ( $new as $add_new ) {
						add_metadata( $object_type, $object_id, $name, $add_new, false );
					}
				}
			} elseif ( '' !== $new && $new != $old  ) {
				update_metadata( $object_type, $object_id, $name, $new );
			} elseif ( '' == $new ) {
				delete_metadata( $object_type, $object_id, $name );
			}

		}
	}

	/**
	 * Returns a timezone string representing the default timezone for the site.
	 *
	 * Roughly copied from WordPress, as get_option('timezone_string') will return
	 * and empty string if no value has beens set on the options page.
	 * A timezone string is required by the wp_timezone_choice() used by the
	 * select_timezone field.
	 *
	 * @since  0.9.5
	 * @return string Timezone string
	 */
	public static function timezone_string() {
		$current_offset = get_option( 'gmt_offset' );
		$tzstring       = get_option( 'timezone_string' );

		if ( empty( $tzstring ) ) { // Create a UTC+- zone if no timezone string exists
			if ( 0 == $current_offset )
				$tzstring = 'UTC+0';
			elseif ( $current_offset < 0 )
				$tzstring = 'UTC' . $current_offset;
			else
				$tzstring = 'UTC+' . $current_offset;
		}

		return $tzstring;
	}

	/**
	 * Returns time string offset by timezone
	 * @since  0.9.5
	 * @param  string $tzstring Time string
	 * @return string           Offset time string
	 */
	public static function timezone_offset( $tzstring ) {
		if ( !empty( $tzstring ) ) {

			if ( substr( $tzstring, 0, 3 ) === 'UTC' ) {
				$tzstring = str_replace( array( ':15',':30',':45' ), array( '.25','.5','.75' ), $tzstring );
				return intval( floatval( substr( $tzstring, 3 ) ) * HOUR_IN_SECONDS );
			}

			$date_time_zone_selected = new DateTimeZone( $tzstring );
			$tz_offset = timezone_offset_get( $date_time_zone_selected, date_create() );

			return $tz_offset;
		}

		return 0;
	}

	/**
	 * Offset a time value based on timezone
	 * @since  0.9.5
	 * @param  integer $object_id Object ID
	 * @return string             Offset time string
	 */
	public static function field_timezone_offset( $object_id = 0 ) {

		$tzstring = self::field_timezone( $object_id );

		return self::timezone_offset( $tzstring );
	}

	/**
	 * Return timezone string
	 * @since  0.9.5
	 * @param  integer $object_id Object ID
	 * @return string             Timezone string
	 */
	public static function field_timezone( $object_id = 0 ) {
		$tzstring = null;
		if ( ! ( $object_id = self::get_object_id( $object_id ) ) )
			return $tzstring;

		if ( array_key_exists( 'timezone', self::$field ) && self::$field['timezone'] ) {
			$tzstring = self::$field['timezone'];
		} else if ( array_key_exists( 'timezone_meta_key', self::$field ) && self::$field['timezone_meta_key'] ) {
			$timezone_meta_key = self::$field['timezone_meta_key'];

			$tzstring = get_metadata( self::get_object_type(), $object_id, $timezone_meta_key, true );

			return $tzstring;
		}

		return false;
	}

	/**
	 * Get object id from global space if no id is provided
	 * @since  0.9.5
	 * @param  integer $object_id Object ID
	 * @return integer $object_id Object ID
	 */
	public static function get_object_id( $object_id = 0 ) {
		if ( $object_id )
			return $object_id;

		// Try to get our object ID from the global space
		switch ( self::get_object_type() ) {
			case 'user':
				$object_id = isset( $GLOBALS['user_id'] ) ? $GLOBALS['user_id'] : $object_id;
				break;

			default:
				$object_id = isset( $GLOBALS['post']->ID ) ? $GLOBALS['post']->ID : $object_id;
				break;
		}

		return $object_id;
	}

	/**
	 * Sets the $object_type based on metabox settings
	 * @since 0.9.5
	 * @param array $meta_box Metabox config array
	 */
	public static function set_object_type( $meta_box ) {

		if ( ! isset( $meta_box['pages'] ) )
			return self::get_object_type();


		$type = false;
		// check if 'pages' is a string
		if ( is_string( $meta_box['pages'] ) )
			$type = $meta_box['pages'];
		// if it's an array of one, extract it
		elseif ( is_array( $meta_box['pages'] ) && count( $meta_box['pages'] === 1 ) )
			$type = is_string( end( $meta_box['pages'] ) ) ? end( $meta_box['pages'] ) : false;

		if ( !$type )
			return self::get_object_type();

		// Get our object type
		if ( 'user' == $type )
			self::$object_type = 'user';
		elseif ( 'comment' == $type )
			self::$object_type = 'comment';
		else
			self::$object_type = 'post';

		return self::get_object_type();
	}

	/**
	 * Returns the object type
	 * @since  0.9.5
	 * @return string Object type
	 */
	public static function get_object_type() {
		return self::$object_type;
	}

	/**
	 * Returns the nonce value for wp_meta_box_nonce
	 * @since  0.9.5
	 * @return string Nonce value
	 */
	public static function nonce() {
		return basename( __FILE__ );
	}

}

// Handle oembed Ajax
add_action( 'wp_ajax_cmb_oembed_handler', array( 'cmb_Meta_Box_ajax', 'oembed_handler' ) );
add_action( 'wp_ajax_nopriv_cmb_oembed_handler', array( 'cmb_Meta_Box_ajax', 'oembed_handler' ) );

/**
 * Loop and output multiple metaboxes
 * @since 0.9.5
 * @param array $meta_boxes Metaboxes config array
 * @param int   $object_id  Object ID
 */
function cmb_print_metaboxes( $meta_boxes, $object_id ) {
	foreach ( (array) $meta_boxes as $meta_box ) {
		cmb_print_metabox( $meta_box, $object_id );
	}
}

/**
 * Output a metabox
 * @since 0.9.5
 * @param array $meta_box  Metabox config array
 * @param int   $object_id Object ID
 */
function cmb_print_metabox( $meta_box, $object_id ) {
	$cmb = new cmb_Meta_Box( $meta_box );
	if ( $cmb ) {

		$cmb::$object_id = $object_id;
		$cmb::set_object_type( $meta_box );

		if ( ! wp_script_is( 'cmb-scripts', 'registered' ) ) {
			$cmb->register_scripts();
		}
		wp_enqueue_script( 'cmb-scripts' );

		// default is to show cmb styles
		if ( ! isset( $meta_box['cmb_styles'] ) || $meta_box['cmb_styles'] != false )
			wp_enqueue_style( 'cmb-styles' );

		$cmb::show_form( $meta_box, $object_id, $cmb::get_object_type() );
	}

}

/**
 * Saves a particular metabox's fields
 * @since 0.9.5
 * @param array $meta_box  Metabox config array
 * @param int   $object_id Object ID
 */
function cmb_save_metabox_fields( $meta_box, $object_id ) {
	cmb_Meta_Box::set_object_type( $meta_box );
	cmb_Meta_Box::save_fields( $meta_box['fields'], $object_id, cmb_Meta_Box::get_object_type() );
}

/**
 * Display a metabox form & save it on submission
 * @since  0.9.5
 * @param  array   $meta_box  Metabox config array
 * @param  int     $object_id Object ID
 * @param  boolean $return    Whether to return or echo form
 * @return string             CMB html form markup
 */
function cmb_metabox_form( $meta_box, $object_id, $echo = true ) {

	// Save the metabox if it's been submitted
	// check permissions
	// @todo more hardening?
	if (
		// check nonce
		isset( $_POST['submit-cmb'], $_POST['object_id'], $_POST['wp_meta_box_nonce'] )
		&& wp_verify_nonce( $_POST['wp_meta_box_nonce'], cmb_Meta_Box::nonce() )
		&& $_POST['object_id'] == $object_id
	)
		cmb_save_metabox_fields( $meta_box, $object_id );

	// Show specific metabox form
	$form = '
	<form class="cmb-form" method="post" id="'. $meta_box['id'] .'" enctype="multipart/form-data" encoding="multipart/form-data">
		'. cmb_print_metabox( $meta_box, $object_id ) . '
		<input type="hidden" name="object_id" value="'. $object_id .'">
		<input type="submit" name="submit-cmb" value="'. __( 'Save', 'cmb' ) .'">
		<a href="'. remove_query_arg( 'edit' ) .'">'. __( 'Cancel', 'cmb' ) .'</a>
	</form>
	';

	if ( $echo )
		echo $form;

	return $form;
}

// End. That's it, folks! //