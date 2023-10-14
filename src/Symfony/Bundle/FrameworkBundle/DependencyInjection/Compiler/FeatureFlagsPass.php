<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler;

use Symfony\Bundle\FrameworkBundle\FeatureFlags\ClosureStrategy;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\FeatureFlags\Debug\TraceableStrategy;
use Symfony\Component\FeatureFlags\Feature;

final class FeatureFlagsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $lazyInMemoryProvider = $container->getDefinition('feature_flags.provider.lazy_in_memory');

        foreach ($container->findTaggedServiceIds('feature_flags.feature_strategy', true) as $serviceId => $tags) {
            $className = $this->getServiceClass($container, $serviceId);
            $r = $container->getReflectionClass($className);

            foreach ($tags as $tag) {
                if (null === $r) {
                    throw new \RuntimeException(sprintf('Invalid service "%s": class "%s" does not exist.', $serviceId, $className));
                }

                $name = $tag['name'] ?? $className;
                $description = $tag['description'] ?? '';
                $method = $tag['method'] ?? '__invoke';

                if (!$r->hasMethod($method)) {
                    throw new \RuntimeException(sprintf('Invalid feature "%s": method "%s::%s()" does not exist.', $serviceId, $r->getName(), $method));
                }

                $strategy = (new Definition(ClosureStrategy::class))
                    ->addArgument((new Definition('Closure'))
                        ->setFactory('Closure::fromCallable')
                        ->addArgument([new Reference($serviceId), $method])
                    )
                    ->addTag('feature_flags.strategy')
                ;

                $feature = (new Definition(Feature::class))
                    ->setShared(false)
                    ->setArguments([
                        $name,
                        $description,
                        false, // not used because the \Closure returns a boolean
                        $strategy,
                    ])
                ;

                $lazyInMemoryProvider
                    ->addMethodCall('add', [
                        $name,
                        (new ServiceClosureArgument($feature)),
                    ])
                ;
            }
        }

        // Debug
        if (!$container->has('feature_flags.data_collector')) {
            return;
        }

        foreach ($container->findTaggedServiceIds('feature_flags.strategy') as $serviceId => $tags) {
            $container->register('debug.' . $serviceId, TraceableStrategy::class)
                ->setDecoratedService($serviceId)
                ->setArguments([
                    '$strategy' => new Reference('.inner'),
                    '$strategyId' => $serviceId,
                    '$dataCollector' => new Reference('feature_flags.data_collector'),
                ]);
        }
    }

    private function getServiceClass(ContainerBuilder $container, string $serviceId): string
    {
        while (true) {
            $definition = $container->findDefinition($serviceId);

            if (!$definition->getClass() && $definition instanceof ChildDefinition) {
                $serviceId = $definition->getParent();

                continue;
            }

            return $definition->getClass();
        }
    }
}
