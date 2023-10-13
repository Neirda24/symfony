<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureFlags\Provider;

use Symfony\Component\FeatureFlags\Feature;
use function array_key_exists;
use function array_keys;

final class LazyInMemoryProvider implements ProviderInterface
{
    /** @var array<string, \Closure> $features */
    private array $features = [];

    public function add(string $featureName, \Closure $feature): void
    {
        $this->features[$featureName] = $feature;
    }

    public function get(string $featureName): mixed
    {
        if (!array_key_exists($featureName, $this->features)) {
            return null;
        }

        return ($this->features[$featureName])();
    }

    public function names(): array
    {
        return array_keys($this->features);
    }
}
