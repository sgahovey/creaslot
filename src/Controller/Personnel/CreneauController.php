<?php

declare(strict_types=1);

namespace App\Controller\Personnel;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use App\Form\CreneauType;
use App\Repository\CreneauRepository;
use App\Repository\TypeRdvRepository;
use App\Security\CreneauVoter;
use App\Service\NotificationService;
use App\Service\SlotService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des créneaux proposés par le Personnel (US-2.x).
 *
 * Accès réservé à ROLE_PERSONNEL (IsGranted de classe) ; les actions sur un créneau
 * précis passent en plus par le CreneauVoter (seul le créateur ou le SUPER_ADMIN agit).
 */
#[IsGranted('ROLE_PERSONNEL')]
class CreneauController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notificationService,
        private readonly SlotService $slotService,
    ) {
    }

    /**
     * Liste paginée des créneaux du Personnel courant, filtrable par état.
     *
     * Bornée à l'utilisateur connecté : le Personnel ne gère que ses propres créneaux.
     */
    #[Route('/creneau', name: 'app_creneau_liste', methods: ['GET'])]
    public function liste(Request $request, CreneauRepository $creneauRepository): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $filtre = $request->query->getString('filtre', 'tous');
        $page = max(1, $request->query->getInt('page', 1));
        $creneaux = $creneauRepository->findByPersonnelWithFilters($utilisateur, $filtre, $page, 10);
        $totalCreneaux = count($creneaux);

        return $this->render('personnel/creneau/liste.html.twig', [
            'creneaux'      => $creneaux,
            'filtre'        => $filtre,
            'page'          => $page,
            'nbPages'       => max(1, (int) ceil($totalCreneaux / 10)),
            'totalCreneaux' => $totalCreneaux,
        ]);
    }

    /**
     * Vue agenda (calendrier) des créneaux ; les données sont chargées ensuite en
     * asynchrone via l'API calendrier (CreneauApiController).
     */
    #[Route('/creneau/agenda', name: 'app_creneau_agenda', methods: ['GET'])]
    public function agenda(TypeRdvRepository $typeRdvRepository): Response
    {
        return $this->render('personnel/creneau/agenda.html.twig', [
            'typesRdv' => $typeRdvRepository->findActifs(),
        ]);
    }

    /**
     * Modifie un créneau, avec des règles différentes selon qu'il est déjà réservé.
     *
     * Autorisé par le Voter EDIT. Un créneau passé ou annulé n'est plus modifiable.
     * Sur un créneau réservé, les horaires sont figés (seul le commentaire à l'auditeur
     * évolue) ; sinon les nouveaux horaires sont contrôlés contre les chevauchements du
     * Personnel avant d'être acceptés. Si le commentaire destiné à l'auditeur change,
     * ce dernier en est notifié par e-mail.
     */
    #[Route('/creneau/{id}/modifier', name: 'app_creneau_modifier', methods: ['GET', 'POST'])]
    public function modifier(Creneau $creneau, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CreneauVoter::EDIT, $creneau);

        if ($creneau->isPasse()) {
            $this->addFlash('error', 'Ce créneau est passé et ne peut plus être modifié.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        if (!$creneau->isEstActif()) {
            $this->addFlash('error', 'Ce créneau a été annulé et ne peut plus être modifié.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        $estReserve = $creneau->isReserve();
        $formulaire = $this->createForm(CreneauType::class, $creneau, ['creneau_reserve' => $estReserve]);

        if (!$estReserve) {
            $this->preremplirChampsNonMappes($formulaire, $creneau);
        }

        $commentaireAuditeurAvant = $creneau->getCommentaireAuditeur();

        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            if (!$estReserve) {
                $this->mettreAJourHoraires($formulaire, $creneau);
            }

            $conflits = $this->slotService->detecteChevauchements(
                $creneau->getUtilisateur(),
                $creneau->getDateDebut(),
                $creneau->getDateFin(),
                $creneau->getId(),
            );

            if ($conflits !== []) {
                $this->slotService->enregistrerPremierChevauchement($creneau, $conflits, 'modification');
                $this->addFlash('error', $this->slotService->construireMessageChevauchement($conflits[0]));

                return $this->render('personnel/creneau/modifier.html.twig', [
                    'formulaire' => $formulaire,
                    'creneau'    => $creneau,
                    'estReserve' => $estReserve,
                ]);
            }

            $this->entityManager->flush();

            $emailEnvoye = $estReserve && $commentaireAuditeurAvant !== $creneau->getCommentaireAuditeur();

            /** @var Utilisateur $utilisateur */
            $utilisateur = $this->getUser();
            $this->logger->info('Créneau modifié', [
                'creneau_id'         => $creneau->getId(),
                'user_id'            => $utilisateur->getId(),
                'commentaire_change' => $emailEnvoye,
            ]);

            if ($emailEnvoye) {
                $this->notificationService->notifierAuditeurCommentaireCreneau($creneau, $commentaireAuditeurAvant);
            }

            $messageFlash = $emailEnvoye
                ? 'Le créneau a été modifié. L\'auditeur a été notifié par email.'
                : 'Le créneau a été modifié.';
            $this->addFlash('success', $messageFlash);

            return $this->redirectToRoute('app_creneau_liste');
        }

        return $this->render('personnel/creneau/modifier.html.twig', [
            'formulaire' => $formulaire,
            'creneau'    => $creneau,
            'estReserve' => $estReserve,
        ]);
    }

    /**
     * Crée un créneau pour le Personnel courant.
     *
     * La date de fin est dérivée d'une durée prédéfinie ou d'une heure de fin libre.
     * Le créneau n'est persisté que s'il ne chevauche aucun autre créneau du même
     * Personnel : un conflit est tracé et renvoyé à l'utilisateur, sans enregistrement.
     */
    #[Route('/creneau/nouveau', name: 'app_creneau_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $creneau = new Creneau();
        $formulaire = $this->createForm(CreneauType::class, $creneau);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var Utilisateur $utilisateur */
            $utilisateur = $this->getUser();

            $dateDebut = $this->construireDateDebut(
                $formulaire->get('date')->getData(),
                $formulaire->get('heureDebut')->getData(),
            );
            $dateFin = $this->calculerDateFin(
                $dateDebut,
                $formulaire->get('duree')->getData(),
                $formulaire->get('heureFin')->getData(),
            );

            $creneau
                ->setUtilisateur($utilisateur)
                ->setDateDebut($dateDebut)
                ->setDateFin($dateFin);

            $conflits = $this->slotService->detecteChevauchements(
                $utilisateur,
                $creneau->getDateDebut(),
                $creneau->getDateFin(),
                null,
            );

            if ($conflits !== []) {
                $this->slotService->enregistrerPremierChevauchement($creneau, $conflits, 'creation');
                $this->addFlash('error', $this->slotService->construireMessageChevauchement($conflits[0]));

                return $this->render('personnel/creneau/nouveau.html.twig', [
                    'formulaire' => $formulaire,
                ]);
            }

            $this->entityManager->persist($creneau);
            $this->entityManager->flush();

            $this->logger->info('Créneau créé', [
                'creneau_id' => $creneau->getId(),
                'user_id'    => $utilisateur->getId(),
            ]);

            $this->addFlash('success', 'Le créneau a été créé.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        return $this->render('personnel/creneau/nouveau.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }

    /**
     * Supprime (désactive) un créneau et annule la réservation qu'il portait.
     *
     * Autorisé par le Voter DELETE, protégé par un jeton CSRF. La suppression est
     * logique (est_actif à false), jamais physique. Si le créneau était réservé, la
     * réservation active est annulée dans la foulée et son auditeur notifié par e-mail
     * (réservation capturée avant annulation, cf. DT-1).
     */
    #[Route('/creneau/{id}/supprimer', name: 'app_creneau_supprimer', methods: ['POST'])]
    public function supprimer(Creneau $creneau, Request $request): Response
    {
        $this->denyAccessUnlessGranted(CreneauVoter::DELETE, $creneau);

        if (($refus = $this->refusSupprimerCreneau($creneau, $request)) !== null) {
            return $refus;
        }

        // DT-1 : capture la Reservation ACTIVE AVANT annulation pour la passer
        // explicitement à notifierAuditeurSuppressionCreneau() après. Cohérent
        // avec OneToMany (évite l'ambiguïté sur quelle résa annulée notifier).
        $reservationAAnnuler = $creneau->getReservationActive();
        $auditeur = $reservationAAnnuler?->getUtilisateur();

        if ($reservationAAnnuler !== null) {
            $this->annulerReservationLiee($creneau);
        }

        $creneau->setEstActif(false);
        $this->entityManager->flush();

        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $this->logger->info('Créneau supprimé', [
            'creneau_id'           => $creneau->getId(),
            'user_id'              => $utilisateur->getId(),
            'reservation_annulee'  => $reservationAAnnuler !== null ? 'oui' : 'non',
            'notification_envoyee' => $reservationAAnnuler !== null ? 'oui' : 'non',
        ]);

        if ($reservationAAnnuler !== null) {
            $this->notificationService->notifierAuditeurSuppressionCreneau($creneau, $reservationAAnnuler);
        }

        $this->addFlash('success', $auditeur !== null
            ? "Le créneau a été supprimé et la réservation de {$auditeur->getPrenom()} {$auditeur->getNom()} a été annulée. L'auditeur a été notifié par email."
            : 'Le créneau a été supprimé.');

        return $this->redirectToRoute('app_creneau_liste');
    }

    private function refusSupprimerCreneau(Creneau $creneau, Request $request): ?Response
    {
        if (!$this->isCsrfTokenValid('supprimer-creneau-' . $creneau->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        if (!$creneau->isEstActif()) {
            $this->addFlash('error', 'Ce créneau est déjà désactivé.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        if ($creneau->isPasse()) {
            $this->addFlash('error', 'Ce créneau est passé et ne peut plus être supprimé.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        return null;
    }

    private function annulerReservationLiee(Creneau $creneau): void
    {
        $creneau->getReservationActive()
            ?->setStatut(StatutReservation::ANNULEE)
            ->setDateAnnulation(new \DateTimeImmutable())
            ->setMotifAnnulation('Créneau supprimé par le Personnel');
    }

    /**
     * @param FormInterface<Creneau> $formulaire
     */
    private function mettreAJourHoraires(FormInterface $formulaire, Creneau $creneau): void
    {
        $dateDebut = $this->construireDateDebut(
            $formulaire->get('date')->getData(),
            $formulaire->get('heureDebut')->getData(),
        );

        $creneau
            ->setDateDebut($dateDebut)
            ->setDateFin($this->calculerDateFin(
                $dateDebut,
                $formulaire->get('duree')->getData(),
                $formulaire->get('heureFin')->getData(),
            ));
    }

    /**
     * @param FormInterface<Creneau> $formulaire
     */
    private function preremplirChampsNonMappes(FormInterface $formulaire, Creneau $creneau): void
    {
        $dateDebut = $creneau->getDateDebut();
        $dateFin = $creneau->getDateFin();
        $dureeMin = (int) (($dateFin->getTimestamp() - $dateDebut->getTimestamp()) / 60);
        $dureeCode = in_array((string) $dureeMin, ['15', '30', '60'], true) ? (string) $dureeMin : 'custom';

        $formulaire->get('date')->setData($dateDebut);
        $formulaire->get('heureDebut')->setData($dateDebut);
        $formulaire->get('duree')->setData($dureeCode);

        if ($dureeCode === 'custom') {
            $formulaire->get('heureFin')->setData($dateFin);
        }
    }

    private function construireDateDebut(
        \DateTimeImmutable $date,
        \DateTimeImmutable $heure,
    ): \DateTimeImmutable {
        $dateDebut = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $date->format('Y-m-d') . ' ' . $heure->format('H:i'),
        );

        if (false === $dateDebut) {
            throw new \RuntimeException('Construction de la date de début du créneau impossible.');
        }

        return $dateDebut;
    }

    private function calculerDateFin(
        \DateTimeImmutable $dateDebut,
        string $duree,
        ?\DateTimeImmutable $heureFinPersonnalisee,
    ): \DateTimeImmutable {
        if ($duree === 'custom' && $heureFinPersonnalisee !== null) {
            $dateFin = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                $dateDebut->format('Y-m-d') . ' ' . $heureFinPersonnalisee->format('H:i'),
            );

            if (false === $dateFin) {
                throw new \RuntimeException('Calcul de la date de fin du créneau impossible.');
            }

            return $dateFin;
        }

        $minutes = is_numeric($duree) ? (int) $duree : 60;

        return $dateDebut->add(new \DateInterval("PT{$minutes}M"));
    }
}
