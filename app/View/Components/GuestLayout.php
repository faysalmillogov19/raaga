<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Gabarit des pages d'authentification (connexion, inscription, mot de passe…),
 * présentées avec la charte des pages publiques.
 */
class GuestLayout extends Component
{
    public function __construct(
        public ?string $titre = null,
        public ?string $intro = null,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
