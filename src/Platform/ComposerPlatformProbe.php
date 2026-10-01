<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Platform;

use Sentinelo\AgentCore\Platform\PlatformProbe;

/**
 * Answers "what is true about this site?" for a Composer-based application.
 *
 * Every fact the shared security scorers need, and nothing else. Three of them deserve
 * a word, because the honest answer is not the obvious one:
 *
 *  - **The database prefix.** Doctrine has no such concept and Laravel ships an empty
 *    prefix by default. So "in use" and "the shipped default" are both '' and the
 *    check reports the default — which is why `database_prefix` is NOT in this
 *    platform's scan profile. The methods still answer truthfully in case a future
 *    profile wants them; they just do not hand out a score.
 *  - **Login protection.** There is no portable notion of a brute-force plugin here,
 *    and guessing would be worse than abstaining. Both login methods answer false, and
 *    `login_security` is likewise absent from the profile — an omitted check costs no
 *    points either way, whereas a false "protected" would have given three away.
 *  - **File editing from the admin.** No Composer application ships an in-browser file
 *    editor, so the risk genuinely does not exist. Reported as "disabled" but, again,
 *    omitted from the profile rather than claimed as a win.
 */
final class ComposerPlatformProbe implements PlatformProbe
{
    public function __construct(
        private readonly Application $application,
        private readonly ApplicationDetector $detector,
        private readonly Environment $environment,
        private readonly string $agentVersion,
        private readonly string $databaseName,
    ) {
    }

    public function applicationName(): string
    {
        return $this->application->label();
    }

    /**
     * Identifiant machine attendu par le backend. Il vient de la valeur de l'énumération,
     * jamais du nom d'affichage passé en minuscules : les deux sont libres de diverger,
     * et c'est celui-ci que le backend compare.
     */
    public function applicationSlug(): string
    {
        return $this->application->value;
    }

    public function applicationVersion(): string
    {
        return $this->detector->applicationVersion($this->application) ?? 'unknown';
    }

    public function agentVersion(): string
    {
        return $this->agentVersion;
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function databaseTablePrefix(): string
    {
        return (string) $this->environment->get('DB_PREFIX', '');
    }

    public function defaultDatabaseTablePrefix(): string
    {
        return '';
    }

    public function isDebugEnabled(): bool
    {
        return $this->environment->bool($this->application->debugVariable());
    }

    /**
     * Debug output reaching the page is the aggravating factor, and on these frameworks
     * it is not a separate switch: debug mode itself renders the exception page. The
     * environment is what decides whether a visitor can see it, so a debug-enabled
     * production environment is reported as displaying — which is exactly the case that
     * turns the finding from a warning into a failure.
     */
    public function isDebugDisplayEnabled(): bool
    {
        if (!$this->isDebugEnabled()) {
            return false;
        }

        $environment = $this->application->environmentVariable();
        $current = strtolower((string) $this->environment->get($environment['variable'], ''));

        return $current === $environment['production'];
    }

    /**
     * Both frameworks log to file by construction (var/log, storage/logs). Reporting
     * this as an anomaly on every site would be noise, so it is only true when debug
     * itself is on — the case where those logs fill with stack traces.
     */
    public function isDebugLogEnabled(): bool
    {
        return $this->isDebugEnabled();
    }

    public function debugSettingName(string $key): string
    {
        $environment = $this->application->environmentVariable();

        return match ($key) {
            'debug', 'debug_display' => $this->application->debugVariable(),
            'debug_log' => $environment['variable'],
            default => $key,
        };
    }

    public function isFileEditingDisabled(): bool
    {
        return true;
    }

    public function hasLoginProtectionPlugin(): bool
    {
        return false;
    }

    public function hasCustomLoginUrl(): bool
    {
        return false;
    }
}
