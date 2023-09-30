<?php

namespace Symfony\Bundle\FeatureFlagsBundle\Tests\DataCollector;

use Symfony\Bundle\FeatureFlagsBundle\DataCollector\FeatureCheckerDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\FeatureFlags\StrategyResult;
use Symfony\Component\VarDumper\Caster\ClassStub;
use function array_map;
use function array_reduce;
use function array_values;
use function method_exists;

final class FeatureCheckerDataCollectorTest extends KernelTestCase
{
    public function testNameIsCorrect()
    {
        $featureCheckerDataCollector = new FeatureCheckerDataCollector();
        $this->assertSame('feature_flags', $featureCheckerDataCollector->getName());
    }

    public function testHasNeededMethodsExposedPubliclyForProfiler()
    {
        $featureCheckerDataCollector = new FeatureCheckerDataCollector();
        $this->assertTrue(method_exists($featureCheckerDataCollector, 'getChecks'));
    }

    public function testCorrectlyCollectIsEnabledData()
    {
        $featureCheckerDataCollector = new FeatureCheckerDataCollector();
        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-1');
        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-2');
        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-1');
        $featureCheckerDataCollector->collectIsEnabledStop(false);
        $featureCheckerDataCollector->collectIsEnabledStop(false);
        $featureCheckerDataCollector->collectIsEnabledStop(true);

        $this->assertCount(3, $featureCheckerDataCollector->getChecks());
        $this->assertSame([
            [
                'feature' => 'some-feature-1',
                'computes' => [],
                'result' => true,
            ],
            [
                'feature' => 'some-feature-2',
                'computes' => [],
                'result' => false,
            ],
            [
                'feature' => 'some-feature-1',
                'computes' => [],
                'result' => false,
            ],
        ], array_values($featureCheckerDataCollector->getChecks()));
    }

    public function testCorrectlyCollectComputeData()
    {
        $featureCheckerDataCollector = new FeatureCheckerDataCollector();
        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-1');
        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-2');

        $featureCheckerDataCollector->collectComputeStart('some-feature-2/strategy-1', 'Some\Strategy\Class');
        $featureCheckerDataCollector->collectComputeStart('some-feature-2/strategy-2', 'Some\Other\Strategy\Class');

        $featureCheckerDataCollector->collectIsEnabledStart('some-feature-1');

        $featureCheckerDataCollector->collectComputeStart('some-feature-1/strategy-1', 'Some\Strategy\Class');
        $featureCheckerDataCollector->collectComputeStart('some-feature-1/strategy-2', 'Some\Other\Strategy\Class');
        $featureCheckerDataCollector->collectComputeStop(StrategyResult::Grant);
        $featureCheckerDataCollector->collectComputeStop(StrategyResult::Deny);

        $featureCheckerDataCollector->collectIsEnabledStop(false); // Stop some-feature-1

        $featureCheckerDataCollector->collectComputeStop(StrategyResult::Abstain);
        $featureCheckerDataCollector->collectComputeStop(StrategyResult::Grant);

        $featureCheckerDataCollector->collectIsEnabledStop(false); // Stop some-feature-2
        $featureCheckerDataCollector->collectIsEnabledStop(true); // Stop some-feature-1


        $this->assertCount(3, $featureCheckerDataCollector->getChecks());

        $result = array_reduce(
            $featureCheckerDataCollector->getChecks(),
            function (array $result, array $check) {
                $result[$check['feature']] ??= [];

                $result[$check['feature']][] = array_values(array_map(
                    function(array $compute) {
                        $this->assertArrayHasKey('strategyId', $compute);
                        $this->assertArrayHasKey('strategyClass', $compute);
                        $this->assertArrayHasKey('level', $compute);
                        $this->assertArrayHasKey('result', $compute);

                        $this->assertInstanceOf(ClassStub::class, $compute['strategyClass']);

                        $compute['strategyClass'] = (string) $compute['strategyClass'];

                        return $compute;
                    },
                    $check['computes']
                ));

                return $result;
            },
            []
        );

        $expectedResult = [
            'some-feature-1' => [
                [],
                [
                    [
                        'strategyId' => 'some-feature-1/strategy-1',
                        'strategyClass' => 'Some\Strategy\Class',
                        'level' => 2,
                        'result' => StrategyResult::Deny,
                    ],
                    [
                        'strategyId' => 'some-feature-1/strategy-2',
                        'strategyClass' => 'Some\Other\Strategy\Class',
                        'level' => 3,
                        'result' => StrategyResult::Grant,
                    ],
                ],
            ],
            'some-feature-2' => [
                [
                    [
                        'strategyId' => 'some-feature-2/strategy-1',
                        'strategyClass' => 'Some\Strategy\Class',
                        'level' => 0,
                        'result' => StrategyResult::Grant,
                    ],
                    [
                        'strategyId' => 'some-feature-2/strategy-2',
                        'strategyClass' => 'Some\Other\Strategy\Class',
                        'level' => 1,
                        'result' => StrategyResult::Abstain,
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedResult, $result);
    }

    public function testServiceIsCorrectlyRegistered()
    {
        // TODO
    }
}
