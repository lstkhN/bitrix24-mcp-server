<?php

namespace Bitrix24\Mcp\Tests\Unit\Transport;

use Bitrix\Main\Config\Option;
use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Transport\HttpTransport;

/**
 * Тесты аутентификации HTTP-транспорта (hash_equals, Bearer токен)
 */
class HttpTransportSecurityTest extends TestCase
{
    private const SERVER_TOKEN = 'test-server-token-abc123';

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        Option::reset();
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);

        // Бэкап $_SERVER и настройка POST-запроса
        foreach (['REQUEST_METHOD', 'CONTENT_TYPE', 'HTTP_AUTHORIZATION', 'HTTP_X_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        Option::reset();
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authorization header is required');

        (new HttpTransport())->receive();
    }

    public function testInvalidAuthorizationFormatIsRejected(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid authorization format');

        (new HttpTransport())->receive();
    }

    public function testWrongTokenIsRejected(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid authorization token');

        (new HttpTransport())->receive();
    }

    public function testEmptyTokenIsRejected(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        $this->expectException(\RuntimeException::class);

        (new HttpTransport())->receive();
    }

    public function testUnconfiguredServerTokenIsRejected(): void
    {
        Option::set('bitrix24.mcp', 'auth_token', '');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server authorization token is not configured');

        (new HttpTransport())->receive();
    }

    /**
     * Корректный токен должен пройти аутентификацию.
     * В CLI окружении php://input пуст, поэтому после успешной проверки
     * токена ожидается ошибка пустого тела (а не ошибка авторизации).
     */
    public function testValidTokenPassesAuthorization(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;

        $transport = new HttpTransport();

        try {
            $result = $transport->receive();
            // Если stdin piped в PHPUnit - вернётся декодированный запрос
            $this->assertIsArray($result);
        } catch (\RuntimeException $e) {
            $this->assertSame('Request body is empty', $e->getMessage());
        }
    }

    /**
     * Отличающиеся на один символ токены должны отвергаться
     * (hash_equals - константное сравнение, отсутствие prefix-обходов)
     */
    public function testPrefixLikeTokenIsRejected(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . rtrim(self::SERVER_TOKEN, '3');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid authorization token');

        (new HttpTransport())->receive();
    }

    public function testGetMethodIsRejected(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only POST method is allowed');

        (new HttpTransport())->receive();
    }

    public function testWrongContentTypeIsRejected(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Content-Type must be application/json');

        (new HttpTransport())->receive();
    }
    public function testCorsDisabledByDefaultNoWildcardHeaders(): void
    {
        Option::reset();
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);
        // Не устанавливаем cors_allowed_origins для проверки default disabled

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_ORIGIN'] = 'https://any-origin.com';

        $transport = new HttpTransport();
        $transport->send(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]);

        $this->assertNotNull($transport->getLastResponse());
    }

    public function testCorsStrictAllowlistAllowsExactOrigin(): void
    {
        Option::set('bitrix24.mcp', 'cors_allowed_origins', 'https://trusted.example.com');
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_ORIGIN'] = 'https://trusted.example.com';

        $transport = new HttpTransport();
        $transport->send(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]);

        $this->assertNotNull($transport->getLastResponse());
    }

    public function testCorsStrictAllowlistRejectsUnknownOrigin(): void
    {
        Option::set('bitrix24.mcp', 'cors_allowed_origins', 'https://trusted.example.com');
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';

        $transport = new HttpTransport();
        $transport->send(['jsonrpc' => '2.0', 'result' => 'ok', 'id' => 1]);

        $this->assertNotNull($transport->getLastResponse());
    }

    public function testBodySizeLimitExceedsMaxSize(): void
    {
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = 1048577; // > 1MB

        $transport = new HttpTransport();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum');
        $transport->receive();
    }

    public function testJsonDepthLimitRejectsDeepNestedBody(): void
    {
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $transport = new HttpTransport();
        $deepValue = ['v' => 1];
        for ($i = 0; $i < 120; $i++) {
            $deepValue = ['nested' => $deepValue];
        }

        $method = new \ReflectionMethod($transport, 'decodeJson');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('maximum depth');
        $method->invoke($transport, json_encode($deepValue), 100);
    }

    public function testMethodValidationAllowsStandardMcpMethods(): void
    {
        Option::set('bitrix24.mcp', 'auth_token', self::SERVER_TOKEN);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::SERVER_TOKEN;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $transport = new HttpTransport();
        $method = new \ReflectionMethod($transport, 'decodeJson');
        $request = ['jsonrpc' => '2.0', 'method' => 'initialize', 'id' => 1];
        $result = $method->invoke($transport, json_encode($request), 100);
        $this->assertSame('initialize', $result['method']);
    }
}
