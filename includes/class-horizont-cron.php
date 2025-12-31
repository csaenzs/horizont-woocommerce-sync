<?php
/**
 * Tareas programadas (Cron)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Horizont_Cron {

    /**
     * Intervalo de sincronización en segundos (1 hora)
     */
    const SYNC_INTERVAL = 3600;

    /**
     * Nombre del hook de sincronización
     */
    const SYNC_HOOK = 'horizont_sync_products_cron';

    /**
     * Nombre del hook de sincronización de stock
     */
    const STOCK_HOOK = 'horizont_sync_stock_cron';

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
        $this->init_hooks();
    }

    /**
     * Inicializar hooks
     */
    private function init_hooks() {
        // Registrar intervalo personalizado
        add_filter('cron_schedules', array($this, 'add_cron_interval'));

        // Hooks de las tareas
        add_action(self::SYNC_HOOK, array($this, 'do_sync_products'));
        add_action(self::STOCK_HOOK, array($this, 'do_sync_stock'));

        // Verificar y programar cuando cambian las opciones
        add_action('update_option_horizont_sync_options', array($this, 'maybe_schedule_sync'), 10, 2);
    }

    /**
     * Agregar intervalo de cron personalizado
     */
    public function add_cron_interval($schedules) {
        $schedules['horizont_hourly'] = array(
            'interval' => self::SYNC_INTERVAL,
            'display' => __('Cada hora (Horizont)', 'horizont-woocommerce-sync'),
        );

        $schedules['horizont_15min'] = array(
            'interval' => 900, // 15 minutos
            'display' => __('Cada 15 minutos (Horizont)', 'horizont-woocommerce-sync'),
        );

        return $schedules;
    }

    /**
     * Programar sincronización según opciones
     */
    public function maybe_schedule_sync($old_value, $new_value) {
        $this->schedule_sync();
    }

    /**
     * Programar o desprogramar tareas según configuración
     */
    public function schedule_sync() {
        $options = get_option('horizont_sync_options', array());

        // Limpiar tareas existentes
        $this->clear_scheduled_sync();

        // Si no está habilitada la sincronización automática, salir
        if (empty($options['auto_sync'])) {
            return;
        }

        // Verificar que esté configurado
        $api = Horizont_API::get_instance();
        if (!$api->is_configured()) {
            return;
        }

        // Programar según el tipo de sincronización
        if (!empty($options['sync_stock_only'])) {
            // Solo stock: cada 15 minutos
            if (!wp_next_scheduled(self::STOCK_HOOK)) {
                wp_schedule_event(time(), 'horizont_15min', self::STOCK_HOOK);
            }
        } else {
            // Sincronización completa: cada hora
            if (!wp_next_scheduled(self::SYNC_HOOK)) {
                wp_schedule_event(time(), 'horizont_hourly', self::SYNC_HOOK);
            }
        }
    }

    /**
     * Limpiar tareas programadas
     */
    public function clear_scheduled_sync() {
        $timestamp = wp_next_scheduled(self::SYNC_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::SYNC_HOOK);
        }

        $timestamp = wp_next_scheduled(self::STOCK_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::STOCK_HOOK);
        }
    }

    /**
     * Ejecutar sincronización de productos
     */
    public function do_sync_products() {
        // Evitar ejecución si no está configurado
        $api = Horizont_API::get_instance();
        if (!$api->is_configured()) {
            return;
        }

        // Marcar que estamos sincronizando
        define('HORIZONT_SYNCING', true);

        $sync = Horizont_Sync::get_instance();
        $result = $sync->sync_all_products_from_horizont();

        // Log del resultado
        if (is_wp_error($result)) {
            $api->log_sync(
                'cron_sync',
                'system',
                null,
                'error',
                $result->get_error_message()
            );
        } else {
            $api->log_sync(
                'cron_sync',
                'system',
                null,
                'success',
                sprintf(
                    'Creados: %d, Actualizados: %d, Errores: %d',
                    $result['created'],
                    $result['updated'],
                    $result['errors']
                )
            );
        }

        // Actualizar última sincronización
        $options = get_option('horizont_sync_options', array());
        $options['last_sync'] = current_time('mysql');
        $options['last_sync_result'] = is_wp_error($result) ? 'error' : 'success';
        update_option('horizont_sync_options', $options);
    }

    /**
     * Ejecutar sincronización de stock
     */
    public function do_sync_stock() {
        // Evitar ejecución si no está configurado
        $api = Horizont_API::get_instance();
        if (!$api->is_configured()) {
            return;
        }

        // Marcar que estamos sincronizando
        if (!defined('HORIZONT_SYNCING')) {
            define('HORIZONT_SYNCING', true);
        }

        $sync = Horizont_Sync::get_instance();
        $result = $sync->sync_stock_from_horizont();

        // Log del resultado
        if (is_wp_error($result)) {
            $api->log_sync(
                'cron_stock_sync',
                'system',
                null,
                'error',
                $result->get_error_message()
            );
        } else {
            $api->log_sync(
                'cron_stock_sync',
                'system',
                null,
                'success',
                sprintf(
                    'Actualizados: %d, Errores: %d',
                    $result['updated'],
                    $result['errors']
                )
            );
        }

        // Actualizar última sincronización de stock
        $options = get_option('horizont_sync_options', array());
        $options['last_stock_sync'] = current_time('mysql');
        update_option('horizont_sync_options', $options);
    }

    /**
     * Obtener próxima ejecución programada
     */
    public function get_next_scheduled() {
        $product_sync = wp_next_scheduled(self::SYNC_HOOK);
        $stock_sync = wp_next_scheduled(self::STOCK_HOOK);

        return array(
            'products' => $product_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $product_sync) : null,
            'stock' => $stock_sync ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $stock_sync) : null,
        );
    }

    /**
     * Activar al activar el plugin
     */
    public static function activate() {
        $instance = self::get_instance();
        $instance->schedule_sync();
    }

    /**
     * Desactivar al desactivar el plugin
     */
    public static function deactivate() {
        $instance = self::get_instance();
        $instance->clear_scheduled_sync();
    }
}
