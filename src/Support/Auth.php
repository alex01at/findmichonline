<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use Kartenlink\App\Model\Organization;
use Kartenlink\App\Model\User;
use PDO;

final class Auth
{
    private const REMEMBER_COOKIE = 'remember_token';
    private const REMEMBER_TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days

    public function __construct(private PDO $db)
    {
    }

    public function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::set('user_id', $user['id']);

        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }
    }

    public function logout(): void
    {
        $userId = Session::get('user_id');
        if ($userId !== null) {
            (new User($this->db))->clearRememberToken((int) $userId);
        }

        $this->clearRememberCookie();
        Session::destroy();
    }

    public function check(): bool
    {
        return Session::get('user_id') !== null;
    }

    /**
     * Logs the user in transparently from a "remember me" cookie if there's
     * no active session but a valid, unexpired remember token. The token is
     * rotated on every use, so a copied/leaked cookie stops working the next
     * time the legitimate user's browser uses (and replaces) it.
     */
    public function attemptRememberLogin(): void
    {
        if ($this->check() || !isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }

        $token = (string) $_COOKIE[self::REMEMBER_COOKIE];
        $user = (new User($this->db))->findByValidRememberTokenHash(hash('sha256', $token));

        if ($user === null) {
            $this->clearRememberCookie();
            return;
        }

        $this->login($user, remember: true);
    }

    public function user(): ?array
    {
        $userId = Session::get('user_id');
        if ($userId === null) {
            return null;
        }

        return (new User($this->db))->findById((int) $userId);
    }

    /**
     * A user's "effective" plan for gating purposes: their real stored plan,
     * or Pro while an active free trial is running. Every Pro-only feature
     * already re-checks this at render/action time rather than caching it,
     * so a trial expiring behaves exactly like a manual downgrade - the
     * existing graceful-degradation logic for that (colors, gallery, extra
     * designs falling back, etc.) needs no separate handling here.
     */
    public function plan(): string
    {
        $user = $this->user();
        if ($user === null) {
            return Features::FREE;
        }

        if (!empty($user['organization_id'])) {
            $org = (new Organization($this->db))->findById((int) $user['organization_id']);
            if ($org !== null && self::hasActiveOrgSubscription($org)) {
                return Features::PRO;
            }
        }

        if (($user['plan'] ?? Features::FREE) === Features::PRO) {
            return Features::PRO;
        }

        if (self::hasActiveTrial($user)) {
            return Features::PRO;
        }

        return Features::FREE;
    }

    public static function hasActiveTrial(array $user): bool
    {
        return !empty($user['trial_ends_at']) && strtotime($user['trial_ends_at']) > time();
    }

    public static function hasActiveOrgSubscription(array $org): bool
    {
        return ($org['subscription_status'] ?? null) === 'active'
            || (!empty($org['trial_ends_at']) && strtotime($org['trial_ends_at']) > time());
    }

    public function can(string $feature): bool
    {
        return Features::allows($this->plan(), $feature);
    }

    public function isAdmin(): bool
    {
        $user = $this->user();
        return $user !== null && (bool) $user['is_admin'];
    }

    public function organization(): ?array
    {
        $user = $this->user();
        if ($user === null || empty($user['organization_id'])) {
            return null;
        }

        return (new Organization($this->db))->findById((int) $user['organization_id']);
    }

    public function isOrgOwner(): bool
    {
        $user = $this->user();
        $org = $this->organization();

        return $user !== null && $org !== null && (int) $org['owner_user_id'] === (int) $user['id'];
    }

    /**
     * True only when a user's Pro access comes from their own temporary
     * trial clock - not a paid plan, not an org, not a demo account. Used
     * to explicitly call out "this will go away" wherever a trial user is
     * currently enjoying a Pro-only feature (e.g. a design other than
     * Classic), since otherwise they'd have no indication their card will
     * silently fall back once the trial ends.
     */
    public function isOnIndividualTrial(): bool
    {
        $user = $this->user();
        if ($user === null || !empty($user['is_demo']) || $this->organization() !== null) {
            return false;
        }

        return ($user['plan'] ?? Features::FREE) !== Features::PRO && self::hasActiveTrial($user);
    }

    private function issueRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        (new User($this->db))->setRememberToken($userId, hash('sha256', $token), self::REMEMBER_TTL_SECONDS);

        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_TTL_SECONDS,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        if (!isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }

        unset($_COOKIE[self::REMEMBER_COOKIE]);
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
