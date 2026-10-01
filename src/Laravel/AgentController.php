<?php

declare(strict_types=1);

namespace Sentinelo\Agent\Laravel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sentinelo\AgentCore\Http\AgentRequest;
use Sentinelo\AgentCore\Http\Router;

/**
 * The single Laravel entry point. Mirrors the Symfony controller exactly: translate,
 * delegate, translate back. Everything that decides anything lives in {@see Router}.
 */
final class AgentController
{
    public function __construct(private readonly Router $router)
    {
    }

    public function __invoke(Request $request, string $route): JsonResponse
    {
        $response = $this->router->handle(new AgentRequest(
            method: $request->method(),
            path: $route,
            headers: $this->headers($request),
            query: $request->query(),
            body: $this->body($request),
            clientIp: (string) $request->ip(),
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
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : $request->post();
    }
}
