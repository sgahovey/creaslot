<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levee lorsqu'un utilisateur tente d'anonymiser son compte alors qu'il a encore
 * des engagements a venir : reservations actives sur des creneaux futurs (Auditeur)
 * ou creneaux futurs qu'il propose (Personnel).
 *
 * L'anonymisation est refusee : l'utilisateur doit d'abord solder ses rendez-vous
 * ou creneaux a venir. Aucune annulation en cascade n'est declenchee (US-12.3,
 * RGPD art. 17 : l'effacement ne doit pas rompre les engagements pris envers des tiers).
 */
final class EngagementsFutursException extends \RuntimeException
{
}
