<?php

declare(strict_types=1);

require __DIR__ . '/../src/Storage/PaymentOrderStorage.php';

use Unah\Storage\PaymentOrderStorage;

$directory = sys_get_temp_dir() . '/unah-payment-orders-test-' . bin2hex(random_bytes(6));
$storage = new PaymentOrderStorage($directory);
$stored = $storage->store('%PDF-test', 'esquela-segura.pdf');
assert(strlen($stored['token']) === 64);
assert(!str_contains(implode(' ', glob($directory . '/*') ?: []), 'esquela-segura'));
$order = $storage->retrieve($stored['token']);
assert($order !== null && $order['content'] === '%PDF-test');
assert($storage->retrieve('../token-invalido') === null);

$data = [
    'numero_orden' => '<script>orden</script>', 'codigo' => 'ABC', 'nombres' => '<b>Nombre</b>',
    'dni' => '12345678', 'correo' => 'a@example.com', 'celular' => '999', 'carrera' => 'Ingeniería',
    'concepto' => 'Inscripción', 'modalidad' => 'Ordinaria', 'jornada' => 'Matutina', 'monto' => 170,
    'fecha_emision' => '26/08/2026', 'fecha_vencimiento' => '30/09/2026',
];
ob_start();
require __DIR__ . '/../templates/payment-order.php';
$html = (string) ob_get_clean();
assert(!str_contains($html, '<script>orden</script>'));
assert(str_contains($html, '&lt;script&gt;orden&lt;/script&gt;'));
assert(str_contains($html, '&lt;b&gt;Nombre&lt;/b&gt;'));

foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
rmdir($directory);
echo "OK\n";
