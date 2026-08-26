<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PaymentOrderService.php';

$directory = sys_get_temp_dir() . '/unah-test-' . bin2hex(random_bytes(4));
$repository = new PaymentOrderRepository($directory);
$mailer = new class extends PaymentOrderMailer {
    public function send(string $email, string $name, string $applicantCode, string $pdf, array $order, string $temporaryUrl): bool { return false; }
};
$service = new PaymentOrderService($repository, new PaymentOrderPdf(), $mailer, 'test-secret', $directory . '/mail.log');
$input = ['correo'=>'applicant@example.test', 'nombres'=>'Postulante de Prueba', 'concepto'=>'Inscripción', 'concepto_key'=>'inscripcion', 'modalidad_key'=>'ORD_EGRESADO', 'procedencia'=>'estatal', 'periodo'=>'regular', 'monto'=>1, 'fecha_vencimiento'=>'2026-09-30'];

$created = $service->createAndSend($input, 'https://example.test/orders');
assert($created['ok'] === true);
assert(preg_match('/^[a-f0-9]{32}$/', $created['order_id']) === 1);
assert($repository->find($created['order_id'])['amount'] == 170.0, 'The server must ignore a manipulated client amount');
assert(count(glob($directory . '/*.json')) === 1);

$retried = $service->retry($created['order_id'], 'https://example.test/orders');
assert($retried['order_id'] === $created['order_id']);
assert($retried['code'] === $created['code']);
assert(count(glob($directory . '/*.json')) === 1, 'Retry must not create another order');

parse_str((string)parse_url($created['download_url'], PHP_URL_QUERY), $query);
$document = $service->download($query['download'], (int)$query['expires'], $query['signature']);
assert(str_starts_with($document['content'], '%PDF-1.4'));
assert($service->download($query['download'], time() - 1, $query['signature']) === null);

$log = file_get_contents($directory . '/mail.log');
assert(!str_contains($log, 'applicant@example.test'));
assert(!str_contains($log, 'Postulante de Prueba'));
assert((fileperms($directory . '/mail.log') & 0777) === 0600);

echo "PaymentOrderServiceTest: OK\n";
