<?php

declare(strict_types=1);

/**
 * Obtiene una variable obligatoria sin asignarle un valor sensible por defecto.
 */
$requiredEnvironmentValue = static function (string $name): string {
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Falta configurar la variable de correo {$name}.");
    }

    return trim($value);
};

$port = filter_var(
    $requiredEnvironmentValue('MAIL_PORT'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 65535]]
);

if ($port === false) {
    throw new RuntimeException('MAIL_PORT debe ser un puerto válido entre 1 y 65535.');
}

$encryption = strtolower($requiredEnvironmentValue('MAIL_ENCRYPTION'));
if (!in_array($encryption, ['tls', 'ssl'], true)) {
    throw new RuntimeException('MAIL_ENCRYPTION debe ser tls o ssl.');
}

$fromAddress = $requiredEnvironmentValue('MAIL_FROM_ADDRESS');
if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
    throw new RuntimeException('MAIL_FROM_ADDRESS debe contener una dirección válida.');
}

return [
    'host' => $requiredEnvironmentValue('MAIL_HOST'),
    'port' => $port,
    'username' => $requiredEnvironmentValue('MAIL_USERNAME'),
    'password' => $requiredEnvironmentValue('MAIL_PASSWORD'),
    'encryption' => $encryption,
    'from_address' => $fromAddress,
];
