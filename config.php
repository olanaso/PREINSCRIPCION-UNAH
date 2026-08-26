<?php

declare(strict_types=1);

/*
 * Configuración del correo saliente.
 *
 * En producción, defina estos valores como variables de entorno para no
 * guardar credenciales en el repositorio. El transporte actual usa mail() de
 * PHP; host, puerto y credenciales quedan listos para conectar un cliente SMTP.
 */
return [
    'mail' => [
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'admisiones@example.edu',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Admisiones UNAH',
        'reply_to' => getenv('MAIL_REPLY_TO') ?: 'admisiones@example.edu',
        'host' => getenv('MAIL_HOST') ?: 'smtp.example.edu',
        'port' => (int) (getenv('MAIL_PORT') ?: 587),
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls',
        'username' => getenv('MAIL_USERNAME') ?: '',
        'password' => getenv('MAIL_PASSWORD') ?: '',
    ],
];
