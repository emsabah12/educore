<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Unit;

use LogicException;
use Modules\Auth\BrowserSession\Security\BrowserSessionSecurityPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrowserSessionSecurityPolicyTest extends TestCase
{
    public function test_hardened_production_configuration_is_accepted(): void
    {
        $policy = new BrowserSessionSecurityPolicy;

        $policy->assertProductionReady(
            $this->validProductionConfiguration(),
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidProductionConfigurationProvider')]
    public function test_insecure_production_configuration_is_rejected(
        string $key,
        mixed $invalidValue,
    ): void {
        $configuration = $this->validProductionConfiguration();
        $configuration[$key] = $invalidValue;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Invalid production BrowserSession configuration',
        );

        (new BrowserSessionSecurityPolicy)
            ->assertProductionReady($configuration);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidProductionConfigurationProvider(): array
    {
        return [
            'client-side cookie session driver' => [
                'driver',
                'cookie',
            ],
            'local file session driver' => [
                'driver',
                'file',
            ],
            'unencrypted server session payload' => [
                'encrypt',
                false,
            ],
            'cookie without host prefix' => [
                'cookie',
                'educore-session',
            ],
            'cookie transmitted over plaintext' => [
                'secure',
                false,
            ],
            'javascript-readable session cookie' => [
                'http_only',
                false,
            ],
            'cookie path narrower than root' => [
                'path',
                '/api',
            ],
            'shared parent-domain cookie' => [
                'domain',
                '.educore.test',
            ],
            'lax same-site policy' => [
                'same_site',
                'lax',
            ],
            'partitioned browser session cookie' => [
                'partitioned',
                true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validProductionConfiguration(): array
    {
        return [
            'driver' => 'database',
            'encrypt' => true,
            'cookie' => '__Host-educore-session',
            'secure' => true,
            'http_only' => true,
            'path' => '/',
            'domain' => null,
            'same_site' => 'strict',
            'partitioned' => false,
        ];
    }
}
