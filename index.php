<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/src/PreRegistrationController.php';

$config = array_replace_recursive(
    require __DIR__ . '/config/payment.php',
    require __DIR__ . '/config.php'
);
$controller = new PreRegistrationController($config);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->submit($_POST, $_SESSION);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}
$view = $controller->view($_SESSION);
$e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$old = static fn (string $field): string => $e((string) ($view['values'][$field] ?? ''));
$error = static fn (string $field): string => isset($view['errors'][$field])
    ? '<p class="error">' . $e($view['errors'][$field]) . '</p>' : '';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Preinscripción UNAH</title>
  <style>
    :root{font-family:system-ui;color:#172033;background:#f3f5f8}body{margin:0}.top{background:#531d31;color:#fff;padding:1.3rem}.top div,main{max-width:760px;margin:auto}main{padding:2rem 1rem}.card{background:#fff;padding:1.5rem;border:1px solid #d9dde5;border-radius:8px;box-shadow:0 5px 18px #18203312}.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.field{margin-bottom:1rem}label{display:block;font-weight:650;margin-bottom:.35rem}input,select{box-sizing:border-box;width:100%;padding:.7rem;border:1px solid #aeb5c1;border-radius:4px}.terms{display:flex;gap:.6rem;align-items:flex-start}.terms input{width:auto;margin-top:.25rem}.error{color:#b42318;font-size:.88rem;margin:.3rem 0 0}.alert{padding:1rem;margin-bottom:1rem;border-radius:5px}.bad{background:#feeceb;color:#8a1c14}.good{background:#e9f8ef;color:#126335}.amount{background:#f8eff2;padding:1rem;margin:1rem 0;border-left:4px solid #762842}button{background:#762842;color:#fff;border:0;padding:.8rem 1.2rem;font-weight:700;border-radius:4px;cursor:pointer}@media(max-width:600px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<header class="top"><div><small>UNAH · DIRECCIÓN DE ADMISIÓN</small><h1>Preinscripción</h1></div></header>
<main>
  <?php if (isset($view['errors']['general'])): ?><div class="alert bad"><?= $e($view['errors']['general']) ?></div><?php endif; ?>
  <?php if ($view['success']): ?><div class="alert good"><strong><?= $e($view['success']['message']) ?></strong><br>Código: <?= $e($view['success']['applicantCode']) ?> · Orden: <?= $e($view['success']['orderNumber']) ?><br><?= $view['success']['mailSent'] ? 'Correo enviado.' : 'Se completó el intento de correo, pero el servidor no confirmó el envío.' ?></div><?php endif; ?>
  <form class="card" method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= $e($view['csrfToken']) ?>">
    <div class="field"><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" value="<?= $old('nombre') ?>" required autocomplete="name"><?= $error('nombre') ?></div>
    <div class="grid">
      <div class="field"><label for="identidad">Número de identidad</label><input id="identidad" name="identidad" value="<?= $old('identidad') ?>" required inputmode="numeric" placeholder="0801199912345"><?= $error('identidad') ?></div>
      <div class="field"><label for="correo">Correo electrónico</label><input id="correo" name="correo" type="email" value="<?= $old('correo') ?>" required autocomplete="email"><?= $error('correo') ?></div>
      <div class="field"><label for="carrera">Carrera</label><select id="carrera" name="carrera" required><option value="">Seleccione</option><?php foreach ($view['careers'] as $key => $label): ?><option value="<?= $e($key) ?>" <?= $old('carrera') === $e($key) ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><?= $error('carrera') ?></div>
      <div class="field"><label for="jornada">Jornada</label><select id="jornada" name="jornada" required><option value="">Seleccione</option><?php foreach ($view['schedules'] as $key => $label): ?><option value="<?= $e($key) ?>" <?= $old('jornada') === $e($key) ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><?= $error('jornada') ?></div>
    </div>
    <div class="field"><label for="concepto">Concepto de pago</label><select id="concepto" name="concepto" required><?php foreach ($view['concepts'] as $key => $item): ?><option value="<?= $e($key) ?>" <?= $old('concepto') === $e($key) ? 'selected' : '' ?>><?= $e($item['label']) ?></option><?php endforeach; ?></select><?= $error('concepto') ?></div>
    <?php $shown = reset($view['concepts']); ?><div class="amount"><strong>Monto definido por la universidad:</strong> <?= $e($shown['currency']) ?> <?= number_format($shown['amount'], 2) ?><br><small>El monto se calcula exclusivamente en el servidor y no se envía desde este formulario.</small></div>
    <div class="field terms"><input id="terminos" name="terminos" type="checkbox" value="1" <?= $old('terminos') === '1' ? 'checked' : '' ?> required><div><label for="terminos">Acepto los términos y confirmo que los datos son correctos.</label><?= $error('terminos') ?></div></div>
    <button type="submit">Generar orden y PDF</button>
  </form>
</main>
</body></html>
