<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Attribute;

/**
 * Turns a method or an invokable class into a voter that grants on true, denies on false and abstains on null, its parameters receiving the token and the vote by type, the attribute by the name $attribute, and the subject otherwise.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class AsVoter
{
    /**
     * @param string|list<string> $attributes The security attributes the voter votes on
     * @param int                 $priority   The priority of the voter
     */
    public function __construct(
        public string|array $attributes,
        public int $priority = 0,
    ) {
    }
}
