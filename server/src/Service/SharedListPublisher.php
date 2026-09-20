<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\NewListRequest;
use App\Entity\CatalogueItem;
use App\Entity\SharedList;
use App\Entity\SharedListItem;
use App\Repository\CatalogueItemRepository;
use App\Repository\SharedListRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Turning a selection of item numbers into a shareable list.
 *
 * Item numbers that no longer exist in the catalogue are skipped rather
 * than rejected: a client may hold a number for something since removed,
 * and losing one line is better than refusing the whole list.
 */
final class SharedListPublisher
{
    /**
     * Whether a stored list holds exactly these item numbers, in order.
     *
     * This is what makes a fingerprint match trustworthy without the server
     * knowing anything about who sent it: the key says "I have published
     * this before", and the contents are what confirm it.
     *
     * @param int[] $numbers
     */
    private static function holdsExactly(SharedList $list, array $numbers): bool
    {
        $entries = $list->getEntries()->toArray();

        if (count($entries) !== count($numbers)) {
            return false;
        }

        for ($i = 0, $count = count($entries); $i < $count; $i++) {
            $item = $entries[$i]->getItem();

            if ($item === null || $item->getId() !== $numbers[$i]) {
                return false;
            }
        }

        return true;
    }

    public function __construct(
        private readonly CatalogueItemRepository $items,
        private readonly SharedListRepository $lists,
    ) {
    }

    /** @return array{list: SharedList, skipped: int, created: bool} */
    public function publish(NewListRequest $request): array
    {
        $numbers = $request->items;
        $resolved = [];
        $items = [];
        $skipped = 0;

        for ($i = 0, $count = count($numbers); $i < $count; $i++) {
            $item = $this->items->find($numbers[$i]);

            if (!$item instanceof CatalogueItem) {
                ++$skipped;
                continue;
            }

            $resolved[] = $item->getId();
            $items[] = $item;
        }

        if ($resolved === []) {
            throw new \InvalidArgumentException('None of those item numbers are in the catalogue');
        }

        /*
         * The same person publishing the same selection again gets the list
         * they already have, rather than a second row and a second address.
         *
         * The key comes from the client and is salted with a secret only
         * that device holds, so this matches a republication and nothing
         * else. Two people who happen to pick the same products produce
         * different keys and keep separate lists.
         */
        $fingerprint = $request->fingerprint;

        if ($fingerprint !== null) {
            $existing = $this->lists->findOneByFingerprint($fingerprint);

            if ($existing !== null && self::holdsExactly($existing, $resolved)) {
                return ['list' => $existing, 'skipped' => $skipped, 'created' => false];
            }

            if ($existing !== null) {
                /*
                 * The key matched a list holding something else. With a
                 * 256-bit secret behind it that should never happen, so
                 * treat it as the one thing it could be — a collision — and
                 * refuse to hand back somebody else's list. The new one is
                 * stored without a key, which costs this publication its
                 * idempotency and nothing more.
                 */
                $fingerprint = null;
            }
        }

        $list = new SharedList(Uuid::v4()->toRfc4122(), $fingerprint);
        $position = 0;

        for ($i = 0, $count = count($items); $i < $count; $i++) {
            $item = $items[$i];

            /*
             * A withdrawn product is still included. The person had it; the
             * list records that, and the viewer marks it as withdrawn. A
             * supplier discontinuing a line is not a reason to edit someone
             * else's list.
             */

            // Duplicates are kept: two of the same product is information.
            $list->addEntry(new SharedListItem($item, $position));
            ++$position;
        }

        $this->lists->save($list);

        return ['list' => $list, 'skipped' => $skipped, 'created' => true];
    }
}
