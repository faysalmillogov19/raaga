<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Droit;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Diapositives;
use App\Support\Plateforme\Catalogue;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * « Paramètres de la plateforme » : informations publiques lues par le site, les SMS, les e-mails
 * et les documents. SUPER_ADMIN : toutes les sections ; ADMIN : contacts, siège, réseaux, messages.
 * Le droit de la section est vérifié côté serveur à chaque écriture.
 */
class PlateformeController extends Controller
{
    /** Disque privé des fichiers déposés (servis par une route qui fixe les en-têtes de sécurité). */
    public const DISQUE = 'local';

    public function __construct(private readonly ParametresPlateforme $parametres) {}

    public function index(Request $request): View
    {
        Gate::authorize(Droit::PlateformeVoir->value);

        $section = Catalogue::existe((string) $request->query('section')) ? $request->query('section') : 'identite';
        $onglet = $request->query('onglet') === 'historique' ? 'historique' : 'parametres';
        $ligne = DB::table('parametres_plateforme')->where('section', $section)->first();

        return view('admin.plateforme.index', [
            'section' => $section,
            'onglet' => $onglet,
            'valeurs' => $this->parametres->section($section),
            'modifiable' => $request->user()->can(Catalogue::droitModification($section)->value),
            'peutRestaurer' => $request->user()->can(Droit::PlateformeModifierTout->value),
            // Valeurs initiales (installation) : pas d'auteur, donc pas de « dernière modification ».
            'derniere' => $ligne?->modifie_par ? ['nom' => User::find($ligne->modifie_par)?->name, 'date' => $ligne->updated_at] : null,
            'versions' => $onglet === 'historique' ? $this->versions($section) : collect(),
        ]);
    }

    public function update(Request $request, string $section): RedirectResponse
    {
        $this->autoriserModification($request, $section);

        $actuelles = $this->parametres->section($section);
        $valeurs = $this->valider($request, $section, $actuelles);

        // Changement d'une information affichée partout : confirmation explicite exigée.
        $sensibles = array_keys(array_intersect_key(ParametresPlateforme::differentiel($actuelles, $valeurs), array_flip(Catalogue::SENSIBLES)));
        if ($sensibles !== [] && ! $request->boolean('confirmation_sensible')) {
            return back()->withInput()->withErrors(['confirmation_sensible' => __('admin.plateforme.sensible.requise', [
                'champs' => implode(', ', array_map(fn ($c) => '« '.__('admin.plateforme.champs.'.$c).' »', $sensibles)),
            ])]);
        }

        // Images (armoiries, logos) : un fichier déposé remplace l'image en vigueur.
        foreach (Catalogue::champs()[$section] as $champ => [$type]) {
            if ($type === 'image' && $request->hasFile($champ)) {
                $valeurs[$champ] = $this->deposerImage($request->file($champ), $champ);
            }
        }

        $titre = __('admin.plateforme.sections.'.$section);

        return redirect()->route('admin.plateforme', ['section' => $section])->with('succes', $this->parametres->enregistrer($section, $valeurs, $request->user())
            ? __('admin.plateforme.enregistre', ['section' => $titre])
            : __('admin.plateforme.aucun_changement'));
    }

    /**
     * Rendu des valeurs saisies dans le style du site public, sans rien enregistrer.
     */
    public function apercu(Request $request, string $section): Response
    {
        $this->autoriserModification($request, $section);

        // Valeurs d'aperçu limitées au rendu de cette page : jamais visibles d'une autre requête.
        $this->parametres->apercu($section, $this->valider($request, $section, $this->parametres->section($section)));
        try {
            $html = view('admin.plateforme.apercu', ['section' => $section])->render();
        } finally {
            $this->parametres->finApercu();
        }

        return response($html);
    }

    public function restaurer(Request $request, int $version): RedirectResponse
    {
        Gate::authorize(Droit::PlateformeModifierTout->value);

        $section = DB::table('parametres_plateforme_versions')->where('id', $version)->value('section') ?? abort(404);

        return redirect()->route('admin.plateforme', ['section' => $section, 'onglet' => 'historique'])
            ->with('succes', $this->parametres->restaurer($version, $request->user())
                ? __('admin.plateforme.historique.restauree')
                : __('admin.plateforme.historique.deja'));
    }

    private function autoriserModification(Request $request, string $section): void
    {
        abort_unless(Catalogue::existe($section), 404);
        Gate::authorize(Catalogue::droitModification($section)->value);
    }

    /**
     * Validation serveur (messages en français sous chaque champ), puis normalisation.
     *
     * @param  array<string, mixed>  $actuelles
     * @return array<string, mixed>
     */
    private function valider(Request $request, string $section, array $actuelles): array
    {
        $e = fn (string $cle) => __('admin.plateforme.erreurs.'.$cle);
        $messages = [];
        foreach (Catalogue::champs()[$section] as $champ => [$type]) {
            $messages += match ($type) {
                'url' => ["{$champ}.url" => $e('https'), "{$champ}.regex" => $e('https')],
                'texte', 'texte_long' => ["{$champ}.not_regex" => $e('promesse')],
                'tel_bf' => ["{$champ}.regex" => $e('tel_bf')],
                'tel_intl' => ["{$champ}.regex" => $e('tel_intl')],
                'expediteur' => ["{$champ}.regex" => $e('expediteur')],
                'image' => ["{$champ}.*" => $e('armoiries')],
                'carrousel' => [
                    "{$champ}.*.fichier.*" => $e('photo'), "{$champ}.*.image.required_without" => $e('diapositive_image'),
                    "{$champ}.*.boutonLien.regex" => $e('lien_bouton'), "{$champ}.*.fin.after_or_equal" => $e('fin_apres_debut'),
                    "{$champ}.max" => $e('diapositives_max'),
                    "{$champ}.*.titre.not_regex" => $e('promesse'), "{$champ}.*.sousTitre.not_regex" => $e('promesse'), "{$champ}.*.boutonLibelle.not_regex" => $e('promesse'),
                ],
                'reseaux', 'liens' => ["{$champ}.*.url.url" => $e('https'), "{$champ}.*.url.regex" => $e('https')],
                'bandeau' => ["{$champ}.lien.url" => $e('https'), "{$champ}.lien.regex" => $e('https'), "{$champ}.fin.after" => $e('fin_apres_debut'), "{$champ}.texte.not_regex" => $e('promesse')],
                'horaires' => ["{$champ}.fin.after" => $e('fin_apres_debut')],
                'maintenance' => ["{$champ}.message.required_if_accepted" => $e('maintenance_message'), "{$champ}.message.not_regex" => $e('promesse')],
                default => [],
            };
        }

        $validateur = Validator::make($request->all(), Catalogue::regles($section), $messages, $this->attributs($section));

        // Carrousel d'accueil : 5 diapositives actives au plus.
        if ($section === 'site_public') {
            $validateur->after(function ($v) use ($request) {
                $actives = collect((array) $request->input('carrousel', []))->filter(fn ($d) => filter_var($d['active'] ?? false, FILTER_VALIDATE_BOOL))->count();
                if ($actives > Diapositives::MAX_ACTIVES) {
                    $v->errors()->add('carrousel', __('admin.plateforme.erreurs.actives_max', ['max' => Diapositives::MAX_ACTIVES]));
                }
            });
        }

        // Chiffres du projet : le total de la répartition doit égaler le coût total.
        if ($section === 'projet') {
            $validateur->after(function ($v) use ($request) {
                $lignes = (array) $request->input('repartitionFinancement', []);
                if ($lignes === [] || $v->errors()->hasAny(['coutTotalProjet', 'repartitionFinancement.*'])) {
                    return;
                }
                $cout = $request->input('coutTotalProjet');
                $total = array_sum(array_map(fn ($l) => (int) ($l['montant'] ?? 0), $lignes));
                if ($cout === null || $cout === '') {
                    $v->errors()->add('repartitionFinancement', __('admin.plateforme.erreurs.financement_cout'));
                } elseif ($total !== (int) $cout) {
                    $v->errors()->add('repartitionFinancement', __('admin.plateforme.erreurs.financement_total', [
                        'total' => format_fcfa($total), 'cout' => format_fcfa((int) $cout),
                    ]));
                }
            });
        }

        return Catalogue::normaliser($section, $validateur->validate(), $actuelles);
    }

    /**
     * Noms des champs dans les messages d'erreur (« Le champ Numéro vert… »).
     *
     * @return array<string, string>
     */
    private function attributs(string $section): array
    {
        $attributs = [];
        foreach (array_keys(Catalogue::regles($section)) as $cle) {
            $parties = explode('.', $cle);
            $libelle = __('admin.plateforme.champs.'.$parties[0]);
            $sous = end($parties);
            if (count($parties) > 1 && $sous !== '*') {
                $libelle .= ' — '.mb_strtolower(__('admin.plateforme.sous_champs.'.$sous));
            }
            $attributs[$cle] = $libelle;
        }

        return $attributs;
    }

    private function deposerImage(UploadedFile $fichier, string $champ): string
    {
        $extension = strtolower($fichier->getClientOriginalExtension()) === 'svg' ? 'svg' : 'png';

        return Storage::disk(self::DISQUE)->putFileAs('plateforme', $fichier, Str::kebab($champ).'-'.Str::uuid().'.'.$extension);
    }

    /**
     * Versions de la section, de la plus récente à la plus ancienne, avec leur différentiel.
     */
    private function versions(string $section)
    {
        $versions = DB::table('parametres_plateforme_versions')->where('section', $section)->orderByDesc('id')->get();
        $auteurs = User::query()->whereIn('id', $versions->pluck('auteur_id')->filter())->pluck('name', 'id');

        return $versions->map(fn ($v) => (object) [
            'id' => $v->id,
            'date' => Carbon::parse($v->created_at),
            'auteur' => $auteurs[$v->auteur_id] ?? null,
            'restauree_depuis' => $v->restauree_depuis,
            // Champs retirés du catalogue depuis la version : non affichés.
            'differentiel' => array_intersect_key(ParametresPlateforme::differentiel(
                json_decode((string) $v->ancienne_valeur, true) ?? Catalogue::vide($section),
                json_decode($v->nouvelle_valeur, true),
            ), Catalogue::champs()[$section]),
        ]);
    }
}
