<?php

/**
 * Plugin Name: WP Spider Cache
 * Plugin URI:  https://wordpress.org/plugins/wp-spider-cache/
 * Author:      John James Jacoby
 * Author URI:  https://profiles.wordpress.org/johnjamesjacoby/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Description: Your friendly neighborhood caching solution for WordPress
 * Version:     2.1.1
 * Text Domain: wp-spider-cache
 * Domain Path: /assets/lang/
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * The main Spider-Cache admin interface
 *
 * @since 2.0.0
 */
class WP_Spider_Cache_UI {

	/**
	 * The URL used for assets
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $url = '';

	/**
	 * The version used for assets
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	private $version = '201602150003';

	/**
	 * Nonce ID for getting the Memcached instance
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $get_instance_nonce = 'sc-get_instance';

	/**
	 * Nonce ID for flushing a Memcache group
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $flush_group_nonce = 'sc-flush_group';

	/**
	 * Nonce ID for removing an item from Memcache
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $remove_item_nonce = 'sc-remove_item';

	/**
	 * Nonce ID for retrieving an item from Memcache
	 *
	 * @since 2.0.0
	 *
	 * @var string
	 */
	public $get_item_nonce = 'sc-get_item';

	/**
	 * The main constructor
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		// Setup the plugin URL, for enqueues
		$this->url = plugin_dir_url( __FILE__ );

		// Notices
		add_action( 'spider_cache_notice', array( $this, 'notice' ) );

		// Admin area UI
		add_action( 'admin_menu',            array( $this, 'admin_menu'    ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		// AJAX
		add_action( 'wp_ajax_sc-get-item',     array( $this, 'ajax_get_mc_item'     ) );
		add_action( 'wp_ajax_sc-get-instance', array( $this, 'ajax_get_mc_instance' ) );
		add_action( 'wp_ajax_sc-flush-group',  array( $this, 'ajax_flush_mc_group'  ) );
		add_action( 'wp_ajax_sc-remove-item',  array( $this, 'ajax_remove_mc_item'  ) );
	}

	/**
	 * Add the top-level admin menu
	 *
	 * @since 2.0.0
	 */
	public function admin_menu() {

		// Add menu page
		$this->hook = add_menu_page(
			esc_html__( 'Spider Cache', 'wp-spider-cache' ),
			esc_html__( 'Spider Cache', 'wp-spider-cache' ),
			'manage_cache', // Single-site admins and multi-site super admins
			'wp-spider-cache',
			array( $this, 'page' ),
			'dashicons-editor-code'
		);

		// Load page on hook
		add_action( "load-{$this->hook}", array( $this, 'load' ) );
		add_action( "load-{$this->hook}", array( $this, 'help' ) );
	}

	/**
	 * Enqueue assets
	 *
	 * @since 2.0.0
	 */
	public function admin_enqueue() {

		// Bail if not this page
		if ( $GLOBALS['page_hook'] !== $this->hook ) {
			return;
		}

		// Enqueue
		wp_enqueue_style( 'wp-spider-cache', $this->url . 'assets/css/spider-cache.css', array(),          $this->version );
		wp_enqueue_script( 'wp-spider-cache', $this->url . 'assets/js/spider-cache.js', array( 'jquery' ), $this->version, true );

		// Localize JS
		wp_localize_script( 'wp-spider-cache', 'WP_Spider_Cache', array(
			'no_results'         => $this->get_no_results_row(),
			'refreshing_results' => $this->get_refreshing_results_row()
		) );
	}

	/**
	 * Maybe clear a cache group, based on user request
	 *
	 * @since 2.0.0
	 *
	 * @param bool $redirect
	 */
	private function maybe_clear_cache_group( $redirect = true ) {

		// Bail if not clearing
		if ( empty( $_GET['cache_group'] ) ) {
			return;
		}

		// Clear the cache group
		$cleared = $this->clear_group( $_GET['cache_group'] );

		// Bail if not redirecting
		if ( false === $redirect ) {
			return;
		}

		// Assemble the URL
		$url = add_query_arg( array(
			'keys_cleared'  => $cleared,
			'cache_cleared' => $_GET['cache_group']
		), menu_page_url( 'wp-spider-cache', false ) );

		// Redirect
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Maybe clear a user's entire cache, based on user request
	 *
	 * @since 2.0.0
	 *
	 * @param bool $redirect
	 */
	private function maybe_clear_user_cache( $redirect = true ) {

		// Clear user ID
		if ( empty( $_GET['user_id'] ) ) {
			return;
		}

		// How are we getting the user?
		if ( is_numeric( $_GET['user_id'] ) ) {
			$by = 'id';
		} elseif ( is_email( $_GET['user_id'] ) ) {
			$by = 'email';
		} elseif ( is_string( $_GET['user_id'] ) ) {
			$by = 'slug';
		} else {
			$by = 'login';
		}

		// Get the user
		$_user = get_user_by( $by, $_GET['user_id'] );

		// Bail if no user found
		if ( empty( $_user ) ) {
			return;
		}

		// Delete user caches
		wp_cache_delete( $_user->ID,            'users'      );
		wp_cache_delete( $_user->ID,            'user_meta'  );
		wp_cache_delete( $_user->user_login,    'userlogins' );
		wp_cache_delete( $_user->user_nicename, 'userslugs'  );
		wp_cache_delete( $_user->user_email,    'useremail'  );

		// Bail if not redirecting
		if ( false === $redirect ) {
			return;
		}

		// Assemble the URL
		$url = add_query_arg( array(
			'keys_cleared'  => '2',
			'cache_cleared' => $_user->ID
		), menu_page_url( 'wp-spider-cache', false ) );

		// Redirect
		wp_safe_redirect( $url );
		exit();
	}

	/**
	 * Helper function to check nonce and avoid caching the request
	 *
	 * @since 2.0.0
	 *
	 * @param string $nonce
	 */
	private function check_nonce( $nonce = '' ) {
		check_ajax_referer( $nonce , 'nonce' );

		nocache_headers();
	}

	/**
	 * Attempt to output the server cache contents
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_mc_instance() {
		$this->check_nonce( $this->get_instance_nonce );

		// Attempt to output the server contents
		if ( ! empty( $_POST['name'] ) ) {
			$server = filter_var( $_POST['name'], FILTER_VALIDATE_IP );
			$this->do_rows( $server );
		}

		exit();
	}

	/**
	 * Delete all cache keys in a cache group
	 *
	 * @since 2.0.0
	 */
	public function ajax_flush_mc_group() {
		$this->check_nonce( $this->flush_group_nonce );

		// Loop through keys and attempt to delete them
		if ( ! empty( $_POST['keys'] ) && ! empty( $_GET['group'] ) ) {
			foreach ( $_POST['keys'] as $key ) {
				wp_cache_delete(
					$this->sanitize_key( $key           ),
					$this->sanitize_key( $_GET['group'] )
				);
			}
		}

		exit();
	}

	/**
	 * Delete a single cache key in a specific group
	 *
	 * @since 2.0.0
	 */
	public function ajax_remove_mc_item() {
		$this->check_nonce( $this->remove_item_nonce );

		// Delete a key in a group
		if ( ! empty( $_GET['key'] ) && ! empty( $_GET['group'] ) ) {
			wp_cache_delete(
				$this->sanitize_key( $_GET['key']   ),
				$this->sanitize_key( $_GET['group'] )
			);
		}

		exit();
	}

	/**
	 * Attempt to get a cached item
	 *
	 * @since 2.0.0
	 */
	public function ajax_get_mc_item() {
		$this->check_nonce( $this->get_item_nonce );

		// Bail if invalid posted data
		if ( ! empty( $_GET['key'] ) && ! empty( $_GET['group'] ) ) {
			$this->do_item(
				$this->sanitize_key( $_GET['key']   ),
				$this->sanitize_key( $_GET['group'] )
			);
		}

		exit();
	}

	/**
	 * Clear all of the items in a cache group
	 *
	 * @since 2.0.0
	 *
	 * @param string $group
	 * @return int
	 */
	public function clear_group( $group = '' ) {

		// Setup counter
		$cleared = 0;
		$servers = $this->get_servers();

		// Loop through servers
		foreach ( $servers as $server ) {
			$port = empty( $server[1] ) ? 11211 : $server['port'];
			$list = $this->retrieve_keys( $server['host'], $port );

			// Loop through items
			foreach ( $list as $item ) {
				if ( strstr( $item, "{$group}:" ) ) {
					wp_cache_delete( $item );
					$cleared++;
				}
			}
		}

		// Return count
		return $cleared;
	}

	/**
	 * Check for actions
	 *
	 * @since 2.0.0
	 */
	public function load() {
		$this->maybe_clear_cache_group( true );
		$this->maybe_clear_user_cache( true );
	}

	/**
	 * Help text
	 *
	 * @since 2.1.0
	 */
	public function help() {

		// Overview
		get_current_screen()->add_help_tab( array(
			'id'      => 'overview',
			'title'   => esc_html__( 'Overview', 'wp-spider-cache' ),
			'content' =>
				'<p>' . esc_html__( 'All the cached objects and output is listed alphabetically in Spider Cache, starting with global groups and ending with this specific site.',   'wp-spider-cache' ) . '</p>' .
				'<p>' . esc_html__( 'You can narrow the list by searching for specific group & key names.', 'wp-spider-cache' ) . '</p>'
		) );

		// Using cache key salt
		if ( defined( 'WP_CACHE_KEY_SALT' ) && ! empty( WP_CACHE_KEY_SALT ) ) {
			get_current_screen()->add_help_tab( array(
				'id'      => 'salt',
				'title'   => esc_html__( 'Cache Key', 'wp-spider-cache' ),
				'content' =>
					'<p>' . sprintf( esc_html__( 'A Cache Key Salt was identified: %s', 'wp-spider-cache' ), '<code>' . WP_CACHE_KEY_SALT . '</code>' ) . '</p>' .
					'<p>' . __( 'This advanced configuration option is usually defined in <code>wp-config.php</code> and is commonly used as a way to invalidate all cached data for the entire installation by updating the value of the <code>WP_CACHE_KEY_SALT</code> constant.', 'wp-spider-cache' ) . '</p>'
			) );
		}

		// Servers
		get_current_screen()->add_help_tab( array(
			'id'      => 'servers',
			'title'   => esc_html__( 'Servers', 'wp-spider-cache' ),
			'content' =>
				'<p>' . esc_html__( 'Choose a registered cache server from the list. Content from that server is automatically retrieved & presented in the table.', 'wp-spider-cache' ) . '</p>' .
				'<p>' . esc_html__( 'It is possible to have more than one cache server, and each server may have different cached content available to it.', 'wp-spider-cache' ) . '</p>' .
				'<p>' . esc_html__( 'Clicking "Refresh" will fetch fresh data from the selected server, and repopulate the table.', 'wp-spider-cache' ) . '</p>'
		) );

		// Screen Content
		get_current_screen()->add_help_tab( array(
			'id'		=> 'content',
			'title'		=> __( 'Screen Content', 'wp-spider-cache' ),
			'content'	=>
				'<p>'  . esc_html__( 'Cached content is displayed in the following way:', 'wp-spider-cache' ) . '</p><ul>' .
				'<li>' . esc_html__( 'Cache groups are listed alphabetically.', 'wp-spider-cache' ) . '</li>' .
				'<li>' . esc_html__( 'Global cache groups will be shown first.', 'wp-spider-cache' ) . '</li>' .
				'<li>' . esc_html__( 'Cache groups for this specific site are shown last.', 'wp-spider-cache' ) . '</li></ul>'
		) );

		// Available actions
		get_current_screen()->add_help_tab( array(
			'id'      => 'actions',
			'title'   => __( 'Available Actions', 'wp-spider-cache' ),
			'content' =>
				'<p>'  . esc_html__( 'Hovering over a row in the list will display action links that allow you to manage that content. You can perform the following actions:', 'wp-spider-cache' ) . '</strong></p><ul>' .
				'<li>' .         __( '<strong>Search</strong> for content within the list.', 'wp-spider-cache' ) . '</li>' .
				'<li>' .         __( '<strong>Clear</strong> many caches at the same time, by group or user ID.', 'wp-spider-cache' ) . '</li>' .
				'<li>' .         __( '<strong>Flush</strong> an entire cache group to remove all of the subsequent keys.', 'wp-spider-cache' ) . '</li>' .
				'<li>' .         __( '<strong>Remove</strong> a single cache key from within a cache group.', 'wp-spider-cache' ) . '</li>' .
				'<li>' .         __( '<strong>View</strong> the contents of a single cache key.', 'wp-spider-cache' ) . '</li></ul>'
		) );

		// Help Sidebar
		get_current_screen()->set_help_sidebar(
			'<p><i class="dashicons dashicons-wordpress"></i> '     . esc_html__( 'Blog ID',     'wp-spider-cache' ) . '</p>' .
			'<p><i class="dashicons dashicons-admin-site"></i> '    . esc_html__( 'Cache Group', 'wp-spider-cache' ) . '</p>' .
			'<p><i class="dashicons dashicons-admin-network"></i> ' . esc_html__( 'Keys',        'wp-spider-cache' ) . '</p>' .
			'<p><i class="dashicons dashicons-editor-code"></i> '   . esc_html__( 'Count',       'wp-spider-cache' ) . '</p>'
		);
	}

	/**
	 * Get all cache keys on a server
	 *
	 * @since 2.0.0
	 *
	 * @param  string $server
	 * @param  int    $port
	 *
	 * @return array
	 */
	public function retrieve_keys( $server, $port = 11211 ) {

		// Connect to Memcache
		$memcache = new Memcache();
		$memcache->connect( $server, $port );

		// No errors
		$old_errors = error_reporting( 0 );

		// Get slabs
		$slabs = $memcache->getExtendedStats( 'slabs' );
		$list  = array();

		// Loop through servers to get slabs
		foreach ( $slabs as $server => $slabs ) {

			// Loop through slabs to target single slabs
			foreach ( array_keys( $slabs ) as $slab_id ) {

				// Skip if slab ID is empty
				if ( empty( $slab_id ) ) {
					continue;
				}

				// Get the entire slab
				$cache_dump = $memcache->getExtendedStats( 'cachedump', (int) $slab_id );

				// Loop through slab to find keys
				foreach ( $cache_dump as $slab_dump ) {

					// Skip if key isn't an array (how'd that happen?)
					if ( ! is_array( $slab_dump ) ) {
						continue;
					}

					// Loop through keys and add to list
					foreach( array_keys( $slab_dump ) as $k ) {
						$list[] = $k;
					}
				}
			}
		}

		// Restore error reporting
		error_reporting( $old_errors );

		// Return the list of Memcache server slab keys
		return $list;
	}

	/**
	 * Output the contents of a cached item into a textarea
	 *
	 * @since 2.0.0
	 *
	 * @param  string  $key
	 * @param  string  $group
	 */
	public function do_item( $key, $group ) {

		// Get results directly from Memcached
		$cache   = wp_cache_get( $key, $group );
		$full    = wp_cache_get_key( $key, $group );
		$code    = wp_cache_get_result_code();
		$message = wp_cache_get_result_message();

		// @todo Something prettier with cached value
		$value   = is_array( $cache ) || is_object( $cache )
			? serialize( $cache )
			: $cache;

		// Not found?
		if ( false === $value ) {
			$value = 'ERR';
		}

		// Combine results
		$results =
			sprintf( __( 'Key:     %s',      'wp-spider-cache' ), $key            ) . "\n" .
			sprintf( __( 'Group:   %s',      'wp-spider-cache' ), $group          ) . "\n" .
			sprintf( __( 'Full:    %s',      'wp-spider-cache' ), $full           ) . "\n" .
			sprintf( __( 'Code:    %s - %s', 'wp-spider-cache' ), $code, $message ) . "\n" .
			sprintf( __( 'Value:   %s',      'wp-spider-cache' ), $value          ); ?>

		<textarea class="sc-item" class="widefat" rows="10" cols="35"><?php echo $results; ?></textarea>

		<?php
	}

	/**
	 * Output a link used to flush an entire cache group
	 *
	 * @since 0.2.0
	 *
	 * @param int    $blog_id
	 * @param string $group
	 * @param string $nonce
	 */
	private function get_flush_group_link( $blog_id, $group, $nonce ) {

		// Setup the URL
		$url = add_query_arg( array(
			'action'  => 'sc-flush-group',
			'blog_id' => $blog_id,
			'group'   => $group,
			'nonce'   => $nonce
		), admin_url( 'admin-ajax.php' ) );

		// Start the output buffer
		ob_start(); ?>

		<a class="sc-flush-group" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Flush Group', 'wp-spider-cache' ); ?></a>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Get the map of cache groups $ keys
	 *
	 * The keymap is limited to global keys and keys to the current site. This
	 * is because cache keys are built inside the WP_Object_Cache class, and
	 * are occasionally prefixed with the current blog ID, meaning we cannot
	 * reliably ask the memcache server for data without a way to force the key.
	 *
	 * Maybe in a future version of WP_Object_Cache, a method to retrieve a raw
	 * value based on a full cache key will exist. Until then, no bueno.
	 *
	 * @since 2.0.0
	 *
	 * @param  string $server
	 * @return array
	 */
	private function get_keymaps( $server = '' ) {

		// Use current blog ID to limit keymap scope
		$current_blog_id = get_current_blog_id();

		// Set an empty keymap array
		$keymaps = array();
		$offset  = 0;

		// Offset by 1 if using cache-key salt
		if ( wp_object_cache()->cache_key_salt ) {
			$offset = 1;
		}

		// Get keys for this server and loop through them
		foreach ( $this->retrieve_keys( $server ) as $item ) {

			// Skip if CLIENT_ERROR or malforwed [sic]
			if ( empty( $item ) || ! strstr( $item, ':' ) ) {
				continue;
			}

			// Separate the item into parts
			$parts = explode( ':', $item );

			// Remove key salts
			if ( $offset > 0 ) {
				$parts = array_slice( $parts, $offset );
			}

			// Multisite means first part is numeric
			if ( is_numeric( $parts[ 0 ] ) ) {
				$blog_id = (int) $parts[ 0 ];
				$group   = $parts[ 1 ];
				$global  = false;

			// Single site or global cache group
			} else {
				$blog_id = 0;
				$group   = $parts[ 0 ];
				$global  = true;
			}

			// Only show global keys and keys for this site
			if ( ! empty( $blog_id ) && ( $blog_id !== $current_blog_id ) ) {
				continue;
			}

			// Build the cache key based on number of parts
			if ( ( count( $parts ) === 1 ) ) {
				$key = $parts[ 0 ];
			} else {
				if ( true === $global ) {
					$key = implode( ':', array_slice( $parts, 1 ) );
				} else {
					$key = implode( ':', array_slice( $parts, 2 ) );
				}
			}

			// Build group key by combining blog ID & group
			$group_key = $blog_id . $group;

			// Build the keymap
			if ( isset( $keymaps[ $group_key ] ) ) {
				$keymaps[ $group_key ]['keys'][] = $key;
			} else {
				$keymaps[ $group_key ] = array(
					'blog_id' => $blog_id,
					'group'   => $group,
					'keys'    => array( $key ),
					'item'    => $item
				);
			}
		}

		// Sort the keymaps by key
		ksort( $keymaps );

		return $keymaps;
	}

	/**
	 * Output contents of cache group keys
	 *
	 * @since 2.0.0
	 *
	 * @param int    $blog_id
	 * @param string $group
	 * @param array  $keys
	 */
	private function get_cache_key_links( $blog_id = 0, $group = '', $keys = array() ) {

		// Setup variables used in the loop
		$remove_item_nonce = wp_create_nonce( $this->remove_item_nonce );
		$get_item_nonce    = wp_create_nonce( $this->get_item_nonce    );
		$admin_url         = admin_url( 'admin-ajax.php' );

		// Start the output buffer
		ob_start();

		// Loop through keys and output data & action links
		foreach ( $keys as $key ) :

			// Get URL
			$get_url = add_query_arg( array(
				'blog_id' => $blog_id,
				'group'   => $group,
				'key'     => $key,
				'action'  => 'sc-get-item',
				'nonce'   => $get_item_nonce,
			), $admin_url );

			// Maybe include the blog ID in the group
			$include_blog_id = ! empty( $blog_id )
				? "{$blog_id}:{$group}"
				: $group;

			// Remove URL
			$remove_url = add_query_arg( array(
				'group'   => $include_blog_id,
				'key'     => $key,
				'action'  => 'sc-remove-item',
				'nonce'   => $remove_item_nonce
			), $admin_url ); ?>

			<div class="item" data-key="<?php echo esc_attr( $key ); ?>">
				<code><?php echo implode( '</code> : <code>', explode( ':', $key ) ); ?></code>
				<div class="row-actions">
					<span class="trash">
						<a class="sc-remove-item" href="<?php echo esc_url( $remove_url ); ?>"><?php esc_html_e( 'Remove', 'wp-spider-cache' ); ?></a>
					</span>
					| <a class="sc-view-item" href="<?php echo esc_url( $get_url ); ?>"><?php esc_html_e( 'View', 'wp-spider-cache' ); ?></a>
				</div>
			</div>

			<?php
		endforeach;

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Output the WordPress admin page
	 *
	 * @since 2.0.0
	 */
	public function page() {
		$servers            = $this->get_servers();
		$get_instance_nonce = wp_create_nonce( $this->get_instance_nonce ); ?>

		<div class="wrap spider-cache" id="sc-wrapper">
			<h2><?php esc_html_e( 'Spider Cache', 'wp-spider-cache' ); ?></h2>

			<?php do_action( 'spider_cache_notice' ); ?>

			<div class="wp-filter">
				<div class="sc-toolbar-secondary">
					<select class="sc-server-selector" data-nonce="<?php echo $get_instance_nonce ?>">
						<option value=""><?php esc_html_e( 'Select a Server', 'wp-spider-cache' ); ?></option><?php

						// Loop through servers
						foreach ( $servers as $server ) :

							?><option value="<?php echo esc_attr( $server['host'] ); ?>"><?php echo esc_html( $server['host'] ); ?></option><?php

						endforeach;

					?></select>
					<button class="button action sc-refresh-instance" disabled><?php esc_html_e( 'Refresh', 'wp-spider-cache' ); ?></button>
				</div>
				<div class="sc-toolbar-primary search-form">
					<label for="sc-search-input" class="screen-reader-text"><?php esc_html_e( 'Search Cache', 'wp-spider-cache' ); ?></label>
					<input type="search" placeholder="<?php esc_html_e( 'Search', 'wp-spider-cache' ); ?>" id="sc-search-input" class="search">
				</div>
			</div>

			<div id="sc-show-item"></div>

			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<?php echo $this->bulk_actions(); ?>
				</div>
				<div class="alignright">
					<form action="<?php menu_page_url( 'wp-spider-cache' ) ?>" method="get">
						<input type="hidden" name="page" value="wp-spider-cache">
						<input type="text" name="cache_group" />
						<button class="button"><?php esc_html_e( 'Clear Cache Group', 'wp-spider-cache' ); ?></button>
					</form>
					<form action="<?php menu_page_url( 'wp-spider-cache' ) ?>" method="get">
						<input type="hidden" name="page" value="wp-spider-cache">
						<input type="text" name="user_id" />
						<button class="button"><?php esc_html_e( 'Clear User Cache', 'wp-spider-cache' ); ?></button>
					</form>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped posts">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="cb-select-all-1"><?php esc_html_e( 'Select All', 'wp-spider-cache' ); ?></label>
							<input id="cb-select-all-1" type="checkbox">
						</td>
						<th class="blog-id"><?php esc_html_e( 'Blog ID', 'wp-spider-cache' ); ?></th>
						<th class="cache-group"><?php esc_html_e( 'Cache Group', 'wp-spider-cache' ); ?></th>
						<th class="keys"><?php esc_html_e( 'Keys', 'wp-spider-cache' ); ?></th>
						<th class="count"><?php esc_html_e( 'Count', 'wp-spider-cache' ); ?></th>
					</tr>
				</thead>

				<tbody class="sc-contents">
					<?php echo $this->get_no_results_row(); ?>
				</tbody>

				<tfoot>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="cb-select-all-2"><?php esc_html_e( 'Select All', 'wp-spider-cache' ); ?></label>
							<input id="cb-select-all-2" type="checkbox">
						</td>
						<th class="blog-id"><?php esc_html_e( 'Blog ID', 'wp-spider-cache' ); ?></th>
						<th class="cache-group"><?php esc_html_e( 'Cache Group', 'wp-spider-cache' ); ?></th>
						<th class="keys"><?php esc_html_e( 'Keys', 'wp-spider-cache' ); ?></th>
						<th class="count"><?php esc_html_e( 'Count', 'wp-spider-cache' ); ?></th>
					</tr>
				</tfoot>
			</table>
		</div>

	<?php
	}

	/**
	 * Return the bulk actions dropdown
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function bulk_actions() {

		// Start an output buffer
		ob_start(); ?>

		<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'wp-spider-cache' ); ?></label>
		<select name="action" id="bulk-action-selector-top">
			<option value="-1"><?php esc_html_e( 'Bulk Actions', 'wp-spider-cache' ); ?></option>
			<option value="edit" class="hide-if-no-js"><?php esc_html_e( 'Flush Groups', 'wp-spider-cache' ); ?></option>
		</select>
		<input type="submit" id="doaction" class="button action" value="<?php esc_html_e( 'Apply', 'wp-spider-cache' ); ?>">

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Output the Memcache server contents in a table
	 *
	 * @since 2.0.0
	 *
	 * @param string $server
	 */
	public function do_rows( $server = '' ) {

		// Setup the nonce
		$flush_group_nonce = wp_create_nonce( $this->flush_group_nonce );

		// Get server key map & output groups in rows
		foreach ( $this->get_keymaps( $server ) as $values ) {
			$this->do_row( $values, $flush_group_nonce );
		}
	}

	/**
	 * Output a table row based on values
	 *
	 * @since 2.0.0
	 *
	 * @param  array   $values
	 * @param  string  $flush_group_nonce
	 */
	private function do_row( $values = array(), $flush_group_nonce = '' ) {
		?>

		<tr>
			<th scope="row" class="check-column">
				<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $values['group'] ); ?>" id="checkbox_<?php echo esc_attr( $values['group'] ); ?>">
				<label class="screen-reader-text" for="checkbox_<?php echo esc_attr( $values['group'] ); ?>"><?php esc_html_e( 'Select', 'wp-spider-cache' ); ?></label>
			</th>
			<td>
				<code><?php echo esc_html( $values['blog_id'] ); ?></code>
			</td>
			<td>
				<span class="row-title"><?php echo esc_html( $values['group'] ); ?></span>
				<div class="row-actions"><span class="trash"><?php echo $this->get_flush_group_link( $values['blog_id'], $values['group'], $flush_group_nonce ); ?></span></div>
			</td>
			<td>
				<?php echo $this->get_cache_key_links( $values['blog_id'], $values['group'], $values['keys'] ); ?>
			</td>
			<td>
				<?php echo number_format_i18n( count( $values['keys'] ) ); ?>
			</td>
		</tr>

	<?php
	}

	/**
	 * Returns a table row used to show no results were found
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function get_no_results_row() {

		// Buffer
		ob_start(); ?>

		<tr class="sc-no-results">
			<td colspan="5">
				<?php esc_html_e( 'No results found.', 'wp-spider-cache' ); ?>
			</td>
		</tr>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Returns a table row used to show results are loading
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private function get_refreshing_results_row() {

		// Buffer
		ob_start(); ?>

		<tr class="sc-refreshing-results">
			<td colspan="5">
				<?php esc_html_e( 'Refreshing...', 'wp-spider-cache' ); ?>
			</td>
		</tr>

		<?php

		// Return the output buffer
		return ob_get_clean();
	}

	/**
	 * Return list of servers, if function exists
	 *
	 * @since 2.1.1
	 *
	 * @return array
	 */
	private function get_servers() {
		return function_exists( 'wp_cache_get_server_list' )
			? wp_cache_get_server_list()
			: array();
	}

	/**
	 * Sanitize a user submitted cache group or key value
	 *
	 * This strips out unwanted and/or unexpected characters from cache keys
	 * and groups.
	 *
	 * @since 2.1.2
	 *
	 * @param  string  $key
	 *
	 * @return string
	 */
	private function sanitize_key( $key = '' ) {
		return preg_replace( '/[^a-z0-9:_\-]/', '', $key );
	}

	/**
	 * Maybe output a notice to the user that action has taken place
	 *
	 * @since 2.0.0
	 */
	public function notice() {

		// Default status & message
		$status  = 'notice-warning';
		$message = '';

		// Bail if no notice
		if ( isset( $_GET['cache_cleared'] ) ) {

			// Cleared
			$keys = isset( $_GET['keys_cleared'] )
				? (int) $_GET['keys_cleared']
				: 0;

			// Cache
			$cache = isset( $_GET['cache_cleared'] )
				? $_GET['cache_cleared']
				: 'none returned';

			// Success
			$status = 'notice-success';

			// Assemble the message
			$message = sprintf(
				esc_html__( 'Cleared %s keys from %s group(s).', 'wp-spider-cache' ),
				'<strong>' . esc_html( $keys  ) . '</strong>',
				'<strong>' . esc_html( $cache ) . '</strong>'
			);
		}

		// No Memcached
		if ( ! class_exists( 'Memcached' ) ) {
			$message = esc_html__( 'Please install the Memcached extension.', 'wp-spider-cache' );
		}

		// No object cache
		if ( ! function_exists( 'wp_object_cache' ) ) {
			$message .= sprintf( esc_html__( 'Hmm. It looks like you do not have a persistent object cache installed. Did you forget to copy the drop-in plugins to %s?', 'wp-spider-cache' ), '<code>' . str_replace( ABSPATH, '', WP_CONTENT_DIR ) . '</code>' );
		}

		// Bail if no message
		if ( empty( $message ) ) {
			return;
		} ?>

		<div id="message" class="notice <?php echo $status; ?>">
			<p><?php echo $message; ?></p>
		</div>

		<?php
	}
}

// Go web. Fly. Up, up, and away web! Shazam! Go! Go! Go web go! Tally ho!
new WP_Spider_Cache_UI();
