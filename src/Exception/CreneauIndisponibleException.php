<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levee lorsque le re-check post-verrou detecte que le creneau vient
 * d'etre reserve par un autre auditeur (acces concurrent).
 */
final class CreneauIndisponibleException extends \RuntimeException
{
}
