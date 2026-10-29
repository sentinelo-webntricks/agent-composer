# sentinelo/agent-composer

L'agent Sentinelo pour les applications basées sur Composer : **Symfony, Sylius, Laravel**.

Il partage son cœur métier avec l'agent WordPress (`sentinelo/agent-core`) : mêmes routes,
mêmes payloads, même barème de sécurité. Ce qui change, c'est le périmètre — et ce
périmètre est un choix, pas une limite qu'on finira par lever.

## Ce que fait l'agent

| | |
|---|---|
| **Sauvegarde** | base + code + médias, archivés, **chiffrés avant de quitter la machine**, envoyés vers le stockage objet, avec rétention GFS |
| **Restauration** | sauvegarde de sécurité préalable, vérification SHA-256 **avant** d'écrire quoi que ce soit, site hors ligne pendant l'opération |
| **Scan de sécurité** | 9 checks notés + l'inventaire des paquets pour la corrélation CVE côté plateforme |
| **Heartbeat** | présence, version de l'agent, version du framework |

## Ce qu'il ne fait pas, et pourquoi

**Il n'applique aucune mise à jour.** `composer update` + migrations + `cache:clear`
n'est pas atomique : dès que `vendor/` change, l'application qui tourne est un mélange
de deux versions, et une migration qui échoue n'a pas de retour arrière. Cela suppose
aussi un `vendor/` inscriptible, que la plupart des déploiements de production
refusent — et là où il l'est, y écrire fait diverger le code exécuté de l'artefact
construit par le pipeline du client : leur prochain déploiement efface notre
modification sans que personne ne s'en aperçoive.

L'agent lève donc `Port\UpdatesNotSupported`, le backend le sait par
`SitePlatform::supportsPackageUpdates()`, et l'interface ne propose pas le bouton. Sur
ces cibles, la valeur du produit est la surveillance, la sauvegarde, la sécurité et le
rapport.

**Il ne se met pas à jour lui-même.** Distribué en paquet Composer privé, pas en
archive auto-installable. Conséquence assumée : on ne peut pas pousser un correctif à
la flotte, seulement le signaler. C'est compensé par `meta.plugin_version`, présent sur
**chaque** réponse : la plateforme sait quelle version répond sur chaque site et peut
lister les agents périmés.

**Il n'a pas d'inventaire d'extensions au sens WordPress.** Il n'y a ni marketplace ni
activation par paquet : la source est `composer.lock`. Et `latest_version` y vaut
toujours `null`, ce qui signifie **INCONNU, jamais « à jour »** — la version installable
dépend des contraintes du projet, pas de la dernière version publiée. Confondre les deux
laisserait croire que la flotte est à jour alors que personne n'a vérifié.

## Architecture

```
src/
  Platform/      quelle application, quelle disposition de fichiers, quels paramètres de base
  Adapter/       les ports d'AgentCore implémentés pour un projet Composer
  Repository/    les tables de l'agent, en PDO
  Scanner/       collecte des faits ; la NOTATION vit dans AgentCore
  Enrolment/     la poignée de main qui donne sa clé au site — sans framework
  Symfony/       bundle, contrôleur, abonnement maintenance, commande console
  Laravel/       service provider, contrôleur, middleware, commande artisan
```

L'enveloppe, l'authentification et le routeur ne sont pas ici : ils vivent au cœur
partagé (`AgentCore\Http\`), et c'est ce qui fait que les trois agents répondent la
même chose.

Deux invariants portent tout le reste :

1. **Le routeur ne connaît aucun framework.** `Symfony/` et `Laravel/` ne font que
   traduire une requête et retraduire une réponse. C'est ce qui permet de tester la
   totalité de la surface HTTP sans booter quoi que ce soit — et d'ajouter une
   quatrième cible sans toucher à la logique.
2. **L'agent se construit depuis la racine du projet et son `.env`, pas depuis le
   conteneur de l'application.** Deux des moments où il compte le plus sont des moments
   où le framework est indisponible : un cron sur un site dont le conteneur compilé est
   cassé par un mauvais déploiement, et c'est précisément là qu'on veut une sauvegarde.

## Barème de sécurité

Les identifiants et les poids viennent de `AgentCore\Scanner\CheckCatalogue`, partagé
avec tous les agents. Cette plateforme déclare le sous-ensemble qu'elle sait
honnêtement exécuter (`ComposerScanProfile`), et `ScoreCalculator` normalise sur ce qui
a réellement tourné : **78/100 veut dire la même chose ici que sur un site WordPress**,
ce que le rapport client mensuel suppose en les affichant côte à côte.

Un check qu'on ne peut pas faire est **omis**, jamais simulé. Un stub qui « passe »
distribue des points gratuits et fait passer cette plateforme pour plus sûre que celle
qui a réellement fait la vérification. Les omissions et leur raison sont documentées
dans `ComposerScanProfile` et épinglées par un test.

## Installation

Chaque étape est ici. Les étapes 2 à 4 se font **à la main** : `composer require` ne les
fait pas et ne les signale pas.

### 1. Le paquet

```bash
composer require sentinelo/agent-composer
```

### 2. Monter l'agent — Symfony / Sylius uniquement

**`composer require` ne suffit pas, et rien ne le dit.** Le paquet n'a pas de recette
Flex : le bundle n'est pas ajouté à `bundles.php` d'un projet existant, et les routes ne
sont pas importées. Sans ces deux lignes l'agent est installé et muet — le site répond
404 sur `/_sentinelo/v1/…`.

```php
// config/bundles.php
Sentinelo\Agent\Symfony\SentineloBundle::class => ['all' => true],
```

```yaml
# config/routes/sentinelo.yaml
sentinelo:
    resource: '@SentineloBundle/config/routes.php'
    type: php
```

Puis `php bin/console cache:clear`.

**Laravel :** rien à faire, le service provider est auto-découvert.

### 3. Le secret

L'agent chiffre les archives avant qu'elles ne quittent la machine, avec une clé dérivée
de `SENTINELO_SECRET` (sinon `APP_SECRET`, sinon `APP_KEY`). Une variable dédiée est
recommandée, dans `.env.local` — jamais dans un fichier versionné :

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # → SENTINELO_SECRET=…
```

> **Conserver ce secret hors du site.** Il déchiffre les sauvegardes : le perdre rend
> illisible tout ce qui a été sauvegardé avec.

### 4. Le cron

```cron
* * * * * cd /chemin/du/projet && php bin/console sentinelo:run >> /dev/null 2>&1
```

(`php artisan sentinelo:run` sur Laravel.) Les tables de l'agent sont créées au premier
passage — aucune migration à ajouter au projet.

### 5. L'enrôlement

L'enrôlement est le geste qui rend l'agent utile : sans clé, `Authenticator` refuse tout
le monde par `403 NOT_CONFIGURED`, et toute la chaîne (sauvegarde, restauration, scan,
audit, rapport) est authentifiée par cette clé. Créer le site dans la plateforme, copier
la clé affichée, puis — deux chemins, un seul travail :

```bash
php bin/console sentinelo:enroll <clé>   # ou : php artisan sentinelo:enroll <clé>
SENTINELO_API_KEY=<clé>                  # adoptée par le prochain passage de cron
```

La clé, le `site_uuid` et le `webhook_secret` rendus par la plateforme sont scellés dans
la table de l'agent, chiffrés au repos — jamais dans un fichier du projet, qui est un
dépôt git et dont l'image est reconstruite à chaque déploiement.

### Vérifier

```bash
curl -s https://votre-site/_sentinelo/v1/status
```

Avant l'enrôlement : `403 NOT_CONFIGURED` dans une enveloppe JSON — l'agent est monté.
Un 404 veut dire que l'étape 2 manque.
