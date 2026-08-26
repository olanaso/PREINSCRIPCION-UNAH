# PREINSCRIPCION-UNAH

Aplicación para generar órdenes de pago de preinscripción y enviarlas por correo mediante SMTP.

## Requisitos

- PHP 8.1 o superior.
- Composer.
- Una cuenta SMTP habilitada por el administrador institucional.

## Configuración del correo

1. Instale las dependencias:

   ```bash
   composer install
   ```

2. Copie la plantilla de configuración y reemplace los valores ilustrativos por los datos entregados por su proveedor SMTP:

   ```bash
   cp .env.example .env
   ```

3. Configure estas variables en `.env`:

   | Variable | Descripción | Ejemplo |
   | --- | --- | --- |
   | `MAIL_HOST` | Servidor SMTP | `smtp.example.com` |
   | `MAIL_PORT` | Puerto SMTP (1 a 65535) | `587` |
   | `MAIL_USERNAME` | Usuario SMTP | `usuario@example.com` |
   | `MAIL_PASSWORD` | Contraseña o token SMTP | `contrasena-ilustrativa` |
   | `MAIL_ENCRYPTION` | Cifrado, `tls` o `ssl` | `tls` |
   | `MAIL_FROM_ADDRESS` | Remitente válido | `no-responder@example.com` |

   `.env` está excluido de Git. Nunca copie credenciales reales a `.env.example`, al código fuente o a los registros de la aplicación.

4. Cargue el entorno y la configuración al iniciar la aplicación:

   ```php
   require __DIR__ . '/vendor/autoload.php';

   Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
   $mailConfig = require __DIR__ . '/config/mail.php';
   ```

El archivo `config/mail.php` detiene la inicialización con un error de configuración si falta una variable, si el puerto está fuera de rango, si el cifrado no es compatible o si el remitente no es válido.

## Envío de una orden

Genere primero el PDF y pase su ruta al servicio de correo:

```php
use App\Mail\PaymentOrderMailer;

$mailer = new PaymentOrderMailer($mailConfig);
$mailer->send(
    'postulante@example.com',
    ['Código' => 'ORD-0001', 'Monto' => 'S/ 100.00'],
    __DIR__ . '/storage/orden-0001.pdf'
);
```

El servicio valida el destinatario y el archivo, escapa los datos incluidos en el HTML y adjunta el PDF. Si falla la conexión o el envío, lanza un mensaje seguro y genérico; la interfaz no debe mostrar la excepción anterior, la configuración SMTP ni credenciales.
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
