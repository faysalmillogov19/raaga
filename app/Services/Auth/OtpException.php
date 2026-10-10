<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Refus d'envoi ou de vérification d'un code. Le message est déjà traduit et affichable.
 */
class OtpException extends RuntimeException {}
