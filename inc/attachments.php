<?php
/**
 * HM Press Events
 *
 * @package HM
 */

namespace HM\PR_Times\Attachments;

use HM\PR_Times;
use WP_Error;
use WP_post;

/**
 * Schedule a cron pr-times posts are updated/published to add and update featured image.
 *
 * @param int     $post_id Current Post id.
 * @param WP_post $post    Post Object.
 *
 * @return void
 */
function schedule_attachment_upload( int $post_id, WP_post $post ) : void {
	// Return if post type is not pr-times.
	if ( $post->post_status !== 'publish' || ! has_post_category( $post_id ) ) {
		return;
	}

	$image_url = get_post_meta( $post_id, 'image_url', true );

	// Return if there is no Image URL for the post.
	if ( empty( $image_url ) ) {
		return;
	}

	// Return if cron is scheduled.
	if ( wp_next_scheduled( 'hm_prtimes_attachment_upload', [ $post_id, $image_url ] ) ) {
		return;
	}

	wp_schedule_single_event( time(), 'hm_prtimes_attachment_upload', [ $post_id, $image_url ] );

}

/**
 * Add featured image for the post.
 *
 * @param int    $post_id   Recently created post id.
 * @param string $image_url Image URL from the feed.
 *
 * @return bool|WP_Error
 */
function upload_thumbnail_media( int $post_id, string $image_url ) {
	$attachment_id = media_sideload_image( urldecode( esc_url_raw( $image_url ) ), $post_id, null, 'id' );

	if ( is_wp_error( $attachment_id ) ) {
		return new WP_Error( 'pr-times', $attachment_id->get_error_message() );
	}

	if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
		// Translators: placeholder is post id.
		$msg = sprintf( esc_html__( 'Thumbnail could not set for post ID: %d', 'hm-backend' ), $post_id );
		return new WP_Error( 'pr-times', $msg );
	}

	return delete_post_meta( $post_id, 'image_url', $image_url );
}

/**
 * Add Custom post status to query.
 *
 * @param array $query Current Query Arguments.
 *
 * @return array
 */
function add_custom_post_status( array $query ) : array {
	$parent_id = filter_input( INPUT_POST, 'post_id', FILTER_SANITIZE_NUMBER_INT );

	if (
		empty( $parent_id )
		|| empty( $query['post__in'] )
		|| ! has_post_category( $parent_id )
	) {
		return $query;
	}

	$query['post_status'] = $query['post_status'] . ',' . PR_Times\POST_STATUS;

	return $query;
}

/**
 * Change post status from `inherit` so that it does not show up on media page.
 *
 * @param array $data Attachment data before it is updated in or added to the database.
 *
 * @return array
 */
function change_attachment_status( array $data ) : array {
	if ( $data['post_type'] !== 'attachment' || empty( $data['post_parent'] ) || ! has_post_category( $data['post_parent'] ) ) {
		return $data;
	}

	$data['post_status'] = PR_Times\POST_STATUS;

	return $data;
}

/**
 * Check if post has category.
 *
 * @param int $post_id Post ID.
 *
 * @return bool
 */
function has_post_category( int $post_id ): bool {
	$category_id = PR_Times\get_press_events_category_id();
	return has_category( $category_id, $post_id );
}
