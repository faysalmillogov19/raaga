<?php

namespace App\Services\Sms;

use App\Contracts\SmsProvider;
use App\Services\Plateforme\ParametresPlateforme;
use App\Support\Telephone;

/**
 * Point d'envoi unique des SMS : l'expéditeur et les variables publiques ({numeroVert},
 * {urlSiteOfficiel}, {expediteurSms}, {nomPlateforme}) viennent des paramètres de la plateforme.
 *
 * Modèle : « Code {code}.[ Aide : {numeroVert}] » · un segment entre crochets disparaît
 * si l'une de ses variables est vide, pour ne jamais envoyer « Aide :  » ou « null ».
 */
class EnvoiSms
{
    public function __construct(
        private readonly SmsProvider $provider,
        private readonly ParametresPlateforme $parametres,
    ) {}

    /**
     * @param  array<string, string|int|null>  $variables  variables propres au message ({code}, {minutes}…)
     */
    public function envoyer(string $telephone, string $modele, array $variables = []): void
    {
        $expediteur = $this->parametres->get('identite.expediteurSms');

        $this->provider->envoyer($telephone, $this->rendre($modele, $variables), $expediteur);
    }

    /**
     * @param  array<string, string|int|null>  $variables
     */
    public function rendre(string $modele, array $variables = []): string
    {
        $numeroVert = $this->parametres->get('contacts.numeroVert');
        $valeurs = [
            'numeroVert' => $numeroVert ? Telephone::formaterLocal($numeroVert) : null,
            'urlSiteOfficiel' => $this->parametres->get('identite.urlSiteOfficiel'),
            'expediteurSms' => $this->parametres->get('identite.expediteurSms'),
            'nomPlateforme' => $this->parametres->get('identite.nomPlateforme'),
            ...$variables,
        ];

        $remplacer = fn (string $texte) => preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($valeurs[$m[1]] ?? ''), $texte);

        // Segments facultatifs : retirés si une variable qu'ils contiennent est vide.
        $texte = preg_replace_callback('/\[([^\[\]]*)\]/', function ($m) use ($valeurs, $remplacer) {
            preg_match_all('/\{(\w+)\}/', $m[1], $noms);
            foreach ($noms[1] as $nom) {
                if (($valeurs[$nom] ?? null) === null || $valeurs[$nom] === '') {
                    return '';
                }
            }

            return $remplacer($m[1]);
        }, $modele);

        return trim($remplacer($texte));
    }
}
