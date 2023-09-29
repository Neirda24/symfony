<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Bundle\FeatureFlagsBundle\Command\FeatureFlagsDebugCommand;
use Symfony\Bundle\FeatureFlagsBundle\DataCollector\FeatureCheckerDataCollector;

return static function (ContainerConfigurator $container) {
    $services = $container->services();

    $services->set('feature_flags.data_collector', FeatureCheckerDataCollector::class)
        ->tag('data_collector', ['template' => '@FeatureFlags/Collector/profiler.html.twig', 'id' => 'feature_flags'])
    ;
    $services->set('console.command.feature_flags_debug', FeatureFlagsDebugCommand::class)
        ->args([
            tagged_locator('feature_flags.feature_provider', 'name'),
        ])
        ->tag('console.command')
    ;
};
