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

use Psr\Container\ContainerInterface;
use Symfony\Component\FeatureToggle\Provider\InMemoryProvider;
use Symfony\Component\FeatureToggle\Provider\ProviderInterface;
use function array_key_exists;
use function array_map;
use function array_merge;
use function array_reduce;
use function array_unshift;
use function is_array;
use function iterator_to_array;

/** @implements \IteratorAggregate<int, Feature> */
final class FeatureCollection implements ContainerInterface, \IteratorAggregate
{
    /** @var array<string, Feature> */
    private array $features = [];

    private iterable $providers;

    /**
     * @param list<Feature> $features
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(array $features, iterable $providers = [])
    {
        $this->providers = $providers;
        if ([] !== $features) {
            array_unshift($this->providers, new InMemoryProvider($features));
        }
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
        $providers = is_array($this->providers) === true ? $this->providers : iterator_to_array($this->providers);

        $features = array_merge(...array_reduce($providers, static function(array $list, ProviderInterface $provider): array {
            $featureNames = $provider->names();

            $list[] = array_map($provider->get(...), $featureNames);

            return $list;
        }, []));

        return new \ArrayIterator(array_values($features));
    }
}
