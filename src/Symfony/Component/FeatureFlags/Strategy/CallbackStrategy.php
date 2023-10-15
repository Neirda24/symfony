<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\FeatureFlags\Strategy;

use Closure;
use Symfony\Component\FeatureFlags\StrategyResult;
use function is_bool;

final class CallbackStrategy implements StrategyInterface
{
    /**
     * @param Closure(): (bool|StrategyResult) $inner
     */
    public function __construct(
        private readonly Closure $inner,
    ) {
    }

    public function compute(): StrategyResult
    {
        $innerResult = ($this->inner)();

        if (is_bool($innerResult)) {
            return $innerResult === true ? StrategyResult::Grant : StrategyResult::Deny;
        }

        if ($innerResult instanceof StrategyResult) {
            return $innerResult;
        }

        // TODO : LogicExcepiton
    }
}
