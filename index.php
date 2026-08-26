<?php
declare(strict_types=1);
require_once __DIR__ . '/src/PaymentOrderService.php';
require_once __DIR__ . '/src/DniLookupService.php';

$dataDirectory = getenv('PAYMENT_ORDER_DIR') ?: sys_get_temp_dir() . '/unah-payment-orders';
$service = new PaymentOrderService(new PaymentOrderRepository($dataDirectory), new PaymentOrderPdf(), new PaymentOrderMailer(), getenv('APP_KEY') ?: 'local-development-key-change-in-production', $dataDirectory . '/mail.log');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

if (isset($_GET['consultar_dni'])) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $person = (new DniLookupService(new CurlDniHttpClient()))->lookup(trim((string) $_GET['consultar_dni']));
    echo json_encode(['ok' => true, 'persona' => $person], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (InvalidArgumentException $error) {
    http_response_code(404); echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $error) {
    error_log('dni lookup failed: ' . $error::class . PHP_EOL, 3, $dataDirectory . '/mail.log');
    http_response_code(502); echo json_encode(['ok' => false, 'message' => 'No fue posible consultar el DNI. Intente nuevamente.'], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if (isset($_GET['download'], $_GET['expires'], $_GET['signature'])) {
  $document = $service->download((string)$_GET['download'], (int)$_GET['expires'], (string)$_GET['signature']);
  if (!$document) { http_response_code(404); exit('El enlace no es válido o ha expirado.'); }
  header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $document['name'] . '"');
  header('X-Content-Type-Options: nosniff'); echo $document['content']; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    $result = isset($input['retry_order_id']) ? $service->retry((string)$input['retry_order_id'], $baseUrl) : $service->createAndSend($input, $baseUrl);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (InvalidArgumentException|JsonException $error) {
    http_response_code(422); echo json_encode(['ok'=>false, 'message'=>$error instanceof JsonException ? 'La solicitud no es válida.' : $error->getMessage()]);
  } catch (Throwable $error) {
    error_log('payment-order request failed: ' . $error::class . PHP_EOL, 3, $dataDirectory . '/mail.log');
    @chmod($dataDirectory . '/mail.log', 0600);
    http_response_code(500); echo json_encode(['ok'=>false, 'message'=>'No pudimos completar la solicitud. Intente nuevamente.']);
  }
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UNAH - Esquela de Pago</title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

  <style type="text/tailwindcss">
    @theme {
      --color-vino-50:#fbf5f7;
      --color-vino-100:#f5e7ec;
      --color-vino-200:#e8cbd5;
      --color-vino-600:#8d3553;
      --color-vino-700:#762842;
      --color-vino-800:#631f37;
      --color-vino-900:#531d31;
    }

    @layer components {
      .card { @apply border border-slate-200 bg-white; }
      .head { @apply border-b border-slate-200 bg-slate-50 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wide text-vino-900 sm:px-3 sm:py-2 sm:text-[11px]; }
      .label { @apply mb-0.5 block text-[11px] font-semibold text-slate-700; }
      .input { @apply w-full rounded-none border border-slate-300 bg-white px-2.5 py-2 text-[13px] text-slate-900 outline-none focus:border-vino-700 focus:ring-1 focus:ring-vino-100 sm:px-2 sm:py-1.5 sm:text-xs; }
      .select { @apply w-full rounded-none border border-slate-300 bg-white px-2.5 py-2 text-[13px] text-slate-900 outline-none focus:border-vino-700 focus:ring-1 focus:ring-vino-100 sm:px-2 sm:py-1.5 sm:text-xs; }
      .btn { @apply inline-flex min-h-10 items-center justify-center rounded-none bg-vino-700 px-3 py-2 text-[13px] font-bold text-white hover:bg-vino-800 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-0 sm:text-xs; }
      .btn2 { @apply inline-flex min-h-10 items-center justify-center rounded-none border border-slate-300 bg-white px-3 py-2 text-[13px] font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-0 sm:text-xs; }
    }

    @media print {
      body > *:not(#printArea) { display:none !important; }
      #printArea { display:block !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-slate-100 pb-20 text-slate-900 sm:pb-0">

<header class="bg-vino-900 text-white">
  <div class="mx-auto flex max-w-5xl items-center justify-between gap-2 px-3 py-2 sm:px-4 sm:py-2.5">
    <div>
      <p class="text-[9px] uppercase tracking-[0.16em] text-vino-200">Universidad Nacional Autónoma de Huanta</p>
      <h1 class="text-[13px] font-bold leading-tight sm:text-sm">Dirección de Admisión · Generación de Esquela de Pago</h1>
    </div>
    <span class="hidden text-[11px] text-vino-100 sm:inline">Admisión</span>
  </div>
</header>

<main class="mx-auto max-w-5xl px-2.5 py-2.5 sm:px-4 sm:py-3">
  <div class="grid gap-2.5 lg:grid-cols-[1fr_270px]">

    <form id="formEsquela" class="space-y-3" onsubmit="event.preventDefault()">

      <!-- DATOS PERSONALES -->
      <section class="card">
        <div class="head">1. Datos del postulante</div>
        <div class="grid gap-2 p-2.5 sm:grid-cols-2 md:grid-cols-3 sm:p-3">
          <div class="sm:col-span-2 md:col-span-1">
            <label class="label">DNI *</label>
            <div class="flex gap-1.5">
              <input id="dni" class="input min-w-0" maxlength="8" inputmode="numeric" autocomplete="off" placeholder="12345678">
              <button id="btnBuscarDni" type="button" class="btn2 shrink-0">Buscar</button>
            </div>
            <p id="estadoDni" class="mt-1 hidden text-[10px] font-semibold" aria-live="polite"></p>
          </div>
          <div>
            <label class="label">Apellido paterno *</label>
            <input id="apPaterno" class="input" autocomplete="family-name">
          </div>
          <div>
            <label class="label">Apellido materno *</label>
            <input id="apMaterno" class="input" autocomplete="additional-name">
          </div>
          <div>
            <label class="label">Nombres *</label>
            <input id="nombres" class="input" autocomplete="given-name">
          </div>
          <div>
            <label class="label">Correo electrónico *</label>
            <input id="correo" type="email" class="input" autocomplete="email" placeholder="correo@ejemplo.com">
          </div>
          <div>
            <label class="label">Celular *</label>
            <input id="celular" class="input" inputmode="numeric" autocomplete="tel" placeholder="987654321">
          </div>
        </div>
      </section>

      <!-- CONCEPTO Y TASA -->
      <section class="card">
        <div class="head">2. Concepto y cálculo automático de la tasa</div>

        <div class="grid gap-2 p-2.5 sm:grid-cols-2 md:grid-cols-4 sm:p-3">
          <div>
            <label class="label">Concepto *</label>
            <select id="conceptoPago" class="select">
              <option value="inscripcion">Derecho de inscripción</option>
              <option value="reinscripcion">Reinscripción Extraordinario → Ordinario</option>
              <option value="constancia">Constancia de ingreso</option>
            </select>
          </div>

          <div id="grupoModalidad">
            <label class="label">Modalidad *</label>
            <select id="modalidad" class="select">
              <optgroup label="Examen Ordinario">
                <option value="ORD_EGRESADO">Egresados de secundaria</option>
                <option value="ORD_QUINTO">Quinto de secundaria</option>
                <option value="ORD_1_4">Primero a cuarto de secundaria</option>
              </optgroup>
              <optgroup label="Examen Extraordinario">
                <option value="EXT_PRIMEROS">Primeros puestos</option>
                <option value="EXT_BECA18">Beca 18</option>
                <option value="EXT_CONVENIOS">Convenios con comunidades campesinas y nativas</option>
                <option value="EXT_DISCAPACIDAD">Personas con discapacidad</option>
                <option value="EXT_VIOLENCIA">Afectados por violencia sociopolítica / víctimas del terrorismo</option>
                <option value="EXT_TITULADOS">Titulados o graduados</option>
                <option value="EXT_DEPORTISTAS">Deportistas destacados</option>
                <option value="EXT_SERVICIO">Servicio militar</option>
                <option value="EXT_TRASLADO_INT">Traslado interno</option>
                <option value="EXT_TRASLADO_EXT">Traslado externo</option>
              </optgroup>
            </select>
          </div>

          <div id="grupoColegio">
            <label class="label">Procedencia *</label>
            <select id="procedencia" class="select">
              <option value="estatal">Estatal</option>
              <option value="particular">Particular</option>
            </select>
          </div>

          <div id="grupoPeriodo">
            <label class="label">Periodo de inscripción *</label>
            <select id="periodo" class="select">
              <option value="regular">Regular</option>
              <option value="rezagado">Rezagado</option>
            </select>
          </div>
        </div>

        <div class="grid gap-2 border-t border-slate-200 bg-vino-50 p-2.5 sm:grid-cols-2 md:grid-cols-[1fr_140px_150px] sm:p-3">
          <div>
            <label class="label">Descripción aplicada</label>
            <input id="descripcionTasa" class="input bg-white font-semibold" readonly>
          </div>

          <div>
            <label class="label">Monto</label>
            <input id="monto" class="input bg-white text-right text-sm font-black text-vino-900" readonly>
          </div>

          <div>
            <label class="label">Estado de tasa</label>
            <input id="estadoTasa" class="input bg-white font-bold" readonly>
          </div>
        </div>

        <div id="alertaNoDisponible" class="hidden border-t border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
          Esta combinación no tiene una tasa habilitada en el cuadro proporcionado. No se puede generar la esquela.
        </div>
      </section>

      <!-- ESCUELA -->
      <section id="grupoEscuela" class="card">
        <div class="head">3. Escuela profesional</div>
        <div class="p-2.5 sm:p-3">
          <label class="label">Escuela profesional a la que postula *</label>
          <input id="escuela" class="input" list="escuelas" placeholder="Escriba o seleccione">
          <datalist id="escuelas">
            <option value="Ingeniería y Gestión Ambiental">
            <option value="Ingeniería de Negocios Agronómicos y Forestales">
            <option value="Administración de Turismo Sostenible y Hotelería">
            <option value="Ingeniería Civil">
            <option value="Ingeniería de Sistemas">
          </datalist>
        </div>
      </section>

      <!-- PAGO -->
      <section class="card">
        <div class="head">4. Instrucciones de pago</div>
        <div class="p-2.5 sm:p-3">
          <div class="border-l-4 border-vino-700 bg-vino-50 p-2">
            <p class="text-xs font-bold text-vino-900">Siga estos pasos para realizar el pago:</p>
            <ol class="mt-1.5 list-decimal space-y-1 pl-4 text-xs leading-5 text-slate-700">
              <li>Genere su esquela y verifique que <b>DNI, modalidad y monto</b> sean correctos.</li>
              <li>Pague únicamente el <b>monto exacto que aparece en la esquela</b>.</li>
              <li>Use el <b>código de pago</b> generado y su DNI como referencia cuando corresponda.</li>
              <li>Puede pagar por <b>Yape</b>, <b>Banco de la Nación</b> o presentar la <b>esquela impresa</b>, según los canales habilitados por la universidad.</li>
              <li>Conserve el voucher, captura o constancia del pago hasta que sea validado.</li>
              <li>Realice el pago antes de la fecha de vencimiento. Si está en periodo rezagado, el sistema aplicará automáticamente la tarifa de rezagados.</li>
            </ol>
          </div>

          <div class="mt-2 grid gap-2 md:grid-cols-2">
            <div class="border border-slate-200 p-2">
              <p class="text-xs font-bold text-vino-900">Yape</p>
              <p class="mt-1 text-[11px] leading-4 text-slate-600">Pague al número/QR oficial de la Universidad. En la referencia coloque su <b>DNI y código de esquela</b>.</p>
              <p class="mt-1 text-[10px] font-semibold text-amber-700">Número o QR institucional: POR CONFIGURAR</p>
            </div>

            <div class="border border-slate-200 p-2">
              <p class="text-xs font-bold text-vino-900">Banco de la Nación</p>
              <p class="mt-1 text-[11px] leading-4 text-slate-600">Realice el pago usando la cuenta/código oficial y conserve el voucher.</p>
              <p class="mt-1 text-[10px] font-semibold text-amber-700">Cuenta/código institucional: POR CONFIGURAR</p>
            </div>
          </div>
        </div>
      </section>

      <!-- GENERACIÓN -->
      <section class="card">
        <div class="head">5. Generar y enviar esquela</div>
        <div class="grid gap-2 p-2.5 sm:grid-cols-2 md:grid-cols-3 sm:p-3">
          <div>
            <label class="label">Fecha de emisión</label>
            <input id="fechaEmision" class="input bg-slate-100" readonly>
          </div>
          <div>
            <label class="label">Fecha de vencimiento *</label>
            <input id="fechaVencimiento" type="date" class="input" value="2026-09-30">
          </div>
          <div>
            <label class="label">Correo destino</label>
            <input id="correoDestino" class="input bg-slate-100" readonly placeholder="Se toma del correo registrado">
          </div>
        </div>

        <div class="hidden flex-wrap gap-2 border-t border-slate-200 bg-slate-50 p-3 sm:flex">
          <button id="btnGenerar" type="button" class="btn">Generar esquela y enviar correo</button>
          <button id="btnImprimir" type="button" class="btn2" disabled>Imprimir esquela</button>
        </div>
      </section>

      
      <!-- TASAS MÓVIL -->
      <section class="card sm:hidden">
        <div class="head">Tasas de pago</div>
        <div class="p-2">
          <div class="mb-2 flex gap-1 overflow-x-auto pb-1 text-[10px]">
            <button type="button" class="rate-tab shrink-0 border border-vino-700 bg-vino-700 px-2 py-1 font-bold text-white" data-group="ordinario">Ordinario</button>
            <button type="button" class="rate-tab shrink-0 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700" data-group="extraordinario">Extraordinario</button>
            <button type="button" class="rate-tab shrink-0 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700" data-group="otros">Otros pagos</button>
          </div>
          <div id="mobileRates" class="space-y-1.5"></div>
        </div>
      </section>

      <!-- TABLA DE TASAS -->
      <details class="card hidden sm:block" open>
        <summary class="cursor-pointer bg-slate-50 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-vino-900">
          Ver cuadro oficial de tasas
        </summary>

        <div class="overflow-x-auto bg-white p-2">
          <div class="min-w-[760px]">
            <div class="mb-1 text-center text-[11px] font-black uppercase text-slate-900">
              DIRECCIÓN DE ADMISIÓN
            </div>

            <table class="w-full border-collapse text-[10px] leading-tight">
              <thead>
                <tr>
                  <th class="border border-black bg-[#c40000] px-2 py-2 text-center font-black text-white" rowspan="2">
                    MODALIDADES
                  </th>
                  <th class="border border-black bg-[#c40000] px-2 py-2 text-center font-black text-white" colspan="2">
                    REGULAR
                  </th>
                  <th class="border border-black bg-[#c40000] px-2 py-2 text-center font-black text-white" colspan="2">
                    REZAGADOS
                  </th>
                </tr>
                <tr>
                  <th class="border border-black bg-[#c40000] px-2 py-1 text-center font-black text-white">ESTATAL</th>
                  <th class="border border-black bg-[#c40000] px-2 py-1 text-center font-black text-white">PARTICULAR</th>
                  <th class="border border-black bg-[#c40000] px-2 py-1 text-center font-black text-white">ESTATAL</th>
                  <th class="border border-black bg-[#c40000] px-2 py-1 text-center font-black text-white">PARTICULAR</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">EXAMEN ORDINARIO</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">ESTATAL</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">PARTICULAR</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">ESTATAL</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">PARTICULAR</td>
                </tr>

                <tr>
                  <td class="border border-black px-1 py-1">EGRESADOS DE SECUNDARIA</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">QUINTO DE SECUNDARIA</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">PRIMERO A CUARTO DE SECUNDARIA</td>
                  <td class="border border-black px-1 py-1 text-center">S/50.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/50.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/80.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/80.00</td>
                </tr>

                <tr>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">EXAMEN EXTRAORDINARIO</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">ESTATAL</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">PARTICULAR</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">ESTATAL</td>
                  <td class="border border-black bg-[#b9b7b7] px-2 py-1 text-center font-black">PARTICULAR</td>
                </tr>

                <tr>
                  <td class="border border-black px-1 py-1">PRIMEROS PUESTOS</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">BECA 18</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">CONVENIOS CON COMUNIDADES CAMPESINAS Y NATIVAS</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">PERSONAS CON DISCAPACIDAD</td>
                  <td class="border border-black px-1 py-1 text-center">S/85.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/100.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/115.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/130.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">AFECTADOS POR VIOLENCIA SOCIOPOLÍTICA Y/O VÍCTIMAS DEL TERRORISMO</td>
                  <td class="border border-black px-1 py-1 text-center">S/20.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/20.00</td>
                  <td class="border border-black px-1 py-1 text-center">-</td>
                  <td class="border border-black px-1 py-1 text-center">-</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">TITULADOS O GRADUADOS</td>
                  <td class="border border-black px-1 py-1 text-center">S/270.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/400.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/300.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/430.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">DEPORTISTAS DESTACADOS (CALIFICADOS Y/O ALTO NIVEL)</td>
                  <td class="border border-black px-1 py-1 text-center">S/170.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/200.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">SERVICIO MILITAR</td>
                  <td class="border border-black px-1 py-1 text-center">S/85.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/100.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/115.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/130.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">TRASLADO INTERNO</td>
                  <td class="border border-black px-1 py-1 text-center">S/230.00</td>
                  <td class="border border-black px-1 py-1 text-center">-</td>
                  <td class="border border-black px-1 py-1 text-center">S/260.00</td>
                  <td class="border border-black px-1 py-1 text-center">-</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1">TRASLADO EXTERNO</td>
                  <td class="border border-black px-1 py-1 text-center">S/270.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/400.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/300.00</td>
                  <td class="border border-black px-1 py-1 text-center">S/430.00</td>
                </tr>

                <tr>
                  <td class="border border-black bg-[#ffd966] px-2 py-1 text-center font-black">OTROS PAGOS</td>
                  <td class="border border-black bg-[#ffd966] px-2 py-1 text-center font-black" colspan="4">COSTO</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1 font-semibold">
                    PAGO POR REINSCRIPCIÓN DEL EXAMEN EXTRAORDINARIO AL EXAMEN ORDINARIO
                  </td>
                  <td class="border border-black px-1 py-1 text-center font-black" colspan="4">S/50.00</td>
                </tr>
                <tr>
                  <td class="border border-black px-1 py-1 font-semibold">PAGO POR CONSTANCIA DE INGRESO</td>
                  <td class="border border-black px-1 py-1 text-center font-black" colspan="4">S/15.00</td>
                </tr>
              </tbody>
            </table>

            <table class="mt-3 w-[66%] border-collapse text-[10px]">
              <tbody>
                <tr>
                  <td class="border border-black px-2 py-1 font-bold">Recargo por inscripción extemporánea</td>
                  <td class="border border-black px-2 py-1 text-center font-black">30</td>
                </tr>
                <tr>
                  <td class="border border-black px-2 py-1 font-bold">
                    Pago por reinscripción del examen extraordinario al examen ordinario
                  </td>
                  <td class="border border-black px-2 py-1 text-center font-black">50</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </details>

    </form>

    <!-- RESUMEN -->
    <aside class="order-first space-y-2.5 lg:order-none lg:sticky lg:top-3 lg:self-start">
      <section class="card">
        <div class="bg-vino-900 px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-white">Resumen</div>
        <div class="grid grid-cols-2 gap-2 p-2.5 text-xs sm:block sm:space-y-2 sm:p-3">
          <div>
            <span class="text-[10px] text-slate-500">Postulante</span>
            <p id="resNombre" class="font-bold">-</p>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <span class="text-[10px] text-slate-500">DNI</span>
              <p id="resDni" class="font-bold">-</p>
            </div>
            <div>
              <span class="text-[10px] text-slate-500">Procedencia</span>
              <p id="resProcedencia" class="font-bold">Estatal</p>
            </div>
          </div>
          <div>
            <span class="text-[10px] text-slate-500">Concepto</span>
            <p id="resConcepto" class="font-bold">Derecho de inscripción</p>
          </div>
          <div>
            <span class="text-[10px] text-slate-500">Modalidad</span>
            <p id="resModalidad" class="font-bold">Egresados de secundaria</p>
          </div>
          <div>
            <span class="text-[10px] text-slate-500">Periodo</span>
            <p id="resPeriodo" class="font-bold">Regular</p>
          </div>
          <div class="col-span-2 border-t border-slate-200 pt-2 sm:col-span-1">
            <span class="text-[10px] text-slate-500">Monto a pagar</span>
            <p id="resMonto" class="text-2xl font-black text-vino-900">S/ 170.00</p>
          </div>
        </div>
      </section>

      <section id="estadoEnvio" class="card hidden">
        <div id="estadoEnvioDetalle" class="border-l-4 border-emerald-600 p-3">
          <p id="tituloEnvio" class="text-xs font-bold text-emerald-700">Esquela generada</p>
          <p id="msgCorreo" class="mt-1 text-[11px] leading-4 text-slate-600"></p>
          <div class="mt-2 flex flex-wrap gap-2">
            <a id="enlaceEsquela" class="btn2" href="#">Descargar PDF</a>
            <button id="btnReintentar" type="button" class="btn2 hidden">Reintentar correo</button>
          </div>
        </div>
      </section>

      <section class="border border-amber-200 bg-amber-50 p-3">
        <p class="text-xs font-bold text-amber-900">Otros pagos</p>
        <div class="mt-1 space-y-1 text-[11px] text-amber-800">
          <p><b>Reinscripción Extraordinario → Ordinario:</b> S/ 50.00</p>
          <p><b>Constancia de ingreso:</b> S/ 15.00</p>
          <p><b>Recargo por inscripción extemporánea:</b> S/ 30.00</p>
        </div>
        <p class="mt-2 text-[10px] leading-4 text-amber-800">
          El recargo extemporáneo se muestra como concepto informado por separado y no se suma automáticamente a las tarifas de rezagados para evitar duplicar un cobro.
        </p>
      </section>
    </aside>

  </div>
</main>

<div class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-300 bg-white p-2 shadow-[0_-4px_16px_rgba(0,0,0,0.08)] sm:hidden">
  <div class="mx-auto grid max-w-5xl grid-cols-[1fr_auto] gap-2">
    <button id="btnGenerarMobile" type="button" class="btn w-full">Generar y enviar esquela</button>
    <button id="btnImprimirMobile" type="button" class="btn2 px-3" disabled>Imprimir</button>
  </div>
</div>


<!-- IMPRESIÓN -->
<div id="printArea" class="hidden">
  <div style="max-width:720px;margin:auto;padding:26px;font-family:Arial,sans-serif;color:#222;">
    <div style="border-bottom:3px solid #531d31;padding-bottom:10px;">
      <div style="font-size:11px;color:#531d31;font-weight:bold;">UNIVERSIDAD NACIONAL AUTÓNOMA DE HUANTA</div>
      <h2 style="margin:4px 0 0;font-size:20px;color:#531d31;">ESQUELA DE PAGO</h2>
      <div style="font-size:11px;">Dirección de Admisión</div>
    </div>

    <table style="width:100%;border-collapse:collapse;margin-top:14px;font-size:12px;">
      <tr><td style="width:180px;padding:4px;font-weight:bold;">Código de esquela:</td><td id="eCodigo"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Postulante:</td><td id="eNombre"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">DNI:</td><td id="eDni"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Correo:</td><td id="eCorreo"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Escuela profesional:</td><td id="eEscuela"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Concepto:</td><td id="eConcepto"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Modalidad:</td><td id="eModalidad"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Procedencia:</td><td id="eProcedencia"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Periodo:</td><td id="ePeriodo"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Fecha de emisión:</td><td id="eEmision"></td></tr>
      <tr><td style="padding:4px;font-weight:bold;">Vencimiento:</td><td id="eVence"></td></tr>
    </table>

    <div style="margin-top:14px;border:2px solid #531d31;padding:12px;text-align:center;">
      <div style="font-size:11px;font-weight:bold;color:#531d31;">MONTO A PAGAR</div>
      <div id="eMonto" style="margin-top:4px;font-size:28px;font-weight:900;color:#531d31;"></div>
    </div>

    <div style="margin-top:16px;border:1px solid #ccc;padding:12px;">
      <div style="font-size:13px;font-weight:bold;color:#531d31;margin-bottom:6px;">¿CÓMO REALIZAR EL PAGO?</div>
      <ol style="padding-left:18px;margin:0;font-size:11px;line-height:1.55;">
        <li>Verifique que sus datos y el monto de esta esquela sean correctos.</li>
        <li>Pague únicamente el monto exacto indicado.</li>
        <li>Use su DNI y el código de esta esquela como referencia.</li>
        <li>Puede utilizar Yape, Banco de la Nación o presentar la esquela impresa, según los canales habilitados.</li>
        <li>Conserve el voucher o constancia hasta que el pago sea validado.</li>
        <li>No realice el pago después de la fecha de vencimiento.</li>
      </ol>
      <div style="margin-top:8px;font-size:10px;">
        <b>Yape institucional:</b> POR CONFIGURAR ·
        <b>Banco de la Nación:</b> POR CONFIGURAR
      </div>
    </div>
  </div>
</div>

<script>
  const TASAS = {
    ORD_EGRESADO:      { nombre:"Egresados de secundaria", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    ORD_QUINTO:        { nombre:"Quinto de secundaria", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    ORD_1_4:           { nombre:"Primero a cuarto de secundaria", regular:{estatal:50, particular:50}, rezagado:{estatal:80, particular:80} },

    EXT_PRIMEROS:      { nombre:"Primeros puestos", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    EXT_BECA18:        { nombre:"Beca 18", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    EXT_CONVENIOS:     { nombre:"Convenios con comunidades campesinas y nativas", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    EXT_DISCAPACIDAD:  { nombre:"Personas con discapacidad", regular:{estatal:85, particular:100}, rezagado:{estatal:115, particular:130} },
    EXT_VIOLENCIA:     { nombre:"Afectados por violencia sociopolítica / víctimas del terrorismo", regular:{estatal:20, particular:20}, rezagado:{estatal:null, particular:null} },
    EXT_TITULADOS:     { nombre:"Titulados o graduados", regular:{estatal:270, particular:400}, rezagado:{estatal:300, particular:430} },
    EXT_DEPORTISTAS:   { nombre:"Deportistas destacados (calificados y/o alto nivel)", regular:{estatal:170, particular:200}, rezagado:{estatal:200, particular:230} },
    EXT_SERVICIO:      { nombre:"Servicio militar", regular:{estatal:85, particular:100}, rezagado:{estatal:115, particular:130} },
    EXT_TRASLADO_INT:  { nombre:"Traslado interno", regular:{estatal:230, particular:null}, rezagado:{estatal:260, particular:null} },
    EXT_TRASLADO_EXT:  { nombre:"Traslado externo", regular:{estatal:270, particular:400}, rezagado:{estatal:300, particular:430} }
  };

  const OTROS = {
    reinscripcion: { nombre:"Reinscripción del examen extraordinario al examen ordinario", monto:50 },
    constancia: { nombre:"Constancia de ingreso", monto:15 }
  };

  const $ = id => document.getElementById(id);

  function money(n) {
    return n == null ? "-" : `S/ ${Number(n).toFixed(2)}`;
  }

  function nombreCompleto() {
    return [$("apPaterno").value.trim(), $("apMaterno").value.trim(), $("nombres").value.trim()]
      .filter(Boolean).join(" ");
  }

  function conceptoTexto() {
    if ($("conceptoPago").value === "inscripcion") return "Derecho de inscripción";
    return OTROS[$("conceptoPago").value]?.nombre || "";
  }

  function calcular() {
    const concepto = $("conceptoPago").value;
    let valor = null;
    let descripcion = "";
    let disponible = true;

    const esInscripcion = concepto === "inscripcion";

    $("grupoModalidad").classList.toggle("hidden", !esInscripcion);
    $("grupoColegio").classList.toggle("hidden", !esInscripcion);
    $("grupoPeriodo").classList.toggle("hidden", !esInscripcion);
    $("grupoEscuela").classList.toggle("hidden", concepto === "constancia");

    if (esInscripcion) {
      const tasa = TASAS[$("modalidad").value];
      const periodo = $("periodo").value;
      const procedencia = $("procedencia").value;
      valor = tasa?.[periodo]?.[procedencia] ?? null;
      disponible = valor !== null;

      descripcion = `${tasa.nombre} · ${procedencia === "estatal" ? "Estatal" : "Particular"} · ${periodo === "regular" ? "Regular" : "Rezagado"}`;

      $("resModalidad").textContent = tasa.nombre;
      $("resProcedencia").textContent = procedencia === "estatal" ? "Estatal" : "Particular";
      $("resPeriodo").textContent = periodo === "regular" ? "Regular" : "Rezagado";
    } else {
      valor = OTROS[concepto].monto;
      descripcion = OTROS[concepto].nombre;
      $("resModalidad").textContent = "No aplica";
      $("resProcedencia").textContent = "No aplica";
      $("resPeriodo").textContent = "No aplica";
    }

    $("descripcionTasa").value = descripcion;
    $("monto").value = disponible ? money(valor) : "NO DISPONIBLE";
    $("estadoTasa").value = disponible ? "HABILITADA" : "NO HABILITADA";
    $("estadoTasa").className = "input bg-white font-bold " + (disponible ? "text-emerald-700" : "text-red-700");
    $("alertaNoDisponible").classList.toggle("hidden", disponible);
    $("btnGenerar").disabled = !disponible;

    $("resMonto").textContent = disponible ? money(valor) : "No disponible";
    $("resConcepto").textContent = conceptoTexto();
    $("correoDestino").value = $("correo").value.trim();

    return { disponible, valor, descripcion };
  }

  function actualizarResumen() {
    $("resNombre").textContent = nombreCompleto() || "-";
    $("resDni").textContent = $("dni").value.trim() || "-";
    calcular();
  }

  async function buscarDni() {
    const dni = $("dni").value.trim();
    const estado = $("estadoDni");
    if (!/^\d{8}$/.test(dni)) {
      estado.className = "mt-1 text-[10px] font-semibold text-red-700";
      estado.textContent = "Ingrese un DNI válido de 8 dígitos.";
      return;
    }

    $("btnBuscarDni").disabled = true;
    estado.className = "mt-1 text-[10px] font-semibold text-slate-600";
    estado.textContent = "Consultando DNI…";
    try {
      const response = await fetch(`${window.location.pathname}?consultar_dni=${encodeURIComponent(dni)}`, {headers:{"Accept":"application/json"}});
      const result = await response.json().catch(() => ({message:"Respuesta no válida del servicio."}));
      if (!response.ok || !result.ok) throw new Error(result.message || "No se encontraron datos para el DNI.");
      $("nombres").value = result.persona.nombres || "";
      $("apPaterno").value = result.persona.apellido_paterno || "";
      $("apMaterno").value = result.persona.apellido_materno || "";
      estado.className = "mt-1 text-[10px] font-semibold text-emerald-700";
      estado.textContent = "Datos cargados correctamente.";
      actualizarResumen();
    } catch (error) {
      estado.className = "mt-1 text-[10px] font-semibold text-red-700";
      estado.textContent = error.message || "No fue posible consultar el DNI.";
    } finally {
      $("btnBuscarDni").disabled = false;
    }
  }

  function renderTabla() {
    const tbody = $("tablaTasas");
    if (!tbody) return;
    tbody.innerHTML = "";

    Object.values(TASAS).forEach(t => {
      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td class="border border-slate-200 px-2 py-1">${t.nombre}</td>
        <td class="border border-slate-200 px-2 py-1 text-center">${money(t.regular.estatal)}</td>
        <td class="border border-slate-200 px-2 py-1 text-center">${money(t.regular.particular)}</td>
        <td class="border border-slate-200 px-2 py-1 text-center">${money(t.rezagado.estatal)}</td>
        <td class="border border-slate-200 px-2 py-1 text-center">${money(t.rezagado.particular)}</td>`;
      tbody.appendChild(tr);
    });
  }

  function validarFormulario() {
    const concepto = $("conceptoPago").value;
    const calc = calcular();

    if (!calc.disponible) return "La tasa seleccionada no está habilitada.";

    if ($("dni").value.trim().length !== 8) return "Ingrese un DNI válido de 8 dígitos.";
    if (!nombreCompleto()) return "Ingrese los nombres y apellidos del postulante.";

    const correo = $("correo").value.trim();
    if (!correo || !correo.includes("@")) return "Ingrese un correo electrónico válido.";

    if (!$("celular").value.trim()) return "Ingrese un número de celular.";
    if (concepto !== "constancia" && !$("escuela").value.trim()) return "Seleccione la escuela profesional.";
    if (!$("fechaVencimiento").value) return "Seleccione la fecha de vencimiento.";

    return "";
  }

  function generarCodigo() {
    const d = new Date();
    const ymd = d.toISOString().slice(0,10).replaceAll("-","");
    const rnd = Math.floor(10000 + Math.random()*89999);
    return `UNAH-${ymd}-${rnd}`;
  }

  function prepararEsquela(codigo, calc) {
    const concepto = $("conceptoPago").value;
    $("eCodigo").textContent = codigo;
    $("eNombre").textContent = nombreCompleto();
    $("eDni").textContent = $("dni").value.trim();
    $("eCorreo").textContent = $("correo").value.trim();
    $("eEscuela").textContent = concepto === "constancia" ? "No aplica" : ($("escuela").value.trim() || "-");
    $("eConcepto").textContent = conceptoTexto();
    $("eModalidad").textContent = concepto === "inscripcion" ? TASAS[$("modalidad").value].nombre : "No aplica";
    $("eProcedencia").textContent = concepto === "inscripcion" ? ($("procedencia").value === "estatal" ? "Estatal" : "Particular") : "No aplica";
    $("ePeriodo").textContent = concepto === "inscripcion" ? ($("periodo").value === "regular" ? "Regular" : "Rezagado") : "No aplica";
    $("eEmision").textContent = $("fechaEmision").value;
    $("eVence").textContent = $("fechaVencimiento").value;
    $("eMonto").textContent = money(calc.valor);
  }

  let ultimaOrdenId = null;

  function mostrarResultado(result) {
    ultimaOrdenId = result.order_id;
    const enviado = result.mail_sent;
    $("estadoEnvio").classList.remove("hidden");
    $("estadoEnvioDetalle").className = `border-l-4 p-3 ${enviado ? "border-emerald-600" : "border-amber-500"}`;
    $("tituloEnvio").className = `text-xs font-bold ${enviado ? "text-emerald-700" : "text-amber-800"}`;
    $("tituloEnvio").textContent = enviado ? "Orden generada y correo enviado" : "Orden generada; correo pendiente";
    $("msgCorreo").textContent = result.message;
    $("enlaceEsquela").href = result.download_url;
    $("btnReintentar").classList.toggle("hidden", enviado);
    $("btnImprimir").disabled = false;
  }

  async function enviarSolicitud(payload) {
    const response = await fetch(window.location.pathname, {method:"POST", headers:{"Content-Type":"application/json", "Accept":"application/json"}, body:JSON.stringify(payload)});
    const result = await response.json().catch(() => ({message:"No pudimos completar la solicitud. Intente nuevamente."}));
    if (!response.ok) throw new Error(result.message || "No pudimos completar la solicitud. Intente nuevamente.");
    return result;
  }

  $("btnGenerar").addEventListener("click", async () => {
    const error = validarFormulario();
    if (error) {
      alert(error);
      return;
    }

    const calc = calcular();
    const payload = {
      dni: $("dni").value.trim(),
      nombres: nombreCompleto(),
      correo: $("correo").value.trim(),
      celular: $("celular").value.trim(),
      escuela: $("escuela").value.trim(),
      concepto: conceptoTexto(),
      concepto_key: $("conceptoPago").value,
      modalidad_key: $("modalidad").value,
      modalidad: $("conceptoPago").value === "inscripcion" ? TASAS[$("modalidad").value].nombre : null,
      procedencia: $("conceptoPago").value === "inscripcion" ? $("procedencia").value : null,
      periodo: $("conceptoPago").value === "inscripcion" ? $("periodo").value : null,
      monto: calc.valor,
      fecha_emision: $("fechaEmision").value,
      fecha_vencimiento: $("fechaVencimiento").value
    };

    $("btnGenerar").disabled = true;
    try {
      const result = await enviarSolicitud(payload);
      prepararEsquela(result.code, calc);
      mostrarResultado(result);
    } catch (error) {
      alert(error.message || "No pudimos completar la solicitud. Intente nuevamente.");
    } finally {
      $("btnGenerar").disabled = false;
    }
  });

  $("btnReintentar").addEventListener("click", async () => {
    if (!ultimaOrdenId) return;
    $("btnReintentar").disabled = true;
    try { mostrarResultado(await enviarSolicitud({retry_order_id: ultimaOrdenId})); }
    catch (error) { alert(error.message || "No fue posible reenviar el correo. Intente nuevamente."); }
    finally { $("btnReintentar").disabled = false; }
  });

  $("btnImprimir").addEventListener("click", () => window.print());
  $("btnBuscarDni").addEventListener("click", buscarDni);
  $("dni").addEventListener("keydown", event => {
    if (event.key === "Enter") { event.preventDefault(); buscarDni(); }
  });

  document.querySelectorAll("input, select").forEach(el => {
    el.addEventListener("input", actualizarResumen);
    el.addEventListener("change", actualizarResumen);
  });

  const now = new Date();
  $("fechaEmision").value = now.toLocaleDateString("es-PE");

  renderTabla();
  actualizarResumen();

  const RATE_GROUPS = {
    ordinario: ["ORD_EGRESADO","ORD_QUINTO","ORD_1_4"],
    extraordinario: ["EXT_PRIMEROS","EXT_BECA18","EXT_CONVENIOS","EXT_DISCAPACIDAD","EXT_VIOLENCIA","EXT_TITULADOS","EXT_DEPORTISTAS","EXT_SERVICIO","EXT_TRASLADO_INT","EXT_TRASLADO_EXT"]
  };

  function renderMobileRates(group = "ordinario") {
    const box = $("mobileRates");
    if (!box) return;
    box.innerHTML = "";

    if (group === "otros") {
      box.innerHTML = `
        <div class="border border-slate-200 p-2 text-[11px]">
          <div class="flex justify-between gap-3"><span>Reinscripción Extraordinario → Ordinario</span><b>S/ 50.00</b></div>
        </div>
        <div class="border border-slate-200 p-2 text-[11px]">
          <div class="flex justify-between gap-3"><span>Constancia de ingreso</span><b>S/ 15.00</b></div>
        </div>
        <div class="border border-amber-200 bg-amber-50 p-2 text-[11px]">
          <div class="flex justify-between gap-3"><span>Recargo por inscripción extemporánea</span><b>S/ 30.00</b></div>
        </div>`;
      return;
    }

    RATE_GROUPS[group].forEach(key => {
      const t = TASAS[key];
      const div = document.createElement("div");
      div.className = "border border-slate-200 bg-white p-2";
      div.innerHTML = `
        <p class="text-[11px] font-bold leading-tight text-slate-800">${t.nombre}</p>
        <div class="mt-1 grid grid-cols-2 gap-x-3 gap-y-1 text-[10px]">
          <div><span class="text-slate-500">Regular estatal</span><br><b>${money(t.regular.estatal)}</b></div>
          <div><span class="text-slate-500">Regular particular</span><br><b>${money(t.regular.particular)}</b></div>
          <div><span class="text-slate-500">Rezagado estatal</span><br><b>${money(t.rezagado.estatal)}</b></div>
          <div><span class="text-slate-500">Rezagado particular</span><br><b>${money(t.rezagado.particular)}</b></div>
        </div>`;
      box.appendChild(div);
    });
  }

  document.querySelectorAll(".rate-tab").forEach(btn => {
    btn.addEventListener("click", () => {
      document.querySelectorAll(".rate-tab").forEach(b => {
        b.className = "rate-tab shrink-0 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700";
      });
      btn.className = "rate-tab shrink-0 border border-vino-700 bg-vino-700 px-2 py-1 font-bold text-white";
      renderMobileRates(btn.dataset.group);
    });
  });

  const btnGenerarMobile = $("btnGenerarMobile");
  const btnImprimirMobile = $("btnImprimirMobile");

  btnGenerarMobile?.addEventListener("click", () => $("btnGenerar").click());
  btnImprimirMobile?.addEventListener("click", () => $("btnImprimir").click());

  const observer = new MutationObserver(() => {
    if (btnGenerarMobile) btnGenerarMobile.disabled = $("btnGenerar").disabled;
    if (btnImprimirMobile) btnImprimirMobile.disabled = $("btnImprimir").disabled;
  });
  observer.observe($("btnGenerar"), {attributes:true, attributeFilter:["disabled"]});
  observer.observe($("btnImprimir"), {attributes:true, attributeFilter:["disabled"]});

  renderMobileRates("ordinario");

</script>
</body>
</html>
