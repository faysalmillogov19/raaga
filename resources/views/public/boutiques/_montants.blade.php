{{-- Caution et avance sur deux lignes distinctes, chacune avec sa mention. Variables : $lignes (MontantsBoutique::lignes), $compact (facultatif). --}}
<dl @class(['space-y-2', 'text-[0.95rem]' => ! ($compact ?? false), 'text-sm' => $compact ?? false])>
    @foreach ($lignes as $ligne)
        <div>
            <div class="flex justify-between gap-3"><dt class="font-semibold text-encre">{{ $ligne['libelle'] }}</dt>
                <dd class="whitespace-nowrap text-right font-extrabold text-vert-fonce">{{ format_fcfa($ligne['montant']) }}</dd></div>
            <p class="text-sm text-gris">{{ $ligne['mention'] }}</p>
        </div>
    @endforeach
</dl>
