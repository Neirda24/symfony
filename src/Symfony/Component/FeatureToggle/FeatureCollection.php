<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\FeatureToggle;

use ArrayIterator;
use Psr\Container\ContainerInterface;
use Symfony\Component\FeatureToggle\Provider\InMemoryProvider;
use Symfony\Component\FeatureToggle\Provider\ProviderInterface;
use function array_key_exists;
use function array_map;
use function array_merge;
use function is_array;

/** @implements \IteratorAggregate<int, Feature> */
final class FeatureCollection implements ContainerInterface, \IteratorAggregate
{
    /** @var array<string, Feature> */
    private array $features = [];

    /**
     * @var iterable<int, ProviderInterface>
     */
    private iterable $providers;

    /**
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = is_array($providers) ? new ArrayIterator($providers) : $providers;
    }

    /**
     * @param list<Feature> $features
     */
    public static function withFeatures(array $features): self
    {
        return new self([new InMemoryProvider($features)]);
    }

    private function findFeature(string $featureName): ?Feature
    {
        if (array_key_exists($featureName, $this->features)) {
            return $this->features[$featureName];
        }

        foreach ($this->providers as $provider) {
            if (($feature = $provider->get($featureName)) !== null) {
                $this->features[$feature->getName()] = $feature;

                return $feature;
            }
        }

        return null;
    }

    public function has(string $id): bool
    {
        return $this->findFeature($id) !== null;
    }

    /**
     * @throws FeatureNotFoundException If the feature is not registered in this provider.
     */
    public function get(string $id): Feature
    {
        return $this->findFeature($id) ?? throw new FeatureNotFoundException($id);
    }

    /**
     * @return \Traversable<int, Feature>
     */
    public function getIterator(): \Traversable
    {
        /** @var list<list<Feature>> $featuresStackedPerProvider */
        $featuresStackedPerProvider = [];

        foreach ($this->providers as $provider) {
            $featuresStackedPerProvider[] = array_map($provider->get(...), $provider->names());
        }

        return new \ArrayIterator(array_merge(...$featuresStackedPerProvider));
    }
}
