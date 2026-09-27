<?php

namespace Bitrix24\Mcp\Cloud;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// Exceptions are loaded together through the explicit Cloud class map.
class CloudException extends \RuntimeException
{
}
class ConfigurationException extends CloudException
{
}
class AuthenticationException extends CloudException
{
}
class AuthorizationException extends CloudException
{
}
class ValidationException extends CloudException
{
}
class RateLimitException extends CloudException
{
}
class TransientHttpException extends CloudException
{
}
class BitrixApiException extends CloudException
{
}
// phpcs:enable PSR1.Classes.ClassDeclaration.MultipleClasses
