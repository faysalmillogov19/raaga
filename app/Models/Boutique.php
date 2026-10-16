<?php

namespace App\Models;

use App\Enums\EtatPhysiqueBoutique;
use App\Enums\PositionBoutique;
use App\Enums\StatutBoutique;
use App\Models\Concerns\AvecNiveau;
use App\Services\Backoffice\RegleGestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Boutique d'un site : code SITE-NIVEAU-BLOC-NUMÉRO (ex. RAA-R1-A-045), fiche (niveau, bloc, surface, type, position,
 * coffret, planche, état physique, observations) et statut commercial. Le code est calculé à la création, ne change jamais et
 * n'est jamais réutilisé : une boutique supprimée reste en base (suppression logique) et garde son code.
 */
#[Fillable([
    'site_id', 'type_boutique_id', 'numero', 'niveau', 'bloc', 'surface',
    'position', 'coffret', 'planche', 'etat_physique', 'observations', 'statut',
])]
class Boutique extends Model
{
    use AvecNiveau, SoftDeletes;

    /** Bloc : code d'une zone, 1 à 5 lettres majuscules ou chiffres (A, B2, MAG, ETAL), comme ZoneSite::FORMAT_CODE. */
    public const FORMAT_BLOC = '/^[A-Z0-9]{1,5}$/';

    /** Numéro : 1 à 3 chiffres, écrit sur 3 chiffres dans le code. */
    public const CHIFFRES_NUMERO = 3;

    /** Champs qui composent le code : figés dès que le code existe. */
    private const ELEMENTS_DU_CODE = ['site_id', 'niveau', 'bloc', 'numero', 'code'];

    protected function casts(): array
    {
        return [
            'surface' => 'decimal:2',
            'statut' => StatutBoutique::class,
            'position' => PositionBoutique::class,
            'etat_physique' => EtatPhysiqueBoutique::class,
        ];
    }

    /** Seul changement de code admis : le recodage qui suit un changement de code du site (Site::recoderBoutiques). */
    private static bool $recodage = false;

    protected static function booted(): void
    {
        static::saving(function (Boutique $boutique) {
            if (! self::$recodage && $boutique->getOriginal('code') !== null && $boutique->isDirty(self::ELEMENTS_DU_CODE)) {
                throw new RegleGestionException(__('admin.sites.boutiques.code_fige', ['code' => $boutique->getOriginal('code')]));
            }
            if ($boutique->code === null) {
                $boutique->code = self::composerCode($boutique->site?->code, $boutique->niveau, $boutique->bloc, $boutique->numero);
            }
            // Un code déjà attribué (même à une boutique supprimée) ou abandonné lors d'un recodage n'est jamais réutilisé.
            if ($boutique->code !== null && $boutique->isDirty('code')
                && (self::withTrashed()->where('code', $boutique->code)->whereKeyNot($boutique->getKey())->exists()
                    || HistoriqueCode::query()->boutiques()->where('ancien_code', $boutique->code)->exists())) {
                throw new RegleGestionException(__('admin.sites.boutiques.code_pris', ['code' => $boutique->code]));
            }
        });
    }

    /** Change le statut commercial et le garde dans l'historique (boutique_statuts, ajout seul). */
    public function changerStatut(StatutBoutique $nouveau, string $motif, ?ReservationBoutique $reservation = null, ?User $auteur = null): void
    {
        $ancien = $this->statut;
        if ($ancien === $nouveau) {
            return;
        }
        $this->forceFill(['statut' => $nouveau])->save();
        BoutiqueStatut::create(['boutique_id' => $this->id, 'ancien' => $ancien?->value, 'nouveau' => $nouveau->value,
            'reservation_boutique_id' => $reservation?->id, 'auteur_id' => $auteur?->id, 'motif' => $motif, 'created_at' => now()]);
    }

    /** Recode la boutique avec le nouveau code de son site ; l'ancien code est conservé dans l'historique. */
    public function recoder(string $codeSite, ?User $auteur): void
    {
        $ancien = $this->code;
        $nouveau = self::composerCode($codeSite, $this->niveau, $this->bloc, $this->numero);
        if ($ancien === null || $nouveau === null || $ancien === $nouveau) {
            return;
        }

        self::$recodage = true;
        try {
            $this->forceFill(['code' => $nouveau])->save();
        } finally {
            self::$recodage = false;
        }
        HistoriqueCode::create(['objet_type' => HistoriqueCode::BOUTIQUE, 'objet_id' => $this->id, 'ancien_code' => $ancien,
            'nouveau_code' => $nouveau, 'auteur_id' => $auteur?->id, 'created_at' => now()]);
    }

    /**
     * Code SITE-NIVEAU-BLOC-NUMÉRO, ou null si un élément manque ou est mal formé (jamais de valeur inventée).
     * Le numéro garde ses derniers chiffres (« A-045 » → 045) pour les boutiques saisies avant le code.
     */
    public static function composerCode(?string $site, ?string $niveau, ?string $bloc, ?string $numero): ?string
    {
        $bloc = $bloc === null ? null : mb_strtoupper(trim($bloc));
        if ($site === null || ! preg_match(Niveau::FORMAT_CODE, (string) $niveau) || $bloc === null || ! preg_match(self::FORMAT_BLOC, $bloc)
            || ! preg_match('/(?<!\d)(\d{1,'.self::CHIFFRES_NUMERO.'})$/', (string) $numero, $m) || (int) $m[1] === 0) {
            return null;
        }

        return implode('-', [$site, $niveau, $bloc, str_pad((string) (int) $m[1], self::CHIFFRES_NUMERO, '0', STR_PAD_LEFT)]);
    }

    /** Ce qui identifie la boutique à l'écran, dans les SMS et les tirages : le code, ou l'ancien numéro tant qu'il manque. */
    public function reference(): string
    {
        return $this->code ?? (string) $this->numero;
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<TypeBoutique, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(TypeBoutique::class, 'type_boutique_id');
    }
}
