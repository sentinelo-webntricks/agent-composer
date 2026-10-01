<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Enrolment;

/**
 * L'enrôlement n'a pas abouti, et on dit lequel des cas c'est.
 *
 * Typée plutôt que générique parce qu'elle a deux appelants aux exigences opposées : la
 * commande console la laisse remonter et sort en échec — un déploiement qui croit avoir
 * enrôlé le site est pire qu'un déploiement rouge —, tandis que l'adoption automatique
 * depuis l'environnement l'attrape et journalise, puisqu'elle s'exécute dans le cron et
 * n'a personne à qui parler.
 */
final class EnrolmentFailed extends \RuntimeException
{
    public static function missingKey(): self
    {
        return new self(
            'Aucune clé API fournie. Passez-la en argument (`sentinelo:enroll <clé>`) ou '
            . 'définissez ' . Enroller::API_KEY_VARIABLE . ' dans l\'environnement.'
        );
    }

    public static function missingPlatformUrl(): self
    {
        return new self('Aucune adresse de plateforme : renseignez SENTINELO_SAAS_URL ou --saas-url.');
    }

    public static function transport(string $url, string $error): self
    {
        return new self(sprintf('Plateforme injoignable (%s) : %s', $url, $error));
    }

    /**
     * Le corps de la réponse est repris — tronqué — parce que c'est lui qui porte le
     * remède (« Invalid API key », une page de pare-feu, une erreur de la plateforme).
     * Il ne contient jamais la clé : nous l'envoyons dans un en-tête, et la plateforme
     * ne la renvoie pas.
     */
    public static function refused(int $status, string $body): self
    {
        $body = trim($body);
        if (mb_strlen($body) > 300) {
            $body = mb_substr($body, 0, 300) . '…';
        }

        return new self(sprintf(
            'La plateforme a refusé l\'enrôlement (HTTP %d) : %s',
            $status,
            $body === '' ? 'réponse vide' : $body
        ));
    }

    /**
     * Réponse acceptée mais inexploitable.
     *
     * Sans `site_uuid` l'agent n'a rien à mettre dans ses webhooks ni dans son heartbeat :
     * il paraîtrait enrôlé et ne parlerait à personne. Mieux vaut refuser bruyamment que
     * de sceller une moitié d'enrôlement.
     */
    public static function incomplete(string $url): self
    {
        return new self(sprintf(
            'Réponse de %s acceptée mais sans site_uuid : impossible de rattacher cet agent à un site.',
            $url
        ));
    }
}
