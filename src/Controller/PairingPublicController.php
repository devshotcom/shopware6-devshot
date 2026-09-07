<?php declare(strict_types=1);

namespace Devshot\Connector\Controller;

use Devshot\Connector\Exception\PairingConflictException;
use Devshot\Connector\Exception\PairingDeniedException;
use Devshot\Connector\Service\PairingService;
use Devshot\Connector\Service\WorkspaceSyncService;
use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID], 'auth_required' => false])]
final class PairingPublicController
{
    public function __construct(
        private readonly PairingService $pairing,
        private readonly WorkspaceSyncService $workspaceSync,
    ) {
    }

    #[Route(path: '/devshot/api/pairings', name: 'frontend.devshot.pairing.request', methods: ['POST'])]
    public function requestAccess(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        try {
            return $this->json($this->pairing->requestAccess($payload, $body, $this->signatureHeaders($request)), 202);
        } catch (PairingDeniedException) {
            return $this->json(['error' => 'pairing_denied'], 401);
        }
    }

    #[Route(path: '/devshot/api/pairings/{id}', name: 'frontend.devshot.pairing.status', methods: ['GET'])]
    public function status(string $id, Request $request): Response
    {
        try {
            return $this->json($this->pairing->status(
                $id,
                'GET',
                '/devshot/api/pairings/' . $id,
                '',
                $this->signatureHeaders($request),
            ));
        } catch (PairingDeniedException) {
            return $this->json(['error' => 'pairing_denied'], 401);
        }
    }

    #[Route(path: '/devshot/api/operations', name: 'frontend.devshot.operation.request', methods: ['POST'])]
    public function requestOperation(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);
        $pairingId = $request->headers->get('X-Devshot-Connection', '');
        try {
            return $this->json($this->pairing->requestOperation(
                $pairingId,
                'POST',
                '/devshot/api/operations',
                $body,
                $this->signatureHeaders($request),
                is_array($payload) && is_string($payload['action'] ?? null) ? $payload['action'] : '',
            ), 202);
        } catch (PairingDeniedException) {
            return $this->json(['error' => 'pairing_denied'], 401);
        } catch (PairingConflictException $exception) {
            return $this->json(['error' => 'operation_invalid', 'message' => $exception->getMessage()], 400);
        }
    }

    #[Route(path: '/devshot/api/operations/{id}', name: 'frontend.devshot.operation.status', methods: ['GET'])]
    public function operationStatus(string $id, Request $request): Response
    {
        $pairingId = $request->headers->get('X-Devshot-Connection', '');
        try {
            return $this->json($this->pairing->operationStatus(
                $pairingId,
                $id,
                'GET',
                '/devshot/api/operations/' . $id,
                '',
                $this->signatureHeaders($request),
            ));
        } catch (PairingDeniedException) {
            return $this->json(['error' => 'pairing_denied'], 401);
        }
    }

    #[Route(path: '/devshot/api/workspace-sync', name: 'frontend.devshot.workspace.sync', methods: ['POST'])]
    public function sync(Request $request): Response
    {
        $body = $request->getContent();
        $payload = json_decode($body, true);
        $pairingId = $request->headers->get('X-Devshot-Connection', '');
        if (!is_array($payload)
            || !is_string($payload['operationId'] ?? null)
            || !is_string($payload['uploadEndpoint'] ?? null)
            || !is_string($payload['uploadToken'] ?? null)) {
            return $this->json(['error' => 'invalid_request'], 400);
        }

        try {
            $this->pairing->authorizeSync(
                $pairingId,
                $payload['operationId'],
                'POST',
                '/devshot/api/workspace-sync',
                $body,
                $this->signatureHeaders($request),
            );
            $result = $this->workspaceSync->sync($payload['uploadEndpoint'], $payload['uploadToken']);

            return $this->json(['ok' => true, 'syncId' => $result['syncId']]);
        } catch (PairingDeniedException) {
            return $this->json(['error' => 'pairing_denied'], 401);
        } catch (PairingConflictException $exception) {
            return $this->json(['error' => 'operation_approval_required', 'message' => $exception->getMessage()], 409);
        } catch (\RuntimeException) {
            return $this->json(['error' => 'workspace_sync_failed'], 502);
        }
    }

    /** @return array{timestamp: string, nonce: string, contentDigest: string, signature: string} */
    private function signatureHeaders(Request $request): array
    {
        return [
            'timestamp' => $request->headers->get('X-Devshot-Timestamp', ''),
            'nonce' => $request->headers->get('X-Devshot-Nonce', ''),
            'contentDigest' => $request->headers->get('X-Devshot-Content-SHA256', ''),
            'signature' => $request->headers->get('X-Devshot-Signature', ''),
        ];
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
