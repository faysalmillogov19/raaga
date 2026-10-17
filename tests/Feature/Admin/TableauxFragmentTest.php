<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\MenuBackoffice;
use Database\Seeders\ReferentielsSitesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Mise à jour d'un tableau (<x-tableau>) sans recharger la page : la demande porte l'en-tête X-Tableau (identifiant du
 * tableau) ; le serveur ne renvoie que le fragment des résultats, confirmé par X-Tableau-Fragment. Une réponse qui n'est
 * pas ce fragment (page complète, page de connexion après expiration de session, erreur) n'est jamais insérée : la page
 * est rechargée normalement.
 */
class TableauxFragmentTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    /** Tous les tableaux de données du back-office (identifiants passés à Tableau::pour ou ImportsController::journal). */
    private const TABLEAUX = ['boutiques', 'campagnes', 'reservations', 'capacites', 'cautions', 'finances', 'grilles', 'historique', 'interets', 'kyc',
        'remboursements', 'sites', 'types', 'utilisateurs', 'niveaux', 'zones', 'unites', 'moyens', 'paiements-souscriptions', 'paiements-cautions',
        'imports_sites', 'imports_boutiques', 'derniers-imports'];

    private const PROFILS = [Role::SuperAdmin, Role::Admin, Role::Validateur, Role::Regulateur, Role::GestionnaireSites, Role::ControleurPaiements];

    /** Pages à visiter pour un profil : son menu, le tableau de bord, la deuxième vue des paiements, la fiche d'une campagne. @return list<string> */
    private function pages(User $user, int $campagne): array
    {
        $urls = collect(MenuBackoffice::pour($user))->flatten(1)->map(fn (array $e) => MenuBackoffice::url($e))->push(route('admin.tableau'));
        if ($user->can('paiements.voir')) {
            $urls->push(route('admin.paiements', ['vue' => 'cautions']));
        }
        if ($user->can('boutiques.voir')) {
            $urls->push(route('admin.boutiques.show', $campagne));
        }

        return $urls->unique()->values()->all();
    }

    public function test_chaque_liste_de_chaque_profil_ne_renvoie_que_le_fragment(): void
    {
        $this->seed(ReferentielsSitesSeeder::class);
        $campagne = Boutiques::campagneOuverte()->id;
        $couverts = [];

        foreach (self::PROFILS as $role) {
            $user = $this->connecterBackoffice($role);
            foreach ($this->pages($user, $campagne) as $url) {
                $page = $this->get($url);
                if (! $page->isOk() || ! preg_match_all('#data-tableau="([^"]+)"#', $page->getContent(), $m)) {
                    continue;
                }
                // Page complète : elle varie selon X-Tableau (aucun cache ne confond page et fragment).
                $this->assertStringContainsString('X-Tableau', (string) $page->headers->get('Vary'), $url);
                foreach (array_unique($m[1]) as $id) {
                    $fragment = $this->withHeaders(['X-Tableau' => $id, 'X-Requested-With' => 'XMLHttpRequest'])->get($url)->assertOk();
                    $html = $fragment->getContent();
                    $contexte = $role->value.' '.$url.' ['.$id.']';
                    $this->assertSame($id, $fragment->headers->get('X-Tableau-Fragment'), $contexte);
                    $this->assertStringStartsWith('<div data-tableau-resultats', ltrim($html), $contexte);
                    // Ni le menu, ni l'en-tête, ni le titre de la page.
                    foreach (['data-layout="admin"', 'class="adm-lateral"', 'class="adm-haut"', 'class="adm-nav"', '<h1', '<html', 'id="recherche-globale"'] as $morceau) {
                        $this->assertStringNotContainsString($morceau, $html, $contexte.' : '.$morceau);
                    }
                    $couverts[$id][] = $role->value;
                }
                $this->withHeaders(['X-Tableau' => '', 'X-Requested-With' => '']);
            }
        }

        // Inventaire complet : chaque tableau du back-office a été vérifié pour au moins un profil.
        $this->assertSame(collect(self::TABLEAUX)->sort()->values()->all(), collect(array_keys($couverts))->sort()->values()->all());
    }

    public function test_un_autre_identifiant_ou_aucun_en_tete_donne_la_page_complete_sans_confirmation(): void
    {
        $this->connecterBackoffice(Role::GestionnaireSites);
        foreach ([null, 'undefined', 'sites'] as $entete) {
            $reponse = $this->withHeaders($entete === null ? [] : ['X-Tableau' => $entete])->get(route('admin.referencement.boutiques'))->assertOk();
            $this->assertNull($reponse->headers->get('X-Tableau-Fragment'));
            $this->assertStringContainsString('data-layout="admin"', $reponse->getContent());
        }
    }

    public function test_session_expiree_la_page_de_connexion_n_est_jamais_un_fragment(): void
    {
        // Non connecté : redirection vers la connexion ; la page de connexion n'est pas un fragment confirmé.
        $this->withHeaders(['X-Tableau' => 'boutiques', 'X-Requested-With' => 'XMLHttpRequest']);
        $reponse = $this->get(route('admin.referencement.boutiques'));
        $this->assertNull($reponse->headers->get('X-Tableau-Fragment'));
        $this->assertNotSame(200, $reponse->status());
        $connexion = $this->followingRedirects()->get(route('admin.referencement.boutiques'));
        $this->assertNull($connexion->headers->get('X-Tableau-Fragment'));
        $this->assertStringNotContainsString('data-tableau-resultats', $connexion->getContent());

        // Session du back-office expirée par inactivité : même chose.
        $this->connecterBackoffice(Role::GestionnaireSites);
        $this->withSession(['backoffice' => ['totp_ok' => true, 'debut' => now()->timestamp, 'activite' => now()->subDay()->timestamp]]);
        $expiree = $this->withHeaders(['X-Tableau' => 'boutiques'])->get(route('admin.referencement.boutiques'));
        $expiree->assertRedirect();
        $this->assertNull($expiree->headers->get('X-Tableau-Fragment'));
    }

    public function test_le_client_n_insere_que_le_fragment_confirme(): void
    {
        $js = file_get_contents(public_path('js/app.js'));
        $tableau = substr($js, strpos($js, "composant('tableau'"), 4000);
        // Identifiant lu sur la racine du composant (this.$el est l'élément d'où part l'événement : lien, liste, formulaire).
        $this->assertStringContainsString('return this.$root;', $tableau);
        $this->assertStringContainsString("'X-Tableau': id", $tableau);
        $this->assertStringNotContainsString('this.$el.dataset', $js);
        // Insertion seulement si la réponse est le fragment confirmé de ce tableau ; sinon rechargement de la page.
        $this->assertStringContainsString("reponse.headers.get('X-Tableau-Fragment') !== id", $tableau);
        $this->assertStringContainsString('reponse.redirected', $tableau);
        $this->assertStringContainsString("startsWith('<div data-tableau-resultats')", $tableau);
        $this->assertLessThan(strpos($tableau, 'this.$refs.resultats.innerHTML = html'), strpos($tableau, 'window.location.href = url'));
    }
}
