<?php

declare(strict_types=1);

namespace Kartenlink\App\Controller;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Kartenlink\App\Model\BusinessCard;
use Kartenlink\App\Support\Auth;
use Kartenlink\App\Support\Translator;
use PDO;

final class QrCodeController
{
    private BusinessCard $cards;

    public function __construct(private PDO $db, private Auth $auth, private string $appUrl, private Translator $translator)
    {
        $this->cards = new BusinessCard($db);
    }

    public function png(): void
    {
        $this->respondOwn('png');
    }

    public function svg(): void
    {
        $this->respondOwn('svg');
    }

    public function publicPng(array $params): void
    {
        $this->respondPublic($params['slug'], 'png');
    }

    public function publicSvg(array $params): void
    {
        $this->respondPublic($params['slug'], 'svg');
    }

    /** Fixed QR for the marketing-page demo cards (DemoCardController) - no real card behind it. */
    public function demoSvg(): void
    {
        $this->render('dein-name', 'svg');
    }

    private function respondOwn(string $format): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null) {
            http_response_code(404);
            echo $this->translator->trans('qr.no_card');
            return;
        }

        $this->render($card['slug'], $format);
    }

    private function respondPublic(string $slug, string $format): void
    {
        $card = $this->cards->findPublishedBySlug($slug);

        if ($card === null) {
            http_response_code(404);
            echo $this->translator->trans('qr.no_card');
            return;
        }

        $this->render($card['slug'], $format);
    }

    private function render(string $slug, string $format): void
    {
        $cardUrl = $this->appUrl . '/' . $slug;
        $download = isset($_GET['download']);
        $filename = $slug . '-qrcode.' . $format;

        if ($format === 'svg') {
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => false,
            ]);
            $body = (new QRCode($options))->render($cardUrl);
            header('Content-Type: image/svg+xml');
        } else {
            $options = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => false,
                'scale' => 10,
                'imageTransparent' => false,
            ]);
            $body = (new QRCode($options))->render($cardUrl);
            header('Content-Type: image/png');
        }

        if ($download) {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        echo $body;
    }
}
