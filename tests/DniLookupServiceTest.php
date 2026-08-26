<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/DniLookupService.php';

$client = new class implements DniHttpClient {
    public string $url = '';
    public array $headers = [];
    public function get(string $url, array $headers): array
    {
        $this->url = $url;
        $this->headers = $headers;
        return ['status' => 200, 'body' => json_encode(['data' => ['persona' => [
            'nombres' => 'MARÍA ELENA', 'apellidoPaterno' => 'QUISPE', 'apellidoMaterno' => 'FLORES'
        ]]])];
    }
};

$person = (new DniLookupService($client))->lookup('70021899');
assert($person['nombres'] === 'MARÍA ELENA');
assert($person['apellido_paterno'] === 'QUISPE');
assert($person['apellido_materno'] === 'FLORES');
assert(str_contains($client->url, 'tipoDocumento=DNI&nroDocumento=70021899'));
assert(in_array('Authorization: Bearer', $client->headers, true));

try {
    (new DniLookupService($client))->lookup('123');
    assert(false, 'An invalid DNI must be rejected before the request');
} catch (InvalidArgumentException $error) {
    assert($error->getMessage() === 'Ingrese un DNI válido de 8 dígitos.');
}

echo "DniLookupServiceTest: OK\n";
