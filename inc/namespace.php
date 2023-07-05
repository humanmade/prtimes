<?php
/**
 * HM PR Times.
 *
 * @package HM
 */

namespace HM\PR_Times;

use Altis;
use WP_Error;
use WP_Query;
use WP_User_Query;

const AUTHOR_SLUG       = 'prtimes';
const RSS_AUTHOR_SLUG   = 'pressevents';
const STORY_AUTHOR_SLUG = 'story';

/**
 * This is only for prtimes post's featured images, so that we can hide them on media page, since media page only
 * shows inherit and private images attachments.
 * And use it to get when editors will try to crop the image.
 */
const POST_STATUS = 'inherit-prtimesimage';

/**
 * Plugin bootstrapper
 */
function bootstrap() {
	add_action( 'init', __NAMESPACE__ . '\\register' );
	// add_action( 'admin_init', __NAMESPACE__ . '\\schedule' );
	add_action( 'save_post_pr-times', __NAMESPACE__ . '\\Attachments\\schedule_attachment_upload', 10, 2 );
	add_filter( 'wp_insert_attachment_data', __NAMESPACE__ . '\\Attachments\\change_attachment_status' );
	add_filter( 'ajax_query_attachments_args', __NAMESPACE__ . '\\Attachments\\add_custom_post_status' );
	add_filter( 'the_posts', __NAMESPACE__ . '\\add_auto_nextpage', 10, 2 );

	add_action( 'hm_prtimes_feed', __NAMESPACE__ . '\\fetch' );
	add_action( 'hm_press_events_feed', __NAMESPACE__ . '\\Press_Events\\fetch' );
	add_action( 'hm_story_feed', __NAMESPACE__ . '\\Story\\fetch' );

	add_action( 'hm_prtimes_post_upsert', __NAMESPACE__ . '\\upsert' );
	add_action( 'hm_press_events_post_upsert', __NAMESPACE__ . '\\upsert' );
	add_action( 'hm_story_post_upsert', __NAMESPACE__ . '\\upsert' );

	add_action( 'hm_prtimes_attachment_upload', __NAMESPACE__ . '\\Attachments\\upload_thumbnail_media', 10, 2 );

	add_action( 'hm_prtimes_update_cache', __NAMESPACE__ . '\\update_cache' );
}

/**
 * Register PR Times CPT.
 */
function register() {
	// Register Post status for prtimes featured image.
	register_post_status(
		POST_STATUS,
		[
			'exclude_from_search'       => true,
			'internal'                  => true,
			'protected'                 => true,
			'private'                   => true,
			'publicly_queryable'        => false,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		]
	);
}

/**
 * Schedule PR Times feed check.
 */
function schedule() {
	$pr_times_url = get_prtimes_url();

	if (
		! wp_next_scheduled( 'hm_prtimes_feed' )
		&&
		! empty( $pr_times_url )
	) {
		wp_schedule_event( time(), 'hourly', 'hm_prtimes_feed', [ $pr_times_url ] );
	}

	$press_events_url = get_press_events_url();

	if (
		! wp_next_scheduled( 'hm_press_events_feed' )
		&&
		! empty( $press_events_url )
	) {
		wp_schedule_event( time(), 'hourly', 'hm_press_events_feed', [ $press_events_url ] );
	}

	$admin_story_url = get_admin_story_url();

	if (
		! wp_next_scheduled( 'hm_story_feed' )
		&&
		! empty( $admin_story_url )
	) {
		wp_schedule_event( time(), 'hourly', 'hm_story_feed', [ $admin_story_url ] );
	}
}

/**
 * Fetch PR Times Feed.
 *
 * @param string $pr_times_url PR Times URL.
 *
 * @return WP_Error|null
 */
function fetch( $pr_times_url ): ?WP_Error {

	// Check to see if update time matches.
	$request = wp_remote_get( $pr_times_url );
	if ( is_wp_error( $request ) ) {
		return new WP_Error( 'pr-times', $request->get_error_message() );
	}

	$body = wp_remote_retrieve_body( $request );

	if ( is_wp_error( $body ) ) {
		return new WP_Error( 'pr-times', $body->get_error_message() );
	}

	$feed = simplexml_load_string( $body );

	$last_updated = check_last_update( $feed->channel );

	if ( $last_updated === false ) {
		return null;
	}

	parse( $feed->item );
}

/**
 * Check last time the feed was updated.
 *
 * @param object $feed Feed.
 *
 * @return bool
 */
function check_last_update( $feed ) {
	$channel = $feed[0]->children( 'dc', true );
	if ( empty( $channel ) ) {
		return false;
	}

	$feed_date    = (string) $channel->date;
	$last_updated = get_option( 'hm_prtimes_last_updated' );
	if ( $last_updated === $feed_date ) {
		return false;
	}

	// Possibly move this inside parse.
	update_option( 'hm_prtimes_last_updated', $feed_date );

	return true;
}

/**
 * PR Times Parse over items in feed.
 *
 * @param object $items Items in feed.
 */
function parse( $items ) {
	$author    = get_user_by( 'slug', AUTHOR_SLUG );
	$author_id = ! empty( $author ) ? $author->ID : '';
	$time = 0;

	foreach ( $items as $item ) {
		$content = $item->children( 'content', true );

		$post = [
			'ID'           => 0,
			'post_title'   => (string) $item->title,
			'post_author'  => $author_id,
			'post_date'    => (string) $item->date,
			'post_status'  => 'publish',
			'post_content' => (string) $content->encoded,
			'post_type'    => 'post',
			'meta_input'   => [
				'prtimes_ref_id'       => (string) $item->link,
				'prtimes_last_updated' => (string) $item->LastBuildDate, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'image_url'            => (string) $item->image,
			],
		];

		if ( ! wp_next_scheduled( 'hm_prtimes_post_upsert', [ $post ] ) ) {
			wp_schedule_single_event( time() + $time, 'hm_prtimes_post_upsert', [ $post ] );
			$time += 15;
		}
	}
}

/**
 * Upsert
 *
 * @param array $item Item to be inserted.
 *
 * @return void|WP_Error
 */
function upsert( $item = [] ) {

	// Check if Post exists.
	$post_meta = check_post_exists(
		$item['meta_input']['prtimes_ref_id'],
		$item['meta_input']['prtimes_last_updated']
	);

	if ( $post_meta === false ) {
		return;
	}

	if ( $post_meta['type'] === 'update' ) {
		$item['ID'] = $post_meta['post_id'];
	}

	$post_id = wp_insert_post( $item );

	if ( is_wp_error( $post_id ) ) {
		return new WP_Error( 'pr-times', $post_id->get_error_message() );
	}

	update_user_meta( $item['post_author'], 'prtimes_last_published_date', time() );

	if ( ! wp_next_scheduled( 'hm_prtimes_update_cache' ) ) {
		wp_schedule_single_event( time(), 'hm_prtimes_update_cache' );
	}
}

/**
 * Check Post exists.
 *
 * @param string $ref_id       Reference ID.
 * @param string $last_updated Last time the post was updated.
 *
 * @return mixed
 */
function check_post_exists( $ref_id, $last_updated ) {
	$args = [
		'posts_per_page' => 1,
		'post_type'      => 'post',
		'post_status'    => 'any',
		'meta_key'       => 'prtimes_ref_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => $ref_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'fields'         => 'ids',
	];

	$query = new WP_Query( $args );

	$post_meta = [
		'type'    => 'new',
		'post_id' => 0,
	];

	if ( $query->have_posts() ) {
		$post_id = $query->posts[0];

		$post_last_updated = get_post_meta( $post_id, 'prtimes_last_updated', true );

		if ( $post_last_updated === $last_updated ) {
			return false; // Don't do anything.
		}

		$post_meta['type']    = 'update';
		$post_meta['post_id'] = $post_id;
	}

	return $post_meta;
}

/**
 * Update Cache when posts are imported.
 */
function update_cache() {
	$args = [
		'fields'      => [
			'ID',
			'user_nicename',
			'display_name',
		],
		'orderby'     => 'meta_value_num',
		'order'       => 'desc',
		'count_total' => true,
		'login__in' => [ AUTHOR_SLUG, RSS_AUTHOR_SLUG, STORY_AUTHOR_SLUG ],
		'meta_query'  => [ // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'sorting_clause' => [
				'key'     => 'prtimes_last_published_date',
				'value'   => 0,
				'compare' => '>=',
			],
		],
	];

	wp_cache_delete( CPT_SLUG . '_total', CPT_SLUG );
	wp_cache_delete( CPT_SLUG . '_users', CPT_SLUG );

	$query = new WP_User_Query( $args );

	if ( ! empty( $query->get_total() ) ) {
		wp_cache_set( CPT_SLUG . '_total', $query->get_total(), CPT_SLUG );
	}

	if ( ! empty( $query->get_results() ) ) {
		wp_cache_set( CPT_SLUG . '_users', $query->get_results(), CPT_SLUG );
	}
}

/**
 * Filters the array of retrieved posts after they've been fetched and
 * internally processed.
 *
 * @param WP_Post[] $posts Array of post objects.
 * @param WP_Query  $query The WP_Query instance (passed by reference).
 *
 * @return array
 */
function add_auto_nextpage( $posts, $query ) {
	$first_page_p_num = 2;

	if ( ! $query->is_single() ) {
		return $posts;
	}

	if ( ! $query->is_main_query() ) {
		return $posts;
	}

	$current_post = $posts[0];
	if ( CPT_SLUG !== $current_post->post_type ) {
		return $posts;
	}

	$current_content = $current_post->post_content;
	$current_content = apply_filters( 'the_content', $current_content );
	if ( $first_page_p_num >= mb_substr_count( $current_content, '<p>' ) ) {
		return $posts;
	}

	$current_content = preg_replace( '@</p>@', '</p><!--nextpage-->', $current_content, $first_page_p_num );
	$current_content = preg_replace( '@</p><!--nextpage-->@', '</p>', $current_content, $first_page_p_num - 1 );

	$current_post->post_content = $current_content;
	$posts[0]                   = $current_post;

	return $posts;
}

/**
 * Get default PR Times URL.
 *
 * @return string|null
 */
function get_prtimes_url(): ?string {
	if ( ! is_feed_enabled( 'prtimes' ) ) {
		return null;
	}

	return Altis\get_config()['modules']['rs']['prtimes']['url'] ?? null;
}

/**
 * Get Press Events URL.
 *
 * @return string|null
 */
function get_press_events_url(): ?string {
	if ( ! is_feed_enabled( 'press-events' ) ) {
		return null;
	}

	return Altis\get_config()['modules']['rs']['press-events']['default'] ?? null;
}

/**
 * Get Admin Story URL.
 *
 * @return string|null
 */
function get_admin_story_url(): ?string {
	if ( ! is_feed_enabled( 'admin-story' ) ) {
		return null;
	}

	return Altis\get_config()['modules']['rs']['admin-story']['default'] ?? null;
}

/**
 * Get if feed is enabled.
 *
 * @param string $feed Feed name.
 *
 * @return bool
 */
function is_feed_enabled( $feed ): bool {
	return Altis\get_config()['modules']['rs'][ $feed ]['enabled'] ?? false;
}
