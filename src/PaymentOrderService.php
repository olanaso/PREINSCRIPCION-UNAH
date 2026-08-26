<?php

declare(strict_types=1);

final class PaymentOrderRepository
{
    public function __construct(private string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo inicializar el almacenamiento.');
        }
    }

    public function create(array $order): void
    {
        $path = $this->path($order['internal_id']);
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('La orden ya existe.');
        }
        try {
            chmod($path, 0600);
            if (!flock($handle, LOCK_EX) || fwrite($handle, json_encode($order, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('No se pudo guardar la orden.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function find(string $internalId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $internalId)) return null;
        $contents = @file_get_contents($this->path($internalId));
        return $contents === false ? null : json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    private function path(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }
}

final class PaymentOrderPdf
{
    public function generate(array $order): string
    {
        $lines = [
            'UNIVERSIDAD NACIONAL AUTONOMA DE HUANTA', 'ESQUELA DE PAGO',
            'Codigo: ' . $order['code'], 'Postulante: ' . $order['applicant_name'],
            'Concepto: ' . $order['concept'], 'Monto: S/ ' . number_format($order['amount'], 2),
            'Vencimiento: ' . $order['due_date'], 'Use el codigo de esquela como referencia y conserve su comprobante.'
        ];
        $text = "BT /F1 12 Tf 55 770 Td ";
        foreach ($lines as $i => $line) {
            if ($i) $text .= "0 -28 Td ";
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $this->ascii($line));
            $text .= "({$safe}) Tj ";
        }
        $text .= 'ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            "<< /Length " . strlen($text) . ">>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'
        ];
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n"; }
        $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        return $pdf . "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function ascii(string $value): string
    {
        return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    }
}

class PaymentOrderMailer
{
    public function send(string $email, string $name, string $applicantCode, string $pdf, array $order, string $temporaryUrl): bool
    {
        $boundary = '=_unah_' . bin2hex(random_bytes(12));
        $subject = 'Esquela de pago ' . $applicantCode;
        $html = '<h2>Esquela de pago</h2><p>Hola ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p><b>Orden:</b> ' . htmlspecialchars($order['code']) . '<br><b>Concepto:</b> ' . htmlspecialchars($order['concept'])
            . '<br><b>Monto:</b> S/ ' . number_format($order['amount'], 2) . '<br><b>Vencimiento:</b> ' . htmlspecialchars($order['due_date']) . '</p>'
            . '<p>Pague el monto exacto por un canal institucional, use el código de la orden como referencia y conserve el comprobante.</p>'
            . '<p>También puede <a href="' . htmlspecialchars($temporaryUrl) . '">descargar temporalmente la esquela</a>.</p>';
        $body = "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n--{$boundary}\r\nContent-Type: application/pdf; name=\"esquela-{$applicantCode}.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"esquela-{$applicantCode}.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($pdf)) . "\r\n--{$boundary}--\r\n";
        $headers = ['MIME-Version: 1.0', "Content-Type: multipart/mixed; boundary=\"{$boundary}\"", 'From: ' . (getenv('MAIL_FROM') ?: 'no-reply@unah.edu.pe')];
        return mail($email, $subject, $body, implode("\r\n", $headers));
    }
}

final class PaymentOrderService
{
    public function __construct(private PaymentOrderRepository $repository, private PaymentOrderPdf $pdf, private PaymentOrderMailer $mailer, private string $secret, private string $logFile) {}

    public function createAndSend(array $input, string $baseUrl): array
    {
        $order = $this->validate($input);
        $order['internal_id'] = bin2hex(random_bytes(16));
        $order['code'] = 'UNAH-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $order['created_at'] = gmdate(DATE_ATOM);
        $this->repository->create($order); // The order always exists before PDF or SMTP work.
        return $this->deliver($order, $baseUrl);
    }

    public function retry(string $internalId, string $baseUrl): array
    {
        $order = $this->repository->find($internalId);
        if (!$order) throw new InvalidArgumentException('La orden indicada no existe.');
        return $this->deliver($order, $baseUrl);
    }

    public function download(string $id, int $expires, string $signature): ?array
    {
        if ($expires < time() || !hash_equals($this->sign($id, $expires), $signature)) return null;
        $order = $this->repository->find($id);
        return $order ? ['name' => 'esquela-' . $order['code'] . '.pdf', 'content' => $this->pdf->generate($order)] : null;
    }

    private function deliver(array $order, string $baseUrl): array
    {
        $expires = time() + 3600;
        $url = $baseUrl . '?download=' . rawurlencode($order['internal_id']) . '&expires=' . $expires . '&signature=' . $this->sign($order['internal_id'], $expires);
        try {
            $sent = $this->mailer->send($order['email'], $order['applicant_name'], $order['code'], $this->pdf->generate($order), $order, $url);
            $this->log($order['internal_id'], $sent ? 'sent' : 'failed', $sent ? null : 'mail() returned false');
        } catch (Throwable $error) {
            $sent = false;
            $this->log($order['internal_id'], 'failed', $error::class . ': ' . $error->getMessage());
        }
        return ['ok' => true, 'mail_sent' => $sent, 'order_id' => $order['internal_id'], 'code' => $order['code'], 'download_url' => $url,
            'message' => $sent ? 'Orden generada y correo enviado.' : 'Orden generada, pero el correo no pudo enviarse. Puede reintentarlo.'];
    }

    private function validate(array $in): array
    {
        $email = filter_var(trim((string)($in['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $name = trim((string)($in['nombres'] ?? ''));
        $due = (string)($in['fecha_vencimiento'] ?? '');
        $amount = $this->officialAmount($in);
        if (!$email || $name === '' || mb_strlen($name) > 160 || $amount === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            throw new InvalidArgumentException('Revise los datos ingresados.');
        }
        return ['email'=>$email, 'applicant_name'=>$name, 'concept'=>mb_substr(trim((string)($in['concepto'] ?? 'Orden de pago')), 0, 180), 'amount'=>(float)$amount, 'due_date'=>$due];
    }

    private function officialAmount(array $in): ?float
    {
        if (($in['concepto_key'] ?? '') === 'reinscripcion') return 50.0;
        if (($in['concepto_key'] ?? '') === 'constancia') return 15.0;
        if (($in['concepto_key'] ?? '') !== 'inscripcion') return null;
        $rates = [
            'ORD_EGRESADO'=>[[170,200],[200,230]], 'ORD_QUINTO'=>[[170,200],[200,230]], 'ORD_1_4'=>[[50,50],[80,80]],
            'EXT_PRIMEROS'=>[[170,200],[200,230]], 'EXT_BECA18'=>[[170,200],[200,230]], 'EXT_CONVENIOS'=>[[170,200],[200,230]],
            'EXT_DISCAPACIDAD'=>[[85,100],[115,130]], 'EXT_VIOLENCIA'=>[[20,20],[null,null]], 'EXT_TITULADOS'=>[[270,400],[300,430]],
            'EXT_DEPORTISTAS'=>[[170,200],[200,230]], 'EXT_SERVICIO'=>[[85,100],[115,130]], 'EXT_TRASLADO_INT'=>[[230,null],[260,null]],
            'EXT_TRASLADO_EXT'=>[[270,400],[300,430]]
        ];
        $period = ($in['periodo'] ?? '') === 'regular' ? 0 : (($in['periodo'] ?? '') === 'rezagado' ? 1 : null);
        $origin = ($in['procedencia'] ?? '') === 'estatal' ? 0 : (($in['procedencia'] ?? '') === 'particular' ? 1 : null);
        return $period === null || $origin === null ? null : ($rates[$in['modalidad_key'] ?? ''][$period][$origin] ?? null);
    }

    private function sign(string $id, int $expires): string { return hash_hmac('sha256', $id . '|' . $expires, $this->secret); }
    private function log(string $id, string $status, ?string $detail): void
    {
        $record = ['time'=>gmdate(DATE_ATOM), 'order_id'=>$id, 'mail_status'=>$status];
        if ($detail) $record['technical_detail'] = str_replace(["\r", "\n"], ' ', mb_substr($detail, 0, 500));
        error_log(json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL, 3, $this->logFile);
        @chmod($this->logFile, 0600);
    }
}
