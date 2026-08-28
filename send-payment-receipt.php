<?php

declare(strict_types=1);

require_once __DIR__ . '/src/PaymentOrderBootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(array('error' => 'Metodo no permitido.'));
    exit;
}

session_start();
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    http_response_code(419);
    echo json_encode(array('error' => 'La sesion del formulario vencio. Recargue la pagina.'), JSON_UNESCAPED_UNICODE);
    exit;
}
session_write_close();

try {
    $raw = (string) file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('La solicitud no contiene datos validos.');
    }

    $configuration = require __DIR__ . '/config.php';
    $service = unahPaymentOrderService($configuration['mail']);
    $result = $service->sendSimulatedReceipt($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(array('error' => $error->getMessage()), JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log(get_class($error) . ': ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(array('error' => 'No se pudo enviar el recibo. Intente nuevamente.'), JSON_UNESCAPED_UNICODE);
}
