<?php
declare(strict_types=1);

/**
 * Creates short-lived, non-enumerable payment-order links and validates downloads.
 * PAYMENT_ORDER_SIGNING_KEY must be a random secret of at least 32 characters.
 */

const LINK_TTL_SECONDS = 600;

function signingKey(): string
{
    $key = (string) getenv('PAYMENT_ORDER_SIGNING_KEY');
    if (strlen($key) < 32) {
        throw new RuntimeException('PAYMENT_ORDER_SIGNING_KEY no está configurada correctamente.');
    }
    return $key;
}

function storageDirectory(): string
{
    $directory = (string) (getenv('PAYMENT_ORDER_STORAGE_DIR') ?: sys_get_temp_dir() . '/unah-payment-orders');
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible preparar el almacenamiento de esquelas.');
    }
    return $directory;
}

function signature(string $id, int $expires): string
{
    return hash_hmac('sha256', $id . '.' . $expires, signingKey());
}

function pdfForCode(string $code): string
{
    $text = 'Esquela de pago UNAH - Codigo de postulante: ' . $code;
    $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    $stream = "BT /F1 16 Tf 72 740 Td ({$escaped}) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true);
        $code = is_array($input) ? trim((string) ($input['applicant_code'] ?? '')) : '';
        if (!preg_match('/^UNAH-[0-9]{8}-[0-9]{5}$/', $code)) fail(422, 'Código de postulante inválido.');

        $id = bin2hex(random_bytes(24));
        if (file_put_contents(storageDirectory() . '/' . $id . '.pdf', pdfForCode($code), LOCK_EX) === false) {
            throw new RuntimeException('No fue posible guardar la esquela.');
        }
        $expires = time() + LINK_TTL_SECONDS;
        $query = http_build_query(['id' => $id, 'expires' => $expires, 'signature' => signature($id, $expires)]);
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['download_url' => $base . '/payment-order.php?' . $query, 'expires_at' => gmdate(DATE_ATOM, $expires)]);
        exit;
    }

    $id = (string) ($_GET['id'] ?? '');
    $expires = filter_var($_GET['expires'] ?? null, FILTER_VALIDATE_INT);
    $provided = (string) ($_GET['signature'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $id) || !$expires || $expires < time() || !hash_equals(signature($id, $expires), $provided)) {
        fail(403, 'El enlace es inválido o ha expirado. Genere una nueva esquela.');
    }
    $path = storageDirectory() . '/' . $id . '.pdf';
    if (!is_file($path)) fail(404, 'La esquela ya no está disponible.');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="esquela-' . substr($id, 0, 10) . '.pdf"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
} catch (Throwable $error) {
    error_log($error->getMessage());
    fail(500, 'No se pudo procesar la esquela.');
}

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
