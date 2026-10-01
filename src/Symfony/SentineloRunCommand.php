<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Symfony;

use Sentinelo\Agent\Agent;
use Sentinelo\Agent\Enrolment\Enroller;
use Sentinelo\Agent\Scheduling\TaskRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The agent's cron entry point.
 *
 * Install line:
 *
 *     * * * * * cd /var/www/site && php bin/console sentinelo:run >> /dev/null 2>&1
 *
 * Every minute, and it is cheap: the command asks the scheduler what is due and, on the
 * overwhelming majority of ticks, the answer is nothing. Running it more rarely would
 * only add latency to the heartbeat and the webhook retries.
 *
 * Exit code 0 even when a task fails, on purpose. A non-zero exit makes cron mail the
 * operator, and a failing backup already reports itself to the platform through the
 * proper channel — a nightly mail nobody reads is how real alerts get filtered out.
 */
#[AsCommand(name: 'sentinelo:run', description: 'Exécute les tâches Sentinelo dues (à appeler depuis cron).')]
final class SentineloRunCommand extends Command
{
    public function __construct(
        private readonly TaskRunner $tasks,
        private readonly Agent $agent,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'task',
            InputArgument::OPTIONAL,
            'Forcer une tâche précise, en ignorant sa date d\'échéance.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Creates the agent's tables on first run, so installing the bundle needs no
        // migration in the client's project.
        $this->agent->install();

        // L'adoption de la clé semée par l'environnement.
        //
        // C'est le chemin des déploiements automatisés : `SENTINELO_API_KEY` posée dans
        // l'environnement, personne n'ouvre de terminal après coup, et le premier passage
        // de cron rattache le site. Rend `null` — le cas ordinaire — quand il n'y a rien à
        // faire, ne lève jamais, et s'auto-régule à cinq minutes : voir Enroller.
        $this->agent->enroller()->adopt(
            $this->agent->environment()->get(Enroller::API_KEY_VARIABLE)
        );

        $forced = $input->getArgument('task');
        if (is_string($forced) && $forced !== '') {
            try {
                $this->tasks->runNow($forced);
                $io->success($forced . ' : terminé');
            } catch (\Throwable $e) {
                $io->error($forced . ' : ' . $e->getMessage());
            }

            return Command::SUCCESS;
        }

        $outcomes = $this->tasks->runDue();

        if ($outcomes === []) {
            $io->writeln('<comment>Rien à exécuter.</comment>', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }

        foreach ($outcomes as $hook => $outcome) {
            $io->writeln(sprintf('%s : %s', $hook, $outcome));
        }

        return Command::SUCCESS;
    }
}
