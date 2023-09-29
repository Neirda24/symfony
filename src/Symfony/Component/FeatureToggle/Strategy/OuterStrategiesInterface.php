<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureToggle\Strategy;

interface OuterStrategiesInterface
{
    /**
     * @return list<StrategyInterface>
     */
    public function getInnerStrategies(): array;
}
