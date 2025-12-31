<?php
/**
 * Receptor de webhooks desde el ERP (Contagracia → WooCommerce)
 *
 * Este endpoint recibe notificaciones cuando cambian productos/stock en el ERP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_Webhook_Receiver {

    /**
     * Namespace de la REST API
     */
    const API_NAMESPACE = 'horizont/v1';

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
        add_action('rest_api_init', array($this, 'register_routes'));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhook Receiver] Constructor called, hook added for rest_api_init');
        }
    }

    /**
     * Registrar rutas REST API
     */
    public function register_routes() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhook Receiver] register_routes() called');
        }

        // Endpoint principal para recibir webhooks
        register_rest_route(self::API_NAMESPACE, '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => array($this, 'verify_webhook_signature'),
        ));

        // Endpoint para verificar que el webhook está activo (health check)
        register_rest_route(self::API_NAMESPACE, '/webhook/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'webhook_status'),
            'permission_callback' => '__return_true',
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhook Receiver] Routes registered: /webhook and /webhook/status');
        }
    }

    /**
     * Verificar firma/token del webhook
     */
    public function verify_webhook_signature($request) {
        $options = get_option('horizont_sync_options', array());
        $stored_token = isset($options['api_token']) ? $options['api_token'] : '';

        if (empty($stored_token)) {
            return new WP_Error(
                'not_configured',
                __('Plugin no configurado', 'horizont-woocommerce-sync'),
                array('status' => 503)
            );
        }

        // Verificar token en header
        $received_token = $request->get_header('X-Webhook-Token');

        if (empty($received_token)) {
            // También aceptar en X-API-Token para compatibilidad
            $received_token = $request->get_header('X-API-Token');
        }

        if (empty($received_token) || !hash_equals($stored_token, $received_token)) {
            return new WP_Error(
                'unauthorized',
                __('Token inválido', 'horizont-woocommerce-sync'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Estado del webhook (health check)
     */
    public function webhook_status($request) {
        $options = get_option('horizont_sync_options', array());
        $is_configured = !empty($options['api_token']) && !empty($options['api_url']);

        return new WP_REST_Response(array(
            'status' => 'ok',
            'configured' => $is_configured,
            'version' => HORIZONT_SYNC_VERSION,
            'webhook_url' => rest_url(self::API_NAMESPACE . '/webhook'),
        ), 200);
    }

    /**
     * Manejar webhook entrante
     */
    public function handle_webhook($request) {
        $body = $request->get_json_params();

        if (empty($body)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Empty request body',
            ), 400);
        }

        $event = isset($body['event']) ? sanitize_text_field($body['event']) : '';
        $data = isset($body['data']) ? $body['data'] : array();

        if (empty($event)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Missing event type',
            ), 400);
        }

        // Log del webhook recibido
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhook] Received: ' . $event . ' - ' . json_encode($data));
        }

        // Definir flag para evitar loops
        if (!defined('HORIZONT_SYNCING')) {
            define('HORIZONT_SYNCING', true);
        }

        // Procesar según el tipo de evento
        switch ($event) {
            case 'product.updated':
                $result = $this->handle_product_updated($data);
                break;

            case 'product.created':
                $result = $this->handle_product_created($data);
                break;

            case 'product.deleted':
                $result = $this->handle_product_deleted($data);
                break;

            case 'stock.updated':
                $result = $this->handle_stock_updated($data);
                break;

            case 'price.updated':
                $result = $this->handle_price_updated($data);
                break;

            case 'bulk.sync':
                $result = $this->handle_bulk_sync($data);
                break;

            default:
                $result = array(
                    'success' => false,
                    'error' => 'Unknown event type: ' . $event,
                );
        }

        // Log resultado
        $api = Horizont_API::get_instance();
        $api->log_sync(
            'webhook_received',
            'webhook',
            $event,
            $result['success'] ? 'success' : 'error',
            isset($result['error']) ? $result['error'] : '',
            $data
        );

        $status_code = $result['success'] ? 200 : 400;
        return new WP_REST_Response($result, $status_code);
    }

    /**
     * Manejar actualización de producto
     */
    private function handle_product_updated($data) {
        if (empty($data['inventory_item_id'])) {
            return array('success' => false, 'error' => 'Missing inventory_item_id');
        }

        $inventory_item_id = sanitize_text_field($data['inventory_item_id']);

        // Buscar producto en WooCommerce por mapeo
        $wc_product_id = $this->get_wc_product_by_erp_id($inventory_item_id);

        if (!$wc_product_id) {
            // Producto no existe en WooCommerce, crearlo
            return $this->handle_product_created($data);
        }

        $product = wc_get_product($wc_product_id);
        if (!$product) {
            return array('success' => false, 'error' => 'WooCommerce product not found');
        }

        // Actualizar campos
        $updated = false;

        if (isset($data['name'])) {
            $product->set_name(sanitize_text_field($data['name']));
            $updated = true;
        }

        if (isset($data['description'])) {
            $product->set_description(wp_kses_post($data['description']));
            $updated = true;
        }

        if (isset($data['unit_price'])) {
            $product->set_regular_price(floatval($data['unit_price']));
            $updated = true;
        }

        if (isset($data['sku'])) {
            try {
                $product->set_sku(sanitize_text_field($data['sku']));
                $updated = true;
            } catch (WC_Data_Exception $e) {
                // SKU duplicado, ignorar
            }
        }

        if ($updated) {
            $product->save();
        }

        // Actualizar stock si viene incluido
        if (isset($data['stock']) || isset($data['quantity'])) {
            $stock = isset($data['stock']) ? intval($data['stock']) : intval($data['quantity']);
            $this->update_product_stock($wc_product_id, $stock);
        }

        // Actualizar imagen si viene incluida
        if (!empty($data['image_url'])) {
            $sync = Horizont_Sync::get_instance();
            // Usar reflection para acceder al método privado o hacer público
            $this->sync_product_image_from_url($wc_product_id, $data['image_url']);
        }

        return array(
            'success' => true,
            'message' => 'Product updated',
            'wc_product_id' => $wc_product_id,
        );
    }

    /**
     * Manejar creación de producto
     */
    private function handle_product_created($data) {
        if (empty($data['inventory_item_id'])) {
            return array('success' => false, 'error' => 'Missing inventory_item_id');
        }

        // Verificar si ya existe
        $existing = $this->get_wc_product_by_erp_id($data['inventory_item_id']);
        if ($existing) {
            return $this->handle_product_updated($data);
        }

        // Crear producto simple (las variantes se manejan aparte)
        $product = new WC_Product_Simple();

        $product->set_name(sanitize_text_field($data['name'] ?? 'Producto sin nombre'));

        if (!empty($data['description'])) {
            $product->set_description(wp_kses_post($data['description']));
        }

        if (!empty($data['unit_price'])) {
            $product->set_regular_price(floatval($data['unit_price']));
        }

        if (!empty($data['sku'])) {
            try {
                $product->set_sku(sanitize_text_field($data['sku']));
            } catch (WC_Data_Exception $e) {
                // SKU duplicado
            }
        }

        // Stock
        if (isset($data['stock']) || isset($data['quantity'])) {
            $stock = isset($data['stock']) ? intval($data['stock']) : intval($data['quantity']);
            $product->set_manage_stock(true);
            $product->set_stock_quantity($stock);
            $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }

        $product->set_status('publish');

        try {
            $product_id = $product->save();

            // Guardar mapeo
            update_post_meta($product_id, '_horizont_inventory_item_id', $data['inventory_item_id']);

            // También guardar en el ERP via API
            $api = Horizont_API::get_instance();
            $api->save_product_mapping($data['inventory_item_id'], $product_id);

            // Imagen
            if (!empty($data['image_url'])) {
                $this->sync_product_image_from_url($product_id, $data['image_url']);
            }

            return array(
                'success' => true,
                'message' => 'Product created',
                'wc_product_id' => $product_id,
            );
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Manejar eliminación de producto
     */
    private function handle_product_deleted($data) {
        if (empty($data['inventory_item_id'])) {
            return array('success' => false, 'error' => 'Missing inventory_item_id');
        }

        $wc_product_id = $this->get_wc_product_by_erp_id($data['inventory_item_id']);

        if (!$wc_product_id) {
            return array('success' => true, 'message' => 'Product not found in WooCommerce');
        }

        // Mover a papelera en lugar de eliminar permanentemente
        $product = wc_get_product($wc_product_id);
        if ($product) {
            $product->set_status('trash');
            $product->save();
        }

        return array(
            'success' => true,
            'message' => 'Product moved to trash',
            'wc_product_id' => $wc_product_id,
        );
    }

    /**
     * Manejar actualización de stock
     */
    private function handle_stock_updated($data) {
        if (empty($data['inventory_item_id'])) {
            return array('success' => false, 'error' => 'Missing inventory_item_id');
        }

        $inventory_item_id = sanitize_text_field($data['inventory_item_id']);
        $new_stock = isset($data['stock']) ? intval($data['stock']) : (isset($data['quantity']) ? intval($data['quantity']) : null);

        if ($new_stock === null) {
            return array('success' => false, 'error' => 'Missing stock quantity');
        }

        $wc_product_id = $this->get_wc_product_by_erp_id($inventory_item_id);

        if (!$wc_product_id) {
            return array('success' => false, 'error' => 'Product not mapped in WooCommerce');
        }

        $result = $this->update_product_stock($wc_product_id, $new_stock);

        if ($result) {
            return array(
                'success' => true,
                'message' => 'Stock updated',
                'wc_product_id' => $wc_product_id,
                'new_stock' => $new_stock,
            );
        }

        return array('success' => false, 'error' => 'Failed to update stock');
    }

    /**
     * Manejar actualización de precio
     */
    private function handle_price_updated($data) {
        if (empty($data['inventory_item_id'])) {
            return array('success' => false, 'error' => 'Missing inventory_item_id');
        }

        $inventory_item_id = sanitize_text_field($data['inventory_item_id']);
        $new_price = isset($data['unit_price']) ? floatval($data['unit_price']) : null;

        if ($new_price === null) {
            return array('success' => false, 'error' => 'Missing price');
        }

        $wc_product_id = $this->get_wc_product_by_erp_id($inventory_item_id);

        if (!$wc_product_id) {
            return array('success' => false, 'error' => 'Product not mapped in WooCommerce');
        }

        $product = wc_get_product($wc_product_id);
        if (!$product) {
            return array('success' => false, 'error' => 'WooCommerce product not found');
        }

        $product->set_regular_price($new_price);
        $product->save();

        return array(
            'success' => true,
            'message' => 'Price updated',
            'wc_product_id' => $wc_product_id,
            'new_price' => $new_price,
        );
    }

    /**
     * Manejar sincronización masiva
     */
    private function handle_bulk_sync($data) {
        // Disparar sincronización completa
        $sync = Horizont_Sync::get_instance();
        $result = $sync->sync_all_products();

        return array(
            'success' => true,
            'message' => 'Bulk sync completed',
            'result' => $result,
        );
    }

    /**
     * Obtener producto WooCommerce por ID del ERP
     */
    private function get_wc_product_by_erp_id($inventory_item_id) {
        global $wpdb;

        // Primero buscar en meta local
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_horizont_inventory_item_id'
             AND meta_value = %s
             LIMIT 1",
            $inventory_item_id
        ));

        if ($product_id) {
            return intval($product_id);
        }

        // Si no está en meta local, buscar en los mapeos del ERP
        $api = Horizont_API::get_instance();
        $mappings = $api->get_product_mappings();

        if (!empty($mappings)) {
            foreach ($mappings as $mapping) {
                if ($mapping['inventory_item_id'] === $inventory_item_id &&
                    !empty($mapping['external_product_id'])) {
                    $product_id = intval($mapping['external_product_id']);

                    // Guardar en meta local para futuras búsquedas
                    update_post_meta($product_id, '_horizont_inventory_item_id', $inventory_item_id);

                    return $product_id;
                }
            }
        }

        return null;
    }

    /**
     * Actualizar stock de producto
     */
    private function update_product_stock($product_id, $stock) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return false;
        }

        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock);
        $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        $product->save();

        return true;
    }

    /**
     * Sincronizar imagen de producto desde URL
     */
    private function sync_product_image_from_url($product_id, $image_url) {
        if (empty($image_url)) {
            return false;
        }

        // Verificar si la imagen ya existe
        $current_image_id = get_post_thumbnail_id($product_id);
        if ($current_image_id) {
            $current_url = get_post_meta($current_image_id, '_horizont_source_url', true);
            if ($current_url === $image_url) {
                return $current_image_id;
            }
        }

        // Descargar imagen
        $response = wp_remote_get($image_url, array(
            'timeout' => 30,
            'sslverify' => false,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return false;
        }

        // Nombre del archivo
        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            $filename .= '.jpg';
        }

        // Subir a WordPress
        $upload = wp_upload_bits($filename, null, $image_data);
        if ($upload['error']) {
            return false;
        }

        // Crear attachment
        $wp_filetype = wp_check_filetype($filename);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $product_id);

        if (is_wp_error($attachment_id)) {
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        // Guardar URL de origen
        update_post_meta($attachment_id, '_horizont_source_url', $image_url);

        // Asignar como imagen destacada
        set_post_thumbnail($product_id, $attachment_id);

        return $attachment_id;
    }

    /**
     * Obtener URL del webhook para configuración
     */
    public static function get_webhook_url() {
        return rest_url(self::API_NAMESPACE . '/webhook');
    }
}
