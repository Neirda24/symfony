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
use function array_column;
use function array_map;
use function array_shift;
use function array_slice;
use function chunk_split;
use function count;
use function implode;
use function json_encode;
use function levenshtein;
use function sprintf;
use function str_repeat;
use function strlen;
use function usort;

/**
 * A console command for retrieving information about feature flags.
 */
#[AsCommand(name: 'debug:feature-flags', description: 'Display configured features and their provider for an application')]
final class FeatureFlagsDebugCommand extends Command
{
    private SymfonyStyle $io;

    /** @var iterable<string, ProviderInterface> */
    private iterable $featureProviders;

    /** @var array<string, list<string>> */
    private array $featureSources = [];

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
        $this->io = new SymfonyStyle($input, $output);

        if (null === $input->getArgument('featureName')) {
            return $this->listAllFeaturesPerProvider();
        }

        return $this->detailsFeature($input->getArgument('featureName'));
    }

    private function detailsFeature(string $debuggingFeatureName): int
    {
        $this->io->title("About \"{$debuggingFeatureName}\" flag");

        $tableHeaders = ['Name', 'Description', 'Default', 'Provider', 'Strategy Tree'];
        $tableRows = [];
        $providerNames = [];
        $candidates = [];

        foreach ($this->featureProviders as $serviceName => $featureProvider) {
            $providerName = $featureProvider::class;
            if ($providerName !== $serviceName) {
                $providerName .= " ({$serviceName})";
            }
            $providerNames[] = $providerName;

            foreach ($featureProvider->names() as $featureName) {
                $this->featureSources[$featureName] ??= [];
                $this->featureSources[$featureName][] = $providerName;

                if (
                    !\in_array($featureName, $candidates, true)
                    && (str_contains($featureName, $debuggingFeatureName) || levenshtein($featureName, $debuggingFeatureName) <= \strlen($featureName) / 3)
                ) {
                    $candidates[] = $featureName;
                }

                if ($featureName !== $debuggingFeatureName) {
                    continue;
                }

                if (1 < count($this->featureSources[$featureName])) {
                    continue;
                }

                $feature = $featureProvider->get($featureName);
                $featureGetDefault = Closure::bind(fn(): bool => $feature->default, $feature, Feature::class);

                $tableRows[] = [
                    $featureName,
                    chunk_split($feature->getDescription(), 60, "\n"),
                    json_encode($featureGetDefault()),
                    $providerName,
                    $this->getStrategyTreeFromFeature($feature)
                ];
            }
        }

        if (0 === count($tableRows)) {
            $warning = sprintf(
                "\"%s\" not found in any of the following providers :\n%s",
                $debuggingFeatureName,
                implode("\n", array_map(fn (string $providerName) => '  * '.$providerName, $providerNames)),
            );
            if (0 < count($candidates)) {
                $warning .= sprintf(
                    "\nDid you mean \"%s\"?",
                    implode('", "', $candidates),
                );
            }
            $this->io->warning($warning);

            return 1;
        }

        $this->io
            ->createTable()
            ->setHorizontal()
            ->setHeaders($tableHeaders)
            ->setRows($tableRows)
            ->setStyle('compact')
            ->render()
        ;

        $this->renderDuplicateWarnings($tableRows[0][0]);

        return 0;
    }

    private function listAllFeaturesPerProvider(): int
    {
        $this->io->title('Feature list grouped by their providers');

        $order = 0;
        foreach ($this->featureProviders as $serviceName => $featureProvider) {
            ++$order;

            $providerName = $featureProvider::class;
            if ($providerName !== $serviceName) {
                $providerName .= " ({$serviceName}).";
            }
            $this->io->section("#{$order} - {$providerName}");

            $tableHeaders = ['Name', 'Description', 'Default', 'Main Strategy'];
            $tableRows = [];

            foreach ($featureProvider->names() as $featureName) {
                $this->featureSources[$featureName] ??= [];
                $this->featureSources[$featureName][] = $providerName;

                $feature = $featureProvider->get($featureName);

                $featureGetDefault = Closure::bind(fn(): bool => $feature->default, $feature, Feature::class);
                $featureGetStrategy = Closure::bind(fn(): StrategyInterface => $feature->strategy, $feature, Feature::class);

                $strategy = $featureGetStrategy();
                $strategyClass = $strategy::class;
                $strategyId = null;

                if ($strategy instanceof TraceableStrategy) {
                    $strategyGetId = Closure::bind(fn(): string => $strategy->strategyId, $strategy, TraceableStrategy::class);

                    $strategyId = $strategyGetId();
                    $strategyClass = $strategy->getInnerStrategy()::class;
                }

                $strategyString = $strategyClass;
                if (null !== $strategyId) {
                    $strategyString .= " ({$strategyId})";
                }

                $tableRows[] = [
                    $featureName,
                    chunk_split($feature->getDescription(), 60, "\n"),
                    json_encode($featureGetDefault()),
                    $strategyString
                ];

            }
            $this->io->table($tableHeaders, $tableRows);
        }

        foreach ($this->featureSources as $featureName => $providerNames) {
            $this->renderDuplicateWarnings($featureName);
        }

        return 0;
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

        if ($strategy instanceof TraceableStrategy) {
            $strategyGetId = Closure::bind(fn(): string => $strategy->strategyId, $strategy, TraceableStrategy::class);

            return $this->getStrategyTree($strategy->getInnerStrategy(), $strategyGetId());
        } elseif ($strategy instanceof OuterStrategiesInterface) {
            $children = array_map(
                fn(StrategyInterface $strategyInterface): array => $this->getStrategyTree($strategyInterface),
                $strategy->getInnerStrategies()
            );
        } elseif ($strategy instanceof OuterStrategyInterface) {
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

    private function renderDuplicateWarnings(string $featureName): void
    {
        $providerNames = $this->featureSources[$featureName] ?? [];
        $duplicatesCount = count($providerNames) - 1;
        if (0 === $duplicatesCount) {
            return;
        } elseif (1 === $duplicatesCount) {
            $warningMessage = sprintf("Found 1 duplicate for \"%s\" feature, which will probably never be used, in those providers:", $featureName);
        } else {
            $warningMessage = sprintf("Found %d duplicates for \"%s\" feature, which will probably never be used, in those providers:", $featureName, $duplicatesCount);
        }

        $warningMessage.= "\n";
        $warningMessage.= implode("\n", array_map(fn(string $providerName): string => '  * '.$providerName, $providerNames));

        $this->io->warning($warningMessage);
    }
}
