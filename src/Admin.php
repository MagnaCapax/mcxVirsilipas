<?php

declare(strict_types=1);

namespace MagnaCapax\Virsilipas;

/**
 * The billing/provisioning side, authenticated by its own admin token.
 * Billing pushes grants here; it never needs, and is never given, customer keys after creation.
 */
final class Admin
{
    public function __construct(private readonly Config $config, private readonly Ledger $ledger)
    {
    }

    /** POST /admin/keys {"ref": "..."} -> 201 {"ref", "key"}; the key is returned only this once. */
    public function createKey(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        $key = $this->ledger->createKey($ref);
        Log::write('info', 'key_create', ['ref' => $ref]);
        Http::json(201, ['ref' => $ref, 'key' => $key]);
    }

    /** POST /admin/keys/active {"ref": "...", "active": true|false} — suspend or unsuspend. */
    public function setActive(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        if (!is_bool($b['active'] ?? null)) {
            throw new HttpError(400, 'active must be true or false');
        }
        $this->ledger->setActive($ref, $b['active']);
        Log::write('info', 'key_active', ['ref' => $ref, 'active' => $b['active']]);
        Http::json(200, ['ref' => $ref, 'active' => $b['active']]);
    }

    /** POST /admin/grants {"ref", "grant_id", "tokens"} — applied once per grant_id; tokens may be negative. */
    public function grant(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        $grantId = $b['grant_id'] ?? null;
        $tokens = $b['tokens'] ?? null;
        if (!is_string($grantId) || $grantId === '' || strlen($grantId) > 200 || !is_int($tokens)) {
            throw new HttpError(400, 'grant needs a string grant_id and an integer tokens');
        }
        $r = $this->ledger->grant($ref, $grantId, $tokens);
        Log::write('info', 'grant', ['ref' => $ref, 'grant_id' => $grantId, 'tokens' => $tokens, 'applied' => $r['applied'], 'balance' => $r['balance']]);
        Http::json(200, $r);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $this->authorize();
        return Http::jsonBody(65536);
    }

    private function authorize(): void
    {
        if (!hash_equals($this->config->adminTokenSha256, hash('sha256', Http::bearer()))) {
            Log::write('warn', 'admin_auth_failed');
            throw new HttpError(401, 'invalid admin token', 'authentication_error');
        }
    }

    /** @param array<string, mixed> $b */
    private static function ref(array $b): string
    {
        $ref = $b['ref'] ?? null;
        if (!is_string($ref) || $ref === '' || strlen($ref) > 200) {
            throw new HttpError(400, 'ref must be a non-empty string (max 200 bytes)');
        }
        return $ref;
    }
}
