<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\DependencyInjection\Compiler\RegisterCallableVotersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CallableVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class RegisterCallableVotersPassTest extends TestCase
{
    public function testRegistersAVoterPerTaggedMethod()
    {
        $container = new ContainerBuilder();
        $container->register('rules', RegisterCallableVotersPassTest_Rules::class)
            ->addTag('security.callable_voter', ['attributes' => ['POST_VIEW', 'POST_EDIT'], 'method' => 'post', 'priority' => 10])
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => 'nullablePost', 'priority' => 0]);

        (new RegisterCallableVotersPass())->process($container);

        $voter = $container->getDefinition('.security.voter.rules.post');
        $this->assertSame(CallableVoter::class, $voter->getClass());
        $this->assertSame([['priority' => 10]], $voter->getTag('security.voter'));

        $callable = $voter->getArgument(0);
        $this->assertInstanceOf(Definition::class, $callable);
        $this->assertSame([\Closure::class, 'fromCallable'], $callable->getFactory());
        $this->assertEquals([[new Reference('rules'), 'post']], $callable->getArguments());
        $this->assertTrue($callable->isLazy());

        $this->assertSame(['subject'], $voter->getArgument(1));
        $this->assertSame(['POST_VIEW', 'POST_EDIT'], $voter->getArgument(2));
        $this->assertSame([RegisterCallableVotersPassTest_Post::class], $voter->getArgument(3));
        $this->assertSame(RegisterCallableVotersPassTest_Rules::class.'::post', $voter->getArgument(4));

        $other = $container->getDefinition('.security.voter.rules.nullablePost');
        $this->assertSame(CallableVoter::class, $other->getClass());
        $this->assertSame([['priority' => 0]], $other->getTag('security.voter'));
    }

    public function testRegisteredVoterCallsTheTaggedMethodOnlyWhenItVotes()
    {
        $container = new ContainerBuilder();
        $container->register('rules', RegisterCallableVotersPassTest_Rules::class)
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => 'published', 'priority' => 0]);

        (new RegisterCallableVotersPass())->process($container);
        $container->getDefinition('.security.voter.rules.published')->setPublic(true);
        $container->compile();

        RegisterCallableVotersPassTest_Rules::$instances = 0;
        $voter = $container->get('.security.voter.rules.published');

        $this->assertSame(0, RegisterCallableVotersPassTest_Rules::$instances, 'The service declaring the method is built only when the voter calls it.');
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new \stdClass(), ['POST_EDIT']));
        $this->assertSame(0, RegisterCallableVotersPassTest_Rules::$instances, 'A subject the voter does not vote on does not build the service.');

        $vote = new Vote();
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), new RegisterCallableVotersPassTest_Post(published: true), ['POST_EDIT'], $vote));
        $this->assertSame(['POST_EDIT is denied on a published post.'], $vote->reasons);
        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new NullToken(), new RegisterCallableVotersPassTest_Post(), ['POST_EDIT']));
        $this->assertSame(1, RegisterCallableVotersPassTest_Rules::$instances);
        $this->assertSame(RegisterCallableVotersPassTest_Rules::class.'::published', (string) $voter);
    }

    public function testCallsAStaticMethodWithoutBuildingTheService()
    {
        $container = new ContainerBuilder();
        $container->register('rules', RegisterCallableVotersPassTest_Rules::class)
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => 'staticRule', 'priority' => 0]);

        (new RegisterCallableVotersPass())->process($container);

        $callable = $container->getDefinition('.security.voter.rules.staticRule')->getArgument(0);
        $this->assertSame([[RegisterCallableVotersPassTest_Rules::class, 'staticRule']], $callable->getArguments());
        $this->assertFalse($callable->isLazy());

        $container->getDefinition('.security.voter.rules.staticRule')->setPublic(true);
        $container->compile();

        RegisterCallableVotersPassTest_Rules::$instances = 0;
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $container->get('.security.voter.rules.staticRule')->vote(new NullToken(), new RegisterCallableVotersPassTest_Post(), ['POST_EDIT']));
        $this->assertSame(0, RegisterCallableVotersPassTest_Rules::$instances);
    }

    public function testThrowsOnAServiceThatIsAlreadyAVoter()
    {
        $container = new ContainerBuilder();
        $container->register('rules', RegisterCallableVotersPassTest_Rules::class)
            ->addTag('security.voter')
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => 'post', 'priority' => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Cannot use "#[AsVoter]" on "%s": the service "rules" is already a voter. To set its priority, use "#[AsTaggedItem]".', RegisterCallableVotersPassTest_Rules::class));

        (new RegisterCallableVotersPass())->process($container);
    }

    public function testRegistersAnInvokableClass()
    {
        $container = new ContainerBuilder();
        $container->register('rule', RegisterCallableVotersPassTest_InvokableRule::class)
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => '__invoke', 'priority' => 0]);

        (new RegisterCallableVotersPass())->process($container);

        $voter = $container->getDefinition('.security.voter.rule.__invoke');
        $this->assertEquals([[new Reference('rule'), '__invoke']], $voter->getArgument(0)->getArguments());
        $this->assertSame(['subject', 'token'], $voter->getArgument(1));
        $this->assertSame([RegisterCallableVotersPassTest_Post::class], $voter->getArgument(3));
        $this->assertSame(RegisterCallableVotersPassTest_InvokableRule::class.'::__invoke', $voter->getArgument(4));
    }

    public static function provideParameters(): iterable
    {
        yield 'no parameter' => ['none', [], null];
        yield 'the subject alone' => ['post', ['subject'], [RegisterCallableVotersPassTest_Post::class]];
        yield 'the token alone, voting on all subjects' => ['tokenOnly', ['token'], null];
        yield 'the token before the subject' => ['tokenThenPost', ['token', 'subject'], [RegisterCallableVotersPassTest_Post::class]];
        yield 'the vote before the subject' => ['voteThenPost', ['vote', 'subject'], [RegisterCallableVotersPassTest_Post::class]];
        yield 'everything' => ['everything', ['attribute', 'subject', 'token', 'vote'], [RegisterCallableVotersPassTest_Post::class]];
        yield 'an untyped attribute and subject' => ['untypedAttribute', ['attribute', 'subject'], null];
    }

    #[DataProvider('provideParameters')]
    public function testResolvesWhatEachParameterReceives(string $method, array $expectedArguments, ?array $expectedSubjectTypes)
    {
        $voter = $this->processMethod($method);

        $this->assertSame($expectedArguments, $voter->getArgument(1));
        $this->assertSame($expectedSubjectTypes, $voter->getArgument(3));
    }

    public static function provideSubjectTypes(): iterable
    {
        yield 'all types for an untyped subject' => ['untyped', null];
        yield 'all types for mixed' => ['mixed', null];
        yield 'a class' => ['post', [RegisterCallableVotersPassTest_Post::class]];
        yield 'a nullable class' => ['nullablePost', [RegisterCallableVotersPassTest_Post::class, 'null']];
        yield 'a union' => ['union', [RegisterCallableVotersPassTest_Post::class, RegisterCallableVotersPassTest_Comment::class, 'null']];
        yield 'a builtin type' => ['builtin', ['int']];
        yield 'null alone' => ['null', ['null']];
        yield 'object' => ['object', ['object']];
    }

    #[DataProvider('provideSubjectTypes')]
    public function testDeducesTheSubjectTypesFromTheSubjectParameter(string $method, ?array $expected)
    {
        $this->assertSame($expected, $this->processMethod($method)->getArgument(3));
    }

    public static function provideInvalidSignatures(): iterable
    {
        yield 'an intersection' => ['intersection', 'Cannot deduce the subjects voted on by "%s::intersection()" from the type of its parameter "$subject"'];
        yield 'iterable' => ['iterable', 'Cannot deduce the subjects voted on by "%s::iterable()" from the type of its parameter "$subject"'];
        yield 'two subjects' => ['twoSubjects', 'Cannot tell which of the parameters "$post" and "$comment" of the voter "%s::twoSubjects()" is the subject'];
        yield 'a return type that is not a vote' => ['returnsString', 'The voter "%s::returnsString()" must return a boolean, null or one of the "Symfony\\Component\\Security\\Core\\Authorization\\Voter\\VoterInterface::ACCESS_*" constants, "string" declared.'];
        yield 'a void return type' => ['returnsVoid', 'The voter "%s::returnsVoid()" must return a boolean, null or one of the "Symfony\\Component\\Security\\Core\\Authorization\\Voter\\VoterInterface::ACCESS_*" constants, "void" declared.'];
        yield 'two tokens' => ['twoTokens', 'The parameters "$token" and "$impersonator" of the voter "%s::twoTokens()" both receive the token: declare it once.'];
        yield 'two votes' => ['twoVotes', 'The parameters "$vote" and "$otherVote" of the voter "%s::twoVotes()" both receive the vote: declare it once.'];
        yield 'a vote that cannot be null' => ['requiredVote', 'The parameter "$vote" of the voter "%s::requiredVote()" must allow null'];
        yield 'an attribute that is not a string' => ['intAttribute', 'The parameter "$attribute" of the voter "%s::intAttribute()" receives the security attribute'];
        yield 'a variadic parameter' => ['variadic', 'The parameter "$posts" of the voter "%s::variadic()" cannot be variadic.'];
        yield 'a missing method' => ['missing', 'Invalid voter "rules": method "%s::missing()" does not exist or is not public.'];
        yield 'a private method' => ['hidden', 'Invalid voter "rules": method "%s::hidden()" does not exist or is not public.'];
    }

    #[DataProvider('provideInvalidSignatures')]
    public function testThrowsOnASignatureItCannotUse(string $method, string $message)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf($message, RegisterCallableVotersPassTest_Rules::class));

        $this->processMethod($method);
    }

    private function processMethod(string $method): Definition
    {
        $container = new ContainerBuilder();
        $container->register('rules', RegisterCallableVotersPassTest_Rules::class)
            ->addTag('security.callable_voter', ['attributes' => ['POST_EDIT'], 'method' => $method, 'priority' => 0]);

        (new RegisterCallableVotersPass())->process($container);

        return $container->getDefinition('.security.voter.rules.'.$method);
    }
}

class RegisterCallableVotersPassTest_Post
{
    public function __construct(
        public bool $published = false,
    ) {
    }
}

class RegisterCallableVotersPassTest_Comment
{
}

class RegisterCallableVotersPassTest_Rules
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    public function published(?Vote $vote, RegisterCallableVotersPassTest_Post $post, string $attribute): ?false
    {
        if (!$post->published) {
            return null;
        }

        $vote?->addReason($attribute.' is denied on a published post.');

        return false;
    }

    public static function staticRule(RegisterCallableVotersPassTest_Post $post): ?true
    {
        return true;
    }

    public function returnsString(): string
    {
        return 'yes';
    }

    public function returnsVoid(): void
    {
    }

    public function none(): bool
    {
        return true;
    }

    public function tokenOnly(TokenInterface $token): bool
    {
        return true;
    }

    public function tokenThenPost(TokenInterface $token, RegisterCallableVotersPassTest_Post $post): bool
    {
        return true;
    }

    public function voteThenPost(?Vote $vote, RegisterCallableVotersPassTest_Post $post): bool
    {
        return true;
    }

    public function everything(string $attribute, RegisterCallableVotersPassTest_Post $post, TokenInterface $token, ?Vote $vote = null): bool
    {
        return true;
    }

    public function untypedAttribute($attribute, $subject): bool
    {
        return true;
    }

    public function untyped($subject): bool
    {
        return true;
    }

    public function mixed(mixed $subject): bool
    {
        return true;
    }

    public function post(RegisterCallableVotersPassTest_Post $post): bool
    {
        return true;
    }

    public function nullablePost(?RegisterCallableVotersPassTest_Post $post): bool
    {
        return true;
    }

    public function union(RegisterCallableVotersPassTest_Post|RegisterCallableVotersPassTest_Comment|null $subject): bool
    {
        return true;
    }

    public function builtin(int $subject): bool
    {
        return true;
    }

    public function null(null $subject): bool
    {
        return true;
    }

    public function object(object $subject): bool
    {
        return true;
    }

    public function intersection(\Countable&\Stringable $subject): bool
    {
        return true;
    }

    public function iterable(iterable $subject): bool
    {
        return true;
    }

    public function twoSubjects(RegisterCallableVotersPassTest_Post $post, RegisterCallableVotersPassTest_Comment $comment): bool
    {
        return true;
    }

    public function twoTokens(TokenInterface $token, TokenInterface $impersonator): bool
    {
        return true;
    }

    public function twoVotes(?Vote $vote, ?Vote $otherVote): bool
    {
        return true;
    }

    public function requiredVote(Vote $vote): bool
    {
        return true;
    }

    public function intAttribute(int $attribute): bool
    {
        return true;
    }

    public function variadic(RegisterCallableVotersPassTest_Post ...$posts): bool
    {
        return true;
    }

    private function hidden(): bool
    {
        return true;
    }
}

class RegisterCallableVotersPassTest_InvokableRule
{
    public function __invoke(RegisterCallableVotersPassTest_Post $post, TokenInterface $token): bool
    {
        return true;
    }
}
