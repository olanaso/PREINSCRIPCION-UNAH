<?php

declare(strict_types=1);

namespace App\Mail;

use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class PaymentOrderMailer
{
    /** @param array{host:string, port:int, username:string, password:string, encryption:string, from_address:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Envía al postulante su orden de pago en formato PDF.
     *
     * @param array<string, scalar|null> $orderData
     */
    public function send(string $applicantEmail, array $orderData, string $pdfPath): void
    {
        if (filter_var($applicantEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo del postulante no es válido.');
        }

        if (!is_file($pdfPath) || !is_readable($pdfPath)) {
            throw new InvalidArgumentException('No se encontró el PDF de la orden de pago.');
        }

        $mailer = new PHPMailer(true);

        try {
            $this->configureSmtp($mailer);
            $mailer->setFrom($this->config['from_address'], 'Admisiones UNAH');
            $mailer->addAddress($applicantEmail);
            $mailer->addAttachment($pdfPath, 'orden-de-pago.pdf');
            $mailer->isHTML(true);
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->Subject = 'Orden de pago de preinscripción';
            $mailer->Body = $this->buildHtmlBody($orderData);
            $mailer->AltBody = 'Se adjunta su orden de pago de preinscripción en formato PDF.';
            $mailer->send();
        } catch (Throwable) {
            error_log('No fue posible enviar una orden de pago por correo.');

            throw new RuntimeException(
                'No fue posible enviar la orden de pago. Intente nuevamente más tarde.'
            );
        }
    }

    private function configureSmtp(PHPMailer $mailer): void
    {
        $mailer->isSMTP();
        $mailer->SMTPDebug = 0;
        $mailer->Host = $this->config['host'];
        $mailer->Port = $this->config['port'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->config['username'];
        $mailer->Password = $this->config['password'];
        $mailer->SMTPSecure = $this->config['encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
    }

    /** @param array<string, scalar|null> $orderData */
    private function buildHtmlBody(array $orderData): string
    {
        $rows = '';
        foreach ($orderData as $label => $value) {
            $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rows .= "<tr><th style=\"text-align:left;padding:6px\">{$safeLabel}</th>"
                . "<td style=\"padding:6px\">{$safeValue}</td></tr>";
        }

        return '<h1>Orden de pago de preinscripción</h1>'
            . '<p>Estimado(a) postulante:</p>'
            . '<p>Adjuntamos el PDF de su orden de pago. Verifique los siguientes datos:</p>'
            . '<table style="border-collapse:collapse" border="1" cellpadding="0">'
            . $rows
            . '</table>'
            . '<p>Conserve este mensaje y el documento adjunto.</p>';
    }
}
