<?php

namespace StoneScriptDB\Auth;

class TokenClaims
{
    public function __construct(
        public readonly string $identity_id,
        public readonly string $tenant_id,
        public readonly string $tenant_slug,
        public readonly string $platform_code,
        public readonly string $role,
        public readonly ?string $local_user_id,
        public readonly int $exp,
        public readonly int $iat
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            identity_id: $payload['identity_id'] ?? throw new \InvalidArgumentException('Missing identity_id claim'),
            tenant_id: $payload['tenant_id'] ?? throw new \InvalidArgumentException('Missing tenant_id claim'),
            tenant_slug: $payload['tenant_slug'] ?? '',  // Optional — not all platforms use subdomain routing
            platform_code: $payload['platform_code'] ?? throw new \InvalidArgumentException('Missing platform_code claim'),
            role: $payload['role'] ?? throw new \InvalidArgumentException('Missing role claim'),
            local_user_id: $payload['local_user_id'] ?? null,
            exp: $payload['exp'] ?? throw new \InvalidArgumentException('Missing exp claim'),
            iat: $payload['iat'] ?? throw new \InvalidArgumentException('Missing iat claim')
        );
    }

    public function isExpired(): bool
    {
        return time() >= $this->exp;
    }

    public function toArray(): array
    {
        return [
            'identity_id' => $this->identity_id,
            'tenant_id' => $this->tenant_id,
            'tenant_slug' => $this->tenant_slug,
            'platform_code' => $this->platform_code,
            'role' => $this->role,
            'local_user_id' => $this->local_user_id,
            'exp' => $this->exp,
            'iat' => $this->iat,
        ];
    }
}
