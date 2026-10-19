@props(['etapes', 'variante' => 'verticale'])

{{--
    « verticale » (défaut) : $etapes = [['titre' => …, 'detail' => …|null, 'statut' => 'fait'|'en_cours'|'a_venir'], …].
    « dates » (thème « nuit ») : étapes en colonnes, pastille de date colorée, trait pointillé, carte sombre :
    $etapes = [['date' => texte de la pastille, 'titre' => …, 'detail' => …|null, 'couleur' => …|null], …].
--}}
@if ($variante === 'dates')
    <ol {{ $attributes->class(['nuit-frise']) }}>
        @foreach (array_values($etapes) as $i => $etape)
            <li class="nuit-frise-etape nuit-c-{{ \App\Support\CouleursNuit::nom($etape['couleur'] ?? null, $i) }}">
                <span class="nuit-frise-date">{{ $etape['date'] }}</span>
                <span class="nuit-frise-trait" aria-hidden="true"></span>
                <div class="nuit-carte nuit-frise-carte">
                    <p class="nuit-frise-titre">{{ $etape['titre'] }}</p>
                    @if (! empty($etape['detail']))<p class="nuit-frise-detail">{{ $etape['detail'] }}</p>@endif
                </div>
            </li>
        @endforeach
    </ol>
@else
<ol {{ $attributes->merge(['class' => 'relative']) }}>
    @foreach ($etapes as $etape)
        <li class="relative flex gap-4 pb-6 last:pb-0">
            @unless ($loop->last)
                <span class="absolute left-[11px] top-7 h-[calc(100%-1.75rem)] w-0.5 bg-ligne" aria-hidden="true"></span>
            @endunless
            <span @class([
                'relative mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2',
                'border-vert bg-vert text-white' => $etape['statut'] === 'fait',
                'border-jaune bg-jaune' => $etape['statut'] === 'en_cours',
                'border-gris-inactif bg-white' => $etape['statut'] === 'a_venir',
            ]) aria-hidden="true">
                @if ($etape['statut'] === 'fait')
                    <x-public.icone nom="check" class="h-3.5 w-3.5" />
                @endif
            </span>
            <div class="min-w-0">
                <p class="font-semibold text-encre">{{ $etape['titre'] }}</p>
                <p class="texte-carte">
                    @if (! empty($etape['detail'])){{ $etape['detail'] }} · @endif
                    <span @class(['font-semibold', 'text-vert-fonce' => $etape['statut'] === 'fait', 'text-encre' => $etape['statut'] !== 'fait'])>{{ __('public.offre.calendrier.'.$etape['statut']) }}</span>
                </p>
            </div>
        </li>
    @endforeach
</ol>
@endif
