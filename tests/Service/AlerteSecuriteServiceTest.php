<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AlerteSecuriteService;
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
 *
 * Le journal est une doublure observée (mock) uniquement dans les tests qui
 * portent sur la trace ; ailleurs c'est une doublure muette (stub), le service
 * étant alors interrogé sur l'appel qu'il émet et non sur ce qu'il journalise.
 */
final class AlerteSecuriteServiceTest extends TestCase
{
    private const URL_BASE = 'http://uptime-kuma:3001';

    private const JETON = 'jeton-de-test-abc123';

    public function test_l_echec_de_l_appel_ne_se_propage_pas(): void
    {
        $journal = $this->createMock(LoggerInterface::class);
        $journal->expects($this->once())
            ->method('warning')
            ->with('Alerte de supervision injoignable', ['exception' => TransportException::class]);

        $service = $this->creerServiceAvecJournal($this->clientQuiEchoue(), $journal);

        $service->signaleBlocageApresPlafonnement();
    }

    public function test_le_depassement_du_delai_ne_se_propage_pas(): void
    {
        $clientQuiExpire = new MockHttpClient(
            static fn (): never => throw new TransportException('délai maximal dépassé'),
        );

        $journal = $this->createMock(LoggerInterface::class);
        $journal->expects($this->once())->method('warning');

        $this->creerServiceAvecJournal($clientQuiExpire, $journal)->signaleBlocageApresPlafonnement();
    }

    public function test_une_reponse_en_erreur_est_tracee_sans_exception(): void
    {
        $clientRefusant = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $journal = $this->createMock(LoggerInterface::class);
        $journal->expects($this->once())
            ->method('warning')
            ->with('Alerte de supervision refusée', ['code_http' => 404]);

        $this->creerServiceAvecJournal($clientRefusant, $journal)->signaleBlocageApresPlafonnement();
    }

    public function test_l_appel_vise_le_point_d_entree_push_du_jeton(): void
    {
        $urlSollicitee = '';

        $this->creerService($this->clientQuiReleveLUrl($urlSollicitee))
            ->signaleBlocageApresPlafonnement();

        $this->assertStringStartsWith(
            self::URL_BASE . '/api/push/' . self::JETON,
            $urlSollicitee,
        );
    }

    public function test_le_statut_pousse_est_up_pour_le_mode_inverse(): void
    {
        $urlSollicitee = '';

        $this->creerService($this->clientQuiReleveLUrl($urlSollicitee))
            ->signaleBlocageApresPlafonnement();

        $this->assertStringContainsString('status=up', $urlSollicitee);
    }

    public function test_aucune_donnee_personnelle_ne_quitte_l_application(): void
    {
        $urlSollicitee = '';

        $this->creerService($this->clientQuiReleveLUrl($urlSollicitee))
            ->signaleBlocageApresPlafonnement();

        $message = urldecode($urlSollicitee);

        $this->assertStringNotContainsString('@', $message);
        $this->assertStringContainsString('Blocage apres plafonnement des tentatives de connexion', $message);
        $this->assertStringContainsString('preprod', $message);
    }

    public function test_le_jeton_n_est_jamais_journalise(): void
    {
        $contextesJournalises = [];

        $journal = $this->createStub(LoggerInterface::class);
        $journal->method('warning')->willReturnCallback(
            function (\Stringable|string $message, array $context) use (&$contextesJournalises): void {
                $contextesJournalises[] = $context;
            },
        );

        $this->creerServiceAvecJournal($this->clientQuiEchoue(), $journal)->signaleBlocageApresPlafonnement();

        $this->assertStringNotContainsString(
            self::JETON,
            json_encode($contextesJournalises, \JSON_THROW_ON_ERROR),
        );
    }

    public function test_sans_jeton_aucun_appel_n_est_tente(): void
    {
        $client = $this->clientQuiRefuseTouteSollicitation();

        $service = new AlerteSecuriteService(
            $client,
            $this->createStub(LoggerInterface::class),
            'dev',
            self::URL_BASE,
        );

        $service->signaleBlocageApresPlafonnement();

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function test_sans_url_de_base_aucun_appel_n_est_tente(): void
    {
        $client = $this->clientQuiRefuseTouteSollicitation();

        $service = new AlerteSecuriteService(
            $client,
            $this->createStub(LoggerInterface::class),
            'dev',
            null,
            self::JETON,
        );

        $service->signaleBlocageApresPlafonnement();

        $this->assertSame(0, $client->getRequestsCount());
    }

    private function creerService(MockHttpClient $httpClient): AlerteSecuriteService
    {
        return $this->creerServiceAvecJournal($httpClient, $this->createStub(LoggerInterface::class));
    }

    private function creerServiceAvecJournal(
        MockHttpClient $httpClient,
        LoggerInterface $journal,
    ): AlerteSecuriteService {
        return new AlerteSecuriteService(
            $httpClient,
            $journal,
            'preprod',
            self::URL_BASE,
            self::JETON,
        );
    }

    private function clientQuiEchoue(): MockHttpClient
    {
        return new MockHttpClient(
            static fn (): never => throw new TransportException('supervision injoignable'),
        );
    }

    private function clientQuiRefuseTouteSollicitation(): MockHttpClient
    {
        return new MockHttpClient(
            static fn (): never => throw new \LogicException('Aucun appel ne devait être tenté.'),
        );
    }

    /**
     * Client qui répond favorablement et consigne l'URL sollicitée dans $urlSollicitee.
     */
    private function clientQuiReleveLUrl(string &$urlSollicitee): MockHttpClient
    {
        return new MockHttpClient(
            static function (string $methode, string $url) use (&$urlSollicitee): ResponseInterface {
                $urlSollicitee = $url;

                return new MockResponse('{"ok":true}');
            },
        );
    }
}
