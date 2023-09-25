<?php

declare(strict_types=1);

namespace Symfony\Bundle\FeatureToggleBundle\Provider;

use Closure;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\Provider\ProviderInterface;
use Symfony\Component\FeatureToggle\Strategy\StrategyInterface;
use function array_keys;

final class LazyInMemoryProvider implements ProviderInterface
{
    /**
     * @param ServiceLocator<array{description: string, default: bool, strategy: (Closure(): StrategyInterface)}> $providerLocator
     */
    public function __construct(
        private readonly ServiceLocator $providerLocator,
    ) {
    }

    public function get(string $featureName): ?Feature
    {
        if (!$this->providerLocator->has($featureName)) {
            return null;
        }

        $featureConfig = $this->providerLocator->get($featureName);

        return new Feature(
            $featureName,
            $featureConfig['description'],
            $featureConfig['default'],
            $featureConfig['strategy']($featureName),
        );
    }

    public function names(): array
    {
        return array_keys($this->providerLocator->getProvidedServices());
    }
}
