<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

final class Translator
{
    public const SUPPORTED_LOCALES = ['de', 'en'];
    public const DEFAULT_LOCALE = 'de';

    /** @var array<string, string> */
    private array $translations;

    public function __construct(private string $locale, string $langPath)
    {
        $this->translations = require $langPath . '/' . $this->locale . '.php';
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function trans(string $key, array $replacements = []): string
    {
        $text = $this->translations[$key] ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
        }

        return $text;
    }

    public static function detectLocale(?string $acceptLanguageHeader): string
    {
        if ($acceptLanguageHeader !== null) {
            $preferred = strtolower(substr($acceptLanguageHeader, 0, 2));
            if (in_array($preferred, self::SUPPORTED_LOCALES, true)) {
                return $preferred;
            }
        }

        return self::DEFAULT_LOCALE;
    }
}
