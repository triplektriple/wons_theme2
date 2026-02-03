<?php
/**
 * Init
 *
 * @since x.x.x
 * @package Whats New library
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Astra_Sites_Whats_New' ) ) :

	/**
	 * Admin
	 */
	class Astra_Sites_Whats_New {

		/**
		 * Instance
		 *
		 * @since x.x.x
		 * @var (Object) Astra_Sites_Whats_New
		 */
		private static $instance = null;

		/**
		 * Get Instance
		 *
		 * @since x.x.x
		 *
		 * @return self Class object.
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor.
		 *
		 * @since x.x.x
		 */
		private function __construct() {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		}

		/**
         * Enqueue scripts in the admin area.
         *
         * @param string $hook Current screen hook.
         *
         * @return void
         */
		public function enqueue_scripts( $hook = '' ) {

            if ( 'appearance_page_starter-templates' !== $hook ) {
                return;
            }

			wp_enqueue_style( 'starter-templates-whats-new-rss', ASTRA_SITES_URI . 'inc/lib/whats-new/whats-new-rss.css', array(), ASTRA_SITES_VER );
		}

	}

/**
 * Kicking this off by calling 'get_instance()' method
 */
Astra_Sites_Whats_New::get_instance();

endif;