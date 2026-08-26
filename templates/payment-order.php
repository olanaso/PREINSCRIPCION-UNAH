<?php
/** @var array<string, scalar|null> $data */
$escape = static fn (mixed $value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 32px 38px 52px; }
    * { box-sizing: border-box; }
    body { color: #20242a; font-family: "DejaVu Sans", sans-serif; font-size: 11px; margin: 0; }
    .header { border-bottom: 4px solid #6f1d3b; padding-bottom: 12px; }
    .institution { color: #6f1d3b; font-size: 16px; font-weight: bold; letter-spacing: .3px; }
    .office { color: #555; font-size: 10px; margin-top: 3px; text-transform: uppercase; }
    .title { background: #6f1d3b; color: #fff; font-size: 22px; font-weight: bold; margin-top: 18px; padding: 11px; text-align: center; text-transform: uppercase; }
    .order { border: 1px solid #b9bdc4; margin-top: 12px; width: 100%; }
    .order td { padding: 7px 9px; }
    .order .label { background: #f1f2f4; color: #555; font-size: 9px; font-weight: bold; text-transform: uppercase; width: 22%; }
    .order .value { font-weight: bold; width: 28%; }
    .section { color: #6f1d3b; font-size: 12px; font-weight: bold; margin: 16px 0 6px; text-transform: uppercase; }
    .details { border-collapse: collapse; width: 100%; }
    .details td { border: 1px solid #c7c9cd; padding: 7px 9px; }
    .details .label { background: #f5f5f6; color: #555; font-weight: bold; width: 30%; }
    .amount { border: 2px solid #6f1d3b; margin-top: 17px; padding: 12px; text-align: center; }
    .amount-label { color: #6f1d3b; font-size: 10px; font-weight: bold; text-transform: uppercase; }
    .amount-value { color: #6f1d3b; font-size: 28px; font-weight: bold; margin-top: 2px; }
    .warning { background: #fff7dd; border-left: 5px solid #d49b00; margin-top: 16px; padding: 10px 12px; }
    .warning strong { color: #704d00; }
    .warning ul { margin: 6px 0 0; padding-left: 17px; }
    .warning li { margin-bottom: 4px; }
    .footer { border-top: 1px solid #999; bottom: -33px; color: #555; font-size: 8px; left: 0; padding-top: 6px; position: fixed; right: 0; }
    .footer-right { float: right; }
</style>
</head>
<body>
<header class="header">
    <div class="institution">UNIVERSIDAD NACIONAL AUTÓNOMA DE HUANTA</div>
    <div class="office">Dirección de Admisión · Documento institucional de pago</div>
</header>

<div class="title">Orden de pago</div>
<table class="order" cellspacing="0">
    <tr>
        <td class="label">N.º de orden</td><td class="value"><?= $escape($data['numero_orden'] ?? '') ?></td>
        <td class="label">Código postulante</td><td class="value"><?= $escape($data['codigo'] ?? '') ?></td>
    </tr>
</table>

<div class="section">Datos personales</div>
<table class="details">
    <tr><td class="label">Nombre completo</td><td><?= $escape($data['nombres'] ?? '') ?></td></tr>
    <tr><td class="label">Documento de identidad</td><td><?= $escape($data['dni'] ?? '') ?></td></tr>
    <tr><td class="label">Correo electrónico</td><td><?= $escape($data['correo'] ?? '') ?></td></tr>
    <tr><td class="label">Teléfono</td><td><?= $escape($data['celular'] ?? '') ?></td></tr>
</table>

<div class="section">Información académica y del pago</div>
<table class="details">
    <tr><td class="label">Carrera</td><td><?= $escape($data['carrera'] ?? '') ?></td></tr>
    <tr><td class="label">Concepto</td><td><?= $escape($data['concepto'] ?? '') ?></td></tr>
    <tr><td class="label">Modalidad</td><td><?= $escape($data['modalidad'] ?? '') ?></td></tr>
    <tr><td class="label">Jornada</td><td><?= $escape($data['jornada'] ?? '') ?></td></tr>
</table>

<div class="amount">
    <div class="amount-label">Monto total a pagar</div>
    <div class="amount-value">S/ <?= $escape(number_format((float) ($data['monto'] ?? 0), 2, '.', ',')) ?></div>
</div>

<div class="warning">
    <strong>Advertencias importantes</strong>
    <ul>
        <li>Verifique sus datos antes de efectuar el pago; una orden con información incorrecta no debe utilizarse.</li>
        <li>Pague únicamente el monto indicado y antes de la fecha límite.</li>
        <li>Conserve esta orden y el comprobante de pago para cualquier gestión posterior.</li>
        <li>Este documento no constituye por sí mismo un comprobante de pago.</li>
    </ul>
</div>

<footer class="footer">
    Emitida: <?= $escape($data['fecha_emision'] ?? '') ?> · Límite de pago: <?= $escape($data['fecha_vencimiento'] ?? '') ?> · Orden: <?= $escape($data['numero_orden'] ?? '') ?>
    <span class="footer-right">Página 1 de 1</span>
</footer>
</body>
</html>
