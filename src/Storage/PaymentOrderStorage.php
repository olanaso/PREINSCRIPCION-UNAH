<?php

declare(strict_types=1);

namespace Unah\Storage;

use RuntimeException;

final class PaymentOrderStorage
{
    public const RETENTION_SECONDS = 86400;

    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unah-payment-orders';
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento temporal.');
        }
    }

    /** @return array{token: string, expires_at: int} */
    public function store(string $content, string $filename): array
    {
        $this->purgeExpired();
        $token = bin2hex(random_bytes(32));
        $key = hash('sha256', $token);
        $expiresAt = time() + self::RETENTION_SECONDS;

        $pdfPath = $this->path($key, 'pdf');
        $metadataPath = $this->path($key, 'json');
        $metadata = json_encode(['filename' => $filename, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR);

        if (file_put_contents($pdfPath, $content, LOCK_EX) === false
            || file_put_contents($metadataPath, $metadata, LOCK_EX) === false) {
            @unlink($pdfPath);
            @unlink($metadataPath);
            throw new RuntimeException('No se pudo guardar la orden temporal.');
        }
        chmod($pdfPath, 0600);
        chmod($metadataPath, 0600);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** @return array{content: string, filename: string}|null */
    public function retrieve(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $this->purgeExpired();
        $key = hash('sha256', $token);
        $metadataPath = $this->path($key, 'json');
        $pdfPath = $this->path($key, 'pdf');
        if (!is_file($metadataPath) || !is_file($pdfPath)) {
            return null;
        }

        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (!is_array($metadata) || ($metadata['expires_at'] ?? 0) < time()) {
            @unlink($metadataPath);
            @unlink($pdfPath);
            return null;
        }

        $content = file_get_contents($pdfPath);
        return $content === false ? null : [
            'content' => $content,
            'filename' => basename((string) ($metadata['filename'] ?? 'orden-de-pago.pdf')),
        ];
    }

    public function purgeExpired(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $metadataPath) {
            $metadata = json_decode((string) @file_get_contents($metadataPath), true);
            if (!is_array($metadata) || ($metadata['expires_at'] ?? 0) < time()) {
                $key = pathinfo($metadataPath, PATHINFO_FILENAME);
                @unlink($this->path($key, 'pdf'));
                @unlink($metadataPath);
            }
        }
    }

    private function path(string $key, string $extension): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $key . '.' . $extension;
    }
}
