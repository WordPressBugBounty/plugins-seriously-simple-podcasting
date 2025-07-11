<?php
/**
 * Paid Memberships Pro controller.
 */

namespace SeriouslySimplePodcasting\Integrations\Paid_Memberships_Pro;

use SeriouslySimplePodcasting\Handlers\Admin_Notifications_Handler;
use SeriouslySimplePodcasting\Handlers\Castos_Handler;
use SeriouslySimplePodcasting\Handlers\Feed_Handler;
use SeriouslySimplePodcasting\Helpers\Log_Helper;
use SeriouslySimplePodcasting\Integrations\Abstract_Integrator;
use SeriouslySimplePodcasting\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paid Memberships Pro controller
 *
 *
 * @since 2.9.3
 * @package SeriouslySimplePodcasting
 * @author Sergiy Zakharchenko
 */
class Paid_Memberships_Pro_Integrator extends Abstract_Integrator {

	use Singleton;

	const BULK_UPDATE_STARTED = 'ssp_pmpro_bulk_update_started';

	const ADD_LIST_OPTION = 'ssp_pmpro_add_subscribers';

	const REVOKE_LIST_OPTION = 'ssp_pmpro_revoke_subscribers';

	const MEMBERSHIP_SERIES_OPTION = 'ssp_pmpro_membership_series';

	const EVENT_BULK_SYNC_SUBSCRIBERS = 'ssp_pmpro_bulk_sync_subscribers';

	const EVENT_ADD_SUBSCRIBERS = 'ssp_pmpro_add_subscribers';

	const EVENT_REVOKE_SUBSCRIBERS = 'ssp_pmpro_revoke_subscribers';


	/**
	 * Class Paid_Memberships_Pro_Integrator constructor.
	 *
	 * @param Feed_Handler $feed_handler
	 * @param Castos_Handler $castos_handler
	 * @param Log_Helper $logger
	 * @param Admin_Notifications_Handler $notices_handler
	 */
	public function init( $feed_handler, $castos_handler, $logger, $notices_handler ) {

		if ( ! $this->check_dependencies(
			array( 'PMPro_Membership_Level' ),
			array( 'pmpro_getMembershipLevelsForUser', 'pmpro_get_no_access_message' ) ) ) {
			return;
		}

		$this->feed_handler = $feed_handler;
		$this->castos_handler = $castos_handler;
		$this->logger = $logger;
		$this->notices_handler = $notices_handler;

		if ( is_admin() && ! ssp_is_ajax() ) {
			$this->init_integration_settings();
		} else {
			if ( self::integration_enabled() ) {
				$this->protect_private_series();
				$this->print_private_podcast_feeds();
			}
		}

		if ( ssp_is_connected_to_castos() ) {
			$this->init_subscribers_sync();
		}
	}

	/**
	 * Checks if integration is enabled.
	 *
	 * @return bool
	 */
	public static function integration_enabled() {
		return class_exists( 'PMPro_Membership_Level' ) && 'on' === ssp_get_option( 'enable_pmpro_integration', 'on' );
	}

	/**
	 * Inits subscribers sync.
	 * There are 2 cases when sync is needed:
	 * 1. When user's Membership Level is changed.
	 * 2. When Series -> Membership Level association is changed.
	 */
	protected function init_subscribers_sync() {

		// Sync users when their Membership Level is changed (from admin panel, when registered or cancelled).
		add_filter( 'pmpro_before_change_membership_level', array(
			$this,
			'sync_subscribers_on_change_membership_level',
		), 10, 2 );


		// Schedule the bulk sync when Series -> Membership Level association is changed.
		add_filter( 'allowed_options', array( $this, 'schedule_bulk_sync_on_settings_update' ), 20 );

		// Step 1. Run the scheduled bulk sync. Prepare add and remove lists, and run add process.
		add_action( self::EVENT_BULK_SYNC_SUBSCRIBERS, array( $this, 'bulk_sync_subscribers' ) );

		// Step 2. Run add process.
		add_action( self::EVENT_ADD_SUBSCRIBERS, array( $this, 'bulk_add_subscribers' ) );

		// Step 3. Run revoke process.
		add_action( self::EVENT_REVOKE_SUBSCRIBERS, array( $this, 'bulk_revoke_subscribers' ) );
	}

	/**
	 * Schedules bulk sync cronjob when integration settings are updated.
	 *
	 * @since 3.4.1
	 *
	 * @param array $allowed_options
	 *
	 * @return array
	 */
	public function schedule_bulk_sync_on_settings_update( $allowed_options ) {
		// Option ss_podcasting_is_pmpro_integration is a marker that PMPro integration settings have been saved.
		if ( ! isset( $allowed_options['ss_podcasting'] ) ) {
			return $allowed_options;
		}

		$key = array_search( 'ss_podcasting_is_pmpro_integration', $allowed_options['ss_podcasting'] );
		if ( false !== $key ) {
			unset( $allowed_options['ss_podcasting'][ $key ] );
			$this->update_membership_series();
			$this->schedule_bulk_sync_subscribers();
		}

		return $allowed_options;
	}

	/**
	 * To prevent unexpected revokes from Castos when PMPro integration is enabled,
	 * sync only series that were connected to PMPro membership levels before or connected now.
	 * */
	protected function update_membership_series() {

		$series_ids = $this->get_current_membership_series_ids();

		$old_sync_series = $this->get_saved_membership_series();

		$new_sync_series = array_unique( array_merge( $old_sync_series, $series_ids ) );

		$this->save_membership_series_data( $new_sync_series );
	}

	/**
	 * Save membership series option to determine later which series can be synchronized with Castos.
	 *
	 * @since 3.4.1
	 *
	 * @param $series
	 *
	 * @return void
	 */
	protected function save_membership_series_data( $series ) {
		update_option( self::MEMBERSHIP_SERIES_OPTION, $series, false );
	}

	/**
	 * Get saved membership series option to determine which series can be synchronized with Castos.
	 *
	 * @since 3.4.1
	 *
	 * @return false|mixed|null
	 */
	protected function get_saved_membership_series() {
		return get_option( self::MEMBERSHIP_SERIES_OPTION, array() );
	}

	/**
	 * Gets membership series using previous and current membership series data.
	 * It's needed to make sure we sync only series that are or were involved with PMPro integration.
	 * */
	protected function get_membership_series_ids() {
		$ssp_membership_series_ids = $this->get_current_membership_series_ids();
		$castos_sync_series = $this->get_saved_membership_series();

		return array_unique( array_merge( $ssp_membership_series_ids, $castos_sync_series ) );
	}

	/**
	 * Bulk sync subscribers after settings change.
	 * Modified version of @see parent::bulk_sync_subscribers() that uses current Castos emails instead of the previous settings state.
	 *
	 * @since 3.4.1
	 */
	public function bulk_sync_subscribers() {
		try {
			if ( $this->bulk_update_started() ) {
				throw new \Exception('Another bulk update has already started');
			}
			$users_series_map = $this->generate_users_series_map();
			$membership_series_ids = $this->get_membership_series_ids();

			$series_emails_map = $this->get_series_emails_map( $users_series_map );

			$list_to_add = array();
			$list_to_revoke = array();

			foreach ( $membership_series_ids as $series_id ) {
				$ssp_series_emails = isset ( $series_emails_map[ $series_id ] ) ? $series_emails_map[ $series_id ] : array();
				$castos_series_emails = $this->get_castos_series_emails( $series_id );
				$list_to_add[ $series_id ] = array_diff( $ssp_series_emails, $castos_series_emails );
				$list_to_revoke[ $series_id ] = array_diff( $castos_series_emails, $ssp_series_emails );
			}

			// Convert emails to ids for Add for backward compatibility,
			// and because we'll need not only email but also username.
			update_option( static::ADD_LIST_OPTION, $this->convert_emails_to_ids( $list_to_add ) );

			// For revoke, we'll need a list of emails, so don't convert it and use directly.
			// Also, this approach allows removing emails that are not associated with any user in WordPress.
			update_option( static::REVOKE_LIST_OPTION, $list_to_revoke );

			$this->schedule_bulk_add_subscribers( 0 );
		} catch ( \Exception $e ) {
			$this->logger->log( __METHOD__ . ': Something went wrong! Bulk sync process rescheduled. ' . $e->getMessage() );

			// Another process is running, try to sync later.
			if ( ! wp_next_scheduled( static::EVENT_BULK_SYNC_SUBSCRIBERS ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, static::EVENT_BULK_SYNC_SUBSCRIBERS );
			}
		}
	}

	/**
	 * Recursively converts user emails in the list to ids.
	 * We need the user ids format for the backward compatibility and to determine emails and user names in the bulk add function.
	 *
	 * @since 3.4.1
	 *
	 * @param array $list
	 *
	 * @return array
	 */
	protected function convert_emails_to_ids( $list ) {
		$converted = array();
		foreach ( $list as $k => $v ) {
			if ( is_array( $v ) ) {
				$converted[ $k ] = $this->convert_emails_to_ids( $v );
			}
			if ( is_string( $v ) && filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
				$user = get_user_by( 'email', $v );
				if ( $user ) {
					$converted[ $k ] = $user->ID;
				}
			}
		}

		return $converted;
	}

	/**
	 * Revoke subscribers from Castos podcasts. Uses emails list instead of IDs.
	 *
	 * @since 3.4.1
	 *
	 * @see parent::revoke_subscribers_from_podcast()
	 *
	 * @param int $series_id
	 * @param string[] $emails
	 *
	 * @return int
	 */
	protected function revoke_subscribers_from_podcast( $series_id, $emails ) {
		$podcast_ids = $this->convert_series_ids_to_podcast_ids( array( $series_id ) );

		$count = $this->castos_handler->revoke_subscribers_from_podcasts( $podcast_ids, $emails );

		$this->logger->log( __METHOD__ . ' Revoked subscribers: ' . $count . PHP_EOL . print_r( $emails, true ) );

		return $count;
	}

	/**
	 * Converts "user_ids->series_ids" map to "series_ids->emails" map.
	 *
	 * @since 3.4.1
	 *
	 * @param array $users_series_map
	 *
	 * @return array
	 */
	public function get_series_emails_map( $users_series_map ) {
		$users_data = $this->get_users_data( array_keys( $users_series_map ) );

		$series_emails_map = array();
		foreach ( $users_series_map as $user_id => $series_ids ) {
			$user_email = isset( $users_data[ $user_id ]['user_email'] ) ? $users_data[ $user_id ]['user_email'] : '';
			if ( ! $series_ids || ! $user_email ) {
				continue;
			}
			foreach ( $series_ids as $series_id ) {
				$series_emails_map[ $series_id ][] = $user_email;
			}
		}
		foreach ( $series_emails_map as $series_id => $user_emails ) {
			$series_emails_map[ $series_id ] = array_unique( $user_emails );
		}

		return $series_emails_map;
	}

	/**
	 * Gets all series ids currently involved in PMPro integration.
	 *
	 * @since 3.4.1
	 *
	 * @return array
	 * */
	public function get_current_membership_series_ids() {
		$membership_levels = $this->get_membership_levels();

		$membership_series_ids = array();
		foreach ( $membership_levels as $level ) {
			$membership_series_ids = array_unique( array_merge(
				$membership_series_ids,
				$this->get_series_ids_by_level( $level->id )
			) );
		}

		return $membership_series_ids;
	}

	/**
	 * Gets Castos series active subscriber emails
	 *
	 * @since 3.4.1
	 *
	 * @param int $series_id
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function get_castos_series_emails( $series_id ) {
		$subscribers = [];
		$podcast = $this->get_castos_podcast_by_series_id( $series_id );
		if ( $podcast ) {
			$subscribers = $this->castos_handler->get_podcast_subscribers( $podcast['id'] );
		}
		$emails = array();
		foreach ( $subscribers as $subscriber ) {
			if ( 'active' === $subscriber['status'] ) {
				$emails[] = $subscriber['email'];
			}
		}

		return $emails;
	}

	/**
	 * Gets Castos podcast by SSP series ID.
	 *
	 * @since 3.4.1
	 *
	 * @param int $series_id
	 *
	 * @return array
	 */
	public function get_castos_podcast_by_series_id( $series_id ) {
		$castos_podcasts = $this->get_castos_podcasts();

		foreach ( $castos_podcasts as $castos_podcast ) {
			if ( isset( $castos_podcast['series_id'] ) && $series_id === $castos_podcast['series_id'] ) {
				return $castos_podcast;
			}
		}

		return array();
	}


	/**
	 * Gets users series map.
	 *
	 * @return array
	 */
	protected function get_users_series_map() {
		return get_option( 'ss_pmpro_users_series_map', array() );
	}

	/**
	 * Updates users series map.
	 *
	 * @param array $map
	 *
	 * @return void
	 */
	protected function update_users_series_map( $map ) {
		update_option( 'ss_pmpro_users_series_map', $map, false );
	}

	/**
	 * Gets the map between users and related series [['2' => [3, 4]]].
	 *
	 * @return array
	 */
	protected function generate_users_series_map() {
		$map = array();

		$membership_users = $this->get_membership_user_ids();

		foreach ( $membership_users as $user ) {
			$series = $this->get_series_ids_by_level( $user->membership_id );

			$map[ $user->user_id ] = $series;
		}

		return $map;
	}


	/**
	 * @return string
	 */
	protected function get_successfully_finished_notice() {
		return __( 'PMPro data successfully synchronized!', 'seriously-simple-podcasting' );
	}


	/**
	 * Sync subscribers when user's Membership Level is changed (case 1).
	 *
	 * @param array|int $level
	 * @param int $user_id
	 */
	public function sync_subscribers_on_change_membership_level( $level, $user_id ) {

		$level_id = is_array( $level ) ? $level['membership_id'] : $level;

		$old_level = pmpro_getMembershipLevelForUser( $user_id );

		$old_series_ids = isset( $old_level->id ) ? $this->get_series_ids_by_level( $old_level->id ) : array();

		$new_series_ids = $this->get_series_ids_by_level( $level_id );

		$revoke_series_ids = array_diff( $old_series_ids, $new_series_ids );

		$add_series_ids = array_diff( $new_series_ids, $old_series_ids );

		$this->sync_user( $user_id, $revoke_series_ids, $add_series_ids );

		return $level;
	}


	/**
	 * Gets IDs of all users who have any membership level.
	 *
	 * @return array
	 */
	protected function get_membership_user_ids() {

		global $wpdb;

		$query = "SELECT DISTINCT user_id, membership_id from {$wpdb->pmpro_memberships_users} WHERE status='active'";

		$res = $wpdb->get_results( $query );

		return $res;
	}


	/**
	 * Inits integration settings.
	 * */
	protected function init_integration_settings() {
		// Use priority 12 because Podcast and Series post types registered on 11.
		add_action( 'init', array( $this, 'integration_settings' ), 12 );
	}


	/**
	 * Protects private series.
	 * */
	protected function protect_private_series() {
		add_filter( 'pmpro_has_membership_access_filter', array( $this, 'access_filter' ), 10, 4 );
		add_action( 'ssp_before_feed', array( $this, 'protect_feed_access' ) );

		add_filter( 'ssp_show_media_player_in_content', function ( $show ) {
			if ( function_exists( 'pmpro_has_membership_access' ) && ! pmpro_has_membership_access() ) {
				return false;
			}

			return $show;
		} );
	}

	/**
	 * Prints list of private podcast feeds
	 *
	 * @return void
	 */
	protected function print_private_podcast_feeds() {
		add_action( 'pmpro_account_bullets_top', function () {
			$feed_urls = $this->get_private_feed_urls();

			if ( empty( $feed_urls ) ) {
				return;
			}

			$add = '<li class="ssp-pmpro-private-feeds"><strong>' . __( 'Private Podcast Feeds', 'seriously-simple-podcasting' ) . ':</strong> ' . '<ul>';

			foreach ( $feed_urls as $feed_url ) {
				$add .= '<li>' . make_clickable( $feed_url ) . '</li>';
			}

			$add .= '</ul></li>';

			echo $add;
		} );
	}

	/**
	 * Get array of private feed URLs
	 *
	 * @return string[]
	 */
	protected function get_private_feed_urls() {
		$current_user = wp_get_current_user();
		$users_series_map = $this->generate_users_series_map();

		$feed_urls = get_transient( 'ssp_pmpro_feed_urls_user_' . $current_user->ID );

		if ( $feed_urls ) {
			return $feed_urls;
		}

		if ( ! empty( $users_series_map[ $current_user->ID ] ) ) {
			$podcast_ids = $this->convert_series_ids_to_podcast_ids( $users_series_map[ $current_user->ID ] );
		}

		if ( empty( $podcast_ids ) ) {
			return array();
		}

		foreach ( $podcast_ids as $podcast_id ) {
			$feed_urls[] = $this->get_podcast_feed_url( $podcast_id );
		}

		$feed_urls = array_values( $feed_urls );

		if ( $feed_urls ) {
			set_transient( 'ssp_pmpro_feed_urls_user_' . $current_user->ID, $feed_urls, HOUR_IN_SECONDS );
		}

		return $feed_urls;
	}

	/**
	 * Get podcast feed url.
	 *
	 * @param $podcast_id
	 *
	 * @return string|null
	 */
	protected function get_podcast_feed_url( $podcast_id ) {
		$current_user = wp_get_current_user();
		$subscriptions = $this->castos_handler->get_subscriptions_by_email( $current_user->user_email );

		foreach ( $subscriptions as $subscription ) {
			if (
				isset( $subscription['status'] ) && 'active' === $subscription['status'] &&
				isset( $subscription['podcast_id'] ) && $podcast_id === $subscription['podcast_id'] &&
				isset ( $subscription['feed_url'] )
			) {
				return $subscription['feed_url'];
			}
		}

		return null;
	}

	/**
	 * Protects access to private feeds.
	 * */
	public function protect_feed_access() {
		$series_slug = $this->feed_handler->get_series_slug();
		if ( empty( $series_slug ) ) {
			return;
		}
		$series_term = get_term_by( 'slug', $this->feed_handler->get_series_slug(), ssp_series_taxonomy() );

		if ( ! $series_term ) {
			return;
		}

		$series_levels = $this->get_series_level_ids( $series_term->term_id );
		$has_access = $this->has_access( wp_get_current_user(), $series_levels );

		if ( ! $has_access ) {
			$description = wp_strip_all_tags( pmpro_get_no_access_message( '', $series_levels ) );
			$this->feed_handler->render_feed_no_access( $series_term->term_id, $description );
			exit();
		}
	}


	/**
	 * Protects access to private episodes.
	 *
	 * @param bool $access
	 * @param \WP_Post $post
	 * @param \WP_User $user
	 * @param object[] $post_levels
	 *
	 * @return bool
	 */
	public function access_filter( $access, $post, $user, $post_levels ) {

		// If there is no access to this post, do not modify it.
		if ( ! $access ) {
			return $access;
		}

		$should_protect = apply_filters( 'ssp_pmpro_protect_private_episodes', true, $post, $user, $post_levels );

		if ( ! $should_protect ) {
			return $access;
		}

		// Get level ids.
		$post_level_ids = array_filter( array_map( function ( $item ) {
			return isset( $item->id ) ? $item->id : null;
		}, (array) $post_levels ) );

		$is_admin = is_admin() && ! ssp_is_ajax();
		$is_podcast = in_array( $post->post_type, ssp_post_types() );

		if ( $is_admin || ! $is_podcast || ! $access ) {
			return $access;
		}

		$series = $this->get_episode_series( $post->ID );

		foreach ( $series as $series_item ) {
			$post_level_ids = array_merge( $post_level_ids, $this->get_series_level_ids( $series_item->term_id ) );
		}

		return $this->has_access( $user, $post_level_ids );
	}


	/**
	 * Check if user has access to the episode. Took the logic from PMPro.
	 *
	 * @return bool
	 * @see pmpro_has_membership_access()
	 */
	protected function has_access( $user, $related_level_ids ) {
		if ( empty( $related_level_ids ) ) {
			return true;
		}

		$user_levels = pmpro_getMembershipLevelsForUser( $user->ID );

		$user_level_ids = array();

		if ( is_array( $user_levels ) ) {
			foreach ( $user_levels as $user_level ) {
				$user_level_ids[] = $user_level->id;
			}
		}

		return count( $user_level_ids ) && count( array_intersect( $user_level_ids, $related_level_ids ) );
	}


	/**
	 * Gets series level ids.
	 *
	 * @param $term_id
	 *
	 * @return int[]
	 */
	protected function get_series_level_ids( $term_id ) {
		$levels = (array) ssp_get_option( sprintf( 'series_%s_pmpro_levels', $term_id ), null );
		$level_ids = array();
		foreach ( $levels as $level ) {
			$level_ids[] = (int) str_replace( 'lvl_', '', $level );
		}

		return array_filter( $level_ids );
	}


	/**
	 * Inits integration settings.
	 */
	public function integration_settings() {

		if ( ! $this->needs_integration_settings() ) {
			return;
		}

		$args = $this->get_integration_settings();

		if ( ! ssp_is_connected_to_castos() ) {
			$msg = __( 'Please <a href="%s">connect to Castos hosting</a> to enable integrations', 'seriously-simple-podcasting' );
			$msg = sprintf( $msg, admin_url( 'edit.php?post_type=podcast&page=podcast_settings&tab=castos-hosting' ) );

			$args['description'] = $msg;
			$args['fields'] = array();
		} else {
			if ( 'podcast_settings' === filter_input( INPUT_GET, 'page' ) && $this->bulk_update_started() ) {
				$this->notices_handler->add_flash_notice( __( 'Synchronizing Paid Memberships Pro data with Castos...', 'seriously-simple-podcasting' ) );
			}
		}

		$this->add_integration_settings( $args );
	}


	/**
	 * Checks if we need to obtain the dynamic integration settings.
	 *
	 * @return bool
	 */
	protected function needs_integration_settings() {
		global $pagenow;

		return 'options.php' === $pagenow || 'podcast_settings' === filter_input( INPUT_GET, 'page' );
	}


	/**
	 * Gets integration settings.
	 *
	 * @return array
	 */
	protected function get_integration_settings() {
		$series = $this->get_series();
		$levels = $this->get_membership_levels();

		$settings = array(
			'id' => 'paid_memberships_pro',
			'title' => __( 'Paid Memberships Pro', 'seriously-simple-podcasting' ),
			'description' => __( 'Select which Podcast you would like to be available only
								to Members via Paid Memberships Pro.', 'seriously-simple-podcasting' ),
			'fields' => array(
				array(
					'id' => 'is_pmpro_integration',
					'type' => 'hidden',
				),
				array(
					'id' => 'enable_pmpro_integration',
					'type' => 'checkbox',
					'default' => 'on',
					'label' => __( 'Enable integration', 'seriously-simple-podcasting' ),
					'description' => __( 'Enable Paid Memberships Pro integration', 'seriously-simple-podcasting' ),
				),
			),
		);

		if ( ! self::integration_enabled() ) {
			$settings['description'] = '';

			return $settings;
		}

		if ( ! $levels ) {
			$levels_url = admin_url( 'admin.php?page=pmpro-membershiplevels' );
			$settings['description'] = sprintf( __( 'To require membership to access a podcast please <a href="%s">set up a
										membership level</a> first.', 'seriously-simple-podcasting' ), $levels_url );

			return $settings;
		}

		$checkbox_options = array();

		foreach ( $levels as $level ) {
			$checkbox_options[ 'lvl_' . $level->id ] = sprintf( 'Require %s to access', $level->name );
		}

		foreach ( $series as $series_item ) {
			$series_item_settings = array(
				'id' => sprintf( 'series_%s_pmpro_levels', $series_item->term_id ),
				'label' => $series_item->name,
				'type' => 'select2_multi',
				'options' => $checkbox_options,
				'description' => 'Require enrollment to level',
			);

			if ( ! $this->is_series_protected_in_castos( $series_item->term_id ) ) {
				$series_item_settings['type'] = 'info';
				$series_item_settings['description'] = 'Please first make this podcast private in your Castos dashboard';
			}

			$settings['fields'][] = $series_item_settings;
		}

		return $settings;
	}


	/**
	 * Check if the series is protected on Castos side.
	 *
	 * @param int $series_id
	 * @param bool $default
	 *
	 * @return bool|mixed
	 */
	protected function is_series_protected_in_castos( $series_id, $default = false ) {
		$podcasts = $this->get_castos_podcasts();

		foreach ( $podcasts as $podcast ) {
			if ( isset( $podcast['series_id'] ) && $series_id === $podcast['series_id'] ) {
				return $podcast['is_feed_protected'];
			}
		}

		// Return true
		return $default;
	}


	/**
	 * Gets all possible membership levels.
	 *
	 * @return array
	 */
	protected function get_membership_levels() {
		return (array) pmpro_getAllLevels();
	}
}
