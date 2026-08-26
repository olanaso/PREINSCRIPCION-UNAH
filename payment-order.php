<?php

declare(strict_types=1);

use Unah\Storage\PaymentOrderStorage;

require __DIR__ . '/vendor/autoload.php';

$token = (string) ($_GET['token'] ?? '');
$order = (new PaymentOrderStorage())->retrieve($token);
if ($order === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'La orden no existe o el enlace ha vencido.';
    exit;
}

$disposition = isset($_GET['download']) ? 'attachment' : 'inline';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($order['content']));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($order['filename'], '"\\') . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $order['content'];
