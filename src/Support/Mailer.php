<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

final class Mailer
{
    public function __construct(private string $fromAddress, private string $fromName)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $headers = [
            'From' => sprintf('%s <%s>', $this->encodeHeader($this->fromName), $this->fromAddress),
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        return mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $headerLines));
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
