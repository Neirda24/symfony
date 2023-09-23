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

use AppendIterator;
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
     * @var AppendIterator<int, ProviderInterface>
     */
    private AppendIterator $providers;

    /**
     * @param list<Feature> $features
     * @param iterable<ProviderInterface> $providers
     */
    public function __construct(array $features, iterable $providers = [])
    {
        $this->providers = new AppendIterator();
        if ([] !== $features) {
            $this->providers->append(new ArrayIterator([new InMemoryProvider($features)]));
        }
        $this->providers->append(is_array($providers) ? new ArrayIterator($providers) : $providers);
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
