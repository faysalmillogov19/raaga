<?php

namespace Tests\Feature\Cartes;

use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\Site;
use App\Models\User;
use App\Services\Kyc\JustificatifsGroupeSanguin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActeursParametrage;
use Tests\Support\Boutiques;
use Tests\Support\ConnexionBackoffice;
use Tests\TestCase;

/**
 * Réunion du 05/11/2026, carte : justificatif du groupe sanguin (déposé à la réservation, validé ou refusé par l'ADMIN),
 * libellés des sites corrigés dans le référentiel.
 */
class CarteReunion0511Test extends TestCase
{
    use ActeursParametrage, ConnexionBackoffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creerActeurs();
        Notification::fake();
        Storage::fake('local');
        Boutiques::campagneOuverte();
    }

    public function test_justificatif_depose_dans_les_pieces_d_identite_puis_valide_ou_refuse_par_l_admin(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide, 'telephone' => '+22670001234', 'email' => null]);
        // Réservation : plus de champ de justificatif, un renvoi vers « Vérifier mon identité ».
        $this->actingAs($citoyen)->get(route('boutiques.boutique', Boutiques::boutique('A', 1)->code))->assertOk()
            ->assertSee('data-renvoi-justificatif', false)->assertDontSee('name="justificatif_groupe_sanguin"', false);

        // Pièces d'identité : groupe, accord et justificatif, modifiables à tout moment.
        $this->actingAs($citoyen)->get(route('espace.verification'))->assertOk()->assertSee('data-groupe-sanguin', false)->assertSee('name="justificatif"', false);
        $this->actingAs($citoyen)->post(route('espace.verification.groupe-sanguin'), ['groupe_sanguin' => 'B+'])->assertSessionHasErrors('consentement_groupe_sanguin');
        $this->actingAs($citoyen)->post(route('espace.verification.groupe-sanguin'), ['groupe_sanguin' => 'B+', 'consentement_groupe_sanguin' => '1',
            'justificatif' => UploadedFile::fake()->image('groupe.jpg')])->assertSessionHasNoErrors()->assertRedirect(route('espace.verification').'#groupe-sanguin');
        $document = JustificatifsGroupeSanguin::dernier($citoyen);
        $this->assertSame([TypeDocumentKyc::GroupeSanguin, StatutDocumentKyc::EnAttente, 'B+'], [$document->type, $document->statut, $citoyen->fresh()->groupe_sanguin]);
        $this->actingAs($citoyen)->get(route('espace.verification'))->assertSee(__('public.verification.groupe_sanguin.statuts.EN_ATTENTE'));

        // Dossier de l'ADMIN : groupe déclaré, justificatif à vérifier, boutons Valider et Refuser.
        $this->connecterBackoffice(Role::Admin);
        $this->get(route('admin.kyc.dossier', $citoyen))->assertOk()->assertSee('data-groupe-sanguin', false)->assertSee('B+')
            ->assertSee(__('admin.kyc.groupe_sanguin.statuts.EN_ATTENTE'))->assertSee(__('admin.kyc.groupe_sanguin.valider'));
        // Refus : motif obligatoire.
        $this->post(route('admin.kyc.groupe-sanguin.refuser', $document))->assertSessionHasErrors('motif');
        $this->post(route('admin.kyc.groupe-sanguin.valider', $document))->assertRedirect(route('admin.kyc.dossier', $citoyen));
        $this->assertSame(StatutDocumentKyc::Valide, $document->fresh()->statut);
        $this->assertDatabaseHas('audit_logs', ['action' => 'groupe_sanguin.justificatif_valide', 'entite_id' => $document->id]);
        // Déjà décidé : plus de décision.
        $this->post(route('admin.kyc.groupe-sanguin.refuser', $document), ['motif' => 'Document illisible'])->assertStatus(409);
    }

    public function test_seul_l_admin_decide_du_justificatif(): void
    {
        $citoyen = User::factory()->role(Role::Citoyen)->create(['statut_kyc' => StatutKyc::Valide]);
        $document = app(JustificatifsGroupeSanguin::class)->deposer($citoyen, UploadedFile::fake()->image('groupe.jpg'));
        foreach ([Role::Validateur, Role::Regulateur, Role::SuperAdmin] as $role) {
            $this->connecterBackoffice($role);
            $this->post(route('admin.kyc.groupe-sanguin.valider', $document))->assertForbidden();
        }
        $this->assertSame(StatutDocumentKyc::EnAttente, KycDocument::query()->find($document->id)->statut);
    }

    public function test_libelles_des_sites_corriges_dans_le_referentiel(): void
    {
        foreach (['RAA' => 'Marché moderne de l’Arrondissement N°1 (Raaga)', 'A04' => 'Marché moderne de l’Arrondissement N°4', 'SAR' => 'Marché moderne de Sarfalao'] as $code => $nom) {
            Site::query()->forceCreate(['statut' => 'CONFIRME', 'code' => $code, 'nom' => $nom, 'ville' => 'Ouagadougou', 'adresse' => 'Adresse', 'version' => 1, 'cree_par' => $this->validateur->id]);
        }
        (require database_path('migrations/2026_12_03_000000_libelles_sites_reunion_05_11.php'))->up();

        $this->assertSame(['A04' => 'Arrondissement 4 Ouagadougou', 'RAA' => 'Arrondissement 1 Ouagadougou', 'SAR' => 'Sarfalao Bobo-Dioulasso'],
            Site::query()->whereIn('code', ['RAA', 'A04', 'SAR'])->orderBy('code')->pluck('nom', 'code')->all());
        $this->assertSame(3, AuditLog::query()->where('action', 'site.renomme')->count());
        // Relancée : rien ne change.
        (require database_path('migrations/2026_12_03_000000_libelles_sites_reunion_05_11.php'))->up();
        $this->assertSame(3, AuditLog::query()->where('action', 'site.renomme')->count());
    }
}
