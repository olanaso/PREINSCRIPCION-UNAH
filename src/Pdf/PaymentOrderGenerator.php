<?php

declare(strict_types=1);

namespace Unah\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class PaymentOrderGenerator
{
    public function __construct(private readonly string $templatePath)
    {
    }

    /**
     * @param array<string, scalar|null> $data
     * @return array{content: string, filename: string}
     */
    public function generate(array $data): array
    {
        if (!is_file($this->templatePath)) {
            throw new RuntimeException('No se encontró la plantilla de la orden de pago.');
        }

        ob_start();
        require $this->templatePath;
        $html = ob_get_clean();

        if ($html === false) {
            throw new RuntimeException('No se pudo renderizar la plantilla de la orden de pago.');
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $code = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($data['codigo'] ?? 'orden'));
        $code = trim((string) $code, '-_') ?: 'orden';

        return [
            'content' => $dompdf->output(),
            'filename' => 'esquela-' . substr($code, 0, 80) . '.pdf',
        ];
    }
}
