<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\View;

/**
 * SEO landing pages targeting specific professions (long-tail search intent,
 * e.g. "digitale visitenkarte handwerker"). Purely static marketing content -
 * one shared template, all copy driven by lang keys under `landing.{key}.*`.
 */
final class LandingController
{
    /** profession key => [feature icons (3), CTA link target, demo card design shown in the phone mockup] */
    public const PROFESSIONS = [
        'handwerker' => ['icons' => ['📱', '📞', '💬'], 'cta_href' => '/register', 'demo_design' => 'classic'],
        'makler' => ['icons' => ['🏠', '📅', '💬'], 'cta_href' => '/register', 'demo_design' => 'professional'],
        'fotografen' => ['icons' => ['🖼️', '📱', '📅'], 'cta_href' => '/register', 'demo_design' => 'playful'],
        'selbststaendige' => ['icons' => ['🔗', '💾', '📅'], 'cta_href' => '/register', 'demo_design' => 'modern'],
        'unternehmen' => ['icons' => ['🏢', '🎨', '💶'], 'cta_href' => '/team', 'demo_design' => 'professional'],
    ];

    public function __construct(private View $view)
    {
    }

    public function show(array $params): void
    {
        $profession = $params['profession'] ?? '';
        if (!array_key_exists($profession, self::PROFESSIONS)) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        echo $this->view->render('landing/vertical.twig', [
            'profession' => $profession,
            'icons' => self::PROFESSIONS[$profession]['icons'],
            'cta_href' => self::PROFESSIONS[$profession]['cta_href'],
            'demo_design' => self::PROFESSIONS[$profession]['demo_design'],
        ]);
    }
}
