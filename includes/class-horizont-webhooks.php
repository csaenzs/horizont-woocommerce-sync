<?php
/**
 * Manejo de webhooks y eventos de WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_Webhooks {

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
     * Inicializar hooks de WooCommerce
     */
    private function init_hooks() {
        // Solo si está configurado
        if (!$this->api->is_configured()) {
            return;
        }

        // Eventos de pedidos (para notificar ventas al ERP)
        add_action('woocommerce_new_order', array($this, 'on_new_order'), 10, 2);
        add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);
        add_action('woocommerce_order_status_completed', array($this, 'on_order_completed'), 10, 2);
        add_action('woocommerce_order_status_cancelled', array($this, 'on_order_cancelled'), 10, 2);
        add_action('woocommerce_order_status_refunded', array($this, 'on_order_refunded'), 10, 2);

        // Reducción de stock al completar pedido
        add_action('woocommerce_reduce_order_stock', array($this, 'on_order_stock_reduced'), 10, 1);
    }

    /**
     * Cuando se crea un nuevo pedido
     */
    public function on_new_order($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $order_data = $this->prepare_order_data($order);

        // Notificar al ERP
        $result = $this->api->notify_sale($order_data);

        if (is_wp_error($result)) {
            $this->api->log_sync(
                'webhook_received',
                'order',
                $order_id,
                'error',
                $result->get_error_message(),
                $order_data
            );
        } else {
            $this->api->log_sync(
                'webhook_received',
                'order',
                $order_id,
                'success',
                '',
                $order_data,
                $result
            );
        }

        // Guardar flag en el pedido
        $order->update_meta_data('_horizont_notified', 'yes');
        $order->update_meta_data('_horizont_notified_at', current_time('mysql'));
        $order->save();
    }

    /**
     * Cuando cambia el estado del pedido
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        $order_data = array(
            'order_id' => $order_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'updated_at' => current_time('c'),
        );

        $this->api->log_sync(
            'order_status_changed',
            'order',
            $order_id,
            'success',
            '',
            $order_data
        );
    }

    /**
     * Cuando el pedido se completa
     */
    public function on_order_completed($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        // Si no se notificó antes, notificar ahora
        if ($order->get_meta('_horizont_notified') !== 'yes') {
            $this->on_new_order($order_id, $order);
        }

        // Marcar como completado en el log
        $this->api->log_sync(
            'order_completed',
            'order',
            $order_id,
            'success'
        );
    }

    /**
     * Cuando el pedido se cancela
     */
    public function on_order_cancelled($order_id, $order = null) {
        $this->api->log_sync(
            'order_cancelled',
            'order',
            $order_id,
            'success',
            'Pedido cancelado - stock debe restaurarse'
        );
    }

    /**
     * Cuando el pedido se reembolsa
     */
    public function on_order_refunded($order_id, $order = null) {
        $this->api->log_sync(
            'order_refunded',
            'order',
            $order_id,
            'success',
            'Pedido reembolsado'
        );
    }

    /**
     * Cuando se reduce el stock por un pedido
     */
    public function on_order_stock_reduced($order) {
        $order_id = $order->get_id();

        // Recopilar info de stock reducido
        $items_data = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->managing_stock()) {
                $items_data[] = array(
                    'product_id' => $product->get_id(),
                    'sku' => $product->get_sku(),
                    'quantity_reduced' => $item->get_quantity(),
                    'new_stock' => $product->get_stock_quantity(),
                );
            }
        }

        $this->api->log_sync(
            'stock_reduced',
            'order',
            $order_id,
            'success',
            '',
            array('items' => $items_data)
        );
    }

    /**
     * Cuando cambia el stock de un producto
     */
    public function on_stock_changed($product) {
        $options = get_option('horizont_sync_options', array());

        // Solo procesar si la sincronización es bidireccional
        if (empty($options['sync_direction']) || $options['sync_direction'] !== 'bidirectional') {
            return;
        }

        // Evitar loops infinitos durante sincronización
        if (defined('HORIZONT_SYNCING') && HORIZONT_SYNCING) {
            return;
        }

        $sku = $product->get_sku();
        $new_stock = $product->get_stock_quantity();

        // Solo enviar si tiene SKU
        if (empty($sku)) {
            return;
        }

        // Enviar cambio de stock al ERP
        $result = $this->api->notify_stock_change($sku, $new_stock);

        $status = is_wp_error($result) ? 'error' : 'success';
        $message = is_wp_error($result) ? $result->get_error_message() : 'Stock sincronizado a Contagracia ERP';

        $this->api->log_sync(
            'stock_changed_woo',
            'product',
            $product->get_id(),
            $status,
            $message,
            array(
                'sku' => $sku,
                'new_stock' => $new_stock,
            )
        );
    }

    /**
     * Cuando cambia el stock de una variación
     */
    public function on_variation_stock_changed($variation) {
        $this->on_stock_changed($variation);
    }

    /**
     * Cuando se actualiza un producto
     */
    public function on_product_updated($product_id, $product) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhooks] on_product_updated called for product ID: ' . $product_id);
        }

        $options = get_option('horizont_sync_options', array());

        // Solo procesar si la sincronización es bidireccional
        if (empty($options['sync_direction']) || $options['sync_direction'] !== 'bidirectional') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: sync_direction is "' . ($options['sync_direction'] ?? 'not set') . '" (needs "bidirectional")');
            }
            return;
        }

        // Evitar loops infinitos
        if (defined('HORIZONT_SYNCING') && HORIZONT_SYNCING) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: HORIZONT_SYNCING is true (avoiding loop)');
            }
            return;
        }

        // Obtener el producto si no viene como objeto
        if (!is_object($product)) {
            $product = wc_get_product($product_id);
        }

        if (!$product) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Could not get product object');
            }
            return;
        }

        $sku = $product->get_sku();

        // Solo enviar si tiene SKU (para poder identificarlo en el ERP)
        if (empty($sku)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Product has no SKU');
            }
            return;
        }

        // Preparar datos del producto
        $product_data = array(
            'sku' => $sku,
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock' => $product->get_stock_quantity(),
            'wc_product_id' => $product_id,
        );

        // Enviar al ERP
        $result = $this->api->notify_product_update($product_data);

        $status = is_wp_error($result) ? 'error' : 'success';
        $message = is_wp_error($result) ? $result->get_error_message() : 'Producto sincronizado a Contagracia ERP';

        $this->api->log_sync(
            'product_updated_woo',
            'product',
            $product_id,
            $status,
            $message,
            $product_data
        );
    }

    /**
     * Cuando se crea un producto nuevo
     */
    public function on_product_created($product_id, $product) {
        $options = get_option('horizont_sync_options', array());

        // Solo procesar si la sincronización es bidireccional
        if (empty($options['sync_direction']) || $options['sync_direction'] !== 'bidirectional') {
            return;
        }

        // Evitar loops infinitos
        if (defined('HORIZONT_SYNCING') && HORIZONT_SYNCING) {
            return;
        }

        $this->api->log_sync(
            'product_created_woo',
            'product',
            $product_id,
            'pending',
            'Producto creado en WooCommerce'
        );
    }

    /**
     * Hook alternativo: cuando se guarda un producto (más confiable)
     */
    public function on_product_save($post_id, $post, $update) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhooks] on_product_save called for post ID: ' . $post_id . ' (update: ' . ($update ? 'yes' : 'no') . ')');
        }

        // Solo procesar actualizaciones, no creaciones
        if (!$update) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Not an update');
            }
            return;
        }

        // Evitar auto-saves y revisiones
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Autosave or revision');
            }
            return;
        }

        // Verificar que es un producto publicado
        if ($post->post_status !== 'publish') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Post status is "' . $post->post_status . '" (needs "publish")');
            }
            return;
        }

        $options = get_option('horizont_sync_options', array());

        // Solo procesar si la sincronización es bidireccional
        if (empty($options['sync_direction']) || $options['sync_direction'] !== 'bidirectional') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: sync_direction is "' . ($options['sync_direction'] ?? 'not set') . '" (needs "bidirectional")');
            }
            return;
        }

        // Evitar loops infinitos
        if (defined('HORIZONT_SYNCING') && HORIZONT_SYNCING) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: HORIZONT_SYNCING is true (avoiding loop)');
            }
            return;
        }

        // Obtener el producto WooCommerce
        $product = wc_get_product($post_id);

        if (!$product) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Could not get WooCommerce product');
            }
            return;
        }

        // Solo productos simples y variables (no variaciones individuales)
        if ($product->is_type('variation')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Product is a variation');
            }
            return;
        }

        $sku = $product->get_sku();

        // Solo enviar si tiene SKU
        if (empty($sku)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Webhooks] Skipped: Product has no SKU');
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhooks] Processing product update for SKU: ' . $sku);
        }

        // Preparar datos del producto
        $product_data = array(
            'sku' => $sku,
            'name' => $product->get_name(),
            'description' => $product->get_description(),
            'price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock' => $product->get_stock_quantity(),
            'wc_product_id' => $post_id,
        );

        // Enviar al ERP
        $result = $this->api->notify_product_update($product_data);

        $status = is_wp_error($result) ? 'error' : 'success';
        $message = is_wp_error($result) ? $result->get_error_message() : 'Producto sincronizado a Contagracia ERP';

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Horizont Webhooks] Product sync result: ' . $status . ' - ' . $message);
        }

        $this->api->log_sync(
            'product_updated_woo',
            'product',
            $post_id,
            $status,
            $message,
            $product_data
        );
    }

    /**
     * Preparar datos del pedido para enviar al ERP
     */
    private function prepare_order_data($order) {
        $items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            $item_data = array(
                'product_id' => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'subtotal' => floatval($item->get_subtotal()),
                'total' => floatval($item->get_total()),
                'tax' => floatval($item->get_total_tax()),
            );

            if ($product) {
                $item_data['sku'] = $product->get_sku();
                $item_data['unit_price'] = floatval($product->get_price());
            }

            $items[] = $item_data;
        }

        return array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'currency' => $order->get_currency(),
            'subtotal' => floatval($order->get_subtotal()),
            'total_tax' => floatval($order->get_total_tax()),
            'total' => floatval($order->get_total()),
            'discount_total' => floatval($order->get_discount_total()),
            'shipping_total' => floatval($order->get_shipping_total()),
            'payment_method' => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->format('c') : null,
            'customer' => array(
                'id' => $order->get_customer_id(),
                'email' => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'phone' => $order->get_billing_phone(),
                'company' => $order->get_billing_company(),
            ),
            'billing' => array(
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'company' => $order->get_billing_company(),
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ),
            'shipping' => array(
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
            ),
            'items' => $items,
            'source' => 'woocommerce',
            'store_url' => home_url(),
        );
    }
}
