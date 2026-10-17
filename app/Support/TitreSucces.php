<?php

namespace App\Support;

/**
 * Titre de la popup de succès, déduit de la fin de la clé du message (« admin.tranches.messages.soumise » : « Soumis »,
 * « …controle_recu » : « Paiement reçu »…). Aucune famille reconnue : null, la popup prend « Opération réussie ».
 */
final class TitreSucces
{
    /** Fin de clé (expression régulière) => titre (public.popups.titres_succes), dans l'ordre de priorité. */
    private const FAMILLES = [
        '/controle_recu$/' => 'paiement_recu',
        '/controle_incomplet$/' => 'paiement_incomplet',
        '/preuve_envoyee$/' => 'preuve',
        '/(^|_)(soumis|soumise|enregistre_soumis)$/' => 'soumis',
        '/(^|_)(confirme|confirmee|conforme)$/' => 'valide',
        '/(^|_)(rejete|rejetee|refusee)$/' => 'rejete',
        '/(^|_)(supprime|supprimee)$/' => 'supprime',
        '/(^|_)(retire|retiree)$/' => 'retire',
        '/(^|_)(reactive|reactivee)$/' => 'reactive',
        '/(^|_)(desactive|desactivee)$/' => 'desactive',
        '/(^|_)(propose|proposee)$/' => 'propose',
        '/(^|_)(demande|demandee)$/' => 'demande',
        '/^revision$/' => 'revision',
        '/(^|_)(ouvert|ouverte)$/' => 'ouvert',
        '/(^|_)(cloture|cloturee)$/' => 'cloture',
        '/(^|_)(attribue|attribuee)$/' => 'attribue',
        '/^(invite|lien)$/' => 'invitation',
        '/^relance$/' => 'relance',
        '/^declare$/' => 'declare',
        '/^envoye$/' => 'envoye',
        '/^douteux$/' => 'signale',
        '/^importe$/' => 'importe',
        '/^deverrouille$/' => 'deverrouille',
        '/(^|_)(enregistre|enregistree|enregistres|cree|creee|cree_brouillon|creee_brouillon|ajoutee|modifie|modifiee|renommee|brouillon_conserve)$/' => 'enregistre',
    ];

    public static function pour(string $cle): ?string
    {
        $fin = substr((string) strrchr('.'.$cle, '.'), 1);
        foreach (self::FAMILLES as $motif => $titre) {
            if (preg_match($motif, $fin)) {
                return __('public.popups.titres_succes.'.$titre);
            }
        }

        return null;
    }
}
