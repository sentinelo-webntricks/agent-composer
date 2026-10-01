<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Scanner;

use Sentinelo\Agent\Platform\Layout;

/**
 * Gathers filesystem facts for the shared scorers. Grades nothing itself.
 *
 * Two sweeps, both deliberately bounded. This runs on the client's production host,
 * during their traffic, on hardware we do not control: an unbounded recursive walk of a
 * media directory with two million product images is not a thorough scan, it is an
 * outage. So both sweeps carry a file budget and a deadline, and — this is the part
 * that matters — they report whether they finished. An interrupted sweep that found
 * nothing is reported as inconclusive, never as clean: see
 * {@see \Sentinelo\AgentCore\Scanner\Check\SuspiciousFilesScorer}.
 */
final class FilesystemInspector
{
    /** Executable extensions that have no business in a data directory. */
    private const EXECUTABLE_EXTENSIONS = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'pht'];

    /** Markers of an obfuscated payload, looked for only in files already flagged. */
    private const PAYLOAD_MARKERS = [
        'eval(base64_decode',
        'eval(gzinflate',
        'eval(str_rot13',
        'eval($_POST',
        'eval($_GET',
        'assert($_',
        'preg_replace(\'/.*/e\'',
        'shell_exec($_',
        'system($_',
    ];

    public function __construct(
        private readonly Layout $layout,
        private readonly int $fileBudget = 20000,
        private readonly int $deadlineSeconds = 30,
    ) {
    }

    /**
     * Executable files sitting in directories meant to hold uploads.
     *
     * @return array{findings: list<array{path: string, reason: string}>, complete: bool}
     */
    public function suspiciousFiles(): array
    {
        $root = $this->layout->pathFor(\Sentinelo\AgentCore\Port\FileSystem::ROLE_UPLOADS);
        if ($root === null) {
            // No media root: nothing to sweep, and that IS a complete answer.
            return ['findings' => [], 'complete' => true];
        }

        $findings = [];
        $seen = 0;
        $deadline = time() + $this->deadlineSeconds;
        $complete = true;

        foreach ($this->walk($root) as $file) {
            if (++$seen > $this->fileBudget || time() > $deadline) {
                $complete = false;
                break;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
                continue;
            }

            $findings[] = [
                'path' => $this->relative($file->getPathname()),
                // The extension alone is the finding. The marker search only sharpens
                // the wording — a plain .php under the media root is already wrong,
                // whether or not it is obfuscated.
                'reason' => $this->hasPayloadMarker($file->getPathname())
                    ? 'fichier exécutable contenant un motif d\'obfuscation connu'
                    : 'fichier exécutable dans un répertoire de données',
            ];
        }

        return ['findings' => $findings, 'complete' => $complete];
    }

    /**
     * Paths opened wider than they should be.
     *
     * Only the directories the agent knows are load-bearing are inspected, not the whole
     * tree: `vendor/` alone is tens of thousands of files, and a permission finding
     * there is the client's package manager's business, not a security event.
     *
     * @return list<array{path: string, issue: string}>
     */
    public function permissionFindings(): array
    {
        $findings = [];

        $writableCandidates = array_filter([
            $this->layout->publicPath(),
            $this->layout->pathFor(\Sentinelo\AgentCore\Port\FileSystem::ROLE_UPLOADS),
            $this->layout->projectRoot() . '/config',
        ]);

        foreach ($writableCandidates as $path) {
            if (is_dir($path) && $this->isWorldWritable($path)) {
                $findings[] = [
                    'path' => $this->relative($path),
                    'issue' => \Sentinelo\AgentCore\Scanner\Check\FilePermissionsScorer::ISSUE_WORLD_WRITABLE,
                ];
            }
        }

        foreach ([$this->layout->environmentFile(), $this->layout->environmentFile() . '.local'] as $secret) {
            if (is_file($secret) && $this->isWorldReadable($secret)) {
                $findings[] = [
                    'path' => $this->relative($secret),
                    'issue' => \Sentinelo\AgentCore\Scanner\Check\FilePermissionsScorer::ISSUE_WORLD_READABLE_SECRET,
                ];
            }
        }

        return $findings;
    }

    /**
     * Facts about the configuration file, for the shared scorer.
     *
     * `servedOverHttp` is NOT decided here: only a real request against the site can
     * establish it, and guessing from the path would report a false positive on every
     * project whose webserver already denies dotfiles.
     *
     * @return array{path: string, present: bool, inside_public_root: bool, world_readable: bool}
     */
    public function configFileFacts(): array
    {
        $path = $this->layout->environmentFile();
        $public = rtrim($this->layout->publicPath(), '/') . '/';

        return [
            'path' => $this->relative($path),
            'present' => is_file($path),
            'inside_public_root' => str_starts_with($path, $public),
            'world_readable' => is_file($path) && $this->isWorldReadable($path),
        ];
    }

    private function isWorldWritable(string $path): bool
    {
        $mode = @fileperms($path);

        return $mode !== false && ($mode & 0o002) !== 0;
    }

    private function isWorldReadable(string $path): bool
    {
        $mode = @fileperms($path);

        return $mode !== false && ($mode & 0o004) !== 0;
    }

    private function hasPayloadMarker(string $path): bool
    {
        // Only the head of the file: a payload hidden at byte 40 000 000 of an image
        // is not worth reading a 40 MB file for, and dropper stubs live at the top.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 8192);
        fclose($handle);

        foreach (self::PAYLOAD_MARKERS as $marker) {
            if (stripos($head, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<\SplFileInfo> */
    private function walk(string $root): iterable
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\UnexpectedValueException) {
            return;
        }

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    private function relative(string $absolute): string
    {
        $root = $this->layout->projectRoot() . '/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
