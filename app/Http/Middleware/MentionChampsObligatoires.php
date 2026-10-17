<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/**
 * Mention « * Champ obligatoire » en haut de chaque formulaire qui contient au moins un champ obligatoire (astérisque
 * <x-requis />, repéré par data-requis), sur toutes les pages HTML : même rendu partout, aucun formulaire oublié.
 */
class MentionChampsObligatoires
{
    public function handle(Request $request, Closure $next): ReponseHttp
    {
        $reponse = $next($request);
        if (! $reponse instanceof Response || ! str_contains((string) $reponse->headers->get('Content-Type'), 'text/html')) {
            return $reponse;
        }
        $html = (string) $reponse->getContent();
        if (! str_contains($html, 'data-requis')) {
            return $reponse;
        }

        $mention = view('components.mention-requis')->render();
        $reponse->setContent(preg_replace_callback('#(<form\b[^>]*>)(.*?)(</form>)#s',
            fn (array $m) => str_contains($m[2], 'data-requis') && ! str_contains($m[2], 'data-mention-requis') ? $m[1].$mention.$m[2].$m[3] : $m[0], $html) ?? $html);

        return $reponse;
    }
}
