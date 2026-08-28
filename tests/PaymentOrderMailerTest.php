<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PaymentOrderService.php';

$capture = sys_get_temp_dir() . '/unah-smtp-capture.eml';
@unlink($capture);
$mailer = new PaymentOrderMailer(array(
    'transport' => 'smtp',
    'from_address' => 'admisiones@example.test',
    'from_name' => 'Admisiones UNAH',
    'reply_to' => 'admisiones@example.test',
    'host' => '127.0.0.1',
    'port' => 2526,
    'encryption' => '',
    'username' => '',
    'password' => '',
    'timeout' => 5,
    'verify_peer' => false,
));
$order = array(
    'code' => 'UNAH-TEST-001',
    'concept' => 'Derecho de inscripción',
    'amount' => 170,
    'due_date' => date('Y-m-d', strtotime('+30 days')),
);
$sent = $mailer->send(
    'postulante@example.test',
    'Ana María Quispe',
    $order['code'],
    '%PDF-1.4 test',
    $order,
    'https://example.test/payment-order.php?test=1'
);
assert($sent === true);
$message = file_get_contents($capture);
assert(strpos($message, 'Content-Type: application/pdf') !== false);
assert(strpos($message, base64_encode('%PDF-1.4 test')) !== false);
assert(strpos(quoted_printable_decode($message), 'https://example.test/payment-order.php?test=1') !== false);
@unlink($capture);
echo "PaymentOrderMailerTest: OK\n";
