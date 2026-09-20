# Installing Shelf

Two pieces: a single HTML file, and a Symfony application behind it. The
simplest arrangement puts the HTML inside the Symfony app's `public/`
directory so both share one origin and need no configuration.

## Requirements

### Server

| | |
|---|---|
| PHP | 8.2 or newer, CLI and web |
| Extensions | `ctype`, `iconv`, `json`, and the PDO driver for your database |
| Composer | 2.x |
| Database | SQLite, MySQL 8, MariaDB 10.6+, or PostgreSQL 13+ |
| Web server | Anything that serves PHP. Nginx, Apache, Caddy, FrankenPHP, or `symfony serve` while developing |

SQLite needs no server of its own and is a reasonable choice for a catalogue
of this size. Nothing in the code assumes a particular engine.

### TLS

**Serve it over HTTPS.** This is a requirement, not a preference:

- Browsers refuse camera access except over `https://` or `localhost`, so
  scanning does not work at all without it.
- `crypto.subtle` is unavailable in an insecure context, so repeat shares
  cannot be recognised across a browser restart.
- Safari's storage rules treat insecure origins less kindly.

A certificate from Let's Encrypt is enough. During development, `localhost`
counts as secure; a LAN address such as `192.168.1.10` does not.

### Browsers

| | |
|---|---|
| Chrome / Edge | 90+ |
| Safari | 15.4+ (iOS and macOS) |
| Firefox | 90+ |

Sharing uses the Web Share API where present — Safari on iOS and macOS,
Chrome on Android. Elsewhere the Copy and Download buttons cover the same
ground. Scanning uses the browser's own barcode detector when it exists and
the built-in decoders otherwise, so no browser is left out.

## Install

```bash
git clone <your-fork> shelf
cd shelf/server

composer install
cp .env.example .env
```

Edit `.env`:

```dotenv
APP_ENV=prod
APP_SECRET=<a long random string>

# SQLite, relative to the project directory
DATABASE_URL="sqlite:///%kernel.project_dir%/var/catalogue.db"
# or
# DATABASE_URL="mysql://user:pass@127.0.0.1:3306/catalogue?serverVersion=8.0&charset=utf8mb4"
# DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/catalogue?serverVersion=16&charset=utf8"

# Origins allowed to call the API from a browser.
CORS_ALLOW_ORIGIN=*
```

Create the schema and load the starting products:

```bash
composer db
```

That runs three steps, which you can also run yourself:

```bash
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:seed-catalogue          # safe to re-run
```

Before trusting the mapping, since the code has not been booted against a
live Doctrine install:

```bash
php bin/console doctrine:schema:validate
```
Serve `public/` as the document root.

```bash
symfony serve                       # development
# or
php -S localhost:8000 -t public     # development, no Symfony CLI
```

Open `https://your-host/index.html`.

## Hosting the app separately

If the page lives somewhere other than the API, two settings have to agree.

In `index.html`, near the top of the script:

```js
const API_BASE_RAW = 'https://api.example.org';
```

In `server/.env`:

```dotenv
CORS_ALLOW_ORIGIN=https://app.example.org
```

Trailing slashes on `API_BASE_RAW` are stripped automatically. A wildcard
origin is acceptable for a catalogue with no identity in it, but narrowing it
costs nothing.

## Behind a reverse proxy

The address inside a QR code is built from the incoming request, so a proxy
that does not pass the original host will produce links pointing at an
internal name. Configure Symfony to trust it:

```dotenv
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
TRUSTED_HOSTS='^(app\.example\.org)$'
```

Make sure the proxy sends `X-Forwarded-Proto`, or generated links will say
`http` and the codes will be useless on a device that then refuses the
camera.

## Verifying it works

```bash
# the catalogue, nine products after seeding
curl -s https://your-host/api/catalogue | head -c 400

# contributing: 201 the first time, 200 and "restored"/"reused" after
curl -s -X POST https://your-host/api/items \
  -H 'Content-Type: application/json' \
  -d '{"name":"Test product","codes":[{"kind":"barcode","value":"5012345678900"}]}'

# publishing a list, which answers with the address a QR code would carry
curl -s -X POST https://your-host/api/lists \
  -H 'Content-Type: application/json' -d '{"items":[1,4,9]}'
```

Then in a browser: open the app, expand the catalogue, add an item, open the
cart and press **Show QR code**. Scanning it with any phone should open a page
listing what you picked.

No response should ever carry a `Set-Cookie` header:

```bash
curl -sD - -o /dev/null https://your-host/api/catalogue | grep -i set-cookie
```

## Maintenance

```bash
# retire a product; existing lists keep it, marked "no longer sold"
php bin/console app:withdraw-item 4
php bin/console app:withdraw-item 4 --restore

# shed old shared lists; nothing expires on its own
php bin/console app:purge-lists --older-than="90 days" --dry-run
php bin/console app:purge-lists --older-than="90 days"
```

There is deliberately no command that deletes a catalogue item. Rows are
referenced by lists people have shared and by copies on their devices, and
`shared_list_item` holds its foreign key `ON DELETE RESTRICT` so the database
refuses a hard delete rather than quietly emptying someone's list.

## Upgrading

```bash
git pull
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear
```

The app upgrades its own local database when a visitor next opens it. No
action is needed on any device, and no data is lost in the process.

## Troubleshooting

**The camera never starts.** Check the page is on `https://` or `localhost`.
The scan sheet says which of the three causes applies — blocked permission, no
camera, or an insecure page — and offers to take the number typed instead.

**Nothing decodes.** Fill the frame with the code, hold still for focus, and
avoid glare. **Capture now** takes one careful full-resolution attempt. After
nine seconds without a read the sheet starts giving this advice itself.

**A new QR code every time, for the same list.** Generate twice and watch the
network panel:

- No second `POST /api/lists` means the device recalled the address, and what
  is changing is the host part of the URL. See *Behind a reverse proxy* above.
- A second POST answering `"reused": false` means the server did not
  recognise the key. Check the request body actually contains `fingerprint`,
  and that the `shared_list.fingerprint` column exists — `Version20260920100000`
  adds it.
- No `fingerprint` in the body at all means there is no secure context, so
  `crypto.subtle` is missing. Serve over HTTPS.

Inspect what the device remembers from the browser console:

```js
indexedDB.open('shelf').onsuccess = e => {
  const s = e.target.result.transaction('meta','readonly').objectStore('meta');
  s.get('publishedLists').onsuccess = r => console.log('remembered:', r.target.result);
  s.get('deviceSecret').onsuccess  = r => console.log('secret:', r.target.result ? 'present' : 'MISSING');
};
```

Remember that `localhost` and `127.0.0.1` are different origins with separate
storage, as are `http://` and `https://` on the same host.

**`Attempt to read property "value" on array`.** An older build. The DTOs
normalise their own input now, rather than depending on
`phpdocumentor/reflection-docblock` being installed for `MapRequestPayload`
to see inside a typed array.

**405 on a GET to `/api/items`.** Also an older build. Reading a shared list
moved to `/api/lists/{uuid}`; sitting under the POST-only `/api/items` meant
any truncated or redirected request surfaced as a puzzling 405.

**The list vanished from a device.** Most likely Safari's seven-day rule for
origins without recent interaction. Add the page to the home screen, which
exempts it and also encourages Chrome to grant persistent storage. There is
no server-side copy to restore from, by design.
