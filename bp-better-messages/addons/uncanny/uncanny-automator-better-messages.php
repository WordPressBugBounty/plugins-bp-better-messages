<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

/**
 * @wordpress-plugin
 * Plugin Name:       Uncanny Automator Sample Integration
 * Plugin URI:        https://www.automatorplugin.com
 * Description:       Sample integration plugin for Uncanny Automator
 * Version:           1.0.0
 * Author:            Uncanny Automator
 * Author URI:        https://www.automatorplugin.com
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:       automator-sample
 * Domain Path:       /languages
 */
class Uncanny_Automator_Better_Messages {
	public $integration_code = 'better-messages';
	public $directory;

	public function __construct() {
		$this->directory = __DIR__ . DIRECTORY_SEPARATOR . $this->integration_code;
		add_action( 'automator_configuration_complete', array( $this, 'add_this_integration' ) );
	}

	public function add_this_integration() {
		if ( ! function_exists( 'automator_add_integration' ) ) {
			wp_die( 'automator_add_integration() function not found. Please upgrade Uncanny Automator to version 3.0+' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		}

		if ( empty( $this->integration_code ) || empty( $this->directory ) ) {
			return false;
		}

		automator_add_integration_directory( $this->integration_code, $this->directory );
	}
}

new Uncanny_Automator_Better_Messages();
