<?php

namespace App\Http\Controllers\Publique;

use App\Http\Controllers\Controller;
use App\Services\Publique\CollectePublique;
use App\Support\Phase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __construct(private readonly CollectePublique $collecte) {}

    public function offre(): View
    {
        $etat = $this->collecte->etat();
        $maintenant = CarbonImmutable::now();

        // Frise : une étape est « faite » quand sa date est passée, « en cours » pour la première non faite.
        $etapes = [
            ['cle' => 'ouverture', 'date' => $etat?->dateOuverture],
            ['cle' => 'cloture', 'date' => $etat?->dateCloture],
            ['cle' => 'allocation', 'date' => null, 'quand' => __('public.offre.calendrier.apres_cloture')],
            ['cle' => 'attestations', 'date' => null, 'quand' => __('public.offre.calendrier.apres_allocation')],
            ['cle' => 'marche', 'date' => null, 'quand' => __('public.offre.calendrier.a_annoncer')],
        ];

        $enCoursTrouve = false;
        foreach ($etapes as &$etape) {
            $faite = $etape['date'] !== null && $etape['date']->lessThanOrEqualTo($maintenant);
            $etape['statut'] = $faite ? 'fait' : ($enCoursTrouve ? 'a_venir' : 'en_cours');
            $etape['titre'] = __('public.offre.calendrier.etapes.'.$etape['cle']);
            $etape['detail'] = $etape['date']?->translatedFormat('j F Y') ?? $etape['quand'] ?? null;
            $enCoursTrouve = $enCoursTrouve || ! $faite;
        }
        unset($etape);

        return view('public.offre', [
            'etat' => $etat,
            'etapes' => $etapes,
            'documents' => $this->documents(),
            'urlSouscrire' => $this->urlSouscrire(),
        ]);
    }

    public function faq(): View
    {
        $groupes = __('public.faq.groupes');

        // Phase 1 : boutiques et cautions uniquement ; phase 2 : questions sur les actions.
        return view('public.faq', [
            'groupes' => Phase::presentationActions()
                ? array_diff_key($groupes, ['boutiques' => true])
                : array_intersect_key($groupes, array_flip(['boutiques', 'securite'])),
        ]);
    }

    public function transparence(): View
    {
        return view('public.transparence', [
            'etat' => $this->collecte->etat(),
            'statistiques' => $this->collecte->statistiques(),
            'chantier' => $this->collecte->chantier(),
            'rapports' => collect(config('raaga.rapports'))
                ->map(fn (array $r) => $r + ['url' => $this->urlFichier($r['fichier'] ?? null)])
                ->all(),
        ]);
    }

    public function actualite(string $slug): View
    {
        $actualite = $this->collecte->actualite($slug) ?? abort(404);

        return view('public.actualite', [
            'actualite' => $actualite,
            'autres' => collect($this->collecte->actualites())->where('slug', '!=', $slug)->values()->all(),
        ]);
    }

    /**
     * Pages du tunnel de souscription pas encore mises en service.
     */
    public function preparation(Request $request): View
    {
        $page = $request->route()->defaults['page'];

        return view('public.preparation', [
            'page' => $page,
            'titre' => __("public.preparation.pages.{$page}.titre"),
            'intro' => __("public.preparation.pages.{$page}.intro"),
        ]);
    }

    public function legal(Request $request): View
    {
        $page = $request->route()->defaults['page'];

        return view('public.legal', [
            'page' => $page,
            'titre' => __('public.legal.pages.'.$page),
        ]);
    }

    /**
     * @return list<array{cle: string, url: ?string}>
     */
    private function documents(): array
    {
        return collect(config('raaga.documents'))
            ->map(fn (?string $fichier, string $cle) => ['cle' => $cle, 'url' => $this->urlFichier($fichier)])
            ->values()
            ->all();
    }

    /**
     * URL publique du fichier s'il existe dans public/, sinon null (« bientôt disponible »).
     */
    private function urlFichier(?string $fichier): ?string
    {
        return $fichier && is_file(public_path($fichier)) ? asset($fichier) : null;
    }

    /** Souscription ouverte : souscription (ou création de compte) ; sinon, alerte SMS « bientôt ouverte ». */
    private function urlSouscrire(): string
    {
        return Phase::lienSouscription();
    }
}
