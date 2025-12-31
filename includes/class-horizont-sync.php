<?php
/**
 * Lógica de sincronización de productos
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_Sync {

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
    }

    /**
     * Sincronizar todos los productos desde Contagracia a WooCommerce
     */
    public function sync_all_products_from_horizont() {
        if (!$this->api->is_configured()) {
            return new WP_Error('not_configured', __('Plugin no configurado', 'horizont-woocommerce-sync'));
        }

        // Obtener solo productos padre (no variantes)
        $products = $this->api->get_products();

        if (is_wp_error($products)) {
            return $products;
        }

        $results = array(
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'skipped' => 0,
        );

        foreach ($products as $horizont_product) {
            $result = $this->sync_product_from_horizont($horizont_product);

            if (is_wp_error($result)) {
                $results['errors']++;
                $this->api->log_sync(
                    'pull',
                    'product',
                    $horizont_product['id'],
                    'error',
                    $result->get_error_message()
                );
            } elseif ($result === 'created') {
                $results['created']++;
            } elseif ($result === 'updated') {
                $results['updated']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Sincronizar un producto desde Contagracia a WooCommerce
     */
    public function sync_product_from_horizont($horizont_product) {
        // Usar SKU o consecutive como identificador
        $sku = !empty($horizont_product['sku']) ? $horizont_product['sku'] : $horizont_product['consecutive'];

        // Verificar si tiene identificador
        if (empty($sku)) {
            return 'skipped';
        }

        // Verificar si tiene variantes
        $has_variants = $this->api->has_variants($horizont_product['id']);

        if ($has_variants) {
            return $this->sync_variable_product($horizont_product, $sku);
        } else {
            return $this->sync_simple_product($horizont_product, $sku);
        }
    }

    /**
     * Sincronizar producto simple
     */
    private function sync_simple_product($horizont_product, $sku) {
        // Buscar si ya existe en WooCommerce por SKU
        $wc_product_id = wc_get_product_id_by_sku($sku);

        // Obtener stock
        $stock = $this->api->get_product_stock($horizont_product['id']);

        // Preparar datos del producto
        $product_data = $this->prepare_simple_product_data($horizont_product, $stock);

        if ($wc_product_id) {
            $wc_product = wc_get_product($wc_product_id);

            // Si era variable y ahora es simple, eliminar variaciones primero y luego el producto
            if ($wc_product && $wc_product->is_type('variable')) {
                // Eliminar todas las variaciones primero
                $children = $wc_product->get_children();
                foreach ($children as $child_id) {
                    wp_delete_post($child_id, true);
                }
                // Limpiar caché de SKU
                wc_delete_product_transients($wc_product_id);
                wp_delete_post($wc_product_id, true);
                $wc_product_id = 0;
            }
        }

        if ($wc_product_id) {
            // Actualizar producto existente
            $result = $this->update_simple_product($wc_product_id, $product_data);
            if (!is_wp_error($result)) {
                $this->api->save_product_mapping($horizont_product['id'], $wc_product_id);
                return 'updated';
            }
            return $result;
        } else {
            // Crear nuevo producto
            $new_product_id = $this->create_simple_product($product_data);
            if (!is_wp_error($new_product_id)) {
                $this->api->save_product_mapping($horizont_product['id'], $new_product_id);
                return 'created';
            }
            return $new_product_id;
        }
    }

    /**
     * Sincronizar producto variable (con variantes)
     */
    private function sync_variable_product($horizont_product, $sku) {
        // Buscar si ya existe en WooCommerce por SKU
        $wc_product_id = wc_get_product_id_by_sku($sku);

        // Obtener variantes de Contagracia
        $variants = $this->api->get_product_variants($horizont_product['id']);

        if (is_wp_error($variants) || empty($variants)) {
            // Si no hay variantes, sincronizar como simple
            return $this->sync_simple_product($horizont_product, $sku);
        }

        // Obtener atributos del producto
        $attributes = $this->api->get_product_attributes($horizont_product['id']);

        // Preparar datos del producto variable
        $product_data = $this->prepare_variable_product_data($horizont_product, $attributes);

        if ($wc_product_id) {
            $wc_product = wc_get_product($wc_product_id);

            // Si era simple y ahora es variable, eliminarlo y crear de nuevo
            if ($wc_product && $wc_product->is_type('simple')) {
                // Limpiar caché de SKU
                wc_delete_product_transients($wc_product_id);
                wp_delete_post($wc_product_id, true);
                $wc_product_id = 0;
            }
        }

        if ($wc_product_id) {
            // Actualizar producto variable existente
            $result = $this->update_variable_product($wc_product_id, $product_data, $variants);
            if (!is_wp_error($result)) {
                $this->api->save_product_mapping($horizont_product['id'], $wc_product_id);
                return 'updated';
            }
            return $result;
        } else {
            // Crear nuevo producto variable
            $new_product_id = $this->create_variable_product($product_data, $variants);
            if (!is_wp_error($new_product_id)) {
                $this->api->save_product_mapping($horizont_product['id'], $new_product_id);
                return 'created';
            }
            return $new_product_id;
        }
    }

    /**
     * Preparar datos de producto simple
     */
    private function prepare_simple_product_data($horizont_product, $stock) {
        $sku = !empty($horizont_product['sku']) ? $horizont_product['sku'] : $horizont_product['consecutive'];

        $data = array(
            'name' => $horizont_product['name'],
            'sku' => $sku,
            'regular_price' => floatval($horizont_product['unit_price']),
            'manage_stock' => isset($horizont_product['manage_inventory']) ? $horizont_product['manage_inventory'] : true,
            'stock_quantity' => intval($stock),
            'stock_status' => $stock > 0 ? 'instock' : 'outofstock',
        );

        if (!empty($horizont_product['description'])) {
            $data['description'] = $horizont_product['description'];
            $data['short_description'] = wp_trim_words($horizont_product['description'], 30);
        }

        if (!empty($horizont_product['category']) && !empty($horizont_product['category']['name'])) {
            $data['category_name'] = $horizont_product['category']['name'];
        }

        // Imagen del producto
        if (!empty($horizont_product['image_url'])) {
            $data['image_url'] = $horizont_product['image_url'];
        }

        return $data;
    }

    /**
     * Preparar datos de producto variable
     */
    private function prepare_variable_product_data($horizont_product, $attributes) {
        $sku = !empty($horizont_product['sku']) ? $horizont_product['sku'] : $horizont_product['consecutive'];

        $data = array(
            'name' => $horizont_product['name'],
            'sku' => $sku,
            'attributes' => array(),
        );

        if (!empty($horizont_product['description'])) {
            $data['description'] = $horizont_product['description'];
            $data['short_description'] = wp_trim_words($horizont_product['description'], 30);
        }

        if (!empty($horizont_product['category']) && !empty($horizont_product['category']['name'])) {
            $data['category_name'] = $horizont_product['category']['name'];
        }

        // Imagen del producto
        if (!empty($horizont_product['image_url'])) {
            $data['image_url'] = $horizont_product['image_url'];
        }

        // Procesar atributos
        if (!is_wp_error($attributes) && !empty($attributes)) {
            foreach ($attributes as $attr) {
                $data['attributes'][] = array(
                    'id' => $attr['inventory_attribute_id'],
                    'name' => $attr['inventory_attribute_name'],
                );
            }
        }

        return $data;
    }

    /**
     * Crear producto simple en WooCommerce
     */
    private function create_simple_product($data) {
        $product = new WC_Product_Simple();

        $product->set_name($data['name']);
        $product->set_sku($data['sku']);
        $product->set_regular_price($data['regular_price']);
        $product->set_manage_stock($data['manage_stock']);

        if ($data['manage_stock']) {
            $product->set_stock_quantity($data['stock_quantity']);
            $product->set_stock_status($data['stock_status']);
        }

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (!empty($data['category_name'])) {
            $category_id = $this->get_or_create_category($data['category_name']);
            if ($category_id) {
                $product->set_category_ids(array($category_id));
            }
        }

        $product->set_status('publish');

        try {
            $product_id = $product->save();

            // Sincronizar imagen después de guardar
            if (!empty($data['image_url'])) {
                $this->sync_product_image($product_id, $data['image_url']);
            }

            return $product_id;
        } catch (Exception $e) {
            return new WP_Error('create_failed', $e->getMessage());
        }
    }

    /**
     * Actualizar producto simple en WooCommerce
     */
    private function update_simple_product($product_id, $data) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return new WP_Error('product_not_found', __('Producto no encontrado', 'horizont-woocommerce-sync'));
        }

        $product->set_name($data['name']);
        $product->set_regular_price($data['regular_price']);
        $product->set_manage_stock($data['manage_stock']);

        if ($data['manage_stock']) {
            $product->set_stock_quantity($data['stock_quantity']);
            $product->set_stock_status($data['stock_status']);
        }

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (!empty($data['category_name'])) {
            $category_id = $this->get_or_create_category($data['category_name']);
            if ($category_id) {
                $product->set_category_ids(array($category_id));
            }
        }

        try {
            $product->save();

            // Sincronizar imagen
            if (!empty($data['image_url'])) {
                $this->sync_product_image($product_id, $data['image_url']);
            }

            return true;
        } catch (Exception $e) {
            return new WP_Error('update_failed', $e->getMessage());
        }
    }

    /**
     * Crear producto variable en WooCommerce
     */
    private function create_variable_product($data, $variants) {
        $product = new WC_Product_Variable();

        $product->set_name($data['name']);
        $product->set_sku($data['sku']);

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (!empty($data['category_name'])) {
            $category_id = $this->get_or_create_category($data['category_name']);
            if ($category_id) {
                $product->set_category_ids(array($category_id));
            }
        }

        $product->set_status('publish');

        try {
            $product_id = $product->save();

            // Sincronizar imagen del producto padre
            if (!empty($data['image_url'])) {
                $this->sync_product_image($product_id, $data['image_url']);
            }

            // Crear atributos y variaciones
            $this->create_product_attributes_and_variations($product_id, $data['attributes'], $variants);

            return $product_id;
        } catch (Exception $e) {
            return new WP_Error('create_failed', $e->getMessage());
        }
    }

    /**
     * Actualizar producto variable en WooCommerce
     */
    private function update_variable_product($product_id, $data, $variants) {
        $product = wc_get_product($product_id);

        if (!$product || !$product->is_type('variable')) {
            return new WP_Error('product_not_found', __('Producto variable no encontrado', 'horizont-woocommerce-sync'));
        }

        $product->set_name($data['name']);

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (!empty($data['category_name'])) {
            $category_id = $this->get_or_create_category($data['category_name']);
            if ($category_id) {
                $product->set_category_ids(array($category_id));
            }
        }

        try {
            $product->save();

            // Sincronizar imagen del producto padre
            if (!empty($data['image_url'])) {
                $this->sync_product_image($product_id, $data['image_url']);
            }

            // Actualizar atributos y variaciones
            $this->create_product_attributes_and_variations($product_id, $data['attributes'], $variants);

            return true;
        } catch (Exception $e) {
            return new WP_Error('update_failed', $e->getMessage());
        }
    }

    /**
     * Crear atributos y variaciones de producto
     */
    private function create_product_attributes_and_variations($product_id, $attributes, $variants) {
        // Recopilar todos los valores de atributos de las variantes
        // Estructura: $attribute_values['Color'] = ['Rojo', 'Amarillo']
        $attribute_values = array();

        foreach ($variants as $variant) {
            $variances = $this->api->get_variant_variances($variant['id']);
            if (!is_wp_error($variances) && !empty($variances)) {
                foreach ($variances as $variance) {
                    // Usar el nombre del atributo que viene de la API
                    $attr_name = isset($variance['attribute_name']) ? $variance['attribute_name'] : 'Opcion';
                    $value = $variance['inventory_variance_name'];

                    if (!isset($attribute_values[$attr_name])) {
                        $attribute_values[$attr_name] = array();
                    }
                    if (!in_array($value, $attribute_values[$attr_name])) {
                        $attribute_values[$attr_name][] = $value;
                    }
                }
            }
        }

        // Crear atributos en WooCommerce
        $wc_attributes = array();
        $position = 0;

        foreach ($attribute_values as $attr_name => $values) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_name);
            $attribute->set_options($values);
            $attribute->set_position($position);
            $attribute->set_visible(true);
            $attribute->set_variation(true);
            $wc_attributes[] = $attribute;
            $position++;
        }

        $product = wc_get_product($product_id);
        $product->set_attributes($wc_attributes);
        $product->save();

        // Eliminar variaciones existentes
        $existing_variations = $product->get_children();
        foreach ($existing_variations as $variation_id) {
            wp_delete_post($variation_id, true);
        }

        // Crear variaciones
        $variation_errors = array();
        foreach ($variants as $variant) {
            $result = $this->create_product_variation($product_id, $variant, $attribute_values);
            if (is_wp_error($result)) {
                $variation_errors[] = $result->get_error_message();
            }
        }

        // Sincronizar variaciones
        WC_Product_Variable::sync($product_id);

        // Si hubo errores en algunas variaciones, loguearlos pero no fallar todo el producto
        if (!empty($variation_errors)) {
            error_log('Contagracia Sync - Errores en variaciones del producto ' . $product_id . ': ' . implode(', ', $variation_errors));
        }
    }

    /**
     * Crear variación de producto
     */
    private function create_product_variation($product_id, $variant, $attribute_values) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);

        // SKU de la variante
        $variant_sku = !empty($variant['sku']) ? $variant['sku'] : $variant['consecutive'];
        if ($variant_sku) {
            // Verificar si el SKU ya existe en otro producto
            $existing_product_id = wc_get_product_id_by_sku($variant_sku);
            if ($existing_product_id) {
                // Si el producto existente es una variación de este mismo producto padre, eliminarla primero
                $existing_product = wc_get_product($existing_product_id);
                if ($existing_product && $existing_product->get_parent_id() == $product_id) {
                    // Es una variación nuestra, la eliminamos para recrearla
                    wp_delete_post($existing_product_id, true);
                    $variation->set_sku($variant_sku);
                } elseif ($existing_product && $existing_product->get_parent_id() == 0 && $existing_product->get_id() == $product_id) {
                    // Es el mismo producto padre, no usar el SKU en la variación
                    // Crear SKU único para la variante
                    $variation->set_sku($variant_sku . '-var-' . substr($variant['id'], 0, 8));
                } else {
                    // El SKU existe en otro producto diferente, crear SKU único
                    $variation->set_sku($variant_sku . '-' . substr($variant['id'], 0, 8));
                }
            } else {
                $variation->set_sku($variant_sku);
            }
        }

        // Precio
        $variation->set_regular_price(floatval($variant['unit_price']));

        // Stock
        $stock = $this->api->get_product_stock($variant['id']);
        $manage_stock = isset($variant['manage_inventory']) ? $variant['manage_inventory'] : true;

        $variation->set_manage_stock($manage_stock);
        if ($manage_stock) {
            $variation->set_stock_quantity(intval($stock));
            $variation->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }

        // Obtener varianzas (valores de atributos)
        $variances = $this->api->get_variant_variances($variant['id']);
        $variation_attributes = array();

        if (!is_wp_error($variances) && !empty($variances)) {
            foreach ($variances as $variance) {
                // Usar el nombre del atributo que viene de la API
                $attr_name = isset($variance['attribute_name']) ? $variance['attribute_name'] : 'Opcion';
                $value = $variance['inventory_variance_name'];

                // WooCommerce usa slugs para los atributos en variaciones
                $variation_attributes[sanitize_title($attr_name)] = $value;
            }
        }

        $variation->set_attributes($variation_attributes);

        try {
            $variation_id = $variation->save();

            // Sincronizar imagen de la variante si tiene
            if (!empty($variant['image_url'])) {
                $this->sync_variation_image($variation_id, $variant['image_url']);
            }

            // Guardar mapeo de la variante
            $this->api->save_product_mapping($variant['id'], $product_id, $variation_id);

            return $variation_id;
        } catch (WC_Data_Exception $e) {
            // Error específico de WooCommerce (como SKU duplicado)
            // Intentar sin SKU
            try {
                $variation->set_sku('');
                $variation_id = $variation->save();

                // Sincronizar imagen de la variante
                if (!empty($variant['image_url'])) {
                    $this->sync_variation_image($variation_id, $variant['image_url']);
                }

                $this->api->save_product_mapping($variant['id'], $product_id, $variation_id);
                return $variation_id;
            } catch (Exception $e2) {
                return new WP_Error('variation_failed', $e2->getMessage());
            }
        } catch (Exception $e) {
            return new WP_Error('variation_failed', $e->getMessage());
        }
    }

    /**
     * Sincronizar solo stock desde Contagracia
     */
    public function sync_stock_from_horizont() {
        if (!$this->api->is_configured()) {
            return new WP_Error('not_configured', __('Plugin no configurado', 'horizont-woocommerce-sync'));
        }

        $mappings = $this->api->get_product_mappings();

        if (empty($mappings)) {
            return array('updated' => 0, 'errors' => 0);
        }

        $results = array('updated' => 0, 'errors' => 0);

        foreach ($mappings as $mapping) {
            $stock = $this->api->get_product_stock($mapping['inventory_item_id']);

            // Determinar si es variación o producto
            $wc_id = !empty($mapping['external_variant_id'])
                ? $mapping['external_variant_id']
                : $mapping['external_product_id'];

            $wc_product = wc_get_product($wc_id);

            if ($wc_product && $wc_product->get_manage_stock()) {
                $wc_product->set_stock_quantity(intval($stock));
                $wc_product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');

                try {
                    $wc_product->save();
                    $results['updated']++;
                } catch (Exception $e) {
                    $results['errors']++;
                }
            }
        }

        return $results;
    }

    /**
     * Obtener estado de sincronización de un producto
     */
    public function get_product_sync_status($wc_product_id) {
        $mappings = $this->api->get_product_mappings();

        foreach ($mappings as $mapping) {
            if ($mapping['external_product_id'] == $wc_product_id) {
                return array(
                    'synced' => true,
                    'horizont_id' => $mapping['inventory_item_id'],
                    'last_synced' => $mapping['last_synced_at'],
                    'status' => $mapping['sync_status'],
                );
            }
        }

        return array('synced' => false);
    }

    /**
     * Obtener o crear categoría en WooCommerce
     */
    private function get_or_create_category($category_name) {
        if (empty($category_name)) {
            return null;
        }

        $existing = get_term_by('name', $category_name, 'product_cat');

        if ($existing) {
            return $existing->term_id;
        }

        $result = wp_insert_term($category_name, 'product_cat');

        if (is_wp_error($result)) {
            return null;
        }

        return $result['term_id'];
    }

    /**
     * Sincronizar categorías desde Contagracia
     */
    public function sync_categories_from_horizont() {
        $categories = $this->api->get_categories();

        if (is_wp_error($categories)) {
            return $categories;
        }

        $results = array('created' => 0, 'existing' => 0, 'errors' => 0);

        foreach ($categories as $horizont_cat) {
            $existing = get_term_by('name', $horizont_cat['name'], 'product_cat');

            if ($existing) {
                $results['existing']++;
                continue;
            }

            $result = wp_insert_term($horizont_cat['name'], 'product_cat');

            if (is_wp_error($result)) {
                $results['errors']++;
            } else {
                $results['created']++;
            }
        }

        return $results;
    }

    /**
     * Sincronizar imagen de producto desde URL
     *
     * @param int $product_id ID del producto en WooCommerce
     * @param string $image_url URL de la imagen
     * @return int|false ID del attachment o false si falla
     */
    private function sync_product_image($product_id, $image_url) {
        if (empty($image_url)) {
            return false;
        }

        // Verificar si la imagen ya existe para este producto
        $current_image_id = get_post_thumbnail_id($product_id);
        if ($current_image_id) {
            // Verificar si la URL actual coincide
            $current_url = get_post_meta($current_image_id, '_horizont_source_url', true);
            if ($current_url === $image_url) {
                // Imagen ya sincronizada, no hacer nada
                return $current_image_id;
            }
        }

        // Descargar la imagen
        $image_data = $this->download_image($image_url);

        if (is_wp_error($image_data) || empty($image_data)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Sync] Error descargando imagen: ' . $image_url);
            }
            return false;
        }

        // Obtener nombre del archivo de la URL
        $filename = basename(parse_url($image_url, PHP_URL_PATH));

        // Asegurar extensión válida
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            $filename .= '.jpg';
        }

        // Subir a la biblioteca de medios de WordPress
        $upload = wp_upload_bits($filename, null, $image_data);

        if ($upload['error']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Horizont Sync] Error subiendo imagen: ' . $upload['error']);
            }
            return false;
        }

        // Crear attachment
        $wp_filetype = wp_check_filetype($filename);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $product_id);

        if (is_wp_error($attachment_id)) {
            return false;
        }

        // Generar metadata de la imagen
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        // Guardar URL de origen para evitar re-descargas
        update_post_meta($attachment_id, '_horizont_source_url', $image_url);

        // Asignar como imagen destacada del producto
        set_post_thumbnail($product_id, $attachment_id);

        // Eliminar imagen anterior si existía
        if ($current_image_id && $current_image_id !== $attachment_id) {
            wp_delete_attachment($current_image_id, true);
        }

        return $attachment_id;
    }

    /**
     * Descargar imagen desde URL
     *
     * @param string $url URL de la imagen
     * @return string|WP_Error Contenido binario de la imagen o error
     */
    private function download_image($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false, // En producción considerar activar
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error('download_failed', 'HTTP ' . $response_code);
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return new WP_Error('empty_response', 'Respuesta vacía');
        }

        return $body;
    }

    /**
     * Sincronizar imagen de variación
     *
     * @param int $variation_id ID de la variación
     * @param string $image_url URL de la imagen
     * @return int|false ID del attachment o false si falla
     */
    private function sync_variation_image($variation_id, $image_url) {
        if (empty($image_url)) {
            return false;
        }

        $variation = wc_get_product($variation_id);
        if (!$variation) {
            return false;
        }

        // Verificar si ya tiene imagen
        $current_image_id = $variation->get_image_id();
        if ($current_image_id) {
            $current_url = get_post_meta($current_image_id, '_horizont_source_url', true);
            if ($current_url === $image_url) {
                return $current_image_id;
            }
        }

        // Descargar la imagen
        $image_data = $this->download_image($image_url);

        if (is_wp_error($image_data) || empty($image_data)) {
            return false;
        }

        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        if (!preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $filename)) {
            $filename .= '.jpg';
        }

        $upload = wp_upload_bits($filename, null, $image_data);

        if ($upload['error']) {
            return false;
        }

        $wp_filetype = wp_check_filetype($filename);
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment($attachment, $upload['file'], $variation->get_parent_id());

        if (is_wp_error($attachment_id)) {
            return false;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);

        update_post_meta($attachment_id, '_horizont_source_url', $image_url);

        // Asignar imagen a la variación
        $variation->set_image_id($attachment_id);
        $variation->save();

        // Eliminar imagen anterior
        if ($current_image_id && $current_image_id !== $attachment_id) {
            wp_delete_attachment($current_image_id, true);
        }

        return $attachment_id;
    }
}
