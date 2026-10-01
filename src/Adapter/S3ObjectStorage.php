<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\ConfigStore;
use Sentinelo\AgentCore\Port\HttpClient;
use Sentinelo\AgentCore\Port\HttpResponse;
use Sentinelo\AgentCore\Port\Logger;
use Sentinelo\AgentCore\Port\ObjectStorage;
use Sentinelo\AgentCore\Storage\MultipartUpload;
use Sentinelo\AgentCore\Storage\S3Endpoint;
use Sentinelo\AgentCore\Storage\S3Signer;
use Sentinelo\AgentCore\Storage\StorageError;

/**
 * Object storage over the shared S3 wire protocol.
 *
 * The protocol — SigV4, endpoint resolution (path-style for B2, which is not
 * negotiable), multipart framing — already lives in `AgentCore\Storage` and is verified
 * against the AWS test vectors. What this class supplies is the TRANSPORT, which is the
 * one part that genuinely differs per platform: streaming a multi-gigabyte archive out
 * without ever holding it in memory.
 *
 * Note the deliberate absence of `aws/aws-sdk-php`. It drags in a large dependency tree;
 * on a Composer application that tree would merge with the CLIENT's, and an agent that
 * forces a `guzzlehttp/guzzle` major on a shop is an agent that does not get installed.
 * The WordPress agent shipped it until August 2026 and paid for it in a different
 * currency — 3 009 of its 3 450 files, enough that its own updates stopped fitting in a
 * shared host's request budget. No agent carries it now; it survives as a test-only
 * oracle for the signer.
 *
 * `size()` keeps its three outcomes strictly distinct, because callers act very
 * differently on each: an int is the object at that size, null is a store that
 * *confirmed* absence (404), and a throw is "could not be determined". Folding unknown
 * into absent is how a network hiccup deletes a good backup.
 *
 * ## Un refus, pas une dégradation (MWP-01, quatrième audit)
 *
 * Cette classe n'implémente PAS `ResumableObjectStorage`. Le commentaire de `put()`
 * annonçait ce refus depuis toujours — « deliberately not implemented rather than
 * silently degraded » — puis **tentait quand même l'envoi d'un seul tenant** au-delà du
 * seuil multipart, en se contentant d'un avertissement au journal. Le résultat mesuré est
 * exactement ce que le commentaire prétendait éviter : sur un hébergement qui coupe PHP à
 * 360 s, l'envoi meurt sans rien écrire, la sauvegarde reste « en cours » jusqu'à ce que
 * la plateforme l'expire, et **elle recommence à l'identique le lendemain**. Une
 * application dont l'archive ne tient pas dans une requête ne sauvegarde alors jamais, et
 * rien ne le dit.
 *
 * Le refus est donc devenu un vrai refus : au-delà de {@see MultipartUpload::MULTIPART_THRESHOLD},
 * `put()` lève AVANT d'ouvrir la connexion, la sauvegarde échoue avec un motif qui NOMME
 * la cause et le geste, et la plateforme l'affiche. Une mauvaise nouvelle honnête se lit ;
 * une expiration silencieuse se cherche pendant des semaines.
 *
 * Ce que ça coûte, et il faut le dire : une application dont l'archive dépasse ce seuil
 * n'est plus sauvegardée par cet agent tant que la capacité reprenable n'est pas écrite.
 * Elle ne l'était pas non plus avant — la différence est qu'on le sait.
 */
final class S3ObjectStorage implements ObjectStorage
{
    private const UPLOAD_TIMEOUT = 900;

    public function __construct(
        private readonly ConfigStore $config,
        private readonly HttpClient $http,
        private readonly Logger $logger,
    ) {
    }

    public function put(string $localPath, string $key): array
    {
        if (!is_readable($localPath)) {
            throw new \RuntimeException('Archive introuvable pour l\'envoi : ' . $localPath);
        }

        $size = (int) filesize($localPath);
        [$endpoint, $signer] = $this->connection();

        if (MultipartUpload::isRequired($size)) {
            // Un refus, avant d'ouvrir la connexion. Voir le docblock de la classe : cet
            // agent n'implémente pas `ResumableObjectStorage`, donc un envoi interrompu
            // ne reprend jamais — le tenter produit une sauvegarde « en cours » que la
            // plateforme expirera, et qui repartira de zéro le lendemain pour mourir au
            // même endroit. Le message nomme la cause ET le geste : c'est ce qui sépare
            // un refus utile d'une panne.
            $message = sprintf(
                'Archive de %s Mo : au-dela du seuil d\'envoi en une seule requete (%s Mo). '
                    . 'Cet agent n\'a pas de reprise d\'envoi : un transfert interrompu par la '
                    . 'limite de temps de l\'hebergeur ne reprendrait pas, et la sauvegarde '
                    . 'echouerait sans le dire. Reduisez le perimetre (exclusions de chemins, '
                    . 'reglables par site depuis la plateforme) ou envoyez cette archive a la main.',
                number_format($size / 1048576, 1, ',', ' '),
                number_format(MultipartUpload::MULTIPART_THRESHOLD / 1048576, 0, ',', ' ')
            );

            $this->logger->log('error', 'backup', $message);

            throw new \RuntimeException($message);
        }

        $url = $endpoint->objectUrl($key);
        $headers = $this->baseHeaders($endpoint, $signer, 'PUT', $url, 'UNSIGNED-PAYLOAD', [
            'Content-Length' => (string) $size,
            'Content-Type' => 'application/octet-stream',
        ]);

        $response = $this->http->request('PUT', $url, [
            'headers' => $headers,
            'file' => $localPath,
            'timeout' => self::UPLOAD_TIMEOUT,
        ]);

        if (!$response->isSuccess()) {
            throw new \RuntimeException('Envoi vers le stockage objet refusé : ' . self::refusal($response));
        }

        return [
            'url' => $url,
            'bucket' => $endpoint->bucket,
            'path' => $key,
            'size' => $size,
            'etag' => isset($response->headers['etag']) ? trim($response->headers['etag'], '"') : null,
        ];
    }

    public function get(string $key, string $localPath): string
    {
        [$endpoint, $signer] = $this->connection();
        $url = $endpoint->objectUrl($key);

        $response = $this->http->request('GET', $url, [
            'headers' => $this->baseHeaders($endpoint, $signer, 'GET', $url, hash('sha256', '')),
            'sink' => $localPath,
            'timeout' => self::UPLOAD_TIMEOUT,
        ]);

        if (!$response->isSuccess()) {
            @unlink($localPath);

            throw new \RuntimeException(sprintf('Téléchargement de %s refusé : %s', $key, self::refusal($response)));
        }

        // An empty file is not a download. Restoring from it would decrypt to nothing
        // and then import an empty schema over a live database.
        if (!is_file($localPath) || filesize($localPath) === 0) {
            @unlink($localPath);

            throw new \RuntimeException('Archive téléchargée vide : ' . $key);
        }

        return $localPath;
    }

    public function delete(string $key): bool
    {
        try {
            [$endpoint, $signer] = $this->connection();
        } catch (\RuntimeException) {
            return false;
        }

        $url = $endpoint->objectUrl($key);
        $response = $this->http->request('DELETE', $url, [
            'headers' => $this->baseHeaders($endpoint, $signer, 'DELETE', $url, hash('sha256', '')),
        ]);

        return $response->isSuccess() || $response->statusCode === 404;
    }

    public function size(string $key): ?int
    {
        [$endpoint, $signer] = $this->connection();
        $url = $endpoint->objectUrl($key);

        $response = $this->http->request('HEAD', $url, [
            'headers' => $this->baseHeaders($endpoint, $signer, 'HEAD', $url, hash('sha256', '')),
        ]);

        if ($response->statusCode === 404) {
            // A definite answer: the store says it is not there.
            return null;
        }

        if (!$response->isSuccess()) {
            throw new \RuntimeException(sprintf(
                'Existence de %s indéterminable : %s',
                $key,
                self::refusal($response)
            ));
        }

        $length = $response->headers['content-length'] ?? null;
        if ($length === null) {
            throw new \RuntimeException('Réponse HEAD sans Content-Length : taille indéterminable');
        }

        return (int) $length;
    }

    /**
     * @return array{0: S3Endpoint, 1: S3Signer}
     */
    private function connection(): array
    {
        $credentials = $this->config->get('cloud_credentials');
        if (!is_array($credentials) || $credentials === []) {
            // Exact wording matters: BackupRunner matches on it to record the upload as
            // *skipped* rather than failing the whole backup.
            throw new \RuntimeException('Cloud credentials not configured');
        }

        $provider = (string) ($this->config->get('cloud_provider') ?: 'backblaze_b2');
        $endpoint = S3Endpoint::fromCredentials($provider, $credentials);

        $keyId = (string) ($credentials['key_id'] ?? $credentials['access_key_id'] ?? '');
        $secret = (string) ($credentials['app_key'] ?? $credentials['secret_access_key'] ?? '');

        if ($keyId === '' || $secret === '' || $endpoint->bucket === '') {
            throw new \RuntimeException('Cloud credentials not configured');
        }

        return [$endpoint, new S3Signer($keyId, $secret, $endpoint->region)];
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function baseHeaders(
        S3Endpoint $endpoint,
        S3Signer $signer,
        string $method,
        string $url,
        string $payloadHash,
        array $extra = [],
    ): array {
        $headers = [
            'Host' => $endpoint->host(),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => S3Signer::timestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        ] + $extra;

        // Signed from the very headers that will be sent — the signer reads x-amz-date
        // out of this array rather than regenerating it, which is what stopped the
        // intermittent SignatureDoesNotMatch on a second boundary.
        $headers['Authorization'] = $signer->authorization($method, $url, $headers, $payloadHash);

        return $headers;
    }

    /**
     * Le motif que le dépôt a donné, et non le seul code que nous avons lu.
     *
     * Le corps d'un refus S3 porte la cause ET souvent le geste — mesuré le
     * 4 septembre 2026, `storage_cap_exceeded` et l'adresse de la page où relever le
     * plafond, jetés au profit d'un « HTTP 403 » qui ne nomme que notre propre
     * contrôle. Le décodage vit au cœur ({@see StorageError}) : quatre agents lisent
     * le même protocole, et un décodage recopié quatre fois diverge quatre fois.
     */
    private static function refusal(HttpResponse $response): string
    {
        if ($response->error !== null) {
            // Pas de réponse : c'est le transport, et son message est déjà le bon.
            return $response->error;
        }

        return StorageError::describe($response->statusCode, $response->body);
    }
}
