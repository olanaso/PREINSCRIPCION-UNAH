# PREINSCRIPCION-UNAH

Formulario PHP para generar una esquela de pago en PDF, adjuntarla a un correo y ofrecer una descarga temporal al postulante.

## Funcionamiento

1. `index.php` valida los datos visibles y envía el formulario por AJAX.
2. El botón junto al DNI consulta los datos personales mediante `lookup-dni.php`; la llamada externa se realiza únicamente desde el servidor.
   El formulario también permite leer una foto del anverso utilizando exclusivamente Google Gemini para completar DNI, nombres y apellidos.
3. `generate-payment-order.php` vuelve a validar los datos y calcula el monto oficial en el servidor; no confía en el monto del navegador.
4. `PaymentOrderService` genera un código y una orden no predecibles, crea el PDF y lo adjunta al correo.
5. `payment-order.php` permite abrir o descargar el PDF durante una hora mediante un enlace firmado.
6. Los datos temporales se guardan fuera del directorio público y se eliminan oportunistamente después de 24 horas.

La fecha de emisión se fija al día actual y el vencimiento se calcula automáticamente a 2 días desde la generación. El campo no es editable en la interfaz y el servidor rechaza cualquier vencimiento distinto.

El flujo principal no requiere Composer ni una biblioteca externa de PDF. Es compatible con el PHP 7.0 utilizado actualmente por Apache en este equipo y con PHP 8.

## Configuración del correo SMTP

Defina estas variables de entorno en el servidor y reinicie Apache para que las reciba:

```text
MAIL_TRANSPORT=smtp
MAIL_FROM_ADDRESS=admisiones@unah.edu.pe
MAIL_FROM_NAME=Admisiones UNAH
MAIL_REPLY_TO=admisiones@unah.edu.pe
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=usuario-smtp
MAIL_PASSWORD=clave-o-token-smtp
MAIL_VERIFY_PEER=1
```

Este servidor también puede usar `config.local.php` para la configuración privada. El archivo está excluido de Git, protegido contra acceso HTTP y sus valores son reemplazados por variables de entorno cuando estas existen.

La consulta de DNI mantiene la verificación HTTPS activa y usa `config/cacert.pem`. Puede reemplazarse mediante la variable `SSL_CA_BUNDLE` cuando el servidor administre su propio paquete de certificados.

Para SMTP con TLS implícito use normalmente `MAIL_ENCRYPTION=ssl` y el puerto indicado por el proveedor. Para un relay local sin cifrado use `MAIL_ENCRYPTION=none`. Las credenciales nunca deben escribirse en `index.php`, JavaScript ni el repositorio.

En producción configure también la URL pública base para que el enlace incluido en el correo sea correcto:

```text
APP_BASE_URL=https://admision.unah.edu.pe/preinscripcion
ADMISSION_PERIOD=2026-II
```

Opcionalmente, establezca `PAYMENT_ORDER_STORAGE_DIR` a un directorio privado con permiso de escritura y `PAYMENT_ORDER_SIGNING_KEY` a un secreto aleatorio de al menos 32 caracteres. Si no se define la clave, la aplicación crea una automáticamente dentro del almacenamiento privado.

Si SMTP no está configurado o falla, la esquela se conserva y el formulario muestra el botón de descarga; no informa falsamente que el correo fue enviado.

## Configuración pública de medios de pago

El flujo permite generar una esquela, mostrar un QR de Yape o abrir una pasarela externa para tarjetas. Configure únicamente datos públicos mediante variables de entorno:

```text
YAPE_QR_IMAGE=assets/qr-yape.png
YAPE_PHONE=999999999
YAPE_HOLDER=UNIVERSIDAD NACIONAL AUTÓNOMA DE HUANTA
CARD_CHECKOUT_URL=https://checkout.proveedor.pe/...
CARD_PROVIDER=Nombre de la pasarela
```

`YAPE_QR_IMAGE` puede ser una ruta pública dentro del proyecto o una URL HTTPS. `CARD_CHECKOUT_URL` debe usar HTTPS. Los números de tarjeta y el CVV no se reciben ni se almacenan en esta aplicación; la captura real debe realizarla una pasarela de pago certificada.

### Modo de simulación

La interfaz actual permite demostrar el pago con tarjeta y Yape sin mover dinero. La tarjeta acepta únicamente números ficticios autorizados. Yape solicita el celular, luego un código de aprobación de 6 dígitos. Ambos flujos muestran un procesamiento animado, generan una boleta marcada `SIMULACIÓN · SIN VALOR FINANCIERO` y envían el recibo PDF al correo registrado usando la misma configuración SMTP de la esquela. Los datos escritos en el formulario de tarjeta permanecen en el navegador y no se envían al servidor; el servidor solo recibe el método simulado, la marca y los últimos cuatro dígitos.

Las máscaras visibles de DNI, celular, correo y tarjeta usan IMask `7.6.1` fijado desde CDN con SRI. El formulario mantiene los números sin espacios para las validaciones y para el envío al servidor.

## Lectura del DNI con Google Gemini

Configure la API key únicamente en una variable de entorno del servidor y reinicie Apache:

```text
GEMINI_API_KEY=AIza...
GEMINI_DNI_MODEL=gemini-3.7-flash
GEMINI_DNI_TIMEOUT=120
```

Como alternativa local, agregue la sección siguiente a `config.local.php`, que está excluido de Git y protegido contra acceso HTTP:

```php
'gemini' => array(
    'api_key' => 'AIza...',
    'model' => 'gemini-3.7-flash',
    'timeout' => 120,
),
```

La clave nunca se incluye en `index.php` ni se entrega al navegador. `read-dni-gemini.php` valida CSRF, MIME, tamaño, resolución y frecuencia de uso; convierte temporalmente la imagen a Base64 y llama a Gemini Interactions API con `store: false`, pensamiento bajo y un esquema JSON limitado a DNI, apellido paterno, apellido materno y prenombres. La aplicación no escribe la imagen en su almacenamiento.

No existe un OCR local ni una llamada de respaldo a OpenAI. Si la API key no está configurada, no tiene cuota, Gemini no está disponible o la imagen no puede leerse, el formulario muestra el error y no completa campos automáticamente. Se admiten archivos JPG, PNG y WebP de hasta 10 MB y 40 megapíxeles; los resultados permanecen editables y deben verificarse antes de continuar.

## Pruebas

En este entorno local:

```powershell
& 'C:\AppServ\php7\php.exe' -d assert.exception=1 -d zend.assertions=1 tests\PaymentOrderServiceTest.php
& 'C:\AppServ\php7\php.exe' -d assert.exception=1 -d zend.assertions=1 tests\GeminiDniReaderTest.php
& 'C:\AppServ\php8\php.exe' -d assert.exception=1 -d zend.assertions=1 tests\OpenAiDniReaderTest.php
```

La prueba comprueba el cálculo del monto en el servidor, la persistencia de una sola orden al reintentar, la firma del enlace, la caducidad y la estructura PDF.

`PaymentOrderMailerTest.php` puede ejecutarse junto con `FakeSmtpServer.php` para verificar localmente el mensaje multiparte y el PDF adjunto sin enviar correo a Internet.

`SmtpAuthenticationTest.php` comprueba la conexión y autenticación del servidor configurado y termina con `QUIT`; no envía ningún mensaje.
