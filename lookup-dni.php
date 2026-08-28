<?php

declare(strict_types=1);

require_once __DIR__ . '/src/DniLookupService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(array('error' => 'Método no permitido.'), JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$requestToken = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
    session_write_close();
    http_response_code(419);
    echo json_encode(array('error' => 'La sesión del formulario venció. Recargue la página.'), JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
$attempts = array_values(array_filter($_SESSION['dni_lookup_attempts'] ?? array(), function ($timestamp) use ($now) {
    return is_int($timestamp) && $timestamp > $now - 60;
}));
if (count($attempts) >= 20) {
    $_SESSION['dni_lookup_attempts'] = $attempts;
    session_write_close();
    http_response_code(429);
    echo json_encode(array('error' => 'Se realizaron demasiadas consultas. Espere un minuto.'), JSON_UNESCAPED_UNICODE);
    exit;
}
$attempts[] = $now;
$_SESSION['dni_lookup_attempts'] = $attempts;
session_write_close();

try {
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
        throw new InvalidArgumentException('La solicitud no contiene datos válidos.');
    }
    $dni = preg_replace('/\D+/', '', (string) ($input['dni'] ?? ''));
    $person = (new DniLookupService(new CurlDniHttpClient()))->lookup($dni);
    echo json_encode(array('ok' => true, 'persona' => $person), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(array('error' => $error->getMessage()), JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('DNI lookup: ' . get_class($error) . ' - ' . $error->getMessage());
    http_response_code(502);
    echo json_encode(array('error' => 'No fue posible consultar el DNI. Puede ingresar los datos manualmente.'), JSON_UNESCAPED_UNICODE);
}
