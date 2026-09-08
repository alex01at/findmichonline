<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use Kartenlink\App\Support\Mailer;
use Kartenlink\App\Support\Session;
use Kartenlink\App\Support\Translator;
use Kartenlink\App\Support\View;

final class ContactController
{
    public function __construct(
        private View $view,
        private Translator $translator,
        private Mailer $mailer,
        private string $contactEmail
    ) {
    }

    public function show(): void
    {
        echo $this->view->render('contact.twig', [
            'old' => [],
        ]);
    }

    public function submit(): void
    {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        $errors = [];

        if ($name === '') {
            $errors[] = $this->translator->trans('contact.errors.name_required');
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = $this->translator->trans('contact.errors.email_invalid');
        }
        if ($message === '') {
            $errors[] = $this->translator->trans('contact.errors.message_required');
        }

        if ($errors !== []) {
            echo $this->view->render('contact.twig', [
                'old' => ['name' => $name, 'email' => $email, 'message' => $message],
                'errors' => $errors,
            ]);
            return;
        }

        $body = $this->translator->trans('contact.email_body', [
            'name' => $name,
            'email' => $email,
            'message' => $message,
        ]);
        $this->mailer->send($this->contactEmail, $this->translator->trans('contact.email_subject'), $body);

        Session::flash('success', $this->translator->trans('contact.success'));
        header('Location: /kontakt');
        exit;
    }
}
