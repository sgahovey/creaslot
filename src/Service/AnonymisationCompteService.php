<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use App\Exception\EngagementsFutursException;
use App\Repository\CreneauRepository;
use App\Repository\ReservationRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Droit à l'effacement RGPD (art. 17) par ANONYMISATION self-service (US-12.3).
 *
 * On ne supprime rien physiquement : les créneaux et réservations passés restent
 * intacts (intégrité de l'historique, contraintes FK RESTRICT respectées). Seules
 * les données personnelles portées par l'entité Utilisateur sont neutralisées, et
 * le compte devient inutilisable (mot de passe aléatoire, désactivé).
 *
 * L'anonymisation est refusée tant que l'utilisateur a des engagements à venir :
 * il doit d'abord les solder (aucune annulation en cascade automatique).
 */
final readonly class AnonymisationCompteService
{
    /** Adresse email de remplacement : neutre et unique (indexée sur l'id du compte). */
    private const string EMAIL_ANONYME_PATRON = 'anonymise-%d@creaslot.local';

    /** Valeur de remplacement des nom et prénom. */
    private const string VALEUR_ANONYME = 'Anonymisé';

    /**
     * Champs de historique_utilisateur (US-12.1) porteurs de données personnelles,
     * purgés après anonymisation pour un effacement réellement complet.
     *
     * @var list<string>
     */
    private const array CHAMPS_PII_HISTORIQUE = ['email', 'nom', 'prenom'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ReservationRepository $reservationRepository,
        private CreneauRepository $creneauRepository,
        private JournalAdminService $journalAdminService,
    ) {
    }

    /**
     * Vrai si l'utilisateur a des engagements à venir qui bloquent l'anonymisation :
     * une réservation active future (Auditeur) ou un créneau actif futur/en cours
     * qu'il propose (Personnel). Les deux sont vérifiés, quel que soit le rôle.
     */
    public function aDesEngagementsFuturs(Utilisateur $utilisateur): bool
    {
        $maintenant = new \DateTimeImmutable();

        return $this->reservationRepository->aReservationActiveAVenir($utilisateur, $maintenant)
            || $this->creneauRepository->existeCreneauActifFuturOuEnCours($utilisateur, $maintenant);
    }

    /**
     * Anonymise le compte dans une transaction atomique. Lève EngagementsFutursException
     * si des engagements à venir subsistent (rien n'est alors modifié).
     */
    public function anonymiser(Utilisateur $utilisateur): void
    {
        if ($this->aDesEngagementsFuturs($utilisateur)) {
            throw new EngagementsFutursException();
        }

        $this->entityManager->beginTransaction();

        try {
            $this->neutraliserDonneesPersonnelles($utilisateur);
            // Trace enregistrée APRÈS neutralisation : le journal fige des libellés déjà
            // anonymisés (« Anonymisé Anonymisé ») — aucune donnée personnelle n'y entre.
            $this->journalAdminService->enregistrer(TypeActionJournal::COMPTE_ANONYMISATION, $utilisateur, $utilisateur);
            $this->entityManager->flush();
            $this->effacerHistoriquePii($utilisateur);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    private function neutraliserDonneesPersonnelles(Utilisateur $utilisateur): void
    {
        $id = $utilisateur->getId();
        if ($id === null) {
            throw new \LogicException("Impossible d'anonymiser un compte non persisté.");
        }

        $utilisateur
            ->setEmail(sprintf(self::EMAIL_ANONYME_PATRON, $id))
            ->setNom(self::VALEUR_ANONYME)
            ->setPrenom(self::VALEUR_ANONYME)
            ->setMotDePasseHash($this->genererHashInutilisable($utilisateur))
            ->setEstActif(false);
    }

    /**
     * Hash argon2id d'un secret aléatoire que personne ne connaît : le format reste
     * cohérent avec la colonne mot_de_passe_hash, mais aucun mot de passe ne pourra
     * jamais correspondre — le compte est définitivement inconnectable.
     */
    private function genererHashInutilisable(Utilisateur $utilisateur): string
    {
        return $this->passwordHasher->hashPassword($utilisateur, bin2hex(random_bytes(32)));
    }

    /**
     * Purge les lignes personnelles de historique_utilisateur pour ce compte.
     *
     * Le trigger SQL trg_historique_utilisateur (US-12.1) a copié les anciennes valeurs
     * (email, nom, prénom) lors de l'UPDATE d'anonymisation. Ce DELETE s'exécute dans la
     * MÊME transaction, donc après le trigger, et retire ces lignes nominatives. Les
     * lignes non-personnelles (role, est_actif, mot_de_passe) sont conservées : elles ne
     * référencent que l'id (pseudonyme). Table non mappée par l'ORM → DBAL paramétré.
     */
    private function effacerHistoriquePii(Utilisateur $utilisateur): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM historique_utilisateur WHERE utilisateur_id = :id AND champ_modifie IN (:champs)',
            ['id'     => $utilisateur->getId(), 'champs' => self::CHAMPS_PII_HISTORIQUE],
            ['champs' => ArrayParameterType::STRING],
        );
    }
}
