<?php

declare(strict_types=1);

final class OpenAiDniServiceException extends RuntimeException
{
}

interface OpenAiDniHttpClient
{
    /** @return array{status:int, body:string} */
    public function postJson(string $url, array $headers, string $body, int $timeout): array;
}

final class CurlOpenAiDniHttpClient implements OpenAiDniHttpClient
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
            error_log('OpenAI connection error: ' . $message);
            throw new OpenAiDniServiceException('No se pudo establecer una conexión segura con OpenAI.');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return array('status' => $status, 'body' => (string) $response);
    }
}

final class OpenAiDniReader
{
    const ENDPOINT = 'https://api.openai.com/v1/responses';

    private $client;
    private $apiKey;
    private $model;
    private $timeout;

    public function __construct(OpenAiDniHttpClient $client, string $apiKey, string $model, int $timeout = 120)
    {
        $apiKey = trim($apiKey);
        $model = trim($model);
        if ($apiKey === '') {
            throw new InvalidArgumentException('La lectura con OpenAI no está configurada.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $model)) {
            throw new InvalidArgumentException('El modelo de OpenAI configurado no es válido.');
        }
        $this->client = $client;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = max(20, min(180, $timeout));
    }

    /** @return array{dni:?string,apellido_paterno:?string,apellido_materno:?string,nombres:?string} */
    public function read(string $mimeType, string $imageBytes, string $safetyIdentifier = ''): array
    {
        if (!in_array($mimeType, array('image/jpeg', 'image/png', 'image/webp'), true)) {
            throw new InvalidArgumentException('El formato de imagen no está permitido.');
        }
        if ($imageBytes === '') {
            throw new InvalidArgumentException('La imagen está vacía.');
        }

        $payload = $this->payload($mimeType, $imageBytes, $safetyIdentifier);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('No se pudo preparar la solicitud de lectura.');
        }

        $response = $this->client->postJson(self::ENDPOINT, array(
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
        ), $encoded, $this->timeout);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new OpenAiDniServiceException($this->serviceErrorMessage(
                (int) $response['status'],
                (string) $response['body']
            ));
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('OpenAI devolvió una respuesta no válida.');
        }
        if (isset($body['error']) && is_array($body['error'])) {
            throw new RuntimeException('OpenAI no pudo procesar la imagen.');
        }

        $outputText = $this->outputText($body);
        $data = json_decode($outputText, true);
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

    private function payload(string $mimeType, string $imageBytes, string $safetyIdentifier): array
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
            'En el DNI amarillo: el apellido_paterno es el valor inmediatamente debajo de "Primer Apellido"; el apellido_materno es el valor inmediatamente debajo de "Segundo Apellido"; nombres es el valor inmediatamente debajo de "Pre Nombres".',
            'El DNI son exactamente los 8 dígitos impresos junto a la etiqueta DNI. Excluye el dígito verificador que aparece después del guion.',
            'No confundas fechas, ubigeo, número de tarjeta, texto vertical, etiquetas, anotaciones ni flechas con los cuatro valores solicitados.',
            'Usa la MRZ solo para verificar o recuperar un valor ilegible. La MRZ puede omitir el segundo apellido, por lo que nunca debes copiar el primero como segundo.',
            'En el DNI electrónico: toma los prenombres debajo de "Prenombres" y divide los apellidos impresos bajo "Apellidos" respetando su orden; conserva unidos los apellidos compuestos.',
            'Devuelve apellidos y nombres en mayúsculas, sin títulos ni etiquetas.',
        ));
        $payload = array(
            'model' => $this->model,
            'store' => false,
            'reasoning' => array('effort' => 'none'),
            'instructions' => $instructions,
            'input' => array(array(
                'role' => 'user',
                'content' => array(
                    array('type' => 'input_text', 'text' => 'Extrae únicamente DNI, apellido paterno, apellido materno y prenombres de esta imagen.'),
                    array(
                        'type' => 'input_image',
                        'detail' => 'original',
                        'image_url' => 'data:' . $mimeType . ';base64,' . base64_encode($imageBytes),
                    ),
                ),
            )),
            'text' => array('format' => array(
                'type' => 'json_schema',
                'name' => 'dni_peru_basico',
                'strict' => true,
                'schema' => $schema,
            )),
            'max_output_tokens' => 600,
        );
        if (preg_match('/^[a-f0-9]{64}$/', $safetyIdentifier)) {
            $payload['safety_identifier'] = $safetyIdentifier;
        }
        return $payload;
    }

    private function serviceErrorMessage(int $status, string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);
        $error = is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])
            ? $decoded['error'] : array();
        $code = strtolower((string) ($error['code'] ?? ''));
        $type = strtolower((string) ($error['type'] ?? ''));

        if ($status === 401 || $code === 'invalid_api_key') {
            return 'La API key de OpenAI no es válida o fue revocada.';
        }
        if ($status === 429 && ($code === 'credit_balance_exhausted' || $type === 'insufficient_quota')) {
            return 'La cuenta de OpenAI no tiene créditos disponibles. Agregue saldo en la facturación de la API.';
        }
        if ($status === 429) {
            return 'OpenAI alcanzó temporalmente el límite de solicitudes. Intente nuevamente en un momento.';
        }
        if ($status === 400 || $status === 404) {
            return 'OpenAI rechazó la configuración del modelo o de la solicitud.';
        }
        if ($status >= 500) {
            return 'OpenAI no está disponible temporalmente.';
        }
        return 'OpenAI no pudo procesar la imagen.';
    }

    private function outputText(array $response): string
    {
        foreach ($response['output'] ?? array() as $output) {
            if (!is_array($output) || ($output['type'] ?? '') !== 'message') {
                continue;
            }
            foreach ($output['content'] ?? array() as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'output_text'
                    && isset($content['text']) && is_string($content['text'])) {
                    return $content['text'];
                }
            }
        }
        throw new RuntimeException('OpenAI no devolvió datos del DNI.');
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
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }
        $name = preg_replace('/[^\p{L}\s\'’-]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', trim((string) $name));
        if ($name === '') {
            return null;
        }
        $name = mb_strtoupper($name, 'UTF-8');
        return mb_substr($name, 0, 100, 'UTF-8');
    }
}
