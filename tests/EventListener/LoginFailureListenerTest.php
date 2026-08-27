<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\LoginFailureListener;
use App\Service\AlerteSecuriteService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Tests unitaires de l'écouteur d'échec d'authentification (OWASP A09).
 *
 * Les trois branches se distinguent par leur niveau de journalisation, et c'est
 * cette distinction qui est testée : un compte désactivé n'est pas un incident
 * (notice), une faute de frappe non plus (warning), mais un blocage après
 * plafonnement en est un (error). Le contexte journalisé se limite à l'adresse
 * tentée, conformément à la minimisation des données.
 *
 * Le logger est le seul collaborateur observé ; l'authenticator est un stub, il
 * n'est présent que pour satisfaire le constructeur de l'évènement.
 *
 * Le dernier test porte sur l'isolation de l'alerte de supervision : c'est le
 * point critique du dispositif, une supervision en panne ne devant ni interrompre
 * l'authentification, ni empêcher l'écriture de la trace.
 */
final class LoginFailureListenerTest extends TestCase
{
    private LoggerInterface&MockObject $securityLogger;

    private LoginFailureListener $listener;

    protected function setUp(): void
    {
        $this->securityLogger = $this->createMock(LoggerInterface::class);
        $this->listener = new LoginFailureListener(
            $this->securityLogger,
            $this->creerAlerteMuette(),
        );
    }

    public function test_compte_desactive_est_journalise_en_notice(): void
    {
        $this->securityLogger->expects($this->once())
            ->method('notice')
            ->with(
                'Tentative de connexion sur compte désactivé',
                ['email' => 'auditeur@example.test'],
            );
        $this->securityLogger->expects($this->never())->method('warning');
        $this->securityLogger->expects($this->never())->method('error');

        ($this->listener)($this->creerEvenement(new DisabledException(), 'auditeur@example.test'));
    }

    public function test_blocage_apres_plafonnement_est_journalise_en_error(): void
    {
        $this->securityLogger->expects($this->once())
            ->method('error')
            ->with(
                'Connexion bloquée après plafonnement des tentatives',
                ['email' => 'auditeur@example.test'],
            );
        $this->securityLogger->expects($this->never())->method('warning');
        $this->securityLogger->expects($this->never())->method('notice');

        ($this->listener)($this->creerEvenement(
            new TooManyLoginAttemptsAuthenticationException(5),
            'auditeur@example.test',
        ));
    }

    public function test_mauvais_identifiants_restent_journalises_en_warning(): void
    {
        $this->securityLogger->expects($this->once())
            ->method('warning')
            ->with(
                'Tentative de connexion échouée',
                ['email' => 'auditeur@example.test'],
            );
        $this->securityLogger->expects($this->never())->method('error');
        $this->securityLogger->expects($this->never())->method('notice');

        ($this->listener)($this->creerEvenement(
            new BadCredentialsException(),
            'auditeur@example.test',
        ));
    }

    public function test_le_contexte_journalise_se_limite_a_l_adresse_tentee(): void
    {
        $contexte = null;
        $this->securityLogger->expects($this->once())
            ->method('error')
            ->willReturnCallback(
                function (\Stringable|string $message, array $context) use (&$contexte): void {
                    $contexte = $context;
                },
            );

        ($this->listener)($this->creerEvenement(
            new TooManyLoginAttemptsAuthenticationException(5),
            'auditeur@example.test',
        ));

        $this->assertSame(['email'], array_keys((array) $contexte));
    }

    public function test_adresse_absente_du_formulaire_donne_un_contexte_vide(): void
    {
        $this->securityLogger->expects($this->once())
            ->method('error')
            ->with(
                'Connexion bloquée après plafonnement des tentatives',
                ['email' => ''],
            );

        ($this->listener)($this->creerEvenement(
            new TooManyLoginAttemptsAuthenticationException(5),
            null,
        ));
    }

    public function test_l_echec_de_l_alerte_n_interrompt_pas_le_traitement(): void
    {
        $this->securityLogger->expects($this->once())
            ->method('error')
            ->with(
                'Connexion bloquée après plafonnement des tentatives',
                ['email' => 'auditeur@example.test'],
            );

        $ecouteur = new LoginFailureListener(
            $this->securityLogger,
            $this->creerAlerteEnEchec(),
        );

        $ecouteur($this->creerEvenement(
            new TooManyLoginAttemptsAuthenticationException(5),
            'auditeur@example.test',
        ));
    }

    /**
     * Service d'alerte dont l'appel sortant échoue systématiquement.
     *
     * Le logger du service est distinct de celui de l'écouteur : on veut isoler
     * l'assertion sur la ligne de journal produite par l'écouteur.
     */
    private function creerAlerteEnEchec(): AlerteSecuriteService
    {
        $clientEnEchec = new MockHttpClient(
            static fn (): never => throw new TransportException('supervision injoignable'),
        );

        return new AlerteSecuriteService(
            $clientEnEchec,
            $this->createMock(LoggerInterface::class),
            'test',
            'http://uptime-kuma:3001',
            'jeton-de-test',
        );
    }

    /**
     * Service d'alerte non configuré, donc silencieux : c'est l'état des autres
     * tests, qui portent sur le niveau de journalisation et non sur l'alerte.
     */
    private function creerAlerteMuette(): AlerteSecuriteService
    {
        return new AlerteSecuriteService(
            new MockHttpClient(),
            $this->createMock(LoggerInterface::class),
            'test',
        );
    }

    private function creerEvenement(AuthenticationException $exception, ?string $email): LoginFailureEvent
    {
        $request = new Request();
        if (null !== $email) {
            $request->request->set('email', $email);
        }

        return new LoginFailureEvent(
            $exception,
            $this->createStub(AuthenticatorInterface::class),
            $request,
            null,
            'main',
        );
    }
}
