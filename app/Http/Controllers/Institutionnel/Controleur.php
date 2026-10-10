<?php

namespace App\Http\Controllers\Institutionnel;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\User;

/**
 * Base des écrans de l'espace institutionnel : tout est limité à l'institution de l'utilisateur
 * connecté. Une donnée d'une autre institution (ou d'un citoyen) renvoie 403.
 */
abstract class Controleur extends Controller
{
    protected function utilisateur(): User
    {
        return request()->user();
    }

    protected function institution(): Institution
    {
        return $this->utilisateur()->institution;
    }

    /** L'objet appartient-il à l'institution de l'utilisateur ? Sinon 403. */
    protected function verifierAppartenance(object $objet): void
    {
        abort_unless((int) $objet->institution_id === (int) $this->utilisateur()->institution_id, 403);
    }

    /** Actions réservées au signataire (signer, déclarer un virement, gérer le dossier et les utilisateurs). */
    protected function exigerSignataire(): void
    {
        abort_unless($this->utilisateur()->estSignataire(), 403);
    }
}
