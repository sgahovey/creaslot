<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\StatutReservation;

/**
 * Levee lorsqu'une reservation ne peut pas etre annulee (deja annulee ou passee).
 * Porte le statut courant pour que l'appelant choisisse le message adapte.
 */
final class ReservationNonAnnulableException extends \RuntimeException
{
    public function __construct(private readonly StatutReservation $statut)
    {
        parent::__construct();
    }

    public function getStatut(): StatutReservation
    {
        return $this->statut;
    }
}
