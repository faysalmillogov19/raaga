<?php

namespace Tests\Support;

use App\Contracts\SmsProvider;

/**
 * Capture les SMS au lieu de les envoyer, pour lire le code dans les tests.
 */
class FakeSmsProvider implements SmsProvider
{
    /** @var list<array{telephone: string, message: string, expediteur: ?string}> */
    public array $envoyes = [];

    public function envoyer(string $telephone, string $message, ?string $expediteur = null): void
    {
        $this->envoyes[] = ['telephone' => $telephone, 'message' => $message, 'expediteur' => $expediteur];
    }

    public function dernierCode(): ?string
    {
        $dernier = end($this->envoyes);

        return $dernier && preg_match('/\b(\d{6})\b/', $dernier['message'], $m) ? $m[1] : null;
    }
}
