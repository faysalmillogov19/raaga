<?php

namespace App\Models;

use App\Enums\FamilleMoyenPaiement;
use App\Enums\StatutTypeBoutique;
use App\Enums\UsageMoyenPaiement;
use App\Models\Concerns\ModificationDemandee;
use App\Models\Concerns\ReferentielGere;
use App\Support\MemoRequete;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Moyen de paiement (Faso Arzeka, virement et versement bancaires, Orange Money, Moov Africa Money, Telecel Money,
 * espèces au guichet…). Saisi par le RÉGULATEUR, validé par le VALIDATEUR (voir ReferentielGere), jamais supprimé :
 * un moyen désactivé n'est plus proposé, les paiements passés le gardent. Le code, jamais modifié, est la valeur
 * enregistrée dans les paiements et les paramètres. Pour chaque usage (actions, caution) : autorisé ou non, montant
 * minimum et maximum par opération (vide = sans limite). Aucune clé ni aucun secret d'opérateur ici (.env).
 * label() et value : mêmes usages que l'ancienne liste en dur, pour les écrans et les documents.
 */
#[Table(name: 'moyens_paiement')]
#[Fillable(['code', 'libelle', 'description', 'famille', 'instructions', 'usages', 'ordre', 'actif', 'par_defaut', 'statut'])]
class MoyenPaiement extends Model
{
    use ModificationDemandee, ReferentielGere;

    /** Code : lettres majuscules, chiffres et « _ » (ORANGE_MONEY, FASO_ARZEKA). */
    public const FORMAT_CODE = '/^[A-Z][A-Z0-9_]{1,19}$/';

    /** Code du virement bancaire, seul moyen des actions A (institutions). */
    public const VIREMENT = 'VIREMENT';

    protected function casts(): array
    {
        return ['famille' => FamilleMoyenPaiement::class, 'usages' => 'array', 'ordre' => 'integer', 'actif' => 'boolean', 'par_defaut' => 'boolean',
            'statut' => StatutTypeBoutique::class, 'valide_le' => 'datetime', 'version' => 'integer', 'modification_demandee' => 'array'];
    }

    public static function typeReferentiel(): string
    {
        return 'moyen_paiement';
    }

    protected function champsVersion(): array
    {
        return ['code', 'libelle', 'description', 'famille', 'instructions', 'usages', 'ordre', 'actif', 'statut'];
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
    /** Paiement manuel (banque : virement, versement ; guichet : espèces) : contrôlé par le CONTRÔLEUR DES PAIEMENTS. */
    public function estManuel(): bool
    {
        return in_array($this->famille, [FamilleMoyenPaiement::Banque, FamilleMoyenPaiement::Guichet], true);
    }

    public function estMobile(): bool
    {
        return $this->famille === FamilleMoyenPaiement::MobileMoney;
    }

    /** @return array{autorise: bool, min: ?int, max: ?int} */
    public function usage(UsageMoyenPaiement $usage): array
    {
        $u = (array) ($this->usages[$usage->value] ?? []);

        return ['autorise' => (bool) ($u['autorise'] ?? false), 'min' => isset($u['min']) ? (int) $u['min'] : null, 'max' => isset($u['max']) ? (int) $u['max'] : null];
    }

    /** Autorisé pour cet usage et plafonds (minimum, maximum par opération) qui couvrent le montant. */
    public function couvre(UsageMoyenPaiement $usage, int $montant): bool
    {
        $u = $this->usage($usage);

        return $u['autorise'] && ($u['min'] === null || $montant >= $u['min']) && ($u['max'] === null || $montant <= $u['max']);
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
     * Moyens proposables : validés et actifs, dans l'ordre d'affichage (option : seulement ces familles).
     *
     * @param  list<FamilleMoyenPaiement>|null  $familles
     * @return Collection<int, self>
     */
    public static function proposables(?array $familles = null): Collection
    {
        return static::query()->utilisables()->when($familles, fn ($q) => $q->whereIn('famille', array_map(fn ($f) => $f->value, $familles)))
            ->orderBy('ordre')->orderBy('libelle')->get();
    }

    /**
     * Moyens proposés pour un paiement : parmi les canaux retenus (tranche, campagne), seulement les moyens actifs,
     * confirmés, autorisés pour cet usage et dont les plafonds couvrent le montant à payer.
     *
     * @param  array<int, string>  $codes
     * @return list<self>
     */
    public static function pourPaiement(array $codes, UsageMoyenPaiement $usage, int $montant): array
    {
        return self::proposables()->filter(fn (self $m) => in_array($m->code, $codes, true) && $m->couvre($usage, $montant))->values()->all();
    }
}
