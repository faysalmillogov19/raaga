<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Services\Backoffice\GestionUtilisateurs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Création d'un SUPER_ADMIN depuis le serveur (premier déploiement, ou plus aucun SUPER_ADMIN actif).
 * Aucun mot de passe n'est créé : la personne reçoit un lien d'invitation et suit le parcours normal
 * (mot de passe, puis double authentification).
 */
class CreerSuperAdmin extends Command
{
    protected $signature = 'raaga:creer-superadmin
        {nom : Nom et prénom(s)}
        {email : Adresse électronique professionnelle}
        {--force : Créer même si des SUPER_ADMIN actifs existent déjà}';

    protected $description = 'Crée un compte SUPER_ADMIN et affiche son lien d’invitation (valable 48 h).';

    public function handle(GestionUtilisateurs $gestion): int
    {
        $validateur = Validator::make(
            ['nom' => $this->argument('nom'), 'email' => $this->argument('email')],
            ['nom' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'unique:users,email']],
        );

        if ($validateur->fails()) {
            foreach ($validateur->errors()->all() as $erreur) {
                $this->error($erreur);
            }

            return self::FAILURE;
        }

        $existants = GestionUtilisateurs::superAdminsActifs();

        if ($existants > 0 && ! $this->option('force')) {
            $this->error("{$existants} SUPER_ADMIN actif(s) existe(nt) déjà : invitez les autres comptes depuis le back-office (Utilisateurs). Utilisez --force pour passer outre.");

            return self::FAILURE;
        }

        $user = $gestion->inviter(null, $this->argument('nom'), $this->argument('email'), Role::SuperAdmin);
        $lien = $gestion->dernierLien();

        $this->info("Compte SUPER_ADMIN créé pour {$user->email}.");
        $this->line('Lien d’invitation (valable '.config('raaga.backoffice.invitation_heures').' h, à usage unique) :');
        $this->line($lien);
        $this->warn('Transmettez ce lien à la personne par un canal sûr. Il a aussi été envoyé par courriel.');

        return self::SUCCESS;
    }
}
