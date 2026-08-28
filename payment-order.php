<?php

declare(strict_types=1);

require_once __DIR__ . '/src/PaymentOrderBootstrap.php';

try {
    $id = (string) ($_GET['id'] ?? '');
    $expires = filter_var($_GET['expires'] ?? null, FILTER_VALIDATE_INT);
    $signature = (string) ($_GET['signature'] ?? '');
    if ($expires === false || $expires === null) {
        throw new InvalidArgumentException('Enlace invalido.');
    }

    $configuration = require __DIR__ . '/config.php';
    $document = unahPaymentOrderService($configuration['mail'])->download($id, (int) $expires, $signature);
    if ($document === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'La esquela no existe o el enlace temporal ha vencido.';
        exit;
    }

    $disposition = isset($_GET['attachment']) ? 'attachment' : 'inline';
    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string) $document['name']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($document['content']));
    header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $document['content'];
} catch (Throwable $error) {
    error_log(get_class($error) . ': ' . $error->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No se pudo recuperar la esquela.';
}
