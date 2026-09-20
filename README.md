# Shelf

A personal list of the products you own, kept on your own device, with a
catalogue service that has no idea who you are.

Scan the barcode on a pack and the item joins your list, along with the date
you added it and a photo of what you scanned. When you want to hand the list
to someone — a carer, a pharmacist, a partner doing the shopping — it becomes
a QR code holding a single link. None of it needs an account.

```
items-list.html           the app: one self-contained file, no build step
server/                   Symfony 7 + Doctrine catalogue API
server/public/list.html   the page a scanned code opens
```

See **[INSTALL.md](INSTALL.md)** to get it running, and
**[server/README.md](server/README.md)** for the endpoint reference.

## What it does

**Keeps a list on the device.** Every entry records the product, the moment
you added it, and optionally a photo. Adding the same thing twice gives you
two entries with two dates, because a list of what you have is a record of
events rather than a set.

**Reads the codes on packaging.** Barcodes (EAN-13, UPC-A, EAN-8) and QR
codes, in any orientation: mirrored, upside down, or read right to left. The
decoders are written out in full rather than pulled from a library, because
no browser engine on iOS ships a barcode API and every iOS browser is WebKit
underneath. Where the browser does have one it is used, and these are the
fallback.

**Photographs what it scanned.** The frame that produced the read is kept
with the entry, on the device.

**Shares a list as one link.** Pressing share registers the selected item
numbers and puts the resulting address in a QR code. Scanning it opens a page
listing the products by number and name. The code carries an address and
nothing else, so it stays small and reliably scannable however long the list
is.

**Keeps itself in step.** Product names and codes that change in the
catalogue are pulled down on load. What you own is never overwritten by a
sync: your dates, your photos, your entries.

## How it protects privacy

The design starts from the position that the server should not be able to
learn who you are even if it wanted to, rather than promising not to look.

**There is no account, and no table in which one could go.** No users table,
no owner column, no submitter field, no API key. Sessions are switched off in
configuration rather than merely unused, because an enabled session hands
every caller a tracking cookie whether the code reads it or not, and
`Set-Cookie` is stripped from every response. Credentials are never allowed
on cross-origin requests, so a browser will not attach cookies either.

**Your list never reaches the server.** Entries, dates and photos live in
IndexedDB in your browser. There is no endpoint that returns "this person's
list", because nothing is stored that could answer one. What the app does
re-fetch is the catalogue, which is public product data everyone reads.

**Photos never leave the device at all.** Not with a contribution, not in the
QR code, not anywhere. They are held in a separate object store so that
reading your list does not drag megabytes of JPEG around.

**Sharing is by possession of a link.** Publishing stores item numbers,
positions and a timestamp under a random UUID. No owner. Whoever holds the
link sees the list; nobody else can find it. It is a snapshot, so editing or
deleting your own list afterwards leaves it untouched.

**Contributions carry the product, not the person.** Scanning something the
catalogue does not know sends a name and a code. Anything else in the request
body is discarded before it reaches the domain. Submitting the same product
twice produces one row and no record that it happened twice.

**Repeat shares are recognised without identifying you.** Publishing the same
selection again returns the address you already have rather than minting
another. The key that makes this work is a hash of your selection salted with
a random secret generated when your local database is created and never sent
anywhere. The server stores only the result. It cannot tell which lists came
from one device, since two lists from the same device hash to unrelated
values, and it cannot learn a list's contents from its key. A digest the
server could compute itself would be identical for everyone who picked the
same products, and two strangers would silently end up sharing one address.

**Deleting is local and complete.** *Delete my items* clears exactly two
IndexedDB stores. It cannot touch the catalogue, including products you
contributed — those belong to the shared data, not to you.

## Limitations

Worth reading before deciding this fits your problem.

### Your data has no backup

Everything is on one device and nothing is mirrored anywhere. Lose the phone,
clear site data, or switch browser, and the list and its photos are gone.
That follows directly from not storing them server side rather than being an
oversight, but it is the sharpest trade-off in the design.

Browsers make it worse in one specific case: with cross-site tracking
prevention on, Safari deletes script-written storage from an origin you have
not interacted with in seven days of browser use. Adding the page to the home
screen exempts it, and is also what makes Chrome grant persistent storage.
The app requests persistence at startup and reports honestly in the interface
whether it was granted.

There is no export or import. Adding one would turn browser storage from the
only copy into a cache, and is the first thing worth building if this matters
to you.

### No continuity across devices

By construction. Two browsers are two independent users with two lists and
two secrets. Giving one person their list on a second device would mean
storing it server side under some identifier, which is the property the whole
design exists to avoid.

### Sharing needs a connection, or degrades

With no server reachable the code falls back to carrying the item numbers
inside the link, which resolves once there is a connection, and says so in
the interface. Such a code holds roughly 200 items at five-digit numbers, or
110 at ten digits, where a registered list is one short address regardless of
length.

### A shared link is a capability

Anyone holding it can read the list, and there is no way to revoke one. The
UUID is unguessable, so the practical risk is passing the link on, but a
printed code cannot be taken back. Lists never expire on their own either — a
QR code on paper should not stop working because a cron job decided so — so
retention is a decision you make with `app:purge-lists`.

### The contribution endpoint is open

No authentication means anyone who can reach the API can add products or
attach codes to existing ones. There is no rate limiting and no moderation of
submitted names, because the usual mechanisms for both want an identity. On a
private network or a trusted deployment this is fine. Exposed publicly it
needs something in front of it, and that something will probably see addresses
even though the application does not.

### Scanning has real limits

Codes that are simultaneously blurred, noisy, tilted and small may not read.
In a live camera loop another frame a moment later usually succeeds, but a
single bad photograph can fail. The decoder refuses an uncertain read rather
than guessing: across a 170-image test suite it read 154 correctly, missed 16,
and returned wrong digits zero times. Wrong digits are worse than no read,
because nothing prompts you to try again.

Cameras need HTTPS or `localhost`. Over plain HTTP there is no camera and no
`crypto.subtle`, so scanning is replaced by typing the number, and repeat
shares are recognised only by the device's own memory.

### Server logs still see addresses

The application records nothing about callers. Your web server's access log is
another matter, and is the one place an IP will be written. Turn it off or
anonymise it if the promise matters.

### Known issues

- Publishing the same list twice *simultaneously* can race: both requests miss
  the lookup, both insert, and the unique index turns the loser into a 500
  rather than a graceful reuse. Unlikely, since the client suppresses the
  double press, but real.
- Retiring a product is a console command. There is no administrative
  interface.

## Status

The PHP is syntax-checked but has not been run against a live Doctrine
install. The wire contract, code canonicalisation, idempotent contribution,
soft deletion, revival and the sync rules were all exercised end to end
against a SQLite stand-in with the same schema, driven from a real browser.
Run `doctrine:schema:validate` once before trusting the ORM mapping.

The QR encoder and both decoders were verified independently: the encoder
module-for-module against a reference implementation across all 40 versions,
4 error-correction levels and 8 masks, and the decoders by round trip and by
an outside scanner.
