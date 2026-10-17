<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\ActeursParametrage;
use Tests\TestCase;

/**
 * Notifications en popup (succès, information, alerte, erreur, erreurs de formulaire) et boutons Retour / Suivant à côté
 * du fil d'Ariane, dans le back-office, le site public et l'espace institutionnel.
 */
class NotificationsNavigationTest extends TestCase
{
    use ActeursParametrage, RefreshDatabase;

    /** Popups de la page : type => [titre, texte, fermeture automatique]. @return array<string, array{0: string, 1: string, 2: bool}> */
    private function popups(string $html): array
    {
        preg_match_all('#<dialog class="notification notification-(\w+)" role="alertdialog" aria-modal="true"(.*?)</dialog>#s', $html, $m, PREG_SET_ORDER);
        $popups = [];
        foreach ($m as [$tout, $type, $corps]) {
            preg_match('#class="notification-titre">([^<]*)</h2>#', $corps, $titre);
            preg_match('#class="notification-texte">([^<]*)</p>#', $corps, $texte);
            $this->assertStringContainsString('data-notification-fermer>Compris</button>', $corps);
            $popups[$type] = [html_entity_decode($titre[1]), html_entity_decode($texte[1]), str_contains($corps, 'data-fermeture-auto="4000"')];
        }

        return $popups;
    }

    public function test_chaque_type_de_message_dans_la_popup_avec_titre_et_comportement(): void
    {
        $this->creerActeurs();
        $page = $this->en($this->regulateur)->withSession(['succes' => 'Grille enregistrée.', 'statut' => 'Session prolongée.',
            'avertissement' => 'Délai bientôt dépassé.', 'erreur' => 'Action impossible.'])->get(route('admin.tableau'))->assertOk()->getContent();

        $this->assertSame([
            'succes' => ['Enregistré', 'Grille enregistrée.', true],
            'information' => ['Information', 'Session prolongée.', true],
            'alerte' => ['Attention', 'Délai bientôt dépassé.', false],
            'erreur' => ['Erreur', 'Action impossible.', false],
        ], $this->popups($page));
        // Accessibilité : alertdialog titré et décrit.
        $this->assertMatchesRegularExpression('#role="alertdialog" aria-modal="true"\s+aria-labelledby="notification-0-titre" aria-describedby="notification-0-texte"#', $page);
        // Plus aucune ancienne bannière dans la page.
        $this->assertStringNotContainsString('adm-message adm-message-ok', $page);
        $this->assertStringNotContainsString('adm-message adm-message-erreur', $page);
    }

    public function test_erreurs_de_formulaire_resumees_et_gardees_a_cote_des_champs(): void
    {
        $this->creerActeurs();
        $page = $this->en($this->regulateur)->from(route('admin.boutiques.create'))->followingRedirects()
            ->post(route('admin.boutiques.store'), [])->assertOk()->getContent();
        $popups = $this->popups($page);
        $this->assertSame('Formulaire à corriger', $popups['erreur'][0]);
        $this->assertMatchesRegularExpression('#^\d+ champs à corriger : voir les messages sous les champs\.$#', $popups['erreur'][1]);
        $this->assertFalse($popups['erreur'][2]);
        // Les messages restent sous les champs.
        $this->assertStringContainsString('id="nom-erreur" class="message-erreur" role="alert"', $page);
    }

    public function test_popups_sur_le_site_public_et_page_de_contact(): void
    {
        $page = $this->withSession(['contact.numero' => 'DC-2026-000123'])->get(route('contact'))->assertOk()->getContent();
        $popups = $this->popups($page);
        $this->assertSame(__('public.contact.formulaire.envoye_titre'), $popups['succes'][0]);
        $this->assertStringContainsString('DC-2026-000123', $popups['succes'][1]);
        $this->assertTrue($popups['succes'][2]);
        // Sans message : aucune popup.
        $this->flushSession();
        $this->assertSame([], $this->popups($this->get(route('accueil'))->assertOk()->getContent()));
    }

    public function test_plus_aucune_ancienne_banniere_de_message_flash(): void
    {
        foreach (File::allFiles(resource_path('views')) as $fichier) {
            if ($fichier->getFilename() === 'notification.blade.php') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression("#session\\('(succes|erreur|statut|avertissement|info|contact\\.numero|souscription\\.reservee)'\\)#",
                $fichier->getContents(), $fichier->getRelativePathname());
        }
    }

    public function test_boutons_retour_et_suivant_dans_chaque_espace(): void
    {
        $this->creerActeurs();
        $boutons = function (string $html, string $parent): void {
            $this->assertSame(1, substr_count($html, 'data-navigation-historique'));
            $this->assertStringContainsString('data-historique-retour data-parent="'.e($parent).'" title="Retour"', $html);
            $this->assertStringContainsString('<span class="sr-only">Retour à la page précédente</span>', $html);
            $this->assertStringContainsString('data-historique-suivant title="Suivant"', $html);
            $this->assertStringContainsString('<span class="sr-only">Aller à la page suivante</span>', $html);
            // Jamais dans une popup.
            $this->assertDoesNotMatchRegularExpression('#<dialog[^>]*data-notification(?:(?!</dialog>).)*data-navigation-historique#s', $html);
        };

        // Back-office : parent = dernier lien du fil d'Ariane (ici la liste des campagnes).
        $boutons($this->en($this->regulateur)->withSession(['succes' => 'Ok.'])->get(route('admin.boutiques.create'))->assertOk()->getContent(), route('admin.boutiques'));
        $boutons($this->en($this->regulateur)->get(route('admin.tableau'))->assertOk()->getContent(), route('admin.tableau'));
        // Connexion au back-office.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $boutons($this->get(route('admin.connexion'))->assertOk()->getContent(), url('/'));
        // Site public : page avec fil (contact) et sans fil (accueil).
        $boutons($this->get(route('contact'))->assertOk()->getContent(), route('accueil'));
        $boutons($this->get(route('accueil'))->assertOk()->getContent(), route('accueil'));

        // Espace institutionnel.
        $this->en($this->superAdmin)->post(route('admin.institutions.store'), ['denomination' => 'Caisse nationale', 'sigle' => 'CN', 'rccm' => 'BF-OUA-2000-B-1',
            'ifu' => '000111X', 'signataire_nom' => 'Directeur général', 'signataire_email' => 'dg@caisse.test'])->assertSessionHas('succes');
        $signataire = User::query()->where('email', 'dg@caisse.test')->firstOrFail();
        // Double authentification déjà activée (sinon : redirection vers l'enrôlement).
        $signataire->forceFill(['totp_secret' => str_repeat('A', 32), 'totp_active_le' => now()])->save();
        $this->app['auth']->forgetGuards();
        $espace = $this->actingAs($signataire)->withSession(['institutionnel' => ['totp_ok' => true, 'debut' => now()->timestamp, 'activite' => now()->timestamp]]);
        $boutons($espace->get(route('institutionnel.tableau'))->assertOk()->getContent(), route('institutionnel.tableau'));
    }

    public function test_absents_des_pdf_et_de_l_impression_comportement_client(): void
    {
        // PDF et courriels : jamais de boutons ni de popup.
        foreach (['components/document-pdf.blade.php', 'vendor/mail/html/layout.blade.php'] as $vue) {
            $contenu = File::get(resource_path('views/'.$vue));
            $this->assertStringNotContainsString('x-navigation-historique', $contenu);
            $this->assertStringNotContainsString('x-notification', $contenu);
        }
        foreach (File::allFiles(resource_path('views/documents')) as $fichier) {
            $this->assertStringNotContainsString('navigation-historique', $fichier->getContents());
        }
        // Impression : masqués.
        $css = File::get(public_path('css/raaga.css'));
        $this->assertStringContainsString("@media print {\n    .nav-historique,\n    .notification {\n        display: none !important;", $css);

        // Comportement côté navigateur.
        $js = File::get(public_path('js/app.js'));
        $this->assertStringContainsString("dialogue.showModal();\n            dialogue.querySelector('[data-notification-fermer]')?.focus();", $js);
        $this->assertStringContainsString('setTimeout(() => dialogue.close(), delai)', $js);
        $this->assertStringContainsString('window.location.href = bouton.dataset.parent;', $js);
        $this->assertStringContainsString("const possible = !(api && 'canGoForward' in api) || api.canGoForward;", $js);
        $this->assertStringContainsString('window.history.forward()', $js);
    }
}
