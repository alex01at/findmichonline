<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\LegalPage;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;

/**
 * Public Impressum/Datenschutz pages. Content is stored in the
 * legal_pages table and edited via the admin panel (AdminController),
 * not through a deploy — falls back to a placeholder note if an admin
 * hasn't filled in that language yet.
 */
final class LegalController
{
    private LegalPage $pages;

    public function __construct(private View $view, private Translator $translator, PDO $db, private string $locale)
    {
        $this->pages = new LegalPage($db);
    }

    public function show(string $slug): void
    {
        $page = $this->pages->find($slug);

        if ($page === null) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $content = trim((string) ($page['content_' . $this->locale] ?? ''));

        echo $this->view->render('legal/placeholder.twig', [
            'heading' => $this->translator->trans('footer.' . $slug),
            'content' => $content !== '' ? $content : null,
        ]);
    }
}
