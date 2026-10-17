<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Champs obligatoires : un astérisque devant le libellé, dans tous les formulaires (back-office, site public, espace
 * institutionnel, connexion). Composants : selon « required » ou « obligatoire » ; champs écrits à la main : contrôlés
 * dans les vues.
 */
class ChampsObligatoiresTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    private const MARQUE = '#^\s*(@if \([^)]*\))?<x-requis />#';

    /** Rendu de <x-requis /> : astérisque masqué aux lecteurs d'écran, puis « obligatoire » lu seulement par eux. */
    private const REQUIS = '<span class="adm-requis champ-requis" aria-hidden="true" data-requis>*</span><span class="sr-only">obligatoire</span> ';

    public function test_chaque_libelle_d_un_champ_required_ecrit_a_la_main_porte_l_asterisque_devant(): void
    {
        $manquants = [];
        foreach (File::allFiles(resource_path('views')) as $fichier) {
            $s = $fichier->getContents();
            preg_match_all('#<label\b[^>]*\bfor="([^"]+)"[^>]*>(.*?)</label>#s', $s, $libelles, PREG_SET_ORDER);
            foreach ($libelles as [$tout, $cible, $texte]) {
                if (! preg_match('#<(?:input|select|textarea)\b[^>]*\bid="'.preg_quote($cible, '#').'"[^>]*>#s', $s, $controle)
                    || ! preg_match('#\brequired\b|@required\(\s*(true|1)#', $controle[0])) {
                    continue;
                }
                $texte = preg_replace('#^\s*<input\b[^>]*>\s*(<span>)?#s', '', $texte);
                if (! preg_match(self::MARQUE, $texte) && ! str_contains($fichier->getRelativePathname(), 'components'.DIRECTORY_SEPARATOR.'admin'.DIRECTORY_SEPARATOR.'champ')) {
                    $manquants[] = $fichier->getRelativePathname().' : '.trim(preg_replace('/\s+/', ' ', strip_tags($texte)));
                }
            }
            // Groupes (boutons radio, cases) obligatoires : astérisque devant la légende.
            preg_match_all('#<fieldset\b(?:(?!</fieldset>).)*?<legend[^>]*>(.*?)</legend>((?:(?!</fieldset>).)*)</fieldset>#s', $s, $groupes, PREG_SET_ORDER);
            foreach ($groupes as [$tout, $legende, $contenu]) {
                if (preg_match('#<input\b[^>]*type="(radio|checkbox)"[^>]*\brequired\b#s', $contenu) && ! preg_match(self::MARQUE, $legende)
                    && ! str_contains($legende, '$libelle')) {
                    $manquants[] = $fichier->getRelativePathname().' (légende) : '.trim(strip_tags($legende));
                }
            }
        }
        $this->assertSame([], $manquants);
    }

    public function test_formulaires_rendus_back_office_public_et_inscription(): void
    {
        $r = self::REQUIS;

        // Back-office : composant de champ (astérisque devant) et choix multiples obligatoires.
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.boutiques.create'))->assertOk()
            ->assertSee('class="adm-libelle">'.$r.'Nom de la campagne', false)
            ->assertSee('<legend class="formulaire-libelle">'.$r.'Moyens de paiement de la caution', false)
            ->assertSee('<legend class="adm-libelle">'.$r.'Types proposés', false)
            ->assertDontSee('Nom de la campagne <span class="adm-requis"', false);

        // Site public : contact (composant et champs écrits à la main) ; un champ facultatif n'a pas d'astérisque.
        $this->app['auth']->forgetGuards();
        $contact = $this->get(route('contact'))->assertOk();
        $contact->assertSee('text-encre">'.$r.__('public.contact.formulaire.attributs.nom'), false)
            ->assertSee('<label for="objet" class="block font-semibold text-encre">'.$r, false)
            ->assertDontSee('text-encre">'.$r.__('public.contact.formulaire.email_facultatif'), false);
        // Inscription : le pays de résidence (étape suivante du parcours) et l'acceptation des conditions, exigés par le serveur.
        $vue = file_get_contents(resource_path('views/public/compte/inscription.blade.php'));
        $this->assertStringContainsString('<label for="pays_residence"><x-requis /> ', $vue);
        $this->assertStringContainsString("<span><x-requis /> {!! __('public.compte.inscription.conditions'", $vue);
    }

    public function test_astérisque_masque_aux_lecteurs_d_ecran_avec_texte_obligatoire(): void
    {
        // Plus aucun astérisque écrit à la main : toutes les vues passent par <x-requis />.
        foreach (File::allFiles(resource_path('views')) as $fichier) {
            if (! in_array($fichier->getFilename(), ['requis.blade.php', 'mention-requis.blade.php'], true)) {
                $this->assertDoesNotMatchRegularExpression('#class="(adm|champ)-requis"#', $fichier->getContents(), $fichier->getRelativePathname());
            }
        }
        $this->connecterBackoffice(Role::Regulateur);
        $html = $this->get(route('admin.boutiques.create'))->assertOk()->getContent();
        // Chaque astérisque est aria-hidden ; celui d'un champ est suivi du texte « obligatoire » réservé aux lecteurs
        // d'écran (celui de la mention « * Champ obligatoire » n'en a pas besoin).
        preg_match_all('#<span class="adm-requis champ-requis"([^>]*)>\*</span>(<span class="sr-only">obligatoire</span>)?#', $html, $m, PREG_SET_ORDER);
        $this->assertGreaterThan(3, count($m));
        foreach ($m as $asterisque) {
            $this->assertStringContainsString('aria-hidden="true"', $asterisque[1]);
            if (str_contains($asterisque[1], 'data-requis')) {
                $this->assertNotEmpty($asterisque[2] ?? '', $asterisque[0]);
            }
        }
    }

    public function test_mention_en_haut_de_chaque_formulaire_avec_un_champ_obligatoire(): void
    {
        $mention = '<p class="mention-requis" data-mention-requis><span class="adm-requis champ-requis" aria-hidden="true">*</span> Champ obligatoire</p>';
        $verifier = function (string $html): int {
            preg_match_all('#(<form\b[^>]*>)(.*?)</form>#s', $html, $formulaires, PREG_SET_ORDER);
            $avec = 0;
            foreach ($formulaires as [$tout, $ouverture, $contenu]) {
                $requis = str_contains($contenu, 'data-requis');
                // Une seule mention, en tête du formulaire ; aucune dans un formulaire sans champ obligatoire.
                $this->assertSame($requis ? 1 : 0, substr_count($contenu, 'data-mention-requis'), $ouverture);
                if ($requis) {
                    $this->assertStringStartsWith('<p class="mention-requis"', ltrim($contenu), $ouverture);
                    $avec++;
                }
            }

            return $avec;
        };

        // Back-office : formulaire de campagne (mention), recherche globale (aucune).
        $this->connecterBackoffice(Role::Regulateur);
        $html = $this->get(route('admin.boutiques.create'))->assertOk()->assertSee($mention, false)->getContent();
        $this->assertGreaterThanOrEqual(1, $verifier($html));
        $this->assertDoesNotMatchRegularExpression('#<form[^>]*recherche[^>]*>\s*<p class="mention-requis"#', $html);

        // Site public : contact ; espace de connexion du back-office.
        $this->app['auth']->forgetGuards();
        $this->assertSame(1, $verifier($this->get(route('contact'))->assertOk()->assertSee($mention, false)->getContent()));
        $this->assertGreaterThanOrEqual(1, $verifier($this->get(route('admin.connexion'))->assertOk()->getContent()));
        // Page sans formulaire à champ obligatoire : aucune mention.
        $this->get(route('accueil'))->assertOk()->assertDontSee('data-mention-requis', false);
    }
}
