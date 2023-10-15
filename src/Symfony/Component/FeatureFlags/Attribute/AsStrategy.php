<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureFlags\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsStrategy
{
    public function __construct(
        public readonly string|null $feature = null,
        public readonly string|null $description = null,
        public readonly string|null $method = null,
    ) {
    }
}
