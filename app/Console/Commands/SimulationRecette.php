<?php

namespace App\Console\Commands;

use App\Contracts\PaymentProvider;
use App\Enums\DecisionParametrage;
use App\Enums\FaceDocument;
use App\Enums\Role;
use App\Enums\StatutBoutique;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutGrilleTarifaire;
use App\Enums\StatutKyc;
use App\Enums\StatutSouscription;
use App\Enums\StatutTranche;
use App\Enums\TypeDocumentKyc;
use App\Models\Boutique;
use App\Models\CampagneBoutiques;
use App\Models\DecisionPhase;
use App\Models\InscriptionBoutique;
use App\Models\KycDocument;
use App\Models\LigneRegistre;
use App\Models\MoyenPaiement;
use App\Models\Paiement;
use App\Models\ParametrageDecision;
use App\Models\ParametresInvestissement;
use App\Models\ReservationBoutique;
use App\Models\Role as RoleModel;
use App\Models\Souscription;
use App\Models\Tranche;
use App\Models\User;
use App\Services\Boutiques\IdentitesAttributaires;
use App\Services\Boutiques\ListesAttente;
use App\Services\Boutiques\ReservationsBoutiques;
use App\Services\Kyc\VerificationIdentite;
use App\Services\Paiement\ServicePaiement;
use App\Services\Souscriptions\IdentitesInvestisseurs;
use App\Services\Souscriptions\PreparationSouscription;
use App\Support\GardeFouBase;
use App\Support\Phase;
use App\Support\Telephone;
use Database\Seeders\ParametresPaiementsSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Données de recette pour tester tous les parcours en local : citoyens fictifs (numéros +226 60 00 00 xx), chacun
 * arrêté à une étape d'un parcours (souscription d'actions, réservation de boutique, liste d'attente, identité,
 * paiement manuel à contrôler). Les paiements passent par le module de paiement (simulateur de recette) et les
 * services de la plateforme, comme à l'écran. Idempotente : un scénario dont le citoyen existe déjà est sauté.
 *
 * Jamais en production : refusée hors APP_ENV=local, avant tout accès à la base. Les codes de connexion des citoyens
 * s'affichent sur /recette/sms.
 */
class SimulationRecette extends Command
{
    protected $signature = 'raaga:simulation';

    protected $description = 'Crée en local des citoyens fictifs à chaque étape des parcours (actions, boutiques, identité, paiements)';

    private const IP = '127.0.0.1';

    private const NAVIGATEUR = 'Simulation de recette (raaga:simulation)';

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> nom, téléphone, scénario, à tester */
    private array $lignes = [];

    private ?CampagneBoutiques $campagne = null;

    private ?Tranche $tranche = null;

    public function handle(): int
    {
        // Jamais hors du poste de développement : refus avant tout accès à la base.
        try {
            GardeFouBase::exigerPosteLocal('raaga:simulation');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->preparerParametres();
        $this->campagne = CampagneBoutiques::ouverte();
        $this->tranche = Phase::trancheOuverte();

        $this->scenariosActions();
        $this->scenariosBoutiques();
        $this->scenariosIdentite();

        // Délais d'identité fixés tout de suite (comme la tâche planifiée), suivants retenus.
        app(IdentitesInvestisseurs::class)->surveiller();
        app(IdentitesAttributaires::class)->surveiller();

        $this->newLine();
        $this->table(['Citoyen', 'Téléphone', 'Scénario', 'À tester'], $this->lignes);
        $this->newLine();
        $this->line('Connexion des citoyens : /connexion avec le numéro, code affiché sur http://localhost:8000/recette/sms.');
        $this->line('Back-office : comptes de démonstration (regulation@, validation@, admin@, paiements@raaga.test ; mot de passe = adresse).');
        $this->line('Paiement par opérateur : simulateur de recette (boutons « succès » ou « échec » sur la page de paiement).');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Pré-requis

    /** Délais de paiement initiaux, paramètres d'investissement et démarches ouverts, si rien n'existe encore. */
    private function preparerParametres(): void
    {
        (new ParametresPaiementsSeeder)->run();
        $regulateur = User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Regulateur->value))->orderBy('id')->first();
        $validateur = User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Validateur->value))->orderBy('id')->first();
        if (! $regulateur || ! $validateur) {
            $this->warn('Comptes RÉGULATEUR et VALIDATEUR absents : lancez d’abord les comptes de démonstration (raaga:comptes-demo).');

            return;
        }

        if (ParametresInvestissement::confirmes() === null && ($tranche = Tranche::query()->where('statut', StatutTranche::Ouverte)->first())) {
            $fiche = ParametresInvestissement::query()->create(['objectif_collecte' => (int) $tranche->objectif_montant, 'valeur_action' => (int) $tranche->valeur_unitaire,
                'minimum_actions' => max(1, (int) $tranche->min_actions_par_souscription), 'plafond_global' => null, 'statut' => StatutGrilleTarifaire::Confirme]);
            $version = $fiche->creerVersion($regulateur, 'Simulation de recette');
            ParametrageDecision::query()->create(['objet_type' => 'investissement', 'objet_id' => $fiche->id, 'version' => $version->numero,
                'decision' => DecisionParametrage::Confirme, 'decideur_id' => $validateur->id, 'created_at' => now()]);
            $this->info('Paramètres d’investissement confirmés (simulation).');
        }

        if (! Phase::souscriptionParametre()) {
            DecisionPhase::query()->create(['presentation_actions' => true, 'souscription_actions' => true, 'statut' => DecisionPhase::CONFIRMEE,
                'motif' => 'Simulation de recette', 'propose_par' => $regulateur->id, 'decide_par' => $validateur->id, 'decide_le' => now()]);
            Cache::forget(Phase::CACHE);
            $this->info('Démarches : présentation et souscription des actions ouvertes (simulation).');
        }
    }

    // ---------------------------------------------------------------- Actions

    private function scenariosActions(): void
    {
        if (! $this->tranche) {
            $this->warn('Aucune tranche ouverte : scénarios « actions » sautés (confirmez une tranche au back-office).');

            return;
        }

        $this->scenario('Awa KABORÉ', '60000001', StatutKyc::Valide, 'Actions : souscription réservée, à payer',
            'Payer depuis « Mon espace » (simulateur), puis reçu', fn (User $u) => $this->souscrire($u, 5));

        $this->scenario('Issa OUÉDRAOGO', '60000002', StatutKyc::Valide, 'Actions : souscription payée (Orange Money)',
            'Reçu PDF, collecte sur Transparence', fn (User $u) => $this->payer($this->souscrire($u, 10)));

        $this->scenario('Mariam SAWADOGO', '60000003', StatutKyc::Valide, 'Actions : allouées et inscrites au registre',
            'Attestation PDF, carte d’actionnaire', fn (User $u) => $this->allouer($this->payer($this->souscrire($u, 20))));

        $this->scenario('Paul ZONGO', '60000004', StatutKyc::Rejete, 'Actions payées, identité refusée : délai de correction',
            'Alerte « identité à corriger », renvoyer les pièces', fn (User $u) => $this->payer($this->souscrire($u, 8)));

        $this->scenario('Rasmata TRAORÉ', '60000005', StatutKyc::AMettreAJour, 'Actions allouées, identité non corrigée : actions gelées',
            'Attestation et carte bloquées ; valider l’identité au back-office pour dégeler', function (User $u) {
                $this->allouer($this->payer($this->souscrire($u, 12)));
                $u->forceFill(['kyc_actions_a_corriger_avant' => now()->subMinute()])->save();
            });

        $this->scenario('Ali SANOU', '60000006', StatutKyc::NonSoumis, 'Aucune souscription, identité non soumise',
            'Acheter des actions sans KYC, puis déposer les pièces', fn (User $u) => null);

        $this->scenario('Fatimata NIKIÉMA', '60000007', StatutKyc::Valide, 'Actions : virement avec preuve déposée',
            'CONTRÔLEUR DES PAIEMENTS : marquer reçu, incomplet ou rejeté', fn (User $u) => $this->preuveVirement($this->souscrire($u, 15)));
    }

    // ---------------------------------------------------------------- Boutiques

    private function scenariosBoutiques(): void
    {
        if (! $this->campagne) {
            $this->warn('Aucune campagne de boutiques ouverte : scénarios « boutiques » sautés.');

            return;
        }
        $libres = $this->boutiquesLibres(8);
        if (count($libres) < 6) {
            $this->warn('Pas assez de boutiques libres avec un tarif dans la campagne ouverte : scénarios « boutiques » sautés.');

            return;
        }
        [$b1, $b2, $b3, $b4, $b5, $b6] = $libres;

        $this->scenario('Moussa KONATÉ', '60000011', StatutKyc::Valide, 'Boutique '.$b1->code.' pré-réservée : caution à payer',
            'Payer la caution ou laisser expirer (liste d’attente)', fn (User $u) => $this->inscrire($u, $b1));

        $this->scenario('Oumar DIALLO', '60000012', StatutKyc::Valide, 'Liste d’attente '.$b1->code.', rang 1',
            'Retenu si Moussa se désiste ou ne paie pas', fn (User $u) => $this->inscrire($u, $b1));

        $this->scenario('Nafissatou BARRY', '60000013', StatutKyc::Valide, 'Liste d’attente '.$b1->code.', rang 2',
            'Rang affiché dans « Mes boutiques »', fn (User $u) => $this->inscrire($u, $b1));

        $this->scenario('Salimata COULIBALY', '60000014', StatutKyc::Valide, 'Boutique '.$b2->code.' réservée : avance à payer',
            'Payer l’avance, ou désistement (caution en retenue)', fn (User $u) => $this->payer($this->inscrire($u, $b2)));

        $this->scenario('Hamidou TAPSOBA', '60000015', StatutKyc::Valide, 'Boutique '.$b3->code.' : avance payée, prête à attribuer',
            'VALIDATEUR : « Attributions à faire », date du contrat', function (User $u) use ($b3) {
                $r = $this->payer($this->inscrire($u, $b3));
                $this->payer($r->refresh());
            });

        $this->scenario('Aïcha OUATTARA', '60000016', StatutKyc::Valide, 'Boutique '.$b4->code.' attribuée',
            'Carte de commerçant, plein écran et PDF', function (User $u) use ($b4) {
                $r = $this->payer($this->inscrire($u, $b4));
                $this->payer($r->refresh());
                app(ReservationsBoutiques::class)->attribuer($r->refresh(), now()->toDateString(), $this->validateur());
            });

        $this->scenario('Brahima SOMÉ', '60000017', StatutKyc::Rejete, 'Boutique '.$b5->code.' : identité refusée, délai de correction',
            'Alerte sur le suivi ; sans correction, la boutique passe au suivant', function (User $u) use ($b5) {
                $this->payer($this->inscrire($u, $b5));
                // Pièce déposée à la réservation, puis refusée au back-office.
                $u->forceFill(['statut_kyc' => StatutKyc::Rejete])->save();
            });

        $this->scenario('Zénabou ILBOUDO', '60000018', StatutKyc::Valide, 'Boutique '.$b6->code.' : caution par virement, preuve déposée',
            'CONTRÔLEUR DES PAIEMENTS : contrôle de la preuve', fn (User $u) => $this->preuveVirement($this->inscrire($u, $b6)));
    }

    // ---------------------------------------------------------------- Identité

    private function scenariosIdentite(): void
    {
        $this->scenario('Clément YAMÉOGO', '60000021', StatutKyc::EnAttente, 'Dossier d’identité complet, en attente',
            'ADMIN : « Vérifications d’identité », conforme ou douteux', fn (User $u) => $this->dossierComplet($u));
    }

    // ---------------------------------------------------------------- Outils

    /** Crée le citoyen et joue le scénario, sauf s'il existe déjà ; une erreur n'arrête pas les autres scénarios. */
    private function scenario(string $nom, string $local, StatutKyc $kyc, string $libelle, string $aTester, callable $jouer): void
    {
        $telephone = '+226'.$local;
        if (User::query()->where('telephone', $telephone)->exists()) {
            $this->lignes[] = [$nom, Telephone::formaterLocal($telephone), $libelle.' (déjà créé)', $aTester];

            return;
        }
        try {
            $u = $this->citoyen($nom, $telephone, $kyc);
            $jouer($u);
            $this->lignes[] = [$nom, Telephone::formaterLocal($telephone), $libelle, $aTester];
        } catch (Throwable $e) {
            $this->warn($nom.' : scénario incomplet ('.$e->getMessage().')');
        }
    }

    private function citoyen(string $nom, string $telephone, StatutKyc $kyc): User
    {
        [$prenoms, $famille] = explode(' ', $nom, 2);
        $u = User::query()->forceCreate([
            'name' => $nom, 'prenoms' => $prenoms, 'nom' => $famille, 'telephone' => $telephone, 'password' => Hash::make(Str::random(40)),
            'role_id' => RoleModel::pour(Role::Citoyen)->id, 'pays_residence' => 'BF', 'date_naissance' => now()->subYears(35)->toDateString(),
            'statut_kyc' => $kyc, 'actif' => true,
        ]);
        if ($kyc === StatutKyc::Valide) {
            $u->forceFill(['niveau_kyc' => 1, 'numero_piece' => 'B'.substr($telephone, -7).'S', 'piece_expire_le' => now()->addYears(5)->toDateString(),
                'identite_validee' => ['nom' => $famille, 'prenoms' => $prenoms, 'type_titulaire' => 'PHYSIQUE', 'validee_le' => now()->toIso8601String()]])->save();
        }

        return $u;
    }

    private function souscrire(User $u, int $actions): Souscription
    {
        return app(PreparationSouscription::class)->reserver($this->tranche, $u, $actions,
            array_map(fn ($d) => $d->value, PreparationSouscription::ENGAGEMENTS), null, self::IP, self::NAVIGATEUR);
    }

    private function inscrire(User $u, Boutique $b): ?ReservationBoutique
    {
        $inscription = app(ListesAttente::class)->inscrire($this->campagne, $u, $b, TypeDocumentKyc::Cnib, 'B'.substr((string) $u->telephone, -7).'S',
            $this->fichier('piece-'.$u->id.'.jpg', 'CNIB SPÉCIMEN'), null, array_map(fn ($d) => $d->value, ReservationsBoutiques::ENGAGEMENTS), self::IP, self::NAVIGATEUR);

        return $inscription->reservation;
    }

    /** Paiement par Orange Money, confirmé par le simulateur de recette (caution, avance ou souscription). */
    private function payer(Souscription|ReservationBoutique $objet): Souscription|ReservationBoutique
    {
        $canal = MoyenPaiement::query()->where('code', 'ORANGE_MONEY')->firstOrFail();
        $service = app(ServicePaiement::class);
        $paiement = $service->initier($objet, $canal, (string) $objet->user->telephone, app(PaymentProvider::class), $objet->user);
        $service->confirmer($paiement->reference, true);

        return $objet->refresh();
    }

    /** Virement : référence de versement, preuve déposée (image), paiement en contrôle. */
    private function preuveVirement(Souscription|ReservationBoutique $objet): void
    {
        $canal = MoyenPaiement::query()->where('code', 'VIREMENT')->firstOrFail();
        $paiement = app(ServicePaiement::class)->initierManuel($objet, $canal, $objet->user);
        $chemin = 'paiements/'.$objet->reference.'/'.Str::uuid().'.jpg';
        Storage::disk(VerificationIdentite::DISQUE)->put($chemin, $this->image('AVIS DE VIREMENT SPÉCIMEN'));
        $paiement->forceFill(['preuve_chemin' => $chemin, 'preuve_nom' => 'avis-de-virement.jpg', 'preuve_mime' => 'image/jpeg', 'preuve_deposee_le' => now()])->save();
    }

    /**
     * Allocation : l'écran d'allocation des actions B n'existe pas encore ; la souscription passe « allouée » et ses
     * actions sont inscrites au registre (attestation, carte d'actionnaire).
     */
    private function allouer(Souscription $s): void
    {
        $s->forceFill(['statut' => StatutSouscription::Allouee, 'nb_actions_allouees' => $s->nb_actions_demandees])->save();
        $debut = (int) LigneRegistre::query()->max('numero_action_fin') + 1;
        LigneRegistre::query()->create(['numero_certificat' => 'CB-SIM-'.$s->id, 'user_id' => $s->user_id, 'tranche_id' => $s->tranche_id,
            'nb_actions' => $s->nb_actions_demandees, 'numero_action_debut' => $debut, 'numero_action_fin' => $debut + $s->nb_actions_demandees - 1,
            'date_inscription' => now()]);
    }

    /** Dossier d'identité complet (CNIB recto, verso, selfie), pièces en attente de vérification. */
    private function dossierComplet(User $u): void
    {
        foreach ([[TypeDocumentKyc::Cnib, FaceDocument::Recto, 'CNIB RECTO'], [TypeDocumentKyc::Cnib, FaceDocument::Verso, 'CNIB VERSO'], [TypeDocumentKyc::Selfie, null, 'SELFIE']] as [$type, $face, $texte]) {
            $chemin = 'kyc/'.$u->id.'/'.Str::uuid().'.jpg';
            $contenu = $this->image($texte.' SPÉCIMEN');
            Storage::disk(VerificationIdentite::DISQUE)->put($chemin, $contenu);
            KycDocument::query()->create(['user_id' => $u->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => $chemin, 'nom_original' => strtolower(str_replace(' ', '-', $texte)).'.jpg',
                'mime' => 'image/jpeg', 'taille' => strlen($contenu), 'empreinte_sha256' => hash('sha256', $contenu), 'statut' => StatutDocumentKyc::EnAttente,
                'televerse_par' => $u->id, 'numero' => $face === FaceDocument::Recto ? 'B6000021S' : null, 'expire_le' => $face === FaceDocument::Recto ? now()->addYears(5)->toDateString() : null]);
        }
        $u->forceFill(['numero_piece' => 'B6000021S', 'piece_expire_le' => now()->addYears(5)->toDateString()])->save();
    }

    /** @return list<Boutique> boutiques libres de la campagne ouverte, avec un tarif publié */
    private function boutiquesLibres(int $n): array
    {
        return Boutique::query()->whereIn('site_id', (array) $this->campagne->sites)->whereIn('type_boutique_id', (array) $this->campagne->types)
            ->whereIn('statut', [StatutBoutique::Disponible, StatutBoutique::EnCampagne])
            ->whereNotIn('id', InscriptionBoutique::query()->select('boutique_id'))
            ->whereHas('type', fn ($q) => $q->proposables())->orderBy('code')->limit(60)->get()
            ->filter(fn (Boutique $b) => ReservationsBoutiques::tarifer($b) !== null)
            ->take($n)->values()->all();
    }

    private function validateur(): User
    {
        return User::query()->whereHas('role', fn ($q) => $q->where('code', Role::Validateur->value))->orderBy('id')->firstOrFail();
    }

    /** Fichier image temporaire (pièce d'identité fictive), pour les services qui attendent un fichier déposé. */
    private function fichier(string $nom, string $texte): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'raaga');
        file_put_contents($chemin, $this->image($texte));

        return new UploadedFile($chemin, $nom, 'image/jpeg', null, true);
    }

    /** Image JPEG « spécimen » (aucune vraie pièce d'identité). */
    private function image(string $texte): string
    {
        $img = imagecreatetruecolor(640, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 230, 240, 232));
        imagestring($img, 5, 40, 180, $texte, imagecolorallocate($img, 0, 92, 43));
        ob_start();
        imagejpeg($img, null, 80);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
