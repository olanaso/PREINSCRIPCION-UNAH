<?php

declare(strict_types=1);

interface DniHttpClient
{
    /** @return array{status:int, body:string} */
    public function get(string $url, array $headers): array;
}

final class CurlDniHttpClient implements DniHttpClient
{
    public function get(string $url, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL no está disponible.');
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Error al consultar el DNI: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ['status' => $status, 'body' => $body];
    }
}

final class DniLookupService
{
    private const ENDPOINT = 'https://qellqa.regioncusco.gob.pe/api/virtual/mesa-partes/persona/consultar';

    public function __construct(private DniHttpClient $client) {}

    /** @return array{dni:string,nombres:string,apellido_paterno:string,apellido_materno:string} */
    public function lookup(string $dni): array
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            throw new InvalidArgumentException('Ingrese un DNI válido de 8 dígitos.');
        }

        $url = self::ENDPOINT . '?' . http_build_query(['tipoDocumento' => 'DNI', 'nroDocumento' => $dni]);
        $response = $this->client->get($url, [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: es-ES,es;q=0.9,ar;q=0.8',
            'Authorization: Bearer',
            'Cache-Control: no-cache',
            'Origin: https://qellqavirtual.regioncusco.gob.pe',
            'Pragma: no-cache',
            'Referer: https://qellqavirtual.regioncusco.gob.pe/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
            'sec-ch-ua: "Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('El servicio de consulta de DNI no está disponible.');
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        $person = $this->findPerson(is_array($payload) ? $payload : []);
        $names = $this->value($person, ['nombres', 'nombre', 'prenombres']);
        $paternal = $this->value($person, ['apellidoPaterno', 'apellido_paterno', 'apePaterno', 'primerApellido']);
        $maternal = $this->value($person, ['apellidoMaterno', 'apellido_materno', 'apeMaterno', 'segundoApellido']);
        if ($names === '' || ($paternal === '' && $maternal === '')) {
            throw new InvalidArgumentException('No se encontraron datos para el DNI ingresado.');
        }

        return ['dni' => $dni, 'nombres' => $names, 'apellido_paterno' => $paternal, 'apellido_materno' => $maternal];
    }

    private function findPerson(array $payload): array
    {
        foreach (['persona', 'data', 'resultado', 'result', 'objeto'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->findPerson($payload[$key]);
            }
        }
        return $payload;
    }

    private function value(array $person, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($person[$key]) && is_scalar($person[$key])) return trim((string) $person[$key]);
        }
        return '';
    }
}
