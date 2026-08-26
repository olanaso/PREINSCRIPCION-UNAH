# PREINSCRIPCION-UNAH

## Enlaces temporales de esquelas

Configure `PAYMENT_ORDER_SIGNING_KEY` con un secreto aleatorio de al menos 32 caracteres y, opcionalmente, `PAYMENT_ORDER_STORAGE_DIR` fuera del directorio público. Los enlaces caducan a los 10 minutos, usan identificadores aleatorios y una firma HMAC-SHA256. Un proceso programado debe eliminar los PDF vencidos del almacenamiento.

El botón de WhatsApp abre el selector de contactos con un texto prellenado; por diseño, `wa.me` no puede adjuntar un archivo automáticamente. En navegadores compatibles se ofrece primero el Web Share API para compartir el PDF como archivo.

## Envío automático (integración separada)

Si se requiere que la institución envíe el documento automáticamente, debe implementarse en un servicio backend separado mediante la API oficial de WhatsApp Business/Cloud. Guarde `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` y cualquier secreto de webhook **solo en variables de entorno**, nunca en JavaScript, URLs ni el repositorio. Antes de habilitarlo se requiere:

1. consentimiento explícito (opt-in) del destinatario y registro auditable de ese consentimiento;
2. una plantilla aprobada por Meta para conversaciones iniciadas por la institución fuera de la ventana permitida;
3. aviso de privacidad, mecanismo de baja y política de retención/eliminación del PDF;
4. validación de webhooks, rotación de credenciales y control de acceso al almacenamiento;
5. uso del código de postulante, evitando DNI, correo u otros datos sensibles en mensajes y URLs.
Aplicación PHP para crear órdenes de pago de admisión y entregarlas mediante enlaces temporales.

## Instalación

```bash
composer install
php -S 127.0.0.1:8000
```

## Almacenamiento y eliminación de PDF

Los PDF y sus metadatos se guardan con permisos restrictivos en `sys_get_temp_dir()/unah-payment-orders`, fuera del directorio servido por la aplicación. Los nombres internos se derivan del hash SHA-256 de un token aleatorio de 256 bits y nunca de la identidad ni del nombre proporcionado por el usuario.

Cada orden expira **24 horas** después de generarse. La limpieza oportunista se ejecuta al guardar o consultar una orden y elimina el PDF y sus metadatos al vencer. En producción se recomienda ejecutar periódicamente una tarea que instancie `PaymentOrderStorage` y llame `purgeExpired()` (por ejemplo, cada hora) para garantizar la eliminación incluso cuando no haya tráfico.
