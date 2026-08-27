<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AlerteSecuriteService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Tests unitaires de l'alerte de supervision (OWASP A09).
 *
 * Trois propriétés y sont vérifiées, dans cet ordre d'importance :
 * l'échec de l'appel sortant ne se propage jamais, aucune donnée personnelle
 * ne quitte l'application, et le jeton du point d'entrée n'est jamais journalisé.
 */
final class AlerteSecuriteServiceTest extends TestCase
{
    private const URL_BASE = 'http://uptime-kuma:3001';

    private const JETON = 'jeton-de-test-abc123';

    private LoggerInterface&MockObject $securityLogger;

    protected function setUp(): void
    {
        $this->securityLogger = $this->createMock(LoggerInterface::class);
    }

    public function test_l_echec_de_l_appel_ne_se_propage_pas(): void
    {
        $clientEnEchec = new MockHttpClient(
            static fn (): never => throw new TransportException('supervision injoignable'),
        );

        $this->securityLogger->expects($this->once())
            ->method('warning')
            ->with('Alerte de supervision injoignable', ['exception' => TransportException::class]);

        $service = $this->creerService($clientEnEchec);

        $service->signaleBlocageApresPlafonnement();
    }

    public function test_le_depassement_du_delai_ne_se_propage_pas(): void
    {
        $clientQuiExpire = new MockHttpClient(
            static fn (): never => throw new TransportException('délai maximal dépassé'),
        );

        $this->securityLogger->expects($this->once())->method('warning');

        $this->creerService($clientQuiExpire)->signaleBlocageApresPlafonnement();
    }

    public function test_une_reponse_en_erreur_est_tracee_sans_exception(): void
    {
        $clientRefusant = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $this->securityLogger->expects($this->once())
            ->method('warning')
            ->with('Alerte de supervision refusée', ['code_http' => 404]);

        $this->creerService($clientRefusant)->signaleBlocageApresPlafonnement();
    }

    public function test_l_appel_vise_le_point_d_entree_push_du_jeton(): void
    {
        $urlSollicitee = '';
        $client = new MockHttpClient(
            static function (string $methode, string $url) use (&$urlSollicitee): ResponseInterface {
                $urlSollicitee = $url;

                return new MockResponse('{"ok":true}');
            },
        );

        $this->creerService($client)->signaleBlocageApresPlafonnement();

        $this->assertStringStartsWith(
            self::URL_BASE . '/api/push/' . self::JETON,
            $urlSollicitee,
        );
    }

    public function test_le_statut_pousse_est_up_pour_le_mode_inverse(): void
    {
        $urlSollicitee = '';
        $client = new MockHttpClient(
            static function (string $methode, string $url) use (&$urlSollicitee): ResponseInterface {
                $urlSollicitee = $url;

                return new MockResponse('{"ok":true}');
            },
        );

        $this->creerService($client)->signaleBlocageApresPlafonnement();

        $this->assertStringContainsString('status=up', $urlSollicitee);
    }

    public function test_aucune_donnee_personnelle_ne_quitte_l_application(): void
    {
        $urlSollicitee = '';
        $client = new MockHttpClient(
            static function (string $methode, string $url) use (&$urlSollicitee): ResponseInterface {
                $urlSollicitee = $url;

                return new MockResponse('{"ok":true}');
            },
        );

        $this->creerService($client)->signaleBlocageApresPlafonnement();

        $message = urldecode($urlSollicitee);

        $this->assertStringNotContainsString('@', $message);
        $this->assertStringContainsString('Blocage apres plafonnement des tentatives de connexion', $message);
        $this->assertStringContainsString('preprod', $message);
    }

    public function test_le_jeton_n_est_jamais_journalise(): void
    {
        $contextesJournalises = [];
        $this->securityLogger->method('warning')
            ->willReturnCallback(
                function (\Stringable|string $message, array $context) use (&$contextesJournalises): void {
                    $contextesJournalises[] = $context;
                },
            );

        $clientEnEchec = new MockHttpClient(
            static fn (): never => throw new TransportException('supervision injoignable'),
        );

        $this->creerService($clientEnEchec)->signaleBlocageApresPlafonnement();

        $this->assertStringNotContainsString(
            self::JETON,
            json_encode($contextesJournalises, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_sans_jeton_aucun_appel_n_est_tente(): void
    {
        $client = new MockHttpClient(
            static fn (): never => throw new \LogicException('Aucun appel ne devait être tenté.'),
        );

        $this->securityLogger->expects($this->never())->method('warning');

        $service = new AlerteSecuriteService($client, $this->securityLogger, 'dev', self::URL_BASE, null);

        $service->signaleBlocageApresPlafonnement();

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function test_sans_url_de_base_aucun_appel_n_est_tente(): void
    {
        $client = new MockHttpClient(
            static fn (): never => throw new \LogicException('Aucun appel ne devait être tenté.'),
        );

        $service = new AlerteSecuriteService($client, $this->securityLogger, 'dev', null, self::JETON);

        $service->signaleBlocageApresPlafonnement();

        $this->assertSame(0, $client->getRequestsCount());
    }

    private function creerService(MockHttpClient $httpClient): AlerteSecuriteService
    {
        return new AlerteSecuriteService(
            $httpClient,
            $this->securityLogger,
            'preprod',
            self::URL_BASE,
            self::JETON,
        );
    }
}
