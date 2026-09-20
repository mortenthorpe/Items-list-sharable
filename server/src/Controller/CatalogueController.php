<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CatalogueItemRepository;
use App\Service\CatalogueItemPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading the catalogue. GET only, identical for every caller, which is
 * precisely why it needs no identity: there is nothing to personalise.
 */
final class CatalogueController extends AbstractController
{
    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly CatalogueItemPresenter $presenter,
    ) {
    }

    /**
     * The whole catalogue, or — with `?since=<ISO 8601>` — only what has
     * changed after that instant. A client holding a copy can ask for the
     * difference instead of the lot; one holding nothing omits the
     * parameter and gets everything.
     */
    #[Route('/api/catalogue', name: 'catalogue_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $since = $request->query->get('since');
        $changedOnly = false;

        if (is_string($since) && $since !== '') {
            $moment = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $since)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $since);

            if ($moment === false) {
                return new JsonResponse(
                    ['error' => 'bad_since', 'message' => 'since must be an ISO 8601 instant'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $changedOnly = true;
            $body = $this->presenter->collection($this->items->findChangedSince($moment));
        } else {
            // include_withdrawn=0 for a caller that only wants what can be
            // bought today; the default carries tombstones so a client can
            // reconcile a copy it already holds.
            $withTombstones = $request->query->get('include_withdrawn') !== '0';
            $body = $this->presenter->collection($this->items->findAllWithCodes($withTombstones));
        }

        $body['partial'] = $changedOnly;

        $response = new JsonResponse($body, Response::HTTP_OK);

        /*
         * Shared, non-personal data, so any cache may hold it — but clients
         * read this again the moment they contribute a product, and a stale
         * copy is precisely the one state that cannot contain what was just
         * added. Cacheable, always revalidated: an ETag makes that cheap.
         */
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setVary(['Accept']);
        $response->setEtag(md5((string) json_encode($body)));
        $response->isNotModified($request);

        return $response;
    }
}
