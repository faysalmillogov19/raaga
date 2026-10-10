<?php

namespace App\Http\Controllers\Compte;

use App\Support\Telephone;
use Closure;
use Illuminate\Http\Request;

trait ValideTelephone
{
    /**
     * Valide le champ « telephone » et le renvoie normalisé (« +22670123456 »).
     */
    protected function telephoneValide(Request $request): string
    {
        $message = __('public.compte.erreurs.telephone');

        $request->validate([
            'telephone' => ['required', 'string', function (string $attribut, mixed $valeur, Closure $echec) use ($message) {
                if (! Telephone::estValide($valeur)) {
                    $echec($message);
                }
            }],
        ], ['telephone.required' => $message]);

        return Telephone::normaliser($request->input('telephone'));
    }

    protected function codeValide(Request $request): string
    {
        $request->validate(
            ['code' => ['required', 'regex:/^\d{6}$/']],
            ['code.required' => __('public.compte.erreurs.code'), 'code.regex' => __('public.compte.erreurs.code')],
        );

        return $request->input('code');
    }
}
