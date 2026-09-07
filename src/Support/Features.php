<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

/**
 * Central place that decides which plan unlocks which feature.
 * Add a new gated feature here instead of checking the plan value elsewhere.
 */
final class Features
{
    public const FREE = 'free';
    public const PRO = 'pro';

    /** @var array<string, list<string>> feature => plans that include it */
    private const MATRIX = [
        'design_modern' => [self::PRO],
    ];

    public static function allows(string $plan, string $feature): bool
    {
        return in_array($plan, self::MATRIX[$feature] ?? [], true);
    }
}
