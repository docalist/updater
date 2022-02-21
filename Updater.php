<?php

/**
 * This file is part of Docalist/Updater.
 *
 * Copyright (C) 2015-2022 Daniel Ménard
 *
 * For copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Docalist\Updater;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/*
 * Docs de références.
 *
 * GitLab :
 * https://docs.gitlab.com/ee/user/project/integrations/webhook_events.html
 *
 * BitBucket :
 * https://support.atlassian.com/bitbucket-cloud/docs/event-payloads/
 *
 * GitHub :
 * https://docs.github.com/en/developers/webhooks-and-events/webhooks/webhook-events-and-payloads
 */

/**
 * Les types d'événements qu'on sait gérer.
 */
interface Event
{
    public const PUSH = 'push';
}

/**
 * Un hook contient des conditions et des commandes à exécuter si les conditions sont remplies.
 */
final class Hook
{
    /** @var string[] */
    private $when;

    /** @var string */
    private $on;

    /** @var string */
    private $from;

    /** @var string[] */
    private $do;

    /** @var string */
    private $in;

    /**
     * Initialise le hook.
     */
    public function __construct(array $hook)
    {
        $this->when = (array) ($hook['when'] ?? Event::PUSH);
        $this->on = $hook['on'] ?? 'master';
        $this->from = $hook['from'] ?? '';
        $this->do = (array) ($hook['do'] ?? 'echo Nothing to do!');
        $this->in = $hook['in'] ?? '';
    }

    /**
     * Teste si les conditions sont remplies.
     */
    public function match(string $when, string $on, string $from): bool
    {
        return in_array($when, $this->when, true)
            && $on === $this->on
            && $from === $this->from;
    }

    /**
     * Exécute le hook.
     */
    public function run(string $defaultDirectory): void
    {
        $directory = empty($this->in) ? $defaultDirectory : $this->in;

        foreach ($this->do as $do) {
            $command = sprintf('(cd "%s" && %s) 2>&1', $directory, $do);

            echo '$ ', $command, "\n";
            passthru($command, $exitCode);

            if (0 !== $exitCode) {
                echo 'Exit code: ', $exitCode, "\n\n";

                throw new RuntimeException('An error occured');
            }

            echo "\n";
        }
    }
}

/**
 * Une requête contient les variables du serveur ($_SERVER) et du JSON.
 */
final class Request
{
    /** @var string[] */
    private $vars;

    /** @var object */
    private $json;

    /**
     * Initialise la requête.
     */
    public function __construct(array $vars, string $json)
    {
        $this->vars = $vars;
        if ('application/json' !== $this->get('CONTENT_TYPE')) {
            throw new InvalidArgumentException('Invalid content-type');
        }

        $this->json = json_decode($json);
        if (!is_object($this->json)) {
            throw new InvalidArgumentException('Invalid JSON');
        }
    }

    /**
     * Retourne une variable du serveur (ajouter le préfixe "HTTP_" pour les entêtes de la requête).
     */
    public function get(string $header): string
    {
        return $this->vars[$header] ?? '';
    }

    /**
     * Retourne l'objet JSON de la requête.
     */
    public function json(): object
    {
        return $this->json;
    }
}

/**
 * Un handler gère un type de dépôt.
 */
abstract class Handler
{
    protected const HEADER = 'un entête de requête spécifique à la forge';
    protected const EVENTS = ['event généré par la forge' => 'constante Event::*'];
    protected const USER_AGENT = 'entête user-agent que doit contenir la requête (préfixe)';

    /** @var Request */
    protected $request;

    /**
     * Initialise le handler.
     */
    final private function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Retourne un handler capable de traiter la requête passée en paramètre ou null.
     */
    final public static function from(Request $request): ?self
    {
        /** @var Handler $handler */
        foreach ([GitLab::class, Bitbucket::class, GitHub::class] as $handler) {
            if ($handler::accepts($request)) {
                return new $handler($request);
            }
        }

        return null;
    }

    /**
     * Teste si le handler est capable de traiter la requête passée en paramètre ou null.
     */
    final public static function accepts(Request $request): bool
    {
        if (empty($request->get(static::HEADER))) {
            return false;
        }

        if (0 === strpos($request->get('USER_AGENT'), static::USER_AGENT)) {
            return false;
        }

        return true;
    }

    /**
     * Retourne l'événement du dépôt qui a déclenché la requête (une des constantes Event::*).
     */
    public function getEvent(): string
    {
        return $this::EVENTS[$this->request->get($this::HEADER)] ?? '';
    }

    /**
     * Retourne la branche du dépôt sur laquelle porte l'événement qui a déclenché la requête.
     */
    abstract public function getBranch(): string;

    /**
     * Retourne l'url web du dépôt dans lequel s'est produit l'événement qui a déclenché la requête.
     */
    abstract public function getUrl(): string;
}

/**
 * Handler pour les dépôts hébergés sur bitbucket.org.
 */
final class Bitbucket extends Handler
{
    protected const HEADER = 'HTTP_X_EVENT_KEY';
    protected const EVENTS = ['repo:push' => Event::PUSH];
    protected const USER_AGENT = 'Bitbucket-Webhooks/';

    public function getBranch(): string
    {
        return $this->request->json()->push->changes[0]->new->name ?? '';
    }

    public function getUrl(): string
    {
        return $this->request->json()->repository->website;
    }
}

/**
 * Handler pour les dépôts hébergés sur github.com.
 */
final class GitHub extends Handler
{
    protected const HEADER = 'HTTP_X_GITHUB_EVENT';
    protected const EVENTS = ['push' => Event::PUSH];
    protected const USER_AGENT = 'GitHub-Hookshot/';

    public function getBranch(): string
    {
        throw new RuntimeException('getBranch() not implemented for github');
    }

    public function getUrl(): string
    {
        throw new RuntimeException('getUrl() not implemented for github');
    }
}

/**
 * Handler pour les dépôts hébergés sur gitlab.org ou sur une forge gitlab auto-hébergée.
 */
final class GitLab extends Handler
{
    protected const HEADER = 'HTTP_X_GITLAB_EVENT';
    protected const EVENTS = ['Push Hook' => Event::PUSH];
    protected const USER_AGENT = 'GitLab/';

    public function getBranch(): string
    {
        return basename($this->request->json()->ref ?? '');
    }

    public function getUrl(): string
    {
        return $this->request->json()->repository->homepage ?? ''; // ou alors : project/web_url
    }
}

/**
 * L'updater exécute des commandes quand il reçoit un webhook depuis une forge.
 */
final class Updater
{
    /** @var string */
    private $host;

    /** @var Hook[] */
    private $hooks;

    /**
     * Initialise l'updater.
     */
    private function __construct(string $host, array $hooks)
    {
        $this->host = $host;
        is_string(key($hooks)) && $hooks = [$hooks];
        $this->hooks = [];
        foreach ($hooks as $hook) {
            $this->hooks[] = new Hook($hook);
        }
    }

    /**
     * Traite la requête.
     */
    private function handleRequest(Request $request = null): void
    {
        is_null($request) && $request = new Request($_SERVER, @file_get_contents('php://input'));
        if ($request->get('HTTP_HOST') !== $this->host) {
            throw new InvalidArgumentException('Invalid host');
        }
        $handler = Handler::from($request);
        if (is_null($handler)) {
            throw new InvalidArgumentException('No handler');
        }

        $when = $handler->getEvent();
        $on = $handler->getBranch();
        $from = $handler->getUrl();

        $documentRoot = $request->get('DOCUMENT_ROOT');
        $defaultDirectory = empty($documentRoot) ? getcwd() : dirname($documentRoot);

        $count = 0;
        foreach ($this->hooks as $hook) {
            if ($hook->match($when, $on, $from)) {
                $hook->run($defaultDirectory);
                ++$count;
            }
        }

        if (0 === $count) {
            throw new RuntimeException('No match');
        }

        printf("Hooks executés : %d\n", $count);
    }

    /**
     * Exécute des commandes quand l'hôte indiqué reçoit un webhook depuis une forge.
     *
     * Exemple d'utilisation :
     * ```php
     * use Docalist\Updater\Updater;
     *
     * require_once '../vendor/docalist/updater/Updater.php';   // Charge docalist/updater
     *
     * Updater::update('exemple.org', [                         // Met à jour le site exemple.org
     *     'when' => 'push',                                    // Quand il y a un push
     *     'on'   => 'main',                                    // Sur la branche main
     *     'from' => 'https://github.com/exemple/site',         // Du dépôt github exemple/site
     *     'do'   => [                                          // En exécutant
     *         "git pull -v",
     *         "bin/composer install",
     *         "bin/console doctrine:migrations:migrate --no-interaction",
     *     ],
     *      'in'   => '/var/www/site'                            // Dans ce répertoire
     *    ]);
     * ```
     *
     * Les commandes indiquées ne sont exécutées que si toutes les conditions sont remplies :
     * - L'entête "host" de la requête doit correspondre au site à mettre à jour.
     * - La requête doit contenir un entête "Content-type: application/json" et le corps de la
     *   requête doit contenir un objet JSON valide.
     * - La requête doit contenir un entête spécifique correspondant au type de dépôt
     *   (e.g. 'X-Gitlab-Event') et l'entête 'User-agent' correspondant (e.g. 'GitLab/14.8.0-pre').
     * - L'événement qui a déclenché le webhook doit correspondre (clause 'when')
     * - Le dépôt qui figure dans le corps de la requête doit correspondre (clause 'from').
     * - Pour un événement de type "push", la branche concernée doit correspondre (clause 'on').
     *
     * Si les conditions ne sont pas remplies, la méthode ne fait rien et retourne le code de
     * statut http "400 Bad Request".
     *
     * Si les conditions sont remplies, la méthode exécute dans l'ordre toutes les commandes
     * indiquées (clause 'do') et retourne un code de statut http "200 Ok".
     *
     * Pour chaque commande exécutée, la méthode affiche :
     * - la commande exacte exécutée (avec un '$' comme pour un prompt bash)
     * - la sortie générée par la commande (stderr est redirigée vers stdout en ajoutant  "2>&1" à
     *   la fin de la commande)
     * - le code de sortie retourné par la commande si celui-ci est différent de zéro
     *   (autrement dit seulement s'il y a une erreur).
     */
    public static function update(string $host, array $hooks, Request $request = null): void
    {
        header('Content-Type: text/plain; charset=UTF-8');

        try {
            (new self($host, $hooks))->handleRequest($request);
        } catch (Throwable $th) {
            $status = 418; // I'm a teapot
            $message = $th->getMessage();

            header(sprintf('HTTP/1.0 %s %s', $status, $message), true, $status);

            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Cache-Control: post-check=0, pre-check=0', false);
            header('Pragma: no-cache');

            echo $message, ".\n";
        }
    }
}
