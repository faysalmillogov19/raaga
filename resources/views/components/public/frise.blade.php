@props(['etapes'])

{{-- Frise verticale. $etapes = [['titre' => …, 'detail' => …|null, 'statut' => 'fait'|'en_cours'|'a_venir'], …] --}}
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
