<?php
/**
 * WooCommerce Product Block Editor
 */

namespace Automattic\WooCommerce\Admin\Features\ProductBlockEditor;

use Automattic\WooCommerce\Admin\Features\Features;
use Automattic\WooCommerce\Admin\Features\ProductBlockEditor\ProductTemplates\SimpleProductTemplate;
use Automattic\WooCommerce\Admin\Features\TransientNotices;
use Automattic\WooCommerce\Admin\PageController;
use Automattic\WooCommerce\Internal\Admin\BlockTemplateRegistry\BlockTemplateRegistry;
use Automattic\WooCommerce\Internal\Admin\Loader;
use WP_Block_Editor_Context;

/**
 * Loads assets related to the product block editor.
 */
class Init {
	/**
	 * The context name used to identify the editor.
	 */
	const EDITOR_CONTEXT_NAME = 'woocommerce/edit-product';

	/**
	 * Supported post types.
	 *
	 * @var array
	 */
	private $supported_post_types = array( 'simple' );

	/**
	 * Redirection controller.
	 *
	 * @var RedirectionController
	 */
	private $redirection_controller;

	/**
	 * Constructor
	 */
	public function __construct() {
		if ( Features::is_enabled( 'product-variation-management' ) ) {
			array_push( $this->supported_post_types, 'variable' );
		}

		$this->redirection_controller = new RedirectionController( $this->supported_post_types );

		if ( \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_block_editor' ) ) {
			// Register the product block template.
			$template_registry = wc_get_container()->get( BlockTemplateRegistry::class );
			$template_registry->register( new SimpleProductTemplate() );

			if ( ! Features::is_enabled( 'new-product-management-experience' ) ) {
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_conflicting_styles' ), 100 );
				add_action( 'get_edit_post_link', array( $this, 'update_edit_product_link' ), 10, 2 );
			}
			add_filter( 'woocommerce_admin_get_user_data_fields', array( $this, 'add_user_data_fields' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_filter( 'woocommerce_register_post_type_product', array( $this, 'add_product_template' ) );

			add_action( 'current_screen', array( $this, 'set_current_screen_to_block_editor_if_wc_admin' ) );

			$block_registry = new BlockRegistry();
			$block_registry->init();

			$tracks = new Tracks();
			$tracks->init();
		}
	}

	/**
	 * Enqueue scripts needed for the product form block editor.
	 */
	public function enqueue_scripts() {
		if ( ! PageController::is_admin_or_embed_page() ) {
			return;
		}
		$post_type_object     = get_post_type_object( 'product' );
		$block_editor_context = new WP_Block_Editor_Context( array( 'name' => self::EDITOR_CONTEXT_NAME ) );

		$editor_settings = array();
		if ( ! empty( $post_type_object->template ) ) {
			$editor_settings['template']                 = $post_type_object->template;
			$editor_settings['templateLock']             = ! empty( $post_type_object->template_lock ) ? $post_type_object->template_lock : false;
			$editor_settings['__unstableResolvedAssets'] = $this->get_resolved_assets();
		}

		$editor_settings = get_block_editor_settings( $editor_settings, $block_editor_context );

		$script_handle = 'wc-admin-edit-product';
		wp_register_script( $script_handle, '', array(), '0.1.0', true );
		wp_enqueue_script( $script_handle );
		wp_add_inline_script(
			$script_handle,
			'var productBlockEditorSettings = productBlockEditorSettings || ' . wp_json_encode( $editor_settings ) . ';',
			'before'
		);
		wp_add_inline_script(
			$script_handle,
			sprintf( 'wp.blocks.setCategories( %s );', wp_json_encode( $editor_settings['blockCategories'] ) ),
			'before'
		);
		wp_tinymce_inline_scripts();
	}

	/**
	 * Enqueue styles needed for the rich text editor.
	 */
	public function enqueue_styles() {
		if ( ! PageController::is_admin_or_embed_page() ) {
			return;
		}
		wp_enqueue_style( 'wp-edit-blocks' );
		wp_enqueue_style( 'wp-format-library' );
		wp_enqueue_editor();
		/**
		 * Enqueue any block editor related assets.
		 *
		 * @since 7.1.0
		*/
		do_action( 'enqueue_block_editor_assets' );
	}

	/**
	 * Dequeue conflicting styles.
	 */
	public function dequeue_conflicting_styles() {
		if ( ! PageController::is_admin_or_embed_page() ) {
			return;
		}
		// Dequeing this to avoid conflicts, until we remove the 'woocommerce-page' class.
		wp_dequeue_style( 'woocommerce-blocktheme' );
	}

	/**
	 * Update the edit product links when the new experience is enabled.
	 *
	 * @param string $link    The edit link.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function update_edit_product_link( $link, $post_id ) {
		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return $link;
		}

		if ( $product->get_type() === 'simple' ) {
			return admin_url( 'admin.php?page=wc-admin&path=/product/' . $product->get_id() );
		}

		return $link;
	}

	/**
	 * Get the resolved assets needed for the iframe editor.
	 *
	 * @return array Styles and scripts.
	 */
	private function get_resolved_assets() {
		if ( function_exists( 'gutenberg_resolve_assets_override' ) ) {
			return gutenberg_resolve_assets_override();
		}

		global $pagenow;

		$script_handles = array(
			'wp-polyfill',
		);
		// Note for core merge: only 'wp-edit-blocks' should be in this array.
		$style_handles = array(
			'wp-edit-blocks',
		);

		if ( current_theme_supports( 'wp-block-styles' ) ) {
			$style_handles[] = 'wp-block-library-theme';
		}

		if ( 'widgets.php' === $pagenow || 'customize.php' === $pagenow ) {
			$style_handles[] = 'wp-widgets';
			$style_handles[] = 'wp-edit-widgets';
		}

		$block_registry = \WP_Block_Type_Registry::get_instance();

		foreach ( $block_registry->get_all_registered() as $block_type ) {
			// In older WordPress versions, like 6.0, these properties are not defined.
			if ( isset( $block_type->style_handles ) && is_array( $block_type->style_handles ) ) {
				$style_handles = array_merge( $style_handles, $block_type->style_handles );
			}

			if ( isset( $block_type->editor_style_handles ) && is_array( $block_type->editor_style_handles ) ) {
				$style_handles = array_merge( $style_handles, $block_type->editor_style_handles );
			}

			if ( isset( $block_type->script_handles ) && is_array( $block_type->script_handles ) ) {
				$script_handles = array_merge( $script_handles, $block_type->script_handles );
			}
		}

		$style_handles = array_unique( $style_handles );
		$done          = wp_styles()->done;

		ob_start();

		// We do not need reset styles for the iframed editor.
		wp_styles()->done = array( 'wp-reset-editor-styles' );
		wp_styles()->do_items( $style_handles );
		wp_styles()->done = $done;

		$styles = ob_get_clean();

		$script_handles = array_unique( $script_handles );
		$done           = wp_scripts()->done;

		ob_start();

		wp_scripts()->done = array();
		wp_scripts()->do_items( $script_handles );
		wp_scripts()->done = $done;

		$scripts = ob_get_clean();

		/*
		 * Generate font @font-face styles for the site editor iframe.
		 * Use the registered font families for printing.
		 */
		if ( class_exists( '\WP_Fonts' ) ) {
			$wp_fonts   = wp_fonts();
			$registered = $wp_fonts->get_registered_font_families();
			if ( ! empty( $registered ) ) {
				$queue = $wp_fonts->queue;
				$done  = $wp_fonts->done;

				$wp_fonts->done  = array();
				$wp_fonts->queue = $registered;

				ob_start();
				$wp_fonts->do_items();
				$styles .= ob_get_clean();

				// Reset the Web Fonts API.
				$wp_fonts->done  = $done;
				$wp_fonts->queue = $queue;
			}
		}

		return array(
			'styles'  => $styles,
			'scripts' => $scripts,
		);
	}

	/**
	 * Enqueue styles needed for the rich text editor.
	 *
	 * @param array $args Array of post type arguments.
	 * @return array Array of post type arguments.
	 */
	public function add_product_template( $args ) {
		if ( ! isset( $args['template'] ) ) {
			// Get the template from the registry.
			$template_registry = wc_get_container()->get( BlockTemplateRegistry::class );
			$template          = $template_registry->get_registered( 'simple-product' );

			if ( isset( $template ) ) {
				$args['template_lock'] = 'all';
				$args['template']      = $template->get_formatted_template();
			}
		}
		return $args;
	}

	/**
	 * Adds fields so that we can store user preferences for the variations block.
	 *
	 * @param array $user_data_fields User data fields.
	 * @return array
	 */
	public function add_user_data_fields( $user_data_fields ) {
		return array_merge(
			$user_data_fields,
			array(
				'variable_product_block_tour_shown',
				'product_block_variable_options_notice_dismissed',
			)
		);
	}

	/**
	 * Sets the current screen to the block editor if a wc-admin page.
	 */
	public function set_current_screen_to_block_editor_if_wc_admin() {
		$screen = get_current_screen();

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		// (no idea why I need that phpcs:ignore above, but I'm tired trying to re-write this comment to get it to pass)
		// we can't check the 'path' query param because client-side routing is used within wc-admin,
		// so this action handler is only called on the initial page load from the server, which might
		// not be the product edit page (it mostly likely isn't).
		if ( PageController::is_admin_page() ) {
			$screen->is_block_editor( true );

			wp_add_inline_script(
				'wp-blocks',
				'wp.blocks && wp.blocks.unstable__bootstrapServerSideBlockDefinitions && wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode( get_block_editor_server_block_settings() ) . ');'
			);
		}
	}
}
