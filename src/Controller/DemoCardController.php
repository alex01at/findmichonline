<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\Organization;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\View;

/**
 * Renders a fixed, made-up example card through the exact same design
 * templates the real public /{slug} page uses - no DB, no real user - so the
 * marketing pages (home, landing pages) can show what a finished card
 * actually looks like instead of only describing it. Embedded via a small
 * phone-frame iframe, same CSS-transform-scale technique already used for
 * the design-picker thumbnails in onboarding/team branding.
 */
final class DemoCardController
{
    public const BASE_DEFAULTS = [
        'id' => 0,
        'job_title' => null, 'company' => null, 'category_id' => null,
        'email' => null, 'phone' => null, 'whatsapp' => null, 'website' => null,
        'address' => null, 'workplace' => null, 'bio' => null, 'opening_hours' => null,
        'logo_path' => null, 'photo_path' => null,
        'linkedin_url' => null, 'instagram_url' => null, 'facebook_url' => null, 'youtube_url' => null,
        'booking_url' => null,
        'use_custom_colors' => false,
        'color_background' => null, 'color_header' => null, 'color_content' => null, 'color_footer' => null,
    ];

    /** persona key => card field overrides on top of BASE_DEFAULTS */
    public const PERSONAS = [
        'default' => [
            'slug' => 'anna-berger', 'display_name' => 'Anna Berger', 'job_title' => 'UX-Beraterin',
            'company' => 'Berger Design Studio', 'phone' => '+43 660 1234567', 'email' => 'anna@bergerdesign.at',
            'website' => 'bergerdesign.at', 'address' => 'Mariahilfer Straße 12, 1060 Wien',
            'bio' => 'Ich gestalte digitale Produkte, die Menschen wirklich gerne benutzen.',
            'linkedin_url' => 'https://linkedin.com/in/annaberger', 'booking_url' => 'https://calendly.com/annaberger',
        ],
        'handwerker' => [
            'slug' => 'thomas-gruber', 'display_name' => 'Thomas Gruber', 'job_title' => 'Elektrotechnik-Meister',
            'company' => 'Gruber Elektrotechnik', 'phone' => '+43 664 1112233', 'whatsapp' => '+43 664 1112233',
            'email' => 'office@gruber-elektro.at', 'website' => 'gruber-elektro.at',
            'address' => 'Industriestraße 4, 4020 Linz',
            'bio' => '24h-Notdienst für Strom- und Heizungsausfälle im Großraum Linz.',
        ],
        'makler' => [
            'slug' => 'julia-wagner', 'display_name' => 'Julia Wagner', 'job_title' => 'Immobilienmaklerin',
            'company' => 'Wagner Immobilien', 'phone' => '+43 699 8887766', 'email' => 'julia@wagner-immo.at',
            'website' => 'wagner-immo.at', 'address' => 'Kärntner Ring 5, 1010 Wien',
            'bio' => 'Ihr persönlicher Ansprechpartner für Kauf, Verkauf und Vermietung in Wien.',
            'booking_url' => 'https://calendly.com/juliawagner',
        ],
        'fotografen' => [
            'slug' => 'lukas-meier', 'display_name' => 'Lukas Meier', 'job_title' => 'Hochzeitsfotograf',
            'company' => 'Meier Photography', 'phone' => '+43 676 5554433', 'email' => 'hallo@meier-photography.at',
            'website' => 'meier-photography.at', 'instagram_url' => 'https://instagram.com/meierphotography',
            'bio' => 'Authentische Hochzeitsfotos, die eure Geschichte erzählen.',
            'booking_url' => 'https://calendly.com/meierphotography',
        ],
        'selbststaendige' => [
            'slug' => 'nina-hofer', 'display_name' => 'Nina Hofer', 'job_title' => 'Grafikdesignerin & Texterin',
            'phone' => '+43 650 9998877', 'email' => 'nina@ninahofer.design', 'website' => 'ninahofer.design',
            'linkedin_url' => 'https://linkedin.com/in/ninahofer',
            'bio' => 'Freiberufliche Designerin für Branding, Web und Print.',
            'booking_url' => 'https://calendly.com/ninahofer',
        ],
        'unternehmen' => [
            'slug' => 'michael-berg', 'display_name' => 'Michael Berg', 'job_title' => 'Vertrieb',
            'company' => 'Berg & Partner GmbH', 'phone' => '+43 1 2345678', 'email' => 'michael.berg@bergpartner.at',
            'website' => 'bergpartner.at', 'address' => 'Opernring 8, 1010 Wien',
            'bio' => 'Ihr Ansprechpartner für individuelle Versicherungslösungen.',
            'use_custom_colors' => true,
            'color_background' => Organization::COLOR_PRESETS['graphite']['color_background'],
            'color_header' => Organization::COLOR_PRESETS['graphite']['color_header'],
            'color_content' => Organization::COLOR_PRESETS['graphite']['color_content'],
            'color_footer' => Organization::COLOR_PRESETS['graphite']['color_footer'],
        ],
    ];

    public function __construct(private View $view)
    {
    }

    public function show(array $params): void
    {
        $design = $params['design'] ?? '';
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $persona = $_GET['persona'] ?? 'default';
        if (!array_key_exists($persona, self::PERSONAS)) {
            $persona = 'default';
        }

        $card = array_merge(self::BASE_DEFAULTS, self::PERSONAS[$persona]);
        $card['design'] = $design;
        $card['owner_plan'] = Features::PRO;

        echo $this->view->render("card/designs/{$design}.twig", [
            'card' => $card,
            'meta_description' => '',
            'og_image_url' => null,
            'structured_data_json' => '{}',
            'qr_url' => '/qr/demo.svg',
        ]);
    }
}
