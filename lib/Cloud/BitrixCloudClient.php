<?php

namespace Bitrix24\Mcp\Cloud;

final class BitrixCloudClient
{
    /** @var callable(int):void */
    private $sleeper;

    public function __construct(
        private HttpTransport $transport,
        private array $allowedMethods,
        private float $timeout = 10.0,
        private int $maxAttempts = 3,
        ?callable $sleeper = null,
        private array $allowedHosts = [],
    ) {
        if ($this->timeout <= 0 || $this->maxAttempts < 1) {
            throw new ConfigurationException('Invalid HTTP policy');
        }
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function call(Credential $credential, string $method, array $params = []): array
    {
        $this->validateMethod($method);
        $baseUrl = $this->validateBaseUrl($credential->baseUrl());
        $body = $params;
        $root = $credential->apply($baseUrl, $body);
        $url = rtrim($root, '/') . '/' . $method . '.json';
        $payload = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        $attempts = 0;
        $lastResponse = null;

        while ($attempts < $this->maxAttempts) {
            $attempts++;
            try {
                $response = $this->transport->post(
                    $url,
                    ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
                    $payload,
                    $this->timeout
                );
                $lastResponse = $response;
            } catch (TransientHttpException $exception) {
                if ($attempts >= $this->maxAttempts) {
                    throw $exception;
                }
                $this->backoff($attempts);
                continue;
            }

            if ($response->status === 401) {
                throw new AuthenticationException(
                    $this->safeMessage($credential, $method, 'Bitrix24 authentication failed')
                );
            }
            if ($response->status === 403) {
                throw new AuthorizationException(
                    $this->safeMessage($credential, $method, 'Bitrix24 authorization failed')
                );
            }
            if ($response->status === 429) {
                if ($attempts >= $this->maxAttempts) {
                    throw new RateLimitException(
                        $this->safeMessage($credential, $method, 'Bitrix24 rate limit exceeded')
                    );
                }
                $this->backoff($attempts);
                continue;
            }
            if ($response->status >= 500 || $response->status === 408) {
                if ($attempts >= $this->maxAttempts) {
                    throw new TransientHttpException(
                        $this->safeMessage($credential, $method, 'Bitrix24 temporary HTTP failure')
                    );
                }
                $this->backoff($attempts);
                continue;
            }

            return $this->decodeResponse($credential, $method, $response);
        }

        throw new TransientHttpException($this->safeMessage($credential, $method, 'Bitrix24 request failed'));
    }

    private function validateMethod(string $method): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.]*$/i', $method)) {
            throw new ValidationException('Invalid Bitrix24 REST method');
        }
        if (!in_array($method, $this->allowedMethods, true)) {
            throw new AuthorizationException('REST method is not allowed by policy');
        }
    }

    private function validateBaseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $port = is_array($parts) ? ($parts['port'] ?? null) : null;

        if (
            $scheme !== 'https' || $host === '' || ($port !== null && $port !== 443)
            || isset($parts['user'], $parts['pass'])
        ) {
            throw new ConfigurationException(
                'Portal URL must be an HTTPS URL without credentials'
            );
        }

        $allowed = $this->allowedHosts !== []
            ? in_array(strtolower($host), array_map('strtolower', $this->allowedHosts), true)
            : (bool)preg_match('/(^|\.)bitrix24\.[a-z.]+$/i', $host);

        if (!$allowed) {
            throw new ConfigurationException('Portal host is not allowed');
        }

        return rtrim($baseUrl, '/');
    }

    private function decodeResponse(Credential $credential, string $method, HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new BitrixApiException(
                $this->safeMessage($credential, $method, 'Invalid Bitrix24 JSON response')
            );
        }
        if (isset($decoded['error'])) {
            $code = (string)$decoded['error'];
            $description = isset($decoded['error_description'])
                ? (string)$decoded['error_description']
                : 'Bitrix24 REST error';
            throw new BitrixApiException($this->safeMessage($credential, $method, $code . ': ' . $description));
        }

        return [
            'tenant_id' => $credential->tenantId(),
            'method' => $method,
            'status' => $response->status,
            'result' => $decoded['result'] ?? $decoded,
            'total' => $decoded['total'] ?? null,
            'next' => $decoded['next'] ?? null,
        ];
    }

    private function backoff(int $attempt): void
    {
        $milliseconds = min(1000, 100 * (2 ** ($attempt - 1)));
        ($this->sleeper)($milliseconds * 1000);
    }

    private function safeMessage(Credential $credential, string $method, string $message): string
    {
        return sprintf('%s (tenant=%s, method=%s)', $message, $credential->tenantId(), $method);
    }
}
