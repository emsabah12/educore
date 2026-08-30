<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use Illuminate\Support\Facades\Hash;
use Modules\Auth\Authentication\Contracts\PasswordVerifierInterface;
use Modules\Auth\Authentication\Services\HashPasswordVerifier;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

final class PasswordVerifierContractTest extends TestCase
{
    public function test_password_verifier_contract_exists(): void
    {
        $this->assertTrue(
            interface_exists(
                PasswordVerifierInterface::class,
            ),
            'Authentication must expose an injectable password verification contract.',
        );
    }

    public function test_password_verifier_contract_exposes_exact_verification_boundary(): void
    {
        $this->assertTrue(
            interface_exists(
                PasswordVerifierInterface::class,
            ),
            'PasswordVerifierInterface must exist before its contract can be inspected.',
        );

        $method = new ReflectionMethod(
            PasswordVerifierInterface::class,
            'verify',
        );

        $this->assertSame(
            2,
            $method->getNumberOfParameters(),
            'Password verification must require exactly the submitted password and one stored hash.',
        );

        $parameters = $method->getParameters();

        $this->assertSame(
            'plainPassword',
            $parameters[0]->getName(),
        );

        $this->assertSame(
            'passwordHash',
            $parameters[1]->getName(),
        );

        foreach ($parameters as $parameter) {
            $parameterType = $parameter->getType();

            $this->assertInstanceOf(
                ReflectionNamedType::class,
                $parameterType,
            );

            $this->assertSame(
                'string',
                $parameterType->getName(),
            );
        }

        $returnType = $method->getReturnType();

        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
        );

        $this->assertSame(
            'bool',
            $returnType->getName(),
        );
    }

    public function test_hash_password_verifier_implements_authentication_contract(): void
    {
        $this->assertTrue(
            class_exists(
                HashPasswordVerifier::class,
            ),
            'Authentication must provide the canonical Hash-backed password verifier.',
        );

        $this->assertTrue(
            is_subclass_of(
                HashPasswordVerifier::class,
                PasswordVerifierInterface::class,
            ),
            'HashPasswordVerifier must implement PasswordVerifierInterface.',
        );

        $reflection = new ReflectionClass(
            HashPasswordVerifier::class,
        );

        $this->assertTrue(
            $reflection->isFinal(),
            'Canonical password verifier should not expose an inheritance extension surface.',
        );
    }

    public function test_hash_password_verifier_accepts_matching_password(): void
    {
        $this->assertTrue(
            class_exists(
                HashPasswordVerifier::class,
            ),
            'HashPasswordVerifier must exist before behavior can be tested.',
        );

        $hash = Hash::make(
            'correct-password',
        );

        $verifier = new HashPasswordVerifier();

        $this->assertTrue(
            $verifier->verify(
                'correct-password',
                $hash,
            ),
        );
    }

    public function test_hash_password_verifier_rejects_non_matching_password(): void
    {
        $this->assertTrue(
            class_exists(
                HashPasswordVerifier::class,
            ),
            'HashPasswordVerifier must exist before behavior can be tested.',
        );

        $hash = Hash::make(
            'correct-password',
        );

        $verifier = new HashPasswordVerifier();

        $this->assertFalse(
            $verifier->verify(
                'wrong-password',
                $hash,
            ),
        );
    }
}
