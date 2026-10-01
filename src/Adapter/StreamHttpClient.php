<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Adapter;

use Sentinelo\AgentCore\Port\HttpClient;
use Sentinelo\AgentCore\Port\HttpResponse;

/**
 * Outbound HTTP, through cURL when the extension is present and PHP streams otherwise.
 *
 * The agent cannot assume a PSR-18 client: it has to work on a Sylius shop whose
 * container will not boot and inside a cron job that never touched the framework. Two
 * transports, one behaviour.
 *
 * Notable: a transport failure is returned as an {@see HttpResponse} carrying `error`,
 * not thrown. Callers in agent-core already branch on `isSuccess()`, and turning a DNS
 * hiccup into an exception halfway through a backup would abort a job that the webhook
 * retry queue is perfectly able to finish later.
 */
final class StreamHttpClient implements HttpClient
{
    private const DEFAULT_TIMEOUT = 30;

    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $method = strtoupper($method);
        $headers = (array) ($options['headers'] ?? []);
        $body = $options['body'] ?? null;
        $timeout = (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $sink = isset($options['sink']) ? (string) $options['sink'] : null;
        $file = isset($options['file']) ? (string) $options['file'] : null;

        if (\extension_loaded('curl')) {
            return $this->viaCurl($method, $url, $headers, $body, $timeout, $sink, $file);
        }

        return $this->viaStream($method, $url, $headers, $body, $timeout, $sink, $file);
    }

    /**
     * @param array<string, string> $headers
     */
    private function viaCurl(
        string $method,
        string $url,
        array $headers,
        mixed $body,
        int $timeout,
        ?string $sink,
        ?string $file,
    ): HttpResponse {
        $handle = curl_init($url);
        if ($handle === false) {
            return new HttpResponse(0, '', [], 'curl_init a échoué');
        }

        $responseHeaders = [];
        $sinkHandle = null;

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_HTTPHEADER => $this->flatten($headers),
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }

                return $length;
            },
        ]);

        // Streaming a large archive: read from disk without ever holding it in memory.
        if ($file !== null) {
            $size = filesize($file);
            $upload = fopen($file, 'rb');
            if ($upload === false || $size === false) {
                return new HttpResponse(0, '', [], 'Fichier à envoyer illisible : ' . $file);
            }
            curl_setopt($handle, CURLOPT_UPLOAD, true);
            curl_setopt($handle, CURLOPT_INFILE, $upload);
            curl_setopt($handle, CURLOPT_INFILESIZE, $size);
        } elseif ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }

        // Streaming a large download the same way.
        if ($sink !== null) {
            $sinkHandle = fopen($sink, 'wb');
            if ($sinkHandle === false) {
                return new HttpResponse(0, '', [], 'Destination non inscriptible : ' . $sink);
            }
            curl_setopt($handle, CURLOPT_FILE, $sinkHandle);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        // Pas de `curl_close()` : dépréciée en PHP 8.5 et sans effet depuis 8.0 — la
        // poignée est un objet, libéré quand la variable sort de portée.
        if (isset($upload) && is_resource($upload)) {
            fclose($upload);
        }
        if (is_resource($sinkHandle)) {
            fclose($sinkHandle);
        }

        return new HttpResponse($status, $sink !== null || !is_string($raw) ? '' : $raw, $responseHeaders, $error);
    }

    /**
     * @param array<string, string> $headers
     */
    private function viaStream(
        string $method,
        string $url,
        array $headers,
        mixed $body,
        int $timeout,
        ?string $sink,
        ?string $file,
    ): HttpResponse {
        if ($file !== null) {
            $body = file_get_contents($file);
            if ($body === false) {
                return new HttpResponse(0, '', [], 'Fichier à envoyer illisible : ' . $file);
            }
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $this->flatten($headers)),
            'content' => is_string($body) ? $body : ($body === null ? '' : json_encode($body)),
            'timeout' => $timeout,
            // Without this, PHP turns a 4xx/5xx into false and the status code — which
            // the whole envelope contract depends on — is lost.
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return new HttpResponse(0, '', [], 'Requête HTTP échouée (transport flux)');
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
            }
        }

        if ($sink !== null) {
            file_put_contents($sink, $raw);
            $raw = '';
        }

        return new HttpResponse($status, $raw, $responseHeaders);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function flatten(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            $flat[] = is_int($name) ? (string) $value : $name . ': ' . $value;
        }

        return $flat;
    }
}
