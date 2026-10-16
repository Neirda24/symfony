<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CallableVoter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Registers the methods and invokable classes marked with #[AsVoter] as voters.
 *
 * @internal
 */
final class RegisterCallableVotersPass implements CompilerPassInterface
{
    private const SUBJECT_BUILTIN_TYPES = ['null', 'bool', 'int', 'float', 'string', 'array', 'object'];
    private const RETURN_TYPES = ['bool', 'true', 'false', 'null', 'int', 'mixed'];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds('security.callable_voter', true) as $id => $tags) {
            $definition = $container->getDefinition($id);
            $class = $container->getParameterBag()->resolveValue($definition->getClass());

            if (!$r = $container->getReflectionClass($class)) {
                throw new InvalidArgumentException(\sprintf('Class "%s" used for service "%s" cannot be found.', $class, $id));
            }

            if ($definition->hasTag('security.voter')) {
                throw new InvalidArgumentException(\sprintf('Cannot use "#[AsVoter]" on "%s": the service "%s" is already a voter. To set its priority, use "#[AsTaggedItem]".', $r->name, $id));
            }

            foreach ($tags as $tag) {
                $method = $tag['method'] ?? '__invoke';

                if (!$r->hasMethod($method) || !$r->getMethod($method)->isPublic()) {
                    throw new InvalidArgumentException(\sprintf('Invalid voter "%s": method "%s::%s()" does not exist or is not public.', $id, $r->name, $method));
                }

                $reflector = $r->getMethod($method);
                self::checkReturnType($reflector);
                [$arguments, $subject] = self::resolveArguments($reflector);

                $callable = (new Definition(\Closure::class))->setFactory([\Closure::class, 'fromCallable']);

                if ($reflector->isStatic()) {
                    $callable->setArguments([[$r->name, $method]]);
                } else {
                    // the lazy closure is what defers building the service declaring the method until the voter calls it, as with #[AsCallable], without making that service lazy elsewhere
                    $callable->setArguments([[new Reference($id), $method]])->setLazy(true);
                }

                $container->register('.security.voter.'.$id.'.'.$method, CallableVoter::class)
                    ->setArguments([
                        $callable,
                        $arguments,
                        (array) $tag['attributes'],
                        $subject ? self::getSubjectTypes($reflector, $subject) : null,
                        $r->name.'::'.$method,
                    ])
                    ->addTag('security.voter', ['priority' => $tag['priority'] ?? 0]);
            }
        }
    }

    private static function checkReturnType(\ReflectionMethod $method): void
    {
        if (!$type = $method->getReturnType()) {
            return;
        }

        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $namedType) {
            if (!$namedType instanceof \ReflectionNamedType || !\in_array($namedType->getName(), self::RETURN_TYPES, true)) {
                throw new InvalidArgumentException(\sprintf('The voter "%s::%s()" must return a boolean, null or one of the "%s::ACCESS_*" constants, "%s" declared.', $method->class, $method->name, VoterInterface::class, $type));
            }
        }
    }

    /**
     * @return array{list<'attribute'|'subject'|'token'|'vote'>, \ReflectionParameter|null}
     */
    private static function resolveArguments(\ReflectionMethod $method): array
    {
        $arguments = [];
        $parameters = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($parameter->isVariadic()) {
                throw new InvalidArgumentException(\sprintf('The parameter "$%s" of the voter "%s::%s()" cannot be variadic.', $parameter->name, $method->class, $method->name));
            }

            if ('attribute' === $parameter->name) {
                if ($type && !\in_array($typeName, ['string', 'mixed'], true)) {
                    throw new InvalidArgumentException(\sprintf('The parameter "$attribute" of the voter "%s::%s()" receives the security attribute: type it as "string" or leave it untyped.', $method->class, $method->name));
                }

                $role = 'attribute';
            } elseif (TokenInterface::class === $typeName) {
                $role = 'token';
            } elseif (Vote::class === $typeName) {
                if (!$type->allowsNull()) {
                    throw new InvalidArgumentException(\sprintf('The parameter "$%s" of the voter "%s::%s()" must allow null: type it as "?Vote", as a voter can be called without a vote.', $parameter->name, $method->class, $method->name));
                }

                $role = 'vote';
            } else {
                $role = 'subject';
            }

            if ('subject' === $role && isset($parameters['subject'])) {
                throw new InvalidArgumentException(\sprintf('Cannot tell which of the parameters "$%s" and "$%s" of the voter "%s::%s()" is the subject: only the token, the vote and the "$attribute" can come along with it.', $parameters['subject']->name, $parameter->name, $method->class, $method->name));
            }

            if (isset($parameters[$role])) {
                throw new InvalidArgumentException(\sprintf('The parameters "$%s" and "$%s" of the voter "%s::%s()" both receive the %s: declare it once.', $parameters[$role]->name, $parameter->name, $method->class, $method->name, $role));
            }

            $parameters[$role] = $parameter;
            $arguments[] = $role;
        }

        return [$arguments, $parameters['subject'] ?? null];
    }

    /**
     * @return list<string>|null
     */
    private static function getSubjectTypes(\ReflectionMethod $method, \ReflectionParameter $subject): ?array
    {
        if (!$type = $subject->getType()) {
            return null;
        }

        $types = [];
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $namedType) {
            if (!$namedType instanceof \ReflectionNamedType || ($namedType->isBuiltin() && !\in_array($namedType->getName(), ['mixed', ...self::SUBJECT_BUILTIN_TYPES], true))) {
                throw new InvalidArgumentException(\sprintf('Cannot deduce the subjects voted on by "%s::%s()" from the type of its parameter "$%s": use classes, "%s" or "mixed".', $method->class, $method->name, $subject->name, implode('", "', self::SUBJECT_BUILTIN_TYPES)));
            }

            if ('mixed' === $namedType->getName()) {
                return null;
            }

            $types[] = $namedType->getName();

            if ($namedType->allowsNull() && 'null' !== $namedType->getName()) {
                $types[] = 'null';
            }
        }

        return array_values(array_unique($types));
    }
}
