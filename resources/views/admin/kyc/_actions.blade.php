{{-- Action d'une ligne des vérifications d'identité : examiner le dossier. --}}
<div class="flex justify-end">
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.kyc.dossier', $citoyen)"
                    aria-label="{{ __('admin.kyc.examiner_dossier', ['nom' => $citoyen->name]) }}">{{ __('admin.kyc.examiner') }}</x-admin.bouton>
</div>
