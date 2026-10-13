<?php

namespace App\Support;

/**
 * Caution et avance d'une boutique, toujours présentées sur deux lignes distinctes avec leur mention :
 * la caution est remboursable selon le règlement ; l'avance est faite de loyers payés d'avance.
 */
final class MontantsBoutique
{
    /** @return list<array{libelle: string, montant: int, mention: string}> */
    public static function lignes(?int $caution, int $avance, ?int $moisAvance): array
    {
        if ($caution === null) {
            return [];
        }
        $m = __('public.boutiques.montants');
        $lignes = [['libelle' => $m['caution'], 'montant' => $caution, 'mention' => $m['mention_caution']]];
        if ($avance > 0) {
            $lignes[] = ['libelle' => $m['avance'], 'montant' => $avance,
                'mention' => trans_choice('public.boutiques.montants.mention_avance', (int) $moisAvance, ['mois' => format_nombre((int) $moisAvance)])];
        }

        return $lignes;
    }
}
