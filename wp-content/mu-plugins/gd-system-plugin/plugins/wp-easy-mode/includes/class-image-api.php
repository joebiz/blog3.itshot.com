<?php

namespace WPEM;

use WPaaS\Plugin as WPaaS;

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

/**
 * Class Image_API
 *
 * Handle fetching of image based on category
 */
final class Image_API {

	/**
	 * Constant used to interact with the API
	 */
	const BASE_URL       = 'https://categories.api.godaddy.com/v4';
	const IMAGE_ENDPOINT = '/categories/en_us/latest/%s/photos';
	const CAT_ENDPOINT   = '/categories/en_us/latest';
	private $token       = '53dacdceba099a43ed4fb45b491b16c4afb37d48';

	/**
	 * Holds the API endpoints
	 *
	 * @var string
	 */
	private $image_cat_url;
	private $category_api_url;

	/**
	 * Hold transient base namespace
	 *
	 * @const string
	 */
	private $transient_base                  = 'wpaas_stock_photos_api_v4_';
	private $transient_key_for_d3_categories = 'wpaas_stock_photos_d3_categories_v4';

	/**
	 * Image_API constructor.
	 */
	public function __construct() {

		// This needs to work on cPanel as well
		if ( is_callable( '\WPaaS\Plugin::is_wpaas' ) && \WPaaS\Plugin::is_wpaas() ) {

			$this->token = WPaaS::config( 'd3.token' );

		}

		$this->image_cat_url    = self::BASE_URL . self::IMAGE_ENDPOINT;
		$this->category_api_url = self::BASE_URL . self::CAT_ENDPOINT;

	}

	/**
	 * Retrieve json response from one category and store it as a transient for later use
	 *
	 * @param string $cat
	 * @return object array of objects
	 */
	public function get_images_by_cat( $cat ) {

		if ( false === ( $category = $this->get_api_cat( $cat ) ) ) {

			return [];

		}

		// Check if we have a transient cached response for that call
		if ( $data = get_transient( $this->transient_base . $category ) ) {

			return $data;

		}

		if ( false === ( $data = $this->fetch_images( $category ) ) ) {

			return [];

		}

		shuffle( $data );

		set_transient( $this->transient_base . $category, $data, HOUR_IN_SECONDS );

		return $data;

	}

	public function get_d3_choices() {

		$categories = $this->get_d3_categories();

		if ( ! $categories ) {

			return [];

		}

		/* uncomment this if we ever want to filter out "top level categories"

		// to help ensure the user chooses the most relevant category to their business,
		// let's not include "top level categories"
		$categories = array_filter( $categories, function( $category ) {

			return count( $category['parents'] ) > 0;

		} );
		*/

		uasort( $categories, function( $a, $b ) {

			$pop_a = $a['popularity'];
			$pop_b = $b['popularity'];

			return ( $pop_a === $pop_b ) ? 0 : ( $pop_a > $pop_b ? -1 : 1 );

		} );

		$categories = wp_list_pluck( $categories, 'display_name' );
		$popular    = array_slice( $categories, 0, 50 );
		$others     = array_slice( $categories, 50 );

		natcasesort( $others );

		$choices = array_diff_key(
			$popular + $others,
			array_flip(
				[
					'ayesha_curry_food',
					'conference_attendee',
					'dan_nonprofit',
					'diyfood',
					'elohim_music',
					'pedro',
					'personal_fooddrink',
					'tyson_manufacturing',
				]
			)
		);

		// Prepend an empty choice for Select2
		return [ '' => '' ] + $choices;

	}



	/**
	 * Get and cache D3 categories from their API endpoint
	 *
	 * @link https://websites.godaddy.com/categories/v4/en_us/latest/
	 *
	 * @return false if api error, otherwise assoc array of category object's "str_id" => category object
	 */
	public function get_d3_categories() {

		// Check if we have a transient cached response for that call
		if ( $data = get_transient( $this->transient_key_for_d3_categories ) ) {

			return $data;

		}

		if ( $data = $this->fetch_d3_categories() ) {

			// can use slower cache expiry since the category api endpoint is updated very infrequently
			set_transient( $this->transient_key_for_d3_categories, $data, DAY_IN_SECONDS );

		}

		return $data;

	}

	public function get_d3_categories_fallback() {

		$list = [
			'professional'         => __( 'Business / Finance / Law', 'wp-easy-mode' ),
			'graphicdesign'        => __( 'Design / Art / Portfolio', 'wp-easy-mode' ),
			'education'            => __( 'Education', 'wp-easy-mode' ),
			'health'               => __( 'Health / Beauty', 'wp-easy-mode' ),
			'constructionservices' => __( 'Home Services / Construction', 'wp-easy-mode' ),
			'massmedia'            => __( 'Music / Movies / Entertainment', 'wp-easy-mode' ),
			'nonprofit'            => __( 'Non-profit / Causes / Religious', 'wp-easy-mode' ),
			'generic_outdoors'     => __( 'Other', 'wp-easy-mode' ),
			'pets'                 => __( 'Pets / Animals', 'wp-easy-mode' ),
			'realestate'           => __( 'Real Estate', 'wp-easy-mode' ),
			'restaurants'          => __( 'Restaurant / Food', 'wp-easy-mode' ),
			'personal_sports'      => __( 'Sports / Recreation', 'wp-easy-mode' ),
			'auto'                 => __( 'Transportation / Automotive', 'wp-easy-mode' ),
			'travelservices'       => __( 'Travel / Hospitality / Leisure', 'wp-easy-mode' ),
			'weddingphotographers' => __( 'Wedding', 'wp-easy-mode' ),
		];

		return $list;

	}

	/**
	 * Helper to fetch categories from the API
	 *
	 * As an implementation detail, does some post processing of the raw API json response
	 *
	 * @return false if api error, otherwise assoc array of category object's "str_id" => category object
	 */
	private function fetch_d3_categories() {

		$categories = $this->fetch( $this->category_api_url );

		if ( ! $categories ) {

			return;

		}

		$output = [];

		foreach ( $categories as $i => $cat ) {

			$output[ $cat->id ] = [
				'display_name' => $cat->name,
				'popularity'   => $cat->popularity,
			];

		}

		return $output;

	}

	/**
	 * Check if the current locale can use d3.
	 *
	 * @return bool
	 */
	public function is_d3_locale() {

		return in_array( get_locale(), [ 'en_US', 'en_CA' ] );

	}

	/**
	 * Get api category slug
	 *
	 * @param string $slug
	 *
	 * @return bool|string
	 */
	private function get_api_cat( $slug ) {

		$d3_categories = $this->get_d3_categories();

		if ( ! $d3_categories && array_key_exists( $slug, $this->get_d3_categories_fallback() ) ) {

			return $slug;

		}

		$d3_categories['generic'] = true;

		return isset( $d3_categories[ $slug ] ) ? $slug : false;

	}

	/**
	 * Helper to fetch infomation from the API
	 *
	 * @param  string $url
	 *
	 * @return mixed|false
	 */
	private function fetch( $url ) {

		$response = wp_remote_get(
			$url,
			[
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Token ' . $this->token,
				],
			]
		);

		if ( is_wp_error( $response ) ) {

			return false;

		}

		$response = json_decode( wp_remote_retrieve_body( $response ) );

		return isset( $response->result ) ? $response->result : false;

	}

	/**
	 * Helper function to fetch stock images from the API.
	 *
	 * When the given category has no stock photos, this function will be
	 * responsible for fetching the parent category's stock photo as a fallback.
	 *
	 * @param string $category a valid "str_id" slug from the category API
	 *
	 * @return false if api error, otherwise array of objects from the api
	 */
	private function fetch_images( $category ) {

		$json = $this->fetch( sprintf( $this->image_cat_url, $category ) );

		if ( ! $json ) {

			return false;

		}

		if ( count( $json ) > 0 ) {

			return $json;

		}

		if ( empty( $json->parent_category ) ) {

			return [];

		}

		return $this->fetch_images( $json->parent_category );

	}

}
