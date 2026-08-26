<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/PreRegistrationController.php';
$config = array_replace_recursive(
    require dirname(__DIR__) . '/config/payment.php',
    require dirname(__DIR__) . '/config.php'
);
$config['storage_path'] = sys_get_temp_dir() . '/unah-test-' . bin2hex(random_bytes(4));
$controller = new PreRegistrationController($config);
$session = [];
$view = $controller->view($session);
$controller->submit(['csrf_token' => $view['csrfToken']], $session);
assert(isset($session['flash']['errors']['nombre'], $session['flash']['errors']['identidad'], $session['flash']['errors']['correo']));

$post = [
    'csrf_token' => $view['csrfToken'], 'nombre' => 'Ana López', 'identidad' => '0801-1999-12345',
    'correo' => 'ana@example.com', 'carrera' => 'ingenieria_sistemas', 'jornada' => 'matutina',
    'concepto' => 'preinscripcion', 'terminos' => '1', 'amount' => '0.01',
];
$controller->submit($post, $session);
assert(isset($session['flash']['success']));
assert(count(glob($config['storage_path'] . '/pdf/*.pdf')) === 1);
assert($config['mail']['port'] === 587);
echo "OK\n";
