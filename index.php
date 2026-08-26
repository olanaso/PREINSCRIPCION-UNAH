<?php
$enviado = $_SERVER['REQUEST_METHOD'] === 'POST';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preinscripción UNAH</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
    <main class="flex min-h-screen items-center justify-center px-4 py-10">
        <section class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-xl">
            <header class="bg-blue-900 px-6 py-8 text-center text-white sm:px-10">
                <p class="mb-2 text-sm font-semibold uppercase tracking-[0.25em] text-yellow-300">UNAH</p>
                <h1 class="text-3xl font-bold">Formulario de preinscripción</h1>
                <p class="mt-2 text-sm text-blue-100">Completa tus datos para iniciar el proceso de admisión.</p>
            </header>

            <div class="p-6 sm:p-10">
                <?php if ($enviado): ?>
                    <div class="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="alert">
                        ¡Solicitud enviada correctamente! Pronto recibirás información en tu correo.
                    </div>
                <?php endif; ?>

                <form method="post" action="" class="space-y-5">
                    <div>
                        <label for="nombre" class="mb-2 block text-sm font-semibold">Nombre completo</label>
                        <input type="text" id="nombre" name="nombre" required autocomplete="name"
                            class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-200"
                            placeholder="Escribe tu nombre completo">
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="identidad" class="mb-2 block text-sm font-semibold">Número de identidad</label>
                            <input type="text" id="identidad" name="identidad" required inputmode="numeric"
                                class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-200"
                                placeholder="0000-0000-00000">
                        </div>
                        <div>
                            <label for="correo" class="mb-2 block text-sm font-semibold">Correo electrónico</label>
                            <input type="email" id="correo" name="correo" required autocomplete="email"
                                class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-200"
                                placeholder="nombre@correo.com">
                        </div>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="carrera" class="mb-2 block text-sm font-semibold">Carrera de interés</label>
                            <select id="carrera" name="carrera" required
                                class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-200">
                                <option value="" selected disabled>Selecciona una carrera</option>
                                <option>Ingeniería en Sistemas</option>
                                <option>Administración de Empresas</option>
                                <option>Medicina</option>
                                <option>Derecho</option>
                            </select>
                        </div>
                        <div>
                            <label for="jornada" class="mb-2 block text-sm font-semibold">Jornada preferida</label>
                            <select id="jornada" name="jornada" required
                                class="w-full rounded-lg border border-slate-300 bg-white px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-200">
                                <option value="" selected disabled>Selecciona una jornada</option>
                                <option>Matutina</option>
                                <option>Vespertina</option>
                            </select>
                        </div>
                    </div>

                    <label class="flex items-start gap-3 text-sm text-slate-600">
                        <input type="checkbox" name="acepta_terminos" required
                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-800 focus:ring-blue-700">
                        <span>Confirmo que la información proporcionada es correcta.</span>
                    </label>

                    <button type="submit"
                        class="w-full rounded-lg bg-blue-900 px-5 py-3 font-bold text-white transition hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-200">
                        Enviar preinscripción
                    </button>
                </form>
            </div>
        </section>
    </main>
</body>
</html>
