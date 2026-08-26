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
