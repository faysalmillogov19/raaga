<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Tableau de bord') }} — {{ $role->label() }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    {{ __('Bienvenue, :name. Vous êtes connecté en tant que :role.', ['name' => Auth::user()->name, 'role' => Auth::user()->role->label()]) }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
