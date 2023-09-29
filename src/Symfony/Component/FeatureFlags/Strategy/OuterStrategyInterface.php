<?php

declare(strict_types=1);

namespace Symfony\Component\FeatureFlags\Strategy;

interface OuterStrategyInterface
{
    public function getInnerStrategy(): StrategyInterface;
}
