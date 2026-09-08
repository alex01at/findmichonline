<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;

/**
 * Placeholder pages for the footer links (Impressum/Datenschutz/Kontakt)
 * until real legal content exists.
 */
final class LegalController
{
    private const PAGES = ['impressum', 'datenschutz', 'kontakt'];

    public function __construct(private View $view, private Translator $translator)
    {
    }

    public function show(string $page): void
    {
        if (!in_array($page, self::PAGES, true)) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        echo $this->view->render('legal/placeholder.twig', [
            'heading' => $this->translator->trans('footer.' . $page),
        ]);
    }
}
