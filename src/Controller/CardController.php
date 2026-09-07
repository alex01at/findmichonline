<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

final class CardController
{
    private BusinessCard $cards;

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private LogoUploader $logoUploader,
        private string $appUrl
    ) {
        $this->cards = new BusinessCard($db);
    }

    public function edit(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        echo $this->view->render('card/edit.twig', [
            'card' => $card,
            'old' => $card,
            'design_modern_allowed' => $this->auth->can('design_modern'),
        ]);
    }

    public function save(): void
    {
        $user = $this->auth->user();
        $userId = (int) $user['id'];
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
        $slugInput = trim(strtolower($_POST['slug'] ?? ''));
        $isPublished = isset($_POST['is_published']);
        $removeLogo = isset($_POST['remove_logo']);

        $design = $_POST['design'] ?? 'classic';
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            $design = 'classic';
        }
        $designDowngraded = false;
        if ($design === 'modern' && !$this->auth->can('design_modern')) {
            $design = 'classic';
            $designDowngraded = true;
        }

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
            echo $this->view->render('card/edit.twig', [
                'card' => $existing,
                'old' => [
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
                    'design' => $design,
                    'is_published' => $isPublished,
                ],
                'errors' => $errors,
                'design_modern_allowed' => $this->auth->can('design_modern'),
            ]);
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
            'design' => $design,
            'is_published' => $isPublished,
        ]);

        Session::flash('success', $this->translator->trans('card.edit.success'));
        if ($designDowngraded) {
            Session::flash('error', $this->translator->trans('card.edit.design_downgraded'));
        }
        header('Location: /card/edit');
        exit;
    }

    public function showPublic(array $params): void
    {
        $card = $this->cards->findPublishedBySlug($params['slug']);

        if ($card === null) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $design = in_array($card['design'], BusinessCard::AVAILABLE_DESIGNS, true) ? $card['design'] : 'classic';
        $cardUrl = $this->appUrl . '/' . $card['slug'];
        $logoUrl = $card['logo_path'] ? $this->appUrl . '/' . $card['logo_path'] : null;

        echo $this->view->render("card/designs/{$design}.twig", [
            'card' => $card,
            'meta_description' => $this->buildMetaDescription($card),
            'og_image_url' => $logoUrl,
            'structured_data_json' => $this->buildStructuredData($card, $cardUrl, $logoUrl),
        ]);
    }

    private function buildStructuredData(array $card, string $cardUrl, ?string $logoUrl): string
    {
        $data = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $card['display_name'],
            'jobTitle' => $card['job_title'] ?: null,
            'worksFor' => $card['company'] ? ['@type' => 'Organization', 'name' => $card['company']] : null,
            'email' => $card['email'] ?: null,
            'telephone' => $card['phone'] ?: null,
            'url' => $cardUrl,
            'image' => $logoUrl,
            'address' => $card['address'] ? ['@type' => 'PostalAddress', 'streetAddress' => $card['address']] : null,
        ], static fn ($value) => $value !== null);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function buildMetaDescription(array $card): string
    {
        $parts = [];

        $role = trim($card['job_title'] . ($card['job_title'] && $card['company'] ? ' ' . $this->translator->trans('card.public.at') . ' ' : '') . $card['company']);
        if ($role !== '') {
            $parts[] = $role;
        }
        if (!empty($card['bio'])) {
            $parts[] = $card['bio'];
        }

        $description = $parts === [] ? $this->translator->trans('seo.default_description') : implode('. ', $parts);

        return mb_strimwidth($description, 0, 200, '…');
    }
}
