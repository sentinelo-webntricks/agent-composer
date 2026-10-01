<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Symfony;

use Sentinelo\AgentCore\Http\AgentRequest;
use Sentinelo\AgentCore\Http\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The single Symfony entry point: translate HttpFoundation into the agent's own
 * request/response objects and back.
 *
 * All the behaviour lives in {@see Router}, which knows nothing about Symfony — that
 * is what lets the same routes serve a Laravel site and be tested with no framework at
 * all.
 */
final class AgentController
{
    public function __construct(private readonly Router $router)
    {
    }

    public function __invoke(Request $request, string $route): JsonResponse
    {
        $response = $this->router->handle(new AgentRequest(
            method: $request->getMethod(),
            path: $route,
            headers: $this->headers($request),
            query: $request->query->all(),
            body: $this->body($request),
            clientIp: (string) $request->getClientIp(),
        ));

        // Rien de ce que l'agent répond ne doit finir dans un cache : la
        // plateforme lirait un état d'hier sans rien pour l'en avertir.
        // Voir CacheHeaders, et la panne mesurée qui l'a motivée.
        return new JsonResponse(
            $response->payload,
            $response->status,
            \Sentinelo\AgentCore\Http\CacheHeaders::all(),
        );
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $raw = $request->getContent();
        if ($raw === '') {
            // A form-encoded POST is still a legitimate call; falling back to the
            // request bag rather than an empty array keeps a curl -d test working.
            return $request->request->all();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
