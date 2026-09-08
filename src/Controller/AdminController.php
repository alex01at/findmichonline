<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\CardGalleryImage;
use Kartenlink\App\Model\CardOffering;
use Kartenlink\App\Model\LegalPage;
use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\GalleryUploader;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\PasswordPolicy;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

final class AdminController
{
    private User $users;
    private BusinessCard $cards;
    private LegalPage $legalPages;
    private CardGalleryImage $galleryImages;
    private CardOffering $offerings;

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private LogoUploader $logoUploader,
        private GalleryUploader $galleryUploader
    ) {
        $this->users = new User($db);
        $this->cards = new BusinessCard($db);
        $this->legalPages = new LegalPage($db);
        $this->galleryImages = new CardGalleryImage($db);
        $this->offerings = new CardOffering($db);
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

        if (!PasswordPolicy::isValid($password)) {
            $errors[] = $this->translator->trans('auth.register.errors.password_requirements');
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

        if ($newPassword !== '' && !PasswordPolicy::isValid($newPassword)) {
            $errors[] = $this->translator->trans('auth.register.errors.password_requirements');
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
        $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
        $instagramUrl = trim($_POST['instagram_url'] ?? '');
        $facebookUrl = trim($_POST['facebook_url'] ?? '');
        $youtubeUrl = trim($_POST['youtube_url'] ?? '');
        $bookingUrl = trim($_POST['booking_url'] ?? '');
        $slugInput = trim(strtolower($_POST['slug'] ?? ''));
        $isPublished = isset($_POST['is_published']);
        $removeLogo = isset($_POST['remove_logo']);

        $design = $_POST['design'] ?? 'classic';
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            $design = 'classic';
        }

        // Admin edits bypass the Free/Pro gate entirely (same as design_pro above).
        $useCustomColors = isset($_POST['use_custom_colors']);
        $colorBackground = trim($_POST['color_background'] ?? '');
        $colorHeader = trim($_POST['color_header'] ?? '');
        $colorContent = trim($_POST['color_content'] ?? '');
        $colorFooter = trim($_POST['color_footer'] ?? '');

        $errors = [];

        if ($displayName === '') {
            $errors[] = $this->translator->trans('card.edit.errors.name_required');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('card.edit.errors.email_invalid');
        }

        foreach ([$linkedinUrl, $instagramUrl, $facebookUrl, $youtubeUrl] as $socialUrl) {
            if ($socialUrl !== '' && !filter_var($socialUrl, FILTER_VALIDATE_URL)) {
                $errors[] = $this->translator->trans('card.edit.errors.social_url_invalid');
                break;
            }
        }

        if ($bookingUrl !== '' && !filter_var($bookingUrl, FILTER_VALIDATE_URL)) {
            $errors[] = $this->translator->trans('card.edit.errors.booking_url_invalid');
        }

        if ($useCustomColors) {
            foreach ([$colorBackground, $colorHeader, $colorContent, $colorFooter] as $color) {
                if ($color !== '' && !BusinessCard::isValidHexColor($color)) {
                    $errors[] = $this->translator->trans('card.edit.errors.color_invalid');
                    break;
                }
            }
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
                'linkedin_url' => $linkedinUrl,
                'instagram_url' => $instagramUrl,
                'facebook_url' => $facebookUrl,
                'youtube_url' => $youtubeUrl,
                'booking_url' => $bookingUrl,
                'design' => $design,
                'use_custom_colors' => $useCustomColors,
                'color_background' => $colorBackground,
                'color_header' => $colorHeader,
                'color_content' => $colorContent,
                'color_footer' => $colorFooter,
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
            'linkedin_url' => $linkedinUrl !== '' ? $linkedinUrl : null,
            'instagram_url' => $instagramUrl !== '' ? $instagramUrl : null,
            'facebook_url' => $facebookUrl !== '' ? $facebookUrl : null,
            'youtube_url' => $youtubeUrl !== '' ? $youtubeUrl : null,
            'booking_url' => $bookingUrl !== '' ? $bookingUrl : null,
            'design' => $design,
            'use_custom_colors' => $useCustomColors,
            'color_background' => $useCustomColors && $colorBackground !== '' ? $colorBackground : null,
            'color_header' => $useCustomColors && $colorHeader !== '' ? $colorHeader : null,
            'color_content' => $useCustomColors && $colorContent !== '' ? $colorContent : null,
            'color_footer' => $useCustomColors && $colorFooter !== '' ? $colorFooter : null,
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

    public function showLegal(): void
    {
        echo $this->view->render('admin/legal.twig', [
            'pages' => $this->legalPages->all(),
        ]);
    }

    public function updateLegal(): void
    {
        foreach (LegalPage::PAGES as $slug) {
            $this->legalPages->update(
                $slug,
                trim($_POST['content_de_' . $slug] ?? ''),
                trim($_POST['content_en_' . $slug] ?? '')
            );
        }

        Session::flash('success', $this->translator->trans('admin.legal_saved'));
        header('Location: /admin/legal');
        exit;
    }

    public function deleteGalleryImage(array $params): void
    {
        $userId = (int) $params['id'];
        $card = $this->cards->findByUserId($userId);
        $image = $this->galleryImages->find((int) $params['imageId']);

        if ($card !== null && $image !== null && (int) $image['business_card_id'] === (int) $card['id']) {
            $this->galleryImages->delete((int) $image['id']);
            $this->galleryUploader->remove($image['image_path']);
        }

        header('Location: /admin/users/' . $userId);
        exit;
    }

    public function deleteOffering(array $params): void
    {
        $userId = (int) $params['id'];
        $card = $this->cards->findByUserId($userId);
        $offering = $this->offerings->find((int) $params['offeringId']);

        if ($card !== null && $offering !== null && (int) $offering['business_card_id'] === (int) $card['id']) {
            $this->offerings->delete((int) $offering['id']);
        }

        header('Location: /admin/users/' . $userId);
        exit;
    }

    private function renderEditUser(array $user, ?array $card, array $errors = []): void
    {
        echo $this->view->render('admin/user_edit.twig', [
            'target_user' => $user,
            'card' => $card,
            'gallery_images' => $card !== null ? $this->galleryImages->findByCardId((int) $card['id']) : [],
            'offerings' => $card !== null ? $this->offerings->findByCardId((int) $card['id']) : [],
            'errors' => $errors,
        ]);
    }
}
