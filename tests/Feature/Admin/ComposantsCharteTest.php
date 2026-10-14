<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Enums\StatutSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ViewException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/** Composants de la charte du back-office (<x-admin.*>) et page de contrôle visuel /admin/charte. */
class ComposantsCharteTest extends TestCase
{
    use ConnexionBackoffice, RefreshDatabase;

    public function test_bouton(): void
    {
        $this->blade('<x-admin.bouton>Principal</x-admin.bouton>')
            ->assertSee('<button type="button" class="adm-btn adm-btn-principal adm-btn-normal"', false)->assertSee('Principal');
        $this->blade('<x-admin.bouton variante="contour" taille="petit" href="/x">Voir</x-admin.bouton>')
            ->assertSee('<a href="/x" class="adm-btn adm-btn-contour adm-btn-petit"', false);
        $this->blade('<x-admin.bouton variante="danger" taille="grand" type="submit">Supprimer</x-admin.bouton>')
            ->assertSee('type="submit" class="adm-btn adm-btn-danger adm-btn-grand"', false);
        $this->blade('<x-admin.bouton disabled>Désactivé</x-admin.bouton>')->assertSee('disabled', false);
        $this->blade('<x-admin.bouton chargement>Import en cours…</x-admin.bouton>')
            ->assertSee('aria-busy="true"', false)->assertSee('adm-btn-roue', false)->assertSee('disabled', false);
        $this->blade('<x-admin.bouton variante="contour" icone="modifier" libelle="Modifier" />')
            ->assertSee('adm-btn-icone', false)->assertSee('aria-label="Modifier"', false);

        $this->expectException(ViewException::class);
        $this->blade('<x-admin.bouton icone="modifier" />');
    }

    public static function statuts(): array
    {
        return [
            ['Brouillon', 'neutre'], ['Désactivé', 'neutre'], ['En construction', 'neutre'],
            ['Soumis', 'info'], ['Attribuée', 'info'], ['Aperçu', 'info'], ['Par défaut', 'info'],
            ['Confirmé', 'ok'], ['Disponible', 'ok'], ['Livrée', 'ok'], ['Occupée', 'ok'],
            ['En révision', 'avertissement'], ['Réservée', 'avertissement'], ['En travaux', 'avertissement'],
            ['Rejeté', 'erreur'], ['Refusé', 'erreur'], ['Fermée', 'erreur'], ['Suspendue', 'erreur'],
        ];
    }

    #[DataProvider('statuts')]
    public function test_badge_statut(string $statut, string $variante): void
    {
        $this->blade('<x-admin.badge-statut :statut="$s" />', ['s' => $statut])
            ->assertSee('<span class="adm-badge adm-badge-'.$variante.'">'.e($statut).'</span>', false);
    }

    public function test_badge_statut_depuis_une_enumeration(): void
    {
        $this->blade('<x-admin.badge-statut :statut="$s" />', ['s' => StatutSite::Confirme])
            ->assertSee('adm-badge-ok', false)->assertSee(StatutSite::Confirme->label());
    }

    public function test_champ(): void
    {
        $this->blade('<x-admin.champ nom="code" libelle="Code du site" obligatoire aide="3 caractères." />')
            ->assertSee('<span class="adm-requis" aria-hidden="true">*</span>', false)->assertSee('required', false)
            ->assertSee('aria-describedby="code-aide"', false)->assertSee('3 caractères.');
        $this->withViewErrors(['surface' => 'La surface doit être supérieure à 0.'])
            ->blade('<x-admin.champ nom="surface" libelle="Surface" />')
            ->assertSee('class="champ est-invalide"', false)->assertSee('aria-invalid="true"', false)
            ->assertSee('<p id="surface-erreur" class="message-erreur" role="alert">La surface doit être supérieure à 0.</p>', false);
        $this->blade('<x-admin.champ nom="niveau" libelle="Niveau" type="liste" :options="[\'RC\' => \'Rez\', \'R1\' => \'R+1\']" valeur="R1" vide="Tous" />')
            ->assertSee('<select id="niveau" name="niveau"', false)->assertSee('<option value="R1" selected>', false)->assertSee('<option value="">Tous</option>', false);
        $this->blade('<x-admin.champ nom="observations" libelle="Observations" type="zone" valeur="Angle" />')
            ->assertSee('<textarea id="observations" name="observations" rows="3"', false)->assertSee('>Angle</textarea>', false);
        $this->blade('<x-admin.champ nom="position" libelle="Position" type="radio" :options="[\'A\' => \'Angle\', \'F\' => \'Façade\']" valeur="F" />')
            ->assertSee('<legend class="adm-libelle">Position', false)->assertSee('value="F" checked', false);
        $this->blade('<x-admin.champ nom="attribuable" libelle="Attribuable" type="case" :valeur="true" />')
            ->assertSee('type="checkbox" name="attribuable" value="1" checked', false);
        $this->blade('<x-admin.champ nom="actif" libelle="Type actif" type="interrupteur" />')->assertSee('role="switch"', false);
        $this->blade('<x-admin.champ nom="code_boutique" libelle="Code" type="verrouille" valeur="RAA-R1-A-045" />')
            ->assertSee('readonly aria-readonly="true" class="champ adm-verrouille"', false)->assertDontSee('name="code_boutique"', false);
    }

    public function test_depot_fichier(): void
    {
        $this->blade('<x-admin.depot-fichier nom="fichier" libelle="Fichier Excel" />')
            ->assertSee('class="adm-depot"', false)->assertSee('Déposez le fichier ici ou choisissez-le')
            ->assertSee('.xlsx uniquement, 5 Mo maximum.')->assertSee('type="file"', false)->assertSee('Choisir un fichier');
    }

    public function test_message(): void
    {
        foreach (['ok' => 'status', 'info' => 'status', 'avertissement' => 'alert', 'erreur' => 'alert'] as $type => $role) {
            $this->blade('<x-admin.message :type="$t" titre="Titre.">Texte</x-admin.message>', ['t' => $type])
                ->assertSee('class="adm-message adm-message-'.$type.'" role="'.$role.'"', false)->assertSee('<strong>Titre.</strong> Texte', false);
        }
    }

    public function test_onglets_et_pagination(): void
    {
        $this->blade('<x-admin.onglets actif="plans" :onglets="[\'infos\' => [\'Informations\', \'/i\'], \'plans\' => [\'Plans\', \'/p\']]" />')
            ->assertSee('<a href="/p" class="adm-onglet adm-onglet-actif"', false)->assertSee('aria-current="page"', false)
            ->assertSee('<a href="/i" class="adm-onglet" >Informations</a>', false)->assertDontSee('href="/i" class="adm-onglet adm-onglet-actif"', false);
        $this->blade('<x-admin.pagination :page="1" :derniere="183" :url="fn ($p) => \'/p\'.$p" />')
            ->assertSee('<span aria-disabled="true">Précédent</span>', false)->assertSee('href="/p1"  aria-current="page"', false)
            ->assertSee('<span aria-hidden="true">…</span>', false)->assertSee('href="/p183"', false)->assertSee('href="/p2"  rel="next"', false);
    }

    public function test_confirmation_et_circuit_de_validation(): void
    {
        $this->blade('<x-admin.confirmation id="r" etape="rejeter" titre="Rejeter le site" message="Le motif est transmis." action="/rejeter" />')
            ->assertSee('adm-btn-rejet', false)->assertSee('Rejeter…')->assertSee('<dialog', false)->assertSee('Le motif est transmis.')
            ->assertSee('name="motif" rows="3"  required minlength="5"', false)->assertSee('Annuler');
        $this->blade('<x-admin.confirmation id="s" etape="soumettre" titre="T" message="M" action="/s" />')
            ->assertSee('adm-btn-principal', false)->assertSee('Soumettre au validateur')->assertDontSee('name="motif"', false);
        $this->blade('<x-admin.confirmation id="c" etape="confirmer" titre="T" message="M" action="/c" />')->assertSee('>Confirmer</span>', false);
        $this->blade('<x-admin.confirmation id="v" etape="reviser" titre="T" message="M" action="/v" />')
            ->assertSee('adm-btn-discret', false)->assertSee('Remettre en révision');
        $this->blade('<x-admin.confirmation id="d" titre="Supprimer" message="M" action="/d" bouton="Supprimer" methode="DELETE" danger><x-slot:declencheur>Supprimer</x-slot:declencheur></x-admin.confirmation>')
            ->assertSee('adm-btn-danger', false)->assertSee('name="_method" value="DELETE"', false);
        $this->blade('<x-admin.confirmation id="x" titre="Désactiver" message="M" action="/x" bouton="Désactiver" motif="facultatif"><x-slot:declencheur>Désactiver</x-slot:declencheur></x-admin.confirmation>')
            ->assertSee('Motif (facultatif)')->assertDontSee('required minlength', false);
        $this->blade('<x-admin.circuit />')->assertSee('type="submit" class="adm-btn adm-btn-contour adm-btn-normal"', false)->assertSee('Enregistrer le brouillon');
    }

    public function test_la_page_charte_est_reservee_au_super_admin(): void
    {
        $this->connecterBackoffice(Role::SuperAdmin);
        $this->get(route('admin.charte'))->assertOk()
            ->assertSee('Boutons, statuts et formulaires')->assertSee('Soumettre au validateur')->assertSee('adm-badge-avertissement', false)
            ->assertSee('Déposez le fichier ici ou choisissez-le')->assertSee('role="switch"', false)->assertSee('La surface doit être supérieure à 0.');

        foreach ([Role::Admin, Role::Validateur, Role::Regulateur, Role::GestionnaireSites] as $role) {
            $this->connecterBackoffice($role);
            $this->get(route('admin.charte'))->assertForbidden();
        }
    }
}
