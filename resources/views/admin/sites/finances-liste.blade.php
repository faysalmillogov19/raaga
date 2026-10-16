{{-- Finances des sites : un site par ligne, avec l'état de ses données financières ; chaque site mène à sa fiche financière. --}}
@php($f = __('admin.sites.finances.liste'))

<x-admin.layout :titre="$f['titre']">
    <div class="bo-carte !mt-0" data-finances-sites>
        <p class="texte-carte max-w-3xl">{{ $f['intro'] }}</p>

        <x-tableau :t="$tableau" :libelle="$f['titre']" class="mt-4" />
    </div>
</x-admin.layout>
