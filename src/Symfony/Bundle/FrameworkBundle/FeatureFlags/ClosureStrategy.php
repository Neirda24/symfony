<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\FeatureFlags;

use Symfony\Component\FeatureFlags\Strategy\StrategyInterface;
use Symfony\Component\FeatureFlags\StrategyResult;

final class ClosureStrategy implements StrategyInterface
{
    /**
     * @param \Closure(): bool $inner
     */
    public function __construct(private readonly \Closure $inner)
    {
    }

    public function compute(): StrategyResult
    {
        return ($this->inner)() ? StrategyResult::Grant : StrategyResult::Deny;
    }
}
