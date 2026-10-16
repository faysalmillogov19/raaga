<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Enums\FamilleMoyenPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutReservationBoutique;
use App\Enums\StatutSouscription;
use App\Http\Controllers\Controller;
use App\Models\CorrectionPaiement;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\Relance;
use App\Models\ReservationBoutique;
use App\Models\Souscription;
use App\Services\Audit\Journal;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Paiement\ControlePaiements;
use App\Services\Paiement\Relances;
use App\Support\Tableau\Colonne;
use App\Support\Tableau\Filtre;
use App\Support\Tableau\ResultatTableau;
use App\Support\Tableau\Tableau;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dossiers de paiement (CONTRÔLEUR DES PAIEMENTS) : souscriptions d'actions et cautions des candidatures aux boutiques,
 * avec leurs paiements et leurs relances. Le contrôleur rapproche, marque les paiements manuels (reçu, incomplet,
 * rejeté), relance et propose la correction d'un paiement confirmé ; le VALIDATEUR confirme ou rejette la correction.
 */
class PaiementsController extends Controller
{
    public const VUES = ['souscriptions' => Souscription::class, 'cautions' => ReservationBoutique::class];

    public function __construct(private readonly ControlePaiements $controle, private readonly Relances $relances) {}

    public function index(Request $request): Response|StreamedResponse|View
    {
        Gate::authorize(Droit::PaiementsVoir->value);
        $vue = array_key_exists((string) $request->query('vue'), self::VUES) ? (string) $request->query('vue') : 'souscriptions';
        $classe = self::VUES[$vue];
        $c = __('admin.paiements.colonnes');
        $statuts = $vue === 'souscriptions' ? StatutSouscription::cases() : StatutReservationBoutique::cases();
        $manuels = MoyenPaiement::query()->whereIn('famille', [FamilleMoyenPaiement::Banque, FamilleMoyenPaiement::Guichet])->pluck('code')->all();
        $dernier = fn ($o) => $o->paiements->sortByDesc('id')->first();

        return Tableau::pour($classe::query()->with(['user', 'paiements'])->withCount('relances'), 'paiements-'.$vue)
            ->colonnes([
                Colonne::make('reference', $c['reference'])->triable()->recherchable()->classe('font-mono font-semibold'),
                Colonne::make('citoyen', $c['citoyen'])->recherchable(fn ($q, $terme) => $q->whereHas('user', fn ($u) => $u->whereContient('name', $terme)))
                    ->valeur(fn ($o) => $o->user?->name),
                Colonne::make('statut', $c['statut'])->triable()->valeur(fn ($o) => $o->statut->label()),
                Colonne::make('montant', $c['montant'])->numerique()->valeur(fn ($o) => format_fcfa($o->montantAPayer() + $o->fraisAPayer()))
                    ->texte(fn ($o) => $o->montantAPayer() + $o->fraisAPayer()),
                Colonne::make('echeance', $c['echeance'])->triable('expire_le')->classe('whitespace-nowrap')->valeur(fn ($o) => $o->expire_le?->format('d/m/Y H:i')),
                Colonne::make('paiement', $c['paiement'])->valeur(fn ($o) => ($p = $dernier($o))
                    ? $p->canal?->libelle.' · '.$p->statut->label().($p->controle ? ' · '.__('admin.paiements.controles.'.$p->controle) : '') : $c['aucun']),
                Colonne::make('relances', $c['relances'])->numerique()->triable('relances_count')->valeur(fn ($o) => format_nombre($o->relances_count)),
                Colonne::make('actions', $c['actions'])->vue('admin.paiements._actions', 'objet')->exportable(false),
            ])
            ->filtres([
                Filtre::make('statut', $c['statut'], collect($statuts)->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(), 'statut'),
                // Paiements manuels en attente : ce que le contrôleur doit examiner (les paiements des opérateurs se confirment seuls).
                Filtre::make('controle', $c['a_controler'], ['oui' => __('admin.paiements.a_controler')],
                    fn (Builder $q) => $q->whereHas('paiements', fn ($p) => $p->where('statut', StatutPaiement::EnAttente)->whereIn('canal', $manuels))),
            ])
            ->triParDefaut('echeance', 'asc')
            ->unite(__('admin.paiements.unite'))
            ->export('Raaga_paiements-'.$vue)
            ->repondre($request, fn (ResultatTableau $t) => view('admin.paiements.index', ['tableau' => $t, 'vue' => $vue]));
    }

    public function dossier(string $type, int $id): View
    {
        Gate::authorize(Droit::PaiementsVoir->value);
        $objet = $this->objet($type, $id);
        $objet->load(['user', 'paiements.corrections', 'paiements.controleur']);

        return view('admin.paiements.dossier', [
            'objet' => $objet, 'type' => $type,
            'relances' => Relance::query()->where($type === 'souscription' ? 'souscription_id' : 'reservation_boutique_id', $objet->id)->with('auteur')->latest('id')->get(),
            'remboursements' => $objet->remboursements()->latest('id')->get(),
        ]);
    }

    /** Preuve d'un paiement manuel (avis de virement, bordereau, reçu de caisse) : consultation journalisée. */
    public function preuve(Request $request, Paiement $paiement, Journal $journal): StreamedResponse
    {
        Gate::authorize(Droit::PaiementsVoir->value);
        abort_unless($paiement->preuve_chemin && Storage::disk(VerificationIdentite::DISQUE)->exists($paiement->preuve_chemin), 404);
        $journal->ecrire($request->user(), 'paiement.preuve_consultee', 'Paiement', $paiement->id);

        return Storage::disk(VerificationIdentite::DISQUE)->response($paiement->preuve_chemin, $paiement->preuve_nom, [
            'Content-Type' => $paiement->preuve_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
        ]);
    }

    public function marquer(Request $request, Paiement $paiement): RedirectResponse
    {
        Gate::authorize(Droit::SouscriptionsRapprocher->value);
        $donnees = $request->validate([
            'controle' => ['required', 'in:'.Paiement::RECU.','.Paiement::INCOMPLET.','.Paiement::REJETE],
            'motif' => ['nullable', 'required_unless:controle,'.Paiement::RECU, 'string', 'min:5', 'max:1000'],
            'controle_reference' => ['nullable', 'string', 'max:100'],
            'controle_montant_recu' => ['nullable', 'required_if:controle,'.Paiement::INCOMPLET, 'integer', 'min:1'],
        ], ['controle_montant_recu.*' => __('admin.paiements.erreurs.montant_recu')], __('admin.paiements.champs'));

        $this->controle->marquer($paiement, $donnees['controle'], $donnees['motif'] ?? null, $donnees['controle_reference'] ?? null, $request->user(),
            isset($donnees['controle_montant_recu']) ? (int) $donnees['controle_montant_recu'] : null);

        return $this->retour($paiement, 'admin.paiements.messages.controle_'.strtolower($donnees['controle']));
    }

    public function proposerCorrection(Request $request, Paiement $paiement): RedirectResponse
    {
        Gate::authorize(Droit::SouscriptionsRapprocher->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], [], __('admin.paiements.champs'))['motif'];
        $this->controle->proposerCorrection($paiement, $motif, $request->user());

        return $this->retour($paiement, 'admin.paiements.messages.correction_proposee');
    }

    public function relancer(Request $request, string $type, int $id): RedirectResponse
    {
        Gate::authorize(Droit::RelancesEnvoyer->value);
        $objet = $this->objet($type, $id);
        $message = $request->validate(['message' => ['nullable', 'string', 'max:500']], [], __('admin.paiements.champs'))['message'] ?? null;
        $this->relances->relancer($objet, Relance::MANUELLE, $request->user(), $message);

        return redirect()->route('admin.paiements.dossier', [$type, $id])->with('succes', __('admin.paiements.messages.relance'));
    }

    public function confirmerCorrection(Request $request, CorrectionPaiement $correction): RedirectResponse
    {
        Gate::authorize(Droit::PaiementsCorrectionsConfirmer->value);
        $this->controle->confirmerCorrection($correction, $request->user());

        return redirect()->route('admin.a-valider.rubrique', 'paiements')->with('succes', __('admin.paiements.messages.correction_confirmee'));
    }

    public function rejeterCorrection(Request $request, CorrectionPaiement $correction): RedirectResponse
    {
        Gate::authorize(Droit::PaiementsCorrectionsConfirmer->value);
        $motif = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:1000']], ['motif.*' => __('admin.utilisateurs.erreurs.motif')])['motif'];
        $this->controle->rejeterCorrection($correction, $request->user(), $motif);

        return redirect()->route('admin.a-valider.rubrique', 'paiements')->with('succes', __('admin.paiements.messages.correction_rejetee'));
    }

    private function objet(string $type, int $id): Souscription|ReservationBoutique
    {
        return match ($type) {
            'souscription' => Souscription::query()->findOrFail($id),
            'caution' => ReservationBoutique::query()->findOrFail($id),
            default => abort(404),
        };
    }

    private function retour(Paiement $paiement, string $message): RedirectResponse
    {
        return redirect()->route('admin.paiements.dossier', $paiement->souscription_id ? ['souscription', $paiement->souscription_id] : ['caution', $paiement->reservation_boutique_id])
            ->with('succes', __($message));
    }
}
