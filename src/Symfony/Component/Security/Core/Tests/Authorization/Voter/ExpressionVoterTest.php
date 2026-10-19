<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Tests\Authorization\Voter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolver;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\ExpressionLanguage;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\Authorization\Voter\ExpressionVoter;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

class ExpressionVoterTest extends TestCase
{
    /**
     * @dataProvider getVoteTests
     */
    public function testVoteWithTokenThatReturnsRoleNames($roles, $attributes, $expected, $tokenExpectsGetRoles = true, $expressionLanguageExpectsEvaluate = true)
    {
        $voter = new ExpressionVoter($this->createExpressionLanguage($expressionLanguageExpectsEvaluate), $this->createTrustResolver(), $this->createAuthorizationChecker());

        $this->assertSame($expected, $voter->vote($this->getTokenWithRoleNames($roles, $tokenExpectsGetRoles), null, $attributes));
    }

    public static function getVoteTests()
    {
        return [
            [[], [], VoterInterface::ACCESS_ABSTAIN, false, false],
            [[], ['FOO'], VoterInterface::ACCESS_ABSTAIN, false, false],

            [[], [self::createExpression()], VoterInterface::ACCESS_DENIED, true, false],

            [['ROLE_FOO'], [self::createExpression(), self::createExpression()], VoterInterface::ACCESS_GRANTED],
            [['ROLE_BAR', 'ROLE_FOO'], [self::createExpression()], VoterInterface::ACCESS_GRANTED],
        ];
    }

    /**
     * @dataProvider provideChecksOfAnotherTokenThanTheLoggedInOne
     */
    public function testChecksDecideForTheVotedToken(TokenInterface $votedToken, ?TokenInterface $loggedInToken, Expression $expression, int $expected)
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($loggedInToken);
        $trustResolver = new AuthenticationTrustResolver();
        $accessDecisionManager = new AccessDecisionManager([new RoleVoter(), new AuthenticatedVoter($trustResolver)]);
        $voter = new ExpressionVoter(new ExpressionLanguage(), $trustResolver, new AuthorizationChecker($tokenStorage, $accessDecisionManager), null, $accessDecisionManager);

        $this->assertSame($expected, $voter->vote($votedToken, null, [$expression]));
    }

    public static function provideChecksOfAnotherTokenThanTheLoggedInOne(): iterable
    {
        $admin = new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        $user = new UsernamePasswordToken(new InMemoryUser('user', null, ['ROLE_USER']), 'main', ['ROLE_USER']);
        $rememberedUser = new RememberMeToken(new InMemoryUser('user', null, ['ROLE_USER']), 'main', 'secret');

        yield 'is_granted() for an admin while nobody is logged in' => [$admin, null, new Expression('is_granted("ROLE_ADMIN")'), VoterInterface::ACCESS_GRANTED];
        yield 'is_granted() for a user while an admin is logged in' => [$user, $admin, new Expression('is_granted("ROLE_ADMIN")'), VoterInterface::ACCESS_DENIED];
        yield 'is_fully_authenticated() for a remembered user while an admin is logged in' => [$rememberedUser, $admin, new Expression('is_fully_authenticated()'), VoterInterface::ACCESS_DENIED];
        yield 'is_remember_me() for a remembered user while an admin is logged in' => [$rememberedUser, $admin, new Expression('is_remember_me()'), VoterInterface::ACCESS_GRANTED];
    }

    protected function getTokenWithRoleNames(array $roles, $tokenExpectsGetRoles = true)
    {
        if ($tokenExpectsGetRoles) {
            $mock = $this->createMock(AbstractToken::class);
            $mock->expects($this->once())
                ->method('getRoleNames')
                ->willReturn($roles);

            return $mock;
        }

        return new NullToken();
    }

    protected function createExpressionLanguage($expressionLanguageExpectsEvaluate = true)
    {
        if ($expressionLanguageExpectsEvaluate) {
            $mock = $this->createMock(ExpressionLanguage::class);
            $mock->expects($this->once())
                ->method('evaluate')
                ->willReturn(true);

            return $mock;
        }

        return new ExpressionLanguage();
    }

    protected function createTrustResolver()
    {
        return $this->createStub(AuthenticationTrustResolverInterface::class);
    }

    protected function createAuthorizationChecker()
    {
        return $this->createStub(AuthorizationCheckerInterface::class);
    }

    protected static function createExpression()
    {
        return new Expression('is_granted("ROLE_ADMIN") or is_granted("ROLE_MANAGER")');
    }
}
