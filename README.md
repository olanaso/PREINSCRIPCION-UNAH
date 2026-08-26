# PREINSCRIPCION-UNAH

## Configuración del envío

La aplicación genera la orden antes de intentar el correo y conserva las órdenes en un directorio privado. Configure estas variables en el servidor:

- `APP_KEY`: secreto aleatorio usado para firmar enlaces de descarga temporales.
- `PAYMENT_ORDER_DIR`: directorio fuera de la raíz pública, escribible únicamente por el proceso PHP.
- `MAIL_FROM`: remitente institucional autorizado por el servidor de correo.

PHP debe tener configurado un transporte para `mail()`. Los reintentos usan el identificador interno devuelto por la primera solicitud y no generan una orden adicional.

Para ejecutar la prueba automatizada:

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/PaymentOrderServiceTest.php
```
