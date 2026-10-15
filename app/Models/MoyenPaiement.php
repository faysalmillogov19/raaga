<?php

namespace App\Models;

use App\Enums\StatutTypeBoutique;
use App\Enums\TypeMoyenPaiement;
use App\Models\Concerns\ModificationDemandee;
use App\Models\Concerns\ReferentielGere;
use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Moyen de paiement (Orange Money, Moov Africa Money, Telecel Money, Faso Arzeka, virement bancaire, espèces…) proposé
 * pour les souscriptions, les cautions et les dividendes. Saisi par le RÉGULATEUR, validé par le VALIDATEUR (voir
 * ReferentielGere) ; un moyen désactivé n'est plus proposé, les paiements passés le gardent. Le code, jamais modifié,
 * est la valeur enregistrée dans les paiements et les paramètres (canaux actifs d'une tranche ou d'une campagne).
 * label() et value : mêmes usages que l'ancienne liste en dur, pour les écrans et les documents.
 */
#[Table(name: 'moyens_paiement')]
#[Fillable(['code', 'libelle', 'description', 'type', 'ordre', 'actif', 'par_defaut', 'statut'])]
class MoyenPaiement extends Model
{
    use ModificationDemandee, ReferentielGere;

    /** Code : lettres majuscules, chiffres et « _ » (ORANGE_MONEY, FASO_ARZEKA). */
    public const FORMAT_CODE = '/^[A-Z][A-Z0-9_]{1,19}$/';

    /** Code du virement bancaire, seul moyen des actions A (institutions). */
    public const VIREMENT = 'VIREMENT';

    protected function casts(): array
    {
        return ['type' => TypeMoyenPaiement::class, 'ordre' => 'integer', 'actif' => 'boolean', 'par_defaut' => 'boolean',
            'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime', 'version' => 'integer', 'modification_demandee' => 'array'];
    }

    public static function typeReferentiel(): string
    {
        return 'moyen_paiement';
    }

    protected function champsVersion(): array
    {
        return ['code', 'libelle', 'description', 'type', 'ordre', 'actif', 'statut'];
    }

    public function label(): string
    {
        return $this->libelle;
    }

    /** Code (valeur enregistrée), comme la valeur d'une énumération. */
    public function getValueAttribute(): string
    {
        return $this->code;
    }

    /** Le payeur saisit son numéro de téléphone (mobile money). */
    public function estMobile(): bool
    {
        return $this->type === TypeMoyenPaiement::MobileMoney;
    }

    /** Paiements, paramètres de tranches, de campagnes ou de distributions qui citent ce moyen. */
    public function nombreUtilisations(): int
    {
        return Paiement::query()->where('canal', $this->code)->count()
            + Tranche::query()->whereJsonContains('canaux_actifs', $this->code)->count()
            + CampagneBoutiques::query()->whereJsonContains('canaux_actifs', $this->code)->count()
            + DistributionDividende::query()->where(fn ($q) => $q->whereJsonContains('canaux_autorises', $this->code)->orWhere('canal_defaut', $this->code))->count();
    }

    /** Moyen d'après son code (null : code inconnu) ; lu une fois par page (App\Support\MemoRequete). */
    public static function parCode(?string $code): ?self
    {
        if ($code === null || $code === '') {
            return null;
        }

        return MemoRequete::retenir('moyen_paiement.'.$code, fn () => static::query()->where('code', $code)->first());
    }

    /** Libellé d'un code ; le code lui-même s'il n'existe plus. */
    public static function libelleDe(?string $code): string
    {
        return self::parCode($code)?->libelle ?? (string) $code;
    }

    /**
     * Moyens proposables : validés et actifs, dans l'ordre d'affichage (option : seulement ces types).
     *
     * @param  list<TypeMoyenPaiement>|null  $types
     * @return Collection<int, self>
     */
    public static function proposables(?array $types = null): Collection
    {
        return static::query()->utilisables()->when($types, fn ($q) => $q->whereIn('type', array_map(fn ($t) => $t->value, $types)))
            ->orderBy('ordre')->orderBy('libelle')->get();
    }

    /**
     * Moyens proposables parmi des codes enregistrés (canaux actifs d'une tranche ou d'une campagne), dans l'ordre d'affichage.
     *
     * @param  array<int, string>  $codes
     * @return list<self>
     */
    public static function parmi(array $codes): array
    {
        return self::proposables()->filter(fn (self $m) => in_array($m->code, $codes, true))->values()->all();
    }
}
