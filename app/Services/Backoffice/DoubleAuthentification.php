<?php

namespace App\Services\Backoffice;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification TOTP (RFC 6238) des comptes back-office, et codes de secours.
 */
class DoubleAuthentification
{
    public const NB_CODES_SECOURS = 10;

    /** Tolérance d'horloge : ±1 pas de 30 secondes. */
    private const FENETRE = 1;

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function genererSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * QR code SVG à scanner avec l'application d'authentification.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl('Raaga Back-office', (string) $user->email, $secret);

        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /**
     * Vérifie un code TOTP. Un même code ne peut jamais être utilisé deux fois (rejeu).
     */
    public function verifier(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->totp_secret;
        $code = preg_replace('/\s+/', '', $code);

        if (! $secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $pas = $this->google2fa->verifyKeyNewer($secret, $code, $user->totp_dernier_pas, self::FENETRE);

        if ($pas === false) {
            return false;
        }

        $user->forceFill(['totp_dernier_pas' => $pas === true ? $this->google2fa->getTimestamp() : $pas])->save();

        return true;
    }

    /**
     * Active la double authentification et renvoie les codes de secours (affichés une seule fois).
     *
     * @return list<string>
     */
    public function activer(User $user, string $secret): array
    {
        $codes = $this->genererCodesSecours();

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_active_le' => now(),
            'codes_secours' => array_map(fn (string $code) => Hash::make($code), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Utilise un code de secours (format « XXXX-XXXX ») : valable une seule fois.
     */
    public function utiliserCodeSecours(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        $empreintes = $user->codes_secours ?? [];

        foreach ($empreintes as $i => $empreinte) {
            if (Hash::check($code, $empreinte)) {
                unset($empreintes[$i]);
                $user->forceFill(['codes_secours' => array_values($empreintes)])->save();

                return true;
            }
        }

        return false;
    }

    public function reinitialiser(User $user): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_active_le' => null,
            'totp_dernier_pas' => null,
            'codes_secours' => null,
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function genererCodesSecours(): array
    {
        return array_map(
            fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)),
            range(1, self::NB_CODES_SECOURS),
        );
    }
}
