=== Contagracia WooCommerce Sync ===
Contributors: contagracia
Tags: woocommerce, erp, sync, inventory, stock
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincroniza el inventario de tu tienda WooCommerce con Contagracia ERP.

== Description ==

Contagracia WooCommerce Sync permite mantener sincronizado tu inventario entre WooCommerce y Contagracia ERP.

**Características principales:**

* Sincronización de productos (nombre, SKU, precio, descripción, categoría)
* Sincronización de stock en tiempo real
* Soporte para múltiples bodegas
* Recepción de pedidos desde WooCommerce al ERP
* Sincronización automática programada (cada hora)
* Panel de administración intuitivo

**Requisitos:**

* WooCommerce 5.0 o superior
* PHP 7.4 o superior
* Cuenta activa en Contagracia ERP

== Installation ==

1. Sube la carpeta `horizont-woocommerce-sync` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú 'Plugins' en WordPress
3. Ve a 'Contagracia Sync' en el menú de administración
4. Configura las credenciales de conexión:
   - API URL: la URL del servicio de integraciones de Contagracia (ej. `https://tu-dominio.com/api/ecommerce/plugin`)
   - Token de API: el token `ctg_xxxx` generado en Contagracia
5. Prueba la conexión y comienza a sincronizar

== Configuration ==

**Obtener credenciales:**

1. Inicia sesión en Contagracia
2. Ve a Configuración > Perfil de Empresa > Integraciones > E-commerce
3. Genera un nuevo token de API (empieza con `ctg_`)
4. Copia la URL de la API que aparece en esa misma pantalla

**Configurar bodegas:**

Si tu empresa tiene múltiples bodegas, desde Contagracia puedes seleccionar cuáles incluir en el cálculo de stock disponible para WooCommerce. El plugin usará automáticamente esa configuración.

**Webhook (opcional):**

Para recibir pedidos de WooCommerce en Contagracia en tiempo real, configura la URL del webhook en la sección E-commerce de Contagracia. El plugin enviará notificaciones automáticas cuando se cree o actualice un pedido.

== Frequently Asked Questions ==

= ¿Qué datos se sincronizan? =

* Productos: nombre, SKU, precio, descripción, stock, categoría
* Variantes de producto
* Pedidos recibidos en WooCommerce (hacia Contagracia)

= ¿En qué dirección se sincroniza? =

Contagracia es la fuente de verdad (Contagracia → WooCommerce). Los pedidos fluyen en dirección opuesta (WooCommerce → Contagracia).

= ¿Con qué frecuencia se sincroniza automáticamente? =

Cada hora se ejecuta una sincronización completa de productos y stock.

= ¿Qué pasa si un producto no tiene SKU? =

Los productos sin SKU se omiten durante la sincronización.

= ¿Cómo se autentican las peticiones? =

El plugin envía el token `ctg_xxxx` en el header `X-API-Token` en cada petición al API de Contagracia. No se requiere usuario ni contraseña adicional.

== Changelog ==

= 1.2.0 =
* Envío de cambios de estado de órdenes al backend (processing, completed, cancelled, refunded)
* Nuevo método send_webhook() en API client para envío genérico de eventos

= 1.1.0 =
* Limpieza automática de productos eliminados/inactivados en Contagracia al sincronizar
* Soporte para inventario negativo (backorders habilitados automáticamente)
* Excluir platos de restaurante de la sincronización

= 1.0.0 =
* Versión inicial
* Sincronización de productos e inventario desde Contagracia ERP
* Panel de administración
* Soporte para múltiples bodegas
* Recepción de pedidos desde WooCommerce
* Autenticación mediante token `ctg_xxxx`

== Upgrade Notice ==

= 1.2.0 =
Actualización importante: los cambios de estado de pedidos ahora se envían automáticamente a Contagracia.

= 1.1.0 =
Mejoras en sincronización: limpieza de productos eliminados, soporte backorders.

= 1.0.0 =
Versión inicial del plugin.
