<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\Organization;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

/**
 * Guided first-card setup for brand-new users. Reuses BusinessCard::upsertForUser()
 * for every step - there is no separate "draft" storage, a step save just writes
 * a partial update to the same business_cards row the normal editor uses, with
 * is_published staying 0 until the final publish step. onboarding_step tracks the
 * furthest step the user has reached (for resuming and to stop them skipping
 * ahead via a manipulated URL); onboarding_completed_at marks the wizard as done
 * and is checked by DashboardController to decide whether to show the wizard or
 * the normal dashboard.
 */
final class OnboardingController
{
    public const TOTAL_STEPS = 9;

    private BusinessCard $cards;

    /** step key => [label translation key, url placeholder] */
    public const SOCIAL_PLATFORMS = [
        'linkedin_url' => ['card.edit.linkedin_label', 'https://linkedin.com/in/...'],
        'instagram_url' => ['card.edit.instagram_label', 'https://instagram.com/...'],
        'facebook_url' => ['card.edit.facebook_label', 'https://facebook.com/...'],
        'youtube_url' => ['card.edit.youtube_label', 'https://youtube.com/@...'],
    ];

    public function __construct(
        private PDO $db,
        private View $view,
        private Auth $auth,
        private Translator $translator,
        private LogoUploader $logoUploader,
        private LogoUploader $photoUploader,
        private string $appUrl
    ) {
        $this->cards = new BusinessCard($db);
    }

    public function index(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card !== null && $card['onboarding_completed_at'] !== null) {
            header('Location: /dashboard');
            exit;
        }

        $step = $card !== null ? (int) $card['onboarding_step'] : 1;

        if ($card !== null && $step > 1) {
            echo $this->view->render('onboarding/resume.twig', [
                'completed_steps' => min($step - 1, self::TOTAL_STEPS),
                'total_steps' => self::TOTAL_STEPS,
                'next_step' => min($step, self::TOTAL_STEPS),
            ]);
            return;
        }

        header('Location: /onboarding/1');
        exit;
    }

    public function showStep(array $params): void
    {
        $step = (int) $params['step'];
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            header('Location: /onboarding');
            exit;
        }

        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);
        $isTeamMember = $this->isTeamMember();

        if ($card !== null && $card['onboarding_completed_at'] !== null) {
            header('Location: /dashboard');
            exit;
        }

        if ($step === 7 && $isTeamMember) {
            header('Location: /onboarding/8');
            exit;
        }

        $maxReachable = $card !== null ? (int) $card['onboarding_step'] : 1;
        if ($step > $maxReachable) {
            header('Location: /onboarding/' . $maxReachable);
            exit;
        }

        $this->renderStep($step, $card, [], []);
    }

    public function saveStep(array $params): void
    {
        $step = (int) $params['step'];
        if ($step < 1 || $step > self::TOTAL_STEPS - 1) {
            header('Location: /onboarding');
            exit;
        }

        $user = $this->auth->user();
        $userId = (int) $user['id'];
        $card = $this->cards->findByUserId($userId);
        $isTeamMember = $this->isTeamMember();

        if ($card !== null && $card['onboarding_completed_at'] !== null) {
            header('Location: /dashboard');
            exit;
        }

        if ($step === 7 && $isTeamMember) {
            header('Location: /onboarding/8');
            exit;
        }

        $maxReachable = $card !== null ? (int) $card['onboarding_step'] : 1;
        if ($step > $maxReachable) {
            header('Location: /onboarding/' . $maxReachable);
            exit;
        }

        ['errors' => $errors, 'fields' => $fields, 'old' => $old] = $this->collectStepData($step, $card, $userId, $isTeamMember);

        if ($errors !== []) {
            $this->renderStep($step, $card, $errors, $old);
            return;
        }

        $data = array_merge($this->draftDefaults($card), $fields);
        $this->cards->upsertForUser($userId, $data);

        $savedCard = $this->cards->findByUserId($userId);
        $rawNext = ($step === 6 && $isTeamMember) ? 8 : $step + 1;
        $newStep = min(self::TOTAL_STEPS, max($maxReachable, $rawNext));
        $this->cards->updateOnboardingStep((int) $savedCard['id'], $newStep);

        header('Location: /onboarding/' . $rawNext);
        exit;
    }

    public function publish(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null) {
            header('Location: /onboarding');
            exit;
        }

        if ($card['onboarding_completed_at'] === null) {
            $this->cards->completeOnboarding((int) $card['id']);
        }

        header('Location: /onboarding/success');
        exit;
    }

    public function success(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null || $card['onboarding_completed_at'] === null) {
            header('Location: /onboarding');
            exit;
        }

        echo $this->view->render('onboarding/success.twig', ['card' => $card]);
    }

    public function checkSlug(): void
    {
        $user = $this->auth->user();
        $slug = trim(strtolower($_GET['slug'] ?? ''));

        $available = $slug !== ''
            && preg_match('/^[a-z0-9-]{3,100}$/', $slug) === 1
            && !in_array($slug, BusinessCard::RESERVED_SLUGS, true)
            && !$this->cards->slugExists($slug, (int) $user['id']);

        header('Content-Type: application/json');
        echo json_encode(['available' => $available]);
    }

    /**
     * Renders the user's own in-progress card through the exact same design
     * templates the public /{slug} page uses (CardController::showPublic()),
     * regardless of is_published - this is the single render path behind both
     * the step-7 design thumbnails (?design=...) and the step-9/main preview
     * panel, so there is no second "preview renderer" to keep in sync.
     */
    public function preview(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null) {
            http_response_code(404);
            echo $this->view->render('card/not_found.twig');
            return;
        }

        $isTeamMember = $this->isTeamMember();
        if ($isTeamMember) {
            // Show the wizard's own live preview with the real org branding
            // throughout, not just on the final published card - a team
            // member never gets a say in the design, so ?design= from the
            // (never-shown-to-them) step-7 thumbnails is ignored too.
            $card = Organization::applyBranding($card, $this->auth->organization());
        } else {
            $requestedDesign = $_GET['design'] ?? null;
            $design = ($requestedDesign !== null && in_array($requestedDesign, BusinessCard::AVAILABLE_DESIGNS, true))
                ? $requestedDesign
                : (in_array($card['design'], BusinessCard::AVAILABLE_DESIGNS, true) ? $card['design'] : 'classic');
            $card['design'] = $design;

            // Only ever sent by the org-owner's palette thumbnails on
            // /team/branding, so they can preview a curated palette before
            // saving it - looked up by key rather than trusting raw hex
            // values from the query string.
            $palette = Organization::COLOR_PRESETS[$_GET['palette'] ?? ''] ?? null;
            if ($palette !== null) {
                $card['use_custom_colors'] = true;
                $card['color_background'] = $palette['color_background'];
                $card['color_header'] = $palette['color_header'];
                $card['color_content'] = $palette['color_content'];
                $card['color_footer'] = $palette['color_footer'];
            }
        }

        // Free users previewing a Pro-only design see it exactly as a real
        // visitor would (gray/plain) - same owner_plan gate the templates
        // already use for the live public page. Uses Auth::plan() rather
        // than the raw stored plan so an active trial is reflected too.
        $card['owner_plan'] = $this->auth->plan();

        echo $this->view->render("card/designs/{$card['design']}.twig", [
            'card' => $card,
            'meta_description' => '',
            'og_image_url' => null,
            'structured_data_json' => '{}',
            // The public /qr/{slug}.png route requires a published card, which
            // a draft never is - reuse the existing logged-in-owner QR route
            // instead so the preview's QR code actually renders.
            'qr_url' => '/card/qr.png',
        ]);
    }

    /**
     * @return array{errors: string[], fields: array<string, mixed>, old: array<string, mixed>}
     */
    private function collectStepData(int $step, ?array $card, int $userId, bool $isTeamMember): array
    {
        $errors = [];
        $fields = [];
        $old = [];

        switch ($step) {
            case 1:
                $firstName = trim($_POST['first_name'] ?? '');
                $lastName = trim($_POST['last_name'] ?? '');
                $old['first_name'] = $firstName;
                $old['last_name'] = $lastName;
                $displayName = trim($firstName . ' ' . $lastName);
                if ($displayName === '') {
                    $errors[] = $this->translator->trans('card.edit.errors.name_required');
                    break;
                }
                $fields['display_name'] = $displayName;
                if ($card === null) {
                    $fields['slug'] = $this->cards->generateUniqueSlug($displayName, $userId, BusinessCard::RESERVED_SLUGS);
                }
                break;

            case 2:
                // Team members show the organization's fixed company name as
                // plain text (no input field at all) - never read from POST,
                // so the row simply keeps whatever it already had (null for
                // a new card, since Organization::applyBranding() is what
                // actually supplies the displayed value everywhere).
                if (!$isTeamMember) {
                    $fields['company'] = trim($_POST['company'] ?? '') ?: null;
                }
                $fields['job_title'] = trim($_POST['job_title'] ?? '') ?: null;
                $fields['bio'] = trim($_POST['bio'] ?? '') ?: null;
                break;

            case 3:
                $phone = trim($_POST['phone'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $website = trim($_POST['website'] ?? '');
                $whatsapp = trim($_POST['whatsapp'] ?? '');
                $old = ['phone' => $phone, 'email' => $email, 'website' => $website, 'whatsapp' => $whatsapp];
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = $this->translator->trans('card.edit.errors.email_invalid');
                }
                if ($errors === []) {
                    $fields['phone'] = $phone ?: null;
                    $fields['email'] = $email ?: null;
                    $fields['website'] = $website ?: null;
                    $fields['whatsapp'] = $whatsapp ?: null;
                }
                break;

            case 4:
                // Team members never set their own address - the organization's
                // fixed address (set by the owner) is applied at render time.
                // The only thing they can add is an optional workplace note
                // (room/desk), stored separately so it survives independent
                // of the org address and re-fills cleanly on revisit.
                if ($isTeamMember) {
                    $old['workplace'] = trim($_POST['workplace'] ?? '');
                    $fields['workplace'] = $old['workplace'] !== '' ? $old['workplace'] : null;
                    break;
                }
                if (isset($_POST['skip']) || !isset($_POST['show_address'])) {
                    $fields['address'] = null;
                    break;
                }
                $street = trim($_POST['street'] ?? '');
                $postalCode = trim($_POST['postal_code'] ?? '');
                $city = trim($_POST['city'] ?? '');
                $country = trim($_POST['country'] ?? '');
                $old = ['street' => $street, 'postal_code' => $postalCode, 'city' => $city, 'country' => $country, 'show_address' => true];
                $cityLine = trim($postalCode . ' ' . $city);
                $parts = array_filter([$street, $cityLine, $country], fn ($p) => $p !== '');
                $fields['address'] = $parts !== [] ? implode(', ', $parts) : null;
                break;

            case 5:
                $values = [];
                foreach (array_keys(self::SOCIAL_PLATFORMS) as $key) {
                    $values[$key] = trim($_POST[$key] ?? '');
                }
                $old = $values;
                foreach ($values as $url) {
                    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                        $errors[] = $this->translator->trans('card.edit.errors.social_url_invalid');
                        break;
                    }
                }
                if ($errors === []) {
                    foreach ($values as $key => $url) {
                        $fields[$key] = $url ?: null;
                    }
                }
                break;

            case 6:
                if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $uploaded = $this->photoUploader->upload($userId, $_FILES['photo']);
                        if ($uploaded !== null) {
                            $fields['photo_path'] = $uploaded;
                        }
                    } catch (RuntimeException $e) {
                        $errors[] = $this->translator->trans($e->getMessage());
                    }
                }
                // Company logo is fixed by the organization owner - a team
                // member never gets a logo upload field, so nothing to read here.
                if (!$isTeamMember && isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $uploaded = $this->logoUploader->upload($userId, $_FILES['logo']);
                        if ($uploaded !== null) {
                            $fields['logo_path'] = $uploaded;
                        }
                    } catch (RuntimeException $e) {
                        $errors[] = $this->translator->trans($e->getMessage());
                    }
                }
                break;

            case 7:
                $design = $_POST['design'] ?? 'classic';
                if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
                    $design = 'classic';
                }
                if ($design !== 'classic' && !$this->auth->can('design_pro')) {
                    $design = 'classic';
                    Session::flash('error', $this->translator->trans('card.edit.design_downgraded'));
                }
                $fields['design'] = $design;
                break;

            case 8:
                $slugInput = trim(strtolower($_POST['slug'] ?? ''));
                $old['slug'] = $slugInput;
                if ($slugInput === '' || !preg_match('/^[a-z0-9-]{3,100}$/', $slugInput)) {
                    $errors[] = $this->translator->trans('card.edit.errors.slug_invalid');
                } elseif (in_array($slugInput, BusinessCard::RESERVED_SLUGS, true)) {
                    $errors[] = $this->translator->trans('card.edit.errors.slug_taken');
                } elseif ($this->cards->slugExists($slugInput, $userId)) {
                    $errors[] = $this->translator->trans('card.edit.errors.slug_taken');
                } else {
                    $fields['slug'] = $slugInput;
                }
                break;
        }

        return ['errors' => $errors, 'fields' => $fields, 'old' => $old];
    }

    /** Full set of columns upsertForUser() needs, seeded from the existing draft or sensible blanks for a brand-new one. */
    private function draftDefaults(?array $card): array
    {
        if ($card !== null) {
            return [
                'slug' => $card['slug'],
                'display_name' => $card['display_name'],
                'job_title' => $card['job_title'],
                'company' => $card['company'],
                'category_id' => $card['category_id'],
                'email' => $card['email'],
                'phone' => $card['phone'],
                'whatsapp' => $card['whatsapp'],
                'website' => $card['website'],
                'address' => $card['address'],
                'workplace' => $card['workplace'],
                'bio' => $card['bio'],
                'opening_hours' => $card['opening_hours'],
                'logo_path' => $card['logo_path'],
                'photo_path' => $card['photo_path'],
                'linkedin_url' => $card['linkedin_url'],
                'instagram_url' => $card['instagram_url'],
                'facebook_url' => $card['facebook_url'],
                'youtube_url' => $card['youtube_url'],
                'booking_url' => $card['booking_url'],
                'design' => $card['design'],
                'use_custom_colors' => (bool) $card['use_custom_colors'],
                'color_background' => $card['color_background'],
                'color_header' => $card['color_header'],
                'color_content' => $card['color_content'],
                'color_footer' => $card['color_footer'],
                'is_published' => false,
            ];
        }

        return [
            'slug' => null,
            'display_name' => '',
            'job_title' => null,
            'company' => null,
            'category_id' => null,
            'email' => null,
            'phone' => null,
            'whatsapp' => null,
            'website' => null,
            'address' => null,
            'workplace' => null,
            'bio' => null,
            'opening_hours' => null,
            'logo_path' => null,
            'photo_path' => null,
            'linkedin_url' => null,
            'instagram_url' => null,
            'facebook_url' => null,
            'youtube_url' => null,
            'booking_url' => null,
            'design' => 'classic',
            'use_custom_colors' => false,
            'color_background' => null,
            'color_header' => null,
            'color_content' => null,
            'color_footer' => null,
            'is_published' => false,
        ];
    }

    private function isTeamMember(): bool
    {
        return $this->auth->organization() !== null && !$this->auth->isOrgOwner();
    }

    private function displayStep(int $rawStep, bool $skipDesign): int
    {
        return $skipDesign && $rawStep > 7 ? $rawStep - 1 : $rawStep;
    }

    private function displayTotal(bool $skipDesign): int
    {
        return $skipDesign ? self::TOTAL_STEPS - 1 : self::TOTAL_STEPS;
    }

    private function renderStep(int $step, ?array $card, array $errors, array $old): void
    {
        $isTeamMember = $this->isTeamMember();
        $org = $isTeamMember ? $this->auth->organization() : null;

        $context = [
            'card' => $card,
            'old' => $old,
            'errors' => $errors,
            'step' => $this->displayStep($step, $isTeamMember),
            'total_steps' => $this->displayTotal($isTeamMember),
            'is_team_member' => $isTeamMember,
            'organization' => $org,
        ];

        if ($step === 1) {
            $firstName = $old['first_name'] ?? null;
            $lastName = $old['last_name'] ?? null;
            if ($firstName === null && $card !== null && $card['display_name']) {
                $parts = explode(' ', $card['display_name'], 2);
                $firstName = $parts[0];
                $lastName = $parts[1] ?? '';
            }
            $context['first_name'] = $firstName ?? '';
            $context['last_name'] = $lastName ?? '';
        }

        if ($step === 5) {
            $context['social_platforms'] = self::SOCIAL_PLATFORMS;
        }

        if ($step === 7) {
            $context['design_pro_allowed'] = $this->auth->can('design_pro');
            $context['design_trial_notice'] = $this->auth->isOnIndividualTrial();
        }

        echo $this->view->render("onboarding/step{$step}.twig", $context);
    }
}
