<?php
/**
 * @license MIT
 *
 * Modified by dav-neuland on 29-September-2026 using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace DavMlm\Vendor\GuzzleHttp\Psr7;

/**
 * @internal
 */
trait NonSerializableStreamTrait
{
    public function __serialize(): array
    {
        throw new \LogicException(static::class.' should never be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException(static::class.' should never be unserialized');
    }
}
