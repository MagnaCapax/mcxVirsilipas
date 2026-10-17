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

    /** POST /admin/keys/rotate {"ref": "..."} — a new key for the ref (the old one stops working); balance and history stay. */
    public function rotateKey(): void
    {
        $ref = self::ref($this->body());
        $key = $this->ledger->rotateKey($ref);
        Log::write('info', 'key_rotate', ['ref' => $ref]);
        Http::json(200, ['ref' => $ref, 'key' => $key]);
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

    /**
     * POST /admin/keys/remove {"ref": "..."} — removes the ref's key for good: it never authenticates again and the
     * ref cannot be rotated, re-enabled, granted to or created again. Balance, grants, credit log and usage stay. Idempotent.
     */
    public function removeKey(): void
    {
        $ref = self::ref($this->body());
        $r = $this->ledger->removeKey($ref);
        Log::write('info', 'key_remove', ['ref' => $ref, 'applied' => $r['applied']]);
        Http::json(200, ['ref' => $ref] + $r);
    }

    /**
     * POST /admin/grants {"ref", "grant_id", "credits", "expires_at"?} — applied once per grant_id; credits may be negative.
     * expires_at (unix seconds, or null/absent for never) is stored for a future expiry policy and not enforced.
     */
    public function grant(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        $grantId = $b['grant_id'] ?? null;
        $credits = $b['credits'] ?? null;
        $expiresAt = $b['expires_at'] ?? null;
        if (!is_string($grantId) || $grantId === '' || strlen($grantId) > 200 || !is_int($credits)) {
            throw new HttpError(400, 'grant needs a string grant_id and an integer credits');
        }
        if ($expiresAt !== null && !is_int($expiresAt)) {
            throw new HttpError(400, 'expires_at must be null or a future unix time in seconds', param: 'expires_at');
        }
        $r = $this->ledger->grant($ref, $grantId, $credits, $expiresAt);
        Log::write('info', 'grant', ['ref' => $ref, 'grant_id' => $grantId, 'credits' => $credits, 'expires_at' => $r['expires_at'],
            'applied' => $r['applied'], 'balance' => $r['balance']]);
        Http::json(200, $r);
    }

    /**
     * POST /admin/keys/limits {"ref", "max_concurrent"?, "requests_per_minute"?, "credits_per_minute"?, "bonus_pct"?, "key_group"?}:
     * a given limit is set to a positive integer, or to null to follow the configured default again;
     * limits not given are left as they are. Returns the key's own settings and the limits in force.
     */
    public function setLimits(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        unset($b['ref']);
        // The bonus percent rides along with the limits: billing sets both, and it only raises the key's priority weight.
        // Validated before anything changes, applied after the limits, so a refused request changes nothing.
        $newBonus = null;
        if (array_key_exists('bonus_pct', $b)) {
            $newBonus = $b['bonus_pct'];
            if (!is_int($newBonus) || $newBonus < 0 || $newBonus > Ledger::MAX_BONUS_PCT) {
                throw new HttpError(400, 'bonus_pct must be an integer from 0 to ' . Ledger::MAX_BONUS_PCT);
            }
            unset($b['bonus_pct']);
        }
        // The key's group (for usage statistics) rides along the same way.
        $newGroup = null;
        if (array_key_exists('key_group', $b)) {
            $newGroup = $b['key_group'];
            if (!Ledger::validGroup($newGroup)) {
                throw new HttpError(400, 'key_group must be at most 64 of a-z, 0-9, dot, dash and underscore');
            }
            unset($b['key_group']);
        }
        $overrides = $this->ledger->setLimits($ref, $b);
        if ($newBonus !== null) {
            $this->ledger->setBonus($ref, $newBonus);
        }
        if ($newGroup !== null) {
            $this->ledger->setGroup($ref, $newGroup);
        }
        $s = $this->ledger->status($ref, $this->config->limitDefaults());
        Log::write('info', 'key_limits', ['ref' => $ref, 'bonus_pct' => $s['bonus_pct'], 'key_group' => $s['key_group']] + $overrides);
        Http::json(200, ['ref' => $ref, 'overrides' => $overrides, 'limits' => Ledger::effectiveLimits($overrides, $this->config->limitDefaults()),
            'bonus_pct' => $s['bonus_pct'], 'key_group' => $s['key_group']]);
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
