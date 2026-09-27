<?php

namespace Bitrix24\Mcp\Cloud;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// Transport types are loaded together through the explicit Cloud class map.
final class HttpResponse
{
    public function __construct(public int $status, public string $body, public array $headers = [])
    {
    }
}

interface HttpTransport
{
    public function post(string $url, array $headers, string $body, float $timeout): HttpResponse;
}

final class CurlHttpTransport implements HttpTransport
{
    public function post(string $url, array $headers, string $body, float $timeout): HttpResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransientHttpException('Unable to initialize HTTP client');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int)min($timeout * 1000, 10000),
            CURLOPT_TIMEOUT_MS => (int)max(1, $timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        if ($raw === false) {
            throw new TransientHttpException('HTTP request failed: ' . ($error ?: 'unknown transport error'));
        }

        return new HttpResponse($status, substr($raw, $headerSize));
    }
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MultipleClasses
