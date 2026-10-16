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

    /** POST /admin/grants {"ref", "grant_id", "credits"} — applied once per grant_id; credits may be negative. */
    public function grant(): void
    {
        $b = $this->body();
        $ref = self::ref($b);
        $grantId = $b['grant_id'] ?? null;
        $credits = $b['credits'] ?? null;
        if (!is_string($grantId) || $grantId === '' || strlen($grantId) > 200 || !is_int($credits)) {
            throw new HttpError(400, 'grant needs a string grant_id and an integer credits');
        }
        $r = $this->ledger->grant($ref, $grantId, $credits);
        Log::write('info', 'grant', ['ref' => $ref, 'grant_id' => $grantId, 'credits' => $credits, 'applied' => $r['applied'], 'balance' => $r['balance']]);
        Http::json(200, $r);
    }

    /**
     * POST /admin/keys/limits {"ref", "max_concurrent"?, "requests_per_minute"?, "credits_per_minute"?, "bonus_pct"?}:
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
        $overrides = $this->ledger->setLimits($ref, $b);
        if ($newBonus !== null) {
            $this->ledger->setBonus($ref, $newBonus);
        }
        $bonus = $this->ledger->status($ref, $this->config->limitDefaults())['bonus_pct'];
        Log::write('info', 'key_limits', ['ref' => $ref, 'bonus_pct' => $bonus] + $overrides);
        Http::json(200, ['ref' => $ref, 'overrides' => $overrides, 'limits' => Ledger::effectiveLimits($overrides, $this->config->limitDefaults()), 'bonus_pct' => $bonus]);
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
