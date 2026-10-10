@props(['etat', 'lien' => true])

@php
    /** @var \App\Services\Publique\EtatCollecte $etat */
    $pct = $etat->pourcentage();
    $jours = $etat->joursRestants();
@endphp

<section {{ $attributes->merge(['class' => 'carte-blanche']) }} aria-label="{{ __('public.jauge.titre', ['tranche' => $etat->nomTranche]) }}">
    <div class="jauge-entete">
        <span>{{ __('public.jauge.souscrit') }}</span>
        <span>{{ __('public.jauge.objectif', ['objectif' => format_md_fcfa($etat->objectifMontant, null)]) }}</span>
    </div>

    <p class="jauge-montant">{{ format_fcfa($etat->montantSouscrit) }}</p>

    <div class="jauge-barre"
         role="progressbar"
         aria-label="{{ __('public.jauge.progression') }}"
         aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min($pct, 100) }}"
         aria-valuetext="{{ __('public.jauge.atteint', ['pct' => format_pourcentage($etat->montantSouscrit, $etat->objectifMontant)]) }}">
        <span style="width: {{ min(100, round($etat->montantSouscrit * 100 / max(1, $etat->objectifMontant), 1)) }}%"></span>
    </div>
    <p class="jauge-pct">{!! __('public.jauge.atteint', ['pct' => '<strong>'.e(format_pourcentage($etat->montantSouscrit, $etat->objectifMontant)).'</strong>']) !!}</p>

    <dl class="jauge-chiffres">
        @foreach ([
            [__('public.jauge.souscripteurs'), format_nombre($etat->nbSouscripteurs)],
            [__('public.jauge.actions'), format_nombre($etat->nbActions)],
            [__('public.jauge.restants'), $jours > 0 ? __('public.jauge.jours', ['n' => format_nombre($jours)]) : __('public.jauge.cloturee')],
        ] as [$libelle, $valeur])
            <div class="flex flex-col-reverse">
                <dt>{{ $libelle }}</dt>
                <dd>{{ $valeur }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($lien)
        <a href="{{ route('transparence') }}" class="lien-fort">{{ __('public.jauge.suivre') }} <x-icon name="fleche" class="h-4 w-4" /></a>
    @endif
</section>
