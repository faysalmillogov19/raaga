<?php

namespace App\Services\Cartes;

use App\Enums\StatutCarte;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\StatutReservationBoutique;
use App\Enums\TypeCarte;
use App\Enums\TypeDocumentKyc;
use App\Models\Carte;
use App\Models\KycDocument;
use App\Models\LigneRegistre;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\CarteEmise;
use App\Notifications\PieceBientotExpiree;
use App\Services\Audit\Journal;
use App\Support\Plateforme\ReglagesCarte;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cartes d'accès Raaga. Chaque événement (attribution ou annulation d'une boutique, inscription au registre, dossier
 * d'identité conforme ou douteux, compte désactivé) appelle synchroniser() : l'état attendu de la carte est recalculé
 * et appliqué (émission, mise à jour du contenu, suspension, révocation), sous verrou du titulaire.
 *
 * COMMERÇANT : dès sa première boutique attribuée ; emplacements (code et site) ; validité = fin du contrat le plus
 * lointain (signature + durée paramétrée). ACTIONNAIRE : dès sa première inscription au registre ; numéro d'actionnaire,
 * sans nombre d'actions ni montant ; validité 1 an, renouvelée tant qu'il détient des actions. Les deux ne sont émises
 * que si le dossier d'identité est conforme (sinon : en attente) ; jamais au-delà de l'expiration de la pièce ; identité
 * douteuse : pas de carte, carte actionnaire suspendue. PERSONNEL : émise par le SUPER_ADMIN, 1 an, révoquée si le compte
 * est désactivé.
 */
class Cartes
{
    /** Motif enregistré pour une révocation manuelle confirmée (le motif saisi par l'ADMIN reste dans revocation_motif). */
    public const MOTIF_MANUEL = 'manuelle';

    public function __construct(private readonly Journal $journal) {}

    public static function courante(User $user, TypeCarte $type): ?Carte
    {
        return Carte::query()->where('user_id', $user->id)->where('type', $type)->courantes()->first();
    }

    /** Recalcule et applique l'état de la carte de ce type ; rend la carte courante (ou null). */
    public function synchroniser(User $user, TypeCarte $type, ?User $acteur = null): ?Carte
    {
        $notifier = null;
        $carte = DB::transaction(function () use ($user, $type, $acteur, &$notifier) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $courante = Carte::query()->where('user_id', $user->id)->where('type', $type)->courantes()->lockForUpdate()->first();
            $attendu = $this->attendu($user, $type, $courante);

            if ($attendu === null) {
                if ($courante) {
                    $this->revoquer($courante, $type === TypeCarte::Personnel ? 'compte_desactive' : 'plus_eligible', $acteur);
                }

                return null;
            }
            [$statut, $contenu, $validite] = $attendu;

            if ($courante === null) {
                // Révoquée manuellement (ADMIN, confirmé par le VALIDATEUR) : plus d'émission automatique ; seule une
                // réémission par l'ADMIN redonne une carte.
                if (self::revoqueeManuellement($user, $type)) {
                    return null;
                }
                $courante = $this->creer($user, $type, $statut, $contenu, $validite, $acteur);
                $notifier = $statut === StatutCarte::Active ? $courante : null;

                return $courante;
            }

            $etaitActive = $courante->statut === StatutCarte::Active;
            $change = $courante->contenu != $contenu || $courante->valide_jusqu_au?->toDateString() !== $validite?->toDateString();
            $courante->forceFill([
                'statut' => $statut,
                'contenu' => $contenu,
                'valide_jusqu_au' => $validite,
                'emise_le' => $courante->emise_le ?? ($statut === StatutCarte::Active ? now() : null),
                'suspendue_le' => $statut === StatutCarte::Suspendue ? ($courante->suspendue_le ?? now()) : null,
                'motif' => $statut === StatutCarte::Suspendue ? 'identite' : null,
                // Contenu modifié : la carte (PDF, image) est régénérée à sa nouvelle version.
                'version' => $courante->version + ($change ? 1 : 0),
            ])->save();
            if ($statut === StatutCarte::Active && ! $etaitActive) {
                $notifier = $courante;
                $this->journal->ecrire($acteur, 'carte.activee', 'Carte', $courante->id, null, ['numero' => $courante->numero]);
            } elseif ($statut === StatutCarte::Suspendue && $etaitActive) {
                $this->journal->ecrire($acteur, 'carte.suspendue', 'Carte', $courante->id, null, ['numero' => $courante->numero, 'motif' => 'identite']);
            }

            return $courante;
        });

        if ($notifier) {
            $notifier->user->notify(new CarteEmise($notifier));
        }

        return $carte;
    }

    /** Tous les types qui concernent ce titulaire. */
    public function synchroniserTout(User $user, ?User $acteur = null): void
    {
        foreach (TypeCarte::cases() as $type) {
            if ($type !== TypeCarte::Personnel || self::courante($user, $type)) {
                $this->synchroniser($user, $type, $acteur);
            }
        }
    }

    /** Carte du PERSONNEL : émise par le SUPER_ADMIN pour un compte actif du back-office (remplace la précédente). */
    public function emettrePersonnel(User $user, User $acteur): Carte
    {
        abort_unless($user->estBackoffice() && $user->actif, 422);

        return $this->reemettre($user, TypeCarte::Personnel, $acteur, 'emission');
    }

    /**
     * Réémission (perte, émission du personnel) : l'ancienne carte est révoquée, la nouvelle a un nouveau numéro et un
     * nouveau jeton de QR code.
     */
    public function reemettre(User $user, TypeCarte $type, User $acteur, string $motif = 'reemission'): Carte
    {
        $carte = DB::transaction(function () use ($user, $type, $acteur, $motif) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $ancienne = Carte::query()->where('user_id', $user->id)->where('type', $type)->courantes()->lockForUpdate()->first();
            if ($ancienne) {
                $this->revoquer($ancienne, $motif, $acteur);
            }
            $attendu = $type === TypeCarte::Personnel
                ? [StatutCarte::Active, $this->contenu($user, $type), CarbonImmutable::today()->addMonths((int) config('raaga.cartes.validite_personnel_mois'))]
                : $this->attendu($user, $type, null);
            abort_if($attendu === null, 422);
            $carte = $this->creer($user, $type, $attendu[0], $attendu[1], $attendu[2], $acteur, $ancienne);

            return $carte;
        });
        if ($carte->statut === StatutCarte::Active) {
            $carte->user->notify(new CarteEmise($carte));
        }

        return $carte;
    }

    /**
     * Échéances quotidiennes : carte ACTIONNAIRE renouvelée d'un an tant qu'il détient des actions (jamais au-delà de la
     * pièce) ; autres cartes échues : expirées. Avertissement 30 jours avant l'expiration de la pièce d'identité.
     *
     * @return array{renouvelees: int, expirees: int, avertis: int}
     */
    public function echeances(): array
    {
        $bilan = ['renouvelees' => 0, 'expirees' => 0, 'avertis' => 0];
        $echues = Carte::query()->courantes()->where('statut', StatutCarte::Active)->whereDate('valide_jusqu_au', '<', today())->with('user')->get();
        foreach ($echues as $carte) {
            $user = $carte->user;
            $piece = $user->piece_expire_le ? CarbonImmutable::parse($user->piece_expire_le) : null;
            $renouvelable = $carte->type === TypeCarte::Actionnaire && $this->detientDesActions($user) && ($piece === null || $piece->isFuture());
            if ($renouvelable) {
                $validite = CarbonImmutable::today()->addMonths((int) config('raaga.cartes.validite_actionnaire_mois'));
                $carte->forceFill(['valide_jusqu_au' => $piece ? $validite->min($piece) : $validite, 'version' => $carte->version + 1])->save();
                $this->journal->ecrire(null, 'carte.renouvelee', 'Carte', $carte->id, null, ['numero' => $carte->numero]);
                $bilan['renouvelees']++;

                continue;
            }
            $carte->forceFill(['statut' => StatutCarte::Expiree, 'courante' => null])->save();
            $this->journal->ecrire(null, 'carte.expiree', 'Carte', $carte->id, null, ['numero' => $carte->numero]);
            $bilan['expirees']++;
        }

        $jours = (int) config('raaga.cartes.avertissement_piece_jours');
        $titulaires = User::query()->whereNull('piece_avertie_le')->whereNotNull('piece_expire_le')
            ->whereDate('piece_expire_le', '>=', today())->whereDate('piece_expire_le', '<=', today()->addDays($jours))
            ->whereHas('cartes', fn ($q) => $q->courantes()->where('statut', StatutCarte::Active))->get();
        foreach ($titulaires as $user) {
            $user->notify(new PieceBientotExpiree($user->piece_expire_le));
            $user->forceFill(['piece_avertie_le' => now()])->saveQuietly();
            $bilan['avertis']++;
        }

        return $bilan;
    }

    /** Révocation manuelle demandée par l'ADMIN (motif) : la carte reste valide jusqu'à la décision du VALIDATEUR. */
    public function demanderRevocation(Carte $carte, string $motif, User $admin): void
    {
        abort_unless($carte->courante && $carte->revocation_demandee_le === null, 409);
        $carte->forceFill(['revocation_demandee_le' => now(), 'revocation_demandee_par' => $admin->id, 'revocation_motif' => $motif])->save();
        $this->journal->ecrire($admin, 'carte.revocation_demandee', 'Carte', $carte->id, null, ['numero' => $carte->numero, 'motif' => $motif]);
    }

    /** Le VALIDATEUR confirme : révocation immédiate (refusée au scan dès maintenant). */
    public function confirmerRevocation(Carte $carte, User $validateur): void
    {
        DB::transaction(function () use ($carte, $validateur) {
            $carte = Carte::query()->lockForUpdate()->findOrFail($carte->id);
            abort_if($carte->revocation_demandee_le === null, 409);
            $carte->forceFill(['revocation_demandee_le' => null])->save();
            if ($carte->courante) {
                $this->revoquer($carte, self::MOTIF_MANUEL, $validateur);
            }
        });
    }

    public function rejeterRevocation(Carte $carte, User $validateur, string $motif): void
    {
        abort_if($carte->revocation_demandee_le === null, 409);
        $carte->forceFill(['revocation_demandee_le' => null, 'revocation_demandee_par' => null, 'revocation_motif' => null])->save();
        $this->journal->ecrire($validateur, 'carte.revocation_rejetee', 'Carte', $carte->id, null, ['numero' => $carte->numero, 'motif' => $motif]);
    }

    /** La dernière carte de ce type a-t-elle été révoquée manuellement (sans réémission depuis) ? */
    public static function revoqueeManuellement(User $user, TypeCarte $type): bool
    {
        return Carte::query()->where('user_id', $user->id)->where('type', $type)->latest('id')->value('motif') === self::MOTIF_MANUEL;
    }

    public function revoquer(Carte $carte, string $motif, ?User $acteur): void
    {
        $carte->forceFill(['statut' => StatutCarte::Revoquee, 'courante' => null, 'revoquee_le' => now(), 'motif' => $motif, 'revocation_demandee_le' => null])->save();
        $this->journal->ecrire($acteur, 'carte.revoquee', 'Carte', $carte->id, null, ['numero' => $carte->numero, 'motif' => $motif]);
    }

    /**
     * État attendu de la carte : [statut, contenu, validité], ou null quand le titulaire n'y a pas (ou plus) droit.
     *
     * @return array{0: StatutCarte, 1: array<string, mixed>, 2: ?CarbonImmutable}|null
     */
    private function attendu(User $user, TypeCarte $type, ?Carte $courante): ?array
    {
        if ($type === TypeCarte::Personnel) {
            return $courante && $user->actif ? [StatutCarte::Active, $this->contenu($user, $type), $courante->valide_jusqu_au?->toImmutable()] : null;
        }

        $validite = match ($type) {
            TypeCarte::Commercant => $this->finContrats($user),
            TypeCarte::Actionnaire => $this->detientDesActions($user)
                ? ($courante?->valide_jusqu_au?->isFuture() ? $courante->valide_jusqu_au->toImmutable() : CarbonImmutable::today()->addMonths((int) config('raaga.cartes.validite_actionnaire_mois')))
                : null,
        };
        if ($validite === null) {
            return null;
        }
        // Jamais au-delà de la date d'expiration de la pièce d'identité.
        if ($user->piece_expire_le) {
            $validite = $validite->min(CarbonImmutable::parse($user->piece_expire_le));
        }

        // Actions gelées (identité non corrigée dans le délai) : carte d'actionnaire bloquée jusqu'au dégel.
        $conforme = $user->statut_kyc === StatutKyc::Valide && ! $validite->isPast() && ! ($type === TypeCarte::Actionnaire && $user->actionsGelees());
        $statut = match (true) {
            $conforme => StatutCarte::Active,
            // Identité douteuse : aucune carte ; une carte déjà émise est suspendue jusqu'à régularisation.
            $user->statut_kyc === StatutKyc::Rejete => $courante && $courante->statut !== StatutCarte::EnAttente ? StatutCarte::Suspendue : null,
            default => $courante && $courante->statut !== StatutCarte::EnAttente ? StatutCarte::Suspendue : StatutCarte::EnAttente,
        };

        return $statut === null ? null : [$statut, $this->contenu($user, $type), $validite];
    }

    /** Fin du contrat le plus lointain des boutiques attribuées (signature + durée du contrat), ou null s'il n'y en a pas. */
    private function finContrats(User $user): ?CarbonImmutable
    {
        $annees = (int) config('raaga.cartes.duree_contrat_annees');

        return ReservationBoutique::query()->where('user_id', $user->id)->where('statut', StatutReservationBoutique::Attribuee)->get()
            ->map(fn (ReservationBoutique $r) => CarbonImmutable::parse($r->contrat_signe_le ?? $r->attribuee_le ?? now())->addYears($annees))
            ->max();
    }

    private function detientDesActions(User $user): bool
    {
        return (int) LigneRegistre::query()->where('user_id', $user->id)->sum('nb_actions') > 0;
    }

    /**
     * Contenu affiché : nom (identité validée seulement), date de naissance, photo (selfie validé), et selon le type les
     * emplacements, le numéro d'actionnaire ou la fonction. Jamais de montant ni de nombre d'actions. Toute donnée
     * imprimée y figure : sa modification donne une nouvelle version de la carte, donc de nouveaux fichiers.
     *
     * @return array<string, mixed>
     */
    private function contenu(User $user, TypeCarte $type): array
    {
        $identite = (array) ($user->identite_validee ?? []);
        $photo = KycDocument::query()->where('user_id', $user->id)->where('type', TypeDocumentKyc::Selfie)->where('statut', StatutDocumentKyc::Valide)
            ->latest('id')->value('id');
        $naissance = $identite['date_naissance'] ?? $user->date_naissance;
        $contenu = [
            'nom' => trim(($identite['prenoms'] ?? $user->prenoms ?? '').' '.($identite['nom'] ?? $user->nom ?? '')) ?: $user->name,
            'date_naissance' => $naissance ? CarbonImmutable::parse($naissance)->toDateString() : null,
            'photo' => $photo,
        ];
        // Personne morale : raison sociale, avec le nom et la photo du représentant légal (identité validée).
        if ($type !== TypeCarte::Personnel && filled($identite['raison_sociale'] ?? null)) {
            $contenu = ['raison_sociale' => $identite['raison_sociale'], ...$contenu];
        }

        return match ($type) {
            TypeCarte::Commercant => [...$contenu, 'emplacements' => ReservationBoutique::query()->where('user_id', $user->id)
                ->where('statut', StatutReservationBoutique::Attribuee)->with('boutique.site')->get()
                ->map(fn (ReservationBoutique $r) => ['code' => $r->boutique?->code, 'site' => $r->boutique?->site?->nom])
                ->sortBy('code')->values()->all()],
            TypeCarte::Actionnaire => [...$contenu, 'numero_actionnaire' => sprintf('RG-A-%06d', $user->id)],
            TypeCarte::Personnel => [...$contenu, 'fonction' => $user->codeRole()?->label()],
        };
    }

    private function creer(User $user, TypeCarte $type, StatutCarte $statut, array $contenu, ?CarbonImmutable $validite, ?User $acteur, ?Carte $ancienne = null): Carte
    {
        $carte = Carte::create([
            'type' => $type, 'user_id' => $user->id, 'statut' => $statut, 'courante' => true, 'jeton' => Str::random(64),
            'emise_le' => $statut === StatutCarte::Active ? now() : null, 'valide_jusqu_au' => $validite, 'contenu' => $contenu,
            'version' => 1, 'emise_par' => $acteur?->id, 'remplace_carte_id' => $ancienne?->id,
            // Réimpression à l'identique : réglages de rendu en vigueur figés à l'émission (null s'il en manque un : ils
            // seront figés à la première génération, une fois complets).
            'reglages_rendu' => ReglagesCarte::instantane(),
        ]);
        $carte->forceFill(['numero' => sprintf('RG-C-%06d', $carte->id)])->save();
        $this->journal->ecrire($acteur, $statut === StatutCarte::Active ? 'carte.emise' : 'carte.en_attente', 'Carte', $carte->id, null,
            ['numero' => $carte->numero, 'type' => $type->value, 'remplace' => $ancienne?->numero]);

        return $carte;
    }
}
