<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;

final class AuthController
{
    private User $users;

    public function __construct(private PDO $db, private View $view, private Auth $auth, private Translator $translator)
    {
        $this->users = new User($db);
    }

    public function showRegister(): void
    {
        if ($this->auth->check()) {
            $this->redirect('/dashboard');
        }

        echo $this->view->render('auth/register.twig');
    }

    public function register(): void
    {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];

        if ($name === '') {
            $errors[] = $this->translator->trans('auth.register.errors.name_required');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('auth.register.errors.email_invalid');
        } elseif ($this->users->findByEmail($email) !== null) {
            $errors[] = $this->translator->trans('auth.register.errors.email_taken');
        }

        if (strlen($password) < 8) {
            $errors[] = $this->translator->trans('auth.register.errors.password_too_short');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = $this->translator->trans('auth.register.errors.password_mismatch');
        }

        if ($errors !== []) {
            echo $this->view->render('auth/register.twig', [
                'errors' => $errors,
                'old' => ['name' => $name, 'email' => $email],
            ]);
            return;
        }

        $userId = $this->users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
        $this->auth->login(['id' => $userId]);

        Session::flash('success', $this->translator->trans('auth.register.success'));
        $this->redirect('/dashboard');
    }

    public function showLogin(): void
    {
        if ($this->auth->check()) {
            $this->redirect('/dashboard');
        }

        echo $this->view->render('auth/login.twig');
    }

    public function login(): void
    {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            echo $this->view->render('auth/login.twig', [
                'errors' => [$this->translator->trans('auth.login.errors.invalid_credentials')],
                'old' => ['email' => $email],
            ]);
            return;
        }

        $this->auth->login($user);
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $this->auth->logout();
        $this->redirect('/login');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
