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

    private const MARQUE = '#^\s*(@if \([^)]*\))?<span class="(adm|champ)-requis" aria-hidden="true">\*</span>#';

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
        $adm = '<span class="adm-requis" aria-hidden="true">*</span> ';
        $pub = '<span class="champ-requis" aria-hidden="true">*</span> ';

        // Back-office : composant de champ (astérisque devant) et choix multiples obligatoires.
        $this->connecterBackoffice(Role::Regulateur);
        $this->get(route('admin.boutiques.create'))->assertOk()
            ->assertSee('class="adm-libelle">'.$adm.'Nom de la campagne', false)
            ->assertSee('<legend class="formulaire-libelle">'.$adm.'Moyens de paiement de la caution', false)
            ->assertSee('<legend class="adm-libelle">'.$adm.'Types proposés', false)
            ->assertDontSee('Nom de la campagne <span class="adm-requis"', false);

        // Site public : contact (composant et champs écrits à la main) ; un champ facultatif n'a pas d'astérisque.
        $this->app['auth']->forgetGuards();
        $contact = $this->get(route('contact'))->assertOk();
        $contact->assertSee('text-encre">'.$pub.__('public.contact.formulaire.attributs.nom'), false)
            ->assertSee('<label for="objet" class="block font-semibold text-encre">'.$pub, false)
            ->assertDontSee('text-encre">'.$pub.__('public.contact.formulaire.email_facultatif'), false);
        // Inscription : le pays de résidence (étape suivante du parcours) et l'acceptation des conditions, exigés par le serveur.
        $vue = file_get_contents(resource_path('views/public/compte/inscription.blade.php'));
        $this->assertStringContainsString('<label for="pays_residence">'.$pub, $vue);
        $this->assertStringContainsString('<span>'.$pub.'{!! __(\'public.compte.inscription.conditions\'', $vue);
    }
}
