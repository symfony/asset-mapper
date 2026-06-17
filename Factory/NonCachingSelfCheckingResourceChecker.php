<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Factory;

use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Config\Resource\SelfCheckingResourceInterface;
use Symfony\Component\Config\ResourceCheckerInterface;

/**
 * Avoids process-wide freshness memoization for dev asset caches.
 *
 * @internal
 */
final class NonCachingSelfCheckingResourceChecker implements ResourceCheckerInterface
{
    public function supports(ResourceInterface $metadata): bool
    {
        return $metadata instanceof SelfCheckingResourceInterface;
    }

    /**
     * @param SelfCheckingResourceInterface $resource
     */
    public function isFresh(ResourceInterface $resource, int $timestamp): bool
    {
        return $resource->isFresh($timestamp);
    }
}
