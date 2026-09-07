<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\View;

final class HomeController
{
    public function __construct(private View $view, private Auth $auth)
    {
    }

    public function index(): void
    {
        if ($this->auth->check()) {
            header('Location: /dashboard');
            exit;
        }

        echo $this->view->render('home.twig');
    }
}
