<?php

declare(strict_types=1);

$configuration = require dirname(__DIR__) . '/config.php';
$mail = $configuration['mail'];
$host = (string) $mail['host'];
$port = (int) $mail['port'];
$encryption = strtolower((string) $mail['encryption']);
$remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
$context = stream_context_create(array('ssl' => array(
    'verify_peer' => (bool) $mail['verify_peer'],
    'verify_peer_name' => (bool) $mail['verify_peer'],
    'allow_self_signed' => !(bool) $mail['verify_peer'],
    'peer_name' => $host,
)));

$socket = @stream_socket_client($remote, $number, $message, (int) $mail['timeout'], STREAM_CLIENT_CONNECT, $context);
if (!is_resource($socket)) {
    fwrite(STDERR, "No se pudo abrir la conexión SSL/TLS con SMTP.\n");
    exit(1);
}
stream_set_timeout($socket, (int) $mail['timeout']);

$reply = function () use ($socket) {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    return array((int) substr($response, 0, 3), $response);
};
$command = function ($text, array $expected) use ($socket, $reply) {
    fwrite($socket, $text . "\r\n");
    list($status) = $reply();
    if (!in_array($status, $expected, true)) {
        throw new RuntimeException('SMTP rechazó la operación con el código ' . $status . '.');
    }
};

try {
    list($greeting) = $reply();
    if ($greeting !== 220) throw new RuntimeException('SMTP no envió el saludo esperado.');
    $command('EHLO localhost', array(250));
    $command('AUTH LOGIN', array(334));
    $command(base64_encode((string) $mail['username']), array(334));
    $command(base64_encode((string) $mail['password']), array(235));
    $command('QUIT', array(221));
    echo "Autenticación SMTP SSL correcta; no se envió ningún correo.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    fclose($socket);
}
