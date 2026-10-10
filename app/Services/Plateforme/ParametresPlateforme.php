<?php

namespace App\Services\Plateforme;

use App\Models\User;
use App\Services\Audit\Journal;
use App\Support\Plateforme\Catalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Source unique des informations publiques de la plateforme (en-tête, pied de page, contact,
 * mentions légales, accueil, SMS, e-mails, documents). Lecture mise en cache ; le cache est
 * vidé à chaque enregistrement, si bien que le site public est à jour dès la requête suivante.
 */
class ParametresPlateforme
{
    public const CLE_CACHE = 'plateforme.parametres';

    /** Durée de vie de sécurité du cache (le site est à jour au plus tard dans la minute). */
    private const TTL_SECONDES = 60;

    /** Valeurs d'aperçu : remplacent une section le temps d'un rendu, sans rien enregistrer. */
    private array $apercu = [];

    public function __construct(private readonly Journal $journal) {}

    /**
     * Toutes les sections, complétées par les valeurs vides du catalogue.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tout(): array
    {
        $enregistrees = Cache::remember(self::CLE_CACHE, self::TTL_SECONDES, fn () => DB::table('parametres_plateforme')
            ->pluck('valeurs', 'section')
            ->map(fn (string $json) => json_decode($json, true))
            ->all());

        $valeurs = [];
        foreach (array_keys(Catalogue::SECTIONS) as $section) {
            $valeurs[$section] = array_replace(Catalogue::vide($section), $enregistrees[$section] ?? []);
        }

        return array_replace($valeurs, $this->apercu);
    }

    /**
     * Lecture par chemin : « contacts.numeroVert », « projet.repartitionFinancement »…
     */
    public function get(string $chemin, mixed $defaut = null): mixed
    {
        return data_get($this->tout(), $chemin, $defaut);
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $section): array
    {
        return $this->tout()[$section];
    }

    /**
     * Enregistre une section : nouvelle version (ancienne et nouvelle valeur, auteur, date), journal
     * d'audit, cache vidé. Rien n'est écrit si aucune valeur ne change.
     *
     * @param  array<string, mixed>  $valeurs  valeurs normalisées (Catalogue::normaliser)
     */
    public function enregistrer(string $section, array $valeurs, ?User $auteur, ?int $restaureeDepuis = null): bool
    {
        $ancienne = $this->section($section);
        $nouvelle = array_replace(Catalogue::vide($section), $valeurs);

        if ($nouvelle == $ancienne) {
            return false;
        }

        DB::transaction(function () use ($section, $ancienne, $nouvelle, $auteur, $restaureeDepuis) {
            $existe = DB::table('parametres_plateforme')->where('section', $section)->exists();
            $ligne = ['valeurs' => json_encode($nouvelle, JSON_UNESCAPED_UNICODE), 'modifie_par' => $auteur?->id, 'updated_at' => now()];

            $existe
                ? DB::table('parametres_plateforme')->where('section', $section)->update($ligne)
                : DB::table('parametres_plateforme')->insert([...$ligne, 'section' => $section, 'created_at' => now()]);

            DB::table('parametres_plateforme_versions')->insert([
                'section' => $section,
                'ancienne_valeur' => $existe ? json_encode($ancienne, JSON_UNESCAPED_UNICODE) : null,
                'nouvelle_valeur' => json_encode($nouvelle, JSON_UNESCAPED_UNICODE),
                'auteur_id' => $auteur?->id,
                'restauree_depuis' => $restaureeDepuis,
                'created_at' => now(),
            ]);

            $differentiel = self::differentiel($ancienne, $nouvelle);
            $this->journal->ecrire($auteur, $restaureeDepuis ? 'plateforme.restauree' : 'plateforme.modifiee', 'ParametresPlateforme', $section,
                array_intersect_key($ancienne, $differentiel), array_intersect_key($nouvelle, $differentiel) + ($restaureeDepuis ? ['version_restauree' => $restaureeDepuis] : []));
        });

        $this->viderCache();

        return true;
    }

    /**
     * Remet en vigueur les valeurs d'une version antérieure (crée une nouvelle version).
     */
    public function restaurer(int $versionId, User $auteur): bool
    {
        $version = DB::table('parametres_plateforme_versions')->where('id', $versionId)->first() ?? abort(404);

        return $this->enregistrer($version->section, json_decode($version->nouvelle_valeur, true), $auteur, $version->id);
    }

    /**
     * Rend une section avec des valeurs non enregistrées (aperçu avant enregistrement).
     *
     * @param  array<string, mixed>  $valeurs
     */
    public function apercu(string $section, array $valeurs): void
    {
        $this->apercu[$section] = array_replace(Catalogue::vide($section), $valeurs);
    }

    public function finApercu(): void
    {
        $this->apercu = [];
    }

    public function viderCache(): void
    {
        Cache::forget(self::CLE_CACHE);
    }

    /**
     * Champs modifiés entre deux états d'une section : [champ => [avant, après]].
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function differentiel(array $avant, array $apres): array
    {
        $diff = [];
        foreach ($apres as $champ => $valeur) {
            if (($avant[$champ] ?? null) != $valeur) {
                $diff[$champ] = [$avant[$champ] ?? null, $valeur];
            }
        }

        return $diff;
    }
}
