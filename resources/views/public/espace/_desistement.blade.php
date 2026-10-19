{{-- Désistement (liste d'attente ou boutique retenue) : le bouton n'apparaît qu'après avoir lu ce qu'il implique. Variables : $inscriptionId, $texte. --}}
<details class="mt-3" data-desistement>
    <summary class="inline-flex min-h-[44px] cursor-pointer items-center font-semibold text-rouge">{{ __('public.boutiques.attente.desister') }}</summary>
    <form method="POST" action="{{ route('boutiques.desister', $inscriptionId) }}" class="mt-2">
        @csrf
        <p class="texte-carte">{{ $texte }}</p>
        <button type="submit" class="btn-secondaire mt-3">{{ __('public.boutiques.attente.desister') }}</button>
    </form>
</details>
