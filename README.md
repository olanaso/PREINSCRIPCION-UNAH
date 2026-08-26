# PREINSCRIPCION-UNAH

Aplicación PHP para crear órdenes de pago de admisión y entregarlas mediante enlaces temporales.

## Instalación

```bash
composer install
php -S 127.0.0.1:8000
```

## Almacenamiento y eliminación de PDF

Los PDF y sus metadatos se guardan con permisos restrictivos en `sys_get_temp_dir()/unah-payment-orders`, fuera del directorio servido por la aplicación. Los nombres internos se derivan del hash SHA-256 de un token aleatorio de 256 bits y nunca de la identidad ni del nombre proporcionado por el usuario.

Cada orden expira **24 horas** después de generarse. La limpieza oportunista se ejecuta al guardar o consultar una orden y elimina el PDF y sus metadatos al vencer. En producción se recomienda ejecutar periódicamente una tarea que instancie `PaymentOrderStorage` y llame `purgeExpired()` (por ejemplo, cada hora) para garantizar la eliminación incluso cuando no haya tráfico.
