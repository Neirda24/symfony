<?php

declare(strict_types=1);

namespace Symfony\Bundle\FeatureToggleBundle\Provider;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\Provider\ProviderInterface;
use function array_keys;

final class LazyInMemoryProvider implements ProviderInterface
{
    /**
     * @param array<string, array{description: string, default: bool}> $features
     * @param ServiceLocator $providerLocator
     */
    public function __construct(
        private readonly array $features,
        private readonly ServiceLocator $providerLocator,
    ) {
    }

    public function get(string $featureName): ?Feature
    {
        if (!$this->providerLocator->has($featureName)) {
            return null;
        }

        return new Feature(
            $featureName,
            $this->features[$featureName]['description'],
            $this->features[$featureName]['default'],
            $this->providerLocator->get($featureName),
        );
    }

    public function names(): array
    {
        return array_keys($this->features);
    }
}
