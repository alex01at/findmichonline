<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class View
{
    private Environment $twig;

    public function __construct(string $templatesPath, string $cachePath, bool $debug, Translator $translator, Auth $auth)
    {
        $loader = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : $cachePath,
            'debug' => $debug,
        ]);

        $this->twig->addGlobal('flashes', Session::pullFlashes());
        $this->twig->addGlobal('auth_check', Session::get('user_id') !== null);
        $this->twig->addGlobal('is_admin', $auth->isAdmin());
        $this->twig->addGlobal('locale', $translator->locale());
        $this->twig->addFunction(new TwigFunction(
            'trans',
            fn (string $key, array $replacements = []) => $translator->trans($key, $replacements)
        ));
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }
}
