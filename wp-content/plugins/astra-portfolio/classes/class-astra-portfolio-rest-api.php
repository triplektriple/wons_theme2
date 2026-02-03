<?php
/**
 * Astra Portfolio API
 *
 * @package Astra Portfolio
 * @since 1.0.0
 */

if ( ! class_exists( 'Astra_Portfolio_Rest_API' ) ) :

	/**
	 * Astra_Portfolio_Rest_API
	 *
	 * @since 1.0.0
	 */
	class Astra_Portfolio_Rest_API {

		/**
		 * Instance
		 *
		 * @access private
		 * @var object Class object.
		 * @since 1.0.0
		 */
		private static $instance;

		/**
		 * License Status
		 *
		 * @access private
		 * @var string License status.
		 * @since 1.0.0
		 */
		private static $license_status = null;

		/**
		 * API Request Start Time
		 *
		 * @access private
		 * @var string Start time.
		 * @since 1.0.0
		 */
		private static $start_time;

		/**
		 * Initiator
		 *
		 * @since 1.0.0
		 * @return object initialized object of class.
		 */
		public static function get_instance() {
			if ( ! isset( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 *
		 * @since 1.0.0
		 */
		public function __construct() {

			add_action( 'rest_api_init', array( $this, 'meta_in_rest' ) );

		}

		/**
		 * Add Extra Fields in Rest
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function meta_in_rest() {

			// Start logging how long the request takes for logging.
			self::$start_time = microtime( true );

			register_rest_field(
				'astra-portfolio',
				'astra-site-call-to-action',
				array(
					'get_callback' => array( $this, 'get_post_meta' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'astra-site-open-in-new-tab',
				array(
					'get_callback' => array( $this, 'get_post_meta' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'astra-site-open-portfolio-in',
				array(
					'get_callback' => array( $this, 'get_post_meta' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'astra-site-url',
				array(
					'get_callback' => array( $this, 'get_post_meta' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'thumbnail-image-url',
				array(
					'get_callback' => array( $this, 'get_post_featured_image' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'thumbnail-image-meta',
				array(
					'get_callback' => array( $this, 'get_post_featured_meta' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'lightbox-image-url',
				array(
					'get_callback' => array( $this, 'get_lightbox_image_url' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'portfolio-type',
				array(
					'get_callback' => array( $this, 'get_portfolio_type' ),
					'schema'       => null,
				)
			);

			register_rest_field(
				'astra-portfolio',
				'portfolio-video-url',
				array(
					'get_callback' => array( $this, 'get_portfolio_video_url' ),
					'schema'       => null,
				)
			);

			// Request Time.
			register_rest_field(
				'astra-portfolio',
				'request_time',
				array(
					'get_callback' => array( $this, 'get_api_request_time' ),
					'schema'       => null,
				)
			);

		}

		/**
		 * Set API request Time
		 *
		 * @since 1.0.0
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return mixed              Null or Site Featured Image.
		 */
		public function get_api_request_time( $object = '', $field_name = '', $request = array() ) {

			// End time of logging.
			$end_time = microtime( true );

			return ( $end_time - self::$start_time );
		}

		/**
		 * Get Site Featured Image.
		 *
		 * @since 1.0.0
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return mixed              Null or Site Featured Image.
		 */
		public function get_post_featured_image( $object = '', $field_name = '', $request = array() ) {

			$image_id = get_post_meta( $object['id'], 'astra-portfolio-image-id', true );
			if ( empty( $image_id ) ) {
				return;
			}

			$image_attributes = wp_get_attachment_image_src( $image_id, 'full' );

			return $image_attributes[0];
		}

		/**
		 * Get Site Featured Meta.
		 *
		 * @since 1.0.6
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return array              Site Featured image meta.
		 */
		public function get_post_featured_meta( $object = '', $field_name = '', $request = array() ) {
			$image_id = get_post_meta( $object['id'], 'astra-portfolio-image-id', true );
			if ( empty( $image_id ) ) {
				return array(
					'alt'   => '',
					'title' => '',
				);
			}

			return array(
				'alt'   => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
				'title' => get_the_title( $image_id ),
			);
		}

		/**
		 * Get lightbox image url.
		 *
		 * @since 1.0.2
		 *
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return mixed              Null or Site Featured Image.
		 */
		public function get_lightbox_image_url( $object = '', $field_name = '', $request = array() ) {

			$image_id = get_post_meta( $object['id'], 'astra-lightbox-image-id', true );
			if ( empty( $image_id ) ) {
				return;
			}

			$image_attributes = wp_get_attachment_image_src( $image_id, 'full' );
			return $image_attributes[0];
		}

		/**
		 * Get Site URL
		 *
		 * @since 1.0.0
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return string             Post Meta.
		 */
		public function get_post_meta( $object = '', $field_name = '', $request = array() ) {

			return get_post_meta( $object['id'], $field_name, 1 );
		}

		/**
		 * Get portfolio type.
		 *
		 * @since 1.0.2
		 *
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return string             Post Meta.
		 */
		public function get_portfolio_type( $object = '', $field_name = '', $request = array() ) {

			$type = get_post_meta( $object['id'], 'astra-portfolio-type', 1 );

			if ( ! empty( $type ) ) {
				return $type;
			}

			return 'iframe';
		}

		/**
		 * Get portfolio type.
		 *
		 * @since 1.0.2
		 *
		 * @param  string $object     Rest Object.
		 * @param  string $field_name Rest Field.
		 * @param  array  $request    Rest Request.
		 * @return string             Post Meta.
		 */
		public function get_portfolio_video_url( $object = '', $field_name = '', $request = array() ) {

			return get_post_meta( $object['id'], 'astra-portfolio-video-url', 1 );
		}

	}

	/**
	 * Kicking this off by calling 'get_instance()' method
	 */
	Astra_Portfolio_Rest_API::get_instance();

endif;
