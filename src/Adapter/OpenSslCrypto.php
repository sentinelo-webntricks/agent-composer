<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\Agent\Platform\Environment;
use Sentinelo\AgentCore\Port\Crypto;

/**
 * AES-256-GCM, keyed from the application's own secret.
 *
 * Two rules from the {@see Crypto} port drive everything here.
 *
 * **Authenticated.** GCM's tag is verified on every chunk, so a truncated or altered
 * archive throws instead of decrypting to garbage. That matters more than it sounds:
 * `decryptFile()` is the last thing standing between a corrupted download and a wiped
 * production database, and it can only do that job if the primitive detects tampering.
 * Per-chunk tags alone would not detect a *dropped tail*, so each chunk's index is fed
 * in as additional authenticated data and the stream ends with an explicit terminator
 * chunk — cutting the file short now fails to authenticate rather than silently
 * yielding a shorter, plausible archive.
 *
 * **No silent fallback key.** Absent or placeholder key material throws. A key derived
 * from an empty string, or from the framework's shipped `ThisTokenIsNotSoSecret…`, is
 * identical on every installation in the world and is therefore not a key.
 *
 * Key material is looked up in order: `SENTINELO_SECRET` (a dedicated variable, so an
 * operator can rotate the agent's key without invalidating every session cookie on the
 * site), then `APP_SECRET` (Symfony/Sylius), then `APP_KEY` (Laravel, base64-prefixed).
 */
final class OpenSslCrypto implements Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /** File header. The version byte is what will let a format change stay readable. */
    private const MAGIC = "SNTLO\x01";

    /** 1 MiB plaintext per chunk — bounded memory on a 128 MB shared host. */
    private const CHUNK_BYTES = 1048576;

    /**
     * Values the frameworks ship as placeholders. Encrypting under any of them is
     * indistinguishable from not encrypting at all.
     */
    private const PLACEHOLDERS = [
        'thistokenisnotsosecretchangeit',
        'changeme',
        'secret',
        'base64:',
        'somesecret',
    ];

    private ?string $key = null;

    public function __construct(private readonly Environment $environment)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::MAGIC,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Chiffrement impossible (openssl a échoué)');
        }

        return base64_encode(self::MAGIC . $iv . $tag . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        $headerLength = strlen(self::MAGIC) + self::IV_LENGTH + self::TAG_LENGTH;

        if ($raw === false || strlen($raw) < $headerLength || !str_starts_with($raw, self::MAGIC)) {
            throw new \RuntimeException('Texte chiffré illisible ou produit par une autre implémentation');
        }

        $iv = substr($raw, strlen(self::MAGIC), self::IV_LENGTH);
        $tag = substr($raw, strlen(self::MAGIC) + self::IV_LENGTH, self::TAG_LENGTH);
        $body = substr($raw, $headerLength);

        $plaintext = openssl_decrypt($body, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);

        if ($plaintext === false) {
            throw new \RuntimeException('Déchiffrement refusé : contenu altéré ou clé différente');
        }

        return $plaintext;
    }

    public function encryptFile(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            $this->write($out, self::MAGIC, $destination);
            $index = 0;

            while (!feof($in)) {
                $plain = fread($in, self::CHUNK_BYTES);
                if ($plain === false) {
                    throw new \RuntimeException('Lecture impossible pendant le chiffrement : ' . $source);
                }
                if ($plain === '') {
                    continue;
                }

                $this->writeChunk($out, $plain, $index++, $destination);
            }

            // Explicit terminator: an empty chunk authenticated under its own index.
            // Without it, truncating the file simply produces a shorter archive whose
            // every remaining chunk still verifies.
            $this->writeChunk($out, '', $index, $destination);
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public function decryptFile(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = $this->open($destination, 'wb');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new \RuntimeException('Archive non produite par cet agent (en-tête inattendu)');
            }

            $index = 0;
            $terminated = false;

            while (!$terminated) {
                $header = fread($in, 4);
                if ($header === false || strlen($header) < 4) {
                    throw new \RuntimeException('Archive tronquée : marqueur de fin absent');
                }

                /** @var array{1: int} $unpacked */
                $unpacked = unpack('N', $header);
                $length = $unpacked[1];

                $iv = (string) fread($in, self::IV_LENGTH);
                $tag = (string) fread($in, self::TAG_LENGTH);
                $body = $length > 0 ? (string) fread($in, $length) : '';

                if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::TAG_LENGTH || strlen($body) !== $length) {
                    throw new \RuntimeException('Archive tronquée pendant le déchiffrement');
                }

                $plain = openssl_decrypt(
                    $body,
                    self::CIPHER,
                    $this->key(),
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    self::MAGIC . $index
                );

                if ($plain === false) {
                    throw new \RuntimeException(sprintf(
                        'Déchiffrement refusé au bloc %d : archive altérée, réordonnée, ou clé différente',
                        $index
                    ));
                }

                if ($plain === '') {
                    $terminated = true;
                } else {
                    $this->write($out, $plain, $destination);
                }

                ++$index;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /** @param resource $out */
    private function writeChunk($out, string $plain, int $index, string $destination): void
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipher = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            // The index is authenticated, not encrypted: it makes reordering and
            // truncation detectable.
            self::MAGIC . $index,
            self::TAG_LENGTH
        );

        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement du bloc ' . $index . ' impossible');
        }

        $this->write($out, pack('N', strlen($cipher)) . $iv . $tag . $cipher, $destination);
    }

    /**
     * Write, and refuse to continue on a short write.
     *
     * `fwrite()` returns the number of bytes it managed to write; on a full partition
     * that is fewer than asked for, and it does NOT fail. Ignoring the return value
     * produced the worst possible outcome: encryption "succeeded", the SHA-256 was then
     * computed over the truncated file so it matched what was uploaded, and the backup
     * was recorded as completed AND verified. The damage only surfaced at restore time
     * — the one moment the client is relying on it.
     *
     * @param resource $handle
     */
    private function write($handle, string $bytes, string $destination): void
    {
        // Silenced on purpose: PHP raises a notice carrying the errno ("No space left
        // on device") and then returns anyway. A notice is discarded on most production
        // configurations, so the cause is captured here and carried by the exception
        // that actually propagates.
        $written = @fwrite($handle, $bytes);

        if ($written === false || $written !== strlen($bytes)) {
            $cause = error_get_last()['message'] ?? '';

            throw new \RuntimeException(rtrim(sprintf(
                'Écriture incomplète dans %s (%d octets sur %d) — disque plein ? %s',
                $destination,
                (int) $written,
                strlen($bytes),
                $cause
            )));
        }
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Fichier inaccessible (%s) : %s', $mode, $path));
        }

        return $handle;
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        foreach (['SENTINELO_SECRET', 'APP_SECRET', 'APP_KEY'] as $variable) {
            $material = (string) $this->environment->get($variable, '');

            // Laravel writes APP_KEY as "base64:…"; decode it so the entropy actually
            // used is the 32 raw bytes and not their textual rendering.
            if (str_starts_with($material, 'base64:')) {
                $decoded = base64_decode(substr($material, 7), true);
                $material = $decoded === false ? '' : $decoded;
            }

            if ($this->isUsable($material)) {
                // HKDF gives this agent its own key even when it shares APP_SECRET with
                // the application, so a leak of one does not hand over the other.
                return $this->key = hash_hkdf('sha256', $material, 32, 'sentinelo-agent-backup-v1');
            }
        }

        throw new \RuntimeException(
            'Aucun secret utilisable : définissez SENTINELO_SECRET (ou APP_SECRET / APP_KEY) '
            . 'avec au moins 16 caractères. L\'agent refuse de chiffrer avec une clé par défaut, '
            . 'qui serait identique sur toutes les installations.'
        );
    }

    private function isUsable(string $material): bool
    {
        if (strlen($material) < 16) {
            return false;
        }

        $normalised = strtolower(trim($material));
        foreach (self::PLACEHOLDERS as $placeholder) {
            if ($normalised === $placeholder) {
                return false;
            }
        }

        return true;
    }
}
