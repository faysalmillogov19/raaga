{{-- Manifestations d'intérêt RAAGA Invest : indicateurs (nombre, total des intentions, montant réellement collecté), liste avec export. --}}
@php($i = __('admin.interets'))

<x-admin.layout :titre="$i['titre']" :sous-titre="$i['intro']" :fil="[['libelle' => $i['titre']]]">
    @include('admin.interets._indicateurs', ['indicateurs' => $indicateurs])

    <div class="bo-carte">
        <x-tableau :t="$tableau" :libelle="$i['titre']" />
    </div>
</x-admin.layout>
