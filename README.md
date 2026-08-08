# CyphtWebmail

A Dolibarr module that embeds [Cypht](https://cypht.org), a lightweight open
source webmail client, inside Dolibarr - with single sign-on, Dolibarr contacts
as an address book, and mail accounts stored in Dolibarr's own database.

- Cypht upstream: <https://github.com/cypht-org/cypht>
- Cypht docs: <https://cypht.org>
- Dolibarr: <https://www.dolibarr.org>

Cypht is LGPL-2.1, Dolibarr is GPL-3.0. This module is GPL-3.0.

## Table of contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Setup and first build](#setup-and-first-build)
- [Daily use](#daily-use)
- [How it works](#how-it-works)
- [Where data is stored](#where-data-is-stored)
- [Adding a new Cypht module set](#adding-a-new-cypht-module-set)
- [Adding a new bridge endpoint](#adding-a-new-bridge-endpoint)
- [Project layout](#project-layout)
- [Configuration reference](#configuration-reference)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)

## What it does

| Feature | Notes |
|---|---|
| Embedded webmail | Cypht runs inside a Dolibarr page, with Dolibarr's menu and header |
| Single sign-on | Dolibarr users reach their mail without a second login |
| Dolibarr contacts in Cypht | Third parties and contacts appear as a read-only address book, including compose autocomplete |
| Accounts in the database | Mail accounts and settings live in `llx_cyphtwebmail_userconfig`, not in files |
| Encrypted credentials | Mailbox passwords are encrypted at rest; the rest of the config stays readable SQL |
| Deep-linkable | The browser URL follows the current Cypht page, so reload and bookmarking work |
| Lifecycle aware | Deleting or renaming a Dolibarr user cleans up or follows their webmail data |

## Requirements

- Dolibarr 19+ (developed against **24.0**)
- PHP 8.0+ with `curl`, `openssl`, `mbstring`, `dom`, `PDO`
- **PHP CLI on the server** - the build step shells out to `php scripts/config_gen.php`
- **Composer** available to the webserver user, or a `composer.phar` in the module root
- MySQL/MariaDB or PostgreSQL (whatever Dolibarr already uses)
- `proc_open()` and `exec()` enabled (commonly disabled on shared hosting)

## Installation

### 1. Put the module in Dolibarr's custom folder

The module must sit in Dolibarr's `custom` directory - the same place every
external Dolibarr module goes:

```
<dolibarr>/htdocs/custom/cyphtWebmail
```

For a typical XAMPP install that is:

```
C:\xampp\htdocs\dolibarr\htdocs\custom\cyphtWebmail
```

Clone or copy it there:

```bash
cd <dolibarr>/htdocs/custom
git clone <repository-url> cyphtWebmail
```

Confirm `custom` is enabled in `<dolibarr>/htdocs/conf/conf.php`:

```php
$dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
```

### 2. Install the PHP dependencies

Cypht itself is a Composer dependency, not vendored in git:

```bash
cd <dolibarr>/htdocs/custom/cyphtWebmail
composer install
```

This pulls `jason-munro/cypht` into `vendor/`. You can skip this step if
Composer is reachable by the webserver - the build does it for you.

If you received the module as an archive it may already contain `vendor/`. If it
also contains `public/`, it was packaged wrongly; see
[Build where it will run](#build-where-it-will-run).

### 3. Enable the module

**Home → Setup → Modules/Applications → Interfaces**, find **CyphtWebmail**,
switch it on.

Enabling creates the database tables (`sql/*.sql` runs automatically), registers
the triggers, and adds the menu entries.

## Setup and first build

### The setup page

**CyphtWebmail → Module setup**, or directly:

```
/custom/cyphtWebmail/admin/setup.php
```

This is where everything is configured and built. It has:

- **IMAP defaults** - name, server, port, TLS for the default account form
- **Generate** - the build button (see below)
- **Build log** - streamed live, and kept after a page reload

### Press Generate

Cypht is not usable as shipped; it has to be compiled. Generate runs three
steps and streams the log:

1. `composer install` - fetches/updates Cypht
2. `php scripts/config_gen.php` - Cypht compiles its enabled module sets into
   `config/dynamic.php` plus bundled `site.css` / `site.js`
3. **publish** - copies the built `site/` into `public/`, which is what the
   browser actually loads

Before step 2 the module also writes Cypht's `.env` from Dolibarr's settings,
bridges the flat Composer layout, and installs its own Cypht module sets.

**You must press Generate after:**

- installing or updating the module
- changing anything on the setup page
- editing anything under `cypht/modules/`
- running `composer update`

### Building from the command line

`scripts/build.php` does everything the Generate button does, without a browser.
Use it when `proc_open()` is disabled for the webserver, when the setup page
shows the shell command instead of the button, or from a deploy script.

```bash
cd <dolibarr>/htdocs/custom/cyphtWebmail
php scripts/build.php
```

| Option | What it does |
|---|---|
| `--prepare` | dependencies and module sets only, no Dolibarr needed. For packaging |
| `--dolibarr=PATH` | where Dolibarr lives, if this module sits outside its tree. Takes the `htdocs` folder, the install root, or `master.inc.php` itself |
| `--owner=USER` / `--group=GROUP` | chown/chgrp the writable paths afterwards (POSIX only) |
| `--skip-permissions` | leave ownership and modes alone |
| `--quiet` | errors and the final result only |

Run it as the webserver user, or pass `--owner`. A build run as yourself leaves
files the webserver cannot write, and that fails later, far from the cause.

### Build where it will run

A compiled build belongs to the machine that made it. `config_gen.php` writes
absolute paths into `public/index.php` and `config/dynamic.php`, so a build
copied to another machine, or to a different path on the same one, will not
start.

**Never distribute a folder that has been fully built.** A completed build
leaves `vendor/jason-munro/cypht/.env` behind, holding the database credentials
and `CYPHTWEBMAIL_CONFIG_SECRET`, the key that decrypts every user's stored
mailbox passwords. `.gitignore` keeps `vendor/` and `public/` out of git, but a
zip or a `cp -r` does not read `.gitignore`.

To package the module, stop before anything is compiled:

```bash
php scripts/build.php --prepare      # then zip
```

`--prepare` ends before `.env` and `public/` exist, so the archive ships
`vendor/` and the Cypht module sets and nothing sensitive. The target machine
then needs neither Composer nor network access, just:

```bash
php scripts/build.php
```

If you already zipped a built tree, delete `vendor/jason-munro/cypht/.env` and
`public/` from the archive before it goes anywhere.

### Reactivate after descriptor changes

Menu entries, permissions and tables are written to the database when the
module is *activated*, not on every request. After changing
`core/modules/modcyphtWebmail.class.php`, switch the module **off and on again**
or the changes will not appear.

## Daily use

Open **CyphtWebmail** in the top menu. SSO logs you into Cypht automatically.

First time, add a mailbox: **Servers** inside Cypht → *Add an E-mail Account*.
Cypht supports IMAP, JMAP and EWS for reading, SMTP for sending.

The left column carries the Dolibarr side of the workflow - open tickets,
overdue invoices, agenda, the email collector, email templates, mass emailing
and module setup. It deliberately does not repeat Cypht's own navigation.

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

## Where data is stored

| What | Where | Notes |
|---|---|---|
| Mail accounts + settings | `llx_cyphtwebmail_userconfig` | one row per user, keyed on `fk_user`, cascades on delete |
| Secrets | `llx_const` | `CYPHTWEBMAIL_SSO_SECRET`, `CYPHTWEBMAIL_CONFIG_SECRET` |
| Menu entries | `llx_menu` | written on module activation |
| Sessions | `documents/cyphtWebmail/sso_sessions/` | transient, garbage collected |
| Attachments in progress | `documents/cyphtWebmail/attachments/` | |
| Built app | `public/` | regenerated by Generate, safe to delete |
| Cypht itself | `vendor/jason-munro/cypht/` | Composer-managed, never edit |

The `config` column is plain JSON - queryable - with only `pass` values
encrypted and marked `enc:v1:`.

```sql
SELECT u.login, c.config
FROM llx_cyphtwebmail_userconfig c
JOIN llx_user u ON u.rowid = c.fk_user;
```

**Mail itself is never stored locally.** It stays on the IMAP server.

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
`class/env/cyphtenvconfig.class.php`:

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

## Project layout

```
cyphtWebmail/
├── admin/setup.php              settings + Generate button
├── bridge/                      HTTP endpoints Cypht calls back into Dolibarr
│   └── contacts.php
├── class/
│   ├── cyphtmanager.class.php   facade; every caller uses this
│   ├── build/                   the three-step build pipeline
│   ├── contacts/                Dolibarr-side config for the contacts module set
│   ├── cypht/                   installs cypht/modules/* into the vendored Cypht
│   ├── env/                     builds and writes Cypht's .env
│   ├── sso/                     HMAC secrets, tokens, functional login
│   ├── state/                   paths and version bookkeeping
│   ├── upstream/                patches for upstream Cypht gaps
│   └── vendor/                  flat-Composer-layout bridge
├── core/
│   ├── modules/                 Dolibarr module descriptor
│   └── triggers/                USER_DELETE / USER_MODIFY cleanup
├── cypht/modules/               ★ our Cypht module sets, native layout
│   ├── dolibarr_contacts/
│   └── site/
├── docs/upstream-patches/       patches staged for upstream Cypht
├── js/                          browser code for the Dolibarr-side pages
│   ├── cypht-url-sync.js        keeps the URL in step with the iframe
│   └── admin/setup.js           build page
├── langs/en_US/                 translations
├── public/                      built app (generated, git-ignored)
├── sql/                         table definitions, run on activation
└── vendor/                      Composer (Cypht lives here)
```

Front-end code belongs in `js/` and `css/`, loaded with `dol_buildpath()` and a
`filemtime` cache-buster, never inlined into a PHP string. Anything that runs
inside Cypht instead goes in a module set under `cypht/modules/`.

## Configuration reference

Set through the setup page, or in **Home → Setup → Other** for the ones without
a form field.

| Constant | Default | Purpose |
|---|---|---|
| `CYPHTWEBMAIL_IMAP_NAME` | `Webmail` | default account label |
| `CYPHTWEBMAIL_IMAP_SERVER` | `localhost` | default IMAP host |
| `CYPHTWEBMAIL_IMAP_PORT` | `993` | default IMAP port |
| `CYPHTWEBMAIL_IMAP_TLS` | `true` | default TLS |
| `CYPHTWEBMAIL_SSO_SECRET` | generated | signs SSO and bridge tokens |
| `CYPHTWEBMAIL_CONFIG_SECRET` | generated | encrypts stored mailbox passwords |
| `CYPHTWEBMAIL_SESSION_TTL` | `604800` | session lifetime, seconds |
| `CYPHTWEBMAIL_SESSION_GC_DIVISOR` | `200` | 1-in-N logins sweep old sessions |
| `CYPHTWEBMAIL_SESSION_DEBUG` | `false` | verbose session log; leave off |
| `CYPHTWEBMAIL_CONTACTS_TTL` | `300` | contact cache lifetime, seconds |
| `CYPHTWEBMAIL_CONTACTS_MAX` | `2000` | max contacts fetched |
| `CYPHTWEBMAIL_CONTACTS_TIMEOUT` | `5` | bridge HTTP timeout |
| `CYPHTWEBMAIL_CONTACTS_INSECURE` | `false` | skip TLS verification (self-signed certs) |
| `CYPHTWEBMAIL_BRIDGE_URL` | auto | override when Dolibarr cannot reach itself by its public URL |

**Never edit `vendor/jason-munro/cypht/.env` by hand** - Generate rewrites it.

## Troubleshooting

**Build stops partway with no error.**
Look at `debug.log` and `last_build_log.ndjson` in the module root. The most
common cause is PHP's execution limit; the build sets `set_time_limit(0)` and
`ignore_user_abort(true)`, but a hard server limit still wins.

**"A build is already running" and none is.**
A crashed build left `documents/cyphtWebmail/build.lock` behind. It is ignored
automatically after 420 seconds, or delete it.

**Menu entries or permissions did not change.**
Deactivate and reactivate the module. They are written to `llx_menu` on
activation only.

**Changes under `cypht/modules/` have no effect.**
Press Generate. Those files are copied into Cypht at build time.

**Contacts do not appear.**
Open `bridge/contacts.php` directly in a browser - it should answer
`{"error":"Missing login or token"}`. Anything else (404, 500, a login page)
means the endpoint itself is the problem. Remember the 300-second cache.

**Cypht settings vanish, or the mailbox password stops working.**
Check `CYPHTWEBMAIL_CONFIG_SECRET` still exists in `llx_const`. If it changed,
stored passwords cannot be decrypted and are blanked so Cypht re-prompts.

**Sessions pile up.**
Garbage collection is probabilistic. Lower `CYPHTWEBMAIL_SESSION_GC_DIVISOR` to
sweep more often.

## Contributing

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

### Upstream patches

Fixes that belong in Cypht rather than here go in `docs/upstream-patches/`
with a PR description, and are applied at build time until merged. Keep that
directory as close to empty as possible.

### Before opening a PR

- `php -l` every changed file
- Press Generate and confirm the build completes
- Load the webmail and exercise what you changed
- If you touched the descriptor, deactivate and reactivate

### Testing checklist

- [ ] Build completes, `public/` refreshed
- [ ] SSO logs in without a Cypht login screen
- [ ] Mail lists and messages load
- [ ] Contacts appear under Personal Addresses with source `dolibarr`
- [ ] Compose autocomplete finds a Dolibarr contact
- [ ] Reload keeps the current Cypht page
- [ ] Deleting a test user removes their `llx_cyphtwebmail_userconfig` row
