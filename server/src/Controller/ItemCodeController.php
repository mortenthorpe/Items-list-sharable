<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AdditionalCode;
use App\Dto\CodeShape;
use App\Repository\CatalogueItemRepository;
use App\Service\CatalogueItemPresenter;
use App\Service\CatalogueLinker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recording a second code for a known product, so it can afterwards be
 * found by either the barcode or the QR code on its packaging.
 *
 * The item is addressed by its uuid — a property of the product, not of the
 * person adding the code. Nothing about the caller is read or stored.
 */
final class ItemCodeController extends AbstractController
{
    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly CatalogueLinker $linker,
        private readonly CatalogueItemPresenter $presenter,
    ) {
    }

    #[Route('/api/items/{uuid}/codes', name: 'item_code_add', methods: ['POST'], requirements: [
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ])]
    public function add(string $uuid, #[MapRequestPayload] AdditionalCode $payload): JsonResponse
    {
        $item = $this->items->findOneBy(['uuid' => $uuid]);

        if ($item === null) {
            return new JsonResponse(
                ['error' => 'unknown_item', 'message' => 'No product with that uuid'],
                Response::HTTP_NOT_FOUND,
            );
        }

        // Run it through the same normaliser the submission endpoint uses,
        // so "EAN13" and "qr_code" mean here what they mean there.
        $code = CodeShape::one(['kind' => $payload->kind, 'value' => $payload->value]);

        if ($code === null) {
            return new JsonResponse(
                ['error' => 'unusable_code', 'message' => 'That code cannot be used as an identifier'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $result = $this->linker->attach($item, $code->kind, $code->value);
        } catch (\DomainException $exception) {
            return new JsonResponse(
                ['error' => 'code_taken', 'message' => $exception->getMessage()],
                Response::HTTP_CONFLICT,
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => 'unusable_code', 'message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(
            $this->presenter->one($result['item']),
            $result['outcome'] === CatalogueLinker::ATTACHED
                ? Response::HTTP_CREATED
                : Response::HTTP_OK,
        );
    }
}
