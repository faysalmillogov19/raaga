<?php

namespace App\Enums;

/** Titulaire d'un compte citoyen : personne physique, ou personne morale (entreprise, association, institution). */
enum TypeTitulaire: string
{
    case Physique = 'PHYSIQUE';
    case Morale = 'MORALE';

    /** Formes d'une personne morale. */
    public const FORMES = ['ENTREPRISE', 'ASSOCIATION', 'INSTITUTION'];

    public function label(): string
    {
        return __('public.titulaires.types.'.$this->value);
    }
}
