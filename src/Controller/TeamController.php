<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Model\Organization;
use Kartenlink\App\Model\User;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\LogoUploader;
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

    private Organization $organizations;
    private User $users;

    public function __construct(
        private PDO $db,
        private Auth $auth,
        private View $view,
        private Translator $translator,
        private StripeService $stripe,
        private LogoUploader $logoUploader
    ) {
        $this->organizations = new Organization($db);
        $this->users = new User($db);
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
        ]);
    }

    public function create(): void
    {
        $user = $this->auth->user();
        if (!empty($user['organization_id'])) {
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

        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', $this->translator->trans('team.errors.email_invalid'));
            $this->redirect('/team');
        }

        if ($this->users->findByEmail($email) !== null) {
            Session::flash('error', $this->translator->trans('team.errors.email_taken'));
            $this->redirect('/team');
        }

        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        $password = bin2hex(random_bytes(6));
        $userId = $this->users->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
        $this->users->assignToOrganization($userId, (int) $org['id']);

        $this->syncSeats($org);

        Session::flash('success', $this->translator->trans('team.invite.success', [
            'email' => $email,
            'password' => $password,
        ]));
        $this->redirect('/team');
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

        $this->organizations->updateBranding($orgId, $logoPath, $address, $design);

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
