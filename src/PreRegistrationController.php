<?php

declare(strict_types=1);

final class PreRegistrationController
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function view(array &$session): array
    {
        $session['csrf_token'] ??= bin2hex(random_bytes(32));
        $flash = $session['flash'] ?? null;
        unset($session['flash']);

        return [
            'csrfToken' => $session['csrf_token'],
            'values' => $flash['values'] ?? [],
            'errors' => $flash['errors'] ?? [],
            'success' => $flash['success'] ?? null,
            'concepts' => $this->config['concepts'],
            'careers' => $this->config['careers'],
            'schedules' => $this->config['schedules'],
        ];
    }

    public function submit(array $post, array &$session): void
    {
        $values = $this->sanitize($post);
        $errors = $this->validate($values, $post, $session);

        if ($errors !== []) {
            $session['flash'] = ['values' => $values, 'errors' => $errors];
            return;
        }

        $concept = $this->config['concepts'][$values['concepto']];
        try {
            [$applicantCode, $orderNumber] = $this->reserveIdentifiers();
            $pdfPath = $this->generatePdf($values, $concept, $applicantCode, $orderNumber);
        } catch (Throwable $exception) {
            $session['flash'] = [
                'values' => $values,
                'errors' => ['general' => 'No se pudo generar el PDF. Intente nuevamente.'],
            ];
            return;
        }

        $mailSent = $this->sendEmail($values, $concept, $applicantCode, $orderNumber, $pdfPath);
        $session['csrf_token'] = bin2hex(random_bytes(32));
        $session['flash'] = ['success' => [
            'message' => 'La preinscripción se procesó y el PDF se generó correctamente.',
            'applicantCode' => $applicantCode,
            'orderNumber' => $orderNumber,
            'mailSent' => $mailSent,
        ]];
    }

    private function sanitize(array $post): array
    {
        return [
            'nombre' => trim((string) ($post['nombre'] ?? '')),
            'identidad' => preg_replace('/\D+/', '', (string) ($post['identidad'] ?? '')) ?? '',
            'correo' => trim((string) ($post['correo'] ?? '')),
            'carrera' => (string) ($post['carrera'] ?? ''),
            'jornada' => (string) ($post['jornada'] ?? ''),
            'concepto' => (string) ($post['concepto'] ?? ''),
            'terminos' => isset($post['terminos']) ? '1' : '',
        ];
    }

    private function validate(array $values, array $post, array $session): array
    {
        $errors = [];
        $token = (string) ($post['csrf_token'] ?? '');
        if ($token === '' || !isset($session['csrf_token']) || !hash_equals($session['csrf_token'], $token)) {
            $errors['general'] = 'La sesión del formulario expiró. Recargue la página e intente nuevamente.';
        }
        if ($values['nombre'] === '' || mb_strlen($values['nombre']) < 3) {
            $errors['nombre'] = 'Ingrese su nombre completo.';
        }
        if (!preg_match('/^\d{8,13}$/', $values['identidad'])) {
            $errors['identidad'] = 'La identidad debe contener entre 8 y 13 dígitos.';
        }
        if (!filter_var($values['correo'], FILTER_VALIDATE_EMAIL)) {
            $errors['correo'] = 'Ingrese un correo electrónico válido.';
        }
        if (!array_key_exists($values['carrera'], $this->config['careers'])) {
            $errors['carrera'] = 'Seleccione una carrera válida.';
        }
        if (!array_key_exists($values['jornada'], $this->config['schedules'])) {
            $errors['jornada'] = 'Seleccione una jornada válida.';
        }
        if (!array_key_exists($values['concepto'], $this->config['concepts'])) {
            $errors['concepto'] = 'Seleccione un concepto de pago válido.';
        }
        if ($values['terminos'] !== '1') {
            $errors['terminos'] = 'Debe aceptar los términos para continuar.';
        }
        return $errors;
    }

    private function reserveIdentifiers(): array
    {
        $directory = rtrim($this->config['storage_path'], '/') . '/orders';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el directorio de órdenes.');
        }
        do {
            $code = 'UNAH-' . strtoupper(bin2hex(random_bytes(6)));
            $codePath = $directory . '/code-' . $code . '.lock';
            $codeHandle = @fopen($codePath, 'x');
        } while ($codeHandle === false);
        fclose($codeHandle);
        do {
            $order = (string) random_int(100000000, 999999999);
            $orderHandle = @fopen($directory . '/order-' . $order . '.lock', 'x');
        } while ($orderHandle === false);
        fclose($orderHandle);
        return [$code, $order];
    }

    private function generatePdf(array $values, array $concept, string $code, string $order): string
    {
        $directory = rtrim($this->config['storage_path'], '/') . '/pdf';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el directorio de PDF.');
        }
        $lines = [
            'UNAH - ORDEN DE PAGO', 'Codigo: ' . $code, 'Orden: ' . $order,
            'Postulante: ' . $values['nombre'], 'Identidad: ' . $values['identidad'],
            'Concepto: ' . $concept['label'], 'Monto: ' . $concept['currency'] . ' ' . number_format($concept['amount'], 2),
        ];
        $stream = "BT /F1 12 Tf 50 780 Td ";
        foreach ($lines as $index => $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], iconv('UTF-8', 'ASCII//TRANSLIT', $line) ?: $line);
            $stream .= ($index ? '0 -24 Td ' : '') . '(' . $safe . ') Tj ';
        }
        $stream .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
        $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        $path = $directory . '/' . $code . '.pdf';
        if (file_put_contents($path, $pdf, LOCK_EX) === false) throw new RuntimeException('No fue posible escribir el PDF.');
        return $path;
    }

    private function sendEmail(array $values, array $concept, string $code, string $order, string $pdfPath): bool
    {
        $subject = 'Orden de pago ' . $order;
        $message = "Su orden {$order} fue generada con el código {$code}.\n";
        $message .= 'Monto: ' . $concept['currency'] . ' ' . number_format($concept['amount'], 2) . "\n";
        $message .= 'PDF almacenado: ' . basename($pdfPath);
        $mail = $this->config['mail'];
        $fromName = str_replace(["\r", "\n"], '', (string) $mail['from_name']);
        $fromAddress = filter_var($mail['from_address'], FILTER_VALIDATE_EMAIL);
        $replyTo = filter_var($mail['reply_to'], FILTER_VALIDATE_EMAIL);
        if ($fromAddress === false || $replyTo === false) {
            return false;
        }
        $headers = [
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyTo,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        return @mail($values['correo'], $subject, $message, implode("\r\n", $headers));
    }
}
