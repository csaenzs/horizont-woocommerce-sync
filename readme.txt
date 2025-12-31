=== Horizont WooCommerce Sync ===
Contributors: horizont
Tags: woocommerce, erp, sync, inventory, stock
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincroniza el inventario de tu tienda WooCommerce con Horizont ERP.

== Description ==

Horizont WooCommerce Sync permite mantener sincronizado tu inventario entre WooCommerce y Horizont ERP.

**Características principales:**

* Sincronización de productos (nombre, SKU, precio, descripción)
* Sincronización de stock en tiempo real
* Soporte para múltiples bodegas
* Notificación de ventas al ERP
* Sincronización automática programada
* Panel de administración intuitivo

**Requisitos:**

* WooCommerce 5.0 o superior
* PHP 7.4 o superior
* Cuenta activa en Horizont ERP

== Installation ==

1. Sube la carpeta `horizont-woocommerce-sync` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú 'Plugins' en WordPress
3. Ve a 'Horizont Sync' en el menú de administración
4. Configura las credenciales de conexión:
   - URL de Supabase
   - Anon Key de Supabase
   - Token de API (generado en Horizont ERP)
5. Prueba la conexión y comienza a sincronizar

== Configuration ==

**Obtener credenciales:**

1. Inicia sesión en Horizont ERP
2. Ve a Configuración > Perfil de Empresa > Integraciones E-commerce
3. Genera un nuevo token de API
4. Copia la URL de Supabase y el Anon Key de tu proyecto

**Configurar bodegas:**

Si tu empresa tiene múltiples bodegas, puedes seleccionar cuáles incluir en el cálculo de stock disponible para WooCommerce.

== Frequently Asked Questions ==

= ¿Qué datos se sincronizan? =

* Productos: nombre, SKU, precio, descripción, stock
* Categorías (opcional)
* Notificaciones de ventas

= ¿En qué dirección se sincroniza? =

Por defecto, Horizont es la fuente de verdad (Horizont → WooCommerce). Opcionalmente se puede habilitar sincronización bidireccional.

= ¿Con qué frecuencia se sincroniza automáticamente? =

Cada hora para sincronización completa, cada 15 minutos si solo se sincroniza stock.

= ¿Qué pasa si un producto no tiene SKU? =

Los productos sin SKU se omiten durante la sincronización.

== Changelog ==

= 1.0.0 =
* Versión inicial
* Sincronización de productos e inventario
* Panel de administración
* Soporte para bodegas
* Notificación de ventas al ERP

== Upgrade Notice ==

= 1.0.0 =
Versión inicial del plugin.
