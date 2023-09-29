<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureToggle\Strategy;

interface OuterStrategyInterface
{
    public function getInnerStrategy(): StrategyInterface;
}
