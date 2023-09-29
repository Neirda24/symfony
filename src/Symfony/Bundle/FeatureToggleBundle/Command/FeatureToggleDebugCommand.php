<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FeatureToggleBundle\Command;

use Closure;
use Symfony\Bundle\FeatureToggleBundle\Debug\TraceableStrategy;
use Symfony\Bundle\FrameworkBundle\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\Provider\ProviderInterface;
use Symfony\Component\FeatureToggle\Strategy\OuterStrategiesInterface;
use Symfony\Component\FeatureToggle\Strategy\OuterStrategyInterface;
use Symfony\Component\FeatureToggle\Strategy\StrategyInterface;
use function array_keys;
use function array_map;
use function sprintf;
use function uniqid;

/**
 * A console command for retrieving information about feature toggles.
 */
#[AsCommand(name: 'debug:feature-toggle', description: 'Display configured features and their provider for an application')]
final class FeatureToggleDebugCommand extends Command
{
    /** @var ServiceLocator<ProviderInterface> */
    private ServiceLocator $featureProviders;

    public function __construct(ServiceLocator $featureProviders)
    {
        parent::__construct();

        $this->featureProviders = $featureProviders;
    }

    protected function configure(): void
    {
    }

    /**
     * @throws \LogicException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Feature list grouped by their providers');

        $order = 0;
        foreach (array_keys($this->featureProviders->getProvidedServices()) as $serviceName) {
            $featureProvider = $this->featureProviders->get($serviceName);

            ++$order;

            $providerName = $featureProvider::class;
            if ($providerName !== $serviceName) {
                $providerName .= " ({$serviceName}).";
            }
            $io->section("#{$order} - {$providerName}");

            $tableHeaders = ['Name', 'Description', 'Default', 'Main Strategy'];
            $tableRows = [];

            foreach ($featureProvider->names() as $featureName) {
                $feature = $featureProvider->get($featureName);

                $featureGetDefault = Closure::bind(function (): bool {
                    return $this->default;
                }, $feature, Feature::class);
                $featureGetStrategy = Closure::bind(function (): StrategyInterface {
                    return $this->strategy;
                }, $feature, Feature::class); // TODO : get strategy tree and remove the Traceable from log
                                              // TODO : get strategy service name

                $featureGetDefault->bindTo($feature, Feature::class);

                dd($this->getStrategyTreeFromFeature($feature));

                $tableRows[] = [$featureName, $feature->getDescription(), $featureGetDefault(), $featureGetStrategy()::class];

                $io->table($tableHeaders, $tableRows);
            }
        }

        return 0;
    }

    private function getStrategyTreeFromFeature(Feature $feature): array
    {
        $getMainStrategy = Closure::bind(function (): StrategyInterface {
            return $this->strategy;
        }, $feature, Feature::class);

        $mainStrategy = $getMainStrategy();

        return $this->getStrategyTree($mainStrategy);
    }

    private function getStrategyTree(StrategyInterface $strategy, string|null $strategyId = null): array
    {
        $strategyId = $strategyId ?? uniqid($strategy::class);

        if ($strategy instanceof OuterStrategiesInterface) {
            $result[] = [
                'id' => $strategyId,
                'class' => $strategy::class,
                'children' => array_map(
                    fn(StrategyInterface $strategyInterface): array => $this->getStrategyTree($strategyInterface),
                    $strategy->getInnerStrategies()
                ),
            ];

            return $result;
        } elseif ($strategy instanceof OuterStrategyInterface) {
            if (!$strategy instanceof TraceableStrategy) {
                $result[] = [
                    'id' => $strategyId,
                    'class' => $strategy::class,
                    'children' => [$this->getStrategyTree($strategy->getInnerStrategy())],
                ];

                return $result;
            }

            $getStrategyId = Closure::bind(function (): string {
                return $this->strategyId;
            }, $strategy, TraceableStrategy::class);

            $strategyId = $getStrategyId();

            $result[] = $this->getStrategyTree($strategy->getInnerStrategy(), $strategyId);

            return $result;
        } else {
            return [
                'id' => $strategyId,
                'class' => $strategy::class,
                'children' => [],
            ];
        }
    }
}
