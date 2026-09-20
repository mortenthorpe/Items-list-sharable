<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\NewListRequest;
use App\Repository\CatalogueItemRepository;
use App\Repository\SharedListRepository;
use App\Service\SharedListPresenter;
use App\Service\SharedListPublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Publishing and reading a shared list.
 *
 * The uuid returned here becomes the whole payload of the QR code, so the
 * code itself carries no product data at all — just an address.
 */
final class SharedListController extends AbstractController
{
    /** Beyond this a list is more likely a probe than a shopping trip. */
    private const MAX_NUMBERS = 300;

    public function __construct(
        private readonly SharedListPublisher $publisher,
        private readonly SharedListRepository $lists,
        private readonly SharedListPresenter $presenter,
        private readonly CatalogueItemRepository $items,
    ) {
    }

    /**
     * A list carried in the address itself, rather than stored here.
     *
     * A device that was offline when its code was made could not register a
     * list, so the numbers travel in the URL. Reading is identical for the
     * person scanning it; the only difference is that nothing was written
     * here, which also means such a code keeps working with no row behind
     * it and no retention to think about.
     */
    #[Route('/api/items/by-id/{numbers}', name: 'list_read_numbers', methods: ['GET'], requirements: [
        'numbers' => '\\d+(?:-\\d+)*',
    ])]
    public function readByNumbers(string $numbers): JsonResponse
    {
        $parts = explode('-', $numbers);

        if (count($parts) > self::MAX_NUMBERS) {
            return new JsonResponse(
                ['error' => 'too_many', 'message' => 'That is more items than a list may name directly'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $wanted = [];

        for ($i = 0, $count = count($parts); $i < $count; $i++) {
            $number = (int) $parts[$i];
            if ($number > 0) {
                $wanted[] = $number;
            }
        }

        if ($wanted === []) {
            return new JsonResponse(
                ['error' => 'empty_list', 'message' => 'No item numbers in that address'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $body = $this->presenter->presentNumbers($wanted, $this->items->findByNumbers($wanted));

        if ($body['count'] === 0) {
            return new JsonResponse(
                ['error' => 'unknown_items', 'message' => 'None of those item numbers are in the catalogue'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $response = new JsonResponse($body, Response::HTTP_OK);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    #[Route('/api/lists', name: 'list_publish', methods: ['POST'])]
    public function publish(#[MapRequestPayload] NewListRequest $payload): JsonResponse
    {
        try {
            $result = $this->publisher->publish($payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(
                ['error' => 'empty_list', 'message' => $exception->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $uuid = $result['list']->getUuid();

        // 201 for a list that did not exist, 200 when the same selection
        // was already published and its address is being handed back.
        return new JsonResponse([
            'uuid' => $uuid,
            // The absolute address that goes into the QR code.
            'url' => $this->generateUrl(
                'list_page',
                ['uuid' => $uuid],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'count' => $result['list']->getEntries()->count(),
            'skipped' => $result['skipped'],
            'reused' => !$result['created'],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * A shared list is its own resource, so it lives under /api/lists rather
     * than beneath /api/items. Sharing that prefix with the POST-only
     * submission endpoint invited exactly one confusion: any request that
     * lost its trailing segments — a proxy rewrite, a redirect, a hand-typed
     * URL — arrived at /api/items as a GET and came back 405.
     *
     * The old path is kept as an alias so QR codes already in circulation
     * keep working; it is not used by anything shipped here.
     */
    #[Route('/api/lists/{uuid}', name: 'list_read', methods: ['GET'], requirements: [
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ])]
    #[Route('/api/items/user/{uuid}', name: 'list_read_legacy', methods: ['GET'], requirements: [
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
    ])]
    public function read(string $uuid): JsonResponse
    {
        $list = $this->lists->findOneByUuidWithItems($uuid);

        if ($list === null) {
            return new JsonResponse(
                ['error' => 'unknown_list', 'message' => 'No list with that address'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $response = new JsonResponse($this->presenter->present($list), Response::HTTP_OK);
        // Addressed by an unguessable uuid, but still someone's selection:
        // no shared cache should hold on to it.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
