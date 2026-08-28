<?php

declare(strict_types=1);

require_once __DIR__ . '/src/GeminiDniReader.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function geminiDniError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(array('error' => $message), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    geminiDniError(405, 'Método no permitido.');
}

session_start();
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    session_write_close();
    geminiDniError(419, 'La sesión del formulario venció. Recargue la página.');
}

$now = time();
$attempts = array_values(array_filter($_SESSION['gemini_dni_attempts'] ?? array(), function ($timestamp) use ($now) {
    return is_int($timestamp) && $timestamp > $now - 60;
}));
if (count($attempts) >= 5) {
    $_SESSION['gemini_dni_attempts'] = $attempts;
    session_write_close();
    header('Retry-After: 60');
    geminiDniError(429, 'Se realizaron demasiadas lecturas. Espere un minuto.');
}
$attempts[] = $now;
$_SESSION['gemini_dni_attempts'] = $attempts;
session_write_close();

try {
    if (!isset($_FILES['dni']) || !is_array($_FILES['dni'])
        || (int) ($_FILES['dni']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No se recibió correctamente la imagen del DNI.');
    }
    $size = (int) ($_FILES['dni']['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('La imagen debe pesar como máximo 10 MB.');
    }
    $temporaryPath = (string) ($_FILES['dni']['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('El archivo recibido no es válido.');
    }

    $dimensions = @getimagesize($temporaryPath);
    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])
        || ((int) $dimensions[0] * (int) $dimensions[1]) > 40000000) {
        throw new InvalidArgumentException('La imagen no es válida o supera los 40 megapíxeles.');
    }
    $mimeType = isset($dimensions['mime']) ? strtolower((string) $dimensions['mime']) : '';
    if (class_exists('finfo')) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string) $fileInfo->file($temporaryPath));
        if ($detectedMime !== '') {
            if ($mimeType !== '' && $detectedMime !== $mimeType) {
                throw new InvalidArgumentException('El contenido de la imagen no coincide con su formato.');
            }
            $mimeType = $detectedMime;
        }
    }
    if (!in_array($mimeType, array('image/jpeg', 'image/png', 'image/webp'), true)) {
        throw new InvalidArgumentException('Use una imagen JPG, PNG o WebP.');
    }

    $imageBytes = file_get_contents($temporaryPath);
    if (!is_string($imageBytes) || strlen($imageBytes) !== $size) {
        throw new RuntimeException('No se pudo leer la imagen recibida.');
    }

    $configuration = require __DIR__ . '/config.php';
    $gemini = isset($configuration['gemini']) && is_array($configuration['gemini'])
        ? $configuration['gemini'] : array();
    $reader = new GeminiDniReader(
        new CurlGeminiDniHttpClient(),
        (string) ($gemini['api_key'] ?? ''),
        (string) ($gemini['model'] ?? 'gemini-3.7-flash'),
        (int) ($gemini['timeout'] ?? 120)
    );
    $person = $reader->read($mimeType, $imageBytes);
    echo json_encode(array('ok' => true, 'persona' => $person, 'fuente' => 'gemini'), JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    $status = strpos($error->getMessage(), 'no está configurada') !== false ? 503 : 422;
    geminiDniError($status, $error->getMessage());
} catch (GeminiDniServiceException $error) {
    error_log('Gemini DNI service: ' . $error->getMessage());
    geminiDniError(503, $error->getMessage());
} catch (Throwable $error) {
    error_log('Gemini DNI reader: ' . get_class($error) . ' - ' . $error->getMessage());
    geminiDniError(502, 'No fue posible leer el DNI con Google Gemini en este momento.');
}
