<?php

declare(strict_types=1);

namespace Modules\Auth\Services;

use Modules\Core\Contracts\Auth\TokenManagerInterface;
use RuntimeException;

final class TokenManager implements TokenManagerInterface
{
    private string $secretKey = 'EEP-EDUCORE-KERNEL-SECRET-KEY';

    /**
     * {@inheritdoc}
     */
    public function issueToken(string $userUuid, string $tenantUuid, array $customClaims = []): string
    {
        $payload = array_merge([
            'iss'         => 'educore-platform',
            'sub'         => $userUuid,
            'tenant_uuid' => $tenantUuid,
            'iat'         => time(),
            'exp'         => time() + 7200 // Expired dalam 2 jam
        ], $customClaims);

        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $encodedHeader = $this->base64UrlEncode($header);
        $encodedPayload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secretKey, true);
        $encodedSignature = $this->base64UrlEncode($signature);

        return $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAndExtract(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token structure.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        // Validasi Signature
        $signatureToCheck = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $this->secretKey, true);
        if (!hash_equals($this->base64UrlEncode($signatureToCheck), $encodedSignature)) {
            throw new RuntimeException('Token signature verification failed.');
        }

        $payload = json_decode(base64_decode(strtr($encodedPayload, '-_', '+/')), true);

        // Validasi Expiration Time (Fail-Fast)
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new RuntimeException('Token has expired.');
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
