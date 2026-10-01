<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Symfony;

use Sentinelo\Agent\Agent;
use Sentinelo\Agent\Enrolment\Enroller;
use Sentinelo\Agent\Enrolment\EnrolmentFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rattache ce site à la plateforme — le geste d'installation qui n'existait pas.
 *
 *     php bin/console sentinelo:enroll <clé-api>
 *
 * Sur ces cibles il n'y a pas d'écran d'administration : c'est un développeur ou un
 * déploiement qui installe. La clé arrive donc par la ligne de commande, ou par
 * `SENTINELO_API_KEY` dans l'environnement — auquel cas l'argument est superflu, et le
 * premier passage de `sentinelo:run` aurait fini par enrôler tout seul. Cette commande
 * existe quand même : elle rend la réponse TOUT DE SUITE, avec le motif exact du refus.
 *
 * Sort en échec quand l'enrôlement échoue, à l'inverse de `sentinelo:run`. Les deux sont
 * cohérents : `sentinelo:run` tourne dans cron, où un code de sortie non nul poste un
 * courriel que personne ne lit ; celle-ci tourne dans un script de déploiement, où un
 * déploiement qui croit avoir enrôlé le site est pire qu'un déploiement rouge.
 */
#[AsCommand(name: 'sentinelo:enroll', description: 'Rattache ce site à la plateforme Sentinelo avec sa clé API.')]
final class SentineloEnrollCommand extends Command
{
    public function __construct(private readonly Agent $agent)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'api-key',
            InputArgument::OPTIONAL,
            'Clé API affichée une seule fois par la plateforme. À défaut : ' . Enroller::API_KEY_VARIABLE . '.'
        );
        $this->addOption(
            'saas-url',
            null,
            InputOption::VALUE_REQUIRED,
            'Adresse de la plateforme, si elle diffère de celle configurée.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Crée les tables au premier passage : c'est ici qu'elles servent d'abord, et
        // l'enrôlement ne doit pas exiger d'avoir déjà fait tourner le cron une fois.
        $this->agent->install();

        $key = $input->getArgument('api-key');
        if (!is_string($key) || trim($key) === '') {
            $key = (string) ($this->agent->environment()->get(Enroller::API_KEY_VARIABLE) ?? '');
        }

        $saasUrl = $input->getOption('saas-url');

        try {
            $result = $this->agent->enroller()->enrol($key, is_string($saasUrl) ? $saasUrl : null);
        } catch (EnrolmentFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Site enrôlé : ' . $result->siteUuid);
        $io->writeln('Plateforme : ' . $result->saasUrl);

        if (!$result->webhookSecretReceived) {
            // Voir EnrolmentResult : sans secret, tout ce que l'agent POUSSE est
            // abandonné en silence. Un avertissement ici, pas trois semaines plus tard.
            $io->warning(
                'Aucun secret de webhook reçu : les notifications sortantes (sauvegarde '
                . 'terminée, scan, restauration) resteront muettes. Relancez cette '
                . 'commande une fois le site corrigé côté plateforme.'
            );
        }

        return Command::SUCCESS;
    }
}
