<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PaymentOrderService.php';

$directory = sys_get_temp_dir() . '/unah-test-' . bin2hex(random_bytes(4));
$repository = new PaymentOrderRepository($directory);
$mailer = new class extends PaymentOrderMailer {
    public $receiptCount = 0;
    public function send(string $email, string $name, string $applicantCode, string $pdf, array $order, string $temporaryUrl): bool { return false; }
    public function sendReceipt(string $email, string $name, string $receiptCode, string $pdf, array $receipt): bool
    {
        $this->receiptCount++;
        assert($email === 'applicant@example.test');
        assert($receipt['amount'] == 170.0);
        assert(strncmp($pdf, '%PDF-1.4', 8) === 0);
        return true;
    }
};
$service = new PaymentOrderService($repository, new PaymentOrderPdf(), $mailer, str_repeat('test-secret-', 4), $directory . '/mail.log');
$input = [
    'correo' => 'applicant@example.test',
    'apellido_paterno' => 'Quispe',
    'apellido_materno' => 'Huamán',
    'nombres_propios' => 'Ana María',
    'dni' => '12345678',
    'celular' => '987654321',
    'escuela' => 'Ingeniería de Sistemas',
    'concepto_key' => 'inscripcion',
    'modalidad_key' => 'ORD_EGRESADO',
    'procedencia' => 'estatal',
    'periodo' => 'regular',
    'monto' => 1,
    'fecha_vencimiento' => date('Y-m-d', strtotime('+2 days')),
];

$created = $service->createAndSend($input, 'https://example.test/orders');
assert($created['ok'] === true);
assert(preg_match('/^[a-f0-9]{32}$/', $created['order_id']) === 1);
assert($repository->find($created['order_id'])['amount'] == 170.0, 'The server must ignore a manipulated client amount');
assert(count(glob($directory . '/*.json')) === 1);

$invalidSchool = $input;
$invalidSchool['escuela'] = 'Programa no autorizado';
$schoolRejected = false;
try {
    $service->createAndSend($invalidSchool, 'https://example.test/orders');
} catch (InvalidArgumentException $error) {
    $schoolRejected = true;
}
assert($schoolRejected === true, 'The server must only accept a school from the configured combo');

$retried = $service->retry($created['order_id'], 'https://example.test/orders');
assert($retried['order_id'] === $created['order_id']);
assert($retried['code'] === $created['code']);
assert(count(glob($directory . '/*.json')) === 1, 'Retry must not create another order');

parse_str((string)parse_url($created['download_url'], PHP_URL_QUERY), $query);
$document = $service->download($query['id'], (int)$query['expires'], $query['signature']);
assert(strncmp($document['content'], '%PDF-1.4', 8) === 0);
assert(strpos($document['content'], '/Logo') !== false, 'The official logo must be embedded in the PDF');
assert($service->download($query['id'], time() - 1, $query['signature']) === null);

$receipt = $service->sendSimulatedReceipt(array_merge($input, array(
    'payment_method' => 'tarjeta',
    'operation_code' => 'SIM-' . date('Ymd') . '-A1B2C3D4',
    'card_brand' => 'Visa',
    'card_last4' => '1111',
)));
assert($receipt['ok'] === true);
assert($receipt['mail_sent'] === true);
assert($receipt['operation_code'] === 'SIM-' . date('Ymd') . '-A1B2C3D4');
assert($mailer->receiptCount === 1);

$yapeReceipt = $service->sendSimulatedReceipt(array_merge($input, array(
    'payment_method' => 'yape',
    'operation_code' => 'SIM-' . date('Ymd') . '-B1C2D3E4',
    'yape_phone' => '987654321',
    'yape_approval_code' => '123456',
)));
assert($yapeReceipt['ok'] === true);
assert($yapeReceipt['mail_sent'] === true);
assert($mailer->receiptCount === 2);

if (getenv('WRITE_SAMPLE_PDF') === '1') {
    $sampleDirectory = dirname(__DIR__) . '/tmp/pdfs';
    if (!is_dir($sampleDirectory)) mkdir($sampleDirectory, 0770, true);
    file_put_contents($sampleDirectory . '/esquela-prueba.pdf', $document['content']);
}

$log = file_get_contents($directory . '/mail.log');
assert(strpos($log, 'applicant@example.test') === false);
assert(strpos($log, 'Ana María') === false);
if (DIRECTORY_SEPARATOR !== '\\') {
    assert((fileperms($directory . '/mail.log') & 0777) === 0600);
}

echo "PaymentOrderServiceTest: OK\n";
