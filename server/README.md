# Shelf catalogue API

A Symfony 7 service with two jobs: emit the product catalogue, and accept
products people scan. It has no concept of who is calling it.

## Endpoints

| Method | Path             | Purpose |
|--------|------------------|---------|
| `GET`  | `/api/catalogue` | The whole catalogue |
| `POST` | `/api/items`     | Contribute a scanned product |
| `POST` | `/api/items/{uuid}/codes` | Record another code for a known product |
| `POST` | `/api/lists`     | Publish a list of item numbers, get its address |
| `GET`  | `/api/lists/{uuid}` | Read a published list |
| `GET`  | `/items/user/{uuid}` | The page a scanned QR code opens |

### `GET /api/catalogue`

```json
{
  "schema": "items.v2",
  "count": 9,
  "data": [
    {
      "id": 1,
      "uuid": "bdd640fb-0667-4ad1-9c80-317fa3b1799d",
      "name": "Band-aid-wide",
      "barcode": "5701120030018",
      "qr_code": "https://id.example.org/01/05701120030018",
      "codes": [
        { "kind": "barcode", "value": "5701120030018" },
        { "kind": "qr", "value": "https://id.example.org/01/05701120030018" }
      ]
    }
  ]
}
```

`barcode` and `qr_code` are conveniences for older clients; `codes` is the
real shape. Cacheable by anyone — it is the same response for everybody.

### `POST /api/items`

```json
{
  "name": "Paracetamol 500mg",
  "codes": [{ "kind": "barcode", "value": "5012345678900" }]
}
```

* `201` with the item when the product is new.
* `200` with the **existing** item when any of its codes is already known.
  Contributing twice is a no-op rather than a duplicate.
* `422` when the name is missing or no usable code was supplied.

Anything else in the body is discarded before it reaches the domain.

### `POST /api/items/{uuid}/codes`

```json
{ "kind": "barcode", "value": "5000112637939" }
```

* `201` when the code is attached and now resolves to this product.
* `200` when this product already had it — repeating is harmless.
* `409` when the code identifies a *different* product. Two products
  claiming one code would make scanning ambiguous, so it is refused rather
  than merged or stolen.
* `404` unknown uuid, `422` unusable code.

### Finding a product by either code

A barcode and a GS1 Digital Link QR share a GTIN, so `CodeNormaliser` links
them with no help: register by one and the other finds it immediately.

That fails the moment a QR carries something else — a marketing URL, a batch
code. `https://shop.example/p/gamma-spray` has no computable relationship to
the EAN beside it on the box, and no normalisation can invent one. The
connection has to be *observed*, by someone scanning both codes off the same
package. Two routes do that:

1. Register both at once — `POST /api/items` takes a `codes` array, and the
   client offers to scan the pack's other code before saving.
2. Attach one later — `POST /api/items/{uuid}/codes`, which the client offers
   whenever a scanned code is unknown ("this is a product already in the
   catalogue").

Either way both spellings end up in `item_lookup_key` pointing at one row, and
the product is retrievable by whichever code is to hand.

### Keeping a client in step

Every catalogue item carries `created_at` and `updated_at`. `updated_at`
moves whenever anything a client mirrors changes — the name, or the codes —
so `touch()` is called from `rename()` and from `addCode()` rather than left
to the caller to remember.

A client stores, against each item it has copied, the `updated_at` it saw at
the time. On load it compares: newer on the server means the copy is stale,
and the title and codes are pulled again. What the person owns — the date
they added it, their photo, the entry itself — is never touched by a sync,
because the catalogue describes the product while the entry records their
having it.

`GET /api/catalogue?since=<ISO 8601>` returns only items changed after that
instant, with `"partial": true` in the envelope, for clients that already
hold a copy. Omit it and you get everything.

### Withdrawal is a tombstone, never a deletion

`deleted_at` on a catalogue item marks it withdrawn. Nothing is removed,
because catalogue rows are referenced by lists people have already shared and
by copies on their devices — deleting one would silently rewrite what they
saved. `app:withdraw-item <id>` sets it; `--restore` clears it. There is
deliberately no command that deletes an item, and `shared_list_item` holds its
foreign key `ON DELETE RESTRICT` so the database refuses a hard delete rather
than quietly emptying someone's list.

Withdrawing touches `updated_at` as well. That is what tells every client
holding a copy there is something new to learn; a tombstone nobody is told
about is no better than a deletion.

Consequences, all of them deliberate:

* **Shared lists are unaffected.** A published list keeps every item it had,
  in order. The viewer shows a withdrawn one exactly like the others with a
  *no longer sold* note. It records what someone had when they shared it.
* **Personal lists are unaffected.** The client marks the entry and leaves it
  alone — the person still owns the thing.
* **It stops being offered.** Withdrawn items are filtered out of the browse
  list and out of the "link to an existing product" picker.
* **The payload still carries it.** `GET /api/catalogue` includes withdrawn
  items by default, flagged, because a client that never receives the
  tombstone cannot tell "withdrawn" from "never heard of it".
  `?include_withdrawn=0` gives only what is current.

### Re-contributing revives

Scanning a withdrawn product is evidence it still exists, so
`POST /api/items` with a code that matches one brings it back rather than
leaving a live product marked gone or creating a second row for it. The
response is `200` with `"restored": true`. Linking a code to a withdrawn item
does the same. The client detects the case on scan and calls the endpoint
instead of silently adding from its local copy.

An item the client holds that has vanished from the catalogue *entirely* —
not withdrawn, actually absent from the payload — is still reported and kept.

### Sharing a list

`POST /api/lists` with `{"items": [1, 3, 3, 9]}` returns:

```json
{
  "uuid": "f02217a6-5814-471a-b939-bef79846ca95",
  "url": "https://host/items/user/f02217a6-5814-471a-b939-bef79846ca95",
  "count": 4,
  "skipped": 0
}
```

That `url` is the QR code's entire payload. The code no longer carries the
numbers, so it stays a small, easily scanned symbol no matter how long the
list is, and a scanner shows a link rather than a wall of digits.

Item numbers that are no longer in the catalogue are skipped and counted in
`skipped` rather than failing the whole request. Duplicates are kept: two of
the same product is information, not an error.

`GET /api/lists/{uuid}` returns the list as JSON. `GET /items/user/{uuid}`
serves `public/list.html`, which reads the uuid from its own address and
fetches that JSON — so the response is identical for every list and the
address never appears in the markup.

### One row per list, not one per press

Publishing the same selection twice used to leave a second list behind, so
every press of the share button added to the pile. `shared_list` now carries
a `fingerprint` with a unique index: a matching one returns the existing list
and a `200` with `"reused": true` instead of minting a row.

**The server does not compute that key, and deliberately so.** A digest it
could derive would be identical for everyone who picked the same products,
and two strangers would silently end up sharing one address. Instead the
client hashes its selection together with a 32-byte random secret generated
when its local database is first created — no prompt, nothing to configure —
and never sent anywhere. The server stores only the result.

That gives the property worth having: the same person republishing the same
list matches, while nobody else can produce that value. The server cannot
tell which lists came from one device, since two lists from the same device
hash to unrelated values, and it cannot learn what is in a list from its key.

The field is optional. A client that omits it — no secure context, so no
`crypto.subtle` — simply gets a fresh list each time, which is worse
housekeeping but never wrong. Clients also remember the last list they
published and reuse that address when the selection is unchanged, so the
common case costs no request at all.

The column is nullable, so lists published before the migration keep
resolving; they take part in no matching. `app:purge-lists --older-than="30
days"` remains the way to shed old ones.

**A matching key is confirmed against the contents before it is honoured.**
If a key names a list holding something else, that can only be a collision,
so the incoming list is stored fresh and unkeyed rather than handing back
somebody else's address. It costs that one publication its idempotency and
nothing more. With a 256-bit secret behind every key this should never fire;
it exists so that the one thing it would otherwise do is impossible.

There is no check that a secret is globally unique, and there cannot be a
useful one: verifying that would mean sending it to the server, which would
give the server a stable per-device identifier and undo the whole point.
Thirty-two bytes from a CSPRNG makes a collision vanishingly unlikely on its
own, and the contents check above means even one would be harmless. The
client refuses to invent a secret at all when no cryptographic source is
available, rather than falling back to `Math.random()` and producing
something guessable that looks legitimate; it also never replaces a secret
once written, since doing so would orphan every list already published from
that device.

Because the key is unguessable rather than verifiable, a caller could in
principle replay one it had already seen and be handed that list's address.
With 256 bits of secret that is not a practical route to anything, and the
response carries only the address and a count, never the contents.

### A list with nothing stored behind it

`GET /items/by-id/1-4-4-9` renders the same page from the numbers in the
address, backed by `GET /api/items/by-id/{numbers}`. Nothing is written
here; the address *is* the list.

This is what a code generated while the device was offline carries. The app
cannot register a list without a server, and a code reading `1-4-4-9` is
useless to whoever scans it — so the numbers travel inside a link to this
same site, which opens the same page as soon as there is a connection.

Order and repeats are preserved. Numbers the catalogue no longer knows are
counted in `missing` and the rest are shown, rather than failing the lot;
only when none are known does it answer `404`. A list may name at most 300
items directly — beyond that it is more likely a probe than a shopping trip.

Such a code holds roughly 200 items at five-digit numbers, or about 110 at
the full ten digits, at the highest error correction. Longer lists need a
connection at the moment of sharing.

Copying or sharing takes a different view of the same list. Those happen with
the app in front of the person, so a connection is a fair assumption even if
there was none when the code was drawn: both try to register the list first
and hand over the short `/items/user/{uuid}` address instead. When that
succeeds the panel adopts it, so the code on screen and the link being sent
never disagree. When it fails, the address naming the items is handed over
unchanged and the person is told why.

### The privacy cost of sharing

Worth stating plainly, because it is a real change. Until now nothing about a
person's list existed on the server; the QR code held the numbers and that was
that. A published list is a stored record of one selection.

It still carries no identity: no owner column, no address, no session, and no
way to tell that two lists came from the same person. The uuid is the only
handle, it is unguessable, and whoever holds it sees the list. But it does
persist, so `app:purge-lists --older-than="30 days"` exists. Nothing expires on
its own — a printed QR code should not stop working because a cron job decided
so — which makes retention your choice to make rather than a default you
inherit.

### Caching and read-after-write

`GET /api/catalogue` is public but revalidated on every request, with an
ETag to keep that cheap. It used to carry `max-age=60`, which is wrong for
this resource for one specific reason: a client reads the catalogue again
immediately after contributing to it, and a cached copy is the one state
guaranteed not to contain what was just added. The contribution succeeded,
the refetch returned a catalogue without it, and the product never reached
the person's list.

Clients should not depend on that alone. The app reads the item out of the
write's own response instead of hunting for it in a refetched catalogue, and
sends `cache: 'no-store'` when it does refetch.

### A note on payload shapes

`MapRequestPayload` cannot see inside an `array`. A promoted `array $codes`
says nothing about what it holds, and the `@var SubmittedCode[]` hint is only
read when `phpdocumentor/reflection-docblock` is installed — so without that
package the elements arrive as plain arrays and the first `->value` raises
*Attempt to read property "value" on array*.

Installing the package fixes it. Relying on that is fragile, so the DTOs
normalise their own input instead: `CodeShape` turns objects, `{kind, value}`
rows, `{type, code}` rows and bare strings into `SubmittedCode`, and
`NewListRequest` casts numeric strings to ints. The accepted shape is a
property of these classes rather than of the dependency tree, and junk
normalises to nothing — which fails `Assert\Count` and returns a clean 422
rather than a 500.

### A note on route shapes

The list-read endpoint is `/api/lists/{uuid}`, not `/api/items/user/{uuid}`.
The earlier path put a *list* underneath `/api/items`, which is the POST-only
submission endpoint, and anything that shortened such a URL — a proxy rewrite,
a redirect, a hand-edited address — landed on `/api/items` as a GET and came
back `405 Method Not Allowed`, apparently reporting a request nobody made.

`/api/items/user/{uuid}` is kept as an alias so QR codes already printed keep
resolving. Every `{uuid}` placeholder now carries an explicit UUID
requirement, so no route can swallow a neighbouring path.

The same class of bug bites from the client side: `POST /api/items/` with a
trailing slash gets redirected, and browsers replay a redirected POST as a
**GET**, producing exactly this 405. The app strips trailing slashes from
`API_BASE` once, at the top of the file, so it cannot generate one.

## What is deliberately absent

No users table, no session, no cookies, no API key, no submitter column, no
client address stored anywhere. `PrivacySubscriber` strips `Set-Cookie` from
every response and refuses `Access-Control-Allow-Credentials`, so a browser
will not attach cookies from another origin either. Sessions are switched off
in `framework.yaml` rather than merely unused, because an enabled session
hands every caller a tracking cookie whether the code reads it or not.

`origin` on an item records *how* it arrived (`seed` or `contributed`), never
*who* brought it. Two people contributing the same product produce one row
and no record that it happened twice.

If you put this behind nginx or Apache, remember the access log. That is the
one place an address will otherwise be written; turn it off or anonymise it
if the promise matters to you.

## Identity of a product

Several printed codes mean the same product: the EAN-13 on the box, the UPC-A
spelling of the same GTIN, and the GS1 Digital Link inside the QR code. So
the schema separates the two ideas:

* `product_code` — the code exactly as printed, kept verbatim so a client can
  match what it scanned. An item may have several.
* `item_lookup_key` — the canonical identifier, unique across the table. This
  is what makes a scan resolve to exactly one product, and what makes
  contributions idempotent.

`CodeNormaliser` produces the canonical form: numeric codes become a
zero-padded GTIN-14, and a digital link is unwrapped to the number inside it.
Anything else keeps its case, since a QR payload is often a URL and URL paths
are case-sensitive.

Putting the key on `product_code` does not work, and the seeder is what proves
it: a product's barcode and its digital link reduce to the same key, so a
unique index there forbids storing both.

## Running it

```bash
composer install
cp .env.example .env          # SQLite by default; edit DATABASE_URL for MySQL/Postgres
composer db                   # create, migrate, seed
symfony serve                 # or: php -S localhost:8000 -t public
```

Then point the frontend at it. `API_BASE` at the top of `items-list.html` is
`''`, meaning same origin — serve the HTML from this app's `public/` and it
just works. Host it elsewhere and set `API_BASE` to this service's URL, and
set `CORS_ALLOW_ORIGIN` in `.env` to the frontend's origin.

If the API is unreachable the frontend falls back to its built-in sample
catalogue rather than showing an empty page.

## Layout

```
src/
  Controller/CatalogueController.php       GET  /api/catalogue
  Controller/ItemSubmissionController.php  POST /api/items
  Dto/NewItemRequest.php                   the entire accepted payload
  Dto/SubmittedCode.php
  Entity/CatalogueItem.php                 id (the number clients export), uuid, name
  Entity/ProductCode.php                   a code as printed
  Entity/ItemLookupKey.php                 canonical identifier, unique
  Repository/                              queries only
  Service/CodeNormaliser.php               canonical form; mirrors the browser
  Service/CatalogueWriter.php              the contribution use case
  Service/CatalogueItemPresenter.php       owns the wire shape
  EventSubscriber/PrivacySubscriber.php    CORS, no cookies, no credentials
  Command/SeedCatalogueCommand.php         app:seed-catalogue, safe to re-run
```

## Caveat

The PHP here is syntax-checked but has not been run against a live Doctrine
install — `composer install` could not reach packagist from the environment it
was written in. The wire contract, the canonicalisation rules and the
idempotency behaviour *were* exercised end to end against a SQLite-backed
stand-in with the same schema, including from the browser client. Expect to
run `doctrine:schema:validate` once before trusting the mapping.
