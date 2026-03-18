<?php
/**
 * Plugin Name: Contagracia Sync
 * Plugin URI: https://contagracia.com
 * Description: Sincroniza el inventario de tu tienda WooCommerce con Contagracia ERP
 * Version: 1.0.0
 * Author: Arawana
 * Author URI: https://arawana.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: horizont-woocommerce-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.4
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes del plugin
define('HORIZONT_SYNC_VERSION', '1.0.0');
define('HORIZONT_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HORIZONT_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HORIZONT_SYNC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Clase principal del plugin
 */
final class Horizont_WooCommerce_Sync {

    /**
     * Instancia única
     */
    private static $instance = null;

    /**
     * Obtener instancia
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Cargar dependencias
     */
    private function load_dependencies() {
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-api.php';
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-sync.php';
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-admin.php';
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-webhooks.php';
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-webhook-receiver.php';
        require_once HORIZONT_SYNC_PLUGIN_DIR . 'includes/class-horizont-cron.php';
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Verificar WooCommerce
        add_action('admin_init', array($this, 'check_woocommerce'));

        // Activación y desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Cargar traducciones
        add_action('init', array($this, 'load_textdomain'));

        // Inicializar componentes
        add_action('plugins_loaded', array($this, 'init_components'));

        // Handler para guardar bodegas
        add_action('admin_post_horizont_save_storages', array($this, 'save_storages'));
    }

    /**
     * Verificar si WooCommerce está activo
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            deactivate_plugins(HORIZONT_SYNC_PLUGIN_BASENAME);
        }
    }

    /**
     * Aviso de WooCommerce faltante
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Contagracia Sync requiere WooCommerce para funcionar.', 'horizont-woocommerce-sync'); ?></p>
        </div>
        <?php
    }

    /**
     * Cargar traducciones
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'horizont-woocommerce-sync',
            false,
            dirname(HORIZONT_SYNC_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Inicializar componentes
     */
    public function init_components() {
        if (!class_exists('WooCommerce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Contagracia Sync] WooCommerce not active, skipping init');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Contagracia Sync] Initializing components...');
        }

        // Inicializar clases singleton
        Horizont_API::get_instance();
        Horizont_Sync::get_instance();
        Horizont_Admin::get_instance();
        Horizont_Webhooks::get_instance();
        Horizont_Webhook_Receiver::get_instance();
        Horizont_Cron::get_instance();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Contagracia Sync] All components initialized. REST routes should be registered.');
        }
    }

    /**
     * Activación del plugin
     */
    public function activate() {
        // Crear opciones por defecto
        $default_options = array(
            'supabase_url' => '',
            'supabase_anon_key' => '',
            'api_token' => '',
            'company_id' => '',
            'company_name' => '',
            'integration_id' => '',
            'storage_ids' => array(),
            'sync_direction' => 'horizont_to_woo',
            'auto_sync' => 0,
            'sync_stock_only' => 0,
            'last_sync' => null,
            'last_stock_sync' => null,
        );

        if (!get_option('horizont_sync_options')) {
            add_option('horizont_sync_options', $default_options);
        }

        // Activar cron
        Horizont_Cron::activate();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Desactivar cron
        Horizont_Cron::deactivate();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Guardar selección de bodegas
     */
    public function save_storages() {
        // Verificar nonce
        if (!isset($_POST['horizont_storage_nonce']) ||
            !wp_verify_nonce($_POST['horizont_storage_nonce'], 'horizont_save_storages')) {
            wp_die(__('Acción no autorizada', 'horizont-woocommerce-sync'));
        }

        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Permisos insuficientes', 'horizont-woocommerce-sync'));
        }

        // Obtener bodegas seleccionadas
        $storage_ids = isset($_POST['storage_ids']) ? array_map('sanitize_text_field', $_POST['storage_ids']) : array();

        // Guardar en opciones
        $options = get_option('horizont_sync_options', array());
        $options['storage_ids'] = $storage_ids;
        update_option('horizont_sync_options', $options);

        // Redirigir con mensaje de éxito
        wp_redirect(add_query_arg(
            array(
                'page' => 'horizont-sync',
                'message' => 'storages_saved',
            ),
            admin_url('admin.php')
        ));
        exit;
    }
}

/**
 * Obtener instancia del plugin
 */
function horizont_woocommerce_sync() {
    return Horizont_WooCommerce_Sync::get_instance();
}

// Arrancar plugin
horizont_woocommerce_sync();
