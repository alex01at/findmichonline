<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\CardGalleryImage;
use Kartenlink\App\Model\CardOffering;
use Kartenlink\App\Model\Category;
use Kartenlink\App\Model\Organization;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Features;
use Kartenlink\App\Support\GalleryUploader;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

final class CardController
{
    private BusinessCard $cards;
    private CardGalleryImage $galleryImages;
    private CardOffering $offerings;
    private Category $categories;

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private LogoUploader $logoUploader,
        private LogoUploader $photoUploader,
        private GalleryUploader $galleryUploader,
        private string $appUrl
    ) {
        $this->cards = new BusinessCard($db);
        $this->galleryImages = new CardGalleryImage($db);
        $this->offerings = new CardOffering($db);
        $this->categories = new Category($db);
    }

    public function edit(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);
        $org = $this->auth->organization();
        $isTeamMember = $org !== null && !$this->auth->isOrgOwner();

        if ($isTeamMember && $card !== null) {
            $card = Organization::applyBranding($card, $org);
        }

        echo $this->view->render('card/edit.twig', [
            'card' => $card,
            'old' => $card,
            'is_team_member' => $isTeamMember,
            'organization' => $org,
            'design_pro_allowed' => $this->auth->can('design_pro'),
            'design_trial_notice' => $this->auth->isOnIndividualTrial(),
            'custom_colors_allowed' => $this->auth->can('custom_colors'),
            'gallery_allowed' => $this->auth->can('gallery'),
            'gallery_images' => $card !== null ? $this->galleryImages->findByCardId((int) $card['id']) : [],
            'gallery_max' => CardGalleryImage::MAX_IMAGES,
            'offerings_allowed' => $this->auth->can('offerings'),
            'offerings' => $card !== null ? $this->offerings->findByCardId((int) $card['id']) : [],
            'offerings_max' => CardOffering::MAX_OFFERINGS,
            'booking_link_allowed' => $this->auth->can('booking_link'),
            'categories' => $this->categories->all($this->translator->locale()),
        ]);
    }

    public function save(): void
    {
        $user = $this->auth->user();
        $userId = (int) $user['id'];
        $existing = $this->cards->findByUserId($userId);
        $org = $this->auth->organization();
        $isTeamMember = $org !== null && !$this->auth->isOrgOwner();

        $displayName = trim($_POST['display_name'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $company = $isTeamMember ? '' : trim($_POST['company'] ?? '');
        $categoryId = trim($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
        if ($categoryId !== null && !$this->categories->exists($categoryId)) {
            $categoryId = null;
        }
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $address = $isTeamMember ? '' : trim($_POST['address'] ?? '');
        $workplace = trim($_POST['workplace'] ?? '');
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
        $removePhoto = isset($_POST['remove_photo']);

        $design = $_POST['design'] ?? 'classic';
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            $design = 'classic';
        }
        $designDowngraded = false;
        if ($design !== 'classic' && !$this->auth->can('design_pro')) {
            $design = 'classic';
            $designDowngraded = true;
        }
        if ($isTeamMember) {
            // The organization's design is applied live at render time
            // (Organization::applyBranding()) regardless of what's stored
            // here - keep whatever the row already had rather than letting
            // a tampered request change it to no effect.
            $design = $existing['design'] ?? 'classic';
        }

        $useCustomColors = isset($_POST['use_custom_colors']);
        $colorBackground = trim($_POST['color_background'] ?? '');
        $colorHeader = trim($_POST['color_header'] ?? '');
        $colorContent = trim($_POST['color_content'] ?? '');
        $colorFooter = trim($_POST['color_footer'] ?? '');
        $colorsDowngraded = false;
        if ($useCustomColors && !$this->auth->can('custom_colors')) {
            $useCustomColors = false;
            $colorsDowngraded = true;
        }

        $bookingDowngraded = false;
        if ($bookingUrl !== '' && !$this->auth->can('booking_link')) {
            $bookingUrl = '';
            $bookingDowngraded = true;
        }

        $errors = [];

        if ($useCustomColors) {
            foreach ([$colorBackground, $colorHeader, $colorContent, $colorFooter] as $color) {
                if ($color !== '' && !BusinessCard::isValidHexColor($color)) {
                    $errors[] = $this->translator->trans('card.edit.errors.color_invalid');
                    break;
                }
            }
        }

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
        if ($isTeamMember) {
            // Company logo is fixed by the organization owner (/team/branding);
            // an employee's own upload/remove intent is ignored entirely.
        } elseif ($removeLogo) {
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

        $photoPath = $existing['photo_path'] ?? null;
        if ($removePhoto) {
            $photoPath = null;
        } elseif (isset($_FILES['photo'])) {
            try {
                $uploaded = $this->photoUploader->upload($userId, $_FILES['photo']);
                if ($uploaded !== null) {
                    $photoPath = $uploaded;
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
                    'category_id' => $categoryId,
                    'email' => $email,
                    'phone' => $phone,
                    'whatsapp' => $whatsapp,
                    'website' => $website,
                    'address' => $address,
                    'workplace' => $workplace,
                    'bio' => $bio,
                    'opening_hours' => $openingHours,
                    'logo_path' => $logoPath,
                    'photo_path' => $photoPath,
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
                ],
                'errors' => $errors,
                'is_team_member' => $isTeamMember,
                'organization' => $org,
                'design_pro_allowed' => $this->auth->can('design_pro'),
                'design_trial_notice' => $this->auth->isOnIndividualTrial(),
                'custom_colors_allowed' => $this->auth->can('custom_colors'),
                'gallery_allowed' => $this->auth->can('gallery'),
                'gallery_images' => $existing !== null ? $this->galleryImages->findByCardId((int) $existing['id']) : [],
                'gallery_max' => CardGalleryImage::MAX_IMAGES,
                'offerings_allowed' => $this->auth->can('offerings'),
                'offerings' => $existing !== null ? $this->offerings->findByCardId((int) $existing['id']) : [],
                'offerings_max' => CardOffering::MAX_OFFERINGS,
                'booking_link_allowed' => $this->auth->can('booking_link'),
                'categories' => $this->categories->all($this->translator->locale()),
            ]);
            return;
        }

        if ($removeLogo && !$isTeamMember) {
            $this->logoUploader->remove($userId);
        }
        if ($removePhoto) {
            $this->photoUploader->remove($userId);
        }

        $this->cards->upsertForUser($userId, [
            'slug' => $slug,
            'display_name' => $displayName,
            'job_title' => $jobTitle !== '' ? $jobTitle : null,
            'company' => $company !== '' ? $company : null,
            'category_id' => $categoryId,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
            'website' => $website !== '' ? $website : null,
            'address' => $address !== '' ? $address : null,
            'workplace' => $workplace !== '' ? $workplace : null,
            'bio' => $bio !== '' ? $bio : null,
            'opening_hours' => $openingHours !== '' ? $openingHours : null,
            'logo_path' => $logoPath,
            'photo_path' => $photoPath,
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

        $savedCard = $this->cards->findByUserId($userId);
        $this->cards->markOnboardingDoneIfNeeded((int) $savedCard['id']);

        Session::flash('success', $this->translator->trans('card.edit.success'));
        if ($designDowngraded) {
            Session::flash('error', $this->translator->trans('card.edit.design_downgraded'));
        }
        if ($colorsDowngraded) {
            Session::flash('error', $this->translator->trans('card.edit.colors_downgraded'));
        }
        if ($bookingDowngraded) {
            Session::flash('error', $this->translator->trans('card.edit.booking_downgraded'));
        }
        header('Location: /card/edit');
        exit;
    }

    /**
     * The gallery tab uploads via XHR so it can show a progress bar and
     * avoid a full page reload (which used to reset the tabs back to
     * "Inhalt"). Falls back to the old redirect flow when JS isn't
     * driving the request, so the plain <form> still works without JS.
     */
    public function uploadGalleryImage(): void
    {
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        $error = null;
        $image = null;

        if ($card === null || !$this->auth->can('gallery')) {
            $error = $this->translator->trans('card.gallery.errors.upload_failed');
        } elseif ($this->galleryImages->countByCardId((int) $card['id']) >= CardGalleryImage::MAX_IMAGES) {
            $error = $this->translator->trans('card.gallery.errors.limit_reached');
        } else {
            try {
                $path = $this->galleryUploader->upload((int) $card['id'], $_FILES['gallery_image'] ?? null);
            } catch (RuntimeException $e) {
                $path = null;
                $error = $this->translator->trans($e->getMessage());
            }

            if ($error === null && $path === null) {
                $error = $this->translator->trans('card.gallery.errors.upload_failed');
            } elseif ($error === null) {
                $sortOrder = $this->galleryImages->countByCardId((int) $card['id']);
                $this->galleryImages->add((int) $card['id'], $path, $sortOrder);
                $images = $this->galleryImages->findByCardId((int) $card['id']);
                $image = end($images);
            }
        }

        $remaining = $card !== null
            ? CardGalleryImage::MAX_IMAGES - $this->galleryImages->countByCardId((int) $card['id'])
            : 0;

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $error === null,
                'error' => $error,
                'image' => $image !== null ? ['id' => (int) $image['id'], 'image_path' => $image['image_path']] : null,
                'remaining' => $remaining,
            ]);
            return;
        }

        Session::flash($error === null ? 'success' : 'error', $error ?? $this->translator->trans('card.gallery.upload_success'));
        header('Location: /card/edit');
        exit;
    }

    public function deleteGalleryImage(array $params): void
    {
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);
        $image = $this->galleryImages->find((int) $params['id']);

        $deleted = false;
        // Only ever act if the image actually belongs to the logged-in user's own card.
        if ($card !== null && $image !== null && (int) $image['business_card_id'] === (int) $card['id']) {
            $this->galleryImages->delete((int) $image['id']);
            $this->galleryUploader->remove($image['image_path']);
            $deleted = true;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $deleted]);
            return;
        }

        header('Location: /card/edit');
        exit;
    }

    /**
     * Same AJAX-with-redirect-fallback pattern as the gallery, for the same
     * reason: a plain form POST here would reload the page and reset the
     * tabs back to "Inhalt".
     */
    public function addOffering(): void
    {
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = trim($_POST['price'] ?? '');

        $error = null;
        $offering = null;

        if ($card === null || !$this->auth->can('offerings')) {
            $error = $this->translator->trans('card.offerings.errors.generic');
        } elseif ($title === '') {
            $error = $this->translator->trans('card.offerings.errors.title_required');
        } elseif ($this->offerings->countByCardId((int) $card['id']) >= CardOffering::MAX_OFFERINGS) {
            $error = $this->translator->trans('card.offerings.errors.limit_reached');
        } else {
            $sortOrder = $this->offerings->countByCardId((int) $card['id']);
            $this->offerings->add(
                (int) $card['id'],
                $title,
                $description !== '' ? $description : null,
                $price !== '' ? $price : null,
                $sortOrder
            );
            $rows = $this->offerings->findByCardId((int) $card['id']);
            $offering = end($rows);
        }

        $remaining = $card !== null
            ? CardOffering::MAX_OFFERINGS - $this->offerings->countByCardId((int) $card['id'])
            : 0;

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $error === null,
                'error' => $error,
                'offering' => $offering !== null ? [
                    'id' => (int) $offering['id'],
                    'title' => $offering['title'],
                    'description' => $offering['description'],
                    'price' => $offering['price'],
                ] : null,
                'remaining' => $remaining,
            ]);
            return;
        }

        Session::flash($error === null ? 'success' : 'error', $error ?? $this->translator->trans('card.offerings.add_success'));
        header('Location: /card/edit');
        exit;
    }

    public function deleteOffering(array $params): void
    {
        $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);
        $offering = $this->offerings->find((int) $params['id']);

        $deleted = false;
        if ($card !== null && $offering !== null && (int) $offering['business_card_id'] === (int) $card['id']) {
            $this->offerings->delete((int) $offering['id']);
            $deleted = true;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $deleted]);
            return;
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

        $card = $this->withOrgBranding($card);
        $this->cards->incrementViewCount((int) $card['id']);

        $design = in_array($card['design'], BusinessCard::AVAILABLE_DESIGNS, true) ? $card['design'] : 'classic';
        $cardUrl = $this->appUrl . '/' . $card['slug'];
        // Prefer the personal profile photo over the company logo for link
        // previews - more recognizable for a person's card, same fallback
        // order the design templates already use for the avatar circle.
        $avatarPath = $card['photo_path'] ?: $card['logo_path'];
        $avatarUrl = $avatarPath ? $this->appUrl . '/' . $avatarPath : null;
        $categoryName = $card['category_id']
            ? ($this->translator->locale() === 'de' ? $card['category_name_de'] : $card['category_name_en'])
            : null;

        echo $this->view->render("card/designs/{$design}.twig", [
            'card' => $card,
            'meta_description' => $this->buildMetaDescription($card),
            'og_image_url' => $avatarUrl,
            'structured_data_json' => $this->buildStructuredData($card, $cardUrl, $avatarUrl),
            'gallery_images' => $this->galleryImages->findByCardId((int) $card['id']),
            'offerings' => $this->offerings->findByCardId((int) $card['id']),
            'category_name' => $categoryName,
        ]);
    }

    /**
     * Every clickable contact/social link on a public card points here
     * instead of straight at tel:/mailto:/etc., so each click can be
     * counted per link type before redirecting to the real destination.
     */
    public function trackClick(array $params): void
    {
        $card = $this->cards->findPublishedBySlug($params['slug']);
        $card = $card !== null ? $this->withOrgBranding($card) : null;
        $target = $card !== null ? $this->buildLinkTarget($card, $params['type']) : null;

        if ($target === null) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $this->cards->incrementClickCount((int) $card['id'], $params['type']);

        header('Location: ' . $target);
        exit;
    }

    /**
     * Lets a visitor save the card's details straight into their phone
     * contacts. Pro-only, same as the footer branding removal: gated on
     * the card owner's current plan, not on whether a visitor is logged in.
     */
    public function downloadVcard(array $params): void
    {
        $card = $this->cards->findPublishedBySlug($params['slug']);

        if ($card === null || ($card['owner_plan'] ?? null) !== Features::PRO) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $card = $this->withOrgBranding($card);
        $avatarPath = $card['photo_path'] ?: $card['logo_path'];
        $avatarUrl = $avatarPath ? $this->appUrl . '/' . $avatarPath : null;
        $vcard = $this->buildVCard($card, $avatarUrl);

        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $card['slug'] . '.vcf"');
        header('Content-Length: ' . (string) strlen($vcard));
        echo $vcard;
    }

    private function buildVCard(array $card, ?string $logoUrl): string
    {
        $lines = ['BEGIN:VCARD', 'VERSION:3.0'];
        $lines[] = 'FN:' . $this->escapeVCardValue($card['display_name']);
        $lines[] = 'N:' . $this->escapeVCardValue($card['display_name']) . ';;;;';

        if (!empty($card['job_title'])) {
            $lines[] = 'TITLE:' . $this->escapeVCardValue($card['job_title']);
        }
        if (!empty($card['company'])) {
            $lines[] = 'ORG:' . $this->escapeVCardValue($card['company']);
        }
        if (!empty($card['phone'])) {
            $lines[] = 'TEL;TYPE=WORK,VOICE:' . $this->escapeVCardValue($card['phone']);
        }
        if (!empty($card['email'])) {
            $lines[] = 'EMAIL;TYPE=INTERNET:' . $this->escapeVCardValue($card['email']);
        }
        if (!empty($card['website'])) {
            $url = str_starts_with($card['website'], 'http') ? $card['website'] : 'https://' . $card['website'];
            $lines[] = 'URL:' . $this->escapeVCardValue($url);
        }
        if (!empty($card['address'])) {
            $addressLine = $card['address'] . (!empty($card['workplace']) ? ', ' . $card['workplace'] : '');
            $lines[] = 'ADR;TYPE=WORK:;;' . $this->escapeVCardValue($addressLine) . ';;;;';
        }
        if (!empty($card['bio'])) {
            $lines[] = 'NOTE:' . $this->escapeVCardValue($card['bio']);
        }
        if ($logoUrl !== null) {
            $lines[] = 'PHOTO;VALUE=uri:' . $logoUrl;
        }

        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function escapeVCardValue(string $value): string
    {
        return str_replace(
            ['\\', ',', ';', "\r\n", "\n"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n'],
            $value
        );
    }

    /**
     * A Firma-plan employee's card shows the organization's fixed company
     * name/logo/address/design instead of whatever ended up stored on their
     * own row (the onboarding wizard and editor never let them set those
     * fields in the first place) - resolved live here so an owner changing
     * the branding later takes effect immediately, without the employee
     * needing to re-save their card. findPublishedBySlug() already joins
     * the org columns onto $card, so this is a cheap in-memory check.
     */
    private function withOrgBranding(array $card): array
    {
        if (empty($card['org_id']) || (int) $card['org_owner_user_id'] === (int) $card['user_id']) {
            return $card;
        }

        return Organization::applyBranding($card, [
            'name' => $card['org_name'],
            'logo_path' => $card['org_logo_path'],
            'address' => $card['org_address'],
            'design' => $card['org_design'],
            'color_preset' => $card['org_color_preset'],
        ]);
    }

    private function buildLinkTarget(array $card, string $type): ?string
    {
        return match ($type) {
            'phone' => $card['phone'] ? 'tel:' . $card['phone'] : null,
            'whatsapp' => $card['whatsapp'] ? 'https://wa.me/' . preg_replace('/\D/', '', $card['whatsapp']) : null,
            'email' => $card['email'] ? 'mailto:' . $card['email'] : null,
            'website' => $card['website']
                ? (str_starts_with($card['website'], 'http') ? $card['website'] : 'https://' . $card['website'])
                : null,
            'address' => $card['address']
                ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($card['address'])
                : null,
            'linkedin' => $card['linkedin_url'] ?: null,
            'instagram' => $card['instagram_url'] ?: null,
            'facebook' => $card['facebook_url'] ?: null,
            'youtube' => $card['youtube_url'] ?: null,
            'booking' => ($card['owner_plan'] ?? null) === Features::PRO ? ($card['booking_url'] ?: null) : null,
            default => null,
        };
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
