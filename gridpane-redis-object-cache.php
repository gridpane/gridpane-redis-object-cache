<?php
/*
Plugin Name: GridPane Redis Object Cache
Plugin URI: https://gitlab.gridpane.com/gp-public/gridpane-redis-object-cache
Description: A persistent object cache backend powered by Redis. Supports Predis, PhpRedis, HHVM, replication, clustering and WP-CLI.
Version: 1.5.6
Text Domain: gridpane-redis-object-cache
Domain Path: /languages
Author: Till Krüss | Forked by GridPane
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Forked from Till Krüss Redis Cache plugin when 1.5+ updates to incorporate his new features and PRO stuff started causing selective flush to break.
Added some extra options - flush button in toolbar and option to display cached objects in page footer.

Based on Eric Mann's and Erick Hitter's Redis Object Cache:
https://github.com/ericmann/Redis-Object-Cache
*/


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_REDIS_VERSION', '1.5.5' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once dirname( __FILE__ ) . '/includes/wp-cli-commands.php';
}

class RedisObjectCache {

	private $page;
	private $screen = 'settings_page_gridpane-redis-object-cache';
	private $actions = array( 'enable-cache', 'disable-cache', 'flush-cache', 'update-dropin' );

	public function __construct() {

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		load_plugin_textdomain( 'gridpane-redis-object-cache', false, 'gridpane-redis-object-cache/languages' );

		register_activation_hook( __FILE__, 'wp_cache_flush' );

		$this->page = is_multisite() ? 'settings.php?page=gridpane-redis-object-cache' : 'options-general.php?page=gridpane-redis-object-cache';

		add_action( 'deactivate_plugin', array( $this, 'on_deactivation' ) );

		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'add_admin_menu_page' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'load-' . $this->screen, array( $this, 'do_admin_actions' ) );
		add_action( 'load-' . $this->screen, array( $this, 'add_admin_page_notices' ) );

		add_filter( sprintf(
			'%splugin_action_links_%s',
			is_multisite() ? 'network_admin_' : '',
			plugin_basename( __FILE__ )
		), array( $this, 'add_plugin_actions_links' ) );

	}

	public function add_admin_menu_page() {

		$parent_slug = is_multisite() ? 'settings.php' : 'options-general.php';
		$page_title = __( 'GridPane Redis Object Cache', 'gridpane-redis-object-cache');
		$menu_title = __( 'Redis Object Cache', 'gridpane-redis-object-cache');
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		$menu_slug = 'gridpane-redis-object-cache';
		$callback = array( $this, 'show_admin_page' );

		// add sub-page to "Settings"
		add_submenu_page(
			$parent_slug,
			$page_title,
			$menu_title,
			$capability,
			$menu_slug,
			$callback
		);

		add_action( 'admin_init', array( $this, 'register_redis_object_cache_settings' ) );
		add_action( 'admin_init', array( $this, 'setup_redis_object_cache_sections' ) );

	}

	public function register_redis_object_cache_settings() {

		register_setting( 'gridpane-redis-object-cache-settings', 'gridpane_show_redis_output' );
		register_setting( 'gridpane-redis-object-cache-settings', 'gridpane_add_flush_button' );

	}

	public function setup_redis_object_cache_sections( $arguments ) {
		add_settings_section( 'tools', 'Tools', false, 'gridpane-redis-object-cache-settings' );
		add_settings_field( 'gridpane_add_flush_button', 'Add flush object cache button to admin bar', array( $this, 'admin_bar_field_callback' ), 'gridpane-redis-object-cache-settings', 'tools', array( 'class' => 'plugin_options' )  );
		add_settings_field( 'gridpane_show_redis_output', 'Show cache output in page footer', array( $this, 'footer_field_callback' ), 'gridpane-redis-object-cache-settings', 'tools', array( 'class' => 'plugin_options' ) );
	}

	public function admin_bar_field_callback( $arguments ) {
		echo '<input type="checkbox" name="gridpane_add_flush_button" value="1"' . checked(1, get_option('gridpane_add_flush_button'), false) . ' />';
	}

	public function footer_field_callback( $arguments ) {
		echo '<input type="checkbox" name="gridpane_show_redis_output" value="1"' . checked(1, get_option('gridpane_show_redis_output'), false) . ' />';
	}

	public function show_admin_page() {

		// request filesystem credentials?
		if ( isset( $_GET[ '_wpnonce' ], $_GET[ 'action' ] ) ) {

			$action = $_GET[ 'action' ];

			foreach ( $this->actions as $name ) {

				// verify nonce
				if ( $action === $name && wp_verify_nonce( $_GET[ '_wpnonce' ], $action ) ) {

					$url = wp_nonce_url( network_admin_url( add_query_arg( 'action', $action, $this->page ) ), $action );

					if ( $this->initialize_filesystem( $url ) === false ) {
						return; // request filesystem credentials
					}

				}

			}

		}

		// show admin page
		require_once plugin_dir_path( __FILE__ ) . '/includes/admin-page.php';

	}

	public function show_servers_list() {

		require_once plugin_dir_path( __FILE__ ) . '/includes/servers-list.php';

		$table = new Servers_List;
		$table->prepare_items();
		$table->display();

	}

	public function add_plugin_actions_links( $links ) {

		// add settings link to plugin actions
		return array_merge(
			array( sprintf( '<a href="%s">Settings</a>', network_admin_url( $this->page ) ) ),
			$links
		);

	}

	public function enqueue_admin_styles( $hook_suffix ) {

		if ( $hook_suffix === $this->screen ) {
			wp_enqueue_style( 'gridpane-redis-object-cache', plugin_dir_url( __FILE__ ) . 'includes/admin-page.css', null, WP_REDIS_VERSION );
		}

	}

	public function object_cache_dropin_exists() {
		return file_exists( WP_CONTENT_DIR . '/object-cache.php' );
	}

	public function validate_object_cache_dropin() {

		if ( ! $this->object_cache_dropin_exists() ) {
			return false;
		}

		$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
		$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php' );

		if ( strcmp( $dropin[ 'PluginURI' ], $plugin[ 'PluginURI' ] ) !== 0 ) {
			return false;
		}

		return true;

	}

	public function get_status() {

		if (
			! $this->object_cache_dropin_exists() ||
			( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED )
		) {
			return __( 'Disabled', 'gridpane-redis-object-cache' );
		}

		if ( $this->validate_object_cache_dropin() ) {
			if ( $this->get_redis_status() ) {
				return __( 'Connected', 'gridpane-redis-object-cache' );
			}

			if ( $this->get_redis_status() === false ) {
				return __( 'Not Connected', 'gridpane-redis-object-cache' );
			}
		}

		return __( 'Unknown', 'gridpane-redis-object-cache' );

	}

	public function get_redis_status() {

		global $wp_object_cache;

		if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
			return;
		}

		if ( $this->validate_object_cache_dropin() ) {
			return $wp_object_cache->redis_status();
		}

		return;

	}

	public function get_redis_version() {

		global $wp_object_cache;

		if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
			return;
		}

		if ( $this->validate_object_cache_dropin() && method_exists( $wp_object_cache, 'redis_version' ) ) {
			return $wp_object_cache->redis_version();
		}

	}

	public function get_redis_client_name() {

		global $wp_object_cache;

		if ( isset( $wp_object_cache->redis_client ) ) {
			return $wp_object_cache->redis_client;
		}

		if ( defined( 'WP_REDIS_CLIENT' ) ) {
			return WP_REDIS_CLIENT;
		}

	}

	public function get_redis_cachekey_prefix() {
		return defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : null;
	}

	public function get_redis_maxttl() {
		return defined( 'WP_REDIS_MAXTTL' ) ? WP_REDIS_MAXTTL : null;
	}

	public function show_admin_notices() {

		// only show admin notices to users with the right capability
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			return;
		}

		if ( $this->object_cache_dropin_exists() ) {

			$url = wp_nonce_url( network_admin_url( add_query_arg( 'action', 'update-dropin', $this->page ) ), 'update-dropin' );

			if ( $this->validate_object_cache_dropin() ) {

				$dropin = get_plugin_data( WP_CONTENT_DIR . '/object-cache.php' );
				$plugin = get_plugin_data( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php' );

				if ( version_compare( $dropin[ 'Version' ], $plugin[ 'Version' ], '<' ) ) {
					$message = sprintf( __( 'The Redis object cache drop-in is outdated. Please <a href="%s">update it now</a>.', 'gridpane-redis-object-cache' ), $url );
				}

			} else {

				$message = sprintf( __( 'An unknown object cache drop-in was found. To use Redis, <a href="%s">please replace it now</a>.', 'gridpane-redis-object-cache' ), $url );

			}

			if ( isset( $message ) ) {
				printf( '<div class="update-nag">%s</div>', $message );
			}

		}

	}

	public function add_admin_page_notices() {

		// show PHP version warning
		if ( version_compare( PHP_VERSION, '5.4.0', '<' ) ) {
			add_settings_error( '', 'gridpane-redis-object-cache', __( 'This plugin requires PHP 5.4 or greater.', 'gridpane-redis-object-cache' ) );
		}

		// show action success/failure messages
		if ( isset( $_GET[ 'message' ] ) ) {

			switch ( $_GET[ 'message' ] ) {

				case 'cache-enabled':
					$message = __( 'Object cache enabled.', 'gridpane-redis-object-cache' );
					break;
				case 'enable-cache-failed':
					$error = __( 'Object cache could not be enabled.', 'gridpane-redis-object-cache' );
					break;
				case 'cache-disabled':
					$message = __( 'Object cache disabled.', 'gridpane-redis-object-cache' );
					break;
				case 'disable-cache-failed':
					$error = __( 'Object cache could not be disabled.', 'gridpane-redis-object-cache' );
					break;
				case 'cache-flushed':
					$message = __( 'Object cache flushed.', 'gridpane-redis-object-cache' );
					break;
				case 'flush-cache-failed':
					$error = __( 'Object cache could not be flushed.', 'gridpane-redis-object-cache' );
					break;
				case 'dropin-updated':
					$message = __( 'Updated object cache drop-in and enabled Redis object cache.', 'gridpane-redis-object-cache' );
					break;
				case 'update-dropin-failed':
					$error = __( 'Object cache drop-in could not be updated.', 'gridpane-redis-object-cache' );
					break;

			}

			add_settings_error( '', 'gridpane-redis-object-cache', isset( $message ) ? $message : $error, isset( $message ) ? 'updated' : 'error' );

		}

	}

	public function do_admin_actions() {

		global $wp_filesystem;

		if ( isset( $_GET[ '_wpnonce' ], $_GET[ 'action' ] ) ) {

			$action = $_GET[ 'action' ];

			// verify nonce
			foreach ( $this->actions as $name ) {
				if ( $action === $name && ! wp_verify_nonce( $_GET[ '_wpnonce' ], $action ) ) {
					return;
				}
			}

			if ( in_array( $action, $this->actions ) ) {

				$url = wp_nonce_url( network_admin_url( add_query_arg( 'action', $action, $this->page ) ), $action );

				if ( $action === 'flush-cache' ) {
					$message = wp_cache_flush() ? 'cache-flushed' : 'flush-cache-failed';
				}

				// do we have filesystem credentials?
				if ( $this->initialize_filesystem( $url, true ) ) {

					switch ( $action ) {

						case 'enable-cache':
							$result = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
							do_action( 'redis_object_cache_enable', $result );
							$message = $result ? 'cache-enabled' : 'enable-cache-failed';
							break;

						case 'disable-cache':
							$result = $wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
							do_action( 'redis_object_cache_disable', $result );
							update_option( 'gridpane_add_flush_button', 0 );
							update_option( 'gridpane_show_redis_output', 0 );
							$message = $result ? 'cache-disabled' : 'disable-cache-failed';
							break;

						case 'update-dropin':
							$result = $wp_filesystem->copy( plugin_dir_path( __FILE__ ) . '/includes/object-cache.php', WP_CONTENT_DIR . '/object-cache.php', true );
							do_action( 'redis_object_cache_update_dropin', $result );
							$message = $result ? 'dropin-updated' : 'update-dropin-failed';
							break;

					}

				}

				// redirect if status `$message` was set
				if ( isset( $message ) ) {
					wp_safe_redirect( network_admin_url( add_query_arg( 'message', $message, $this->page ) ) );
					exit;
				}

			}

		}

	}


	public function initialize_filesystem( $url, $silent = false ) {

		if ( $silent ) {
			ob_start();
		}

		if ( ( $credentials = request_filesystem_credentials( $url ) ) === false ) {

			if ( $silent ) {
				ob_end_clean();
			}

			return false;

		}

		if ( ! WP_Filesystem( $credentials ) ) {

			request_filesystem_credentials( $url );

			if ( $silent ) {
				ob_end_clean();
			}

			return false;

		}

		return true;

	}

	public function on_deactivation( $plugin ) {

		global $wp_filesystem;

		if ( $plugin === plugin_basename( __FILE__ ) ) {

			wp_cache_flush();

			if ( $this->validate_object_cache_dropin() && $this->initialize_filesystem( '', true ) ) {
				$wp_filesystem->delete( WP_CONTENT_DIR . '/object-cache.php' );
			}

		}

	}

}

$GLOBALS[ 'redisObjectCache' ] = new RedisObjectCache;

if (get_option('gridpane_add_flush_button')) {

	if ( is_multisite() ) {
		return;
	}

	if ( function_exists( 'wp_cache_flush' ) ) {
		add_action( 'admin_bar_menu', 'flush_object_cache_button', 100 );
	}

}

function flush_object_cache_button( $wp_admin_bar ) {

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['flush-cache-button'] )
	     && 'flush' === $_GET['flush-cache-button']
	     && wp_verify_nonce( $_GET['_wpnonce'], 'flush-cache-button' )
	) {
		wp_cache_flush();
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-success is-dismissible"><p>Object Cache flushed.</p></div>';
		} );
	}

	$dashboard_url = admin_url( add_query_arg( 'flush-cache-button', 'flush', 'index.php' ) );
	$args = array(
		'id'    => 'flush_cache_button',
		'title' => 'Flush Object Cache',
		'href'  => wp_nonce_url( $dashboard_url, 'flush-cache-button' ),
		'meta'  => array( 'class' => 'flush-cache-button' )
	);
	$wp_admin_bar->add_node( $args );
}

if (get_option('gridpane_show_redis_output')) {

	if ( is_multisite() ) {
		return;
	}

	add_action( 'wp_footer', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$GLOBALS['wp_object_cache']->stats();
	} );

}


