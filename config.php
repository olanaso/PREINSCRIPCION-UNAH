<?php

declare(strict_types=1);

$localConfiguration = is_file(__DIR__ . '/config.local.php')
    ? require __DIR__ . '/config.local.php'
    : array();
$localMail = isset($localConfiguration['mail']) && is_array($localConfiguration['mail'])
    ? $localConfiguration['mail']
    : array();
$localPayments = isset($localConfiguration['payments']) && is_array($localConfiguration['payments'])
    ? $localConfiguration['payments']
    : array();
$localOpenAi = isset($localConfiguration['openai']) && is_array($localConfiguration['openai'])
    ? $localConfiguration['openai']
    : array();
$localGemini = isset($localConfiguration['gemini']) && is_array($localConfiguration['gemini'])
    ? $localConfiguration['gemini']
    : array();
$localYape = isset($localPayments['yape']) && is_array($localPayments['yape'])
    ? $localPayments['yape']
    : array();
$localCard = isset($localPayments['card']) && is_array($localPayments['card'])
    ? $localPayments['card']
    : array();
$mailSetting = function (string $environmentName, string $key, $default) use ($localMail) {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    return array_key_exists($key, $localMail) ? $localMail[$key] : $default;
};
$fromAddress = (string) $mailSetting('MAIL_FROM_ADDRESS', 'from_address', 'admisiones@example.edu');
$paymentSetting = function (string $environmentName, array $settings, string $key, $default) {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
};

/*
 * Configuración del correo saliente.
 *
 * En producción, defina estos valores como variables de entorno para no
 * guardar credenciales en el repositorio. El transporte SMTP admite STARTTLS,
 * TLS implícito y autenticación LOGIN; `mail` queda disponible como alternativa.
 */
return [
    'gemini' => [
        'api_key' => (string) $paymentSetting('GEMINI_API_KEY', $localGemini, 'api_key', ''),
        'model' => (string) $paymentSetting('GEMINI_DNI_MODEL', $localGemini, 'model', 'gemini-3.7-flash'),
        'timeout' => (int) $paymentSetting('GEMINI_DNI_TIMEOUT', $localGemini, 'timeout', 120),
    ],
    'openai' => [
        'api_key' => (string) $paymentSetting('OPENAI_API_KEY', $localOpenAi, 'api_key', ''),
        'model' => (string) $paymentSetting('OPENAI_DNI_MODEL', $localOpenAi, 'model', 'gpt-5.6'),
        'timeout' => (int) $paymentSetting('OPENAI_DNI_TIMEOUT', $localOpenAi, 'timeout', 120),
    ],
    'mail' => [
        'transport' => (string) $mailSetting('MAIL_TRANSPORT', 'transport', 'smtp'),
        'from_address' => $fromAddress,
        'from_name' => (string) $mailSetting('MAIL_FROM_NAME', 'from_name', 'Admisiones UNAH'),
        'reply_to' => (string) $mailSetting('MAIL_REPLY_TO', 'reply_to', $fromAddress),
        'host' => (string) $mailSetting('MAIL_HOST', 'host', 'smtp.example.edu'),
        'port' => (int) $mailSetting('MAIL_PORT', 'port', 587),
        'encryption' => (string) $mailSetting('MAIL_ENCRYPTION', 'encryption', 'tls'),
        'username' => (string) $mailSetting('MAIL_USERNAME', 'username', ''),
        'password' => (string) $mailSetting('MAIL_PASSWORD', 'password', ''),
        'timeout' => (int) $mailSetting('MAIL_TIMEOUT', 'timeout', 20),
        'verify_peer' => (string) $mailSetting('MAIL_VERIFY_PEER', 'verify_peer', '1') !== '0',
    ],
    'payments' => [
        'yape' => [
            'qr_image' => (string) $paymentSetting('YAPE_QR_IMAGE', $localYape, 'qr_image', ''),
            'phone' => (string) $paymentSetting('YAPE_PHONE', $localYape, 'phone', ''),
            'holder' => (string) $paymentSetting('YAPE_HOLDER', $localYape, 'holder', ''),
        ],
        'card' => [
            'checkout_url' => (string) $paymentSetting('CARD_CHECKOUT_URL', $localCard, 'checkout_url', ''),
            'provider' => (string) $paymentSetting('CARD_PROVIDER', $localCard, 'provider', 'Pasarela segura'),
        ],
    ],
];
