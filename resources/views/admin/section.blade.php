<x-admin.layout :titre="__('admin.menu.'.$cle)" :fil="[['libelle' => __('admin.menu.'.$cle)]]">
    <div class="bo-carte">
        <p class="font-bold text-encre">{{ __('admin.section.en_construction') }}</p>
        <p class="texte-carte mt-1">{{ __($module === 'admin' ? 'admin.section.arrive_admin' : 'admin.section.arrive', ['etape' => $etape]) }}</p>
    </div>

    <div class="bo-carte">
        <h2 class="!text-[1.15rem]">
            {{ __('admin.section.vos_droits') }}
            @if ($lectureSeule)
                <span class="bo-etiquette bo-etiquette-succes ml-2 align-middle">{{ __('admin.section.lecture_seule') }}</span>
            @endif
        </h2>
        <ul class="bo-droits mt-2">
            @foreach ($droits as $droit)
                <li><x-admin.icone nom="check" class="h-4 w-4" /> <span>{{ $droit->label() }}</span></li>
            @endforeach
        </ul>
    </div>
</x-admin.layout>
