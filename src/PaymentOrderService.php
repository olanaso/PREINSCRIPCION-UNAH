<?php

declare(strict_types=1);

/** Stores order data outside the web root; PDFs are generated on demand. */
final class PaymentOrderRepository
{
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo inicializar el almacenamiento de esquelas.');
        }
    }

    public function create(array $order)
    {
        $this->purgeExpired();
        $path = $this->path($order['internal_id']);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('La esquela ya existe.');
        }
        try {
            @chmod($path, 0600);
            $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || !flock($handle, LOCK_EX) || fwrite($handle, $json) === false) {
                throw new RuntimeException('No se pudo guardar la esquela.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function find(string $internalId)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $internalId)) {
            return null;
        }
        $contents = @file_get_contents($this->path($internalId));
        if ($contents === false) {
            return null;
        }
        $order = json_decode($contents, true);
        return is_array($order) ? $order : null;
    }

    public function purgeExpired(int $retentionSeconds = 86400)
    {
        $cutoff = time() - $retentionSeconds;
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: array() as $path) {
            $modified = @filemtime($path);
            if ($modified !== false && $modified < $cutoff) {
                @unlink($path);
            }
        }
    }

    private function path(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $id . '.json';
    }
}

/** Dependency-free A4 renderer with PDF base fonts and WinAnsi encoding. */
final class PaymentOrderPdf
{
    private $commands = '';
    private $pageWidth = 595.28;
    private $logoPath;

    public function __construct($logoPath = null)
    {
        $this->logoPath = $logoPath ?: dirname(__DIR__) . '/assets/logo-unah.png';
    }

    public function generate(array $order): string
    {
        $this->commands = '';
        $logo = $this->loadLogo();
        $admissionPeriod = trim((string) ($order['admission_period'] ?? '2026-II'));
        if ($admissionPeriod === '') {
            $admissionPeriod = '2026-II';
        }
        $burgundy = array(0.33, 0.11, 0.19);
        $muted = array(0.34, 0.37, 0.42);
        $light = array(0.96, 0.96, 0.97);
        $amber = array(1.00, 0.97, 0.86);

        $this->fillRect(0, 760, $this->pageWidth, 82, $burgundy);
        if ($logo !== null) {
            $this->fillRect(34, 773, 205, 58, array(1, 1, 1));
            $this->strokeRect(34, 773, 205, 58, array(0.78, 0.70, 0.73), 0.8);
            $this->image(42, 779, 189, 48);
            $this->text(262, 805, 9.4, 'VICERRECTORADO ACADÉMICO', true, array(1, 1, 1));
            $this->text(262, 788, 9.4, 'DIRECCIÓN DE ADMISIÓN', true, array(0.95, 0.91, 0.93));
            $this->text(262, 773, 7.4, 'SISTEMA DE PREINSCRIPCIÓN', false, array(0.90, 0.84, 0.87));
        } else {
            $this->fillRect(42, 779, 42, 42, array(1, 1, 1));
            $this->strokeRect(42, 779, 42, 42, array(0.78, 0.70, 0.73), 1);
            $this->text(63, 797, 9, 'UNAH', true, $burgundy, 'center');
            $this->text(101, 811, 11.2, 'UNIVERSIDAD NACIONAL AUTÓNOMA DE HUANTA', true, array(1, 1, 1));
            $this->text(101, 795, 8.5, 'VICERRECTORADO ACADÉMICO', true, array(0.95, 0.91, 0.93));
            $this->text(101, 781, 8.5, 'DIRECCIÓN DE ADMISIÓN', false, array(0.95, 0.91, 0.93));
        }

        $this->text($this->pageWidth / 2, 731, 14, 'ORDEN DE PAGO ADMISIÓN ' . $admissionPeriod, true, $burgundy, 'center');
        $this->text($this->pageWidth / 2, 714, 8.5, 'CÓDIGO DE POSTULANTE: ' . $order['code'], true, $muted, 'center');
        $this->line(42, 701, 553, 701, array(0.75, 0.76, 0.78), 0.8);

        $this->sectionTitle(42, 680, 'DATOS PERSONALES', $burgundy);
        $this->dataRow(55, 660, 'DNI', $order['dni']);
        $this->dataRow(55, 643, 'APELLIDOS', $order['surnames']);
        $this->dataRow(55, 626, 'NOMBRES', $order['given_names']);
        $this->dataRow(55, 609, 'CORREO', $order['email']);

        $this->sectionTitle(42, 579, 'DATOS A LOS QUE POSTULA', $burgundy);
        $this->dataRow(55, 559, 'MODALIDAD', $order['modality']);
        $this->dataRow(55, 542, 'TIPO DE COLEGIO', $order['origin']);
        $this->dataRow(55, 525, 'PROGRAMA DE ESTUDIO', $order['school'], 8.4);
        $this->dataRow(55, 508, 'CONCEPTO', $order['concept'], 8.4);
        $this->dataRow(55, 491, 'PERIODO', $order['period']);

        $this->fillRect(42, 397, 511, 68, $light);
        $this->strokeRect(42, 397, 511, 68, $burgundy, 1.5);
        $this->text($this->pageWidth / 2, 444, 9, 'MONTO A PAGAR (S/.)', true, $burgundy, 'center');
        $this->text($this->pageWidth / 2, 416, 24, number_format((float) $order['amount'], 2, '.', ','), true, $burgundy, 'center');
        $this->text($this->pageWidth / 2, 378, 9, '*** UNA VEZ REALIZADO EL PAGO, NO HABRÁ DEVOLUCIÓN ***', true, array(0.25, 0.25, 0.27), 'center');

        $this->fillRect(42, 214, 511, 112, $amber);
        $this->strokeRect(42, 214, 511, 112, array(0.84, 0.63, 0.13), 0.8);
        $this->text(56, 305, 9, 'INSTRUCCIONES IMPORTANTES', true, array(0.44, 0.30, 0.02));
        $notes = array(
            '1. Verifique que el DNI, la modalidad, el programa y el monto sean correctos.',
            '2. Pague únicamente el monto exacto indicado y antes del vencimiento.',
            '3. Use el código de postulante como referencia cuando el canal de pago lo solicite.',
            '4. Conserve esta esquela y el comprobante hasta que el pago sea validado.',
            '5. Este documento no constituye por sí mismo un comprobante de pago.',
        );
        $y = 287;
        foreach ($notes as $note) {
            $this->text(56, $y, 7.8, $note, false, array(0.25, 0.25, 0.27));
            $y -= 16;
        }

        $this->text($this->pageWidth / 2, 169, 9.5, 'OJO: EL PAGO SE REALIZA CON EL DNI DEL POSTULANTE', true, $burgundy, 'center');
        $this->line(42, 145, 553, 145, array(0.72, 0.73, 0.75), 0.7);
        $this->text(42, 126, 7.5, 'Orden: ' . $order['order_number'], true, $muted);
        $this->text($this->pageWidth / 2, 126, 7.5, 'Emisión: ' . $order['issue_date'], false, $muted, 'center');
        $this->text(553, 126, 7.5, 'Vence: ' . $this->displayDate($order['due_date']), true, $muted, 'right');
        $this->text($this->pageWidth / 2, 92, 7, 'Documento generado electrónicamente por el sistema de admisión UNAH.', false, $muted, 'center');

        return $this->buildDocument($logo);
    }

    private function sectionTitle($x, $y, $label, array $color)
    {
        $this->text($x, $y, 9, $label, true, $color);
        $this->line($x, $y - 6, 553, $y - 6, array(0.82, 0.82, 0.84), 0.6);
    }

    private function dataRow($x, $y, $label, $value, $size = 9)
    {
        $this->text($x, $y, 7.8, $label . ':', true, array(0.33, 0.34, 0.37));
        $this->fitText(180, $y, 365, $size, (string) $value, false, array(0.10, 0.11, 0.13));
    }

    private function fitText($x, $y, $maxWidth, $size, $value, $bold, array $color)
    {
        $current = $size;
        while ($current > 6.4 && $this->estimatedWidth($value, $current) > $maxWidth) {
            $current -= 0.3;
        }
        $this->text($x, $y, $current, $value, $bold, $color);
    }

    private function estimatedWidth($value, $size): float
    {
        return strlen($this->encodeText((string) $value)) * $size * 0.51;
    }

    private function text($x, $y, $size, $value, $bold = false, array $color = array(0, 0, 0), $align = 'left')
    {
        $encoded = $this->encodeText((string) $value);
        $width = strlen($encoded) * $size * 0.51;
        if ($align === 'center') {
            $x -= $width / 2;
        } elseif ($align === 'right') {
            $x -= $width;
        }
        $font = $bold ? 'F2' : 'F1';
        $this->commands .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font, $size, $color[0], $color[1], $color[2], $x, $y, $this->escapePdfText($encoded)
        );
    }

    private function fillRect($x, $y, $width, $height, array $color)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
    }

    private function strokeRect($x, $y, $width, $height, array $color, $lineWidth)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S Q\n", $color[0], $color[1], $color[2], $lineWidth, $x, $y, $width, $height);
    }

    private function line($x1, $y1, $x2, $y2, array $color, $lineWidth)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $color[0], $color[1], $color[2], $lineWidth, $x1, $y1, $x2, $y2);
    }

    private function image($x, $y, $width, $height)
    {
        $this->commands .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q\n", $width, $height, $x, $y);
    }

    private function loadLogo()
    {
        if (!is_file($this->logoPath) || !function_exists('imagecreatefrompng')) {
            return null;
        }
        $image = @imagecreatefrompng($this->logoPath);
        if ($image === false) {
            return null;
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $rgb = '';
        $alpha = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $rgb .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
                $gdAlpha = ($color >> 24) & 0x7F;
                $alpha .= chr(255 - (int) round($gdAlpha * 255 / 127));
            }
        }
        imagedestroy($image);
        return array(
            'width' => $width,
            'height' => $height,
            'rgb' => gzcompress($rgb, 9),
            'alpha' => gzcompress($alpha, 9),
        );
    }

    private function encodeText(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean === null ? '' : $clean);
        return $encoded === false ? $value : $encoded;
    }

    private function escapePdfText(string $value): string
    {
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', ' ', ' '), $value);
    }

    private function displayDate(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed ? $parsed->format('d/m/Y') : $date;
    }

    private function buildDocument($logo): string
    {
        $resources = '/Font << /F1 5 0 R /F2 6 0 R >>';
        if ($logo !== null) {
            $resources .= ' /XObject << /Logo 7 0 R >>';
        }
        $objects = array(
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << ' . $resources . ' >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($this->commands) . ">>\nstream\n" . $this->commands . "endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        );
        if ($logo !== null) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width']
                . ' /Height ' . $logo['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask 8 0 R /Length '
                . strlen($logo['rgb']) . ">>\nstream\n" . $logo['rgb'] . "\nendstream";
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $logo['width']
                . ' /Height ' . $logo['height']
                . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '
                . strlen($logo['alpha']) . ">>\nstream\n" . $logo['alpha'] . "\nendstream";
        }
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0);
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $objectCount = count($objects);
        $pdf .= "xref\n0 " . ($objectCount + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $objectCount; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $pdf . "trailer << /Size " . ($objectCount + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

final class PaymentReceiptPdf
{
    private $commands = '';

    public function generate(array $receipt): string
    {
        $this->commands = '';
        $burgundy = array(0.33, 0.11, 0.19);
        $green = array(0.05, 0.49, 0.29);
        $muted = array(0.34, 0.37, 0.42);
        $light = array(0.96, 0.97, 0.98);

        $this->fillRect(0, 760, 595.28, 82, $burgundy);
        $this->text(42, 812, 15, 'UNIVERSIDAD NACIONAL AUTONOMA DE HUANTA', true, array(1, 1, 1));
        $this->text(42, 792, 10, 'DIRECCION DE ADMISION', true, array(0.95, 0.91, 0.93));
        $this->text(42, 775, 8, 'RECIBO DE PAGO SIMULADO', false, array(0.95, 0.91, 0.93));

        $this->text(297.64, 724, 18, 'RECIBO DE PAGO', true, $burgundy, 'center');
        $this->text(297.64, 704, 9, ' ', true, array(0.72, 0.10, 0.10), 'center');

        $this->fillRect(42, 650, 511, 36, array(0.90, 0.98, 0.93));
        $this->strokeRect(42, 650, 511, 36, $green, 1);
        $this->text(297.64, 663, 13, 'PAGO APROBADO', true, $green, 'center');

        $rows = array(
            array('Operacion', $receipt['operation_code']),
            array('Fecha y hora', $receipt['paid_at']),
            array('Postulante', $receipt['applicant_name']),
            array('DNI', $receipt['dni']),
            array('Correo', $receipt['email']),
            array('Concepto', $receipt['concept']),
            array('Escuela profesional', $receipt['school']),
            array('Metodo', $receipt['payment_method_label']),
        );

        $y = 610;
        foreach ($rows as $index => $row) {
            if ($index % 2 === 0) {
                $this->fillRect(42, $y - 8, 511, 24, $light);
            }
            $this->text(56, $y, 8.5, $row[0] . ':', true, $muted);
            $this->fitText(190, $y, 335, 9.5, (string) $row[1], false, array(0.10, 0.11, 0.13));
            $y -= 28;
        }

        $this->strokeRect(126, 283, 343, 78, $burgundy, 1.5);
        $this->fillRect(126, 283, 343, 78, array(0.98, 0.95, 0.96));
        $this->text(297.64, 333, 9, 'MONTO SIMULADO', true, $burgundy, 'center');
        $this->text(297.64, 303, 26, 'S/ ' . number_format((float) $receipt['amount'], 2, '.', ','), true, $burgundy, 'center');

        $this->line(42, 231, 553, 231, array(0.74, 0.75, 0.77), 0.7);
        $this->text(297.64, 210, 8, 'Este documento fue generado solo para demostracion del sistema.', false, array(0.42, 0.42, 0.45), 'center');
        $this->text(297.64, 194, 8, 'No acredita un pago real y no tiene valor contable.', true, array(0.72, 0.10, 0.10), 'center');

        return $this->buildDocument();
    }

    private function fitText($x, $y, $maxWidth, $size, $value, $bold, array $color)
    {
        $current = $size;
        while ($current > 6.4 && strlen($this->encodeText((string) $value)) * $current * 0.51 > $maxWidth) {
            $current -= 0.3;
        }
        $this->text($x, $y, $current, $value, $bold, $color);
    }

    private function text($x, $y, $size, $value, $bold = false, array $color = array(0, 0, 0), $align = 'left')
    {
        $encoded = $this->encodeText((string) $value);
        $width = strlen($encoded) * $size * 0.51;
        if ($align === 'center') {
            $x -= $width / 2;
        } elseif ($align === 'right') {
            $x -= $width;
        }
        $font = $bold ? 'F2' : 'F1';
        $this->commands .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font, $size, $color[0], $color[1], $color[2], $x, $y, $this->escapePdfText($encoded)
        );
    }

    private function fillRect($x, $y, $width, $height, array $color)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
    }

    private function strokeRect($x, $y, $width, $height, array $color, $lineWidth)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S Q\n", $color[0], $color[1], $color[2], $lineWidth, $x, $y, $width, $height);
    }

    private function line($x1, $y1, $x2, $y2, array $color, $lineWidth)
    {
        $this->commands .= sprintf("q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n", $color[0], $color[1], $color[2], $lineWidth, $x1, $y1, $x2, $y2);
    }

    private function encodeText(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean === null ? '' : $clean);
        return $encoded === false ? $value : $encoded;
    }

    private function escapePdfText(string $value): string
    {
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', ' ', ' '), $value);
    }

    private function buildDocument(): string
    {
        $objects = array(
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> /Contents 4 0 R >>',
            '<< /Length ' . strlen($this->commands) . ">>\nstream\n" . $this->commands . "endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        );
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0);
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 7\n0000000000 65535 f \n";
        for ($i = 1; $i <= 6; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        return $pdf . "trailer << /Size 7 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}

class PaymentOrderMailer
{
    private $config;

    public function __construct(array $config = array())
    {
        $this->config = $config;
    }

    public function send(string $email, string $name, string $applicantCode, string $pdf, array $order, string $temporaryUrl): bool
    {
        $subject = 'Esquela de pago ' . $applicantCode;
        $fileName = 'esquela-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $applicantCode) . '.pdf';
        $html = $this->emailHtml($name, $order, $temporaryUrl);
        return $this->sendPdfDocument($email, $subject, $html, $fileName, $pdf);
    }

    public function sendReceipt(string $email, string $name, string $receiptCode, string $pdf, array $receipt): bool
    {
        $subject = 'Recibo de pago ' . $receiptCode;
        $fileName = 'recibo-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $receiptCode) . '.pdf';
        $html = $this->receiptEmailHtml($name, $receipt);
        return $this->sendPdfDocument($email, $subject, $html, $fileName, $pdf);
    }

    private function sendPdfDocument(string $email, string $subject, string $html, string $fileName, string $pdf): bool
    {
        $fromAddress = filter_var((string) ($this->config['from_address'] ?? ''), FILTER_VALIDATE_EMAIL);
        $replyTo = filter_var((string) ($this->config['reply_to'] ?? ''), FILTER_VALIDATE_EMAIL);
        if ($fromAddress === false || $replyTo === false) {
            throw new RuntimeException('El remitente del correo no esta configurado.');
        }
        $fromName = $this->singleLine((string) ($this->config['from_name'] ?? 'Admisiones UNAH'));
        $boundary = '=_unah_' . bin2hex(random_bytes(12));
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n--" . $boundary . "\r\n"
            . 'Content-Type: application/pdf; name="' . $fileName . "\"\r\n"
            . 'Content-Disposition: attachment; filename="' . $fileName . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($pdf), 76, "\r\n") . '--' . $boundary . "--\r\n";
        $headers = array(
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->encodedHeader($fromName) . ' <' . $fromAddress . '>',
            'Reply-To: ' . $replyTo,
            'To: ' . $email,
            'Subject: ' . $this->encodedHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->messageHost($fromAddress) . '>',
        );
        $transport = strtolower((string) ($this->config['transport'] ?? 'smtp'));
        if ($transport === 'mail') {
            $mailHeaders = array_values(array_filter($headers, function ($header) {
                return strpos($header, 'To: ') !== 0 && strpos($header, 'Subject: ') !== 0;
            }));
            return @mail($email, $this->encodedHeader($subject), $body, implode("\r\n", $mailHeaders));
        }
        return $this->smtpSend($fromAddress, $email, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private function emailHtml(string $name, array $order, string $temporaryUrl): string
    {
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        return '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#20242a"><div style="max-width:620px;margin:auto;border:1px solid #ddd">'
            . '<div style="background:#531d31;color:#fff;padding:18px 22px"><b>UNAH - DIRECCIÓN DE ADMISIÓN</b><h2 style="margin:7px 0 0">Esquela de pago</h2></div>'
            . '<div style="padding:22px"><p>Hola <b>' . $e($name) . '</b>,</p><p>Se generó correctamente su esquela de pago, adjunta a este mensaje.</p>'
            . '<table style="width:100%;border-collapse:collapse"><tr><td style="padding:7px;background:#f3f3f4"><b>Código</b></td><td style="padding:7px">' . $e($order['code']) . '</td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Concepto</b></td><td style="padding:7px">' . $e($order['concept']) . '</td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Monto</b></td><td style="padding:7px"><b>S/ ' . number_format((float) $order['amount'], 2) . '</b></td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Vencimiento</b></td><td style="padding:7px">' . $e($order['due_date']) . '</td></tr></table>'
            . '<p style="margin-top:20px"><a style="background:#762842;color:#fff;padding:10px 14px;text-decoration:none" href="' . $e($temporaryUrl) . '">Descargar esquela</a></p>'
            . '<p style="font-size:12px;color:#666">El enlace es temporal. Conserve el PDF adjunto y su comprobante de pago.</p></div></div></body></html>';
    }

    private function receiptEmailHtml(string $name, array $receipt): string
    {
        $e = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        return '<!doctype html><html lang="es"><body style="font-family:Arial,sans-serif;color:#20242a"><div style="max-width:620px;margin:auto;border:1px solid #ddd">'
            . '<div style="background:#531d31;color:#fff;padding:18px 22px"><b>UNAH - DIRECCION DE ADMISION</b><h2 style="margin:7px 0 0">Recibo de pago</h2></div>'
            . '<div style="padding:22px"><p>Hola <b>' . $e($name) . '</b>,</p><p>Se genero correctamente su recibo de pago simulado, adjunto a este mensaje.</p>'
            . '<table style="width:100%;border-collapse:collapse"><tr><td style="padding:7px;background:#f3f3f4"><b>Operacion</b></td><td style="padding:7px">' . $e($receipt['operation_code']) . '</td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Metodo</b></td><td style="padding:7px">' . $e($receipt['payment_method_label']) . '</td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Concepto</b></td><td style="padding:7px">' . $e($receipt['concept']) . '</td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Monto</b></td><td style="padding:7px"><b>S/ ' . number_format((float) $receipt['amount'], 2) . '</b></td></tr>'
            . '<tr><td style="padding:7px;background:#f3f3f4"><b>Estado</b></td><td style="padding:7px;color:#047857"><b>APROBADO </b></td></tr></table>'
            . '<p style="font-size:12px;color:#a33">Este documento no acredita un pago real y no tiene valor contable.</p></div></div></body></html>';
    }

    private function smtpSend(string $from, string $recipient, string $message): bool
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 587);
        $encryption = strtolower(trim((string) ($this->config['encryption'] ?? 'tls')));
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');
        $timeout = max(5, (int) ($this->config['timeout'] ?? 20));
        if ($host === '' || $host === 'smtp.example.edu') {
            throw new RuntimeException('El servidor SMTP no esta configurado.');
        }
        $verifyPeer = !isset($this->config['verify_peer']) || (bool) $this->config['verify_peer'];
        $context = stream_context_create(array('ssl' => array(
            'verify_peer' => $verifyPeer,
            'verify_peer_name' => $verifyPeer,
            'allow_self_signed' => !$verifyPeer,
            'peer_name' => $host,
        )));
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errorNumber, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new RuntimeException('No se pudo conectar al servidor SMTP.');
        }
        stream_set_timeout($socket, $timeout);
        try {
            $this->expectReply($socket, array(220));
            $helo = preg_replace('/[^A-Za-z0-9.-]/', '', gethostname() ?: 'localhost') ?: 'localhost';
            $this->command($socket, 'EHLO ' . $helo, array(250));
            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', array(220));
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('No se pudo establecer la conexion TLS con SMTP.');
                }
                $this->command($socket, 'EHLO ' . $helo, array(250));
            }
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', array(334));
                $this->command($socket, base64_encode($username), array(334));
                $this->command($socket, base64_encode($password), array(235));
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', array(250));
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', array(250, 251));
            $this->command($socket, 'DATA', array(354));
            $message = preg_replace('/(?m)^\./', '..', $message);
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expectReply($socket, array(250));
            $this->command($socket, 'QUIT', array(221));
            return true;
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $command, array $expected): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('No se pudo escribir en el servidor SMTP.');
        }
        return $this->expectReply($socket, $expected);
    }

    private function expectReply($socket, array $expected): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $status = (int) substr($response, 0, 3);
        if (!in_array($status, $expected, true)) {
            throw new RuntimeException('El servidor SMTP rechazo la operacion (codigo ' . $status . ').');
        }
        return $response;
    }

    private function encodedHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($this->singleLine($value)) . '?=';
    }

    private function singleLine(string $value): string
    {
        return trim(str_replace(array("\r", "\n"), '', $value));
    }

    private function messageHost(string $address): string
    {
        $parts = explode('@', $address, 2);
        return isset($parts[1]) ? preg_replace('/[^A-Za-z0-9.-]/', '', $parts[1]) : 'localhost';
    }
}

final class PaymentOrderService
{
    private $repository;
    private $pdf;
    private $receiptPdf;
    private $mailer;
    private $secret;
    private $logFile;

    public function __construct(PaymentOrderRepository $repository, PaymentOrderPdf $pdf, PaymentOrderMailer $mailer, string $secret, string $logFile, PaymentReceiptPdf $receiptPdf = null)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('La clave de firma debe tener al menos 32 caracteres.');
        }
        $this->repository = $repository;
        $this->pdf = $pdf;
        $this->receiptPdf = $receiptPdf ?: new PaymentReceiptPdf();
        $this->mailer = $mailer;
        $this->secret = $secret;
        $this->logFile = $logFile;
    }

    public function createAndSend(array $input, string $baseUrl): array
    {
        $order = $this->validate($input);
        $order['internal_id'] = bin2hex(random_bytes(16));
        $order['code'] = 'UNAH-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $order['order_number'] = 'OP-' . date('Ymd') . '-' . random_int(100000, 999999);
        $order['created_at'] = date(DATE_ATOM);
        $order['issue_date'] = date('d/m/Y H:i');
        $order['admission_period'] = getenv('ADMISSION_PERIOD') ?: '2026-II';
        $this->repository->create($order);
        return $this->deliver($order, $baseUrl);
    }

    public function sendSimulatedReceipt(array $input): array
    {
        $order = $this->validate($input);
        $method = strtolower(trim((string) ($input['payment_method'] ?? '')));
        $operation = strtoupper(trim((string) ($input['operation_code'] ?? '')));
        if (!in_array($method, array('tarjeta', 'yape', 'yape_qr'), true)) {
            throw new InvalidArgumentException('El metodo de pago no es valido.');
        }
        if (!preg_match('/^SIM-[0-9]{8}-[A-F0-9]{8}$/', $operation)) {
            $operation = 'SIM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        }

        $methodLabel = 'Yape / Aprobacion simulada';
        if ($method === 'tarjeta') {
            $brand = $this->cleanText($input['card_brand'] ?? 'Tarjeta', 30);
            $last4 = preg_replace('/\D+/', '', (string) ($input['card_last4'] ?? ''));
            $methodLabel = ($brand === '' ? 'Tarjeta' : $brand) . ' **** ' . (strlen($last4) === 4 ? $last4 : '0000');
        }
        if ($method === 'yape') {
            $yapePhone = preg_replace('/\D+/', '', (string) ($input['yape_phone'] ?? $order['phone']));
            $approvalCode = preg_replace('/\D+/', '', (string) ($input['yape_approval_code'] ?? ''));
            if (!preg_match('/^9\d{8}$/', $yapePhone)) {
                throw new InvalidArgumentException('El celular Yape no es valido.');
            }
            if (!preg_match('/^\d{6}$/', $approvalCode)) {
                throw new InvalidArgumentException('El codigo de aprobacion Yape no es valido.');
            }
            $methodLabel = 'Yape +51 ' . substr($yapePhone, 0, 3) . ' ' . substr($yapePhone, 3, 3) . ' ' . substr($yapePhone, 6);
        }
        if ($method === 'yape_qr') {
            $methodLabel = 'Yape / Codigo QR';
        }

        $receipt = array(
            'operation_code' => $operation,
            'paid_at' => date('d/m/Y H:i'),
            'email' => $order['email'],
            'applicant_name' => $order['applicant_name'],
            'dni' => $order['dni'],
            'school' => $order['school'],
            'concept' => $order['concept'],
            'amount' => $order['amount'],
            'payment_method' => $method,
            'payment_method_label' => $methodLabel,
        );

        $document = $this->receiptPdf->generate($receipt);
        try {
            $sent = $this->mailer->sendReceipt($order['email'], $order['applicant_name'], $operation, $document, $receipt);
            $this->log($operation, $sent ? 'receipt_sent' : 'receipt_failed', $sent ? null : 'El transporte devolvio false.');
        } catch (Throwable $error) {
            $sent = false;
            $this->log($operation, 'receipt_failed', get_class($error) . ': ' . $error->getMessage());
        }

        return array(
            'ok' => true,
            'mail_sent' => $sent,
            'operation_code' => $operation,
            'message' => $sent ? 'Recibo enviado al correo ingresado.' : 'El recibo se genero, pero el servidor de correo no confirmo el envio.',
        );
    }

    public function retry(string $internalId, string $baseUrl): array
    {
        $order = $this->repository->find($internalId);
        if (!$order) {
            throw new InvalidArgumentException('La esquela indicada no existe.');
        }
        return $this->deliver($order, $baseUrl);
    }

    public function download(string $id, int $expires, string $signature)
    {
        if ($expires < time() || !hash_equals($this->sign($id, $expires), $signature)) {
            return null;
        }
        $order = $this->repository->find($id);
        return $order ? array('name' => 'esquela-' . $order['code'] . '.pdf', 'content' => $this->pdf->generate($order)) : null;
    }

    private function deliver(array $order, string $baseUrl): array
    {
        $expires = time() + 3600;
        $url = $baseUrl . '?id=' . rawurlencode($order['internal_id']) . '&expires=' . $expires . '&signature=' . rawurlencode($this->sign($order['internal_id'], $expires));
        $document = $this->pdf->generate($order);
        try {
            $sent = $this->mailer->send($order['email'], $order['applicant_name'], $order['code'], $document, $order, $url);
            $this->log($order['internal_id'], $sent ? 'sent' : 'failed', $sent ? null : 'El transporte devolvio false.');
        } catch (Throwable $error) {
            $sent = false;
            $this->log($order['internal_id'], 'failed', get_class($error) . ': ' . $error->getMessage());
        }
        return array(
            'ok' => true,
            'mail_sent' => $sent,
            'order_id' => $order['internal_id'],
            'order_number' => $order['order_number'],
            'code' => $order['code'],
            'download_url' => $url,
            'message' => $sent ? 'Esquela generada y enviada al correo ingresado.' : 'La esquela se genero, pero el servidor de correo no confirmo el envio.',
        );
    }

    private function validate(array $input): array
    {
        $email = filter_var(trim((string) ($input['correo'] ?? '')), FILTER_VALIDATE_EMAIL);
        $dni = preg_replace('/\D+/', '', (string) ($input['dni'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($input['celular'] ?? ''));
        $paternal = $this->cleanText($input['apellido_paterno'] ?? '', 80);
        $maternal = $this->cleanText($input['apellido_materno'] ?? '', 80);
        $givenNames = $this->cleanText($input['nombres_propios'] ?? ($input['nombres'] ?? ''), 120);
        $conceptKey = (string) ($input['concepto_key'] ?? '');
        $dueDate = (string) ($input['fecha_vencimiento'] ?? '');
        $amount = $this->officialAmount($input);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $validDate = $date && $date->format('Y-m-d') === $dueDate;
        $today = new DateTimeImmutable('today');
        $requiredDueDate = $today->modify('+2 days');
        if ($email === false || !preg_match('/^\d{8}$/', $dni) || !preg_match('/^\d{9}$/', $phone)
            || $givenNames === '' || $amount === null || !$validDate || $date->format('Y-m-d') !== $requiredDueDate->format('Y-m-d')) {
            throw new InvalidArgumentException('Revise los datos ingresados y la fecha de vencimiento.');
        }
        if (($paternal === '') !== ($maternal === '')) {
            throw new InvalidArgumentException('Ingrese ambos apellidos del postulante.');
        }
        $concepts = array(
            'inscripcion' => 'Derecho de inscripción',
            'reinscripcion' => 'Reinscripción del examen extraordinario al examen ordinario',
            'constancia' => 'Constancia de ingreso',
        );
        if (!isset($concepts[$conceptKey])) {
            throw new InvalidArgumentException('El concepto de pago no es valido.');
        }
        $school = $this->cleanText($input['escuela'] ?? '', 180);
        $schools = array(
            'Ingeniería y Gestión Ambiental',
            'Ingeniería de Negocios Agronómicos y Forestales',
            'Administración de Turismo Sostenible y Hotelería',
            'Ingeniería Civil',
            'Ingeniería de Sistemas',
        );
        if ($conceptKey !== 'constancia' && !in_array($school, $schools, true)) {
            throw new InvalidArgumentException('Seleccione el programa de estudio.');
        }
        $modality = 'No aplica';
        $origin = 'No aplica';
        $period = 'No aplica';
        if ($conceptKey === 'inscripcion') {
            $modalities = $this->modalities();
            $modalityKey = (string) ($input['modalidad_key'] ?? '');
            if (!isset($modalities[$modalityKey])) {
                throw new InvalidArgumentException('La modalidad seleccionada no es valida.');
            }
            $modality = $modalities[$modalityKey];
            $origin = ($input['procedencia'] ?? '') === 'estatal' ? 'Estatal' : 'Particular';
            $period = ($input['periodo'] ?? '') === 'regular' ? 'Regular' : 'Rezagado';
        }
        $surnames = trim($paternal . ' ' . $maternal);
        $applicantName = trim($surnames . ' ' . $givenNames);
        if ($surnames === '') {
            $surnames = 'No consignado';
        }
        return array(
            'email' => $email,
            'applicant_name' => $applicantName,
            'surnames' => $surnames,
            'given_names' => $givenNames,
            'dni' => $dni,
            'phone' => $phone,
            'school' => $conceptKey === 'constancia' ? 'No aplica' : $school,
            'concept_key' => $conceptKey,
            'concept' => $concepts[$conceptKey],
            'modality' => $modality,
            'origin' => $origin,
            'period' => $period,
            'amount' => (float) $amount,
            'due_date' => $dueDate,
        );
    }

    private function officialAmount(array $input)
    {
        $concept = (string) ($input['concepto_key'] ?? '');
        if ($concept === 'reinscripcion') return 50.0;
        if ($concept === 'constancia') return 15.0;
        if ($concept !== 'inscripcion') return null;
        $rates = array(
            'ORD_EGRESADO' => array(array(170, 200), array(200, 230)),
            'ORD_QUINTO' => array(array(170, 200), array(200, 230)),
            'ORD_1_4' => array(array(50, 50), array(80, 80)),
            'EXT_PRIMEROS' => array(array(170, 200), array(200, 230)),
            'EXT_BECA18' => array(array(170, 200), array(200, 230)),
            'EXT_CONVENIOS' => array(array(170, 200), array(200, 230)),
            'EXT_DISCAPACIDAD' => array(array(85, 100), array(115, 130)),
            'EXT_VIOLENCIA' => array(array(20, 20), array(null, null)),
            'EXT_TITULADOS' => array(array(270, 400), array(300, 430)),
            'EXT_DEPORTISTAS' => array(array(170, 200), array(200, 230)),
            'EXT_SERVICIO' => array(array(85, 100), array(115, 130)),
            'EXT_TRASLADO_INT' => array(array(230, null), array(260, null)),
            'EXT_TRASLADO_EXT' => array(array(270, 400), array(300, 430)),
        );
        $period = ($input['periodo'] ?? '') === 'regular' ? 0 : (($input['periodo'] ?? '') === 'rezagado' ? 1 : null);
        $origin = ($input['procedencia'] ?? '') === 'estatal' ? 0 : (($input['procedencia'] ?? '') === 'particular' ? 1 : null);
        $modality = (string) ($input['modalidad_key'] ?? '');
        if ($period === null || $origin === null || !isset($rates[$modality])) return null;
        return $rates[$modality][$period][$origin];
    }

    private function modalities(): array
    {
        return array(
            'ORD_EGRESADO' => 'Egresados de secundaria',
            'ORD_QUINTO' => 'Quinto de secundaria',
            'ORD_1_4' => 'Primero a cuarto de secundaria',
            'EXT_PRIMEROS' => 'Primeros puestos',
            'EXT_BECA18' => 'Beca 18',
            'EXT_CONVENIOS' => 'Convenios con comunidades campesinas y nativas',
            'EXT_DISCAPACIDAD' => 'Personas con discapacidad',
            'EXT_VIOLENCIA' => 'Afectados por violencia sociopolítica / víctimas del terrorismo',
            'EXT_TITULADOS' => 'Titulados o graduados',
            'EXT_DEPORTISTAS' => 'Deportistas destacados (calificados y/o alto nivel)',
            'EXT_SERVICIO' => 'Servicio militar',
            'EXT_TRASLADO_INT' => 'Traslado interno',
            'EXT_TRASLADO_EXT' => 'Traslado externo',
        );
    }

    private function cleanText($value, int $maxLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string) $value));
        $value = preg_replace('/\s+/u', ' ', $value === null ? '' : $value);
        return mb_substr($value === null ? '' : $value, 0, $maxLength, 'UTF-8');
    }

    private function sign(string $id, int $expires): string
    {
        return hash_hmac('sha256', $id . '|' . $expires, $this->secret);
    }

    private function log(string $id, string $status, $detail)
    {
        $record = array('time' => gmdate(DATE_ATOM), 'order_id' => $id, 'mail_status' => $status);
        if ($detail) $record['technical_detail'] = str_replace(array("\r", "\n"), ' ', mb_substr((string) $detail, 0, 500, 'UTF-8'));
        $line = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @error_log($line . PHP_EOL, 3, $this->logFile);
            @chmod($this->logFile, 0600);
        }
    }
}
