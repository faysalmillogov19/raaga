@php
    $classe = match ($statut->value) {
        'BROUILLON' => 'bo-etiquette-neutre',
        'SOUMISE', 'SOUMIS', 'EN_ATTENTE' => 'bo-etiquette-attention',
        'REJETE' => 'bo-etiquette-echec',
        default => 'bo-etiquette-succes',
    };
@endphp
<span class="bo-etiquette {{ $classe }}">{{ $statut->label() }}</span>
