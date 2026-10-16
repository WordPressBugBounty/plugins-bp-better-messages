<?php

use Uncanny_Automator\Recipe;

class Add_Better_Messages_Integration {
	use Recipe\Integrations;

	public function __construct() {
		$this->setup();
	}

	protected function setup() {
		$this->set_integration( 'BETTER_MESSAGES' );
		$this->set_name( 'Better Messages' );
		$this->set_icon( 'icon.png' );
		$this->set_icon_path( __DIR__ . '/img/' );
		$this->set_plugin_file_path( dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'uncanny-automator-better-messages.php' );
		$this->set_external_integration( true );
	}

	public function plugin_active() {
		return true;
	}
}
