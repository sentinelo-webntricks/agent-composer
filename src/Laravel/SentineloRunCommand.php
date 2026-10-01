<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Laravel;

use Illuminate\Console\Command;
use Sentinelo\Agent\Agent;
use Sentinelo\Agent\Enrolment\Enroller;
use Sentinelo\Agent\Scheduling\TaskRunner;

/**
 * The agent's cron entry point on Laravel.
 *
 *     * * * * * cd /var/www/site && php artisan sentinelo:run >> /dev/null 2>&1
 *
 * Can also be attached to Laravel's own scheduler (`$schedule->command('sentinelo:run')
 * ->everyMinute()`) where the client already runs `schedule:run`. Either way the work is
 * the same: ask what is due, run it, say nothing when there is nothing — see the Symfony
 * command for why this always exits 0.
 */
final class SentineloRunCommand extends Command
{
    /** @var string */
    protected $signature = 'sentinelo:run {task? : Forcer une tâche précise, en ignorant sa date d\'échéance}';

    /** @var string */
    protected $description = 'Exécute les tâches Sentinelo dues (à appeler depuis cron).';

    public function handle(TaskRunner $tasks, Agent $agent): int
    {
        // Creates the agent's tables on first run: installing the package is the whole
        // installation, with no migration to add to the client's project.
        $agent->install();

        // Voir la commande Symfony jumelle : l'adoption de `SENTINELO_API_KEY` est le
        // chemin d'enrôlement des déploiements automatisés. Elle ne lève pas et ne fait
        // rien, presque toujours.
        $agent->enroller()->adopt($agent->environment()->get(Enroller::API_KEY_VARIABLE));

        $forced = $this->argument('task');
        if (is_string($forced) && $forced !== '') {
            try {
                $tasks->runNow($forced);
                $this->info($forced . ' : terminé');
            } catch (\Throwable $e) {
                $this->error($forced . ' : ' . $e->getMessage());
            }

            return self::SUCCESS;
        }

        foreach ($tasks->runDue() as $hook => $outcome) {
            $this->line(sprintf('%s : %s', $hook, $outcome));
        }

        return self::SUCCESS;
    }
}
