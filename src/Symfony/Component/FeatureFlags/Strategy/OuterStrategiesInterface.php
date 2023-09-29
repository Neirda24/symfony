<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureFlags\Strategy;

interface OuterStrategiesInterface
{
    /**
     * @return list<StrategyInterface>
     */
    public function getInnerStrategies(): array;
}
