<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FeatureFlagsBundle\Command;

use Closure;
use Symfony\Bundle\FeatureFlagsBundle\Debug\TraceableStrategy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\FeatureFlags\Feature;
use Symfony\Component\FeatureFlags\Provider\ProviderInterface;
use Symfony\Component\FeatureFlags\Strategy\OuterStrategiesInterface;
use Symfony\Component\FeatureFlags\Strategy\OuterStrategyInterface;
use Symfony\Component\FeatureFlags\Strategy\StrategyInterface;
use function array_map;
use function json_encode;
use function str_repeat;
use function strlen;

/**
 * A console command for retrieving information about feature flags.
 */
#[AsCommand(name: 'debug:feature-flags', description: 'Display configured features and their provider for an application')]
final class FeatureFlagsDebugCommand extends Command
{
    /** @var iterable<string, ProviderInterface> */
    private iterable $featureProviders;

    /** @param iterable<string, ProviderInterface> $featureProviders */
    public function __construct(iterable $featureProviders)
    {
        parent::__construct();

        $this->featureProviders = $featureProviders;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('featureName', InputArgument::OPTIONAL, 'Feature name. If provided will display the full tree of strategies regarding that feature.')
        ;
    }

    /**
     * @throws \LogicException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->listAllFeaturesPerProvider($io);

        return 0;
    }

    private function listAllFeaturesPerProvider(SymfonyStyle $io)
    {
        $io->title('Feature list grouped by their providers');

        $order = 0;
        foreach ($this->featureProviders as $serviceName => $featureProvider) {
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

                $featureGetDefault = Closure::bind(fn(): bool => $feature->default, $feature, Feature::class);

                $tableRows[] = [
                    $featureName,
                    $feature->getDescription(),
                    json_encode($featureGetDefault()),
                    $this->getStrategyTreeFromFeature($feature)
                ];

            }
            $io->table($tableHeaders, $tableRows);
        }
    }

    private function getStrategyTreeFromFeature(Feature $feature): string
    {
        $featureGetStrategy = Closure::bind(fn(): StrategyInterface => $feature->strategy, $feature, Feature::class);

        $strategyTree = $this->getStrategyTree($featureGetStrategy());

        return $this->convertStrategyTreeToString($strategyTree);
    }

    private function getStrategyTree(StrategyInterface $strategy, string|null $strategyId = null): array
    {
        $children = [];

        if ($strategy instanceof OuterStrategiesInterface) {
            $children = array_map(
                fn(StrategyInterface $strategyInterface): array => $this->getStrategyTree($strategyInterface),
                $strategy->getInnerStrategies()
            );
        } elseif ($strategy instanceof OuterStrategyInterface) {
            if ($strategy instanceof TraceableStrategy) {
                $strategyGetId = Closure::bind(fn(): string => $strategy->strategyId, $strategy, TraceableStrategy::class);

                return $this->getStrategyTree($strategy->getInnerStrategy(), $strategyGetId());
            }

            $children = [$this->getStrategyTree($strategy->getInnerStrategy())];
        }

        return [
            'id' => $strategyId,
            'class' => $strategy::class,
            'children' => $children,
        ];
    }

    private function convertStrategyTreeToString(array $strategyTree, int $indent = 0): string
    {
        $childIndicator = 'L ';
        $spaces = str_repeat(' ', $indent * strlen($childIndicator));

        $prefix = '' === $spaces ? '' : "{$spaces}{$childIndicator}";

        $row = $strategyTree['class'];

        if (null !== $strategyTree['id']) {
            $row .= " ({$strategyTree['id']})";
        }

        $row .= "\n";

        foreach ($strategyTree['children'] as $child) {
            $row .= $this->convertStrategyTreeToString($child, ($indent + 1));
        }

        return "{$prefix}{$row}";
    }
}
