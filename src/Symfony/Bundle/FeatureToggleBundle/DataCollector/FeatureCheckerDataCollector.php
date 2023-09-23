<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FeatureToggleBundle\DataCollector;

use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\FeatureCollection;
use Symfony\Component\FeatureToggle\Strategy\StrategyInterface;
use Symfony\Component\FeatureToggle\StrategyResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Symfony\Component\VarDumper\Caster\ClassStub;
use Symfony\Component\VarDumper\Cloner\Data;

/**
 * @phpstan-type FeatureType array{
 *     default: bool,
 *     description: string,
 *     strategy: StrategyInterface,
 * }
 * @phpstan-type ToggleType array{
 *     feature: string,
 *     result: bool|null,
 *     computes: array<string, ComputeType>,
 * }
 * @phpstan-type ComputeType array{
 *     strategyId: string,
 *     strategyClass: string,
 *     level: int,
 *     result: StrategyResult|null,
 * }
 *
 * @property Data|array{
 *     toggles: array<string, ToggleType>,
 * } $data
 */
final class FeatureCheckerDataCollector extends DataCollector implements LateDataCollectorInterface
{
    /** @var \SplStack<string> */
    private \SplStack $currentToggle;

    /** @var \SplStack<string> */
    private \SplStack $currentCompute;

    public function __construct()
    {
        $this->data = ['toggles' => []];
        $this->currentToggle = new \SplStack();
        $this->currentCompute = new \SplStack();
    }

    public function collect(Request $request, Response $response, \Throwable $exception = null): void
    {
    }

    public function collectIsEnabledStart(string $featureName): void
    {
        $toggleId = uniqid();

        $this->data['toggles'][$toggleId] = [
            'feature' => $featureName,
            'computes' => [],
            'result' => null,
        ];
        $this->currentToggle->push($toggleId);
    }

    /**
     * @param class-string $strategyClass
     */
    public function collectComputeStart(string $strategyId, string $strategyClass): void
    {
        $toggleId = $this->currentToggle->top();
        $computeId = uniqid();
        $level = $this->currentCompute->count();

        $this->data['toggles'][$toggleId]['computes'][$computeId] = [
            'strategyId' => $strategyId,
            'strategyClass' => new ClassStub($strategyClass),
            'level' => $level,
            'result' => null,
        ];
        $this->currentCompute->push($computeId);
    }

    public function collectComputeStop(StrategyResult $result): void
    {
        $toggleId = $this->currentToggle->top();
        $computeId = $this->currentCompute->pop();

        $this->data['toggles'][$toggleId]['computes'][$computeId]['result'] = $result;
    }

    public function collectIsEnabledStop(bool $result): void
    {
        $toggleId = $this->currentToggle->pop();

        $this->data['toggles'][$toggleId]['result'] = $result;
    }

    public function getName(): string
    {
        return 'feature_toggle';
    }

    public function reset(): void
    {
        $this->data = [
            'toggles' => [],
        ];
    }

    public function lateCollect(): void
    {
        $this->data = $this->cloneVar($this->data);
    }

    /**
     * @return list<ToggleType>|Data
     */
    public function getToggles(): array|Data
    {
        return $this->data['toggles'];
    }
}
