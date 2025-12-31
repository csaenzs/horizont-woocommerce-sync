<?php
/**
 * Panel de administración del plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_Admin {

    /**
     * Instancia de la API
     */
    private $api;

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
        $this->api = Horizont_API::get_instance();
        $this->init_hooks();
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_horizont_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_horizont_sync_products', array($this, 'ajax_sync_products'));
        add_action('wp_ajax_horizont_sync_stock', array($this, 'ajax_sync_stock'));
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Contagracia Sync', 'horizont-woocommerce-sync'),
            __('Contagracia Sync', 'horizont-woocommerce-sync'),
            'manage_woocommerce',
            'horizont-sync',
            array($this, 'render_settings_page'),
            'dashicons-update',
            56
        );

        add_submenu_page(
            'horizont-sync',
            __('Configuración', 'horizont-woocommerce-sync'),
            __('Configuración', 'horizont-woocommerce-sync'),
            'manage_woocommerce',
            'horizont-sync',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'horizont-sync',
            __('Productos', 'horizont-woocommerce-sync'),
            __('Productos', 'horizont-woocommerce-sync'),
            'manage_woocommerce',
            'horizont-products',
            array($this, 'render_products_page')
        );

        add_submenu_page(
            'horizont-sync',
            __('Logs', 'horizont-woocommerce-sync'),
            __('Logs', 'horizont-woocommerce-sync'),
            'manage_woocommerce',
            'horizont-logs',
            array($this, 'render_logs_page')
        );
    }

    /**
     * Registrar opciones
     */
    public function register_settings() {
        register_setting('horizont_sync_options', 'horizont_sync_options', array($this, 'sanitize_options'));

        // Sección de conexión
        add_settings_section(
            'horizont_connection_section',
            __('Conexión con Contagracia ERP', 'horizont-woocommerce-sync'),
            array($this, 'render_connection_section'),
            'horizont-sync'
        );

        add_settings_field(
            'api_url',
            __('URL de API', 'horizont-woocommerce-sync'),
            array($this, 'render_text_field'),
            'horizont-sync',
            'horizont_connection_section',
            array(
                'label_for' => 'api_url',
                'description' => __('URL de la API de Contagracia (proporcionada en el ERP)', 'horizont-woocommerce-sync'),
                'placeholder' => 'https://xxxxx.supabase.co/functions/v1/ecommerce-api',
            )
        );

        add_settings_field(
            'api_token',
            __('Token de API', 'horizont-woocommerce-sync'),
            array($this, 'render_password_field'),
            'horizont-sync',
            'horizont_connection_section',
            array(
                'label_for' => 'api_token',
                'description' => __('Token de ecommerce generado en Contagracia ERP (ej: hzt_xxxx)', 'horizont-woocommerce-sync'),
            )
        );

        // Sección de sincronización
        add_settings_section(
            'horizont_sync_section',
            __('Opciones de Sincronización', 'horizont-woocommerce-sync'),
            array($this, 'render_sync_section'),
            'horizont-sync'
        );

        // Dirección de sincronización fija: solo ERP → WooCommerce
        // (La opción bidireccional fue removida para simplificar)

        add_settings_field(
            'auto_sync',
            __('Sincronización automática', 'horizont-woocommerce-sync'),
            array($this, 'render_checkbox_field'),
            'horizont-sync',
            'horizont_sync_section',
            array(
                'label_for' => 'auto_sync',
                'description' => __('Sincronizar automáticamente cada hora', 'horizont-woocommerce-sync'),
            )
        );

        add_settings_field(
            'sync_stock_only',
            __('Solo sincronizar stock', 'horizont-woocommerce-sync'),
            array($this, 'render_checkbox_field'),
            'horizont-sync',
            'horizont_sync_section',
            array(
                'label_for' => 'sync_stock_only',
                'description' => __('Solo actualizar cantidades de stock (más rápido)', 'horizont-woocommerce-sync'),
            )
        );
    }

    /**
     * Sanitizar opciones
     */
    public function sanitize_options($input) {
        $sanitized = array();

        if (isset($input['api_url'])) {
            $sanitized['api_url'] = esc_url_raw(trim($input['api_url']));
        }

        if (isset($input['api_token'])) {
            $sanitized['api_token'] = sanitize_text_field($input['api_token']);
        }

        if (isset($input['sync_direction'])) {
            $sanitized['sync_direction'] = sanitize_text_field($input['sync_direction']);
        }

        $sanitized['auto_sync'] = isset($input['auto_sync']) ? 1 : 0;
        $sanitized['sync_stock_only'] = isset($input['sync_stock_only']) ? 1 : 0;

        // Preservar datos existentes que no vienen en el form
        $existing = get_option('horizont_sync_options', array());
        if (isset($existing['company_id'])) {
            $sanitized['company_id'] = $existing['company_id'];
        }
        if (isset($existing['company_name'])) {
            $sanitized['company_name'] = $existing['company_name'];
        }
        if (isset($existing['integration_id'])) {
            $sanitized['integration_id'] = $existing['integration_id'];
        }
        if (isset($existing['storage_ids'])) {
            $sanitized['storage_ids'] = $existing['storage_ids'];
        }

        return $sanitized;
    }

    /**
     * Cargar scripts de administración
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'horizont') === false) {
            return;
        }

        wp_enqueue_style(
            'horizont-admin',
            HORIZONT_SYNC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            HORIZONT_SYNC_VERSION
        );

        wp_enqueue_script(
            'horizont-admin',
            HORIZONT_SYNC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            HORIZONT_SYNC_VERSION,
            true
        );

        wp_localize_script('horizont-admin', 'horizontSync', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('horizont_sync_nonce'),
            'strings' => array(
                'testing' => __('Probando conexión...', 'horizont-woocommerce-sync'),
                'syncing' => __('Sincronizando...', 'horizont-woocommerce-sync'),
                'success' => __('Éxito', 'horizont-woocommerce-sync'),
                'error' => __('Error', 'horizont-woocommerce-sync'),
            ),
        ));
    }

    /**
     * Renderizar sección de conexión
     */
    public function render_connection_section() {
        echo '<p>' . esc_html__('Configura la conexión con tu Contagracia ERP.', 'horizont-woocommerce-sync') . '</p>';

        $options = get_option('horizont_sync_options', array());
        if (!empty($options['company_name'])) {
            echo '<div class="notice notice-success inline"><p>';
            printf(
                esc_html__('Conectado a: %s', 'horizont-woocommerce-sync'),
                '<strong>' . esc_html($options['company_name']) . '</strong>'
            );
            echo '</p></div>';
        }
    }

    /**
     * Renderizar sección de sincronización
     */
    public function render_sync_section() {
        echo '<p>' . esc_html__('Configura cómo se sincronizarán los productos.', 'horizont-woocommerce-sync') . '</p>';
    }

    /**
     * Renderizar campo de texto
     */
    public function render_text_field($args) {
        $options = get_option('horizont_sync_options', array());
        $value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : '';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        ?>
        <input type="text"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="horizont_sync_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo esc_attr($value); ?>"
               placeholder="<?php echo esc_attr($placeholder); ?>"
               class="regular-text"
               style="width: 100%; max-width: 500px;">
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Renderizar campo de contraseña
     */
    public function render_password_field($args) {
        $options = get_option('horizont_sync_options', array());
        $value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : '';
        ?>
        <input type="password"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="horizont_sync_options[<?php echo esc_attr($args['label_for']); ?>]"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text">
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Renderizar campo select
     */
    public function render_select_field($args) {
        $options = get_option('horizont_sync_options', array());
        $value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : '';
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
                name="horizont_sync_options[<?php echo esc_attr($args['label_for']); ?>]">
            <?php foreach ($args['options'] as $key => $label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Renderizar campo checkbox
     */
    public function render_checkbox_field($args) {
        $options = get_option('horizont_sync_options', array());
        $value = isset($options[$args['label_for']]) ? $options[$args['label_for']] : 0;
        ?>
        <label>
            <input type="checkbox"
                   id="<?php echo esc_attr($args['label_for']); ?>"
                   name="horizont_sync_options[<?php echo esc_attr($args['label_for']); ?>]"
                   value="1"
                   <?php checked($value, 1); ?>>
            <?php if (isset($args['description'])): ?>
                <?php echo esc_html($args['description']); ?>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('horizont_sync_options');
                do_settings_sections('horizont-sync');
                submit_button(__('Guardar cambios', 'horizont-woocommerce-sync'));
                ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Acciones', 'horizont-woocommerce-sync'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Probar conexión', 'horizont-woocommerce-sync'); ?></th>
                    <td>
                        <button type="button" class="button" id="horizont-test-connection">
                            <?php esc_html_e('Probar conexión', 'horizont-woocommerce-sync'); ?>
                        </button>
                        <span id="horizont-test-result"></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sincronizar productos', 'horizont-woocommerce-sync'); ?></th>
                    <td>
                        <button type="button" class="button button-primary" id="horizont-sync-products">
                            <?php esc_html_e('Sincronizar ahora', 'horizont-woocommerce-sync'); ?>
                        </button>
                        <span id="horizont-sync-result"></span>
                        <p class="description">
                            <?php esc_html_e('Importa productos desde Contagracia ERP a WooCommerce.', 'horizont-woocommerce-sync'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Sincronizar solo stock', 'horizont-woocommerce-sync'); ?></th>
                    <td>
                        <button type="button" class="button" id="horizont-sync-stock">
                            <?php esc_html_e('Actualizar stock', 'horizont-woocommerce-sync'); ?>
                        </button>
                        <span id="horizont-stock-result"></span>
                        <p class="description">
                            <?php esc_html_e('Actualiza solo las cantidades de stock (más rápido).', 'horizont-woocommerce-sync'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e('Sincronización automática (Webhooks)', 'horizont-woocommerce-sync'); ?></h3>
            <p class="description">
                <?php esc_html_e('Para recibir actualizaciones automáticas cuando cambien productos en Contagracia ERP, copia esta URL y pégala en la configuración de Integración Ecommerce de tu empresa.', 'horizont-woocommerce-sync'); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('URL del Webhook', 'horizont-woocommerce-sync'); ?></th>
                    <td>
                        <?php $webhook_url = Horizont_Webhook_Receiver::get_webhook_url(); ?>
                        <input type="text"
                               id="horizont-webhook-url"
                               value="<?php echo esc_attr($webhook_url); ?>"
                               class="regular-text"
                               style="width: 100%; max-width: 500px;"
                               readonly>
                        <button type="button" class="button" id="horizont-copy-webhook" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); this.innerText='<?php esc_attr_e('¡Copiado!', 'horizont-woocommerce-sync'); ?>'; setTimeout(() => this.innerText='<?php esc_attr_e('Copiar', 'horizont-woocommerce-sync'); ?>', 2000);">
                            <?php esc_html_e('Copiar', 'horizont-woocommerce-sync'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Esta URL permite que Contagracia ERP notifique automáticamente a tu tienda cuando cambien productos o stock.', 'horizont-woocommerce-sync'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Estado del Webhook', 'horizont-woocommerce-sync'); ?></th>
                    <td>
                        <?php
                        $options = get_option('horizont_sync_options', array());
                        $is_configured = !empty($options['api_token']) && !empty($options['api_url']);
                        ?>
                        <?php if ($is_configured): ?>
                            <span class="horizont-status horizont-status-synced" style="color: green;">
                                ✓ <?php esc_html_e('Listo para recibir webhooks', 'horizont-woocommerce-sync'); ?>
                            </span>
                        <?php else: ?>
                            <span class="horizont-status horizont-status-pending" style="color: orange;">
                                ⚠ <?php esc_html_e('Configura primero la conexión con Contagracia ERP', 'horizont-woocommerce-sync'); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <hr>
            <h3><?php esc_html_e('Configuración de bodegas', 'horizont-woocommerce-sync'); ?></h3>
            <p class="description">
                <?php esc_html_e('Las bodegas para el cálculo de stock se configuran en Contagracia ERP, en la sección de Integración Ecommerce del perfil de empresa.', 'horizont-woocommerce-sync'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Renderizar página de productos
     */
    public function render_products_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $mappings = $this->api->get_product_mappings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Productos sincronizados', 'horizont-woocommerce-sync'); ?></h1>

            <?php if (empty($mappings)): ?>
                <p><?php esc_html_e('No hay productos sincronizados aún.', 'horizont-woocommerce-sync'); ?></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Producto WooCommerce', 'horizont-woocommerce-sync'); ?></th>
                            <th><?php esc_html_e('ID Contagracia', 'horizont-woocommerce-sync'); ?></th>
                            <th><?php esc_html_e('Estado', 'horizont-woocommerce-sync'); ?></th>
                            <th><?php esc_html_e('Última sincronización', 'horizont-woocommerce-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mappings as $mapping): ?>
                            <?php
                            $wc_product = wc_get_product($mapping['external_product_id']);
                            $product_name = $wc_product ? $wc_product->get_name() : __('(Eliminado)', 'horizont-woocommerce-sync');
                            ?>
                            <tr>
                                <td>
                                    <?php if ($wc_product): ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($mapping['external_product_id'])); ?>">
                                            <?php echo esc_html($product_name); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($product_name); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($mapping['inventory_item_id']); ?></td>
                                <td>
                                    <span class="horizont-status horizont-status-<?php echo esc_attr($mapping['sync_status']); ?>">
                                        <?php echo esc_html($mapping['sync_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if ($mapping['last_synced_at']) {
                                        echo esc_html(
                                            date_i18n(
                                                get_option('date_format') . ' ' . get_option('time_format'),
                                                strtotime($mapping['last_synced_at'])
                                            )
                                        );
                                    } else {
                                        esc_html_e('Nunca', 'horizont-woocommerce-sync');
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderizar página de logs
     */
    public function render_logs_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Logs de sincronización', 'horizont-woocommerce-sync'); ?></h1>
            <p class="description">
                <?php esc_html_e('Los logs de sincronización se guardan en Contagracia ERP. Accede a tu panel de Contagracia para ver el historial completo.', 'horizont-woocommerce-sync'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: Probar conexión
     */
    public function ajax_test_connection() {
        check_ajax_referer('horizont_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permisos insuficientes', 'horizont-woocommerce-sync')));
        }

        $result = $this->api->validate_token();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => isset($result['message']) ? $result['message'] : __('Conexión exitosa', 'horizont-woocommerce-sync'),
        ));
    }

    /**
     * AJAX: Sincronizar productos
     */
    public function ajax_sync_products() {
        check_ajax_referer('horizont_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permisos insuficientes', 'horizont-woocommerce-sync')));
        }

        $sync = Horizont_Sync::get_instance();
        $result = $sync->sync_all_products_from_horizont();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Creados: %d, Actualizados: %d, Errores: %d', 'horizont-woocommerce-sync'),
                $result['created'],
                $result['updated'],
                $result['errors']
            ),
        ));
    }

    /**
     * AJAX: Sincronizar solo stock
     */
    public function ajax_sync_stock() {
        check_ajax_referer('horizont_sync_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permisos insuficientes', 'horizont-woocommerce-sync')));
        }

        $sync = Horizont_Sync::get_instance();
        $result = $sync->sync_stock_from_horizont();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('Actualizados: %d, Errores: %d', 'horizont-woocommerce-sync'),
                $result['updated'],
                $result['errors']
            ),
        ));
    }
}
