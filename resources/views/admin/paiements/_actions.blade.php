{{-- Action d'une ligne des dossiers de paiement : ouvrir le dossier (contrôle, relances, corrections). --}}
@php($type = $objet instanceof \App\Models\Souscription ? 'souscription' : 'caution')
<div class="flex justify-end">
    <x-admin.bouton variante="contour" taille="petit" :href="route('admin.paiements.dossier', [$type, $objet->id])"
                    aria-label="{{ __('admin.paiements.ouvrir_dossier', ['reference' => $objet->reference]) }}">{{ __('admin.paiements.ouvrir') }}</x-admin.bouton>
</div>
