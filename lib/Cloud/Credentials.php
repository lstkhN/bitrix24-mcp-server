<?php

namespace Bitrix24\Mcp\Cloud;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// Credential implementations are loaded together through the explicit Cloud class map.
interface Credential
{
    public function tenantId(): string;
    public function baseUrl(): string;
    public function apply(string $url, array &$body): string;
}

final class WebhookCredential implements Credential
{
    public function __construct(
        private string $tenant,
        private string $portalBaseUrl,
        private int $userId,
        private string $webhookCode,
    ) {
        if ($this->userId < 1 || $this->webhookCode === '') {
            throw new ConfigurationException('Invalid webhook credential');
        }
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }
    public function baseUrl(): string
    {
        return $this->portalBaseUrl;
    }

    public function apply(string $url, array &$body): string
    {
        return rtrim($url, '/') . '/rest/' . $this->userId . '/' . rawurlencode($this->webhookCode);
    }
}

final class OAuthCredential implements Credential
{
    public function __construct(
        private string $tenant,
        private string $portalBaseUrl,
        private string $accessToken,
    ) {
        if ($this->accessToken === '') {
            throw new AuthenticationException('OAuth access token is empty');
        }
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }
    public function baseUrl(): string
    {
        return $this->portalBaseUrl;
    }

    public function apply(string $url, array &$body): string
    {
        // Bitrix24 accepts the OAuth token as the auth request parameter.
        $body['auth'] = $this->accessToken;
        return rtrim($url, '/') . '/rest';
    }
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MultipleClasses
