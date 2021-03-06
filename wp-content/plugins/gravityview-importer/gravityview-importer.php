<?php
/**
 * Plugin Name:       	GravityView - Gravity Forms Import Entries
 * Plugin URI:        	https://gravityview.co/extensions/gravity-forms-entry-importer/
 * Description:       	Import entries into Gravity Forms
 * Version:          	1.1.2
 * Author:            	Katz Web Services, Inc.
 * Author URI:        	https://gravityview.co
 * Text Domain:       	gravityview-importer
 * License:           	GPLv2 or later
 * License URI: 		http://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path:			/languages
 */

if (!class_exists("GFForms") || !is_callable( array('GFForms','include_feed_addon_framework') ) ) {
	return;
}

// Make sure PHP 5.3 is supported
if( version_compare( phpversion(), '5.3' ) <= 0) {

	$message = wpautop( __( 'GravityView Importer requires PHP Version 5.3 or higher. Please contact your web host and ask them to upgrade your server.', 'gravityview-importer') );

	require_once dirname( __FILE__ ) . '/class-gravityview-importer-admin-notices.php';

	GravityView_Importer_Admin_Notices::add_notice( array(
		'message' => $message,
		'class' => 'error',
	));

	GravityView_Importer_Admin_Notices::instance();

	return;

}

GFForms::include_feed_addon_framework();

class GV_Import_Entries_Addon extends GFFeedAddOn {

	/**
	 * @var string Version number of the Add-On
	 */
	protected $_version = '1.1.2';
	/**
	 * @var string Gravity Forms minimum version requirement
	 */
	protected $_min_gravityforms_version = '1.9-beta';
	/**
	 * @var string URL-friendly identifier used for form settings, add-on settings, text domain localization...
	 */
	protected $_slug = 'gravityview-importer';
	/**
	 * @var string Relative path to the plugin from the plugins folder. Example "gravityforms/gravityforms.php"
	 */
	protected $_path = 'gravityview-importer/gravityview-importer.php';
	/**
	 * @var string Full path the the plugin. Example: __FILE__
	 */
	protected $_full_path = __FILE__;
	/**
	 * @var string URL to the Gravity Forms website. Example: 'http://www.gravityforms.com' OR affiliate link.
	 */
	protected $_url = 'https://gravityview.co';
	/**
	 * @var string Title of the plugin to be used on the settings page, form settings and plugins page. Example: 'Gravity Forms MailChimp Add-On'
	 */
	protected $_title = 'GravityView Import Entries';
	/**
	 * @var string Short version of the plugin title to be used on menus and other places where a less verbose string is useful. Example: 'MailChimp'
	 */
	protected $_short_title = 'Import Entries';
	/**
	 * @var array Members plugin integration. List of capabilities to add to roles.
	 */
	protected $_capabilities = array( 'manage_options' );
	/**
	 * @var string The hook suffix for the app menu
	 */
	public $app_hook_suffix = 'gv_import';

	/**
	 * @var bool Only one import form per form
	 */
	protected $_multiple_feeds = false;

	/**
	 * @var int
	 */
	protected $form_id = 0;

	/**
	 * @var int
	 */
	protected $feed_id = 0;


	protected $field_map = array();

	/**
	 * Whether the import file exists in the path
	 *
	 * @var boolean
	 */
	private $_file_exists = NULL;

	/**
	 * @var string
	 */
	private $_error_message = '';

	/**
	 * @var bool
	 */
	public $show_settings = true;

	/**
	 * @var GravityView_Import_License
	 */
	public $license;

	/**
	 * @var GV_Import_Entries_Addon
	 */
	private static $instance;

	/**
	 * @return GV_Import_Entries_Addon
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Before running anything else, require files
	 */
	function pre_init() {

		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && ! DOING_AJAX ) ) {
			return;
		}

		/** @define "$file_path" "./" */
		$file_path = trailingslashit( $this->get_base_path() );

		$this->set_license_handler();

		require_once $file_path . 'helper-functions.php';
		require_once $file_path . 'gravityview-import-export-page.php';
		require_once $file_path . 'vendor/autoload.php';
		require_once $file_path . 'class-gravityview-handle-import.php';
		require_once $file_path . 'class-gravityview-entry-importer.php';
		require_once $file_path . 'class-gravityview-entry-exporter.php';
		require_once $file_path . 'class-gravityview-import-report.php';
		require_once $file_path . 'class-gravityview-import-history.php';
	}

	/**
	 * Set the license handler
	 */
	function set_license_handler() {

		// If importing or license handler is already set, get outta here
		if ( $this->is_import() || ! empty( $this->license ) ) {
			return;
		}

		if ( ! class_exists( 'GravityView_Import_License' ) ) {

			/** @define "$file_path" "./" */
			$file_path = trailingslashit( $this->get_base_path() );

			require_once $file_path . 'class-gravityview-import-license.php';
		}

		$this->license = GravityView_Import_License::get_instance( $this );
	}

	/**
	 * Replace the gear icon with a Floaty head
	 *
	 * @return string
	 */
	function plugin_settings_icon() {
		return '<a class="gvi-astronaut-head-icon" href="https://gravityview.co/extensions/gravity-forms-entry-importer/">GravityView</a>';
	}

	/**
	 * On Admin init
	 */
	function init_admin() {

		// Register tooltips
		add_filter( 'gform_tooltips', array( $this, 'tooltips' ) );

		// Show output ASAP
		add_action( 'gravityview-import/before-settings', 'flush' );
		add_action( 'gravityview-import/before-import', 'flush' );

		// Prevent "File was uploaded successfully" message when running the import
		if ( $this->is_import() ) {
			add_filter( 'gform_admin_error_messages', '__return_empty_array' );
			add_filter( 'gform_admin_messages', '__return_empty_array' );
		}

		parent::init_admin();
	}

	/**
	 * Get the current version #
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->_version;
	}

	/**
	 * @return string
	 */
	public function get_full_path() {
		return $this->_full_path;
	}

	/**
	 * Render plugin settings field
	 *
	 * @return array
	 */
	function plugin_settings_fields() {

		$this->set_license_handler();

		return $this->license->plugin_settings_fields();
	}

	/**
	 * Updates plugin settings with the provided settings
	 *
	 * @param array $settings - Plugin settings to be saved
	 */
	public function update_plugin_settings( $settings ) {
		parent::update_plugin_settings( $settings );
	}

	/**
	 * Update a single setting
	 *
	 * @param $key
	 * @param $value
	 *
	 * @return boolean Whether the settings were updated or not
	 */
	public function update_plugin_setting( $key, $value ) {

		if ( ! is_string( $key ) ) {
			return false;
		}

		$settings         = parent::get_plugin_settings();
		$existing_setting = isset( $settings[ $key ] ) ? $settings[ $key ] : false;

		if ( $existing_setting !== $value ) {
			$settings[ $key ] = $value;
			parent::update_plugin_settings( $settings );

			return true;
		}

		return false;
	}

	/**
	 * Make public.
	 *
	 * @inheritDoc
	 */
	public function get_plugin_setting( $setting_name ) {
		return parent::get_plugin_setting( $setting_name );
	}

	/**
	 * Set up AJAX actions
	 */
	public function init_ajax() {
		add_action( 'wp_ajax_gv_import_complete', array( $this, 'ajax_delete_upload' ) );
	}

	/**
	 * Delete upload via AJAX
	 */
	public function ajax_delete_upload() {

		if ( false === wp_verify_nonce( rgpost( 'nonce' ), 'gv-import-ajax' ) ) {
			exit( 0 );
		}

		$feed_id = intval( rgpost( 'feed_id' ) );

		$this->feed_id = $feed_id;

		$this->setup_feed_settings( $feed_id );

		$file_removed = $this->maybe_delete_upload( true );

		if ( $file_removed ) {
			exit( 1 );
		} else {
			exit();
		}

	}

	/**
	 * Add tooltips for use with gf_tooltip()
	 *
	 * @param array $tooltips
	 *
	 * @return array Tooltips with Importer tooltips added
	 */
	public function tooltips( $tooltips ) {

		$importer_tooltips = array(
			'confusing-column'   => array(
				'title'   => __( 'Data Mapping', 'gravityview-importer' ),
				'content' => __( 'For each row of your uploaded file, choose what form field in Gravity Forms where the data should be added. If the file were a person, choose how the file would fill its own form (what data would go where).', 'gravityview-importer' ),
			),
			'gv-import-file'     => array(
				'title'   => __( 'The Import File', 'gravityview-importer' ),
				'content' => __( 'The import file must be a CSV file (.csv) or TSV file (.tsv)', 'gravityview-importer' ),
			),
			'use-default-values' => array(
				'title'   => __( 'Yes, use the field\'s Default Values', 'gravityview-importer' ),
				'content' => __( 'If a value is empty when importing an entry, use the field Default Value instead. For field types with Choices, this is the selected choices in the "General" tab. Otherwise, Default Values are otherwise set in a Field\'s "Advanced" tab.', 'gravityview-importer' )
			),
			'yes-upload-files'   => array(
				'title'   => __( 'Yes, Download and Save Files', 'gravityview-importer' ),
				'content' => __( 'The importer will attempt to download the file specified and save it to Gravity Forms. If the upload fails, file URLs will be used as a backup.', 'gravityview-importer' )
			),
			'no-upload-files'    => array(
				'title'   => __( 'Just Save the File Link', 'gravityview-importer' ),
				'content' => __( 'No files will be downloaded; the previous file URLs will be imported.', 'gravityview-importer' )
			),
		);

		// Format using a header and paragraphing the content
		foreach ( $importer_tooltips as $key => $tooltip ) {
			$tooltips[ $key ] = sprintf( '<h6>%s</h6>%s', $tooltip['title'], wpautop( $tooltip['content'] ) );
		}

		return $tooltips;
	}

	/***
	 * Modify the render settings page to add form-data form type
	 *
	 * @param array $sections - Configuration array containing all fields to be rendered grouped into sections
	 */
	function render_settings( $sections ) {

		if ( $this->is_import() ) {

			do_action( 'gravityview-import/before-import' );

			$this->render_import_screen( $sections );

			do_action( 'gravityview-import/after-import' );

		} else {

			$this->render_settings_screen( $sections );

		}

	}

	/**
	 * (Make public a protected function)
	 *
	 * Sets the validation error message
	 * Sets the error message to be displayed when a field fails validation.
	 * When implementing a custom validation callback function, use this function to specify the error message to be displayed.
	 *
	 * @param array $field - The current field meta
	 * @param string $error_message - The error message to be displayed
	 */
	public function set_field_error( $field, $error_message = '' ) {
		parent::set_field_error( $field, $error_message );
	}

	/**
	 * Show the import screen
	 *
	 * @param array $sections
	 */
	function render_import_screen( $sections ) {

		include $this->get_base_path() . '/partials/import-screen.php';

	}

	/**
	 * Show the settings screen
	 *
	 * @param $sections
	 */
	function render_settings_screen( $sections ) {

		do_action( 'gravityview-import/before-settings' );

		if ( apply_filters( 'gravityview-import/show-settings', $this->show_settings ) ) {
			parent::render_settings( $sections );
		}

		do_action( 'gravityview-import/after-settings' );
	}

	public function get_form_id() {
		return $this->form_id;
	}

	function save_feed_settings( $feed_id, $form_id, $settings ) {

		$this->feed_id = $feed_id;

		$this->form_id = $form_id;

		$this->settings = $settings;

		return parent::save_feed_settings( $feed_id, $form_id, $this->settings );
	}

	public function maybe_save_feed_settings( $feed_id, $form_id ) {

		$this->feed_id = $feed_id;
		$this->form_id = $form_id;

		$this->maybe_delete_upload();

		$this->maybe_handle_upload();

		return parent::maybe_save_feed_settings( $feed_id, $form_id );
	}

	/**
	 * Make public.
	 *
	 * @inheritDoc
	 */
	public static function maybe_decode_json( $value ) {
		return parent::maybe_decode_json( $value );
	}

	public function is_import() {
		return __( 'Begin Import', 'gravityview-importer' ) === rgpost( 'gform-settings-save' );
	}

	private function setup_feed_settings( $feed_id = 0 ) {

		$settings = $this->get_current_settings();

		// If the settings exist for this feed, use them
		if ( $this->feed_id && ! empty( $settings ) ) {
			if ( empty( $feed_id ) || $this->feed_id === $feed_id ) {
				return $settings;
			}
		}

		$feed_id = empty( $feed_id ) ? $this->feed_id : $feed_id;

		// Fetch the feed settings
		$feed          = $this->get_feed( $feed_id );
		$this->feed_id = $feed_id;
		$this->form_id = $feed['form_id'];

		// Set them so they're accessible using $this->get_setting()
		$this->set_settings( $feed['meta'] );

		return $feed['meta'];
	}

	/**
	 * If Remove File button has been submitted, delete the uploaded file
	 *
	 * @param boolean $force If passed programatically, don't check for $_POST['remove-file']
	 */
	function maybe_delete_upload( $force = false ) {

		$file_removed = false;

		// Handle RESET form
		if ( $force || rgpost( 'remove-file' ) || ( empty( $_POST ) && wp_verify_nonce( rgget( '_wpnonce' ), 'remove-file' ) ) ) {

			$settings = $this->setup_feed_settings();

			$file_path = $this->get_file_path();

			$file_removed = $this->unlink( $file_path );

			unset( $_GET['_wpnonce'], $_POST['_gaddon_setting_file'], $_POST['_gaddon_setting_uploaded-file'] );

			if ( $file_removed && $this->feed_id ) {

				unset( $settings['uploaded-file'], $settings['file'] );

				$this->save_feed_settings( $this->feed_id, $this->form_id, $settings );

				$this->_file_exists = false;
			}
		}

		// Force re-checking whether file exists
		$this->_file_exists = NULL;

		return $file_removed;
	}

	/**
	 * Overwrite the page title based on the action being performed
	 *
	 * @return string
	 */
	public function feed_settings_title() {
		$form_title           = $this->get_current_form_title();
		$import_entries_title = __( 'Import Entries', 'gravityview-importer' );
		if ( $form_title ) {
			$import_entries_title .= sprintf( __( ' to "%s"', 'gravityview-importer' ), $form_title );
		}

		return $this->is_import() ? esc_html__( 'Import Results', 'gravityview-importer' ) : esc_html( $import_entries_title );
	}

	public function get_current_form_title() {
		$form = $this->get_current_form();

		return $form ? $form['title'] : NULL;
	}

	/**
	 * If there's a file
	 *
	 * @return void
	 */
	function maybe_handle_upload() {

		// The JS converts the form type to allow handling file uploads. If it didn't work, show an error.
		if ( isset( $_POST['_gaddon_setting_file'] ) ) {

			GFCommon::add_error_message( __( 'The form did not process properly. You may have Javascript disabled. If so, please enable and try again.', 'gravityview-importer' ) );

		} else if ( rgget( '_gaddon_setting_file', $_FILES ) ) {

			$file = $this->handle_upload();

			if ( ! is_wp_error( $file ) ) {
				$file['file']                           = $this->maybe_escape_windows_path( $file['file'] );
				$_POST['_gaddon_setting_uploaded-file'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $file ) : json_encode( $file );
				$_POST['_gaddon_setting_file']          = $file['file'];
			} else {
				$this->_error_message = $file->get_error_message();
			}
		}
	}

	/**
	 * Fix issue JSON-encoding the file path for Windows machines
	 *
	 * @since 1.1.2
	 *
	 * @param string $path Path to file
	 *
	 * @return string path to file, with backslashes escaped
	 */
	private function maybe_escape_windows_path( $path ) {
		return $this->is_windows() ? str_replace( '\\', '\\\\', $path ) : $path;
	}

	/**
	 * Check whether the current server is Windows
	 *
	 * @since 1.1.2
	 * @return bool True: it's windows; False: not windows
	 */
	private function is_windows() {
		$is_windows = false;

		$windows = array(
			'windows nt',
			'windows',
			'winnt',
			'win32',
			'win'
		);
		$operating_system = strtolower( php_uname( 's' ) );
		foreach ( $windows as $windows_name ) {
			if ( strpos( $operating_system, $windows_name ) !== false ) {
				$is_windows = true;
				break;
			}
		}

		return $is_windows;
	}

	/**
	 * Override the default so that we can add Paragraph field support
	 *
	 * @param $field
	 * @param $form_id
	 *
	 * @return string
	 */
	public function settings_field_map_select( $field, $form_id ) {
		$field['choices'] = self::get_field_map_choices( $form_id );

		return $this->settings_select( $field, false );
	}

	/***
	 * Renders and initializes a file filed type
	 *
	 * @param array $field - Field array containing the configuration options of this field
	 * @param bool $echo = true - true to echo the output to the screen, false to simply return the contents as a string
	 *
	 * @return string The HTML for the field
	 */
	protected function settings_file( $field, $echo = true ) {
		$field['type'] = 'file'; //making sure type is set to hidden

		$attributes = $this->get_field_attributes( $field );

		unset( $attributes['desc'] );

		$html = '<input
                    type="file"
		            multiple="false"
                    name="_gaddon_setting_' . esc_attr( $field['name'] ) . '"' .
		        implode( ' ', $attributes ) .
		        ' />';

		if ( $this->field_failed_validation( $field ) ) {
			$html .= $this->get_error_icon( $field );
		}

		if ( ! empty( $field['desc'] ) ) {
			$html .= '<div class="description">' . wpautop( esc_html( $field['desc'] ) ) . '</div>';
		}

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	/**
	 * @return array
	 */
	function get_supported_upload_mimes() {
		return array(
			'csv' => 'text/csv',
			'txt' => 'text/plain',
			'tsv' => 'text/tab-separated-values',
			//'xml' => 'application/xml',
			//'xls' => 'application/vnd.ms-excel',
			//'json' => 'application/json',
		);
	}

	/**
	 * Unlink an upload and display a message.
	 *
	 * @param $file_path Full path to the file
	 *
	 * @return bool True: Success; False: failed to remove the file.
	 */
	public function unlink( $file_path ) {

		if ( file_exists( $file_path ) ) {

			$unlinked = unlink( $file_path );

			if ( $unlinked ) {
				GFCommon::add_message( __( 'The file was cleared.', 'gravityview-importer' ) );

				return true;
			} else {
				GFCommon::add_error_message( __( 'The file was not able to be deleted.', 'gravityview-importer' ) );

				return false;
			}
		}

		return false;
	}

	/**
	 * Process the file upload
	 *
	 * @return array|WP_Error Array: Upload succeeded. Returns array with `file` (relative path), `url` (file URL), and `type` (file MIME) keys.
	 */
	function handle_upload() {

		if ( false === $this->user_can_upload() ) {
			return new WP_Error( 'upload_failed', __( 'You do not have permission to import entries.', 'gravityview-importer' ) );
		}

		if ( false === $this->validate_nonce() ) {
			return new WP_Error( 'upload_failed', __( 'The request was invalid; please try again (the "nonce" was invalid).', 'gravityview-importer' ) );
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		$uploadedfile = $_FILES['_gaddon_setting_file'];

		$upload_overrides = array(
			'test_form' => false,
			'test_type' => true,
			'mimes'     => $this->get_supported_upload_mimes()
		);

		$movefile = wp_handle_upload( $uploadedfile, $upload_overrides );

		/**
		 * Upload succeeded
		 */
		if ( $movefile && ! isset( $movefile['error'] ) ) {
			return $movefile;
		}

		return new WP_Error( 'upload_failed', $movefile['error'], $uploadedfile );
	}

	/**
	 * Check whether the current request has a valid nonce
	 *
	 * @return bool
	 */
	function validate_nonce() {

		$verify_nonce = wp_verify_nonce( rgpost( '_wpnonce' ), sprintf( 'import_form_%d', $this->form_id ) );

		return $verify_nonce;
	}

	/**
	 * Check whether the current request has a valid capabilities
	 *
	 * @filter gravityview-import/import-cap Modify the capability required to import entries. By default: `gravityforms_edit_entries`
	 *
	 * @return bool
	 */
	function user_can_upload() {

		$required_cap = apply_filters( 'gravityview-import/import-cap', 'gravityforms_edit_entries' );

		$has_permission = GFCommon::current_user_can_any( $required_cap );

		return $has_permission;
	}

	function getImporter() {

		return GravityView_Handle_Import::getInstance();

	}

	public function get_base_path( $full_path = '' ) {
		return parent::get_base_path( $full_path );
	}


	public function _get_field_map_fields( $headers = array() ) {

		$feed = $this->get_current_feed();

		if ( ! $feed ) {
			$feed = array( 'meta' => array() );
		}

		$fields = self::get_field_map_fields( $feed, 'import_field_map' );

		return $fields;
	}

	/**
	 * Register the settings field for the EDD License field type
	 *
	 * @param array $field
	 * @param bool $echo Whether to echo the
	 *
	 * @return string
	 */
	protected function settings_edd_license( $field, $echo = true ) {

		$text = self::settings_text( $field, false );

		$activation = $this->license->settings_edd_license_activation( $field, false );

		$return = $text . $activation;

		if ( $echo ) {
			echo $return;
		}

		return $return;
	}

	/**
	 * Allow mapping fields that aren't otherwise mappable
	 *
	 * @param GF_Field $field GF Field
	 * @param array $fields Fields to be shown as mappable
	 *
	 * @return array Possibly modified $fields
	 */
	public static function add_custom_field_mapping( $field, $fields ) {

		switch ( $field->type ) {
			case 'list':
				/**
				 * List fields have a single ID, not an input for each. We need to allow users to map columns because
				 * GF exports list fields in separate CSV columns.
				 *
				 * @hack
				 */
				if ( $field->enableColumns ) {
					foreach ( (array) $field['choices'] as $key => $choice ) {
						$choice_label = ! empty( $choice['text'] ) ? '(' . $choice['text'] . ')' : '';
						$fields[]     = array(
							'label' => sprintf( '%s %d %s', $field->label, ( $key + 1 ), $choice_label ),
							'value' => $field->id . '.' . ( $key + 1 ),
						);
					}
				}
				break;
			case 'time':
				$fields[] = array(
					'label' => $field->label,
					'value' => $field->id,
				);
				break;
		}

		return $fields;
	}

	public static function get_field_map_choices( $form_id, $field_type = NULL, $exclude_field_types = NULL ) {

		// Default fields
		$fields = parent::get_field_map_choices( $form_id, $field_type );

		// Default form fields
		$form = GFFormsModel::get_form_meta( $form_id );

		$has_product_fields = false;
		$has_post_fields    = false;

		if ( $form ) {
			foreach ( $form['fields'] as $field ) {

				if ( GFCommon::is_product_field( $field->type ) ) {
					$has_product_fields = true;
				}

				if ( GFCommon::is_post_field( $field ) ) {
					$has_post_fields = true;
				}

				$fields = self::add_custom_field_mapping( $field, $fields );
			}
		}

		$fields[] = array(
			'label' => __( 'User Agent', 'gravityview-importer' ),
			'value' => 'user_agent',
		);

		$fields[] = array(
			'label' => __( 'Created By (User ID)', 'gravityview-importer' ),
			'value' => 'user_id',
		);
		$fields[] = array(
			'label' => __( 'Created By (User Login)', 'gravityview-importer' ),
			'value' => 'user_login',
		);

		/**
		 * @since 1.7
		 */
		if ( $has_post_fields ) {

			/**
			 * @todo Update fields
			 */
			$fields[] = array(
				"label" => __( 'Post ID (Update Existing Posts)', 'gravityview-importer' ),
				"value" => 'post_id'
			);

			$fields[] = array(
				'label' => __( 'Post Author', 'gravityview-importer' ),
				'value' => 'post_author'
			);

			$fields[] = array(
				'label' => __( 'Post Status', 'gravityview-importer' ),
				'value' => 'post_status'
			);

		}

		if ( $has_product_fields ) {

			$fields[] = array(
				"label" => __( 'Payment Status', 'gravityview-importer' ),
				"value" => 'payment_status'
			);

			$fields[] = array(
				"label" => __( 'Payment Date', 'gravityview-importer' ),
				"value" => 'payment_date',
			);

			$fields[] = array(
				"label" => __( 'Payment Amount', 'gravityview-importer' ),
				"value" => 'payment_amount'
			);

			$fields[] = array(
				"label" => __( 'Payment Method', 'gravityview-importer' ),
				"value" => 'payment_method'
			);

			$fields[] = array(
				"label" => __( 'Is Fulfilled', 'gravityview-importer' ),
				"value" => 'is_fulfilled',
			);

			$fields[] = array(
				"label" => __( 'Transaction ID', 'gravityview-importer' ),
				"value" => 'transaction_id',
			);

			$fields[] = array(
				"label" => __( 'Transaction Type', 'gravityview-importer' ),
				"value" => 'transaction_type',
			);

		}

		$fields[] = array(
			"label" => __( 'Entry Note', 'gravityview-importer' ),
			"value" => 'entry_note',
		);
		$fields[] = array(
			"label" => __( 'Entry Note Creator', 'gravityview-importer' ),
			"value" => 'entry_note_creator',
		);

		/**
		 * @since 1.1
		 */
		$fields[] = array(
			'label' => __( 'Entry ID (Update Existing Entries)', 'gravityview-importer' ),
			'value' => 'entry_id',
		);

		return $fields;
	}

	/**
	 * Overwrite the left column title on the Feed Map screen
	 *
	 * @return string
	 */
	function field_map_title() {
		return __( 'The data from this file column&hellip;', 'gravityview-importer' );
	}

	/**
	 * Instead of using Javascript to modify the second column text, let's do this right...overriding core methods ;-)
	 *
	 * @since 1.1
	 * @return string Table header
	 */
	public function field_map_table_header() {
		return '<thead>
					<tr>
						<th class="gv-importer-col-csv-field">' . $this->field_map_title() . '</th>
						<th class="gv-importer-col-form-field">' . esc_html__( '&hellip;will be added to this form field', 'gravityview-importer' ) . '</th>
					</tr>
				</thead>';
	}

	/***
	 * Renders the save button for settings pages.
	 *
	 * Same as GFAddOn::settings_save(), but allows for overriding the button class.
	 *
	 * @inheritDoc
	 */
	function settings_save( $field, $echo = true ) {

		$button = parent::settings_save( $field, false );

		// Replace the class
		if ( ! empty( $field['class'] ) ) {
			$button = str_replace( 'button-primary gfbutton', esc_attr( $field['class'] ), $button );
		}

		$button .= wp_nonce_field( sprintf( 'import_form_%d', $this->form_id ), '_wpnonce', true, false );

		if ( $echo ) {
			echo $button;
		}

		return $button;
	}

	private function get_settings_description() {

		/** @define "$base_path" "./" */
		$base_path = trailingslashit( $this->get_base_path() );

		ob_start();

		include( $base_path . 'partials/settings-description.php' );

		return ob_get_clean();
	}

	public function feed_settings_fields() {

		$form = $this->get_current_form();

		return array(
			array(
				"description" => $this->get_settings_description(),
				"id"          => 'gravityview-import-section',
				"fields"      => array(
					// At the top of the array so it doesnt mess with ":last-child" selectors
					array(
						"label" => '',
						"type"  => "hidden",
						"name"  => "uploaded-file",
					),
					array(
						"label"      => __( "Upload Import File", 'gravityview-importer' ),
						"desc"       => __( 'Select your .csv or .tsv import file. You will specify how to import the data in the next step.', 'gravityview-importer' ),
						"class"      => 'gv-importer-file-field',
						"type"       => "file",
						"name"       => "file",
						"dependency" => array( $this, 'file_not_exists' ),
						"required"   => true,
						"tooltip"    => "gv-import-file",
					),
					array(
						"label"       => __( "Current Import File", 'gravityview-importer' ),
						"value"       => __( 'Clear uploaded file', 'gravityview-importer' ),
						"html_before" => '<i class="dashicons dashicons-media-spreadsheet"></i><code>' . basename( $this->get_file_path() ) . '</code><br />',
						"class"       => 'button button-secondary',
						"href"        => add_query_arg( array() ),
						"dependency"  => array( $this, 'get_file' ),
						'onclick'     => 'return confirm("' . esc_js( __( 'Are you sure you want to clear the uploaded file? The import process will start over and any field mapping will be lost.', 'gravityview-importer' ) ) . '");',
						"type"        => "submit",
						"name"        => "remove-file",
					),
					array(
						"name"       => "import_field_map",
						"label"      => __( "Map Fields", 'gravityview-importer' )
						                . '<button class="button button-small button-primary smart-map">' . esc_html__( 'Map Exact Matches', 'gravityview-importer' ) . '</button>'
						                . '<button type="reset" class="button button-small button-secondary reset-field-map">' . esc_html__( 'Reset Configuration', 'gravityview-importer' ) . '</button>'
						,
						"tooltip"    => 'confusing-column',
						"type"       => "field_map",
						"required"   => true,
						"dependency" => array( $this, 'get_file' ),
						"field_map"  => $this->get_field_map(),
					),
					array(
						"name"          => "upload_files",
						"label"         => __( "Upload Files", 'gravityview-importer' ),
						"type"          => "radio",
						"default_value" => 'yes',
						"choices"       => array(
							array(
								"name"    => "yes",
								'label'   => __( 'Upload files mapped to "File Upload" fields.', 'gravityview-importer' ),
								'tooltip' => 'yes-upload-files',
								'value'   => 'yes',
							),
							array(
								"name"    => 'no',
								'label'   => __( 'Don\'t upload, just link to the file.', 'gravityview-importer' ),
								'tooltip' => 'no-upload-files',
								'value'   => 'no',
							)
						),
						"dependency"    => array( $this, 'get_file' ),
					),
					array(
						"name"          => "ignore_required",
						"label"         => __( "Ignore Required", 'gravityview-importer' ),
						"type"          => "checkbox",
						"default_value" => 'no',
						"choices"       => array(
							array(
								"name"  => "ignore_required",
								'label' => __( 'Should an entry be imported even if it is missing required fields?', 'gravityview-importer' ),
								'value' => 'yes',
							),
						),
						"dependency"    => array( $this, 'get_file' ),
					),
					array(
						"name"          => "use_default_value",
						"label"         => __( "Use Default Field Values", 'gravityview-importer' ),
						"type"          => "checkbox",
						"default_value" => 'no',
						"choices"       => array(
							array(
								"name"    => "use_default_value",
								'label'   => __( 'If a field has Default Values and the mapped value is empty, use the default.', 'gravityview-importer' ),
								'value'   => 'yes',
								'tooltip' => 'use-default-values',
							),
						),
						"dependency"    => array( $this, 'get_file' ),
					),
					// TODO: Only show if there's a header in the import that is Post ID
					array(
						"name"          => "overwrite_post_data",
						"label"         => __( "Overwrite Post Data", 'gravityview-importer' ),
						"type"          => "checkbox",
						"default_value" => 'no',
						"choices"       => array(
							array(
								"name"  => "overwrite_post_data",
								'label' => __( 'Confirm that the existing post content will be overwritten by the imported data?', 'gravityview-importer' ),
								'value' => 'yes',
							),
						),
						"dependency"    => array( $this, 'get_file' ),
					),
					/*array(
						"name"          => "skip_empty_overwrite_entry",
						"label"         => __( "Skip Empty/Missing Entry IDs", 'gravityview-importer' ),
						"type"          => "checkbox",
						"default_value" => 'no',
						"choices"       => array(
							array(
								"name"          => "skip_empty_overwrite_entry",
								'label'         => __( 'Only update entries, do not create new entries', 'gravityview-importer' ),
								'tooltip'       => __( 'By default, entries will be imported as new entries if the Entry ID is blank or the specified Entry ID does not exist. By checking this option, entries will only be updated, not created.', 'gravityview-importer' ),
								'value'         => 'yes',
								'default_value' => 'no',
							),
						),
						"dependency"    => array( $this, 'get_file' ),
					),*/
					array(
						"name"          => "overwrite_entry",
						"label"         => __( "Overwrite Entry Data", 'gravityview-importer' ),
						"type"          => "checkbox",
						"default_value" => 'no',
						"choices"       => array(
							array(
								"name"          => "overwrite_entry",
								'label'         => __( 'Warning: the existing entry will be completely replaced by the imported entry, not updated. Confirm you want this.', 'gravityview-importer' ),
								'value'         => 'yes',
								'default_value' => 'no',
							),
						),
						"dependency"    => array( $this, 'get_file' ),
					),
					array(
						"name"           => "condition",
						"label"          => __( "Conditional Import", "gravityview-importer" ),
						"type"           => "feed_condition",
						"checkbox_label" => __( 'Only import rows if they match certain conditions', 'gravityview-importer' ),
						"instructions"   => __( "Import the row if", "gravityview-importer" ),
						"dependency"     => array( $this, 'get_file' ),
					),
					array(
						"value"      => __( "Upload File", 'gravityview-importer' ),
						"type"       => "save",
						'class'      => 'button button-primary button-hero',
						"messages"   => $this->_get_submit_messages( __( "Upload File", 'gravityview-importer' ) ),
						"dependency" => array( $this, 'file_not_exists' ),
					),
					array(
						"label"      => __( 'Save this configuration', 'gravityview-importer' ),
						"value"      => __( "Save Configuration", 'gravityview-importer' ),
						"tooltip"    => __( 'You can save your field mapping and come back later before you Begin Import.', 'gravityview-importer' ),
						"type"       => "submit",
						"name"       => "gform-settings-save",
						"messages"   => $this->_get_submit_messages( __( "Save Configuration", 'gravityview-importer' ) ),
						'class'      => 'button button-secondary ',
						"dependency" => array( $this, 'get_file' ),
					),
					array(
						"value"      => __( "Begin Import", 'gravityview-importer' ),
						"type"       => "save",
						'class'      => 'button button-primary button-hero alignright',
						"name"       => "upload-file",
						"dependency" => array( $this, 'get_file' ),
					),
				),

			)
		);
	}


	/**
	 * There are multiple Submit buttons, so we need to find the correct message for each button
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	private function _get_submit_messages( $value = '' ) {

		if ( ! empty( $_POST['gform-settings-save'] ) ) {
			$value = esc_attr( $_POST['gform-settings-save'] );
		}

		switch ( $value ) {

			case __( "Upload File", 'gravityview-importer' ):
				return array(
					'success' => __( 'File successfully uploaded', 'gravityview-importer' ),
					'error'   => $this->_error_message,
				);
				break;

			case __( "Save Configuration", 'gravityview-importer' ):
				return array(
					'success' => __( 'The configuration has been saved.', 'gravityview-importer' ),
				);
				break;

			default:
				return array(
					'success' => __( 'The configuration has been updated successfully.', 'gravityview-importer' ),
				);
		}

	}

	/***
	 * Renders the save button for settings pages
	 *
	 * @param array $field - Field array containing the configuration options of this field
	 * @param bool $echo = true - true to echo the output to the screen, false to simply return the contents as a string
	 *
	 * @return string The HTML
	 */
	public function settings_submit( $field, $echo = true ) {

		$field['type'] = ( isset( $field['type'] ) && in_array( $field['type'], array(
				'submit',
				'reset'
			) ) ) ? $field['type'] : 'submit';

		$attributes    = $this->get_field_attributes( $field );
		$default_value = rgar( $field, 'value' ) ? rgar( $field, 'value' ) : rgar( $field, 'default_value' );
		$value         = $this->get_setting( $field['name'], $default_value );


		$attributes['class'] = isset( $field['class'] ) ? esc_attr( $field['class'] ) : $attributes['class'];
		$tooltip             = isset( $choice['tooltip'] ) ? gform_tooltip( $choice['tooltip'], rgar( $choice, 'tooltip_class' ), true ) : '';

		$html       = isset( $field['html_before'] ) ? $field['html_before'] : '';
		$html_after = isset( $field['html_after'] ) ? $field['html_after'] : '';

		if ( ! rgar( $field, 'value' ) ) {
			$field['value'] = __( 'Update Settings', 'gravityview-importer' );
		}

		$attributes = $this->get_field_attributes( $field );

		unset( $attributes['html_before'], $attributes['html_after'], $attributes['tooltip'] );

		$html .= '<input
                    type="' . $field['type'] . '"
                    name="' . esc_attr( $field['name'] ) . '"
                    value="' . $value . '" ' .
		         implode( ' ', $attributes ) .
		         ' />';

		$html .= $tooltip;
		$html .= $html_after;
		$html .= wp_nonce_field( sprintf( 'import_form_%d', $this->form_id ), '_wpnonce', true, false );

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	function file_not_exists() {
		return ! $this->get_file();
	}

	/**
	 * Get the path to the uploaded file
	 *
	 * @return bool|string
	 */
	function get_file_path() {

		$file = $this->get_file();

		return $file ? rgget( 'file', $file ) : false;
	}

	function get_file() {

		if ( ! is_null( $this->_file_exists ) ) {
			return $this->_file_exists;
		}

		$return = false;

		$uploaded_file = $this->get_setting( 'uploaded-file' );

		if ( $uploaded_file && $file_path = rgget( 'file', $uploaded_file ) ) {
			$return = file_exists( $file_path ) ? $uploaded_file : false;

			if ( ! $return && empty( $_POST ) ) {
				GFCommon::add_error_message( __( 'The file does not exist. It may have been deleted.', 'gravityview-importer' ) );
			}
		}

		$this->_file_exists = $return;

		return $this->_file_exists;
	}

	/***
	 * @inheritDoc Just making public
	 *
	 * @return string|array
	 */
	public function get_setting( $setting_name, $default_value = '', $settings = false ) {
		return parent::get_setting( $setting_name, $default_value, $settings );
	}

	/***
	 * @inheritDoc Just making public
	 *
	 * @return string|array
	 */
	public function get_current_settings() {
		return parent::get_current_settings();
	}

	/***
	 * @inheritDoc Just making public
	 *
	 * @return array
	 */
	public function update_app_settings( $settings ) {
		return parent::update_app_settings( $settings );
	}

	public function get_field_map() {

		// Already set? Don't process again.
		if ( ! empty( $this->field_map ) ) {
			return $this->field_map;
		}

		if ( $this->file_not_exists() ) {
			return array();
		}

		/**
		 * Get the values from the first row of the uploaded file (the header row)
		 *
		 * @var array
		 */
		$this->field_map = $this->getImporter()->getHeaderRowFieldMap();

		return $this->field_map;
	}

	/**
	 * Is currently viewed tab the import tab?
	 *
	 * @param $tab
	 *
	 * @return bool
	 */
	protected function is_import_tab() {

		$current_tab = rgempty( 'subview', $_GET ) ? 'settings' : rgget( 'subview' );

		if ( strtolower( $current_tab ) === $this->_slug ) {
			return true;
		}

		return false;
	}

	/**
	 * Register scripts
	 *
	 * @return array
	 */
	public function scripts() {

		$scripts = array();

		$script_debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		$scripts[] = array(
			"handle"  => 'gv_importer',
			"src"     => $this->get_base_url() . "/assets/js/admin{$script_debug}.js",
			"version" => $this->_version,
			'enqueue' => array(
				array(
					'admin_page' => array(
						'form_settings',
						'plugin_settings',
						'plugin_page',
						'app_settings',
					),
					'query' => 'subview=gravityview-importer',
				),
			),
			'strings' => array(
				'nonce'               => wp_create_nonce( 'gv-import-ajax' ),
				'feed_id'             => $this->get_current_feed_id(),
				'complete'            => __( 'Complete', 'gravityview-importer' ),
				'cancel'              => __( 'Cancel', 'gravityview-importer' ),
				'updated'             => __( 'Updated', 'gravityview-importer' ),
				'column_header'       => __( '&hellip;will be added to this form field', 'gravityview-importer' ),
				'hide_console'        => __( 'Hide Console', 'gravityview-importer' ),
				'show_console'        => __( 'Show Console', 'gravityview-importer' ),
				'wrapping_up'         => __( 'Wrapping up&hellip;', 'gravityview-importer' ),
				'already_mapped'      => __( 'This field has already been mapped.', 'gravityview-importer' ),
				'overwrite_posts'     => __( 'Warning: Existing post content will be overwritten by the imported data. Proceed?', 'gravityview-importer' ),
				'overwrite_entry'     => __( 'Warning: Existing entry values will be overwritten by the imported data. Proceed?', 'gravityview-importer' ),
				'field_mapping_empty' => __( 'No fields have been mapped. Please configure the field mapping before starting the import.', 'gravityview-importer' ),
				'error_message'       => sprintf( __( 'There was an error on row %s.', 'gravityview-importer' ), '{row}' ),
				'success_message'     => sprintf( __( 'Created %s from Row %s', 'gravityview-importer' ), sprintf( __( 'Entry #%s', 'gravityview-importer' ), '{entry_id}' ), '{row}' ),
			)
		);

		return array_merge( parent::scripts(), $scripts );
	}

	/**
	 * Register styles used by the plugin
	 *
	 * @return array
	 */
	public function styles() {

		$styles = array();

		$styles[] = array(
			"handle"  => $this->_slug . '-admin',
			"src"     => $this->get_base_url() . "/assets/css/admin.css",
			"version" => $this->_version,
			'enqueue' => array(
				array(
					'admin_page' => array(
						'form_settings',
						'plugin_settings',
						'plugin_page',
						'app_settings',
					),
					'query' => 'subview=gravityview-importer',
				),
			)
		);

		$styles[] = array(
			"handle"  => $this->_slug . '-admin-settings',
			"src"     => $this->get_base_url() . "/assets/css/admin-settings.css",
			"version" => $this->_version,
			'enqueue' => array(
				array(
					'admin_page' => array(
						'plugin_settings',
						'app_settings',
					),
				),
			)
		);

		return array_merge( parent::styles(), $styles );
	}

}

function gravityview_importer() {
	return GV_Import_Entries_Addon::get_instance();
}

gravityview_importer();