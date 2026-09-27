<?php
/**
 * Plugin Name: lunexia SMTP Pro
 * Description: A lightweight Gmail OAuth2 & SMTP plugin for WordPress. Easy to set up and use, with secure authentication and email logging.
 * Version: 1.0.0
 * Author: lunexiait
 * Author URI: https://lunexiait.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lunexia-smtp-pro
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'LUNEXIA_SMTP_VERSION', '1.0.0' );
define( 'LUNEXIA_SMTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUNEXIA_SMTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LUNEXIA_SMTP_INCLUDES', LUNEXIA_SMTP_PLUGIN_DIR . 'includes/' );
define( 'LUNEXIA_SMTP_ADMIN', LUNEXIA_SMTP_PLUGIN_DIR . 'admin/' );
define( 'LUNEXIA_SMTP_ASSETS', LUNEXIA_SMTP_PLUGIN_URL . 'assets/' );

// Autoload plugin files
require_once LUNEXIA_SMTP_INCLUDES . 'Database.php';
require_once LUNEXIA_SMTP_INCLUDES . 'GmailOAuth2.php';
require_once LUNEXIA_SMTP_INCLUDES . 'PHPMailerHooks.php';
require_once LUNEXIA_SMTP_ADMIN . 'Settings.php';

/**
 * Initialize the plugin
 */
class Lunexia_SMTP {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->load_hooks();
	}

	public function load_hooks() {
		// Register activation and deactivation hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Load text domain for translations
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Initialize database
		add_action( 'plugins_loaded', array( $this, 'init_database' ) );

		// Initialize settings
		add_action( 'plugins_loaded', array( $this, 'init_settings' ) );

		// Initialize OAuth handler
		add_action( 'plugins_loaded', array( $this, 'init_oauth' ) );

		// Initialize PHPMailer hooks
		add_action( 'plugins_loaded', array( $this, 'init_phpmailer' ) );

		// Enqueue admin assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'lunexia-smtp-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function activate() {
		// Create database table on plugin activation
		$database = new Lunexia_SMTP_Database();
		$database->create_logs_table();

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	public function deactivate() {
		// Clean up on deactivation if needed
		flush_rewrite_rules();
	}

	public function init_database() {
		new Lunexia_SMTP_Database();
	}

	public function init_settings() {
		new Lunexia_SMTP_Settings();
	}

	public function init_oauth() {
		new Lunexia_SMTP_GmailOAuth2();
	}

	public function init_phpmailer() {
		new Lunexia_SMTP_PHPMailerHooks();
	}

	public function enqueue_admin_assets( $hook ) {
		// Enqueue notice CSS globally for admin users with proper permissions
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			$css_content = '
				.lite-smtp-notice-box {
					background: #131519 !important;
					border: 1px solid rgba(255, 255, 255, 0.12) !important;
					border-left: 4px solid #A1FA4C !important;
					border-radius: 16px !important;
					box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3) !important;
					color: #ffffff !important;
					margin: 20px 0 !important;
					padding: 24px !important;
					position: relative !important;
					overflow: hidden !important;
					font-family: "Inter", "Helvetica Neue", Helvetica, Arial, sans-serif !important;
				}
				.lite-smtp-notice-box.lite-smtp-donate-notice {
					border-left-color: #f59e0b !important;
				}
				.lite-smtp-notice-header {
					display: flex !important;
					flex-wrap: wrap !important;
					justify-content: space-between !important;
					gap: 16px !important;
					align-items: flex-start !important;
				}
				.lite-smtp-notice-title strong {
					display: block !important;
					font-size: 18px !important;
					font-weight: 600 !important;
					color: #ffffff !important;
					margin-bottom: 8px !important;
					line-height: 1.4 !important;
				}
				.lite-smtp-notice-title p {
					color: #e0e0e0 !important;
					margin: 0 !important;
					line-height: 1.6 !important;
					font-size: 15px !important;
					font-weight: 400 !important;
				}
				.lite-smtp-notice-close {
					background: rgba(255, 255, 255, 0.05) !important;
					border: 1px solid rgba(255, 255, 255, 0.1) !important;
					color: #8e9299 !important;
					border-radius: 8px !important;
					padding: 6px !important;
					cursor: pointer !important;
					line-height: 0 !important;
					transition: all 0.2s ease !important;
				}
				.lite-smtp-notice-close:hover {
					background: rgba(255, 255, 255, 0.1) !important;
					color: #ffffff !important;
				}
				.lite-smtp-notice-actions {
					display: flex !important;
					flex-wrap: wrap !important;
					gap: 12px !important;
					margin-top: 20px !important;
				}
				.lite-smtp-notice-btn, .lite-smtp-notice-actions .button {
					display: inline-flex !important;
					align-items: center !important;
					justify-content: center !important;
					min-height: 36px !important;
					padding: 0 20px !important;
					border-radius: 8px !important;
					font-weight: 600 !important;
					font-size: 13px !important;
					white-space: nowrap !important;
					transition: opacity 0.2s ease !important;
					cursor: pointer !important;
				}
				.lite-smtp-notice-actions .button-primary, .lite-smtp-notice-btn.button-primary {
					background: #A1FA4C !important;
					color: #000000 !important;
					border: none !important;
					box-shadow: 0 4px 15px rgba(161, 250, 76, 0.4) !important;
				}
				.lite-smtp-notice-actions .button-primary:hover, .lite-smtp-notice-btn.button-primary:hover {
					box-shadow: 0 6px 20px rgba(161, 250, 76, 0.6) !important;
				}
				.lite-smtp-notice-actions .button:not(.button-primary), .lite-smtp-notice-btn:not(.button-primary) {
					background: rgba(255, 255, 255, 0.05) !important;
					color: #ffffff !important;
					border: 1px solid rgba(255, 255, 255, 0.1) !important;
				}
				.lite-smtp-notice-btn.button-primary:hover, .lite-smtp-notice-actions .button-primary:hover {
					box-shadow: 0 6px 20px rgba(161, 250, 76, 0.6) !important;
				}
				.lite-smtp-notice-btn:not(.button-primary):hover, .lite-smtp-notice-actions .button:not(.button-primary):hover {
					opacity: 0.9 !important;
				}
			';
			wp_add_inline_style( 'wp-admin', $css_content );
		}

		// Only load full admin assets on our settings page
		if ( 'settings_page_lunexia-smtp-pro' !== $hook ) {
			return;
		}

		// Add version with timestamp to prevent caching
		$css_file = LUNEXIA_SMTP_PLUGIN_DIR . 'assets/admin.css';
		$version  = file_exists( $css_file ) ? LUNEXIA_SMTP_VERSION . '.' . filemtime( $css_file ) : LUNEXIA_SMTP_VERSION;

		wp_enqueue_style(
			'lunexia-smtp-admin',
			LUNEXIA_SMTP_ASSETS . 'admin.css',
			array(),
			$version
		);

		wp_enqueue_script(
			'lunexia-smtp-admin',
			LUNEXIA_SMTP_ASSETS . 'admin.js',
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script(
			'lunexia-smtp-admin',
			'lunexiaSMTPData',
			array(
				'ajaxurl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lunexia_smtp_nonce' ),
				'security'        => wp_create_nonce( 'lunexia_smtp_test_email' ),
				'settingsPageUrl' => admin_url( 'options-general.php?page=lunexia-smtp-pro' ),
			)
		);
	}
}

// Initialize the plugin
Lunexia_SMTP::get_instance();
