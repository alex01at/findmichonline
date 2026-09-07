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
        $this->respond('png');
    }

    public function svg(): void
    {
        $this->respond('svg');
    }

    private function respond(string $format): void
    {
        $user = $this->auth->user();
        $card = $this->cards->findByUserId((int) $user['id']);

        if ($card === null) {
            http_response_code(404);
            echo $this->translator->trans('qr.no_card');
            return;
        }

        $cardUrl = $this->appUrl . '/' . $card['slug'];
        $download = isset($_GET['download']);
        $filename = $card['slug'] . '-qrcode.' . $format;

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
