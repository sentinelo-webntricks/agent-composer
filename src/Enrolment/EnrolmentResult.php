<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Enrolment;

/**
 * Ce que la plateforme a rendu, et que l'agent a scellé.
 *
 * `$webhookSecretReceived` n'est pas décoratif : sans secret, `WebhookDispatcher`
 * court-circuite et TOUS les webhooks sortants (sauvegarde terminée, scan, restauration)
 * sont abandonnés en silence. Un enrôlement qui n'en rapporte pas est réussi à moitié, et
 * l'opérateur doit le lire au moment où il enrôle — pas trois semaines plus tard, en
 * cherchant pourquoi la plateforme ne voit jamais aboutir une sauvegarde.
 */
final class EnrolmentResult
{
    public function __construct(
        public readonly string $siteUuid,
        public readonly string $saasUrl,
        public readonly bool $webhookSecretReceived,
    ) {
    }
}
