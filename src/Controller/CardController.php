<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;
use PDO;

final class CardController
{
    private BusinessCard $cards;

    public function __construct(private PDO $db, private View $view, private Auth $auth, private Translator $translator)
    {
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
        $slugInput = trim(strtolower($_POST['slug'] ?? ''));
        $isPublished = isset($_POST['is_published']);

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
                    'design' => $design,
                    'is_published' => $isPublished,
                ],
                'errors' => $errors,
                'design_modern_allowed' => $this->auth->can('design_modern'),
            ]);
            return;
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

        echo $this->view->render("card/designs/{$design}.twig", ['card' => $card]);
    }
}
