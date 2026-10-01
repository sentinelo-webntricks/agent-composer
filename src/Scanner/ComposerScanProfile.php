<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Scanner;

use Sentinelo\AgentCore\Scanner\CheckCatalogue;
use Sentinelo\AgentCore\Scanner\ScanProfile;

/**
 * The checks a Composer application can honestly perform.
 *
 * Nine of the catalogue's nineteen scoring checks, plus the zero-weight inventory. The
 * omissions are the interesting part, and every one of them is a refusal to take free
 * points — `ScoreCalculator` normalises over what actually ran, so an omitted check
 * costs nothing, whereas a stub that "passes" would make this platform look safer than
 * a WordPress site that genuinely ran the check.
 *
 * Kept:
 *   ssl · security_headers · sensitive_files · directory_listing   the outside view,
 *       identical on any web application
 *   php_version · debug_mode · config_file                          the configuration
 *   file_permissions · suspicious_files                             the filesystem
 *   extension_vulnerabilities                                       inventory, 0 pt —
 *       it reports the composer.lock contents so the BACKEND can correlate CVEs
 *
 * Omitted, and why:
 *   core_integrity        Composer publishes no per-file hashes, so there is nothing to
 *                         compare against. A check that cannot fail is not a check.
 *   obsolete_versions     the target version is unknown by design (see ComposerInventory):
 *                         the client's constraints decide what they may install, not the
 *                         latest release. Scoring "up to date" would assert something
 *                         nobody verified.
 *   database_prefix       Doctrine has no prefix and Laravel ships an empty one, so
 *                         "in use" always equals "the default". The check would fire on
 *                         every site in the fleet and mean nothing.
 *   admin_users           no portable notion of an administrator account.
 *   login_security        no portable notion of brute-force protection. Answering
 *                         "protected" would have handed over three points for nothing.
 *   file_editing          no in-browser file editor exists here — the risk is absent,
 *                         so the reward must be too.
 *   api_exposure          no conventional user-listing endpoint to probe.
 *   legacy_rpc,
 *   author_enumeration    WordPress-shaped risks that simply do not exist.
 *   admin_path            no default admin path on Symfony or Laravel. Sylius has one
 *                         (/admin) and could bind this later; guessing for all three
 *                         would penalise the two where it is meaningless.
 */
final class ComposerScanProfile
{
    private function __construct()
    {
    }

    public static function create(): ScanProfile
    {
        return ScanProfile::of([
            CheckCatalogue::SUSPICIOUS_FILES,
            CheckCatalogue::FILE_PERMISSIONS,
            CheckCatalogue::SECURITY_HEADERS,
            CheckCatalogue::SSL,
            CheckCatalogue::SENSITIVE_FILES,
            CheckCatalogue::DEBUG_MODE,
            CheckCatalogue::PHP_VERSION,
            CheckCatalogue::CONFIG_FILE,
            CheckCatalogue::DIRECTORY_LISTING,
            CheckCatalogue::EXTENSION_VULNERABILITIES,
        ]);
    }
}
