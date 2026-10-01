<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Symfony;

use Sentinelo\Agent\Agent;
use Sentinelo\AgentCore\Http\Router;
use Sentinelo\Agent\Scheduling\TaskRunner;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Mounts the agent in a Symfony or Sylius application.
 *
 * The `Agent` is built from the project directory and its own `.env`, **not** from the
 * application's Doctrine connection. That looks like duplication and is not: the agent
 * must be able to back up and restore a site whose container will not boot, which is
 * precisely when someone needs a backup. A service depending on `doctrine.dbal` would
 * be unavailable at exactly that moment.
 *
 * One knob, `saas_url`, and it has a sane default: everything else the agent needs is
 * assigned by the platform at enrolment and stored in its own table.
 */
final class SentineloBundle extends AbstractBundle
{
    /**
     * La ressource de routage que le client importe, mot pour mot.
     *
     * Elle est déclarée ici pour qu'il n'existe qu'UNE chaîne : la documentation la
     * publie, le noyau de test l'importe, et `RoutingResourceTest` vérifie que les deux
     * disent la même chose. Le défaut qu'on referme est né de trois copies — une dans
     * la doc, une dans le commentaire de config/routes.php, une paraphrase dans le test
     * — dont seule la moins importante était exercée.
     */
    public const ROUTING_RESOURCE = '@SentineloBundle/config/routes.php';

    protected string $extensionAlias = 'sentinelo';

    /**
     * La racine du paquet, et non celle que devine `AbstractBundle`.
     *
     * `AbstractBundle::getPath()` rend `dirname($fichierDeLaClasse, 2)` : il suppose
     * que la classe du bundle est posée DIRECTEMENT dans `src/`. La nôtre vit dans
     * `src/Symfony/`, avec le contrôleur, l'abonné et la commande qu'elle câble — donc
     * la supposition tombe un cran trop bas et `@SentineloBundle` désignait `src/`.
     * L'import documenté `@SentineloBundle/config/routes.php` cherchait alors
     * `src/config/routes.php`, un fichier qui n'existe pas.
     *
     * Ce que ça coûtait n'est pas « l'agent ne se monte pas » : un `resource` introuvable
     * fait échouer la COMPILATION du conteneur, donc l'application du client rend 500 sur
     * TOUTES ses routes. Un agent censé surveiller un site l'éteignait, en suivant notre
     * propre documentation.
     *
     * On remonte jusqu'au `composer.json` qui définit le paquet plutôt que de compter
     * des niveaux : un nombre de niveaux redevient faux — en silence — le jour où
     * quelqu'un déplace cette classe, ce qui est exactement le défaut qu'on répare.
     * L'ancre, elle, suit la classe où qu'elle aille dans le paquet.
     */
    public function getPath(): string
    {
        return $this->path ??= self::packageRoot();
    }

    /**
     * Le répertoire du paquet : le premier ancêtre porteur d'un `composer.json`.
     *
     * En dépôt c'est `agents/composer/`, chez le client
     * `vendor/sentinelo/agent-composer/` — Composer n'ôte jamais ce fichier d'un paquet
     * installé. S'il manque quand même, on lève : rendre un chemin faux ferait échouer
     * l'import de routes chez le client, à des kilomètres de la cause.
     */
    private static function packageRoot(): string
    {
        $directory = __DIR__;

        while (!is_file($directory . '/composer.json')) {
            $parent = \dirname($directory);
            if ($parent === $directory) {
                throw new \LogicException(
                    'Racine du paquet sentinelo/agent-composer introuvable depuis ' . __DIR__
                    . ' : aucun composer.json parmi les répertoires parents.'
                );
            }
            $directory = $parent;
        }

        return $directory;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('saas_url')
                    ->defaultValue('https://backend.sentinelo.fr')
                    ->info('URL de la plateforme Sentinelo. Sert de valeur par défaut : '
                        . 'la valeur enregistrée à l\'enrôlement la remplace.')
                ->end()
                ->scalarNode('project_dir')
                    ->defaultNull()
                    ->info('Racine du projet. Par défaut %kernel.project_dir%.')
                ->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(Agent::class)
            ->factory([Agent::class, 'fromProjectRoot'])
            ->args([
                $config['project_dir'] ?? '%kernel.project_dir%',
                $config['saas_url'],
            ])
            ->public();

        $services->set(Router::class)->args([service(Agent::class), Agent::VERSION])->public();
        $services->set(TaskRunner::class)->args([service(Agent::class)])->public();

        $services->set(AgentController::class)
            ->args([service(Router::class)])
            ->tag('controller.service_arguments')
            ->public();

        $services->set(MaintenanceSubscriber::class)
            ->args([service(Agent::class)])
            ->tag('kernel.event_subscriber');

        // La commande n'est enregistrée que si le composant Console est là.
        //
        // `symfony/framework-bundle` NE dépend PAS de `symfony/console` : une application
        // purement API ou un worker peut parfaitement s'en passer. Déclarer le service
        // sans garde faisait échouer la compilation du conteneur sur « no corresponding
        // class exists » — c'est-à-dire que l'installation de notre bundle empêchait
        // l'application du CLIENT de démarrer, pas seulement l'agent.
        //
        // Sans Console, l'agent reste entièrement fonctionnel par HTTP ; c'est la ligne
        // de cron qui manque, et l'installation le dit.
        if (class_exists(\Symfony\Component\Console\Command\Command::class)) {
            $services->set(SentineloRunCommand::class)
                ->args([service(TaskRunner::class), service(Agent::class)])
                ->tag('console.command');

            // L'enrôlement : le seul chemin par lequel ce site reçoit sa clé. Il vit
            // sous la même garde, et c'est cohérent — sans Console, la clé arrive par
            // SENTINELO_API_KEY et le cron l'adopte, mais alors ce n'est pas le cron
            // Symfony qui tourne.
            $services->set(SentineloEnrollCommand::class)
                ->args([service(Agent::class)])
                ->tag('console.command');
        }
    }
}
