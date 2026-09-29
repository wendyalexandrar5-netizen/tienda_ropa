<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cliente HTTP mínimo (cURL) para comunicarse con Supabase Auth y Storage.
 */
final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: mixed}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[http] $method $url falló: $error");
            throw new HttpException(503, 'SERVICIO_NO_DISPONIBLE', 'No fue posible comunicarse con el servicio de autenticación. Inténtalo más tarde.');
        }

        $json = null;
        if ($response !== '') {
            try {
                $json = json_decode((string) $response, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $json = null;
            }
        }

        return ['status' => $status, 'body' => (string) $response, 'json' => $json];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: mixed}
     */
    public function json(string $method, string $url, array $headers = [], mixed $payload = null): array
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        return $this->request($method, $url, $headers, $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
