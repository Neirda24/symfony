<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Authorization;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Decides for the given token, whatever the token storage holds.
 *
 * @internal
 */
final class TokenAuthorizationChecker implements AuthorizationCheckerInterface
{
    public function __construct(
        private readonly TokenInterface $token,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        return $this->accessDecisionManager->decide($this->token, [$attribute], $subject);
    }
}
