<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Enrolment;

use Sentinelo\AgentCore\Port\Clock;
use Sentinelo\AgentCore\Port\ConfigStore;
use Sentinelo\AgentCore\Port\HttpClient;
use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Support\Timestamps;

/**
 * Comment un agent Composer reçoit sa clé — le chemin qui n'existait pas.
 *
 * L'agent était installable et inutilisable : aucune commande, aucune variable lue,
 * aucun écran, et le §5.5 de la documentation prescrivait de « lancer le heartbeat »,
 * qui rend `false` faute de clé. Or toute la chaîne (sauvegarde, restauration, scan,
 * audit, rapport) est authentifiée par cette clé, et `Authenticator` refuse tout le monde
 * tant qu'elle manque — à dessein : un agent fraîchement installé est une porte fermée.
 * Il fallait donc une clé, une façon de la poser, et personne n'en fournissait.
 *
 * Cinq décisions portent ce fichier.
 *
 * **1. La clé est scellée dans la table de l'agent, pas dans un fichier.** Les deux
 * autres agents font pareil, pour la même raison, et ici elle est plus forte : sur ces
 * cibles le projet EST un dépôt git et l'image est reconstruite à chaque déploiement. Une
 * clé écrite dans `.env` finit soit versionnée — un incident —, soit écrasée au
 * déploiement suivant. La table, elle, survit aux déploiements, et {@see
 * \Sentinelo\Agent\Adapter\TableConfigStore} chiffre `api_key` au repos, ce qui compte
 * parce que cette table part dans les sauvegardes du site lui-même.
 *
 * **2. Une variable d'environnement ne suffit PAS, et sert quand même.** Elle ne peut
 * pas porter ce que la plateforme frappe à l'enrôlement — `site_uuid` et
 * `webhook_secret` —, donc elle ne remplace pas la poignée de main ; elle l'AMORCE.
 * `SENTINELO_API_KEY` posée dans l'environnement, le premier passage de cron enrôle tout
 * seul : c'est le seul chemin praticable pour un déploiement automatisé, où personne
 * n'ouvre un terminal après coup. La commande reste là pour l'installation à la main, et
 * elle n'est pas bridée par le régulateur ci-dessous : quand un opérateur demande, on
 * essaie.
 *
 * **3. Aucune route HTTP d'enrôlement.** Elle devrait par construction être exemptée de
 * l'authentification — c'est ce qu'on est en train d'installer —, donc n'importe qui
 * pourrait rattacher l'agent d'un client à SA plateforme, et lire ses sauvegardes. Le
 * seul canal est local : la ligne de commande, sur la machine, avec les droits du projet.
 *
 * **4. La clé est écrite AVANT l'appel de vérification.** Contre-intuitif, et c'est ce
 * que fait déjà l'agent WordPress : en répondant, la plateforme POUSSE la configuration
 * cloud sur notre route `/cloud-config`, authentifiée par cette même clé. Écrire après,
 * c'est présenter une porte encore fermée à l'appel qui suit — l'agent archive ensuite et
 * reste bloqué faute d'identifiants de dépôt, une panne déjà vue en production. En
 * contrepartie, un échec RESTAURE ce qui était là : une tentative ratée avec une mauvaise
 * clé ne doit pas déconnecter un site qui fonctionnait.
 *
 * **5. On écrit qu'on va essayer avant d'essayer.** Le même principe que les étapes
 * reprenables de la sauvegarde : l'horodatage de tentative est posé avant l'appel réseau,
 * donc un processus tué pendant l'appel laisse quand même la trace qui empêche le
 * passage de cron suivant de recommencer une minute plus tard.
 */
final class Enroller
{
    /** La variable qui amorce l'enrôlement automatique. */
    public const API_KEY_VARIABLE = 'SENTINELO_API_KEY';

    /** L'endpoint de la plateforme. Le même que celui des deux autres agents. */
    private const VERIFY_PATH = '/api/sites/verify';

    /** Clé de configuration portant la dernière tentative d'adoption. */
    private const ATTEMPT_KEY = 'enrolment_attempted_at';

    /**
     * Espacement des tentatives automatiques.
     *
     * Le cron passe toutes les minutes. Sans régulateur, une plateforme injoignable —
     * ou une clé semée fausse — vaudrait 1 440 requêtes par jour et par site, sur le
     * chemin le moins surveillé du produit.
     */
    private const RETRY_AFTER_SECONDS = 300;

    /** @param \Closure(): array<string, scalar|null> $facts ce que l'agent dit de lui-même */
    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly string $defaultSaasUrl,
        private readonly \Closure $facts,
    ) {
    }

    /**
     * Enrôlé veut dire : une clé ET un site auquel se rattacher.
     *
     * Les deux, parce qu'ils tombent séparément. Une clé seule laisse l'agent
     * authentifier nos appels tout en n'ayant personne à qui envoyer un webhook ni un
     * heartbeat — l'exact profil du site que la plateforme affiche « connecté » pendant
     * que rien ne remonte.
     */
    public function isEnrolled(): bool
    {
        return $this->stored('api_key') !== '' && $this->stored('site_uuid') !== '';
    }

    /** L'adresse de la plateforme retenue : celle scellée à l'enrôlement, sinon le défaut. */
    public function saasUrl(): string
    {
        $stored = $this->stored('saas_url');

        return rtrim($stored !== '' ? $stored : $this->defaultSaasUrl, '/');
    }

    /**
     * Enrôle, ou lève en nommant ce qui manque.
     *
     * Idempotente : la relancer avec la même clé revérifie le lien et re-provisionne le
     * secret de webhook, ce dont on a besoin après une rotation de secret applicatif —
     * `TableConfigStore` ne sait alors plus déchiffrer ce qu'il avait scellé et rend
     * « absent », qui est la réponse honnête mais laisse le site muet.
     */
    public function enrol(string $apiKey, ?string $saasUrl = null): EnrolmentResult
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw EnrolmentFailed::missingKey();
        }

        $target = rtrim(trim($saasUrl ?? $this->saasUrl()), '/');
        if ($target === '') {
            throw EnrolmentFailed::missingPlatformUrl();
        }

        $previousKey = $this->config->get('api_key');
        $previousUrl = $this->config->get('saas_url');

        // Décision 5 : la trace avant la tentative. Puis décision 4 : la clé avant l'appel.
        $this->config->set(self::ATTEMPT_KEY, Timestamps::store($this->clock->now()));
        $this->config->set('saas_url', $target);
        $this->config->set('api_key', $apiKey);

        $response = $this->http->request('POST', $target . self::VERIFY_PATH, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Sentinelo-API-Key' => $apiKey,
            ],
            'body' => (string) json_encode(($this->facts)(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout' => 30,
        ]);

        if ($response->error !== null) {
            $this->rollBack($previousKey, $previousUrl);

            throw EnrolmentFailed::transport($target, $response->error);
        }

        if (!$response->isSuccess()) {
            $this->rollBack($previousKey, $previousUrl);

            throw EnrolmentFailed::refused($response->statusCode, $response->body);
        }

        $payload = json_decode($response->body, true);
        $siteUuid = is_array($payload) && isset($payload['site_uuid']) ? (string) $payload['site_uuid'] : '';

        if ($siteUuid === '') {
            $this->rollBack($previousKey, $previousUrl);

            throw EnrolmentFailed::incomplete($target);
        }

        $this->config->set('site_uuid', $siteUuid);

        $secret = is_array($payload) && isset($payload['webhook_secret'])
            ? (string) $payload['webhook_secret']
            : '';
        if ($secret !== '') {
            $this->config->set('webhook_secret', $secret);
        } else {
            // Pas fatal — l'agent répond aux appels entrants — mais tout ce qu'il POUSSE
            // sera abandonné sans un mot. Ça se dit maintenant.
            $this->logger->log('warning', 'enrolment', 'Enrôlement sans secret de webhook : '
                . 'les notifications sortantes resteront muettes tant qu\'il manquera.');
        }

        $this->config->set('connected_at', Timestamps::store($this->clock->now()));

        $this->logger->log('info', 'enrolment', 'Site enrôlé : ' . $siteUuid . ' sur ' . $target);

        return new EnrolmentResult($siteUuid, $target, $secret !== '');
    }

    /**
     * Adopte la clé semée par l'environnement — le chemin des déploiements automatisés.
     *
     * Ne lève jamais : elle s'exécute dans le cron, où une exception ferait échouer la
     * minute entière (webhooks en attente compris) pour un enrôlement qui peut attendre
     * cinq minutes. Rend `null` quand il n'y a rien à faire, ce qui est le cas ordinaire.
     */
    public function adopt(?string $seededKey, ?string $saasUrl = null): ?EnrolmentResult
    {
        $seededKey = trim((string) $seededKey);
        if ($seededKey === '') {
            return null;
        }

        // Déjà scellée et rattachée : rien à faire. `hash_equals` par habitude, pas par
        // nécessité — la comparaison est locale — mais l'habitude est bonne à tenir.
        if ($this->isEnrolled() && hash_equals($this->stored('api_key'), $seededKey)) {
            return null;
        }

        if (!$this->mayRetryNow()) {
            return null;
        }

        try {
            return $this->enrol($seededKey, $saasUrl);
        } catch (EnrolmentFailed $e) {
            $this->logger->log('warning', 'enrolment', 'Enrôlement automatique refusé : ' . $e->getMessage());

            return null;
        }
    }

    private function mayRetryNow(): bool
    {
        $last = Timestamps::toUnix($this->stored(self::ATTEMPT_KEY) ?: null);

        return $last === null || ($this->clock->now()->getTimestamp() - $last) >= self::RETRY_AFTER_SECONDS;
    }

    /** Un échec ne doit pas déconnecter un site qui fonctionnait. */
    private function rollBack(mixed $previousKey, mixed $previousUrl): void
    {
        $this->config->set('api_key', is_string($previousKey) ? $previousKey : '');
        $this->config->set('saas_url', is_string($previousUrl) ? $previousUrl : '');
    }

    private function stored(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
