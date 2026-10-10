<x-admin.carte-connexion :espace="$espace" :titre="__('admin.connexion.codes_titre')" :intro="__('admin.connexion.codes_intro')">
    <ul class="formulaire grid grid-cols-2 gap-2 font-mono text-[1.05rem] font-bold text-encre">
        @foreach ($codes as $code)
            <li class="rounded-charte border border-ligne bg-fond px-3 py-2 text-center">{{ $code }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route($espace->route('connexion.codes-secours')) }}">
        @csrf
        <button type="submit" class="btn-plein">{{ __('admin.connexion.codes_confirmer') }}</button>
    </form>
</x-admin.carte-connexion>
