<?php

class EDD_RI_Client {

	public $api_url;

	/**
	 * EDD_RI_Client constructor.
	 *
	 * @param string $api_url
	 */
	public function __construct( $api_url ) {

		$this->api_url = trailingslashit( $api_url );

		add_action( 'init', array( $this, 'client_admin' ) );
		add_action( 'plugins_api', array( $this, 'plugins_api' ), 99, 3 );
		add_action( 'wp_ajax_edd_ri_install', array( $this, 'install' ) );
	}

	/**
	 * Instantiate the admin client class.
	 */
	public function client_admin() {

		if ( is_admin() ) {

			if ( ! class_exists( 'EDD_RI_Client_Admin' ) ) {
				include_once( dirname( __FILE__ ) . '/class-EDD_RI_Client_Admin.php' );
			}

			new EDD_RI_Client_Admin( $this );
		}
	}

	/**
	 * Hook into the API.
	 * Allows us to use URLs from our EDD store.
	 *
	 * @param $api
	 * @param $action
	 * @param $args
	 *
	 * @return stdClass
	 */
	public function plugins_api( $api, $action, $args ) {

		if ( 'plugin_information' == $action ) {

			if ( isset( $_POST[ 'edd_ri' ] ) ) {

				$api_params = array(
					'edd_action' => 'get_download',
					'item_name'  => urlencode( $_POST[ 'name' ] ),
					'license'    => isset( $_POST[ 'license' ] ) ? urlencode( $_POST[ 'license' ] ) : null,
				);

				$api                = new stdClass();
				$api->name          = $args->slug;
				$api->version       = '';
				$api->download_link = esc_url(
					add_query_arg(
						array(
							'edd_action' => 'get_download',
							'item_name' => $api_params[ 'item_name' ],
							'license' => $api_params[ 'license' ],
						),
						$this->api_url
					)
				);
			}
		}

		return $api;
	}

	/**
	 * Tries to install the plugin
	 *
	 * @access public
	 */
	public function install() {
		$this->check_capabilities();

		$download      = $_POST[ 'download' ];
		$license       = $_POST[ 'license' ];
		$message       = __( 'An Error Occurred', 'edd_ri' );
		$download_type = $this->_check_download( $download );

		/**
		 * Throw error of the product is not free and license it empty
		 */
		if ( empty( $download ) || ( empty( $license ) && 'free' !== $download_type ) ) {
			wp_send_json_error( $message );
		}

		/**
		 * Install the plugin if it's free
		 */
		if ( 'free' === $download_type ) {
			$installed = $this->_install_plugin( $download, "" );
			wp_send_json_success( $installed );
		}

		/**
		 * Check for license and then install if it's a valid licens
		 */
		if ( $this->_check_license( $license, $download ) ) {
			$installed = $this->_install_plugin( $download, $license );
			wp_send_json_success( $installed );
		} else {
			wp_send_json_error( __( 'Invalid License', 'edd_ri' ) );
		}
	}

	/**
	 * Check if the user is allowed to install the plugin
	 */
	private function check_capabilities() {
		return ! current_user_can( 'install_plugins' );
	}

	/**
	 * Checks license against API
	 *
	 * @param $license
	 * @param $download
	 *
	 * @return bool
	 */
	private function _check_license( $license, $download ) {

		$api_args = array(
			'edd_action' => 'activate_license',
			'license'    => $license,
			'item_name'  => urlencode( $download ),
		);

		// Get a response from our EDD server
		$response = wp_remote_get( add_query_arg( $api_args, $this->api_url ), array( 'timeout'   => 15,
		                                                                              'sslverify' => false,
		) );

		// make sure the response came back okay
		if ( is_wp_error( $response ) ) {
			return false;
		}

		// decode the license data
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		return $license_data->license === 'valid';

	}

	/**
	 * Literally installs the plugin
	 *
	 * @param $download
	 * @param $license
	 *
	 * @return bool
	 */
	private function _install_plugin( $download, $license ) {

		$api_args = array(
			'edd_action' => 'get_download',
			'item_name'  => urlencode( $download ),
			'license'    => $license,
		);

		$download_link = add_query_arg( $api_args, $this->api_url );

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$upgrader = new Plugin_Upgrader( $skin = new Plugin_Installer_Skin(
			compact( 'type', 'title', 'url', 'nonce', 'plugin', 'api' ) ) );

		return $upgrader->install( $download_link );
	}

	/**
	 * Checks download type
	 *
	 * @param string $download
	 *
	 * @return string free|type2|type3
	 */
	private function _check_download( $download ) {
		// Check the user's capabilities before proceeding
		$this->check_capabilities();

		if ( $this->is_plugin_installed( $download ) ) {
			die( json_encode( __( 'Already Installed', 'edd_ri' ) ) );
		}

		$api_params = array(
			'edd_action' => 'check_download',
			'item_name'  => urlencode( $download ),
		);

		// Send our details to the remote server
		$request  = wp_remote_post( $this->api_url, array(
			'timeout'   => 15,
			'sslverify' => false,
			'body'      => $api_params,
		) );

		$response = 'invalid';

		// There was no error, we can proceed
		if ( ! is_wp_error( $request ) ) {

			$request  = maybe_unserialize( json_decode( wp_remote_retrieve_body( $request ) ) );
			$response = isset( $request->download ) ? $request->download : $response;

		} else {
			// Server was unreachable
			$response = 'Server error';
		}

		return $response;
	}

	/**
	 * Checks if plugin is installed
	 *
	 * @param string $plugin_name
	 *
	 * @return bool
	 */
	public function is_plugin_installed( $plugin_name ) {

		$return = false;

		if ( empty( $plugin_name ) ) {
			return $return;
		}

		foreach ( get_plugins() as $plugin ) {

			if ( $plugin[ 'Name' ] === $plugin_name ) {
				$return = true;
				break;
			}

		}

		return $return;
	}
}
