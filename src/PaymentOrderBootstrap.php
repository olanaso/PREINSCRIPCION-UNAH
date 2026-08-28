<?php

declare(strict_types=1);

require_once __DIR__ . '/PaymentOrderService.php';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Lima');

function unahStorageDirectory(): string
{
    $configured = trim((string) getenv('PAYMENT_ORDER_STORAGE_DIR'));
    $directory = $configured !== ''
        ? $configured
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unah-payment-orders';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo preparar el almacenamiento temporal.');
    }
    return rtrim($directory, DIRECTORY_SEPARATOR);
}

function unahSigningSecret(string $directory): string
{
    $configured = (string) getenv('PAYMENT_ORDER_SIGNING_KEY');
    if (strlen($configured) >= 32) {
        return $configured;
    }

    $path = $directory . DIRECTORY_SEPARATOR . '.signing-key';
    if (!is_file($path)) {
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fwrite($handle, bin2hex(random_bytes(32)));
            fclose($handle);
            @chmod($path, 0600);
        }
    }
    $secret = trim((string) @file_get_contents($path));
    if (strlen($secret) < 32) {
        throw new RuntimeException('No se pudo crear la clave privada de las esquelas.');
    }
    return $secret;
}

function unahPaymentOrderService(array $mailConfig): PaymentOrderService
{
    $directory = unahStorageDirectory();
    return new PaymentOrderService(
        new PaymentOrderRepository($directory . DIRECTORY_SEPARATOR . 'orders'),
        new PaymentOrderPdf(),
        new PaymentOrderMailer($mailConfig),
        unahSigningSecret($directory),
        $directory . DIRECTORY_SEPARATOR . 'delivery.log'
    );
}

function unahDownloadEndpointUrl(): string
{
    $configured = trim((string) getenv('APP_BASE_URL'));
    if ($configured !== '') {
        return rtrim($configured, '/') . '/payment-order.php';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    $scheme = $https ? 'https' : 'http';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/generate-payment-order.php'));
    $directory = str_replace('\\', '/', dirname($script));
    $directory = rtrim($directory, '/.');
    return $scheme . '://' . $host . ($directory === '' ? '' : $directory) . '/payment-order.php';
}
