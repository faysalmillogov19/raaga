@php($i = __('institutionnel.bo.institutions'))

<x-admin.layout :titre="$i['nouvelle']" :fil="[['libelle' => $i['titre'], 'url' => route('admin.institutions')], ['libelle' => $i['nouvelle']]]">
    <form method="POST" action="{{ route('admin.institutions.store') }}" class="bo-carte !mt-0 grid gap-4 lg:grid-cols-2" novalidate>
        @csrf
        <x-admin.champ nom="denomination" :libelle="$i['champs']['denomination']" required maxlength="200" class="lg:col-span-2" />
        @foreach (['sigle', 'forme_juridique', 'rccm', 'ifu', 'secteur'] as $champ)
            <x-admin.champ :nom="$champ" :libelle="$i['champs'][$champ]" />
        @endforeach
        <x-admin.champ nom="adresse" :libelle="$i['champs']['adresse']" class="lg:col-span-2" />

        <div class="border-t border-ligne pt-4 lg:col-span-2">
            <p class="font-bold text-encre">{{ __('institutionnel.statuts_role.institution_signataire') }}</p>
        </div>
        <x-admin.champ nom="signataire_nom" :libelle="$i['champs']['signataire_nom']" required />
        <x-admin.champ nom="signataire_email" :libelle="$i['champs']['signataire_email']" type="email" required />

        <div class="flex gap-3 lg:col-span-2">
            <x-admin.bouton variante="principal" type="submit">{{ $i['creer'] }}</x-admin.bouton>
            <x-admin.bouton variante="contour" href="{{ route('admin.institutions') }}">{{ __('admin.confirmation.annuler') }}</x-admin.bouton>
        </div>
    </form>
</x-admin.layout>
