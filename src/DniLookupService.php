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
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );
        $caBundle = getenv('SSL_CA_BUNDLE') ?: dirname(__DIR__) . '/config/cacert.pem';
        if (is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Error al consultar el DNI: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return array('status' => $status, 'body' => $body);
    }
}

final class DniLookupService
{
    const ENDPOINT = 'https://qellqa.regioncusco.gob.pe/api/virtual/mesa-partes/persona/consultar';
    private $client;

    public function __construct(DniHttpClient $client)
    {
        $this->client = $client;
    }

    /** @return array{dni:string,nombres:string,apellido_paterno:string,apellido_materno:string} */
    public function lookup(string $dni): array
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            throw new InvalidArgumentException('Ingrese un DNI válido de 8 dígitos.');
        }

        $endpoint = getenv('DNI_LOOKUP_ENDPOINT') ?: self::ENDPOINT;
        $url = $endpoint . '?' . http_build_query(array('tipoDocumento' => 'DNI', 'nroDocumento' => $dni));
        $response = $this->client->get($url, array(
            'Accept: application/json, text/plain, */*',
            'Accept-Language: es-ES,es;q=0.9,ar;q=0.8',
            'Authorization: Bearer',
            'Cache-Control: no-cache',
            'Origin: https://qellqavirtual.regioncusco.gob.pe',
            'Pragma: no-cache',
            'Referer: https://qellqavirtual.regioncusco.gob.pe/',
            'User-Agent: UNAH-Preinscripcion/1.0',
        ));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('El servicio de consulta de DNI no está disponible.');
        }

        $payload = json_decode($response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            throw new RuntimeException('El servicio de DNI devolvió una respuesta inválida.');
        }
        $person = $this->findPerson($payload);
        $names = $this->value($person, array('nombres', 'nombre', 'prenombres'));
        $paternal = $this->value($person, array('apellidoPaterno', 'apellido_paterno', 'apePaterno', 'primerApellido'));
        $maternal = $this->value($person, array('apellidoMaterno', 'apellido_materno', 'apeMaterno', 'segundoApellido'));
        if ($names === '' || ($paternal === '' && $maternal === '')) {
            throw new InvalidArgumentException('No se encontraron datos para el DNI ingresado.');
        }

        return array('dni' => $dni, 'nombres' => $names, 'apellido_paterno' => $paternal, 'apellido_materno' => $maternal);
    }

    private function findPerson(array $payload): array
    {
        foreach (array('persona', 'data', 'resultado', 'result', 'objeto') as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->findPerson($payload[$key]);
            }
        }
        return $payload;
    }

    private function value(array $person, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($person[$key]) && is_scalar($person[$key])) {
                $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string) $person[$key]));
                return mb_substr($value === null ? '' : $value, 0, 100, 'UTF-8');
            }
        }
        return '';
    }
}
