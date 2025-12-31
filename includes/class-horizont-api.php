<?php
/**
 * Cliente API para comunicarse con Contagracia ERP via Edge Function
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_API {

    /**
     * URL de la Edge Function
     */
    private $api_url;

    /**
     * Token de la empresa
     */
    private $api_token;

    /**
     * Company ID (se obtiene al validar token)
     */
    private $company_id;

    /**
     * Company Name
     */
    private $company_name;

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
        $options = get_option('horizont_sync_options', array());
        $this->api_url = isset($options['api_url']) ? rtrim($options['api_url'], '/') : '';
        $this->api_token = isset($options['api_token']) ? $options['api_token'] : '';
        $this->company_id = isset($options['company_id']) ? $options['company_id'] : '';
        $this->company_name = isset($options['company_name']) ? $options['company_name'] : '';
    }

    /**
     * Verificar si está configurado
     */
    public function is_configured() {
        return !empty($this->api_url) && !empty($this->api_token);
    }

    /**
     * Obtener headers para la Edge Function
     */
    private function get_headers() {
        return array(
            'X-API-Token' => $this->api_token,
            'Content-Type' => 'application/json',
        );
    }

    /**
     * Request genérica a la Edge Function
     */
    private function request($method, $endpoint, $body = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Plugin no configurado', 'horizont-woocommerce-sync'));
        }

        $url = $this->api_url . '/' . ltrim($endpoint, '/');

        $args = array(
            'method' => $method,
            'headers' => $this->get_headers(),
            'timeout' => 30,
        );

        if ($body !== null && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($body);
        }

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont API] Request: ' . $method . ' ' . $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont API] WP Error: ' . $response->get_error_message());
            }
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont API] Response: HTTP ' . $status_code . ' - ' . substr($response_body, 0, 500));
        }

        if ($status_code >= 400) {
            $message = isset($data['error']) ? $data['error'] : __('Error en la API', 'horizont-woocommerce-sync');
            // Agregar más contexto al mensaje de error
            $message .= ' (HTTP ' . $status_code . ')';
            return new WP_Error('api_error', $message, array('status' => $status_code, 'response' => $data));
        }

        return $data;
    }

    /**
     * Validar token y obtener company info
     */
    public function validate_token() {
        // Primero verificamos que haya configuración
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Configura la URL de API y el Token primero', 'horizont-woocommerce-sync'));
        }

        // Intentar obtener productos (valida token y conexión)
        $result = $this->request('GET', 'products');

        if (is_wp_error($result)) {
            return $result;
        }

        // Si llegamos aquí, el token es válido
        $options = get_option('horizont_sync_options', array());
        $options['connected'] = true;
        update_option('horizont_sync_options', $options);

        return array(
            'success' => true,
            'message' => __('Conexión exitosa', 'horizont-woocommerce-sync'),
        );
    }

    /**
     * Obtener productos de Contagracia (solo productos padre, no variantes)
     */
    public function get_products($params = array()) {
        $result = $this->request('GET', 'products');

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['products']) ? $result['products'] : array();
    }

    /**
     * Obtener un producto por ID
     */
    public function get_product($product_id) {
        $result = $this->request('GET', 'products/' . $product_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['product']) ? $result['product'] : null;
    }

    /**
     * Obtener variantes de un producto
     */
    public function get_product_variants($parent_id) {
        $result = $this->request('GET', 'products/' . $parent_id . '/variants');

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['variants']) ? $result['variants'] : array();
    }

    /**
     * Obtener atributos de un producto (para productos variables)
     */
    public function get_product_attributes($product_id) {
        $result = $this->request('GET', 'products/' . $product_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['attributes']) ? $result['attributes'] : array();
    }

    /**
     * Obtener varianzas (valores) de una variante con nombre de atributo
     */
    public function get_variant_variances($variant_id) {
        $result = $this->request('GET', 'variants/' . $variant_id . '/variances');

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['variances']) ? $result['variances'] : array();
    }

    /**
     * Verificar si un producto tiene variantes
     */
    public function has_variants($product_id) {
        $result = $this->request('GET', 'products/' . $product_id . '/has-variants');

        if (is_wp_error($result)) {
            return false;
        }

        return isset($result['has_variants']) ? $result['has_variants'] : false;
    }

    /**
     * Obtener stock de un producto (considerando bodegas configuradas en ERP)
     */
    public function get_product_stock($inventory_item_id) {
        $result = $this->request('GET', 'stock/' . $inventory_item_id);

        if (is_wp_error($result)) {
            return 0;
        }

        return isset($result['stock']) ? intval($result['stock']) : 0;
    }

    /**
     * Obtener categorías de inventario
     */
    public function get_categories() {
        $result = $this->request('GET', 'categories');

        if (is_wp_error($result)) {
            return $result;
        }

        return isset($result['categories']) ? $result['categories'] : array();
    }

    /**
     * Guardar mapeo de producto
     */
    public function save_product_mapping($inventory_item_id, $wc_product_id, $wc_variation_id = null) {
        return $this->request('POST', 'mappings', array(
            'inventory_item_id' => $inventory_item_id,
            'external_product_id' => strval($wc_product_id),
            'external_variant_id' => $wc_variation_id ? strval($wc_variation_id) : null,
            'platform' => 'woocommerce',
        ));
    }

    /**
     * Obtener mapeos de productos
     */
    public function get_product_mappings() {
        $result = $this->request('GET', 'mappings');

        if (is_wp_error($result)) {
            return array();
        }

        return isset($result['mappings']) ? $result['mappings'] : array();
    }

    /**
     * Notificar venta al ERP
     */
    public function notify_sale($order_data) {
        return $this->request('POST', 'webhook', array(
            'event' => 'order.created',
            'data' => $order_data,
        ));
    }

    /**
     * Notificar cambio de stock al ERP
     */
    public function notify_stock_change($sku, $new_stock) {
        return $this->request('POST', 'webhook', array(
            'event' => 'stock.updated',
            'data' => array(
                'sku' => $sku,
                'stock' => $new_stock,
                'source' => 'woocommerce',
                'updated_at' => current_time('c'),
            ),
        ));
    }

    /**
     * Notificar cambio de precio al ERP
     */
    public function notify_price_change($sku, $new_price) {
        return $this->request('POST', 'webhook', array(
            'event' => 'price.updated',
            'data' => array(
                'sku' => $sku,
                'price' => $new_price,
                'source' => 'woocommerce',
                'updated_at' => current_time('c'),
            ),
        ));
    }

    /**
     * Notificar actualización de producto al ERP
     */
    public function notify_product_update($product_data) {
        return $this->request('POST', 'webhook', array(
            'event' => 'product.updated',
            'data' => array_merge($product_data, array(
                'source' => 'woocommerce',
                'updated_at' => current_time('c'),
            )),
        ));
    }

    /**
     * Log de sincronización
     */
    public function log_sync($action, $entity_type, $entity_id, $status, $message = '', $details = null) {
        $this->request('POST', 'log', array(
            'action_key' => 'ecommerce.sync.' . $action,
            'action_description' => $message ?: "Sync $action para $entity_type",
            'entity_type' => $entity_type === 'product' ? 'inventory_item' : $entity_type,
            'entity_id' => $entity_id,
            'success' => $status === 'success',
            'error_message' => $status === 'error' ? $message : null,
            'details' => $details,
        ));
    }
}
