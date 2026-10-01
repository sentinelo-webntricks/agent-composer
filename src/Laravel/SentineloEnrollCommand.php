<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Laravel;

use Illuminate\Console\Command;
use Sentinelo\Agent\Agent;
use Sentinelo\Agent\Enrolment\Enroller;
use Sentinelo\Agent\Enrolment\EnrolmentFailed;

/**
 * Le même geste d'installation, côté Laravel.
 *
 *     php artisan sentinelo:enroll <clé-api>
 *
 * Servir les deux frameworks n'est pas une politesse : l'agent en distribue un de chaque,
 * et refermer la moitié du problème rendrait l'agent installable et inutilisable sur
 * l'autre — l'exact défaut qu'on répare. Toute la logique vit dans {@see Enroller} ; il
 * ne reste ici que la traduction des arguments et l'affichage, comme pour la commande
 * Symfony jumelle.
 */
final class SentineloEnrollCommand extends Command
{
    /** @var string */
    protected $signature = 'sentinelo:enroll
        {api-key? : Clé API affichée une seule fois par la plateforme}
        {--saas-url= : Adresse de la plateforme, si elle diffère de celle configurée}';

    /** @var string */
    protected $description = 'Rattache ce site à la plateforme Sentinelo avec sa clé API.';

    public function handle(Agent $agent): int
    {
        // Crée les tables au premier passage : enrôler ne doit pas exiger d'avoir déjà
        // fait tourner le cron une fois.
        $agent->install();

        $key = $this->argument('api-key');
        if (!is_string($key) || trim($key) === '') {
            $key = (string) ($agent->environment()->get(Enroller::API_KEY_VARIABLE) ?? '');
        }

        $saasUrl = $this->option('saas-url');

        try {
            $result = $agent->enroller()->enrol($key, is_string($saasUrl) && $saasUrl !== '' ? $saasUrl : null);
        } catch (EnrolmentFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Site enrôlé : ' . $result->siteUuid);
        $this->line('Plateforme : ' . $result->saasUrl);

        if (!$result->webhookSecretReceived) {
            $this->warn(
                'Aucun secret de webhook reçu : les notifications sortantes resteront muettes.'
            );
        }

        return self::SUCCESS;
    }
}
