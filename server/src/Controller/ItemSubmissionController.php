<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\NewItemRequest;
use App\Service\CatalogueItemPresenter;
use App\Service\CatalogueWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receiving a scanned product.
 *
 * The payload is bound to NewItemRequest, so only name and codes are read;
 * any other field a client sends is discarded before it reaches the domain.
 * Nothing about the caller is recorded — not their address, not a session,
 * not a contribution count.
 */
final class ItemSubmissionController extends AbstractController
{
    public function __construct(
        private readonly CatalogueWriter $writer,
        private readonly CatalogueItemPresenter $presenter,
    ) {
    }

    #[Route('/api/items', name: 'item_submit', methods: ['POST'])]
    public function submit(#[MapRequestPayload] NewItemRequest $payload): JsonResponse
    {
        try {
            $result = $this->writer->submit($payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => 'unusable_code', 'message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // 201 for a new product, 200 when the code was already known —
        // including when a withdrawn one was brought back. Either way the
        // client gets the item it should reference.
        $body = $this->presenter->one($result['item']);
        $body['restored'] = $result['restored'];

        return new JsonResponse(
            $body,
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
}
