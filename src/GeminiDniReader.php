<?php

declare(strict_types=1);

final class GeminiDniServiceException extends RuntimeException
{
}

interface GeminiDniHttpClient
{
    /** @return array{status:int, body:string} */
    public function postJson(string $url, array $headers, string $body, int $timeout): array;
}

final class CurlGeminiDniHttpClient implements GeminiDniHttpClient
{
    public function postJson(string $url, array $headers, string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL no está disponible.');
        }

        $handle = curl_init($url);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );
        $caBundle = getenv('SSL_CA_BUNDLE') ?: dirname(__DIR__) . '/config/cacert.pem';
        if (is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        if ($response === false) {
            $message = curl_error($handle);
            curl_close($handle);
            error_log('Gemini connection error: ' . $message);
            throw new GeminiDniServiceException('No se pudo establecer una conexión segura con Google Gemini.');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return array('status' => $status, 'body' => (string) $response);
    }
}

final class GeminiDniReader
{
    const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';
    const API_REVISION = '2026-05-20';

    private $client;
    private $apiKey;
    private $model;
    private $timeout;

    public function __construct(GeminiDniHttpClient $client, string $apiKey, string $model, int $timeout = 120)
    {
        $apiKey = trim($apiKey);
        $model = trim($model);
        if ($apiKey === '') {
            throw new InvalidArgumentException('La lectura con Google Gemini no está configurada.');
        }
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $model)) {
            throw new InvalidArgumentException('El modelo de Google Gemini configurado no es válido.');
        }
        $this->client = $client;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = max(20, min(180, $timeout));
    }

    /** @return array{dni:?string,apellido_paterno:?string,apellido_materno:?string,nombres:?string} */
    public function read(string $mimeType, string $imageBytes): array
    {
        if (!in_array($mimeType, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            throw new InvalidArgumentException('El formato de imagen no está permitido.');
        }
        if ($imageBytes === '') {
            throw new InvalidArgumentException('La imagen está vacía.');
        }

        $encoded = json_encode($this->payload($mimeType, $imageBytes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('No se pudo preparar la solicitud de lectura.');
        }

        $response = $this->client->postJson(self::ENDPOINT, array(
            'x-goog-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
            'Api-Revision: ' . self::API_REVISION,
        ), $encoded, $this->timeout);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new GeminiDniServiceException($this->serviceErrorMessage(
                (int) $response['status'],
                (string) $response['body']
            ));
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Google Gemini devolvió una respuesta no válida.');
        }
        if (isset($body['error']) && is_array($body['error'])) {
            throw new GeminiDniServiceException('Google Gemini no pudo procesar la imagen.');
        }

        $data = json_decode($this->outputText($body), true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('La lectura estructurada del DNI no es válida.');
        }

        $result = array(
            'dni' => $this->normalizeDni($data['dni'] ?? null),
            'apellido_paterno' => $this->normalizeName($data['apellido_paterno'] ?? null),
            'apellido_materno' => $this->normalizeName($data['apellido_materno'] ?? null),
            'nombres' => $this->normalizeName($data['nombres'] ?? null),
        );
        if ($result['dni'] === null && $result['apellido_paterno'] === null
            && $result['apellido_materno'] === null && $result['nombres'] === null) {
            throw new InvalidArgumentException('No se pudieron distinguir datos personales en la imagen.');
        }
        return $result;
    }

    private function payload(string $mimeType, string $imageBytes): array
    {
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'dni' => array('type' => array('string', 'null')),
                'apellido_paterno' => array('type' => array('string', 'null')),
                'apellido_materno' => array('type' => array('string', 'null')),
                'nombres' => array('type' => array('string', 'null')),
            ),
            'required' => array('dni', 'apellido_paterno', 'apellido_materno', 'nombres'),
            'additionalProperties' => false,
        );
        $instructions = implode("\n", array(
            'Eres un transcriptor exacto de documentos de identidad peruanos. No inventes ni completes datos dudosos.',
            'Devuelve null cuando un valor no sea claramente visible.',
            'En el DNI amarillo: apellido_paterno es el valor inmediatamente debajo de "Primer Apellido"; apellido_materno es el valor inmediatamente debajo de "Segundo Apellido"; nombres es el valor inmediatamente debajo de "Pre Nombres".',
            'El DNI son exactamente los 8 dígitos impresos junto a la etiqueta DNI. Excluye el dígito verificador que aparece después del guion.',
            'No confundas fechas, ubigeo, número de tarjeta, texto vertical, etiquetas, anotaciones ni flechas con los cuatro valores solicitados.',
            'Usa la MRZ solo para verificar o recuperar un valor ilegible. La MRZ puede omitir el segundo apellido; nunca copies el primero como segundo.',
            'En el DNI electrónico: toma los prenombres debajo de "Prenombres" y divide los apellidos impresos bajo "Apellidos" respetando su orden; conserva unidos los apellidos compuestos.',
            'Devuelve apellidos y nombres en mayúsculas, sin títulos ni etiquetas.',
        ));

        return array(
            'model' => $this->model,
            'store' => false,
            'system_instruction' => $instructions,
            'input' => array(
                array('type' => 'text', 'text' => 'Extrae únicamente DNI, apellido paterno, apellido materno y prenombres de esta imagen.'),
                array(
                    'type' => 'image',
                    'data' => base64_encode($imageBytes),
                    'mime_type' => $mimeType,
                ),
            ),
            'response_format' => array(
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $schema,
            ),
            'generation_config' => array(
                'thinking_level' => 'low',
                'max_output_tokens' => 600,
            ),
        );
    }

    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])
            && trim($response['output_text']) !== '') {
            return $response['output_text'];
        }
        foreach (array_reverse($response['steps'] ?? array()) as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach ($step['content'] ?? array() as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'text'
                    && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
        foreach (array_reverse($response['outputs'] ?? array()) as $output) {
            if (is_array($output) && ($output['type'] ?? '') === 'text'
                && isset($output['text']) && is_string($output['text'])) {
                return $output['text'];
            }
        }
        throw new RuntimeException('Google Gemini no devolvió datos del DNI.');
    }

    private function serviceErrorMessage(int $status, string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error'] : array();
        $googleStatus = strtoupper((string) ($error['status'] ?? ''));

        if ($status === 401 || $googleStatus === 'UNAUTHENTICATED') {
            return 'La API key de Google Gemini no es válida o fue revocada.';
        }
        if ($status === 403 || $googleStatus === 'PERMISSION_DENIED') {
            return 'La API key no tiene permiso para usar Gemini o la API no está habilitada.';
        }
        if ($status === 429 || $googleStatus === 'RESOURCE_EXHAUSTED') {
            return 'Google Gemini alcanzó la cuota disponible. Revise la facturación o los límites de la API.';
        }
        if ($status === 400 || $googleStatus === 'INVALID_ARGUMENT') {
            return 'Google Gemini rechazó la configuración del modelo o de la solicitud.';
        }
        if ($status === 404 || $googleStatus === 'NOT_FOUND') {
            return 'El modelo de Google Gemini configurado no está disponible.';
        }
        if ($status >= 500) {
            return 'Google Gemini no está disponible temporalmente.';
        }
        return 'Google Gemini no pudo procesar la imagen.';
    }

    private function normalizeDni($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $value);
        return is_string($digits) && preg_match('/^\d{8}$/', $digits) ? $digits : null;
    }

    private function normalizeName($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $name = preg_replace('/[^\p{L}\s\'’-]+/u', ' ', trim((string) $value));
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        if (!is_string($name) || $name === '') {
            return null;
        }
        $name = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
        return function_exists('mb_substr') ? mb_substr($name, 0, 100, 'UTF-8') : substr($name, 0, 100);
    }
}
