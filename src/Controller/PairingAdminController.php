<?php declare(strict_types=1);

namespace Devshot\Connector\Controller;

use Devshot\Connector\Exception\PairingConflictException;
use Devshot\Connector\Service\PairingService;
use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID], 'auth_required' => true])]
final class PairingAdminController
{
    public function __construct(private readonly PairingService $pairing)
    {
    }

    #[Route(path: '/api/_action/devshot/pairings', name: 'api.action.devshot.pairing.list', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:read']], methods: ['GET'])]
    public function list(): Response
    {
        return $this->json(['pairings' => $this->pairing->list(), 'operations' => $this->pairing->listOperations()]);
    }

    #[Route(path: '/api/_action/devshot/pairings/invitation', name: 'api.action.devshot.pairing.invite', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']], methods: ['POST'])]
    public function invite(): Response
    {
        return $this->json($this->pairing->createInvitation(), 201);
    }

    #[Route(path: '/api/_action/devshot/pairings/{id}/{decision}', name: 'api.action.devshot.pairing.decide', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']], requirements: ['decision' => 'approve|reject|revoke'], methods: ['POST'])]
    public function decide(string $id, string $decision): Response
    {
        try {
            return $this->json($this->pairing->decide($id, $decision));
        } catch (PairingConflictException $exception) {
            return $this->json(['error' => 'pairing_conflict', 'message' => $exception->getMessage()], 409);
        }
    }

    #[Route(path: '/api/_action/devshot/operations/{id}/{decision}', name: 'api.action.devshot.operation.decide', defaults: [PlatformRequest::ATTRIBUTE_ACL => ['system_config:update']], requirements: ['decision' => 'approve|reject'], methods: ['POST'])]
    public function decideOperation(string $id, string $decision): Response
    {
        try {
            return $this->json($this->pairing->decideOperation($id, $decision));
        } catch (PairingConflictException $exception) {
            return $this->json(['error' => 'operation_conflict', 'message' => $exception->getMessage()], 409);
        }
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
