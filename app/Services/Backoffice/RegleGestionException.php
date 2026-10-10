<?php

namespace App\Services\Backoffice;

use RuntimeException;

/**
 * Action refusée par une règle de gestion (message déjà traduit, affichable tel quel).
 */
class RegleGestionException extends RuntimeException {}
