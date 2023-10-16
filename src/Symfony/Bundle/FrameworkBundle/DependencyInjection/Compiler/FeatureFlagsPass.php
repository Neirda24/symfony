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

use Closure;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\FeatureFlags\Feature;
use Symfony\Component\FeatureFlags\Strategy\CallbackStrategy;

final class FeatureFlagsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('feature_flags.provider.lazy_in_memory')) {
            return;
        }

        $lazyInMemoryProvider = $container->getDefinition('feature_flags.provider.lazy_in_memory');

        $features = $lazyInMemoryProvider->getArgument('$features');

        foreach ($container->findTaggedServiceIds('feature_flags.self_feature_strategy') as $serviceId => $tags) {
            $className = $this->getServiceClass($container, $serviceId);
            $r = $container->getReflectionClass($className);

            foreach ($tags as $tag) {
                $method = $tag['method'] ?? '__invoke';

                if (!$r->hasMethod($method)) {
                    throw new \RuntimeException(sprintf('Invalid feature strategy "%s": method "%s::%s()" does not exist.', $serviceId, $r->getName(), $method));
                }

                $callback = (new Definition(Closure::class))
                    ->setFactory([Closure::class, 'fromCallable'])
                    ->setArguments([[new Reference($serviceId), $method]])
                ;

                $callbackDefinition = (new Definition(CallbackStrategy::class))
                    ->addTag('feature_flags.feature_strategy')
                    ->setArguments([
                        $callback
                    ])
                ;

                $featureName = $tag['feature'];

                if (null === $tag['feature'] || '' === $tag['feature']) {
                    $featureName = $className;
                    if ('__invoke' !== $method) {
                        $featureName .= '::'.$method;
                    }
                }

                $features[$featureName] = new ServiceClosureArgument((new Definition(Feature::class))
                    ->setShared(false)
                    ->setArguments([
                        $featureName,
                        $tag['description'] ?? '',
                        $tag['default'] ?? false,
                        $callbackDefinition,
                    ]))
                ;
            }
        }

        $lazyInMemoryProvider->setArgument('$features', $features);
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
