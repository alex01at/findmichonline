<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

final class AdminController
{
    private User $users;
    private BusinessCard $cards;

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private LogoUploader $logoUploader
    ) {
        $this->users = new User($db);
        $this->cards = new BusinessCard($db);
    }

    public function index(): void
    {
        echo $this->view->render('admin/users.twig', [
            'users' => $this->users->findAllWithCardInfo(),
        ]);
    }

    public function showCreateUser(): void
    {
        echo $this->view->render('admin/user_create.twig');
    }

    public function createUser(): void
    {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $plan = $_POST['plan'] ?? Features::FREE;
        $isAdmin = isset($_POST['is_admin']);

        if (!in_array($plan, [Features::FREE, Features::PRO], true)) {
            $plan = Features::FREE;
        }

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
        }

        if ($errors !== []) {
            echo $this->view->render('admin/user_create.twig', [
                'errors' => $errors,
                'old' => ['name' => $name, 'email' => $email, 'plan' => $plan, 'is_admin' => $isAdmin],
            ]);
            return;
        }

        $userId = $this->users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
        $this->users->updateProfile($userId, $name, $email, $plan, $isAdmin);

        Session::flash('success', $this->translator->trans('admin.user_created'));
        header('Location: /admin');
        exit;
    }

    public function showEditUser(array $params): void
    {
        $user = $this->users->findById((int) $params['id']);
        if ($user === null) {
            http_response_code(404);
            echo $this->view->render('admin/not_found.twig');
            return;
        }

        $this->renderEditUser($user, $this->cards->findByUserId((int) $user['id']));
    }

    public function updateUser(array $params): void
    {
        $userId = (int) $params['id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            http_response_code(404);
            echo $this->view->render('admin/not_found.twig');
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $plan = $_POST['plan'] ?? Features::FREE;
        $isAdmin = isset($_POST['is_admin']);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        if (!in_array($plan, [Features::FREE, Features::PRO], true)) {
            $plan = Features::FREE;
        }

        $errors = [];

        if ($name === '') {
            $errors[] = $this->translator->trans('auth.register.errors.name_required');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('auth.register.errors.email_invalid');
        } else {
            $existing = $this->users->findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== $userId) {
                $errors[] = $this->translator->trans('auth.register.errors.email_taken');
            }
        }

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors[] = $this->translator->trans('auth.register.errors.password_too_short');
        }

        // An admin may not remove their own admin flag; avoids locking everyone out.
        if ($userId === (int) $this->auth->user()['id'] && !$isAdmin) {
            $errors[] = $this->translator->trans('admin.errors.cannot_remove_own_admin');
        }

        if ($errors !== []) {
            echo $this->view->render('admin/user_edit.twig', [
                'target_user' => array_merge($user, [
                    'name' => $name, 'email' => $email, 'plan' => $plan, 'is_admin' => $isAdmin,
                ]),
                'card' => $this->cards->findByUserId($userId),
                'errors' => $errors,
            ]);
            return;
        }

        $this->users->updateProfile($userId, $name, $email, $plan, $isAdmin);
        if ($newPassword !== '') {
            $this->users->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        }

        Session::flash('success', $this->translator->trans('admin.user_updated'));
        header('Location: /admin/users/' . $userId);
        exit;
    }

    public function deleteUser(array $params): void
    {
        $userId = (int) $params['id'];

        if ($userId === (int) $this->auth->user()['id']) {
            Session::flash('error', $this->translator->trans('admin.errors.cannot_delete_self'));
            header('Location: /admin');
            exit;
        }

        $this->users->delete($userId);
        $this->logoUploader->remove($userId);

        Session::flash('success', $this->translator->trans('admin.user_deleted'));
        header('Location: /admin');
        exit;
    }

    public function updateCard(array $params): void
    {
        $userId = (int) $params['id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            http_response_code(404);
            echo $this->view->render('admin/not_found.twig');
            return;
        }

        $existing = $this->cards->findByUserId($userId);

        $displayName = trim($_POST['display_name'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $openingHours = trim($_POST['opening_hours'] ?? '');
        $slugInput = trim(strtolower($_POST['slug'] ?? ''));
        $isPublished = isset($_POST['is_published']);
        $removeLogo = isset($_POST['remove_logo']);

        $design = $_POST['design'] ?? 'classic';
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            $design = 'classic';
        }

        $errors = [];

        if ($displayName === '') {
            $errors[] = $this->translator->trans('card.edit.errors.name_required');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('card.edit.errors.email_invalid');
        }

        if ($slugInput === '') {
            $slug = $this->cards->generateUniqueSlug($displayName !== '' ? $displayName : 'karte', $userId, BusinessCard::RESERVED_SLUGS);
        } elseif (!preg_match('/^[a-z0-9-]{3,100}$/', $slugInput)) {
            $errors[] = $this->translator->trans('card.edit.errors.slug_invalid');
            $slug = $slugInput;
        } elseif (in_array($slugInput, BusinessCard::RESERVED_SLUGS, true)) {
            $errors[] = $this->translator->trans('card.edit.errors.slug_taken');
            $slug = $slugInput;
        } elseif ($this->cards->slugExists($slugInput, $userId)) {
            $errors[] = $this->translator->trans('card.edit.errors.slug_taken');
            $slug = $slugInput;
        } else {
            $slug = $slugInput;
        }

        $logoPath = $existing['logo_path'] ?? null;
        if ($removeLogo) {
            $logoPath = null;
        } elseif (isset($_FILES['logo'])) {
            try {
                $uploaded = $this->logoUploader->upload($userId, $_FILES['logo']);
                if ($uploaded !== null) {
                    $logoPath = $uploaded;
                }
            } catch (RuntimeException $e) {
                $errors[] = $this->translator->trans($e->getMessage());
            }
        }

        if ($errors !== []) {
            $this->renderEditUser($user, array_merge($existing ?? [], [
                'slug' => $slug,
                'display_name' => $displayName,
                'job_title' => $jobTitle,
                'company' => $company,
                'email' => $email,
                'phone' => $phone,
                'website' => $website,
                'address' => $address,
                'bio' => $bio,
                'opening_hours' => $openingHours,
                'logo_path' => $logoPath,
                'design' => $design,
                'is_published' => $isPublished,
            ]), $errors);
            return;
        }

        if ($removeLogo) {
            $this->logoUploader->remove($userId);
        }

        $this->cards->upsertForUser($userId, [
            'slug' => $slug,
            'display_name' => $displayName,
            'job_title' => $jobTitle !== '' ? $jobTitle : null,
            'company' => $company !== '' ? $company : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'website' => $website !== '' ? $website : null,
            'address' => $address !== '' ? $address : null,
            'bio' => $bio !== '' ? $bio : null,
            'opening_hours' => $openingHours !== '' ? $openingHours : null,
            'logo_path' => $logoPath,
            'design' => $design,
            'is_published' => $isPublished,
        ]);

        Session::flash('success', $this->translator->trans('admin.card_updated'));
        header('Location: /admin/users/' . $userId);
        exit;
    }

    public function deleteCard(array $params): void
    {
        $userId = (int) $params['id'];
        $this->cards->deleteForUser($userId);
        $this->logoUploader->remove($userId);

        Session::flash('success', $this->translator->trans('admin.card_deleted'));
        header('Location: /admin/users/' . $userId);
        exit;
    }

    private function renderEditUser(array $user, ?array $card, array $errors = []): void
    {
        echo $this->view->render('admin/user_edit.twig', [
            'target_user' => $user,
            'card' => $card,
            'errors' => $errors,
        ]);
    }
}
