<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SharedList;

/**
 * The wire shape of a published list: the products, in order, each with the
 * code printed on it so the page can show something scannable.
 */
final class SharedListPresenter
{
    public const SCHEMA = 'list.v1';

    public function __construct(private readonly CatalogueItemPresenter $items)
    {
    }

    /**
     * A list given as bare item numbers rather than stored on the server.
     *
     * This is what a code generated offline carries: the device could not
     * register a list, so the numbers travel in the address itself and are
     * resolved on arrival. Nothing is stored, and the same page renders it.
     *
     * @param array<int, \App\Entity\CatalogueItem> $byNumber
     * @param int[] $numbers in the order asked for, repeats included
     */
    public function presentNumbers(array $numbers, array $byNumber): array
    {
        $data = [];
        $missing = 0;

        for ($i = 0, $count = count($numbers); $i < $count; $i++) {
            if (!isset($byNumber[$numbers[$i]])) {
                ++$missing;
                continue;
            }

            $data[] = $this->items->one($byNumber[$numbers[$i]]);
        }

        return [
            'schema' => self::SCHEMA,
            'uuid' => null,          // nothing was stored; the address is the list
            'created_at' => null,
            'count' => count($data),
            'missing' => $missing,
            'data' => $data,
        ];
    }

    public function present(SharedList $list): array
    {
        $entries = $list->getEntries()->toArray();
        $data = [];

        for ($i = 0, $count = count($entries); $i < $count; $i++) {
            $item = $entries[$i]->getItem();

            if ($item === null) {
                continue;
            }

            $data[] = $this->items->one($item);
        }

        return [
            'schema' => self::SCHEMA,
            'uuid' => $list->getUuid(),
            'created_at' => $list->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'count' => count($data),
            'data' => $data,
        ];
    }
}
