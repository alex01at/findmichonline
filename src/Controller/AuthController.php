<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Mailer;
use Kartenlink\App\Support\PasswordPolicy;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;

final class AuthController
{
    private const RESET_TOKEN_TTL_SECONDS = 3600;
    private const TRIAL_DAYS = 7;

    private User $users;

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private Mailer $mailer,
        private string $appUrl
    ) {
        $this->users = new User($db);
    }

    public function showRegister(): void
    {
        // A demo account is still a real, logged-in session (auth->check()
        // is true) - but its whole purpose is to funnel into a real
        // registration, so it must not be bounced back to the dashboard here.
        $user = $this->auth->check() ? $this->auth->user() : null;
        if ($user !== null && empty($user['is_demo'])) {
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

        if (!PasswordPolicy::isValid($password)) {
            $errors[] = $this->translator->trans('auth.register.errors.password_requirements');
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
        $this->users->startTrial($userId, self::TRIAL_DAYS);
        $this->auth->login(['id' => $userId]);

        Session::flash('success', $this->translator->trans('auth.register.success'));
        $this->redirect('/dashboard');
    }

    public function showLogin(): void
    {
        $user = $this->auth->check() ? $this->auth->user() : null;
        if ($user !== null && empty($user['is_demo'])) {
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

        $this->auth->login($user, remember: isset($_POST['remember']));
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $this->auth->logout();
        $this->redirect('/login');
    }

    public function showForgotPassword(): void
    {
        $user = $this->auth->check() ? $this->auth->user() : null;
        if ($user !== null && empty($user['is_demo'])) {
            $this->redirect('/dashboard');
        }

        echo $this->view->render('auth/forgot_password.twig');
    }

    public function forgotPassword(): void
    {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $user = $email !== '' ? $this->users->findByEmail($email) : null;

        if ($user !== null) {
            $token = bin2hex(random_bytes(32));
            $this->users->setPasswordResetToken((int) $user['id'], $token, self::RESET_TOKEN_TTL_SECONDS);

            $link = $this->appUrl . '/reset-password/' . $token;
            $this->mailer->send(
                $user['email'],
                $this->translator->trans('auth.forgot_password.email_subject'),
                $this->translator->trans('auth.forgot_password.email_body', ['link' => $link])
            );
        }

        // Always show the same message, whether or not the address is registered,
        // so this form can't be used to check which emails have an account.
        Session::flash('success', $this->translator->trans('auth.forgot_password.sent'));
        $this->redirect('/forgot-password');
    }

    public function showResetPassword(array $params): void
    {
        $user = $this->users->findByValidResetToken($params['token']);
        if ($user === null) {
            echo $this->view->render('auth/reset_password.twig', ['invalid_token' => true]);
            return;
        }

        echo $this->view->render('auth/reset_password.twig', ['token' => $params['token']]);
    }

    public function resetPassword(array $params): void
    {
        $token = $params['token'];
        $user = $this->users->findByValidResetToken($token);

        if ($user === null) {
            echo $this->view->render('auth/reset_password.twig', ['invalid_token' => true]);
            return;
        }

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        $errors = [];
        if (!PasswordPolicy::isValid($password)) {
            $errors[] = $this->translator->trans('auth.register.errors.password_requirements');
        } elseif ($password !== $passwordConfirm) {
            $errors[] = $this->translator->trans('auth.register.errors.password_mismatch');
        }

        if ($errors !== []) {
            echo $this->view->render('auth/reset_password.twig', ['token' => $token, 'errors' => $errors]);
            return;
        }

        $this->users->resetPassword((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));

        Session::flash('success', $this->translator->trans('auth.reset_password.success'));
        $this->redirect('/login');
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
