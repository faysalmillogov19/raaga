<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CanalSouscription;
use App\Enums\DocumentAccepte;
use App\Enums\Droit;
use App\Enums\StatutKyc;
use App\Enums\StatutSouscription;
use App\Enums\TypeDocumentKyc;
use App\Http\Controllers\Compte\ValideTelephone;
use App\Http\Controllers\Controller;
use App\Models\Souscription;
use App\Models\User;
use App\Services\Auth\OtpException;
use App\Services\Compte\CreationCompte;
use App\Services\Documents\GenerateurPdf;
use App\Services\Guichet\SouscriptionGuichet;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Souscriptions\PreparationSouscription;
use App\Support\Recu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Souscription d'actions au guichet (AGENT DE GUICHET) pour une personne présente : téléphone et code SMS, compte,
 * identité validée sur place, souscription, encaissement, reçu. La démarche en cours est gardée en session
 * (« guichet_souscription ») : téléphone, numéro confirmé, compte, souscription.
 */
class GuichetSouscriptionController extends Controller
{
    use ValideTelephone;

    private const SESSION = 'guichet_souscription';

    public function __construct(private readonly SouscriptionGuichet $guichet, private readonly VerificationIdentite $verification) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $etat = $request->session()->get(self::SESSION, []);
        $citoyen = isset($etat['user_id']) ? User::query()->find($etat['user_id']) : null;
        $souscription = isset($etat['souscription']) ? Souscription::query()->with('tranche')->where('reference', $etat['souscription'])->first() : null;
        $tranche = PreparationSouscription::trancheOuverte();

        $etape = match (true) {
            empty($etat['verifie']) => 'telephone',
            ! $citoyen => 'compte',
            $souscription && Recu::disponible($souscription) => 'recu',
            $souscription && $souscription->statut === StatutSouscription::Reservee => 'paiement',
            $citoyen->statut_kyc !== StatutKyc::Valide => 'identite',
            default => 'souscription',
        };

        return view('admin.guichet.souscription', [
            'etape' => $etape,
            'telephone' => $etat['telephone'] ?? null,
            'codeEnvoye' => isset($etat['telephone']) && empty($etat['verifie']),
            'existant' => $etat['existant'] ?? null,
            'citoyen' => $citoyen,
            'souscription' => $souscription,
            'tranche' => $tranche,
            'aFournir' => $citoyen && $etape === 'identite' ? $this->verification->aFournir($citoyen, TypeDocumentKyc::Cnib) : [],
            'typePiece' => $citoyen ? $this->verification->typePiece($citoyen) : null,
            'moyens' => $souscription && $etape === 'paiement' ? $this->guichet->moyens($souscription) : [],
            'deja' => $citoyen && $tranche ? app(PreparationSouscription::class)->dejaEngage($tranche, $citoyen) : null,
            'engagements' => PreparationSouscription::ENGAGEMENTS,
            'pays' => config('raaga.inscription.pays'),
            'regions' => config('raaga.inscription.regions'),
        ]);
    }

    public function telephone(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $telephone = $this->telephoneValide($request);
        $existant = $this->guichet->personne($telephone);

        try {
            $this->guichet->envoyerCode($telephone);
        } catch (OtpException $e) {
            return back()->withInput()->withErrors(['telephone' => $e->getMessage()]);
        }
        $request->session()->put(self::SESSION, ['telephone' => $telephone, 'existant' => $existant?->name]);

        return redirect()->route('admin.guichet-souscription');
    }

    public function renvoyer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $telephone = $request->session()->get(self::SESSION.'.telephone') ?? abort(409);

        try {
            $this->guichet->envoyerCode($telephone);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back();
    }

    public function code(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $telephone = $request->session()->get(self::SESSION.'.telephone');
        if (! $telephone) {
            return $this->expiree($request);
        }
        $code = $this->codeValide($request);

        try {
            $valide = $this->guichet->verifierCode($telephone, $code);
        } catch (OtpException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }
        if (! $valide) {
            return back()->withErrors(['code' => __('admin.guichet.souscription.erreurs.code')]);
        }

        $citoyen = $this->guichet->personne($telephone);
        $request->session()->put(self::SESSION.'.verifie', true);
        $request->session()->put(self::SESSION.'.user_id', $citoyen?->id);

        return redirect()->route('admin.guichet-souscription');
    }

    public function compte(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $etat = $request->session()->get(self::SESSION, []);
        if (empty($etat['verifie']) || ! empty($etat['user_id'])) {
            return $this->expiree($request);
        }
        $donnees = $request->validate(CreationCompte::regles(), CreationCompte::messages());
        $citoyen = $this->guichet->creerCompte($request->user(), $etat['telephone'], $donnees, $request->ip(), $request->userAgent());
        $request->session()->put(self::SESSION.'.user_id', $citoyen->id);

        return redirect()->route('admin.guichet-souscription');
    }

    public function identite(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $citoyen = $this->citoyen($request);
        $fichier = ['nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:5120'];
        $document = ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:5120'];
        $e = __('public.verification.erreurs');
        $donnees = $request->validate([
            'type_piece' => ['nullable', Rule::in(array_map(fn (TypeDocumentKyc $t) => $t->value, TypeDocumentKyc::piecesIdentite()))],
            'numero_piece' => ['nullable', 'string', 'min:5', 'max:40'],
            'piece_expire_le' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'numero_rccm' => ['nullable', 'string', 'min:4', 'max:60'],
            'numero_ifu' => ['nullable', 'string', 'min:5', 'max:40'],
            'recto' => $fichier, 'verso' => $fichier, 'selfie' => $fichier,
            'rccm' => $document, 'ifu' => $document, 'pouvoir' => $document,
            'piece_conforme' => ['accepted'],
        ], [...$e['fichiers'], 'type_piece.*' => $e['type'], 'piece_expire_le.*' => $e['expiration'], 'numero_piece.*' => $e['numero_piece'],
            'numero_rccm.*' => $e['numero_rccm'], 'numero_ifu.*' => $e['numero_ifu'],
            'piece_conforme.accepted' => __('admin.guichet.souscription.erreurs.piece_conforme')], __('public.verification.attributs'));

        $this->guichet->validerIdentite(
            $request->user(), $citoyen,
            isset($donnees['type_piece']) ? TypeDocumentKyc::from($donnees['type_piece']) : null,
            array_filter($request->only(['recto', 'verso', 'selfie', 'rccm', 'ifu', 'pouvoir'])),
            array_intersect_key($donnees, array_flip(['numero_piece', 'piece_expire_le', 'numero_rccm', 'numero_ifu'])),
        );

        return redirect()->route('admin.guichet-souscription');
    }

    public function souscrire(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $citoyen = $this->citoyen($request);
        $donnees = $request->validate([
            'nb_actions' => ['required', 'integer', 'min:1', 'max:100000000'],
            'engagements' => ['array'],
            'engagements.*' => [Rule::enum(DocumentAccepte::class)],
            'justificatif' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [], __('public.souscription.attributs'));

        $souscription = $this->guichet->souscrire($request->user(), $citoyen, (int) $donnees['nb_actions'], $donnees['engagements'] ?? [],
            $request->file('justificatif'), (string) $request->ip(), (string) $request->userAgent());
        $request->session()->put(self::SESSION.'.souscription', $souscription->reference);

        return redirect()->route('admin.guichet-souscription');
    }

    public function encaisser(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $reference = $request->session()->get(self::SESSION.'.souscription') ?? abort(409);
        $souscription = Souscription::query()->with('tranche')->where('reference', $reference)->firstOrFail();
        $donnees = $request->validate([
            'moyen' => ['required', 'string', 'max:40'],
            'reference' => ['nullable', 'string', 'max:80'],
            'montant_recu' => ['accepted'],
        ], ['moyen.*' => __('admin.guichet.souscription.erreurs.moyen'), 'montant_recu.accepted' => __('admin.guichet.souscription.erreurs.montant_recu')]);

        $this->guichet->encaisser($request->user(), $souscription, $donnees['moyen'], $donnees['reference'] ?? null);

        return redirect()->route('admin.guichet-souscription')->avecSucces('admin.guichet.souscription.encaisse');
    }

    /** Reçu d'une souscription encaissée au guichet (même document que le reçu en ligne). */
    public function recu(string $reference, GenerateurPdf $pdf): Response
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $souscription = Souscription::query()->where('reference', $reference)->where('canal', CanalSouscription::Guichet)->firstOrFail();
        abort_unless(Recu::disponible($souscription), 404);
        $paiement = Recu::paiement($souscription) ?? abort(404);
        $numero = Recu::numero($paiement);

        return response($pdf->rendre('documents.recu-souscription', [
            'souscription' => $souscription->load(['tranche', 'user']),
            'paiement' => $paiement,
            'numero' => $numero,
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recu-'.$numero.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Termine la démarche (nouvelle personne ou abandon) ; une souscription non encaissée expire dans son délai. */
    public function terminer(Request $request): RedirectResponse
    {
        Gate::authorize(Droit::GuichetSouscrire->value);
        $request->session()->forget(self::SESSION);

        return redirect()->route('admin.guichet-souscription');
    }

    private function citoyen(Request $request): User
    {
        $etat = $request->session()->get(self::SESSION, []);
        abort_if(empty($etat['verifie']) || empty($etat['user_id']), 409);

        return User::query()->findOrFail($etat['user_id']);
    }

    private function expiree(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return redirect()->route('admin.guichet-souscription')->withErrors(['telephone' => __('admin.guichet.souscription.erreurs.session')]);
    }
}
