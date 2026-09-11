<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\Organization;
use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\LogoUploader;
use Kartenlink\App\Support\Mailer;
use Kartenlink\App\Support\PasswordPolicy;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\StripeService;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;
use RuntimeException;

final class TeamController
{
    private const TRIAL_DAYS = 7;
    private const MIN_SEATS = 5;

    // More generous than the 1h password-reset token, since this is a
    // routine onboarding task rather than a security-critical action.
    private const INVITE_TOKEN_TTL_DAYS = 7;

    private Organization $organizations;
    private User $users;
    private BusinessCard $cards;

    public function __construct(
        private PDO $db,
        private Auth $auth,
        private View $view,
        private Translator $translator,
        private StripeService $stripe,
        private LogoUploader $logoUploader,
        private LogoUploader $photoUploader,
        private Mailer $mailer,
        private string $appUrl
    ) {
        $this->organizations = new Organization($db);
        $this->users = new User($db);
        $this->cards = new BusinessCard($db);
    }

    public function index(): void
    {
        $user = $this->auth->user();

        $org = !empty($user['organization_id'])
            ? $this->organizations->findById((int) $user['organization_id'])
            : null;

        if ($org === null) {
            echo $this->view->render('team/create.twig');
            return;
        }

        if (!$this->auth->isOrgOwner()) {
            echo $this->view->render('team/member.twig', [
                'organization' => $org,
                'owner' => $this->users->findById((int) $org['owner_user_id']),
            ]);
            return;
        }

        $trialDaysLeft = null;
        if (($org['subscription_status'] ?? null) !== 'active' && Auth::hasActiveTrial($org)) {
            $trialDaysLeft = (int) ceil((strtotime($org['trial_ends_at']) - time()) / 86400);
        }

        echo $this->view->render('team/dashboard.twig', [
            'organization' => $org,
            'members' => $this->organizations->listMembers((int) $org['id']),
            'min_seats' => self::MIN_SEATS,
            'trial_days_left' => $trialDaysLeft,
            'stripe_configured' => $this->stripe->isFirmaConfigured(),
            'color_presets' => Organization::COLOR_PRESETS,
        ]);
    }

    public function create(): void
    {
        $user = $this->auth->user();
        if (!empty($user['organization_id'])) {
            $this->redirect('/team');
        }

        // Demo accounts are a real but time-limited user row, cleaned up by
        // deleting the users row directly - an owned organization would
        // block that delete (FK RESTRICT on organizations.owner_user_id),
        // so demo accounts must never be able to create one.
        if (!empty($user['is_demo'])) {
            Session::flash('error', $this->translator->trans('demo.action_not_allowed'));
            $this->redirect('/team');
        }

        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            Session::flash('error', $this->translator->trans('team.errors.name_required'));
            $this->redirect('/team');
        }

        $orgId = $this->organizations->create($name, (int) $user['id'], self::TRIAL_DAYS);
        $this->users->assignToOrganization((int) $user['id'], $orgId);

        Session::flash('success', $this->translator->trans('team.create.success'));
        $this->redirect('/team');
    }

    public function invite(): void
    {
        $org = $this->auth->organization();

        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $result = $this->inviteOne($org, $email, $name, $jobTitle, $phone);

        if (!$result['success']) {
            Session::flash('error', $result['error']);
            $this->redirect('/team');
        }

        Session::flash('success', $this->translator->trans('team.invite.success', [
            'email' => strtolower($email),
        ]));
        $this->redirect('/team');
    }

    public function inviteCsv(): void
    {
        $org = $this->auth->organization();

        if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            Session::flash('error', $this->translator->trans('team.csv_import.errors.upload_failed'));
            $this->redirect('/team');
        }

        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        if ($handle === false) {
            Session::flash('error', $this->translator->trans('team.csv_import.errors.upload_failed'));
            $this->redirect('/team');
        }

        // First line is treated as a header (name,email,job_title,phone) and skipped.
        fgetcsv($handle);

        $successCount = 0;
        $errorLines = [];
        $row = 1;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if (count(array_filter($data, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            [$name, $email, $jobTitle, $phone] = array_pad($data, 4, null);
            $result = $this->inviteOne($org, (string) $email, (string) $name, (string) $jobTitle, (string) $phone);

            if ($result['success']) {
                $successCount++;
            } else {
                $errorLines[] = $this->translator->trans('team.csv_import.row_error', [
                    'row' => $row,
                    'error' => $result['error'],
                ]);
            }
        }

        fclose($handle);

        $total = $successCount + count($errorLines);
        $summary = $this->translator->trans('team.csv_import.summary', [
            'success' => $successCount,
            'total' => $total,
        ]);

        if ($errorLines !== []) {
            $summary .= ' ' . implode(' ', $errorLines);
        }

        Session::flash($errorLines === [] ? 'success' : 'error', $summary);
        $this->redirect('/team');
    }

    /** Public, unauthenticated - reached from the link in the invite email. */
    public function showAcceptInvite(array $params): void
    {
        $token = (string) ($params['token'] ?? '');
        $user = $token !== '' ? $this->users->findByValidInviteTokenHash(hash('sha256', $token)) : null;

        if ($user === null) {
            http_response_code(404);
            echo $this->view->render('team/invite_invalid.twig');
            return;
        }

        $this->auth->login(['id' => $user['id']]);
        $this->users->clearInviteToken((int) $user['id']);

        $this->redirect('/team/complete-profile');
    }

    public function reinvite(array $params): void
    {
        $org = $this->auth->organization();
        $targetId = (int) ($params['userId'] ?? 0);
        $target = $targetId > 0 ? $this->users->findById($targetId) : null;

        if ($target === null || (int) ($target['organization_id'] ?? 0) !== (int) $org['id']) {
            http_response_code(403);
            echo '403 - Kein Zugriff';
            return;
        }

        if ($targetId === (int) $org['owner_user_id']) {
            Session::flash('error', $this->translator->trans('team.reinvite.cannot_target_owner'));
            $this->redirect('/team');
        }

        $card = $this->cards->findByUserId($targetId);
        if ($card !== null && $card['onboarding_completed_at'] !== null) {
            Session::flash('error', $this->translator->trans('team.reinvite.already_completed'));
            $this->redirect('/team');
        }

        $this->sendInviteEmail($targetId, $target['email']);

        Session::flash('success', $this->translator->trans('team.reinvite.success', [
            'email' => $target['email'],
        ]));
        $this->redirect('/team');
    }

    public function showCompleteProfile(): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        echo $this->view->render('team/complete_profile.twig', [
            'user' => $user,
            'card' => $card,
            'organization' => $this->auth->organization(),
        ]);
    }

    public function completeProfile(): void
    {
        $user = $this->auth->user();
        $userId = (int) $user['id'];
        $card = $this->cards->findByUserId($userId);

        if ($card === null) {
            $this->redirect('/dashboard');
        }

        $errors = [];

        $photoPath = $card['photo_path'] ?? null;
        if (isset($_POST['remove_photo'])) {
            $this->photoUploader->remove($userId);
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

        $linkedinUrl = trim($_POST['linkedin_url'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $bio = trim($_POST['bio'] ?? '');

        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            if (!PasswordPolicy::isValid($password)) {
                $errors[] = $this->translator->trans('auth.register.errors.password_requirements');
            } elseif ($password !== $passwordConfirm) {
                $errors[] = $this->translator->trans('auth.register.errors.password_mismatch');
            }
        }

        if ($errors !== []) {
            echo $this->view->render('team/complete_profile.twig', [
                'user' => $user,
                'card' => array_merge($card, ['photo_path' => $photoPath]),
                'organization' => $this->auth->organization(),
                'errors' => $errors,
                'old' => $_POST,
            ]);
            return;
        }

        $data = $card;
        $data['photo_path'] = $photoPath;
        $data['linkedin_url'] = $linkedinUrl !== '' ? $linkedinUrl : null;
        $data['whatsapp'] = $whatsapp !== '' ? $whatsapp : null;
        $data['bio'] = $bio !== '' ? $bio : null;
        $data['use_custom_colors'] = (bool) $card['use_custom_colors'];
        $data['is_published'] = (bool) $card['is_published'];

        $this->cards->upsertForUser($userId, $data);

        if ($password !== '') {
            $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        }

        // Mirrors what the final wizard step (publish()) does for a team
        // member reaching it the old way: mark onboarding done AND publish,
        // since the admin already supplied everything required for a valid
        // card (name, job title, company/branding inherited from the org).
        if ($card['onboarding_completed_at'] === null) {
            $this->cards->completeOnboarding((int) $card['id']);
        }

        Session::flash('success', $this->translator->trans('team.complete_profile.success'));
        $this->redirect('/dashboard');
    }

    public function remove(array $params): void
    {
        $org = $this->auth->organization();
        $targetId = (int) ($params['userId'] ?? 0);
        $target = $targetId > 0 ? $this->users->findById($targetId) : null;

        if ($target === null || (int) ($target['organization_id'] ?? 0) !== (int) $org['id']) {
            http_response_code(403);
            echo '403 - Kein Zugriff';
            return;
        }

        if ($targetId === (int) $org['owner_user_id']) {
            Session::flash('error', $this->translator->trans('team.errors.cannot_remove_owner'));
            $this->redirect('/team');
        }

        $this->users->assignToOrganization($targetId, null);
        $this->syncSeats($org);

        Session::flash('success', $this->translator->trans('team.remove.success'));
        $this->redirect('/team');
    }

    public function checkout(): void
    {
        if (!$this->stripe->isFirmaConfigured()) {
            Session::flash('error', $this->translator->trans('billing.error.not_configured'));
            $this->redirect('/team');
        }

        $org = $this->auth->organization();
        $owner = $this->users->findById((int) $org['owner_user_id']);
        $org['owner_email'] = $owner['email'] ?? null;

        try {
            $session = $this->stripe->createFirmaCheckoutSession($org, (int) $org['seats']);
        } catch (\Throwable) {
            Session::flash('error', $this->translator->trans('billing.error.checkout_failed'));
            $this->redirect('/team');
        }

        $this->redirect($session->url);
    }

    public function portal(): void
    {
        $org = $this->auth->organization();

        if (empty($org['stripe_customer_id'])) {
            $this->redirect('/team');
        }

        try {
            $session = $this->stripe->createBillingPortalSession($org['stripe_customer_id']);
        } catch (\Throwable) {
            Session::flash('error', $this->translator->trans('billing.error.portal_failed'));
            $this->redirect('/team');
        }

        $this->redirect($session->url);
    }

    public function branding(): void
    {
        $org = $this->auth->organization();
        $orgId = (int) $org['id'];

        $design = $_POST['design'] ?? ($org['design'] ?? 'classic');
        if (!in_array($design, BusinessCard::AVAILABLE_DESIGNS, true)) {
            $design = 'classic';
        }

        $colorPreset = $_POST['color_preset'] ?? null;
        if ($colorPreset !== null && !array_key_exists($colorPreset, Organization::COLOR_PRESETS)) {
            $colorPreset = null;
        }

        $street = trim($_POST['street'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $cityLine = trim($postalCode . ' ' . $city);
        $parts = array_filter([$street, $cityLine, $country], fn ($p) => $p !== '');
        $address = $parts !== [] ? implode(', ', $parts) : null;

        $logoPath = $org['logo_path'] ?? null;
        if (isset($_POST['remove_logo'])) {
            $this->logoUploader->remove($orgId);
            $logoPath = null;
        } elseif (isset($_FILES['logo'])) {
            try {
                $uploaded = $this->logoUploader->upload($orgId, $_FILES['logo']);
                if ($uploaded !== null) {
                    $logoPath = $uploaded;
                }
            } catch (RuntimeException $e) {
                Session::flash('error', $this->translator->trans($e->getMessage()));
                $this->redirect('/team');
            }
        }

        $this->organizations->updateBranding($orgId, $logoPath, $address, $design, $colorPreset);

        Session::flash('success', $this->translator->trans('team.branding.success'));
        $this->redirect('/team');
    }

    public function devActivate(): void
    {
        if ($this->stripe->isFirmaConfigured()) {
            $this->redirect('/team');
        }

        $org = $this->auth->organization();
        $this->organizations->syncStripeSubscription((int) $org['id'], [
            'stripe_customer_id' => $org['stripe_customer_id'],
            'stripe_subscription_id' => $org['stripe_subscription_id'],
            'stripe_subscription_item_id' => $org['stripe_subscription_item_id'],
            'subscription_status' => 'active',
            'cancel_at_period_end' => false,
        ]);

        Session::flash('success', $this->translator->trans('team.dev_activated'));
        $this->redirect('/team');
    }

    /**
     * Creates the user + throwaway-password account, assigns it to the org,
     * pre-fills its business_cards row with the admin-supplied data (with
     * onboarding_completed_at left NULL - that's the existing gate that now
     * routes to /team/complete-profile instead of the wizard), and emails a
     * magic-link invite. Shared by the single-invite form and the CSV
     * importer so both go through identical validation/creation logic.
     *
     * @return array{success: bool, error: ?string}
     */
    private function inviteOne(array $org, string $email, string $name, ?string $jobTitle, ?string $phone): array
    {
        $email = strtolower(trim($email));
        $name = trim($name);
        $jobTitle = trim((string) $jobTitle);
        $phone = trim((string) $phone);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => $this->translator->trans('team.errors.email_invalid')];
        }

        if ($this->users->findByEmail($email) !== null) {
            return ['success' => false, 'error' => $this->translator->trans('team.errors.email_taken')];
        }

        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        // The employee never sees this password - they get in via the magic
        // link (or "forgot password" later) until they optionally set a real
        // one in /team/complete-profile. Keeps password_hash NOT NULL as-is.
        $throwawayHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $userId = $this->users->create($name, $email, $throwawayHash);
        $this->users->assignToOrganization($userId, (int) $org['id']);
        $this->syncSeats($org);

        $slug = $this->cards->generateUniqueSlug($name, $userId, BusinessCard::RESERVED_SLUGS);
        $this->cards->upsertForUser($userId, [
            'slug' => $slug,
            'display_name' => $name,
            'job_title' => $jobTitle !== '' ? $jobTitle : null,
            'company' => $org['name'],
            'category_id' => null,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
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
            // Irrelevant for an org member - Organization::applyBranding()
            // overrides this live at every render site regardless of what's
            // stored here (see BusinessCard.design NOT NULL DEFAULT 'classic').
            'design' => 'classic',
            'use_custom_colors' => false,
            'color_background' => null,
            'color_header' => null,
            'color_content' => null,
            'color_footer' => null,
            'is_published' => false,
        ]);

        $this->sendInviteEmail($userId, $email);

        return ['success' => true, 'error' => null];
    }

    private function sendInviteEmail(int $userId, string $email): void
    {
        $token = bin2hex(random_bytes(32));
        $this->users->setInviteToken($userId, hash('sha256', $token), self::INVITE_TOKEN_TTL_DAYS * 86400);

        $link = $this->appUrl . '/invite/' . $token;
        $this->mailer->send(
            $email,
            $this->translator->trans('team.invite.email_subject'),
            $this->translator->trans('team.invite.email_body', ['link' => $link])
        );
    }

    private function syncSeats(array $org): void
    {
        $seats = max(self::MIN_SEATS, $this->organizations->memberCount((int) $org['id']));
        $this->organizations->updateSeats((int) $org['id'], $seats);

        if (!empty($org['stripe_subscription_item_id'])) {
            try {
                $this->stripe->updateSubscriptionItemQuantity($org['stripe_subscription_item_id'], $seats);
            } catch (\Throwable) {
                // Local seat count is already updated; the next Stripe webhook
                // or a portal visit will reconcile the billed quantity if this call failed.
            }
        }
    }

    private function redirect(string $path): void
    {
        header('Location: ' . $path);
        exit;
    }
}
