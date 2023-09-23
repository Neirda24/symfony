<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\FeatureToggle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\FeatureChecker;
use Symfony\Component\FeatureToggle\FeatureCollection;
use Symfony\Component\FeatureToggle\Strategy\DenyStrategy;
use Symfony\Component\FeatureToggle\Strategy\GrantStrategy;
use Symfony\Component\FeatureToggle\Strategy\StrategyInterface;
use Symfony\Component\FeatureToggle\StrategyResult;

/**
 * @covers \Symfony\Component\FeatureToggle\FeatureChecker
 *
 * @uses \Symfony\Component\FeatureToggle\FeatureCollection
 * @uses \Symfony\Component\FeatureToggle\Feature
 * @uses \Symfony\Component\FeatureToggle\Strategy\GrantStrategy
 * @uses \Symfony\Component\FeatureToggle\Strategy\DenyStrategy
 */
final class FeatureCheckerTest extends TestCase
{
    public function testItCorrectlyCheckTheFeaturesEvenIfNotFound(): void
    {
        $featureChecker = new FeatureChecker(
            new FeatureCollection([]),
            true
        );

        self::assertTrue($featureChecker->isEnabled('not-found-1'));

        $featureChecker = new FeatureChecker(
            new FeatureCollection([
                new Feature(
                    name: 'fake-1',
                    description: 'Fake description 1',
                    default: true,
                    strategy: new GrantStrategy()
                ),
                new Feature(
                    name: 'fake-2',
                    description: 'Fake description 2',
                    default: true,
                    strategy: new DenyStrategy()
                ),
                new Feature(
                    name: 'fake-3',
                    description: 'Fake description 3',
                    default: false,
                    strategy: new class implements StrategyInterface {
                        public function compute(): StrategyResult
                        {
                            return StrategyResult::Abstain;
                        }
                    }
                ),
            ]),
            false
        );

        self::assertFalse($featureChecker->isEnabled('not-found-1'));
        self::assertTrue($featureChecker->isEnabled('fake-1'));
        self::assertFalse($featureChecker->isEnabled('fake-2'));
        self::assertFalse($featureChecker->isEnabled('fake-3'));
    }

    /**
     * @dataProvider provideIsEnableFallback
     */
    public function testIsEnableFallback(bool $expected, StrategyResult $result, bool $default)
    {
        $feature = new Feature(
            name: 'fake',
            description: 'Fake description',
            default: $default,
            strategy: new class($result) implements StrategyInterface {
                public function __construct(private readonly StrategyResult $result) {}

                public function compute(): StrategyResult
                {
                    return $this->result;
                }
            },
        );

        $featureChecker = new FeatureChecker(new FeatureCollection([$feature]),true);

        self::assertSame($expected, $featureChecker->isEnabled('fake'));
    }

    public function provideIsEnableFallback(): iterable
    {
        // Grant
        yield "grant should ignore fallback #1" => [
            true,
            StrategyResult::Grant,
            true,
        ];

        yield "grant should ignore fallback #2" => [
            true,
            StrategyResult::Grant,
            false,
        ];

        // Deny
        yield "deny should ignore fallback #1" => [
            false,
            StrategyResult::Deny,
            true,
        ];

        yield "deny should ignore fallback #2" => [
            false,
            StrategyResult::Deny,
            false,
        ];

        // Abstain
        yield "abstain should use fallback #1" => [
            true,
            StrategyResult::Abstain,
            true,
        ];

        yield "abstain should use fallback #2" => [
            false,
            StrategyResult::Abstain,
            false,
        ];
    }
}
