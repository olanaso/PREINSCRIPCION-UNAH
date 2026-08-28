<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];
$appConfiguration = require __DIR__ . '/config.php';
$paymentConfiguration = isset($appConfiguration['payments']) && is_array($appConfiguration['payments'])
    ? $appConfiguration['payments']
    : array();
$yapeConfiguration = isset($paymentConfiguration['yape']) && is_array($paymentConfiguration['yape'])
    ? $paymentConfiguration['yape']
    : array();
$cardConfiguration = isset($paymentConfiguration['card']) && is_array($paymentConfiguration['card'])
    ? $paymentConfiguration['card']
    : array();

$yapeQrImage = trim((string) ($yapeConfiguration['qr_image'] ?? ''));
$yapeQrIsHttps = filter_var($yapeQrImage, FILTER_VALIDATE_URL) !== false
    && strtolower((string) parse_url($yapeQrImage, PHP_URL_SCHEME)) === 'https';
$yapeQrIsRelative = preg_match('#^(?:assets/)?[A-Za-z0-9_./-]+$#', $yapeQrImage) === 1;
if ($yapeQrImage !== '' && !$yapeQrIsHttps && !$yapeQrIsRelative) {
    $yapeQrImage = '';
}
$cardCheckoutUrl = trim((string) ($cardConfiguration['checkout_url'] ?? ''));
if (filter_var($cardCheckoutUrl, FILTER_VALIDATE_URL) === false
    || strtolower((string) parse_url($cardCheckoutUrl, PHP_URL_SCHEME)) !== 'https') {
    $cardCheckoutUrl = '';
}
$publicPaymentConfiguration = array(
    'yape' => array(
        'qrImage' => $yapeQrImage,
        'phone' => trim((string) ($yapeConfiguration['phone'] ?? '')),
        'holder' => trim((string) ($yapeConfiguration['holder'] ?? '')),
    ),
    'card' => array(
        'checkoutUrl' => $cardCheckoutUrl,
        'provider' => trim((string) ($cardConfiguration['provider'] ?? 'Pasarela segura')),
    ),
);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>UNAH - Esquela de Pago</title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <script src="https://cdn.jsdelivr.net/npm/imask@7.6.1/dist/imask.min.js" integrity="sha384-UO8YwPv//GjwHj93ZlwXcDNjv3BSxdBFUB2jtiOuL3d/a0kS9E8sYvHjTBkQI8u8" crossorigin="anonymous"></script>

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
      .btn { @apply inline-flex min-h-10 items-center justify-center gap-1.5 rounded-none bg-vino-700 px-3 py-2 text-[13px] font-bold text-white hover:bg-vino-800 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-0 sm:text-xs; }
      .btn2 { @apply inline-flex min-h-10 items-center justify-center gap-1.5 rounded-none border border-slate-300 bg-white px-3 py-2 text-[13px] font-bold text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 sm:min-h-0 sm:text-xs; }
    }

    @keyframes qrScan {
      0%, 100% { transform:translateY(0); opacity:.35; }
      50% { transform:translateY(176px); opacity:1; }
    }
    @keyframes paymentSpin { to { transform:rotate(360deg); } }
    @keyframes checkPop {
      0% { transform:scale(.35); opacity:0; }
      70% { transform:scale(1.12); opacity:1; }
      100% { transform:scale(1); opacity:1; }
    }
    @keyframes softPulse { 50% { transform:scale(1.04); opacity:.82; } }
    .qr-scan-line { animation:qrScan 1.8s ease-in-out infinite; }
    .payment-spinner { animation:paymentSpin .8s linear infinite; }
    .payment-check { animation:checkPop .55s ease-out both; }
    .qr-pulse { animation:softPulse 1.4s ease-in-out infinite; }

    @media print {
      body[data-print-target="order"] > *:not(#printArea) { display:none !important; }
      body[data-print-target="order"] #printArea { display:block !important; }
      body[data-print-target="receipt"] > *:not(#reciboPrintArea) { display:none !important; }
      body[data-print-target="receipt"] #reciboPrintArea { display:block !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-slate-100 pb-4 text-slate-900">

<header class="bg-vino-900 text-white">
  <div class="mx-auto flex max-w-5xl items-center justify-between gap-2 px-3 py-2 sm:px-4 sm:py-2.5">
    <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
      <div class="shrink-0 px-1.5 py-1 shadow-sm">
        <img src="assets/logo-unah.png?v=20260827" alt="Universidad Nacional Autónoma de Huanta" class="h-10 w-auto sm:h-14">
      </div>
      <div class="min-w-0">
        <p class="text-[9px] uppercase tracking-[0.14em] text-vino-200">Dirección de Admisión</p>
        <h1 class="text-[12px] font-bold leading-tight sm:text-sm">Generación de Esquela de Pago</h1>
      </div>
    </div>
    <span class="hidden text-[11px] text-vino-100 sm:inline">Admisión</span>
  </div>
</header>

<main class="mx-auto max-w-5xl px-2.5 py-2.5 sm:px-4 sm:py-3">
  <nav class="mb-3 border border-slate-200 bg-white px-2 py-3" aria-label="Progreso del registro">
    <ol id="indicadorPasos" class="grid grid-cols-4">
      <li class="relative text-center">
        <span class="absolute left-1/2 top-4 h-px w-full bg-slate-300" aria-hidden="true"></span>
        <button type="button" class="step-button relative z-10 inline-flex flex-col items-center gap-1" data-step-button="1">
          <span class="step-circle flex h-8 w-8 items-center justify-center rounded-full bg-vino-700 text-xs font-black text-white">1</span>
          <span class="step-label text-[10px] font-bold text-vino-800 sm:text-xs">Identificación</span>
        </button>
      </li>
      <li class="relative text-center">
        <span class="absolute left-1/2 top-4 h-px w-full bg-slate-300" aria-hidden="true"></span>
        <button type="button" class="step-button relative z-10 inline-flex flex-col items-center gap-1" data-step-button="2" disabled>
          <span class="step-circle flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-black text-slate-500">2</span>
          <span class="step-label text-[10px] font-semibold text-slate-500 sm:text-xs">Selección</span>
        </button>
      </li>
      <li class="relative text-center">
        <span class="absolute left-1/2 top-4 h-px w-full bg-slate-300" aria-hidden="true"></span>
        <button type="button" class="step-button relative z-10 inline-flex flex-col items-center gap-1" data-step-button="3" disabled>
          <span class="step-circle flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-black text-slate-500">3</span>
          <span class="step-label text-[10px] font-semibold text-slate-500 sm:text-xs">Pago</span>
        </button>
      </li>
      <li class="relative text-center">
        <button type="button" class="step-button relative z-10 inline-flex flex-col items-center gap-1" data-step-button="4" disabled>
          <span class="step-circle flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-black text-slate-500">4</span>
          <span class="step-label text-[10px] font-semibold text-slate-500 sm:text-xs">Confirmación</span>
        </button>
      </li>
    </ol>
  </nav>

  <div class="grid gap-2.5 lg:grid-cols-[1fr_270px]">

    <form id="formEsquela" class="space-y-3" onsubmit="event.preventDefault()">

      <!-- DATOS PERSONALES -->
      <section class="card" data-step-panel="1">
        <div class="head">1. Datos del postulante</div>
        <div class="grid gap-2 p-2.5 sm:grid-cols-2 md:grid-cols-3 sm:p-3">
          <aside class="order-first border border-sky-200 bg-sky-50 p-3 sm:col-span-2 md:col-span-3" aria-labelledby="ayudaDatosDniTitulo">
            <div class="flex items-start gap-2.5">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="mt-0.5 h-5 w-5 shrink-0 text-sky-700" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 10v6"></path><path d="M12 7h.01"></path></svg>
              <div class="min-w-0">
                <p id="ayudaDatosDniTitulo" class="text-xs font-bold text-sky-950">Evite errores en los datos del postulante</p>
                <p class="mt-1 text-[11px] leading-4 text-sky-900">Para que los datos coincidan exactamente con el DNI y evitar errores de digitación, le recomendamos subir una foto clara del anverso del documento.</p>
                <p id="indicacionModoDatosDni" class="mt-1 text-[10px] font-bold leading-4 text-sky-800">Haga clic en una de las siguientes opciones para continuar:</p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                  <button id="opcionFotoDni" type="button" class="w-full cursor-pointer border border-emerald-200 bg-white p-2 text-left transition hover:border-emerald-500 hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-300" aria-pressed="false" aria-controls="panelOcrDni" aria-describedby="indicacionModoDatosDni">
                    <p class="text-[11px] font-bold text-emerald-800">Opción recomendada: subir una foto</p>
                    <p class="mt-0.5 text-[10px] leading-4 text-slate-600"><strong>Haga clic aquí</strong> para mostrar el lector y luego use “Subir o tomar foto”. El sistema completará los datos encontrados.</p>
                  </button>
                  <button id="opcionManualDni" type="button" class="w-full cursor-pointer border border-slate-200 bg-white p-2 text-left transition hover:border-sky-400 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-300" aria-pressed="false" aria-controls="panelOcrDni" aria-describedby="indicacionModoDatosDni">
                    <p class="text-[11px] font-bold text-slate-800">Opción manual: escribir los datos</p>
                    <p class="mt-0.5 text-[10px] leading-4 text-slate-600"><strong>Haga clic aquí</strong> si prefiere copiar cada dato con cuidado, exactamente como aparece en el DNI.</p>
                  </button>
                </div>
              </div>
            </div>
          </aside>
          <div>
            <label class="label" for="dni">DNI *</label>
            <div class="relative">
              <input id="dni" class="input pr-11" maxlength="8" inputmode="numeric" autocomplete="off" aria-describedby="ayudaCampoDni estadoDni" placeholder="12345678">
              <button id="btnBuscarDni" type="button" class="absolute inset-y-px right-px flex w-10 items-center justify-center border-l border-slate-200 bg-white text-vino-800 hover:bg-vino-50 disabled:cursor-wait disabled:opacity-60" aria-label="Buscar datos del DNI" title="Buscar DNI">
                <svg id="iconoBuscarDni" aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="11" cy="11" r="7"></circle>
                  <path d="m20 20-3.5-3.5"></path>
                </svg>
              </button>
            </div>
            <p id="ayudaCampoDni" class="mt-1 text-[10px] leading-4 text-slate-500">Copie los 8 dígitos, sin el dígito verificador que aparece después del guion.</p>
            <p id="estadoDni" class="mt-1 hidden text-[10px] leading-4" aria-live="polite"></p>
          </div>
          <div>
            <label class="label" for="apPaterno">Apellido paterno *</label>
            <input id="apPaterno" class="input" autocomplete="family-name" aria-describedby="ayudaApellidoPaterno">
            <p id="ayudaApellidoPaterno" class="mt-1 text-[10px] leading-4 text-slate-500">DNI amarillo: dato ubicado debajo de “Primer Apellido”.</p>
          </div>
          <div>
            <label class="label" for="apMaterno">Apellido materno *</label>
            <input id="apMaterno" class="input" autocomplete="additional-name" aria-describedby="ayudaApellidoMaterno">
            <p id="ayudaApellidoMaterno" class="mt-1 text-[10px] leading-4 text-slate-500">DNI amarillo: dato ubicado debajo de “Segundo Apellido”.</p>
          </div>
          <div>
            <label class="label" for="nombres">Prenombres *</label>
            <input id="nombres" class="input" autocomplete="given-name" aria-describedby="ayudaPrenombres">
            <p id="ayudaPrenombres" class="mt-1 text-[10px] leading-4 text-slate-500">Copie todos los nombres que aparecen debajo de “Pre Nombres” o “Prenombres”.</p>
          </div>
          <div>
            <label class="label">Correo electrónico *</label>
            <input id="correo" type="text" inputmode="email" class="input" autocomplete="email" autocapitalize="none" spellcheck="false" aria-describedby="errorCorreo" placeholder="correo@ejemplo.com">
            <p id="errorCorreo" class="mt-1 hidden text-[10px] leading-4 text-red-700"></p>
          </div>
          <div>
            <label class="label">Celular *</label>
            <input id="celular" type="text" class="input" inputmode="numeric" autocomplete="tel" maxlength="11" aria-describedby="errorCelular" placeholder="987 654 321">
            <p id="errorCelular" class="mt-1 hidden text-[10px] leading-4 text-red-700"></p>
          </div>
          <div id="panelOcrDni" class="order-first hidden border border-amber-200 bg-amber-50 p-3 sm:col-span-2 md:col-span-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
              <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-amber-950">Subir foto del DNI (recomendado)</p>
                <p class="mt-1 text-[11px] leading-4 text-amber-900">Compatible con el DNI amarillo y el DNI electrónico. La imagen se procesa únicamente con Google Gemini para extraer el DNI, los apellidos y los prenombres; esta aplicación no conserva el archivo. Si Gemini no está disponible, no se completará ningún campo automáticamente.</p>
                <p class="mt-2 border-l-4 border-amber-500 bg-amber-100 px-2 py-1.5 text-[11px] font-bold leading-4 text-amber-950">Paso siguiente: haga clic en “Subir o tomar foto”.</p>
                <div class="mt-3 border border-amber-200 bg-white/80 p-2.5">
                  <p class="text-[11px] font-bold text-amber-950">Ejemplo de cómo debe verse la foto</p>
                  <div class="mt-2 grid gap-3 sm:grid-cols-[190px_1fr] sm:items-center">
                    <figure>
                      <svg viewBox="0 0 320 200" role="img" aria-label="Ejemplo de una foto correcta del anverso del DNI" class="h-auto w-full rounded-lg shadow-sm">
                        <rect x="3" y="3" width="314" height="194" rx="13" fill="#fff4bd" stroke="#059669" stroke-width="5"></rect>
                        <text x="160" y="23" text-anchor="middle" font-size="10" font-family="Arial, sans-serif" font-weight="700" fill="#334155">DNI · FOTO CORRECTA</text>
                        <rect x="19" y="39" width="83" height="105" rx="5" fill="#dbeafe" stroke="#94a3b8"></rect>
                        <circle cx="60.5" cy="76" r="19" fill="#94a3b8"></circle>
                        <path d="M31 132c4-27 16-39 29-39s26 12 31 39" fill="#94a3b8"></path>
                        <text x="118" y="49" font-size="8" font-family="Arial, sans-serif" fill="#64748b">PRIMER APELLIDO</text>
                        <rect x="118" y="55" width="91" height="8" rx="2" fill="#334155"></rect>
                        <text x="118" y="82" font-size="8" font-family="Arial, sans-serif" fill="#64748b">SEGUNDO APELLIDO</text>
                        <rect x="118" y="88" width="78" height="8" rx="2" fill="#334155"></rect>
                        <text x="118" y="115" font-size="8" font-family="Arial, sans-serif" fill="#64748b">PRE NOMBRES</text>
                        <rect x="118" y="121" width="113" height="8" rx="2" fill="#334155"></rect>
                        <text x="214" y="48" font-size="8" font-family="Arial, sans-serif" fill="#64748b">DNI</text>
                        <rect x="235" y="38" width="63" height="15" rx="2" fill="#b91c1c"></rect>
                        <rect x="19" y="159" width="279" height="6" rx="2" fill="#334155"></rect>
                        <rect x="19" y="174" width="249" height="6" rx="2" fill="#334155"></rect>
                        <circle cx="292" cy="174" r="15" fill="#059669"></circle>
                        <path d="m285 174 5 5 9-11" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
                      </svg>
                      <figcaption class="mt-1 text-center text-[9px] leading-3 text-slate-500">Ejemplo ilustrativo sin datos personales reales.</figcaption>
                    </figure>
                    <ul class="space-y-1 text-[10px] leading-4 text-amber-900">
                      <li class="flex gap-1.5"><span class="font-bold text-emerald-700">✓</span><span>Muestre el documento completo y sus cuatro bordes.</span></li>
                      <li class="flex gap-1.5"><span class="font-bold text-emerald-700">✓</span><span>Tome la foto de frente, en posición horizontal y sin inclinarla.</span></li>
                      <li class="flex gap-1.5"><span class="font-bold text-emerald-700">✓</span><span>Asegúrese de que el texto esté enfocado y se pueda leer.</span></li>
                      <li class="flex gap-1.5"><span class="font-bold text-emerald-700">✓</span><span>Evite reflejos, sombras, dedos u objetos sobre el DNI.</span></li>
                    </ul>
                  </div>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                  <label id="btnSeleccionarDni" for="imagenDni" role="button" tabindex="0" class="btn2 cursor-pointer border-amber-300 bg-white text-amber-950 hover:bg-amber-100">
                    <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 4h-5L8 6H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-3l-1.5-2Z"></path><circle cx="12" cy="13" r="3"></circle></svg>
                    <span>Subir o tomar foto</span>
                  </label>
                  <input id="imagenDni" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" capture="environment">
                  <span class="text-[10px] text-amber-800">JPG, PNG o WebP · máximo 10 MB</span>
                </div>
                <div id="progresoOcrDni" class="mt-3 hidden" aria-live="polite">
                  <div class="h-1.5 overflow-hidden rounded-full bg-amber-200"><div id="barraOcrDni" class="h-full bg-vino-700 transition-[width] duration-300" style="width:0%"></div></div>
                  <p id="estadoOcrDni" class="mt-1 text-[10px] leading-4 text-amber-900"></p>
                </div>
              </div>
              <img id="vistaDni" alt="Vista previa del DNI seleccionado" class="hidden h-24 w-40 shrink-0 border border-amber-200 bg-white object-contain">
            </div>
          </div>
        </div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 p-3">
          <button id="btnContinuarSeleccion" type="button" class="btn">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
            <span>Continuar</span>
          </button>
        </div>
      </section>

      <!-- CONCEPTO Y TASA -->
      <section class="card" data-step-panel="2" hidden>
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
      <section id="grupoEscuela" class="card" data-step-panel="2" hidden>
        <div class="head">3. Escuela profesional</div>
        <div class="p-2.5 sm:p-3">
          <label class="label">Escuela profesional a la que postula *</label>
          <select id="escuela" class="select">
            <option value="">Seleccione una escuela profesional</option>
            <option value="Ingeniería y Gestión Ambiental">Ingeniería y Gestión Ambiental</option>
            <option value="Ingeniería de Negocios Agronómicos y Forestales">Ingeniería de Negocios Agronómicos y Forestales</option>
            <option value="Administración de Turismo Sostenible y Hotelería">Administración de Turismo Sostenible y Hotelería</option>
            <option value="Ingeniería Civil">Ingeniería Civil</option>
            <option value="Ingeniería de Sistemas">Ingeniería de Sistemas</option>
          </select>
        </div>
      </section>

      <section class="card" data-step-panel="2" hidden>
        <div class="flex flex-wrap justify-between gap-2 bg-slate-50 p-3">
          <button id="btnVolverIdentificacion" type="button" class="btn2">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="m11 18-6-6 6-6"></path></svg>
            <span>Volver</span>
          </button>
          <button id="btnContinuarPago" type="button" class="btn">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"></path><path d="m13 6 6 6-6 6"></path></svg>
            <span>Continuar</span>
          </button>
        </div>
      </section>

      <!-- PAGO -->
      <section class="card" data-step-panel="3" hidden>
        <div class="head">3. Seleccione el método de pago</div>
        <div class="space-y-2 p-2.5 sm:p-3">
          <label data-payment-option="esquela" class="payment-option flex cursor-pointer items-center gap-3 border-2 border-vino-700 bg-vino-50 p-3">
            <input type="radio" name="metodoPago" value="esquela" class="h-4 w-4 accent-vino-700" checked>
            <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-vino-100 text-vino-800">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l3 3v17H6z"></path><path d="M14 2v4h4M9 11h6M9 15h6"></path></svg>
            </span>
            <span class="min-w-0 flex-1">
              <b class="block text-sm text-slate-900">Generar esquela de pago</b>
              <small class="text-[11px] leading-4 text-slate-500">Recíbala por correo, visualícela y descárguela en PDF.</small>
            </span>
          </label>

          <label data-payment-option="tarjeta" class="payment-option flex cursor-pointer items-center gap-3 border-2 border-slate-200 bg-white p-3 hover:border-vino-200">
            <input type="radio" name="metodoPago" value="tarjeta" class="h-4 w-4 accent-vino-700">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-slate-100 text-slate-700">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20M6 15h3"></path></svg>
            </span>
            <span class="min-w-0 flex-1">
              <b class="block text-sm text-slate-900">Tarjeta de crédito o débito</b>
              <small class="text-[11px] leading-4 text-slate-500">Visa, Mastercard y otras tarjetas aceptadas por la pasarela.</small>
            </span>
            <span class="hidden gap-1 text-[10px] font-black sm:flex"><i class="not-italic text-blue-700">VISA</i><i class="not-italic text-orange-600">●●</i></span>
          </label>

          <label data-payment-option="yape" class="payment-option flex cursor-pointer items-center gap-3 border-2 border-slate-200 bg-white p-3 hover:border-vino-200">
            <input type="radio" name="metodoPago" value="yape" class="h-4 w-4 accent-vino-700">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-purple-100 text-purple-700">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v6h-6v-2"></path></svg>
            </span>
            <span class="min-w-0 flex-1">
              <b class="block text-sm text-slate-900">Pagar con Yape</b>
              <small class="text-[11px] leading-4 text-slate-500">Pague escaneando el QR o solicite la aprobación con su celular.</small>
            </span>
            <span class="rounded bg-purple-600 px-2 py-1 text-[10px] font-black text-white">YAPE</span>
          </label>
        </div>

        <div id="panelEsquela" class="border-t border-slate-200 bg-slate-50 p-3">
          <div class="grid gap-2 sm:grid-cols-3">
            <div>
              <label class="label">Fecha de emisión</label>
              <input id="fechaEmision" class="input bg-white" readonly>
            </div>
            <div>
              <label class="label">Fecha de vencimiento *</label>
              <input id="fechaVencimiento" type="date" class="input bg-slate-100 text-slate-700" readonly disabled aria-readonly="true" tabindex="-1">
            </div>
            <div>
              <label class="label">Correo destino</label>
              <input id="correoDestino" class="input bg-white" readonly placeholder="Correo registrado">
            </div>
          </div>
          <div class="mt-3 flex flex-wrap gap-2">
            <button id="btnGenerar" type="button" class="btn min-h-11 bg-emerald-600 px-5 text-sm shadow-md shadow-emerald-900/20 ring-2 ring-emerald-100 hover:bg-emerald-700 sm:text-sm">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M6 2h9l3 3v17H6z"></path><path d="M14 2v4h4"></path><path d="m9 15 2 2 4-5"></path></svg>
              <span>Generar</span>
            </button>
          </div>
        </div>

        <div id="panelTarjeta" class="hidden border-t border-slate-200 bg-slate-50 p-3">
          <div class="flex flex-wrap items-center justify-between gap-3 border border-blue-100 bg-white p-3">
            <div>
              <p class="text-sm font-black text-slate-900">Tarjeta de crédito o débito</p>
              <p class="mt-1 text-xs leading-5 text-slate-600">Se abrirá la pasarela Visa para completar los datos de prueba.</p>
            </div>
            <button id="btnAbrirTarjetaModal" type="button" class="btn">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
              <span>Abrir</span>
            </button>
          </div>
        </div>

        <div id="panelYape" class="hidden border-t border-slate-200 bg-slate-50 p-3">
          <div class="grid gap-2 sm:grid-cols-2">
            <button id="btnAbrirYapeQr" type="button" class="flex items-center gap-3 border-2 border-purple-600 bg-purple-50 p-3 text-left hover:bg-purple-100">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-purple-700 text-white">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v6h-6v-2"></path></svg>
              </span>
              <span><b class="block text-sm text-slate-900">Pagar con QR</b><small class="text-[11px] leading-4 text-slate-600">Escanee el código desde la aplicación Yape.</small></span>
            </button>
            <button id="btnAbrirYapeModal" type="button" class="flex items-center gap-3 border border-purple-100 bg-white p-3 text-left hover:border-purple-300 hover:bg-purple-50">
              <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-purple-100 text-purple-700">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg>
              </span>
              <span><b class="block text-sm text-slate-900">Solicitar con celular</b><small class="text-[11px] leading-4 text-slate-600">Ingrese su número y el código de aprobación.</small></span>
            </button>
          </div>
        </div>

        <div class="flex justify-start border-t border-slate-200 bg-white p-3">
          <button id="btnVolverSeleccion" type="button" class="btn2">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="m11 18-6-6 6-6"></path></svg>
            <span>Volver</span>
          </button>
        </div>
      </section>

      <!-- CONFIRMACIÓN -->
      <section class="card" data-step-panel="4" hidden>
        <div class="head">4. Confirmación</div>
        <div class="p-3">
          <div id="confirmacionEstado" class="border-l-4 border-emerald-600 bg-emerald-50 p-3">
            <p id="confirmacionTitulo" class="text-sm font-black text-emerald-800">Operación completada</p>
            <p id="confirmacionMensaje" class="mt-1 text-xs leading-5 text-slate-700"></p>
          </div>

          <div id="visorEsquela" class="mt-3 hidden">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <p class="text-xs font-bold text-vino-900">Vista previa de la esquela generada</p>
              <div class="flex flex-wrap gap-2">
                <button id="btnCompartirEsquela" type="button" class="btn2" disabled>
                  <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"></path><path d="M16 6 12 2 8 6"></path><path d="M12 2v13"></path></svg>
                  <span>Compartir</span>
                </button>
                <button id="btnImprimir" type="button" class="btn2" disabled>
                  <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 21h14"></path></svg>
                  <span>Descargar</span>
                </button>
              </div>
            </div>
            <iframe id="pdfEsquela" title="Esquela de pago generada" class="h-[70vh] min-h-[520px] w-full border border-slate-300 bg-slate-100"></iframe>
          </div>

          <div id="reciboSimulado" class="mt-3 hidden">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
              <p class="text-xs font-bold text-vino-900">Boleta de pago </p>
              <button id="btnImprimirRecibo" type="button" class="btn2">
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>
                <span>Imprimir</span>
              </button>
            </div>
            <div class="relative mx-auto max-w-2xl overflow-hidden border border-slate-300 bg-white p-5 shadow-sm">
              <div class="pointer-events-none absolute inset-0 flex rotate-[-24deg] items-center justify-center text-5xl font-black text-red-100"></div>
              <div class="relative">
                <div class="flex items-center justify-between gap-4 border-b-2 border-vino-800 pb-3">
                  <img src="assets/logo-unah.png?v=20260827" alt="UNAH" class="h-12 w-auto">
                  <div class="text-right">
                    <p class="text-sm font-black text-vino-900">BOLETA DE PAGO</p>
                    <p class="text-[10px] font-black text-red-700">UNAH</p>
                  </div>
                </div>
                <div class="mt-4 grid gap-x-5 gap-y-3 text-xs sm:grid-cols-2">
                  <div><span class="text-[10px] text-slate-500">Operación</span><p data-receipt-field="operation" class="font-black"></p></div>
                  <div><span class="text-[10px] text-slate-500">Fecha y hora</span><p data-receipt-field="date" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">Postulante</span><p data-receipt-field="name" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">DNI</span><p data-receipt-field="dni" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">Concepto</span><p data-receipt-field="concept" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">Escuela profesional</span><p data-receipt-field="school" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">Método</span><p data-receipt-field="method" class="font-bold"></p></div>
                  <div><span class="text-[10px] text-slate-500">Estado</span><p class="font-black text-emerald-700">APROBADO </p></div>
                </div>
                <div class="mt-4 border-2 border-vino-800 bg-vino-50 p-3 text-center">
                  <p class="text-[10px] font-bold text-vino-800">MONTO</p>
                  <p data-receipt-field="amount" class="text-3xl font-black text-vino-900"></p>
                </div>
                <p class="mt-4 border-t border-slate-200 pt-3 text-center text-[9px] leading-4 text-red-700">Documento generado exclusivamente para demostración del sistema. No acredita un pago real y no tiene valor contable.</p>
              </div>
            </div>
          </div>

          <div class="mt-3 flex flex-wrap justify-between gap-2">
            <button id="btnVolverPago" type="button" class="btn2">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5"></path><path d="m11 18-6-6 6-6"></path></svg>
              <span>Volver</span>
            </button>
            <button id="btnNuevaOperacion" type="button" class="btn">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-2.64-6.36"></path><path d="M21 3v6h-6"></path></svg>
              <span>Reiniciar</span>
            </button>
          </div>
        </div>
      </section>

      <!-- TASAS MÓVIL -->
      <section class="card sm:hidden" data-step-panel="2" hidden>
        <div class="head">Tasas de pago</div>
        <div class="p-2">
          <div class="mb-2 flex gap-1 overflow-x-auto pb-1 text-[10px]">
            <button type="button" class="rate-tab inline-flex shrink-0 items-center gap-1 border border-vino-700 bg-vino-700 px-2 py-1 font-bold text-white" data-group="ordinario">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16"></path><path d="M4 12h16"></path><path d="M4 17h16"></path></svg>
              <span>Ordinario</span>
            </button>
            <button type="button" class="rate-tab inline-flex shrink-0 items-center gap-1 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700" data-group="extraordinario">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"></path></svg>
              <span>Extraordinario</span>
            </button>
            <button type="button" class="rate-tab inline-flex shrink-0 items-center gap-1 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700" data-group="otros">
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle></svg>
              <span>Otros</span>
            </button>
          </div>
          <div id="mobileRates" class="space-y-1.5"></div>
        </div>
      </section>

      <!-- TABLA DE TASAS -->
      <details class="card hidden sm:block" data-step-panel="2" hidden>
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
    <aside class="space-y-2.5 lg:sticky lg:top-3 lg:self-start">
      <div id="contenedorResumen" hidden>
        <section class="card hidden lg:block">
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
      </div>

      <section id="estadoEnvio" class="card hidden">
        <div id="estadoEnvioDetalle" class="border-l-4 border-emerald-600 p-3">
          <p id="tituloEnvio" class="text-xs font-bold text-emerald-700">Esquela generada</p>
          <p id="msgCorreo" class="mt-1 text-[11px] leading-4 text-slate-600"></p>
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

<!-- PASARELA TARJETA SIMULADA -->
<div id="modalTarjeta" class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-950/55 p-3" aria-modal="true" role="dialog" aria-labelledby="modalTarjetaTitulo">
  <div class="relative w-full max-w-[448px] border border-white/60 bg-white p-3 shadow-2xl sm:p-6">
    <div class="absolute right-3 top-3 flex items-center gap-2">
      <div class="flex rounded-lg bg-slate-100 p-1 text-[11px] font-black">
        <span class="px-2 py-1 text-slate-500">EN</span>
        <span class="rounded-md bg-white px-2 py-1 text-blue-700 shadow-sm">ES</span>
      </div>
      <button id="btnCerrarTarjetaModal" type="button" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-600 text-white hover:bg-slate-800" aria-label="Cerrar pasarela de tarjeta">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M6 6l12 12M18 6 6 18"></path></svg>
      </button>
    </div>

    <div class="grid gap-4 pt-10 sm:grid-cols-[52px_1fr] sm:pt-8">
      <aside class="hidden border-r border-slate-200 pr-3 sm:flex sm:flex-col sm:items-center sm:gap-5">
        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-700">
          <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><path d="M2 10h20"></path></svg>
        </span>
        <span class="text-center text-[10px] font-black leading-3 text-purple-700">yape</span>
        <span class="space-y-1 text-slate-950" aria-hidden="true"><i class="block h-1 w-7 bg-current"></i><i class="block h-1 w-7 bg-current"></i><i class="block h-1 w-7 bg-current"></i></span>
      </aside>

      <section>
        <h2 id="modalTarjetaTitulo" class="sr-only">Pasarela de tarjeta</h2>
        <div class="mx-auto max-w-[282px] rounded-lg border border-slate-300 bg-slate-100 p-4 shadow-md">
          <div class="flex justify-end gap-1 text-sm font-black">
            <span class="text-blue-700">VISA</span><span class="text-red-600">●</span><span class="text-orange-500">●</span><span class="text-blue-500">◉</span><span class="border border-blue-600 px-1 text-[10px] leading-4 text-blue-700">AM</span>
          </div>
          <div class="mt-6 h-7 w-9 rounded-md border border-slate-300 bg-gradient-to-br from-white to-slate-200"></div>
          <div id="tarjetaVistaNumero" class="mt-4 truncate font-mono text-[10px] tracking-[0.2em] text-slate-950">0000 0000 0000 0000</div>
          <div class="mt-3 flex items-end justify-between gap-3">
            <div id="tarjetaVistaNombre" class="min-h-4 max-w-[170px] truncate font-mono text-[11px] uppercase text-slate-950">POSTULANTE</div>
            <div class="text-right font-mono text-[9px] uppercase leading-3 text-slate-700">
              <span>VENCE</span><br><b id="tarjetaVistaVence" class="text-[11px] text-slate-950">MM/AA</b>
            </div>
          </div>
        </div>

        <p class="mt-2 text-center text-[10px] font-semibold text-blue-600">Recuerde activar sus compras por internet</p>

        <div class="mt-3 grid gap-3">
          <p class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-[10px] leading-4 text-blue-800">Puede ingresar cualquier número de tarjeta de 13 a 19 dígitos. Esta operación continúa en modo simulación: no realiza cargos y no envía al servidor el número completo ni el CVV.</p>
          <input id="numeroTarjeta" class="input rounded-lg py-3 text-sm" inputmode="numeric" autocomplete="cc-number" maxlength="23" aria-label="Número de tarjeta" placeholder="Número de Tarjeta">
          <div class="grid grid-cols-2 gap-2">
            <input id="vencimientoTarjeta" class="input rounded-lg py-3 text-sm" inputmode="numeric" autocomplete="cc-exp" maxlength="5" aria-label="Fecha de vencimiento de la tarjeta" placeholder="MM/AA">
            <div class="relative">
              <input id="cvvTarjeta" type="text" class="input rounded-lg py-3 pr-10 text-sm" inputmode="numeric" autocomplete="cc-csc" maxlength="4" aria-label="Código de seguridad de la tarjeta" placeholder="CVV">
              <span class="absolute right-3 top-1/2 flex h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full border border-slate-400 text-xs font-black text-slate-500">?</span>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-2">
            <label class="block"><span class="ml-3 text-[9px] text-slate-500">Nombre</span><input id="nombreTarjeta" class="input rounded-lg py-2 text-sm uppercase" autocomplete="cc-given-name" spellcheck="false" placeholder="NOMBRE"></label>
            <label class="block"><span class="ml-3 text-[9px] text-slate-500">Apellido</span><input id="apellidoTarjeta" class="input rounded-lg py-2 text-sm uppercase" autocomplete="cc-family-name" spellcheck="false" placeholder="APELLIDO"></label>
          </div>
          <label class="block"><span class="ml-3 text-[9px] text-slate-500">Correo electrónico</span><input id="correoTarjeta" class="input rounded-lg py-2 text-sm" inputmode="email" autocomplete="off" readonly></label>
          <p id="errorTarjeta" class="hidden text-[10px] font-semibold leading-4 text-red-700" role="alert"></p>
          <div class="border-t border-slate-200 pt-2 text-center text-[10px] text-slate-400">Powered by <b class="text-emerald-700">Pay-me</b></div>
          <div class="flex items-center justify-between text-base font-black">
            <span>Pago total</span><span id="montoTarjeta">S/ 0.00</span>
          </div>
          <button id="btnPagarTarjeta" type="button" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-blue-300 px-4 py-3 text-sm font-black text-white hover:bg-blue-500">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
            <span>Pagar</span>
          </button>
          <button id="btnDatosPrueba" type="button" class="inline-flex items-center justify-center gap-1.5 text-center text-[10px] font-bold text-blue-600">
            <svg aria-hidden="true" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            <span>Usar datos de prueba (opcional)</span>
          </button>
          <p class="text-[10px] text-slate-500">Nro. Operación. 1904080</p>
        </div>
      </section>
    </div>
  </div>
</div>

<!-- SOLICITUD YAPE SIMULADA -->
<div id="modalYape" class="fixed inset-0 z-[90] hidden items-center justify-center bg-slate-950/55 p-3" aria-modal="true" role="dialog" aria-labelledby="modalYapeTitulo">
  <div class="relative max-h-[94vh] w-full max-w-[448px] overflow-y-auto bg-white p-5 shadow-2xl sm:p-7">
    <button id="btnCerrarYapeModal" type="button" class="absolute right-3 top-3 flex h-8 w-8 items-center justify-center text-slate-700 hover:text-slate-950" aria-label="Cerrar solicitud Yape">
      <svg aria-hidden="true" viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M6 6l12 12M18 6 6 18"></path></svg>
    </button>
    <div class="text-center">
      <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-lg bg-purple-700 text-xl font-black text-white">yape</div>
      <h2 id="modalYapeTitulo" class="mt-4 text-sm font-bold text-slate-900">Elija cómo desea pagar con Yape</h2>
    </div>

    <div class="mt-4 grid grid-cols-2 rounded-lg bg-slate-100 p-1 text-xs font-bold">
      <button id="tabYapeQr" type="button" class="rounded-md bg-purple-700 px-3 py-2 text-white shadow-sm">Escanear QR</button>
      <button id="tabYapeCelular" type="button" class="rounded-md px-3 py-2 text-slate-600 hover:bg-white">Usar celular</button>
    </div>

    <div id="yapeModoQr" class="mt-4">
      <p class="text-center text-xs leading-5 text-slate-600">Abra Yape, elija <b>Escanear QR</b>, verifique el titular y realice el pago por el monto indicado.</p>
      <div class="mt-3 flex justify-center">
        <img id="imagenQrYape" alt="Código QR para pagar con Yape" class="hidden max-h-[360px] w-full max-w-[280px] border border-purple-100 bg-purple-700 object-contain shadow-sm">
        <div id="qrYapeNoDisponible" class="flex min-h-56 w-full max-w-[280px] flex-col items-center justify-center border-2 border-dashed border-purple-200 bg-purple-50 p-5 text-center text-purple-800">
          <svg aria-hidden="true" viewBox="0 0 24 24" class="h-16 w-16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2v6h-6v-2"></path></svg>
          <p class="mt-3 text-xs font-bold">QR de Yape pendiente de configurar</p>
          <p class="mt-1 text-[10px] leading-4">Agregue la imagen original para habilitar esta forma de pago.</p>
        </div>
      </div>
      <div class="mt-3 rounded-lg border border-purple-100 bg-purple-50 p-3 text-center">
        <p class="text-[10px] uppercase tracking-wide text-purple-700">Titular</p>
        <p id="titularYapeQr" class="mt-0.5 text-sm font-black text-slate-900">Titular por configurar</p>
        <div class="mt-2 flex items-center justify-between border-t border-purple-200 pt-2 text-sm font-black">
          <span>Pago total</span><span id="montoYapeQr">S/ 0.00</span>
        </div>
      </div>
      <p id="estadoQrYape" class="mt-2 hidden text-center text-[10px] font-semibold leading-4 text-red-700" role="alert"></p>
      <button id="btnConfirmarYapeQr" type="button" class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded bg-purple-600 px-4 py-3 text-sm font-black text-white hover:bg-purple-700 disabled:cursor-not-allowed disabled:bg-slate-300">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
        <span>Ya pagué con Yape</span>
      </button>
    </div>

    <div id="yapeModoCelular" class="mt-5 hidden">
      <div id="yapePasoCelular">
        <label class="mb-2 block text-sm font-semibold text-slate-900" for="yapeCelular">Ingresa tu número de celular</label>
        <div class="flex items-center rounded-full border border-pink-300 px-4 py-3 focus-within:border-pink-500">
          <span class="mr-3 text-slate-500">+51</span>
          <input id="yapeCelular" class="w-full border-0 bg-transparent text-sm outline-none" inputmode="numeric" autocomplete="tel" maxlength="11" placeholder="987 654 321">
        </div>
        <p id="errorYape" class="mt-2 hidden text-[10px] font-semibold leading-4 text-red-700" role="alert"></p>
        <p class="mt-4 text-sm leading-6 text-slate-900">Presiona el botón, ingresa a tu Yape y verifica la aprobación pendiente en <b>"Aprobar compras"</b>.</p>
        <div class="mt-4 flex items-center justify-between text-sm font-black">
          <span>Pago total</span><span id="montoYape">S/ 0.00</span>
        </div>
        <button id="btnConfirmarYape" type="button" class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded bg-purple-400 px-4 py-3 text-sm font-black text-white hover:bg-purple-600">
          <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13"></path><path d="m22 2-7 20-4-9-9-4Z"></path></svg>
          <span>Solicitar</span>
        </button>
      </div>
      <div id="yapePasoCodigo" class="hidden">
        <p class="text-sm leading-6 text-slate-900">Solicitud enviada al celular <b id="yapeCelularResumen">+51 987 654 321</b>.</p>
        <label class="mt-4 mb-2 block text-sm font-semibold text-slate-900" for="yapeCodigoAprobacion">Ingresa código de aprobación de 6 dígitos</label>
        <input id="yapeCodigoAprobacion" class="w-full rounded-full border border-pink-300 px-4 py-3 text-center font-mono text-lg tracking-[0.35em] outline-none focus:border-pink-500" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
        <p id="errorYapeCodigo" class="mt-2 hidden text-[10px] font-semibold leading-4 text-red-700" role="alert"></p>
        <div class="mt-4 flex items-center justify-between text-sm font-black">
          <span>Pago total</span><span id="montoYapeCodigo">S/ 0.00</span>
        </div>
        <button id="btnAprobarYape" type="button" class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded bg-purple-600 px-4 py-3 text-sm font-black text-white hover:bg-purple-700">
          <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
          <span>Aprobar</span>
        </button>
        <button id="btnCambiarYapeCelular" type="button" class="mt-3 inline-flex w-full items-center justify-center gap-1.5 text-center text-xs font-bold text-purple-700">
          <svg aria-hidden="true" viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18"></path><path d="m8 7-5 5 5 5"></path></svg>
          <span>Cambiar</span>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ESTADO DE PAGO SIMULADO -->
<div id="pagoOverlay" class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/85 p-4 text-white" role="status" aria-live="assertive" aria-modal="true">
  <div class="w-full max-w-sm text-center">
    <div id="pagoProcesando">
      <div id="overlayTarjetaIcon" class="mx-auto flex h-24 w-24 items-center justify-center rounded-full border border-white/20 bg-white/10">
        <span class="payment-spinner h-14 w-14 rounded-full border-4 border-white/20 border-t-pink-400"></span>
      </div>
      <div id="overlayQrIcon" class="qr-pulse relative mx-auto hidden h-28 w-28 overflow-hidden border-4 border-purple-400 bg-white p-3 text-slate-950">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-full w-full" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM19 14h2v7h-7v-2"></path></svg>
        <span class="qr-scan-line absolute inset-x-1 top-1 h-1 bg-emerald-400 shadow-[0_0_12px_4px_rgba(52,211,153,.8)]"></span>
      </div>
      <p id="pagoOverlayTitulo" class="mt-6 text-xl font-black">Procesando pago</p>
      <p id="pagoOverlayMensaje" class="mt-2 text-sm text-slate-300">Espere un momento…</p>
    </div>
    <div id="pagoAprobado" class="hidden">
      <div class="payment-check mx-auto flex h-32 w-32 items-center justify-center rounded-full bg-emerald-500 shadow-[0_0_45px_rgba(16,185,129,.55)]">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-20 w-20" fill="none" stroke="white" stroke-width="2.8"><path d="m5 12 4 4L19 6"></path></svg>
      </div>
      <p class="mt-6 text-2xl font-black text-emerald-300">¡PAGO APROBADO!</p>
      <p class="mt-2 text-sm text-white">Completada correctamente</p>
    </div>
  </div>
</div>

<!-- IMPRESIÓN -->
<div id="printArea" class="hidden">
  <div style="max-width:720px;margin:auto;padding:26px;font-family:Arial,sans-serif;color:#222;">
    <div style="display:flex;align-items:center;gap:18px;border-bottom:3px solid #531d31;padding-bottom:10px;">
      <img src="assets/logo-unah.png?v=20260827" alt="UNAH" style="width:210px;height:auto;">
      <div>
        <h2 style="margin:0;font-size:18px;color:#531d31;">ORDEN DE PAGO ADMISIÓN 2026-II</h2>
        <div style="margin-top:4px;font-size:11px;">Dirección de Admisión</div>
      </div>
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

<!-- RECIBO SIMULADO PARA IMPRESIÓN -->
<div id="reciboPrintArea" class="hidden" style="font-family:Arial,sans-serif;color:#1f2937;">
  <div style="position:relative;max-width:680px;margin:0 auto;padding:28px;border:1px solid #cbd5e1;overflow:hidden;">
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;transform:rotate(-24deg);font-size:58px;font-weight:900;color:#fee2e2;"></div>
    <div style="position:relative;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:20px;border-bottom:3px solid #531d31;padding-bottom:12px;">
        <img src="assets/logo-unah.png?v=20260827" alt="UNAH" style="width:230px;height:auto;">
        <div style="text-align:right;"><div style="font-size:17px;font-weight:900;color:#531d31;">BOLETA DE PAGO</div><div style="font-size:10px;font-weight:900;color:#b91c1c;">UNAH</div></div>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:12px;">
        <tr><td style="width:25%;padding:7px;font-weight:bold;">Operación:</td><td data-receipt-field="operation" style="width:25%;padding:7px;"></td><td style="width:20%;padding:7px;font-weight:bold;">Fecha:</td><td data-receipt-field="date" style="padding:7px;"></td></tr>
        <tr><td style="padding:7px;font-weight:bold;">Postulante:</td><td data-receipt-field="name" colspan="3" style="padding:7px;"></td></tr>
        <tr><td style="padding:7px;font-weight:bold;">DNI:</td><td data-receipt-field="dni" style="padding:7px;"></td><td style="padding:7px;font-weight:bold;">Estado:</td><td style="padding:7px;font-weight:900;color:#047857;">APROBADO </td></tr>
        <tr><td style="padding:7px;font-weight:bold;">Concepto:</td><td data-receipt-field="concept" colspan="3" style="padding:7px;"></td></tr>
        <tr><td style="padding:7px;font-weight:bold;">Escuela:</td><td data-receipt-field="school" colspan="3" style="padding:7px;"></td></tr>
        <tr><td style="padding:7px;font-weight:bold;">Método:</td><td data-receipt-field="method" colspan="3" style="padding:7px;"></td></tr>
      </table>
      <div style="margin-top:18px;border:2px solid #531d31;background:#fbf5f7;padding:14px;text-align:center;"><div style="font-size:10px;font-weight:bold;color:#631f37;">MONTO </div><div data-receipt-field="amount" style="font-size:30px;font-weight:900;color:#531d31;"></div></div>
      <p style="margin-top:20px;border-top:1px solid #cbd5e1;padding-top:12px;text-align:center;font-size:9px;line-height:1.5;color:#b91c1c;">Documento generado exclusivamente para demostración del sistema. No acredita un pago real y no tiene valor contable.</p>
    </div>
  </div>
</div>

<script>
  const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
  const PAYMENT_CONFIG = <?= json_encode($publicPaymentConfiguration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let generando = false;
  let pdfUrl = "";
  let esquelaCompartir = null;
  let buscandoDni = false;
  let procesandoOcrDni = false;
  let urlVistaDni = "";
  let dniConsultado = "";
  let pasoActual = 1;
  let pasoMaximo = 1;
  let pagandoSimulado = false;
  const MASCARAS = {};

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

  function iniciarMascaras() {
    if (typeof window.IMask !== "function") return;

    MASCARAS.dni = window.IMask($("dni"), { mask: "00000000" });
    MASCARAS.celular = window.IMask($("celular"), { mask: "000 000 000" });
    MASCARAS.correo = window.IMask($("correo"), { mask: /^[^\s@]*(@[^\s@]*)?$/ });
    MASCARAS.nombreTarjeta = window.IMask($("nombreTarjeta"), {
      mask: /^[A-ZÁÉÍÓÚÜÑ .'-]*$/,
      prepare: value => value.toUpperCase()
    });
    MASCARAS.apellidoTarjeta = window.IMask($("apellidoTarjeta"), {
      mask: /^[A-ZÁÉÍÓÚÜÑ .'-]*$/,
      prepare: value => value.toUpperCase()
    });
    MASCARAS.numeroTarjeta = window.IMask($("numeroTarjeta"), { mask: "0000 0000 0000 0000 000" });
    MASCARAS.vencimientoTarjeta = window.IMask($("vencimientoTarjeta"), {
      mask: "MM/YY",
      lazy: true,
      autofix: true,
      blocks: {
        MM: { mask: window.IMask.MaskedRange, from: 1, to: 12, maxLength: 2 },
        YY: { mask: window.IMask.MaskedRange, from: 0, to: 99, maxLength: 2 }
      }
    });
    MASCARAS.cvvTarjeta = window.IMask($("cvvTarjeta"), {
      mask: "CCC[C]",
      definitions: {
        C: { mask: "0", displayChar: "•" }
      }
    });
    MASCARAS.yapeCelular = window.IMask($("yapeCelular"), { mask: "000 000 000" });
    MASCARAS.yapeCodigoAprobacion = window.IMask($("yapeCodigoAprobacion"), { mask: "000000" });
  }

  function valorSinMascara(id) {
    if (MASCARAS[id]) return MASCARAS[id].unmaskedValue;
    return $(id).value.replace(/\D/g, "");
  }

  function asignarValorMascara(id, valor) {
    if (MASCARAS[id]) {
      MASCARAS[id].value = valor;
      return;
    }
    $(id).value = valor;
  }

  function mostrarErrorCampo(inputId, errorId, mensaje) {
    const input = $(inputId);
    const error = $(errorId);
    input.setAttribute("aria-invalid", mensaje ? "true" : "false");
    input.classList.toggle("border-red-500", Boolean(mensaje));
    input.classList.toggle("focus:border-red-600", Boolean(mensaje));
    error.textContent = mensaje;
    error.classList.toggle("hidden", !mensaje);
  }

  function correoValido() {
    const valor = $("correo").value.trim();
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i.test(valor);
  }

  function validarCorreoVisual() {
    const mensaje = correoValido() ? "" : "Ingrese un correo válido, por ejemplo usuario@dominio.com.";
    mostrarErrorCampo("correo", "errorCorreo", mensaje);
    return !mensaje;
  }

  function validarCelularVisual() {
    const mensaje = /^9\d{8}$/.test(valorSinMascara("celular"))
      ? ""
      : "Ingrese un celular peruano de 9 dígitos que comience con 9.";
    mostrarErrorCampo("celular", "errorCelular", mensaje);
    return !mensaje;
  }

  function mostrarErrorTarjeta(mensaje, campo = "") {
    const ids = ["nombreTarjeta", "apellidoTarjeta", "numeroTarjeta", "vencimientoTarjeta", "cvvTarjeta"];
    ids.forEach(id => {
      $(id).classList.toggle("border-red-500", id === campo && Boolean(mensaje));
      $(id).setAttribute("aria-invalid", id === campo && mensaje ? "true" : "false");
    });
    $("errorTarjeta").textContent = mensaje;
    $("errorTarjeta").classList.toggle("hidden", !mensaje);
  }

  function mostrarErrorYape(mensaje) {
    $("yapeCelular").setAttribute("aria-invalid", mensaje ? "true" : "false");
    $("errorYape").textContent = mensaje;
    $("errorYape").classList.toggle("hidden", !mensaje);
  }

  function mostrarErrorYapeCodigo(mensaje) {
    $("yapeCodigoAprobacion").setAttribute("aria-invalid", mensaje ? "true" : "false");
    $("errorYapeCodigo").textContent = mensaje;
    $("errorYapeCodigo").classList.toggle("hidden", !mensaje);
  }

  function metodoPagoSeleccionado() {
    return document.querySelector('input[name="metodoPago"]:checked')?.value || "esquela";
  }

  function actualizarIndicadorPasos() {
    document.querySelectorAll("[data-step-button]").forEach(button => {
      const numero = Number(button.dataset.stepButton);
      const circle = button.querySelector(".step-circle");
      const label = button.querySelector(".step-label");
      const completado = numero < pasoActual;
      const activo = numero === pasoActual;

      button.disabled = numero > pasoMaximo;
      circle.textContent = completado ? "✓" : String(numero);
      circle.className = "step-circle flex h-8 w-8 items-center justify-center rounded-full text-xs font-black " +
        (activo || completado ? "bg-vino-700 text-white" : "bg-slate-200 text-slate-500");
      label.className = "step-label text-[10px] sm:text-xs " +
        (activo ? "font-bold text-vino-800" : completado ? "font-semibold text-vino-700" : "font-semibold text-slate-500");
    });
  }

  function mostrarPaso(numero) {
    if (numero < 1 || numero > pasoMaximo) return;
    pasoActual = numero;
    document.querySelectorAll("[data-step-panel]").forEach(panel => {
      panel.hidden = Number(panel.dataset.stepPanel) !== numero;
    });
    $("contenedorResumen").hidden = numero === 1;
    actualizarIndicadorPasos();
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function activarPaso(numero) {
    pasoMaximo = Math.max(pasoMaximo, numero);
    mostrarPaso(numero);
  }

  function actualizarMetodoPago() {
    const metodo = metodoPagoSeleccionado();
    $("panelEsquela").classList.toggle("hidden", metodo !== "esquela");
    $("panelTarjeta").classList.toggle("hidden", metodo !== "tarjeta");
    $("panelYape").classList.toggle("hidden", metodo !== "yape");

    document.querySelectorAll("[data-payment-option]").forEach(option => {
      const activo = option.dataset.paymentOption === metodo;
      option.className = "payment-option flex cursor-pointer items-center gap-3 border-2 p-3 " +
        (activo ? "border-vino-700 bg-vino-50" : "border-slate-200 bg-white hover:border-vino-200");
    });

    const calc = calcular();
    $("montoYape").textContent = money(calc.valor);
  }

  function abrirModal(id) {
    if (id === "modalTarjeta") {
      $("correoTarjeta").value = $("correo").value.trim();
      if (!$("nombreTarjeta").value.trim() && !$("apellidoTarjeta").value.trim()) {
        const partes = dividirNombrePostulante();
        asignarValorMascara("nombreTarjeta", partes.nombre);
        asignarValorMascara("apellidoTarjeta", partes.apellido);
      }
      actualizarVistaTarjeta();
    }
    if (id === "modalYape" && !valorSinMascara("yapeCelular")) {
      asignarValorMascara("yapeCelular", valorSinMascara("celular"));
    }
    if (id === "modalYape") {
      mostrarModoYape("qr");
      mostrarErrorYape("");
      mostrarErrorYapeCodigo("");
    }
    cerrarModales();
    $(id).classList.remove("hidden");
    $(id).classList.add("flex");
    document.body.classList.add("overflow-hidden");
  }

  function abrirModalYape(modo = "qr") {
    abrirModal("modalYape");
    mostrarModoYape(modo);
  }

  function cerrarModal(id) {
    $(id).classList.add("hidden");
    $(id).classList.remove("flex");
    if ($("modalTarjeta").classList.contains("hidden") && $("modalYape").classList.contains("hidden")) {
      document.body.classList.remove("overflow-hidden");
    }
  }

  function cerrarModales() {
    cerrarModal("modalTarjeta");
    cerrarModal("modalYape");
  }

  function dividirNombrePostulante() {
    const nombres = $("nombres").value.trim().split(/\s+/).filter(Boolean);
    return {
      nombre: nombres[0] || "",
      apellido: [$("apPaterno").value.trim(), $("apMaterno").value.trim()].filter(Boolean).join(" ")
    };
  }

  function actualizarVistaTarjeta() {
    $("tarjetaVistaNumero").textContent = $("numeroTarjeta").value.trim() || "0000 0000 0000 0000";
    $("tarjetaVistaVence").textContent = $("vencimientoTarjeta").value.trim() || "MM/AA";
    const titular = [$("nombreTarjeta").value.trim(), $("apellidoTarjeta").value.trim()].filter(Boolean).join(" ");
    $("tarjetaVistaNombre").textContent = titular || "POSTULANTE";
  }

  function configurarQrYape() {
    const configuracion = PAYMENT_CONFIG.yape || {};
    const urlQr = String(configuracion.qrImage || "").trim();
    const titular = String(configuracion.holder || "").trim();
    const imagen = $("imagenQrYape");
    const aviso = $("qrYapeNoDisponible");
    const boton = $("btnConfirmarYapeQr");

    $("titularYapeQr").textContent = titular || "Titular por configurar";
    boton.disabled = true;
    imagen.classList.add("hidden");
    aviso.classList.remove("hidden");

    if (!urlQr) return;
    imagen.onload = () => {
      imagen.classList.remove("hidden");
      aviso.classList.add("hidden");
      boton.disabled = false;
      $("estadoQrYape").classList.add("hidden");
    };
    imagen.onerror = () => {
      imagen.classList.add("hidden");
      aviso.classList.remove("hidden");
      boton.disabled = true;
      $("estadoQrYape").textContent = "No se pudo cargar la imagen QR configurada.";
      $("estadoQrYape").classList.remove("hidden");
    };
    imagen.src = urlQr;
  }

  function mostrarModoYape(modo) {
    const esQr = modo === "qr";
    $("yapeModoQr").classList.toggle("hidden", !esQr);
    $("yapeModoCelular").classList.toggle("hidden", esQr);
    $("tabYapeQr").className = "rounded-md px-3 py-2 " + (esQr ? "bg-purple-700 text-white shadow-sm" : "text-slate-600 hover:bg-white");
    $("tabYapeCelular").className = "rounded-md px-3 py-2 " + (!esQr ? "bg-purple-700 text-white shadow-sm" : "text-slate-600 hover:bg-white");
    if (!esQr) mostrarPasoYape("celular");
  }

  function mostrarPasoYape(paso) {
    const esCodigo = paso === "codigo";
    $("yapePasoCelular").classList.toggle("hidden", esCodigo);
    $("yapePasoCodigo").classList.toggle("hidden", !esCodigo);
    if (esCodigo) {
      const celular = valorSinMascara("yapeCelular");
      $("yapeCelularResumen").textContent = `+51 ${celular.slice(0, 3)} ${celular.slice(3, 6)} ${celular.slice(6)}`;
      $("montoYapeCodigo").textContent = $("montoYape").textContent;
      asignarValorMascara("yapeCodigoAprobacion", "");
      $("yapeCodigoAprobacion").focus();
    } else {
      $("yapeCelular").focus();
    }
  }

  function money(n) {
    return n == null ? "-" : `S/ ${Number(n).toFixed(2)}`;
  }

  function nombreCompleto() {
    return [$("apPaterno").value.trim(), $("apMaterno").value.trim(), $("nombres").value.trim()]
      .filter(Boolean).join(" ");
  }

  function esperar(ms) {
    return new Promise(resolve => window.setTimeout(resolve, ms));
  }

  function fechaIsoLocal(fecha) {
    return [
      fecha.getFullYear(),
      String(fecha.getMonth() + 1).padStart(2, "0"),
      String(fecha.getDate()).padStart(2, "0")
    ].join("-");
  }

  function fijarFechasPago() {
    const hoy = new Date();
    const vencimiento = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate() + 2);
    $("fechaEmision").value = hoy.toLocaleDateString("es-PE");
    $("fechaVencimiento").value = fechaIsoLocal(vencimiento);
    $("fechaVencimiento").min = fechaIsoLocal(vencimiento);
    $("fechaVencimiento").max = fechaIsoLocal(vencimiento);
  }

  function codigoOperacionSimulada() {
    const fecha = new Date();
    const parteFecha = [
      fecha.getFullYear(),
      String(fecha.getMonth() + 1).padStart(2, "0"),
      String(fecha.getDate()).padStart(2, "0")
    ].join("");
    let aleatorio = Math.floor(Math.random() * 0xffffff);
    if (window.crypto && window.crypto.getRandomValues) {
      const valores = new Uint32Array(1);
      window.crypto.getRandomValues(valores);
      aleatorio = valores[0];
    }
    return `SIM-${parteFecha}-${aleatorio.toString(16).toUpperCase().padStart(8, "0").slice(-8)}`;
  }

  function payloadPostulante() {
    fijarFechasPago();
    return {
      dni: valorSinMascara("dni"),
      apellido_paterno: $("apPaterno").value.trim(),
      apellido_materno: $("apMaterno").value.trim(),
      nombres_propios: $("nombres").value.trim(),
      correo: $("correo").value.trim(),
      celular: valorSinMascara("celular"),
      escuela: $("escuela").value.trim(),
      concepto_key: $("conceptoPago").value,
      modalidad_key: $("conceptoPago").value === "inscripcion" ? $("modalidad").value : null,
      procedencia: $("conceptoPago").value === "inscripcion" ? $("procedencia").value : null,
      periodo: $("conceptoPago").value === "inscripcion" ? $("periodo").value : null,
      fecha_vencimiento: $("fechaVencimiento").value
    };
  }

  function urlAbsoluta(url) {
    return new URL(url, window.location.href).href;
  }

  function mensajeCompartirEsquela() {
    if (!esquelaCompartir || !pdfUrl) return "";
    const lineas = [
      "Esquela de pago UNAH",
      `Código: ${esquelaCompartir.code}`,
      `Postulante: ${nombreCompleto() || "-"}`,
      `DNI: ${valorSinMascara("dni") || "-"}`,
      `Concepto: ${conceptoTexto() || "-"}`,
      `Monto: ${money(esquelaCompartir.amount)}`,
      `Enlace: ${urlAbsoluta(pdfUrl)}`,
      "El enlace es temporal. Verifique los datos antes de realizar el pago."
    ];
    return lineas.join("\n");
  }

  async function compartirEsquela() {
    const mensaje = mensajeCompartirEsquela();
    if (!mensaje) {
      alert("Primero genere la esquela.");
      return;
    }

    const url = urlAbsoluta(pdfUrl);
    if (navigator.share) {
      try {
        await navigator.share({
          title: "Esquela de pago UNAH",
          text: mensaje,
          url
        });
        return;
      } catch (error) {
        if (error && error.name === "AbortError") return;
      }
    }
    window.open(`https://wa.me/?text=${encodeURIComponent(mensaje)}`, "_blank", "noopener");
  }

  async function enviarReciboSimulado(metodo, detalles = {}) {
    const payload = {
      ...payloadPostulante(),
      payment_method: metodo,
      operation_code: detalles.operationCode || codigoOperacionSimulada(),
      card_brand: detalles.brand || "",
      card_last4: detalles.last4 || "",
      yape_phone: detalles.phone || "",
      yape_approval_code: detalles.approvalCode || ""
    };

    try {
      const response = await fetch("send-payment-receipt.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": CSRF_TOKEN
        },
        body: JSON.stringify(payload)
      });
      const text = await response.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch (_) {
        throw new Error("El servidor devolvió una respuesta no válida al enviar el recibo.");
      }
      if (!response.ok || !result.ok) {
        throw new Error(result.error || "No se pudo enviar el recibo.");
      }
      return result;
    } catch (error) {
      return {
        ok: false,
        mail_sent: false,
        operation_code: payload.operation_code,
        message: error.message
      };
    }
  }

  function escribirRecibo(campo, valor) {
    document.querySelectorAll(`[data-receipt-field="${campo}"]`).forEach(element => {
      element.textContent = valor;
    });
  }

  function detectarMarcaTarjeta(numero) {
    if (/^4/.test(numero)) return "Visa";
    if (/^(?:5[1-5]|2(?:2[2-9]|[3-6]\d|7[01]|720))/.test(numero)) return "Mastercard";
    if (/^3[47]/.test(numero)) return "American Express";
    if (/^6(?:011|5|4[4-9])/.test(numero)) return "Discover";
    if (/^3(?:0[0-5]|[68])/.test(numero)) return "Diners Club";
    if (/^62/.test(numero)) return "UnionPay";
    return "Tarjeta";
  }

  function validarTarjetaIngresada() {
    const nombre = $("nombreTarjeta").value.trim();
    const apellido = $("apellidoTarjeta").value.trim();
    const numero = valorSinMascara("numeroTarjeta");
    const vencimiento = $("vencimientoTarjeta").value.trim();
    const cvv = valorSinMascara("cvvTarjeta");

    if (nombre.length < 2) return { error: "Ingrese el nombre del titular de la tarjeta.", field: "nombreTarjeta" };
    if (apellido.length < 2) return { error: "Ingrese el apellido del titular de la tarjeta.", field: "apellidoTarjeta" };
    if (!/^\d{13,19}$/.test(numero)) return { error: "Ingrese un número de tarjeta de 13 a 19 dígitos.", field: "numeroTarjeta" };
    const partes = vencimiento.match(/^(0[1-9]|1[0-2])\/(\d{2})$/);
    if (!partes) return { error: "Ingrese el vencimiento con el formato MM/AA.", field: "vencimientoTarjeta" };
    const ahora = new Date();
    const mes = Number(partes[1]);
    const anio = 2000 + Number(partes[2]);
    if (anio < ahora.getFullYear() || (anio === ahora.getFullYear() && mes < ahora.getMonth() + 1)) {
      return { error: "La tarjeta está vencida. Ingrese una fecha de vencimiento futura.", field: "vencimientoTarjeta" };
    }
    if (!/^\d{3,4}$/.test(cvv)) return { error: "Ingrese un CVV de 3 o 4 dígitos.", field: "cvvTarjeta" };
    return { error: "", brand: detectarMarcaTarjeta(numero), last4: numero.slice(-4) };
  }

  function validarYapeSimulado() {
    const celular = valorSinMascara("yapeCelular");
    if (!/^9\d{8}$/.test(celular)) {
      return { error: "Ingrese un celular Yape válido de 9 dígitos que comience con 9." };
    }
    return { error: "", phone: celular };
  }

  function validarCodigoYapeSimulado() {
    const codigo = valorSinMascara("yapeCodigoAprobacion");
    if (!/^\d{6}$/.test(codigo)) {
      return { error: "Ingrese el código de aprobación de 6 dígitos." };
    }
    return { error: "", approvalCode: codigo };
  }

  function prepararReciboSimulado(metodo, detalles = {}) {
    const calc = calcular();
    const concepto = $("conceptoPago").value;
    const metodoTexto = metodo === "tarjeta"
      ? `${detalles.brand || "Tarjeta"} •••• ${detalles.last4 || "0000"}`
      : metodo === "yape_qr" ? "Yape / código QR" : "Yape / aprobación";

    escribirRecibo("operation", detalles.operationCode || codigoOperacionSimulada());
    escribirRecibo("date", new Date().toLocaleString("es-PE"));
    escribirRecibo("name", nombreCompleto());
    escribirRecibo("dni", valorSinMascara("dni"));
    escribirRecibo("concept", conceptoTexto());
    escribirRecibo("school", concepto === "constancia" ? "No aplica" : $("escuela").value.trim());
    escribirRecibo("method", metodoTexto);
    escribirRecibo("amount", money(calc.valor));

    $("visorEsquela").classList.add("hidden");
    $("pdfEsquela").removeAttribute("src");
    $("reciboSimulado").classList.remove("hidden");
    $("confirmacionEstado").className = "border-l-4 border-emerald-600 bg-emerald-50 p-3";
    $("confirmacionTitulo").className = "text-sm font-black text-emerald-800";
    $("confirmacionTitulo").textContent = "Pago aprobado (simulación)";
    $("confirmacionMensaje").textContent = detalles.receiptMailSent
      ? `La transaccion con ${metodoTexto} fue aprobada y el recibo fue enviado a ${$("correo").value.trim()}. Puede imprimir la boleta demostrativa.`
      : `La transaccion con ${metodoTexto} fue aprobada. Puede imprimir la boleta demostrativa; el correo del recibo quedó pendiente.`;
    $("estadoEnvio").classList.remove("hidden");
    $("estadoEnvioDetalle").className = "border-l-4 border-emerald-600 p-3";
    $("tituloEnvio").className = "text-xs font-bold text-emerald-700";
    if (detalles.receiptMailSent) {
      $("estadoEnvioDetalle").className = "border-l-4 border-emerald-600 p-3";
      $("tituloEnvio").className = "text-xs font-bold text-emerald-700";
      $("tituloEnvio").textContent = "Recibo enviado";
      $("msgCorreo").textContent = `El recibo ${detalles.operationCode || ""} por ${money(calc.valor)} fue enviado a ${$("correo").value.trim()}.`;
    } else {
      $("estadoEnvioDetalle").className = "border-l-4 border-amber-500 p-3";
      $("tituloEnvio").className = "text-xs font-bold text-amber-700";
      $("tituloEnvio").textContent = "Pago aprobado; correo pendiente";
      $("msgCorreo").textContent = detalles.receiptMessage || `Se generó una boleta de demostración por ${money(calc.valor)}, pero no se confirmó el envío por correo.`;
    }
    activarPaso(4);
  }

  async function procesarPagoSimulado(metodo, detalles = {}) {
    if (pagandoSimulado) return;
    pagandoSimulado = true;
    cerrarModales();
    $("btnPagarTarjeta").disabled = true;
    $("btnConfirmarYape").disabled = true;
    $("btnAprobarYape").disabled = true;
    $("btnConfirmarYapeQr").disabled = true;
    $("pagoProcesando").classList.remove("hidden");
    $("pagoAprobado").classList.add("hidden");
    const esQrYape = metodo === "yape_qr";
    $("overlayTarjetaIcon").classList.toggle("hidden", esQrYape);
    $("overlayQrIcon").classList.toggle("hidden", !esQrYape);
    $("pagoOverlayTitulo").textContent = esQrYape ? "Validando pago con QR" : metodo === "yape" ? "Validando aprobación Yape" : "Procesando tarjeta";
    $("pagoOverlayMensaje").textContent = esQrYape
      ? "Confirmando la operación realizada desde Yape..."
      : metodo === "yape" ? "Confirmando el código de aprobación ingresado..." : "Validando los datos de la operación simulada...";
    $("pagoOverlay").classList.remove("hidden");
    $("pagoOverlay").classList.add("flex");

    await esperar(1900);
    $("pagoProcesando").classList.add("hidden");
    $("pagoAprobado").classList.remove("hidden");
    await esperar(1200);

    const operationCode = codigoOperacionSimulada();
    $("pagoAprobado").classList.add("hidden");
    $("pagoProcesando").classList.remove("hidden");
    $("pagoOverlayTitulo").textContent = "Enviando recibo al correo";
    $("pagoOverlayMensaje").textContent = "Generando el PDF del recibo y adjuntándolo al correo registrado...";
    const receiptResult = await enviarReciboSimulado(metodo, { ...detalles, operationCode });
    detalles.operationCode = receiptResult.operation_code || operationCode;
    detalles.receiptMailSent = Boolean(receiptResult.mail_sent);
    detalles.receiptMessage = receiptResult.message || "";
    $("pagoProcesando").classList.add("hidden");
    $("pagoAprobado").classList.remove("hidden");
    await esperar(700);

    prepararReciboSimulado(metodo, detalles);
    $("pagoOverlay").classList.add("hidden");
    $("pagoOverlay").classList.remove("flex");
    asignarValorMascara("cvvTarjeta", "");
    pagandoSimulado = false;
    $("btnPagarTarjeta").disabled = false;
    $("btnConfirmarYape").disabled = false;
    $("btnAprobarYape").disabled = false;
    $("btnConfirmarYapeQr").disabled = !$("qrYapeNoDisponible").classList.contains("hidden");
  }

  function mostrarEstadoDni(mensaje, tipo = "info") {
    const estado = $("estadoDni");
    const colores = {
      info: "text-slate-500",
      success: "text-emerald-700",
      error: "text-red-700"
    };
    estado.textContent = mensaje;
    estado.className = `mt-1 text-[10px] leading-4 ${colores[tipo] || colores.info}`;
    estado.classList.toggle("hidden", !mensaje);
  }

  function seleccionarModoDatosDni(modo) {
    const usarFoto = modo === "foto";
    const opcionFoto = $("opcionFotoDni");
    const opcionManual = $("opcionManualDni");

    $("panelOcrDni").classList.toggle("hidden", !usarFoto);
    opcionFoto.setAttribute("aria-pressed", usarFoto ? "true" : "false");
    opcionManual.setAttribute("aria-pressed", usarFoto ? "false" : "true");
    opcionFoto.className = "w-full cursor-pointer border p-2 text-left transition focus:outline-none focus:ring-2 focus:ring-emerald-300 " +
      (usarFoto ? "border-emerald-600 bg-emerald-50 ring-1 ring-emerald-300" : "border-emerald-200 bg-white hover:border-emerald-500 hover:bg-emerald-50");
    opcionManual.className = "w-full cursor-pointer border p-2 text-left transition focus:outline-none focus:ring-2 focus:ring-sky-300 " +
      (!usarFoto ? "border-sky-600 bg-sky-50 ring-1 ring-sky-300" : "border-slate-200 bg-white hover:border-sky-400 hover:bg-sky-50");
    $("indicacionModoDatosDni").textContent = usarFoto
      ? "Opción recomendada seleccionada. En el bloque amarillo, haga clic en “Subir o tomar foto”."
      : "Ingreso manual seleccionado. Complete cuidadosamente los campos que aparecen debajo.";

    if (usarFoto) {
      requestAnimationFrame(() => $("panelOcrDni").scrollIntoView({ behavior: "smooth", block: "nearest" }));
    } else {
      $("dni").focus();
    }
  }

  function reiniciarPanelOcrDni() {
    $("progresoOcrDni").classList.add("hidden");
    $("barraOcrDni").style.width = "0%";
    $("estadoOcrDni").textContent = "";
    if (urlVistaDni) URL.revokeObjectURL(urlVistaDni);
    urlVistaDni = "";
    $("vistaDni").removeAttribute("src");
    $("vistaDni").classList.add("hidden");
  }

  function actualizarProgresoOcr(porcentaje, mensaje) {
    const progreso = Math.max(0, Math.min(100, Math.round(Number(porcentaje) || 0)));
    $("progresoOcrDni").classList.remove("hidden");
    $("barraOcrDni").style.width = `${progreso}%`;
    $("estadoOcrDni").textContent = mensaje;
  }

  function cambiarEstadoOcrDni(activo) {
    procesandoOcrDni = activo;
    $("imagenDni").disabled = activo;
    $("btnBuscarDni").disabled = activo || buscandoDni;
    $("btnSeleccionarDni").classList.toggle("pointer-events-none", activo);
    $("btnSeleccionarDni").classList.toggle("opacity-60", activo);
    $("btnSeleccionarDni").setAttribute("aria-disabled", activo ? "true" : "false");
    const textoBoton = $("btnSeleccionarDni").querySelector("span");
    if (textoBoton) textoBoton.textContent = activo ? "Procesando foto..." : "Subir o tomar foto";
  }

  async function leerDniConGemini(archivo) {
    const formData = new FormData();
    formData.append("dni", archivo, archivo.name || "dni.jpg");
    const response = await fetch("read-dni-gemini.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "X-CSRF-Token": CSRF_TOKEN },
      body: formData
    });
    const text = await response.text();
    let result;
    try {
      result = JSON.parse(text);
    } catch (_) {
      throw new Error("El servidor de visión devolvió una respuesta no válida.");
    }
    if (!response.ok || !result.ok || !result.persona) {
      throw new Error(result.error || "No fue posible leer el DNI con Google Gemini.");
    }
    return result.persona;
  }

  function normalizarNombreDni(valor) {
    return String(valor || "")
      .toUpperCase()
      .replace(/[^A-ZÁÉÍÓÚÜÑ'\-\s]/g, " ")
      .replace(/\s+/g, " ")
      .trim()
      .slice(0, 100);
  }

  function normalizarLecturaDni(resultado) {
    const origen = resultado || {};
    const dni = String(origen.dni || "").replace(/\D/g, "");
    return {
      dni: /^\d{8}$/.test(dni) ? dni : "",
      apellido_paterno: normalizarNombreDni(origen.apellido_paterno),
      apellido_materno: normalizarNombreDni(origen.apellido_materno),
      nombres: normalizarNombreDni(origen.nombres)
    };
  }

  function lecturaDniCompleta(resultado) {
    const seguro = normalizarLecturaDni(resultado);
    return /^\d{8}$/.test(seguro.dni)
      && Boolean(seguro.apellido_paterno)
      && Boolean(seguro.apellido_materno)
      && Boolean(seguro.nombres);
  }

  function aplicarLecturaDni(resultado, fuente, advertencia = "") {
    const seguro = normalizarLecturaDni(resultado);
    if (seguro.dni) asignarValorMascara("dni", seguro.dni);
    $("apPaterno").value = seguro.apellido_paterno;
    $("apMaterno").value = seguro.apellido_materno;
    $("nombres").value = seguro.nombres;
    dniConsultado = valorSinMascara("dni");
    actualizarResumen();

    const faltantes = [];
    if (!/^\d{8}$/.test(valorSinMascara("dni"))) faltantes.push("DNI");
    if (!seguro.apellido_paterno) faltantes.push("apellido paterno");
    if (!seguro.apellido_materno) faltantes.push("apellido materno");
    if (!seguro.nombres) faltantes.push("prenombres");
    const avisoFaltantes = faltantes.length ? ` Complete manualmente: ${faltantes.join(", ")}.` : "";
    const avisoServicio = advertencia ? ` Google Gemini: ${advertencia}` : "";
    const apellidosLeidos = [seguro.apellido_paterno, seguro.apellido_materno].filter(Boolean).join(" ");
    actualizarProgresoOcr(100, `${fuente} · DNI: ${valorSinMascara("dni") || "no identificado"} · Apellidos: ${apellidosLeidos || "no identificados"} · Prenombres: ${seguro.nombres || "no identificados"}.${avisoFaltantes}${avisoServicio}`);
    mostrarEstadoDni(`Datos leídos con ${fuente}. Verifíquelos con el DNI antes de continuar.${avisoServicio}`, faltantes.length || advertencia ? "info" : "success");
    const primerFaltante = faltantes.includes("apellido paterno") ? "apPaterno"
      : faltantes.includes("apellido materno") ? "apMaterno"
        : faltantes.includes("prenombres") ? "nombres"
          : faltantes.includes("DNI") ? "dni" : "correo";
    $(primerFaltante).focus();
  }

  async function procesarFotoDni(archivo) {
    if (procesandoOcrDni || !archivo) return;
    const tiposPermitidos = ["image/jpeg", "image/png", "image/webp"];
    if (!tiposPermitidos.includes(archivo.type)) {
      actualizarProgresoOcr(0, "Seleccione una imagen JPG, PNG o WebP.");
      $("imagenDni").value = "";
      return;
    }
    if (archivo.size > 10 * 1024 * 1024) {
      actualizarProgresoOcr(0, "La imagen supera el límite de 10 MB.");
      $("imagenDni").value = "";
      return;
    }

    if (urlVistaDni) URL.revokeObjectURL(urlVistaDni);
    urlVistaDni = URL.createObjectURL(archivo);
    $("vistaDni").src = urlVistaDni;
    $("vistaDni").classList.remove("hidden");
    cambiarEstadoOcrDni(true);
    actualizarProgresoOcr(10, "Enviando la imagen a Google Gemini...");

    try {
      const resultado = normalizarLecturaDni(await leerDniConGemini(archivo));
      aplicarLecturaDni(
        resultado,
        lecturaDniCompleta(resultado) ? "Google Gemini" : "Google Gemini (lectura parcial)"
      );
    } catch (geminiError) {
      const mensaje = geminiError.message || "No fue posible leer el DNI con Google Gemini.";
      actualizarProgresoOcr(0, `Google Gemini: ${mensaje}`);
      mostrarEstadoDni(
        `${mensaje} No se modificaron los datos del postulante; puede intentarlo nuevamente.`,
        "error"
      );
    } finally {
      cambiarEstadoOcrDni(false);
      $("imagenDni").value = "";
    }
  }

  async function buscarDni() {
    if (buscandoDni || procesandoOcrDni) return;
    const dni = valorSinMascara("dni").slice(0, 8);
    asignarValorMascara("dni", dni);
    if (!/^\d{8}$/.test(dni)) {
      mostrarEstadoDni("Ingrese los 8 dígitos del DNI para realizar la búsqueda.", "error");
      $("dni").focus();
      return;
    }

    buscandoDni = true;
    $("btnBuscarDni").disabled = true;
    $("iconoBuscarDni").classList.add("animate-spin");
    mostrarEstadoDni("Consultando datos del postulante...", "info");

    try {
      const response = await fetch("lookup-dni.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": CSRF_TOKEN
        },
        body: JSON.stringify({ dni })
      });
      const text = await response.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch (_) {
        throw new Error("El servidor devolvió una respuesta no válida.");
      }
      if (!response.ok || !result.ok || !result.persona) {
        throw new Error(result.error || "No se encontraron datos para el DNI ingresado.");
      }

      $("apPaterno").value = result.persona.apellido_paterno || "";
      $("apMaterno").value = result.persona.apellido_materno || "";
      $("nombres").value = result.persona.nombres || "";
      dniConsultado = dni;
      actualizarResumen();
      reiniciarPanelOcrDni();
      mostrarEstadoDni("Datos encontrados y cargados correctamente.", "success");
      $("correo").focus();
    } catch (lookupError) {
      mostrarEstadoDni(`${lookupError.message} Para leer una foto, haga clic en “Opción recomendada: subir una foto”; también puede completar los datos manualmente.`, "error");
      $("opcionFotoDni").focus();
    } finally {
      buscandoDni = false;
      $("btnBuscarDni").disabled = false;
      $("iconoBuscarDni").classList.remove("animate-spin");
    }
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
    $("btnGenerar").disabled = !disponible || generando || metodoPagoSeleccionado() !== "esquela";

    $("resMonto").textContent = disponible ? money(valor) : "No disponible";
    $("resConcepto").textContent = conceptoTexto();
    $("correoDestino").value = $("correo").value.trim();
    $("correoTarjeta").value = $("correo").value.trim();
    $("montoYape").textContent = disponible ? money(valor) : "No disponible";
    $("montoYapeQr").textContent = disponible ? money(valor) : "No disponible";
    $("montoTarjeta").textContent = disponible ? money(valor) : "No disponible";

    return { disponible, valor, descripcion };
  }

  function actualizarResumen() {
    $("resNombre").textContent = nombreCompleto() || "-";
    $("resDni").textContent = $("dni").value.trim() || "-";
    calcular();
  }

  function renderTabla() {
    const tbody = $("tablaTasas");
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

  function validarIdentificacion() {
    if (!/^\d{8}$/.test(valorSinMascara("dni"))) return "Ingrese un DNI válido de 8 dígitos.";
    if (!$("apPaterno").value.trim() || !$("apMaterno").value.trim() || !$("nombres").value.trim()) {
      return "Ingrese los prenombres y ambos apellidos del postulante.";
    }

    if (!validarCorreoVisual()) return "Ingrese un correo electrónico válido.";

    if (!validarCelularVisual()) return "Ingrese un celular válido de 9 dígitos.";
    return "";
  }

  function validarSeleccion() {
    const concepto = $("conceptoPago").value;
    const calc = calcular();
    if (!calc.disponible) return "La tasa seleccionada no está habilitada.";
    if (concepto !== "constancia" && !$("escuela").value.trim()) return "Seleccione la escuela profesional.";
    return "";
  }

  function validarFormulario() {
    const errorIdentificacion = validarIdentificacion();
    if (errorIdentificacion) return errorIdentificacion;
    const errorSeleccion = validarSeleccion();
    if (errorSeleccion) return errorSeleccion;
    if (!$("fechaVencimiento").value) return "Seleccione la fecha de vencimiento.";

    return "";
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

  $("btnGenerar").addEventListener("click", async () => {
    const error = validarFormulario();
    if (error) {
      alert(error);
      return;
    }

    const calc = calcular();
    const payload = payloadPostulante();

    generando = true;
    pdfUrl = "";
    esquelaCompartir = null;
    $("btnGenerar").disabled = true;
    $("btnGenerar").querySelector("span").textContent = "Enviando";
    $("btnImprimir").disabled = true;
    $("btnCompartirEsquela").disabled = true;
    $("estadoEnvio").classList.add("hidden");

    try {
      const response = await fetch("generate-payment-order.php", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": CSRF_TOKEN
        },
        body: JSON.stringify(payload)
      });
      const text = await response.text();
      let result;
      try {
        result = JSON.parse(text);
      } catch (_) {
        throw new Error("El servidor devolvió una respuesta no válida.");
      }
      if (!response.ok || !result.ok) {
        throw new Error(result.error || "No se pudo generar la esquela.");
      }

      prepararEsquela(result.code, calc);
      pdfUrl = result.download_url;
      esquelaCompartir = {
        code: result.code,
        amount: calc.valor
      };
      $("estadoEnvio").classList.remove("hidden");
      $("btnImprimir").disabled = false;
      $("btnCompartirEsquela").disabled = false;
      $("pdfEsquela").src = pdfUrl;
      $("visorEsquela").classList.remove("hidden");
      $("reciboSimulado").classList.add("hidden");
      if (result.mail_sent) {
        $("estadoEnvioDetalle").className = "border-l-4 border-emerald-600 p-3";
        $("tituloEnvio").className = "text-xs font-bold text-emerald-700";
        $("tituloEnvio").textContent = "Esquela enviada";
        $("msgCorreo").textContent = `La esquela ${result.code} por ${money(calc.valor)} fue enviada a ${payload.correo}. También puede descargarla aquí.`;
        $("confirmacionEstado").className = "border-l-4 border-emerald-600 bg-emerald-50 p-3";
        $("confirmacionTitulo").className = "text-sm font-black text-emerald-800";
        $("confirmacionTitulo").textContent = "Esquela generada y enviada";
        $("confirmacionMensaje").textContent = `La esquela ${result.code} fue enviada a ${payload.correo}. Puede revisarla en el visor y descargar el PDF.`;
      } else {
        $("estadoEnvioDetalle").className = "border-l-4 border-amber-500 p-3";
        $("tituloEnvio").className = "text-xs font-bold text-amber-700";
        $("tituloEnvio").textContent = "PDF generado; correo pendiente";
        $("msgCorreo").textContent = `${result.message} Descargue el PDF y revise la configuración SMTP del servidor.`;
        $("confirmacionEstado").className = "border-l-4 border-amber-500 bg-amber-50 p-3";
        $("confirmacionTitulo").className = "text-sm font-black text-amber-800";
        $("confirmacionTitulo").textContent = "Esquela generada; correo pendiente";
        $("confirmacionMensaje").textContent = `${result.message} La esquela está disponible en el visor y puede descargarse.`;
      }
      activarPaso(4);
    } catch (requestError) {
      $("estadoEnvio").classList.remove("hidden");
      $("estadoEnvioDetalle").className = "border-l-4 border-red-600 p-3";
      $("tituloEnvio").className = "text-xs font-bold text-red-700";
      $("tituloEnvio").textContent = "No se pudo generar la esquela";
      $("msgCorreo").textContent = requestError.message;
    } finally {
      generando = false;
      $("btnGenerar").querySelector("span").textContent = "Generar";
      calcular();
    }
  });

  $("btnImprimir").addEventListener("click", () => {
    if (pdfUrl) window.open(`${pdfUrl}&attachment=1`, "_blank", "noopener");
  });

  $("btnCompartirEsquela").addEventListener("click", compartirEsquela);

  $("btnContinuarSeleccion").addEventListener("click", () => {
    const error = validarIdentificacion();
    if (error) {
      alert(error);
      return;
    }
    activarPaso(2);
  });

  $("btnVolverIdentificacion").addEventListener("click", () => mostrarPaso(1));
  $("btnContinuarPago").addEventListener("click", () => {
    const error = validarSeleccion();
    if (error) {
      alert(error);
      return;
    }
    activarPaso(3);
    actualizarMetodoPago();
  });
  $("btnVolverSeleccion").addEventListener("click", () => mostrarPaso(2));
  $("btnVolverPago").addEventListener("click", () => mostrarPaso(3));
  $("btnNuevaOperacion").addEventListener("click", () => window.location.reload());

  document.querySelectorAll("[data-step-button]").forEach(button => {
    button.addEventListener("click", () => mostrarPaso(Number(button.dataset.stepButton)));
  });

  document.querySelectorAll('input[name="metodoPago"]').forEach(radio => {
    radio.addEventListener("change", () => {
      actualizarMetodoPago();
      if (radio.checked && radio.value === "tarjeta") abrirModal("modalTarjeta");
      if (radio.checked && radio.value === "yape") abrirModalYape("qr");
    });
  });

  $("btnDatosPrueba").addEventListener("click", () => {
    const partes = dividirNombrePostulante();
    asignarValorMascara("nombreTarjeta", partes.nombre || "ERICK");
    asignarValorMascara("apellidoTarjeta", partes.apellido || "ESCALANTE OLANO");
    asignarValorMascara("numeroTarjeta", "4111 1111 1111 1111");
    asignarValorMascara("vencimientoTarjeta", "12/30");
    asignarValorMascara("cvvTarjeta", "123");
    mostrarErrorTarjeta("");
    actualizarVistaTarjeta();
  });

  $("btnPagarTarjeta").addEventListener("click", () => {
    const validacion = validarTarjetaIngresada();
    if (validacion.error) {
      mostrarErrorTarjeta(validacion.error, validacion.field);
      $(validacion.field).focus();
      return;
    }
    mostrarErrorTarjeta("");
    procesarPagoSimulado("tarjeta", validacion);
  });

  $("btnConfirmarYape").addEventListener("click", () => {
    const validacion = validarYapeSimulado();
    if (validacion.error) {
      mostrarErrorYape(validacion.error);
      $("yapeCelular").focus();
      return;
    }
    mostrarErrorYape("");
    mostrarPasoYape("codigo");
  });

  $("btnConfirmarYapeQr").addEventListener("click", () => {
    if (!$("qrYapeNoDisponible").classList.contains("hidden")) {
      $("estadoQrYape").textContent = "El código QR todavía no está configurado.";
      $("estadoQrYape").classList.remove("hidden");
      return;
    }
    $("estadoQrYape").classList.add("hidden");
    procesarPagoSimulado("yape_qr", { qrHolder: $("titularYapeQr").textContent.trim() });
  });

  $("btnAprobarYape").addEventListener("click", () => {
    const celular = validarYapeSimulado();
    if (celular.error) {
      mostrarPasoYape("celular");
      mostrarErrorYape(celular.error);
      $("yapeCelular").focus();
      return;
    }
    const codigo = validarCodigoYapeSimulado();
    if (codigo.error) {
      mostrarErrorYapeCodigo(codigo.error);
      $("yapeCodigoAprobacion").focus();
      return;
    }
    mostrarErrorYapeCodigo("");
    procesarPagoSimulado("yape", { ...celular, ...codigo });
  });

  $("btnCambiarYapeCelular").addEventListener("click", () => mostrarPasoYape("celular"));

  $("btnAbrirTarjetaModal").addEventListener("click", () => abrirModal("modalTarjeta"));
  $("btnAbrirYapeQr").addEventListener("click", () => abrirModalYape("qr"));
  $("btnAbrirYapeModal").addEventListener("click", () => abrirModalYape("celular"));
  $("tabYapeQr").addEventListener("click", () => mostrarModoYape("qr"));
  $("tabYapeCelular").addEventListener("click", () => mostrarModoYape("celular"));
  $("btnCerrarTarjetaModal").addEventListener("click", () => cerrarModal("modalTarjeta"));
  $("btnCerrarYapeModal").addEventListener("click", () => cerrarModal("modalYape"));
  $("modalTarjeta").addEventListener("click", event => {
    if (event.target === $("modalTarjeta")) cerrarModal("modalTarjeta");
  });
  $("modalYape").addEventListener("click", event => {
    if (event.target === $("modalYape")) cerrarModal("modalYape");
  });
  document.addEventListener("keydown", event => {
    if (event.key === "Escape") cerrarModales();
  });

  $("btnImprimirRecibo").addEventListener("click", () => {
    document.body.dataset.printTarget = "receipt";
    window.print();
  });
  window.addEventListener("afterprint", () => {
    delete document.body.dataset.printTarget;
  });

  iniciarMascaras();
  configurarQrYape();
  $("correo").addEventListener("blur", validarCorreoVisual);
  $("correo").addEventListener("input", () => {
    if (!$("errorCorreo").classList.contains("hidden")) validarCorreoVisual();
  });
  $("celular").addEventListener("blur", validarCelularVisual);
  $("celular").addEventListener("input", () => {
    if (!$("errorCelular").classList.contains("hidden")) validarCelularVisual();
  });
  ["nombreTarjeta", "apellidoTarjeta", "numeroTarjeta", "vencimientoTarjeta", "cvvTarjeta"].forEach(id => {
    $(id).addEventListener("input", () => {
      if (!$("errorTarjeta").classList.contains("hidden")) mostrarErrorTarjeta("");
      actualizarVistaTarjeta();
    });
  });
  $("yapeCelular").addEventListener("input", () => {
    if (!$("errorYape").classList.contains("hidden")) mostrarErrorYape("");
  });
  $("yapeCodigoAprobacion").addEventListener("input", () => {
    if (!$("errorYapeCodigo").classList.contains("hidden")) mostrarErrorYapeCodigo("");
  });

  $("btnBuscarDni").addEventListener("click", buscarDni);
  $("opcionFotoDni").addEventListener("click", () => seleccionarModoDatosDni("foto"));
  $("opcionManualDni").addEventListener("click", () => seleccionarModoDatosDni("manual"));
  $("btnSeleccionarDni").addEventListener("keydown", event => {
    if ((event.key === "Enter" || event.key === " ") && !procesandoOcrDni) {
      event.preventDefault();
      $("imagenDni").click();
    }
  });
  $("imagenDni").addEventListener("change", event => procesarFotoDni(event.target.files?.[0]));
  window.addEventListener("beforeunload", () => {
    if (urlVistaDni) URL.revokeObjectURL(urlVistaDni);
  });
  $("dni").addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      buscarDni();
    }
  });
  $("dni").addEventListener("input", event => {
    const limpio = valorSinMascara("dni").slice(0, 8);
    if (!MASCARAS.dni && event.target.value !== limpio) event.target.value = limpio;
    if (dniConsultado && limpio !== dniConsultado) {
      dniConsultado = "";
      mostrarEstadoDni("El DNI cambió; realice una nueva búsqueda.", "info");
    }
  });

  document.querySelectorAll("input, select").forEach(el => {
    el.addEventListener("input", actualizarResumen);
    el.addEventListener("change", actualizarResumen);
  });

  fijarFechasPago();

  renderTabla();
  actualizarResumen();
  actualizarMetodoPago();
  actualizarIndicadorPasos();

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
        b.className = "rate-tab inline-flex shrink-0 items-center gap-1 border border-slate-300 bg-white px-2 py-1 font-bold text-slate-700";
      });
      btn.className = "rate-tab inline-flex shrink-0 items-center gap-1 border border-vino-700 bg-vino-700 px-2 py-1 font-bold text-white";
      renderMobileRates(btn.dataset.group);
    });
  });

  renderMobileRates("ordinario");

</script>
</body>
</html>
