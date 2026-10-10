@props(['statut'])

{{-- Étiquette de statut (pièce, institution, appel de fonds, virement, convention). --}}
@php
    $succes = ['VALIDE', 'VERIFIEE', 'ACTIVE', 'RECU', 'RAPPROCHEE', 'CONFIRMEE'];
    $echec = ['REJETE', 'REJETEE', 'EN_RETARD', 'SUSPENDUE'];
@endphp
<span @class(['bo-etiquette', 'bo-etiquette-succes' => in_array($statut->value, $succes, true), 'bo-etiquette-echec' => in_array($statut->value, $echec, true)])>{{ $statut->label() }}</span>
