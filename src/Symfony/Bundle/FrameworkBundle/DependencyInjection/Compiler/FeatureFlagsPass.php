<?php

declare(strict_types=1);

namespace Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler;

use Closure;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
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
        $lazyInMemoryProvider = $container->getDefinition('feature_flags.provider.lazy_in_memory');

        $features = $lazyInMemoryProvider->getArgument('$features');

        foreach ($container->findTaggedServiceIds('feature_flags.self_feature_strategy') as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $callback = (new Definition(Closure::class))
                    ->setFactory([Closure::class, 'fromCallable'])
                    ->setArguments([[new Reference($serviceId), $tag['method']]])
                ;

                $callbackDefinition = (new Definition(CallbackStrategy::class))
                    ->addTag('feature_flags.feature_strategy')
                    ->setArguments([
                        $callback
                    ])
                ;

                $features[$tag['feature']] = new ServiceClosureArgument((new Definition(Feature::class))
                    ->setShared(false)
                    ->setArguments([
                        $tag['feature'],
                        '',
                        $tag['default'],
                        $callbackDefinition,
                    ]))
                ;
            }
        }

        $lazyInMemoryProvider->setArgument('$features', $features);
    }
}
