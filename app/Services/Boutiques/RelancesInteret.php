<?php

namespace App\Services\Boutiques;

use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutCampagneBoutiques;
use App\Enums\StatutInscriptionBoutique;
use App\Enums\StatutReservationBoutique;
use App\Models\Boutique;
use App\Models\BoutiqueStatut;
use App\Models\CampagneBoutiques;
use App\Models\InscriptionBoutique;
use App\Models\RelanceInteret;
use App\Models\ReservationBoutique;
use App\Models\User;
use App\Notifications\BoutiquesNonAttribueesRegulateur;
use App\Notifications\RelanceInteretCitoyen;
use App\Services\Audit\Journal;
use App\Services\Sms\EnvoiSms;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Liste d'attente générale d'une campagne : les souscripteurs inscrits sur au moins une boutique et attributaires
 * d'aucune (ni réservation en cours, ni boutique attribuée), hors identité annulée.
 *
 * Boutique libérée (désistement, impayé, identité) sans personne à retenir sur sa liste : la liste générale est
 * prévenue (SMS et « Mon espace ») et peut manifester à nouveau son intérêt jusqu'à la fin du délai de relance de la
 * campagne, même après la clôture des réservations ; le premier qui s'inscrit sur une boutique relancée en devient
 * l'attributaire. À la fin du délai, les boutiques restées libres d'une campagne qui ne prend plus de réservations sont
 * signalées au RÉGULATEUR, invité à ouvrir une nouvelle campagne. Une boutique n'est relancée qu'une fois par libération.
 */
class RelancesInteret
{
    private const CAMPAGNES = [StatutCampagneBoutiques::Ouverte, StatutCampagneBoutiques::Cloturee];

    public function __construct(private readonly Journal $journal) {}

    /** @return Collection<int, User> souscripteurs non attributaires de la campagne */
    public static function listeGenerale(CampagneBoutiques $campagne): Collection
    {
        $inscrits = InscriptionBoutique::query()->where('campagne_boutiques_id', $campagne->id)->select('user_id');
        $annules = InscriptionBoutique::query()->where('campagne_boutiques_id', $campagne->id)->where('statut', StatutInscriptionBoutique::Annulee)->select('user_id');
        $attributaires = ReservationBoutique::query()->where('campagne_boutiques_id', $campagne->id)->whereIn('statut', StatutReservationBoutique::actifs())->select('user_id');

        return User::query()->whereIn('id', $inscrits)->whereNotIn('id', $annules)->whereNotIn('id', $attributaires)->orderBy('id')->get();
    }

    /** Relance en cours qui ouvre cette boutique à ce citoyen (membre de la liste générale), ou null. */
    public static function ouvertePour(Boutique $boutique, ?User $user): ?RelanceInteret
    {
        if ($user === null) {
            return null;
        }
        $relance = RelanceInteret::query()->whereNull('cloturee_le')->where('fin_le', '>', now())
            ->whereJsonContains('boutiques', $boutique->id)->latest('id')->with('campagne')->first();

        return $relance && self::listeGenerale($relance->campagne)->contains('id', $user->id) ? $relance : null;
    }

    /** @return list<int> boutiques d'une relance en cours de la campagne ouvertes à ce citoyen */
    public static function boutiquesOuvertesA(CampagneBoutiques $campagne, ?User $user): array
    {
        if ($user === null || ! self::listeGenerale($campagne)->contains('id', $user->id)) {
            return [];
        }

        return RelanceInteret::query()->where('campagne_boutiques_id', $campagne->id)->whereNull('cloturee_le')->where('fin_le', '>', now())
            ->pluck('boutiques')->flatten()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Tâche planifiée : relances échues closes (RÉGULATEUR prévenu si besoin), puis nouvelles relances. @return array{ouvertes: int, closes: int} */
    public function executer(): array
    {
        $bilan = ['ouvertes' => 0, 'closes' => 0];

        RelanceInteret::query()->whereNull('cloturee_le')->where('fin_le', '<=', now())->with('campagne')->get()
            ->each(function (RelanceInteret $r) use (&$bilan) {
                $this->clore($r);
                $bilan['closes']++;
            });

        CampagneBoutiques::query()->whereIn('statut', self::CAMPAGNES)
            ->whereIn('id', InscriptionBoutique::query()->select('campagne_boutiques_id'))->get()
            ->each(function (CampagneBoutiques $c) use (&$bilan) {
                $bilan['ouvertes'] += (int) $this->relancer($c);
            });

        return $bilan;
    }

    /**
     * Boutiques libres de la campagne, perdues par un attributaire, et pas relancées depuis leur dernière libération
     * (après le passage au suivant de leur liste : personne n'a pu être retenu).
     *
     * @return list<int>
     */
    private function aRelancer(CampagneBoutiques $c): array
    {
        // Libres, et perdues par un attributaire de la campagne (expirée, annulée, désistement).
        $libres = Boutique::query()->whereIn('statut', [StatutBoutique::Disponible, StatutBoutique::EnCampagne])
            ->whereIn('id', ReservationBoutique::query()->where('campagne_boutiques_id', $c->id)
                ->whereIn('statut', [StatutReservationBoutique::Expiree, StatutReservationBoutique::Annulee])->select('boutique_id'))->pluck('id');
        if ($libres->isEmpty()) {
            return [];
        }
        // Dernière relance de chaque boutique (clés numériques : pas de fusion qui renumérote).
        $relancees = [];
        foreach (RelanceInteret::query()->where('campagne_boutiques_id', $c->id)->orderBy('id')->get(['boutiques', 'ouverte_le']) as $r) {
            foreach ($r->boutiques as $id) {
                $relancees[(int) $id] = $r->ouverte_le;
            }
        }
        $liberees = BoutiqueStatut::query()->whereIn('boutique_id', $libres)->where('nouveau', StatutBoutique::Disponible->value)
            ->selectRaw('boutique_id, max(created_at) as le')->groupBy('boutique_id')->pluck('le', 'boutique_id');

        return $libres->filter(function (int $id) use ($relancees, $liberees) {
            $relancee = $relancees[$id] ?? null;
            $liberee = $liberees[$id] ?? null;

            return $relancee === null || ($liberee !== null && $relancee->lt($liberee));
        })->values()->all();
    }

    private function relancer(CampagneBoutiques $c): bool
    {
        $boutiques = $this->aRelancer($c);
        if ($boutiques === []) {
            return false;
        }
        $liste = self::listeGenerale($c);
        // Personne à relancer : campagne encore ouverte, les boutiques restent proposées à tous ; sinon, RÉGULATEUR prévenu.
        if ($liste->isEmpty() && $c->accepteReservations()) {
            return false;
        }

        $relance = RelanceInteret::create([
            'campagne_boutiques_id' => $c->id, 'boutiques' => $boutiques, 'destinataires' => $liste->count(),
            'ouverte_le' => now(), 'fin_le' => $liste->isEmpty() ? now() : now()->addSeconds($c->delaiListeSecondes('relance')),
        ]);
        $this->journal->ecrire(null, 'relance_interet.ouverte', 'RelanceInteret', $relance->id, null,
            ['campagne' => $c->id, 'boutiques' => count($boutiques), 'destinataires' => $liste->count(), 'fin' => $relance->fin_le->toAtomString()]);

        if ($liste->isEmpty()) {
            $this->clore($relance->load('campagne'));

            return true;
        }
        $codes = Boutique::query()->whereIn('id', $boutiques)->orderBy('code')->pluck('code')->all();
        foreach ($liste as $user) {
            $user->notify(new RelanceInteretCitoyen($relance, $codes));
            $this->sms($user, $relance, $codes);
        }

        return true;
    }

    /** Fin du délai : boutiques restées libres notées ; campagne qui ne prend plus de réservations : RÉGULATEUR prévenu. */
    private function clore(RelanceInteret $r): void
    {
        $restantes = Boutique::query()->whereIn('id', $r->boutiques)->whereIn('statut', [StatutBoutique::Disponible, StatutBoutique::EnCampagne])
            ->orderBy('code')->pluck('code', 'id');
        $signaler = $restantes->isNotEmpty() && ! $r->campagne->accepteReservations();
        $r->forceFill(['cloturee_le' => now(), 'restantes' => $restantes->keys()->all(), 'regulateur_notifie_le' => $signaler ? now() : null])->save();
        $this->journal->ecrire(null, 'relance_interet.close', 'RelanceInteret', $r->id, null,
            ['restantes' => $restantes->count(), 'regulateur_notifie' => $signaler]);

        if ($signaler) {
            User::query()->where('actif', true)->whereHas('role', fn ($q) => $q->where('code', Role::Regulateur->value))->get()
                ->each(fn (User $u) => $u->notify(new BoutiquesNonAttribueesRegulateur($r->campagne, $restantes->values()->all())));
        }
    }

    /** @param  list<string>  $codes */
    private function sms(User $user, RelanceInteret $r, array $codes): void
    {
        if (! $user->telephone) {
            return;
        }
        try {
            app(EnvoiSms::class)->envoyer((string) $user->telephone, __('public.boutiques.sms.relance'), [
                'n' => count($codes), 'date' => $r->fin_le->translatedFormat('j F Y à H\hi'),
            ]);
        } catch (Throwable $e) {
            Log::warning('SMS de relance non envoyé', ['relance' => $r->id, 'user' => $user->id, 'erreur' => $e->getMessage()]);
        }
    }
}
