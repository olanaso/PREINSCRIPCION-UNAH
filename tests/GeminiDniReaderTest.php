<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GeminiDniReader.php';

final class FakeGeminiDniHttpClient implements GeminiDniHttpClient
{
    public $status = 200;
    public $responseBody = '';
    public $url = '';
    public $headers = array();
    public $requestBody = '';
    public $timeout = 0;

    public function postJson(string $url, array $headers, string $body, int $timeout): array
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->requestBody = $body;
        $this->timeout = $timeout;
        return array('status' => $this->status, 'body' => $this->responseBody);
    }
}

$client = new FakeGeminiDniHttpClient();
$structuredResult = json_encode(array(
    'dni' => '92655483',
    'apellido_paterno' => 'Escalante',
    'apellido_materno' => 'Rivera',
    'nombres' => 'Dara Nayeli',
), JSON_UNESCAPED_UNICODE);
$client->responseBody = json_encode(array(
    'id' => 'int_test',
    'status' => 'completed',
    'steps' => array(array(
        'type' => 'model_output',
        'content' => array(array('type' => 'text', 'text' => $structuredResult)),
    )),
));

$reader = new GeminiDniReader($client, 'AIza-test-only', 'gemini-3.7-flash', 90);
$result = $reader->read('image/jpeg', 'fake-image-bytes');
assert($result === array(
    'dni' => '92655483',
    'apellido_paterno' => 'ESCALANTE',
    'apellido_materno' => 'RIVERA',
    'nombres' => 'DARA NAYELI',
));
assert($client->url === 'https://generativelanguage.googleapis.com/v1beta/interactions');
assert($client->timeout === 90);
assert(in_array('x-goog-api-key: AIza-test-only', $client->headers, true));
assert(in_array('Api-Revision: 2026-05-20', $client->headers, true));

$payload = json_decode($client->requestBody, true);
assert($payload['model'] === 'gemini-3.7-flash');
assert($payload['store'] === false);
assert($payload['generation_config']['thinking_level'] === 'low');
assert($payload['generation_config']['max_output_tokens'] === 600);
assert($payload['input'][1]['type'] === 'image');
assert($payload['input'][1]['mime_type'] === 'image/jpeg');
assert($payload['input'][1]['data'] === base64_encode('fake-image-bytes'));
assert($payload['response_format']['type'] === 'text');
assert($payload['response_format']['mime_type'] === 'application/json');
assert($payload['response_format']['schema']['additionalProperties'] === false);
assert(strpos($payload['system_instruction'], 'inmediatamente debajo de "Primer Apellido"') !== false);

$client->status = 429;
$client->responseBody = json_encode(array('error' => array(
    'code' => 429,
    'status' => 'RESOURCE_EXHAUSTED',
)));
try {
    $reader->read('image/png', 'fake-image-bytes');
    assert(false, 'Una respuesta de cuota debe lanzar una excepción.');
} catch (GeminiDniServiceException $error) {
    assert(strpos($error->getMessage(), 'cuota') !== false);
}

try {
    new GeminiDniReader(new FakeGeminiDniHttpClient(), '', 'gemini-3.7-flash');
    assert(false, 'La API key vacía debe rechazarse.');
} catch (InvalidArgumentException $error) {
    assert(strpos($error->getMessage(), 'no está configurada') !== false);
}

echo "GeminiDniReaderTest: OK\n";
