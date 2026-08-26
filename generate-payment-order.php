<?php

declare(strict_types=1);

use Unah\Pdf\PaymentOrderGenerator;
use Unah\Storage\PaymentOrderStorage;

require __DIR__ . '/vendor/autoload.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Solicitud inválida.');
    }

    $required = ['codigo', 'dni', 'nombres', 'correo', 'celular', 'carrera', 'concepto', 'jornada', 'monto', 'fecha_vencimiento'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            throw new InvalidArgumentException('Falta el campo requerido: ' . $field);
        }
    }
    if (!preg_match('/^[0-9]{8,20}$/', (string) $input['dni']) || !filter_var($input['correo'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Los datos personales no son válidos.');
    }
    $amount = filter_var($input['monto'], FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0 || $amount > 100000) {
        throw new InvalidArgumentException('El monto no es válido.');
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
    $data = [
        'numero_orden' => 'OP-' . $now->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
        'codigo' => substr((string) $input['codigo'], 0, 80),
        'dni' => substr((string) $input['dni'], 0, 20),
        'nombres' => substr((string) $input['nombres'], 0, 150),
        'correo' => substr((string) $input['correo'], 0, 150),
        'celular' => substr((string) $input['celular'], 0, 30),
        'carrera' => substr((string) $input['carrera'], 0, 180),
        'concepto' => substr((string) $input['concepto'], 0, 180),
        'modalidad' => substr((string) ($input['modalidad'] ?? 'No aplica'), 0, 150),
        'jornada' => substr((string) $input['jornada'], 0, 80),
        'monto' => $amount,
        'fecha_emision' => $now->format('d/m/Y H:i'),
        'fecha_vencimiento' => substr((string) $input['fecha_vencimiento'], 0, 20),
    ];

    $generator = new PaymentOrderGenerator(__DIR__ . '/templates/payment-order.php');
    $document = $generator->generate($data);
    $stored = (new PaymentOrderStorage())->store($document['content'], $document['filename']);

    echo json_encode([
        'url' => 'payment-order.php?token=' . rawurlencode($stored['token']),
        'expires_at' => gmdate(DATE_ATOM, $stored['expires_at']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException | JsonException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo generar la orden de pago.'], JSON_UNESCAPED_UNICODE);
}
