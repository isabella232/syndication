<?php

namespace Automattic\Syndication;

/**
 * Syndication Puller
 *
 * The role of this class is to be a base/parent for all pull client classes.
 * This parent class contains methods to process a site using this client
 *
 * @package Automattic\Syndication
 */
abstract class Puller {

	public function __construct(){}

	/**
	 * Process a site and pull all it's posts
	 *
	 * @param obj $client  The syndication client class instance
	 * @param int $site_id The ID of the site for which to pull it's posts
	 * @return array|bool  Array of posts on success, false on failure
	 */
	public static function process_site( $client, $site_id ) {
		global $site_manager, $client_manager;

		// Fetch the site status
		if ( ! in_array( $site_manager->get_site_status( $site_id ), array( 'idle', '' ) ) ) {
			return false;
		}

		// Get the required client.
		$client_transport_type = get_post_meta( $site_id, 'syn_transport_type', true );
		if ( ! $client_transport_type ) {
			return false;
		}

		// Fetch the client so we may pull it's posts
		$client_details = $client_manager->get_pull_client( $client_transport_type );
		if ( ! $client_details ) {
			return false;
		}

		// Mark site as in progress
		$site_manager->update_site_status( 'pulling' );

		// Fetch the site's posts by calling the class located at the
		// namespace given during registration
		$posts = $client->get_posts( $site_id );

		// Update site status
		$site_manager->update_site_status( 'idle' );

		if ( is_array( $posts ) && ! empty( $posts ) ) {
			return $posts;
		} else {
			return false;
		}
	}


	/**
	 *
	 * @param $posts array|\Traversable
	 * @throws \Exception
	 */
	public function process_posts( $posts ) {
		// @todo perform actions to improve performance

		if ( ! is_array( $posts ) || ! $posts instanceof \Traversable ) {
			throw new \InvalidArgumentException( '$posts must be array or Traversable.' );
		}

		foreach ( $posts as $post ) {
			$this->process_post( $post );
		};

		// @todo remove actions to improve performance
	}

	/**
	 * @param Types\Post $post
	 * @throws \Exception
	 */
	public function process_post( Types\Post $post ) {

		// @todo hooks
		// @todo Validate the post.

		// Find local ID.
		if ( ! $post->local_id ) {
			$local_id = $this->get_local_id( $post->remote_id );

			if ( $local_id ) {
				$post->local_id = $local_id;
			}
		}

		// Make sure post exists.
		if ( $post->local_id && ! get_post( $post->local_id ) ) {
			throw new \Exception( 'Post does not exist.' );
		}

		// @todo Bail if post exists and in-progress marker is set.
		// @todo Mark post as in-progress (if post exists).

		// Consume the post.
		$this->process_post_data( $post );
		$this->process_post_meta( $post );
		$this->process_post_terms( $post );

		// @todo Mark post as done.
	}

	public function process_post_data( Types\Post $post ) {

		// @todo Validate again if this method remains public.

		$new_post = $post->post_data;

		// @todo Date/time futzing.

		if ( $post->local_id ) {
			$new_post['ID'] = $post->local_id;
		}

		$new_post = apply_filters( 'syn_before_insert_post', $new_post, $this->current_site_id );

		$res = wp_insert_post( $new_post, true );

		is_wp_error_do_throw( $res );
	}

	public function process_post_meta( Types\Post $post ) {

		// @todo Validate again if this method remains public.
		$post_meta = apply_filters( 'syn_before_update_post_meta', $post->post_meta, $post, $this->current_site_id );

		foreach ( $post->post_meta as $key => $value ) {
			$res = update_post_meta( $post->local_id, $key, $value );

			if ( ! $res ) {
				throw new \Exception( 'Could not insert post meta.' );
			}
		}
	}

	public function process_post_terms( Types\Post $post ) {

		// @todo Validate again if this method remains public.
		$post_terms = apply_filters( 'syn_before_set_object_terms', $post->post_terms, $post, $this->current_site_id );


		foreach ( $post_terms as $taxonomy => $terms ) {

			$res = wp_set_object_terms( $post->local_id, $terms, $taxonomy );

			is_wp_error_do_throw( $res );
		}
	}


	/**
	 * @param $identifier
	 * @return int|void
	 */
	public function get_local_id( $identifier ) {

		$identifier = (string) $identifier;

		if ( empty( $identifier ) ) {
			return;
		}

		$posts = get_posts( [
			'meta_key' => 'syn_identifier',
			'meta_value' => $identifier,
			'posts_per_page' => 1,
			'fields' => 'ids',
		] );

		if ( ! $posts ) {
			return;
		}

		return (int) $posts[0];
	}

	/**
	 * Test the connection with the slave site.
	 *
	 * @param string $remote_url The remote URL
	 * @return bool True on success; false on failure.
	 */
	public function test_connection( $remote_url = '' ) {
		return ! is_wp_error( $this->remote_get( $remote_url = '' ) );
	}

	/**
	 * Fetch a remote url.
	 *
	 * @param string $remote_url The remote URL
	 * @return string|WP_Error The content of the remote feed, or error if there's a problem.
	 */
	public function remote_get( $remote_url = '' ) {

		// Only proceed if we have a valid remote url
		if ( isset( $remote_url ) && ! empty( $remote_url ) ) {

			$request = wp_remote_get( esc_url_raw( $remote_url ) );

			if ( \Automattic\Syndication\is_wp_error_do_throw( $request ) ) {
				return $request;
			} elseif ( 200 != wp_remote_retrieve_response_code( $request ) ) {
				return new \WP_Error( 'syndication-fetch-failure', 'Failed to fetch Remote URL; HTTP code: ' . wp_remote_retrieve_response_code( $request ) );
			}

			return wp_remote_retrieve_body( $request );
		} else {
			return false;
		}
	}
}

