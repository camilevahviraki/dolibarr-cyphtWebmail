# Contributing to CyphtWebmail

How this module is put together and how to extend it. For installing and
running it, see [README.md](README.md).

Read [Project layout](#project-layout) first; it explains where things go and
why, and the rest of this file assumes it.

## Table of contents

- [Project layout](#project-layout)
- [How it works](#how-it-works)
- [Adding a new Cypht module set](#adding-a-new-cypht-module-set)
- [Adding a new bridge endpoint](#adding-a-new-bridge-endpoint)
- [Conventions and workflow](#conventions-and-workflow)

## Project layout

```
cyphtWebmail/
├── index.php                       the Dolibarr page hosting the Cypht iframe
├── admin/
│   ├── setup.php                   settings, build status, Generate button
│   └── build/                      endpoints the Generate button calls
│       ├── build.php               runs the build, streams the log
│       └── build_cancel.php
├── bridge/                         HTTP endpoints Cypht calls back into Dolibarr
│   └── contacts.php
├── class/
│   ├── webmail.class.php           facade; every caller uses this
│   ├── auth/                       secrets, HMAC assertions, SSO login
│   │   ├── token.class.php
│   │   └── login.class.php
│   ├── install/                    everything that builds or installs
│   │   ├── paths.class.php         paths, installed/built version bookkeeping
│   │   ├── environment.class.php   builds and writes Cypht's .env
│   │   ├── vendorlayout.class.php  flat-Composer-layout bridge
│   │   ├── moduleinstaller.class.php  installs cypht/modules/* into vendored Cypht
│   │   ├── upstreampatches.class.php  patches upstream Cypht gaps
│   │   └── pipeline.class.php      the three-step build
│   └── integration/                Dolibarr data exposed to Cypht
│       └── contactsource.class.php
├── core/
│   ├── modules/                    Dolibarr module descriptor
│   └── triggers/                   USER_DELETE / USER_MODIFY cleanup
├── cypht/modules/                  ★ our Cypht module sets, native layout
│   ├── dolibarr_contacts/          contacts as a Cypht address book
│   └── site/                       session, auth and DB-backed user config
├── scripts/
│   └── build.php                   command line build, see above
├── js/                             browser code for the Dolibarr-side pages
│   ├── cypht-url-sync.js           keeps the URL in step with the iframe
│   └── admin/setup.js              build page
├── langs/en_US/                    translations
├── sql/                            table definitions, run on activation
├── docs/upstream-patches/    
├── composer.json                   the jason-munro/cypht version constraint
├── composer.lock                   the exact version a build resolves to
├── public/                         built app (generated, git-ignored)
└── vendor/                         Composer (Cypht lives here, git-ignored)
```

The split inside `class/` is the one worth keeping to. `install/` is everything
that puts Cypht on disk and compiles it, `auth/` is everything that proves who a
user is, `integration/` is Dolibarr data made available to Cypht. New bridge
endpoints get a class in `integration/`, not in `install/`.

Front-end code belongs in `js/`, loaded with `dol_buildpath()` and a `filemtime`
cache-buster, never inlined into a PHP string. Anything that runs inside Cypht
instead goes in a module set under `cypht/modules/`.

## How it works

```
Dolibarr page (index.php)
  │  performSsoLogin()  ── HMAC token ──▶  Cypht cypht_login()
  │
  └─ <iframe> ──▶ public/index.php  (the built Cypht app)
                      │
                      ├─ Custom_Auth          verifies the HMAC token
                      ├─ Custom_Session       own session files
                      ├─ Custom_User_Config   reads/writes llx_cyphtwebmail_userconfig
                      └─ dolibarr_contacts    HTTP ──▶ bridge/contacts.php
```

**Single sign-on.** Dolibarr mints a 60-second HMAC token proving "this is user
X", signed with a shared secret in `llx_const`. Cypht's `Custom_Auth` verifies
it in place of a password. No mailbox credential is involved.

**Why the overrides exist.** Cypht encrypts its settings file with the user's
login password. Under SSO there is no such password - the token is different on
every request - so nothing could ever be decrypted again. `Custom_User_Config`
replaces that with database storage keyed on the Dolibarr user id, encrypting
only the mailbox passwords. Tiki's Cypht integration solves the same problem the
same way.

**Why an iframe.** Cypht ships its own Bootstrap 5 bundle and emits a full HTML
document. Inlining it means reconciling two complete CSS frameworks and two
session models. The iframe is what keeps them apart. The URL sync
(`?cypht=page%3Dcontacts`) gives back reload and bookmarking.

## Adding a new Cypht module set

This is the main extension point. A module set is Cypht's own plugin format -
see the [Cypht module docs](https://cypht.org/modules/) and the sets under
`vendor/jason-munro/cypht/modules/` for working examples.

### 1. Create the folder

```
cypht/modules/<your_module>/
```

Mirror the native layout exactly:

| File | Required | Purpose |
|---|---|---|
| `README.md` | recommended | what the module set does |
| `setup.php` | **yes** | registers handlers/outputs and returns input filters |
| `modules.php` | **yes** | the handler and output classes |
| `hm-<name>.php` | optional | library classes, required from `modules.php` |
| `site.css`, `site.js` | optional | concatenated into the build |

Good models to copy: `gmail_contacts` (smallest complete set), `ldap_contacts`
(a full contact source), `site` (overriding shipped behaviour).

### 2. Register it

Add the name to `CYPHT_MODULES` in
`class/install/environment.class.php`:

```php
'CYPHT_MODULES' => 'core,contacts,dolibarr_contacts,<your_module>,imap,smtp,...',
```

**Order matters.** A module set must come *after* anything it attaches to. A
contact source must follow `contacts`, because it hooks that module's
`load_contacts` handler.

If it is missing from this list, `config_gen.php` never scans its `setup.php`
and it is silently ignored.

### 3. Press Generate

`CyphtModuleInstaller` discovers module sets by globbing `cypht/modules/*` and
copies every file it finds. **No PHP change is needed** to install a new one -
creating the folder and adding it to `CYPHT_MODULES` is the whole job.

Files are merged into the destination, not replaced, which is how
`cypht/modules/site/lib.php` overrides one file of a set Cypht already ships
without disturbing its `modules.php`, `setup.php` or `site.js`.

### 4. Verify

```bash
ls vendor/jason-munro/cypht/modules/<your_module>/
grep "<your_handler>" vendor/jason-munro/cypht/config/dynamic.php
```

The build log also names what it installed:

```
Cypht module sets installed: dolibarr_contacts, site.
```

## Adding a new bridge endpoint

Cypht runs as its own application and has no Dolibarr context. When a module
set needs Dolibarr data, it calls an endpoint under `bridge/` over HTTP. That
keeps permission checks, entity scoping and schema knowledge on the Dolibarr
side. `bridge/contacts.php` is the reference implementation.

An endpoint must:

1. Define the `NOLOGIN` family of constants and load `main.inc.php`
2. Verify an HMAC assertion - **with its own purpose tag**, so a token minted
   for one endpoint cannot be replayed against another:

   ```php
   $expected = hash_hmac('sha256', $login.'|'.$timestamp.'|contacts', $secret);
   ```

3. Enforce a 60-second replay window
4. Resolve the user, realign `$conf->entity`, and check the relevant permission
5. Return JSON, `no-store`

Read the value with a strict filter - `aZ09arobase` for a login, `aZ09` for a
token. Do **not** use `alpha`; it runs HTML-stripping passes that will mangle a
signature.

Then expose the URL as an env key in `buildEnvOverrides()` and read it in your
module set with `Hm_Environment::get()`.

## Conventions and workflow

### Conventions

- Follow Dolibarr's coding style: tabs, `array()`, `dol_escape_htmltag()` on
  output, `GETPOST()` with an explicit filter on input.
- **Never edit `vendor/jason-munro/cypht/`.** It is Composer-managed and a
  `composer update` will silently revert you. Put changes in `cypht/modules/`
  and let the installer deploy them.
- Copy the query shape from Dolibarr core rather than reconstructing SQL -
  column names and module keys are not always what they look like. The invoice
  module key is `invoice` while its permission is still `facture`.
- One concern per class. Installing module sets belongs in
  `CyphtModuleInstaller`, not in whichever bridge happened to need it first.
- No code inside strings. PHP, JS and CSS each live in their own file so an
  editor can highlight them and a linter can read them.
- Comments state constraints, not history. "Must not remove servers: this
  config is the only store" is useful; "this used to work differently" is not.

### Working on the Cypht side

The module sets in `cypht/modules/` are ordinary PHP files - editable and
syntax-highlighted. They are copied into the vendored Cypht by the installer;
they are never loaded by Dolibarr itself.

Useful upstream reading:

- Module sets: `vendor/jason-munro/cypht/modules/*/setup.php`
- Handler/output base classes: `vendor/jason-munro/cypht/lib/modules.php`
- Routing: `vendor/jason-munro/cypht/lib/dispatch.php`
- Build: `vendor/jason-munro/cypht/scripts/config_gen.php`

### Testing checklist

- [ ] Build completes, `public/` refreshed
- [ ] SSO logs in without a Cypht login screen
- [ ] Mail lists and messages load
- [ ] Contacts appear under Personal Addresses with source `dolibarr`
- [ ] Compose autocomplete finds a Dolibarr contact
- [ ] Reload keeps the current Cypht page
- [ ] Deleting a test user removes their `llx_cyphtwebmail_userconfig` row
