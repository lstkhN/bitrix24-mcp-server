<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/** Safe persistence failure; it intentionally does not expose SQL or secrets. */
class OAuthAuthorizationAttemptStoreException extends \RuntimeException
{
}
