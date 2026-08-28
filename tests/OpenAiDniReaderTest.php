<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/OpenAiDniReader.php';

final class FakeOpenAiDniHttpClient implements OpenAiDniHttpClient
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

$client = new FakeOpenAiDniHttpClient();
$structuredResult = json_encode(array(
    'dni' => '92655483',
    'apellido_paterno' => 'Escalante',
    'apellido_materno' => 'Rivera',
    'nombres' => 'Dara Nayeli',
), JSON_UNESCAPED_UNICODE);
$client->responseBody = json_encode(array(
    'id' => 'resp_test',
    'status' => 'completed',
    'output' => array(array(
        'type' => 'message',
        'content' => array(array('type' => 'output_text', 'text' => $structuredResult)),
    )),
));

$reader = new OpenAiDniReader($client, 'sk-test-only', 'gpt-5.6', 90);
$result = $reader->read('image/jpeg', 'fake-image-bytes', str_repeat('a', 64));
assert($result === array(
    'dni' => '92655483',
    'apellido_paterno' => 'ESCALANTE',
    'apellido_materno' => 'RIVERA',
    'nombres' => 'DARA NAYELI',
));
assert($client->url === 'https://api.openai.com/v1/responses');
assert($client->timeout === 90);
assert(in_array('Authorization: Bearer sk-test-only', $client->headers, true));

$payload = json_decode($client->requestBody, true);
assert($payload['model'] === 'gpt-5.6');
assert($payload['store'] === false);
assert($payload['reasoning']['effort'] === 'none');
assert($payload['safety_identifier'] === str_repeat('a', 64));
assert($payload['input'][0]['content'][1]['type'] === 'input_image');
assert($payload['input'][0]['content'][1]['detail'] === 'original');
assert(strpos($payload['input'][0]['content'][1]['image_url'], 'data:image/jpeg;base64,') === 0);
assert($payload['text']['format']['type'] === 'json_schema');
assert($payload['text']['format']['strict'] === true);
assert($payload['text']['format']['schema']['additionalProperties'] === false);
assert(strpos($payload['instructions'], 'inmediatamente debajo de "Primer Apellido"') !== false);

$client->status = 429;
$client->responseBody = json_encode(array('error' => array(
    'type' => 'insufficient_quota',
    'code' => 'credit_balance_exhausted',
)));
try {
    $reader->read('image/png', 'fake-image-bytes');
    assert(false, 'Una respuesta HTTP de error debe lanzar una excepción.');
} catch (OpenAiDniServiceException $error) {
    assert(strpos($error->getMessage(), 'créditos') !== false);
}

try {
    new OpenAiDniReader(new FakeOpenAiDniHttpClient(), '', 'gpt-5.6');
    assert(false, 'La API key vacía debe rechazarse.');
} catch (InvalidArgumentException $error) {
    assert(strpos($error->getMessage(), 'no está configurada') !== false);
}

echo "OpenAiDniReaderTest: OK\n";
