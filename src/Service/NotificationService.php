<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service d'envoi d'emails transactionnels via Brevo (US-4.1+).
 *
 * Centralise la construction des emails (from, replyTo, template Twig) pour que
 * les contrôleurs n'aient qu'à fournir le destinataire, le sujet, le template
 * et son contexte. Aucune logique métier ici : c'est uniquement un wrapper
 * RGPD-friendly autour de MailerInterface.
 *
 * ─ Choix RGPD ─────────────────────────────────────────────────────────────
 * Les logs n'enregistrent JAMAIS l'adresse email du destinataire ni le sujet
 * en clair. Seuls un hash partiel SHA-256 de l'adresse (8 premiers caractères,
 * non réversible en pratique sans dictionnaire ciblé) et le nom du template
 * sont tracés. Cela permet l'audit ("combien d'emails partis ?", "lequel a
 * échoué ?") sans exposer de donnée personnelle dans les fichiers de log,
 * qui peuvent être archivés ou consultés par des tiers (DevOps, hébergeur).
 *
 * ─ Redirection DEV ────────────────────────────────────────────────────────
 * Si la variable d'environnement APP_MAILER_REDIRECT_TO est définie (uniquement
 * prévue en environnement DEV, jamais en preprod/prod), TOUS les emails sont
 * redirigés vers cette adresse au lieu du destinataire d'origine. Le sujet
 * est alors préfixé "[DEV→destinataire-original@exemple.fr] " pour conserver
 * la traçabilité du destinataire prévu. La syntaxe %env(default::VAR)% du
 * conteneur Symfony fait que l'absence de la variable renvoie NULL sans
 * erreur : en preprod/prod, où la variable n'existe pas, le service envoie
 * normalement vers le destinataire d'origine. Côté logs RGPD, on ajoute
 * 'redirige' => true et un 'redirige_vers_hash' (hash partiel SHA-256 de
 * l'adresse de redirection) quand la redirection est active.
 */
final readonly class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire('%env(APP_NOTIFICATION_FROM)%')]
        private string $expediteur,
        #[Autowire('%env(APP_NOTIFICATION_REPLY_TO)%')]
        private string $replyTo,
        #[Autowire('%env(default::APP_MAILER_REDIRECT_TO)%')]
        private ?string $redirectionDev = null,
    ) {}

    /**
     * Envoie un email transactionnel à partir d'un template Twig.
     *
     * Si APP_MAILER_REDIRECT_TO est définie (cf. section "Redirection DEV" du
     * docblock de classe), $to est remplacé par l'adresse de redirection et
     * le sujet est préfixé "[DEV→destinataire-original]" avant construction
     * du TemplatedEmail.
     *
     * @param string               $to       Adresse email du destinataire (jamais loguée en clair)
     * @param string               $subject  Sujet affiché dans la boîte de réception (jamais logué)
     * @param string               $template Chemin du template Twig (ex: 'emails/reservation_confirmee.html.twig')
     * @param array<string, mixed> $context  Variables passées au template
     *
     * @throws \Throwable Si la construction ou l'envoi de l'email échoue.
     *                    L'exception est loguée puis relancée pour traitement par l'appelant.
     */
    public function envoyer(
        string $to,
        string $subject,
        string $template,
        array $context = [],
    ): void {
        $redirige = $this->redirectionDev !== null && $this->redirectionDev !== '';

        if ($redirige) {
            $subject = sprintf('[DEV→%s] %s', $to, $subject);
            $to      = $this->redirectionDev;
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->expediteur))
                ->replyTo(new Address($this->replyTo))
                ->to(new Address($to))
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $contexteErreur = [
                'to_hash'         => substr(hash('sha256', $to), 0, 8),
                'template'        => $template,
                'redirige'        => $redirige,
                'exception_class' => $e::class,
            ];
            if ($redirige) {
                $contexteErreur['redirige_vers_hash'] = substr(hash('sha256', $this->redirectionDev), 0, 8);
            }
            $this->logger->error('Envoi email échoué', $contexteErreur);

            throw $e;
        }

        $contexteSucces = [
            'to_hash'  => substr(hash('sha256', $to), 0, 8),
            'template' => $template,
            'redirige' => $redirige,
        ];
        if ($redirige) {
            $contexteSucces['redirige_vers_hash'] = substr(hash('sha256', $this->redirectionDev), 0, 8);
        }
        $this->logger->info('Email envoyé', $contexteSucces);
    }
}
