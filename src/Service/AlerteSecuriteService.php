<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Signale à la supervision qu'un évènement de sécurité vient de se produire (OWASP A09).
 *
 * ─ Pourquoi ce service existe ─────────────────────────────────────────────
 * Le canal de journalisation `security` conserve la trace d'un blocage, mais
 * personne ne lit un fichier de journal en continu. Sans notification poussée,
 * une attaque par force brute n'est découverte qu'à la prochaine consultation
 * manuelle. Ce service comble l'écart entre « c'est tracé » et « on le sait ».
 *
 * ─ Isolation stricte ──────────────────────────────────────────────────────
 * L'appel sortant est enveloppé dans un try/catch qui AVALE toute exception,
 * sur le modèle de NotificationService::envoyerEtTracer() : une supervision
 * indisponible ne doit jamais empêcher une authentification d'aboutir. La durée
 * de l'appel est bornée pour la même raison, un point d'entrée qui ne répond
 * plus ne pouvant pas se transformer en attente côté navigateur.
 *
 * ─ Minimisation (RGPD art. 5.1.c) ─────────────────────────────────────────
 * Le message envoyé dit QU'UN blocage a eu lieu, jamais QUI. L'adresse tentée
 * reste dans le journal applicatif et ne sort pas de l'application : la
 * supervision est un service tiers du point de vue des données, il n'a pas à
 * connaître d'adresse. Le jeton d'authentification du point d'entrée n'est lui
 * non plus jamais journalisé, alors qu'il est contenu dans l'URL sollicitée.
 *
 * ─ Convention du moniteur ─────────────────────────────────────────────────
 * Le moniteur Uptime Kuma est de type « push » et configuré en mode inversé
 * (upside down). L'absence de sollicitation y vaut donc situation normale, et
 * la sollicitation vaut incident : c'est ce qui permet à un moniteur conçu pour
 * surveiller une absence de battement de signaler, ici, la survenue d'un
 * évènement. Le statut poussé est par conséquent `up`, que le mode inversé
 * transforme en alerte.
 */
final readonly class AlerteSecuriteService
{
    /**
     * Durée maximale accordée à l'appel sortant, en secondes.
     *
     * Volontairement courte : l'appel se fait sur le réseau Docker interne, où
     * la latence normale est de l'ordre de la milliseconde. Ce plafond ne sert
     * qu'à garantir qu'un point d'entrée figé n'ajoute pas d'attente perceptible
     * à la réponse d'authentification.
     */
    private const DUREE_MAXIMALE_SECONDES = 2.0;

    private const CHEMIN_POINT_ENTREE_PUSH = '/api/push/';

    private const MESSAGE_BLOCAGE_APRES_PLAFONNEMENT = 'Blocage apres plafonnement des tentatives de connexion';

    /**
     * URL complète du point d'entrée, jeton compris.
     *
     * Chaîne vide lorsque l'URL de base ou le jeton n'est pas fourni : c'est
     * l'état normal en développement, en test et dans l'intégration continue,
     * où aucune supervision n'existe. Aucun appel n'est alors tenté.
     */
    private string $urlPushBlocageConnexion;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $securityLogger,
        #[Autowire('%env(APP_ENVIRONMENT_LABEL)%')]
        private string $etiquetteEnvironnement,
        #[Autowire('%env(default::SUPERVISION_URL_BASE)%')]
        ?string $urlBaseSupervision = null,
        #[Autowire('%env(default::SUPERVISION_JETON_BLOCAGE_CONNEXION)%')]
        ?string $jetonBlocageConnexion = null,
    ) {
        $this->urlPushBlocageConnexion = $this->composeUrlPush($urlBaseSupervision, $jetonBlocageConnexion);
    }

    /**
     * Signale qu'une connexion vient d'être refusée après plafonnement.
     *
     * Ne lève jamais d'exception et ne retourne rien : l'appelant se trouve sur
     * le chemin d'authentification, il ne doit avoir ni à attendre, ni à gérer
     * un échec de supervision.
     */
    public function signaleBlocageApresPlafonnement(): void
    {
        if ($this->supervisionEstConfiguree()) {
            $this->sollicitePointDEntreePush(self::MESSAGE_BLOCAGE_APRES_PLAFONNEMENT);
        }
    }

    private function supervisionEstConfiguree(): bool
    {
        return '' !== $this->urlPushBlocageConnexion;
    }

    private function sollicitePointDEntreePush(string $message): void
    {
        $parametres = [
            'status' => 'up',
            'msg'    => $message . ' (' . $this->etiquetteEnvironnement . ')',
        ];

        try {
            $reponse = $this->httpClient->request('GET', $this->urlPushBlocageConnexion, [
                'query'        => $parametres,
                'timeout'      => self::DUREE_MAXIMALE_SECONDES,
                'max_duration' => self::DUREE_MAXIMALE_SECONDES,
            ]);

            $codeHttp = $reponse->getStatusCode();

            if (Response::HTTP_OK !== $codeHttp) {
                $this->securityLogger->warning(
                    'Alerte de supervision refusée',
                    ['code_http' => $codeHttp],
                );
            }
        } catch (\Throwable $exception) {
            // L'exception est tracée puis avalée : l'authentification en cours
            // prime sur la supervision. Le contexte se limite à la classe de
            // l'exception, l'URL contenant le jeton du point d'entrée.
            $this->securityLogger->warning(
                'Alerte de supervision injoignable',
                ['exception' => $exception::class],
            );
        }
    }

    private function composeUrlPush(?string $urlBase, ?string $jeton): string
    {
        if (null === $urlBase || '' === $urlBase || null === $jeton || '' === $jeton) {
            return '';
        }

        return rtrim($urlBase, '/') . self::CHEMIN_POINT_ENTREE_PUSH . rawurlencode($jeton);
    }
}
