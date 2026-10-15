{{--
    Aperçu d'une section des paramètres de la plateforme, dans le style du site public. Les valeurs
    saisies (non enregistrées) remplacent celles de la section le temps du rendu : l'en-tête, le
    bandeau et le pied de page du gabarit en tiennent compte.
--}}
@php
    $titreSection = __('admin.plateforme.sections.'.$section);
    $projet = plateforme('projet');
    $maintenance = plateforme('messages.modeMaintenance');
@endphp

<x-public.layout :titre="__('admin.plateforme.apercu_titre', ['section' => $titreSection])">
    <div class="bandeau-annonce-alerte">
        <p class="conteneur py-2 text-center font-bold">{{ __('admin.plateforme.apercu_bandeau') }} · {{ $titreSection }}</p>
    </div>

    @switch($section)
        @case('contacts')
        @case('siege')
        @case('contacts_specialises')
            <div class="conteneur max-w-xl py-10">
                <div class="carte p-5">
                    <h2 class="!text-lg">{{ __('public.faq.contact.titre') }}</h2>
                    <x-public.contacts />
                </div>
            </div>
            @break

        @case('mentions')
            <div class="conteneur max-w-3xl py-10">
                <h1>{{ __('public.legal.pages.mentions') }}</h1>
                <div class="mt-6">@include('public.partials.legal-contenu', ['page' => 'mentions'])</div>
            </div>
            @break

        @case('projet')
            <section class="section">
                <div class="conteneur">
                    <dl class="chiffres-cles" style="grid-template-columns: repeat(2, 1fr)">
                        @if ($projet['nombreBoutiques'] !== null)
                            <div class="flex flex-col-reverse"><dt>{{ __('public.accueil.chiffres.boutiques_detail') }}</dt><dd>{{ format_nombre($projet['nombreBoutiques']) }}</dd></div>
                        @endif
                        @if ($projet['coutTotalProjet'] !== null)
                            <div class="flex flex-col-reverse"><dt>{{ __('public.accueil.chiffres.cout_detail') }}</dt><dd>{{ format_md($projet['coutTotalProjet']) }}</dd></div>
                        @endif
                    </dl>
                </div>
            </section>
            @include('public.partials.financement', ['projet' => $projet])
            @break

        @case('messages')
            @if ($maintenance['actif'])
                @include('public.partials.maintenance-contenu', ['message' => $maintenance['message'], 'finPrevue' => $maintenance['finPrevue'] ? \Carbon\Carbon::parse($maintenance['finPrevue']) : null])
            @endif
            @if ($antiFraude = plateforme('messages.messageAntiFraude'))
                <div class="conteneur max-w-xl py-8"><x-public.alerte variante="attention">{{ $antiFraude }}</x-public.alerte></div>
            @endif
            @break

        @case('site_public')
            {{-- Diapositives actives, dates d'affichage ignorées : l'aperçu montre aussi celles programmées. --}}
            <x-public.carrousel :diapositives="\App\Support\Diapositives::affichees(plateforme('site_public.carrousel'), periode: false)" :url-candidater="route('inscription')" />
            @break

        @default
            <div class="conteneur py-10">
                <p class="texte-carte text-center">{{ __('admin.plateforme.sections.'.$section) }} : en-tête et pied de page ci-dessus et ci-dessous.</p>
            </div>
    @endswitch
</x-public.layout>
