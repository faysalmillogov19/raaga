{{-- RAAGA Invest en chiffres : manifestations d'intérêt, total des intentions, montant réellement collecté (0 tant que la souscription est fermée). --}}
@php($i = __('admin.interets.indicateurs'))
<div class="adm-cartes" data-indicateurs-invest>
    <a href="{{ route('admin.interets') }}" class="adm-indicateur" data-invest="interets">
        <span class="adm-indicateur-libelle">{{ $i['nombre'] }}</span>
        <span class="adm-indicateur-valeur">{{ format_nombre($indicateurs['nombre']) }}</span>
    </a>
    <a href="{{ route('admin.interets') }}" class="adm-indicateur" data-invest="intentions">
        <span class="adm-indicateur-libelle">{{ $i['intentions'] }}</span>
        <span class="adm-indicateur-valeur">{{ format_fcfa($indicateurs['intentions']) }}</span>
    </a>
    <div class="adm-indicateur" data-invest="collecte">
        <span class="adm-indicateur-libelle">{{ $i['collecte'] }}</span>
        <span class="adm-indicateur-valeur">{{ format_fcfa($indicateurs['collecte']) }}</span>
    </div>
</div>
