<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

final class Translator
{
    public const SUPPORTED_LOCALES = ['de', 'en'];

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

    /**
     * German only if the browser actually lists German among its preferred
     * languages (at any priority) - every other case, including a language
     * we don't have translations for (French, Spanish, ...) or a missing/
     * unparseable header, falls back to English rather than German.
     */
    public static function detectLocale(?string $acceptLanguageHeader): string
    {
        if ($acceptLanguageHeader === null || trim($acceptLanguageHeader) === '') {
            return 'en';
        }

        $entries = [];
        foreach (explode(',', $acceptLanguageHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pieces = explode(';q=', $part);
            $tag = strtolower(trim($pieces[0]));
            $quality = isset($pieces[1]) ? (float) $pieces[1] : 1.0;
            $entries[] = [$tag, $quality];
        }

        usort($entries, fn (array $a, array $b) => $b[1] <=> $a[1]);

        foreach ($entries as [$tag, $quality]) {
            $primary = substr($tag, 0, 2);
            if (in_array($primary, self::SUPPORTED_LOCALES, true)) {
                return $primary;
            }
        }

        return 'en';
    }
}
