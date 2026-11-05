<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\StatutDocumentKyc;
use App\Enums\StatutKyc;
use App\Enums\TypeDocumentKyc;
use App\Enums\TypeTitulaire;
use App\Models\Greffe;
use App\Models\KycDocument;
use App\Models\Role as RoleModel;
use App\Models\User;
use App\Support\Recette;
use App\Support\Telephone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comptes de recette (réunion du 05/11/2026), uniquement avec APP_ENV=recette : un compte par profil du back-office
 * (SUPER_ADMIN, ADMIN, VALIDATEUR, RÉGULATEUR, GESTIONNAIRE DES SITES, CONTRÔLEUR DES PAIEMENTS, AGENT DE GUICHET),
 * mot de passe généré, affiché une seule fois, jamais enregistré en clair ; double authentification à la première
 * connexion. Citoyens : personne physique avec IFU (identité et IFU validés), personne physique sans IFU, personne morale
 * (raison sociale, IFU de l'entreprise) ; connexion par téléphone et code SMS, lisible sur la page « SMS envoyés ».
 * Idempotente : un compte existant garde son mot de passe, sauf avec --nouveaux-mots-de-passe.
 * Compte nominatif (--testeur="Nom Prénom" --profil=AGENT_GUICHET) : un compte du back-office au nom du testeur, pour
 * que le journal d'audit identifie chacun ; seul ce compte est créé, les comptes par profil restent disponibles.
 */
class RecetteComptes extends Command
{
    protected $signature = 'raaga:recette-comptes
        {--nouveaux-mots-de-passe : Génère de nouveaux mots de passe pour les comptes du back-office}
        {--testeur= : Compte nominatif : nom et prénom(s) du testeur}
        {--profil= : Profil du compte nominatif (SUPER_ADMIN, ADMIN, VALIDATEUR, REGULATEUR, GESTIONNAIRE_SITES, CONTROLEUR_PAIEMENTS, AGENT_GUICHET)}';

    protected $description = 'Crée en recette un compte par profil (back-office et citoyens), mots de passe générés affichés une seule fois';

    /** Domaine réservé aux tests (RFC 2606) : aucune adresse réelle. */
    public const DOMAINE = '@recette.raaga.test';

    /** Profil du back-office => nom affiché. */
    public const BACKOFFICE = [
        'superadmin' => 'Super-administrateur recette',
        'admin' => 'Administrateur recette',
        'validateur' => 'Validateur recette',
        'regulateur' => 'Régulateur recette',
        'gestionnaire_sites' => 'Gestionnaire des sites recette',
        'controleur_paiements' => 'Contrôleur des paiements recette',
        'agent_guichet' => 'Agent de guichet recette',
    ];

    /** Citoyens : clé => [nom, prénoms, téléphone, titulaire, IFU]. */
    public const CITOYENS = [
        'physique_ifu' => ['SAWADOGO', 'Rasmané', '+22662000001', TypeTitulaire::Physique, '62000001R'],
        'physique_sans_ifu' => ['OUÉDRAOGO', 'Fatimata', '+22662000002', TypeTitulaire::Physique, null],
        'morale' => ['KABORÉ', 'Issa', '+22662000003', TypeTitulaire::Morale, '62000003R'],
    ];

    public function handle(): int
    {
        if (! Recette::active()) {
            $this->error(__('recette.refusee', ['env' => app()->environment()]));

            return self::FAILURE;
        }

        if (filled($this->option('testeur')) || filled($this->option('profil'))) {
            return $this->nominatif();
        }

        $lignes = [];
        foreach (self::BACKOFFICE as $code => $nom) {
            $lignes[] = $this->compteBackoffice($nom, str_replace('_', '-', $code).self::DOMAINE, Role::from($code));
        }

        foreach (self::CITOYENS as $cle => [$nom, $prenoms, $telephone, $titulaire, $ifu]) {
            $user = $this->citoyen($cle, $nom, $prenoms, $telephone, $titulaire, $ifu);
            $libelle = match ($cle) {
                'physique_ifu' => 'Citoyen, personne physique avec IFU',
                'physique_sans_ifu' => 'Citoyen, personne physique sans IFU',
                'morale' => 'Citoyen, personne morale',
            };
            $lignes[] = [$libelle, Telephone::formaterLocal($user->telephone), '', __('recette.comptes.citoyen')];
        }

        $this->info(__('recette.comptes.titre'));
        $this->table(__('recette.comptes.colonnes'), $lignes);
        $this->warn(__('recette.comptes.avertissement'));

        return self::SUCCESS;
    }

    /** Compte nominatif d'un testeur : adresse tirée de son nom et de son profil, mot de passe généré affiché une fois. */
    private function nominatif(): int
    {
        $testeur = trim((string) $this->option('testeur'));
        $profil = strtolower(trim((string) $this->option('profil')));
        if (mb_strlen($testeur) < 3 || ! array_key_exists($profil, self::BACKOFFICE)) {
            $this->error(__('recette.comptes.nominatif_invalide', ['profils' => implode(', ', array_map('strtoupper', array_keys(self::BACKOFFICE)))]));

            return self::FAILURE;
        }
        $email = Str::slug($testeur, '.').'.'.str_replace('_', '-', $profil).self::DOMAINE;
        $this->info(__('recette.comptes.titre'));
        $this->table(__('recette.comptes.colonnes'), [$this->compteBackoffice($testeur, $email, Role::from($profil))]);
        $this->warn(__('recette.comptes.avertissement'));

        return self::SUCCESS;
    }

    /**
     * Compte du back-office créé s'il manque ; mot de passe généré à la création ou avec --nouveaux-mots-de-passe (la
     * double authentification est alors refaite). @return list<string> ligne du tableau affiché
     */
    private function compteBackoffice(string $nom, string $email, Role $role): array
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $motDePasse = null;
        if (! $user->exists || $this->option('nouveaux-mots-de-passe')) {
            $motDePasse = Str::password(16, symbols: false);
            $user->forceFill(['password' => $motDePasse, 'totp_secret' => null, 'totp_active_le' => null]);
        }
        $user->forceFill(['name' => $nom, 'email_verified_at' => $user->email_verified_at ?? now(), 'role_id' => RoleModel::pour($role)->id, 'actif' => true])->save();

        return [$role->label().($nom !== (self::BACKOFFICE[$role->value] ?? null) ? ' · '.$nom : ''), $email, $motDePasse ?? __('recette.comptes.conserve'), __('recette.comptes.backoffice')];
    }

    /** Citoyen de recette (identité validée, pièces « spécimen ») ; créé s'il manque, jamais modifié ensuite. */
    private function citoyen(string $cle, string $nom, string $prenoms, string $telephone, TypeTitulaire $titulaire, ?string $ifu): User
    {
        $existant = User::query()->where('telephone', $telephone)->first();
        if ($existant) {
            return $existant;
        }
        $morale = $titulaire === TypeTitulaire::Morale;
        $user = User::query()->forceCreate([
            'name' => $prenoms.' '.$nom, 'nom' => $nom, 'prenoms' => $prenoms, 'telephone' => $telephone, 'password' => Str::password(40),
            'role_id' => RoleModel::pour(Role::Citoyen)->id, 'pays_residence' => 'BF', 'date_naissance' => now()->subYears(35)->toDateString(),
            'type_titulaire' => $titulaire, 'raison_sociale' => $morale ? 'Société de recette du Faso SARL' : null, 'forme_morale' => $morale ? 'ENTREPRISE' : null,
            // Personne morale (05/11/2026) : gérant (le titulaire) et RCCM, comme à l'inscription.
            'gerant_fonction' => $morale ? 'Gérant' : null, 'email' => $morale ? 'gerant.morale'.self::DOMAINE : null,
            'rccm' => $morale ? 'BF OUA 2026 B '.substr($telephone, -6) : null, 'rccm_immatricule_le' => $morale ? '2026-01-15' : null,
            'rccm_greffe_id' => $morale ? Greffe::query()->where('code', 'OUAGADOUGOU')->value('id') : null,
            'ifu' => $ifu, 'statut_kyc' => StatutKyc::Valide, 'niveau_kyc' => 1, 'actif' => true, 'sexe' => $cle === 'physique_sans_ifu' ? 'F' : 'M', 'lieu_naissance' => 'Ouagadougou',
            'numero_piece' => 'B'.substr($telephone, -7).'R', 'piece_expire_le' => now()->addYears(5)->toDateString(),
            'identite_validee' => array_filter(['nom' => $nom, 'prenoms' => $prenoms, 'raison_sociale' => $morale ? 'Société de recette du Faso SARL' : null,
                'type_titulaire' => $titulaire->value, 'validee_le' => now()->toIso8601String()]),
        ]);
        $pieces = [[TypeDocumentKyc::Cnib, 'RECTO', $user->numero_piece], [TypeDocumentKyc::Cnib, 'VERSO', null], [TypeDocumentKyc::Selfie, null, null]];
        if ($ifu) {
            $pieces[] = [TypeDocumentKyc::Ifu, null, $ifu];
        }
        if ($morale) {
            $pieces[] = [TypeDocumentKyc::JustifPouvoir, null, null];
            $pieces[] = [TypeDocumentKyc::Rccm, null, $user->rccm];
        }
        foreach ($pieces as [$type, $face, $numero]) {
            $contenu = $this->specimen($type->value.($face ? ' '.$face : '').' SPÉCIMEN RECETTE');
            $chemin = 'kyc/'.$user->id.'/'.Str::uuid().'.jpg';
            Storage::disk('local')->put($chemin, $contenu);
            KycDocument::query()->create(['user_id' => $user->id, 'type' => $type, 'face' => $face, 'chemin_fichier' => $chemin, 'nom_original' => Str::slug($type->value).'.jpg',
                'mime' => 'image/jpeg', 'taille' => strlen($contenu), 'empreinte_sha256' => hash('sha256', $contenu), 'statut' => StatutDocumentKyc::Valide,
                'televerse_par' => $user->id, 'valide_le' => now(), 'numero' => $numero,
                'expire_le' => $face === 'RECTO' ? $user->piece_expire_le : null]);
        }

        return $user;
    }

    /** Image JPEG marquée « SPÉCIMEN » (aucune vraie pièce). */
    private function specimen(string $texte): string
    {
        $img = imagecreatetruecolor(640, 400);
        imagefill($img, 0, 0, imagecolorallocate($img, 232, 236, 240));
        imagestring($img, 5, 40, 180, $texte, imagecolorallocate($img, 30, 60, 120));
        ob_start();
        imagejpeg($img, null, 80);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
