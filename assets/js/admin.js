/**
 * JavaScript del panel de administración de Horizont Sync
 */

(function($) {
    'use strict';

    /**
     * Mostrar resultado de una operación
     */
    function showResult($element, success, message) {
        $element.removeClass('loading success error');
        $element.addClass(success ? 'success' : 'error');
        $element.addClass('horizont-result');
        $element.html(message);
    }

    /**
     * Mostrar loading
     */
    function showLoading($element, message) {
        $element.removeClass('success error');
        $element.addClass('loading horizont-result');
        $element.html('<span class="horizont-spinner"></span>' + message);
    }

    /**
     * Probar conexión
     */
    $('#horizont-test-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#horizont-test-result');

        $button.prop('disabled', true);
        showLoading($result, horizontSync.strings.testing);

        $.ajax({
            url: horizontSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'horizont_test_connection',
                nonce: horizontSync.nonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, true, '✓ ' + response.data.message);
                } else {
                    showResult($result, false, '✗ ' + response.data.message);
                }
            },
            error: function() {
                showResult($result, false, '✗ Error de conexión');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Sincronizar productos
     */
    $('#horizont-sync-products').on('click', function() {
        var $button = $(this);
        var $result = $('#horizont-sync-result');

        if (!confirm('¿Iniciar sincronización de productos? Esto puede tomar varios minutos.')) {
            return;
        }

        $button.prop('disabled', true);
        showLoading($result, horizontSync.strings.syncing);

        $.ajax({
            url: horizontSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'horizont_sync_products',
                nonce: horizontSync.nonce
            },
            timeout: 300000, // 5 minutos
            success: function(response) {
                if (response.success) {
                    showResult($result, true, '✓ ' + response.data.message);
                } else {
                    showResult($result, false, '✗ ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showResult($result, false, '✗ Tiempo de espera agotado. La sincronización puede seguir en segundo plano.');
                } else {
                    showResult($result, false, '✗ Error: ' + error);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Sincronizar solo stock
     */
    $('#horizont-sync-stock').on('click', function() {
        var $button = $(this);
        var $result = $('#horizont-stock-result');

        $button.prop('disabled', true);
        showLoading($result, horizontSync.strings.syncing);

        $.ajax({
            url: horizontSync.ajaxUrl,
            type: 'POST',
            data: {
                action: 'horizont_sync_stock',
                nonce: horizontSync.nonce
            },
            timeout: 120000, // 2 minutos
            success: function(response) {
                if (response.success) {
                    showResult($result, true, '✓ ' + response.data.message);
                } else {
                    showResult($result, false, '✗ ' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showResult($result, false, '✗ Tiempo de espera agotado');
                } else {
                    showResult($result, false, '✗ Error: ' + error);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Confirmación antes de guardar configuración
     */
    $('form').on('submit', function(e) {
        var $apiUrl = $('#api_url');
        var $token = $('#api_token');

        // Validar que los campos obligatorios estén llenos
        if ($apiUrl.length && !$apiUrl.val().trim()) {
            alert('Por favor ingresa la URL de API');
            $apiUrl.focus();
            e.preventDefault();
            return false;
        }

        if ($token.length && !$token.val().trim()) {
            alert('Por favor ingresa el Token de API');
            $token.focus();
            e.preventDefault();
            return false;
        }

        // Validar formato de URL
        if ($apiUrl.length && $apiUrl.val()) {
            var urlPattern = /^https?:\/\/.+/i;
            if (!urlPattern.test($apiUrl.val())) {
                alert('La URL de API debe comenzar con http:// o https://');
                $apiUrl.focus();
                e.preventDefault();
                return false;
            }
        }

        return true;
    });

    /**
     * Toggle para mostrar/ocultar campos de contraseña
     */
    $('.toggle-password').on('click', function() {
        var $input = $(this).prev('input');
        var type = $input.attr('type') === 'password' ? 'text' : 'password';
        $input.attr('type', type);
        $(this).text(type === 'password' ? 'Mostrar' : 'Ocultar');
    });

})(jQuery);
