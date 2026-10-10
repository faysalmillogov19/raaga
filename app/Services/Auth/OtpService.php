<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Sms\EnvoiSms;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Codes de confirmation à usage unique envoyés par SMS.
 *
 * - 6 chiffres, valables 5 minutes, stockés sous forme d'empreinte (jamais en clair).
 * - Renvoi possible après 45 secondes, 5 envois maximum par heure et par numéro.
 * - 5 codes erronés : blocage de 15 minutes. Messages d'erreur neutres.
 */
class OtpService
{
    public const VALIDITE_MINUTES = 5;

    public const DELAI_RENVOI_SECONDES = 45;

    public const ENVOIS_MAX_PAR_HEURE = 5;

    public const ESSAIS_MAX = 5;

    public const BLOCAGE_SECONDES = 900;

    public function __construct(private readonly EnvoiSms $sms) {}

    /**
     * @throws OtpException
     */
    public function envoyer(string $telephone, string $objet): void
    {
        $this->refuserSiBloque($telephone, $objet);

        if ($this->secondesAvantRenvoi($telephone, $objet) > 0) {
            throw new OtpException(__('public.otp.erreurs.renvoi_trop_tot'));
        }

        $cleEnvois = "otp-envois:{$objet}:{$telephone}";
        if (RateLimiter::tooManyAttempts($cleEnvois, self::ENVOIS_MAX_PAR_HEURE)) {
            throw new OtpException(__('public.otp.erreurs.trop_envois'));
        }
        RateLimiter::hit($cleEnvois, 3600);

        // Un seul code actif à la fois : les précédents deviennent inutilisables.
        OtpCode::query()
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->whereNull('utilise_le')
            ->where('expire_le', '>', now())
            ->update(['expire_le' => now()]);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'telephone' => $telephone,
            'objet' => $objet,
            'code_hash' => $this->empreinte($code),
            'expire_le' => now()->addMinutes(self::VALIDITE_MINUTES),
            'created_at' => now(),
        ]);

        $this->sms->envoyer($telephone, __('public.otp.sms'), ['code' => $code, 'minutes' => self::VALIDITE_MINUTES]);
    }

    /**
     * @throws OtpException si le numéro est bloqué après trop d'essais.
     */
    public function verifier(string $telephone, string $objet, string $code): bool
    {
        $this->refuserSiBloque($telephone, $objet);

        $otp = OtpCode::query()
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->whereNull('utilise_le')
            ->where('expire_le', '>', now())
            ->latest('id')
            ->first();

        if ($otp === null || ! hash_equals($otp->code_hash, $this->empreinte($code))) {
            $otp?->increment('tentatives');
            RateLimiter::hit($this->cleEssais($telephone, $objet), self::BLOCAGE_SECONDES);
            $this->refuserSiBloque($telephone, $objet);

            return false;
        }

        $otp->update(['utilise_le' => now()]);
        RateLimiter::clear($this->cleEssais($telephone, $objet));

        return true;
    }

    /**
     * Un code encore utilisable (ni expiré, ni déjà utilisé) existe-t-il pour ce numéro ?
     */
    public function aUnCodeActif(string $telephone, string $objet): bool
    {
        return OtpCode::query()
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->whereNull('utilise_le')
            ->where('expire_le', '>', now())
            ->exists();
    }

    /**
     * Secondes à attendre avant de pouvoir demander un nouveau code (0 = possible).
     */
    public function secondesAvantRenvoi(string $telephone, string $objet): int
    {
        $dernier = OtpCode::query()
            ->where('telephone', $telephone)
            ->where('objet', $objet)
            ->latest('id')
            ->value('created_at');

        if ($dernier === null) {
            return 0;
        }

        $ecoule = (int) now()->diffInSeconds($dernier, absolute: true);

        return max(0, self::DELAI_RENVOI_SECONDES - $ecoule);
    }

    /**
     * @throws OtpException
     */
    private function refuserSiBloque(string $telephone, string $objet): void
    {
        $cle = $this->cleEssais($telephone, $objet);

        if (RateLimiter::tooManyAttempts($cle, self::ESSAIS_MAX)) {
            throw new OtpException(__('public.otp.erreurs.bloque', [
                'minutes' => max(1, (int) ceil(RateLimiter::availableIn($cle) / 60)),
            ]));
        }
    }

    private function cleEssais(string $telephone, string $objet): string
    {
        return "otp-essais:{$objet}:{$telephone}";
    }

    private function empreinte(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
