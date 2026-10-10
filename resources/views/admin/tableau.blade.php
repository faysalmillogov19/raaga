<x-admin.layout :titre="__('admin.tableau.titre')">
    <div class="bo-carte">
        <p class="text-[1.1rem] font-bold text-encre">{{ __('admin.tableau.bienvenue', ['nom' => Auth::user()->name]) }}</p>
        <p class="mt-1">{{ __('admin.tableau.profil', ['role' => Auth::user()->codeRole()->badge()]) }}</p>
        <p class="texte-carte mt-3">{{ __('admin.tableau.a_venir') }}</p>
    </div>

    @canany([\App\Enums\Droit::ConventionsVoir->value, \App\Enums\Droit::RegistreAVoir->value])
        @php
            $objectifA = \App\Services\Institutionnels\RegistreA::objectifEngagements();
            $engageA = \App\Services\Institutionnels\RegistreA::totalEngage();
            $capital = \App\Services\Institutionnels\RegistreA::repartitionCapital();
        @endphp
        <div class="bo-carte">
            <p class="font-semibold text-encre">{{ __('admin.tableau.a_traiter.engagements', [
                'engage' => format_md_fcfa($engageA), 'objectif' => format_md_fcfa($objectifA),
                'pct' => $objectifA > 0 ? format_nombre($engageA * 100 / $objectifA, 1)."\u{00A0}%" : '—',
                'a' => format_nombre($capital['a']), 'pct_a' => format_nombre($capital['pct_a'], 2)."\u{00A0}%",
                'b' => format_nombre($capital['b']), 'pct_b' => format_nombre($capital['pct_b'], 2)."\u{00A0}%",
            ]) }}</p>
        </div>
    @endcanany

    @canany([\App\Enums\Droit::ConventionsSaisir->value, \App\Enums\Droit::ConventionsConfirmer->value, \App\Enums\Droit::KybVerifier->value, \App\Enums\Droit::AppelsFondsRapprocher->value, \App\Enums\Droit::TranchesSaisir->value, \App\Enums\Droit::DividendesSaisir->value, \App\Enums\Droit::TranchesConfirmer->value, \App\Enums\Droit::DividendesConfirmer->value])
        <section class="bo-carte" aria-labelledby="a-traiter-titre">
            <h2 id="a-traiter-titre" class="!text-[1.15rem]">{{ __('admin.tableau.a_traiter.titre') }} @if ($aTraiter) <span class="bo-badge">{{ count($aTraiter) }}</span> @endif</h2>
            @if ($aTraiter)
                <ul class="mt-3 divide-y divide-ligne">
                    @foreach ($aTraiter as $element)
                        <li class="py-3">
                            <a href="{{ $element['url'] }}" class="font-bold text-vert-fonce underline underline-offset-2">{{ $element['libelle'] }}</a>
                            @if ($element['detail'])
                                <p class="texte-carte mt-1">{{ __('admin.tableau.a_traiter.motif', ['motif' => $element['detail']]) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="texte-carte mt-2">{{ __('admin.tableau.a_traiter.rien') }}</p>
            @endif
        </section>
    @endcanany

    <div class="bo-carte">
        <h2 class="!text-[1.15rem]">{{ __('admin.tableau.vos_menus') }}</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($menu as $groupe => $entrees)
                @foreach ($entrees as $entree)
                    <a href="{{ route($entree['route']) }}" class="flex items-center gap-3 rounded-charte border border-ligne p-3 font-semibold text-vert-fonce no-underline hover:border-vert">
                        <x-admin.icone :nom="$entree['icone']" />
                        {{ __('admin.menu.'.$entree['cle']) }}
                    </a>
                @endforeach
            @endforeach
        </div>
    </div>
</x-admin.layout>
