<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Listes paginées : les colonnes de la liste des boutiques gardent la même largeur sur toutes les pages (mise en page
 * fixe), quel que soit le contenu de la page ; les listes sans largeurs déclarées gardent la mise en page automatique.
 */
class TableauLargeursTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    /** Largeurs déclarées dans le <colgroup> du tableau de données. @return list<string> */
    private function largeurs(string $html): array
    {
        preg_match('#<colgroup>(.*?)</colgroup>#s', $html, $groupe);
        preg_match_all('#<col style="width: ([^"]+)">#', $groupe[1] ?? '', $m);

        return $m[1];
    }

    public function test_memes_largeurs_de_colonnes_sur_chaque_page_des_boutiques(): void
    {
        // 12 petites boutiques et une grande : deux pages de 10 lignes, des contenus différents d'une page à l'autre.
        Boutiques::campagneOuverte([], 12);
        $this->connecterBackoffice(Role::GestionnaireSites);

        $page1 = $this->get(route('admin.referencement.boutiques', ['tri' => 'code']))->assertOk()->getContent();
        $page2 = $this->get(route('admin.referencement.boutiques', ['tri' => 'code', 'page' => 2]))->assertOk()->getContent();
        foreach ([$page1, $page2] as $html) {
            $this->assertStringContainsString('class="bo-tableau bo-tableau-donnees bo-tableau-fixe"', $html);
        }
        $this->assertCount(9, $this->largeurs($page1));
        $this->assertSame($this->largeurs($page1), $this->largeurs($page2));
        $this->assertSame(100, array_sum(array_map('intval', $this->largeurs($page1))));

        // Liste sans largeurs déclarées (sites) : mise en page automatique, pas de <colgroup>.
        $sites = $this->get(route('admin.sites'))->assertOk()->getContent();
        $this->assertStringNotContainsString('bo-tableau-fixe', $sites);
        $this->assertStringNotContainsString('<colgroup>', $sites);
    }

    public function test_styles_de_stabilite(): void
    {
        $css = file_get_contents(public_path('css/raaga.css'));
        // Mise en page fixe, largeur minimale au-dessus de la règle du back-office (défilement dans le cadre sur mobile).
        $this->assertStringContainsString(".adm .bo-tableau-donnees.bo-tableau-fixe {\n    table-layout: fixed;\n    min-width: 60rem;", $css);
        // Place de la barre de défilement réservée : une page courte ne décale pas la mise en page.
        $this->assertStringContainsString("html:has(body.adm) {\n    scrollbar-gutter: stable;", $css);
    }
}
