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
- [Where data is stored](#where-data-is-stored)
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

To run it:

- Dolibarr 19+ (developed against **24.0**)
- PHP 8.0+ with `curl`, `openssl`, `mbstring`, `dom`, `PDO`
- MySQL/MariaDB or PostgreSQL (whatever Dolibarr already uses)

To build it, additionally:

- a **PHP CLI binary**, which the build invokes to run Cypht's `config_gen.php`
- **Composer**, or a `composer.phar` in the module root, or a `vendor/` that was
  prepared elsewhere
- `proc_open()` and `exec()` enabled

The last three are needed only by whichever side runs the build. Building from
the setup page means the webserver needs them, and shared hosting often disables
`proc_open()` for exactly that reason. Building from a terminal needs them in
your shell instead, where they are normally available, which is why
`scripts/build.php` exists.

## Installation

### 1. Put the module in an external modules directory

The module goes in one of Dolibarr's external module directories, set by
`$dolibarr_main_document_root_alt` in `<dolibarr>/htdocs/conf/conf.php`:

```php
$dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
```

Clone or copy it there:

```bash
cd dolibarr/.../<your external modules directory>
git clone <repository-url> cyphtWebmail
```

If the module is not in the tree below Dolibarr, `scripts/build.php` cannot find
it on its own; pass `--dolibarr=/path/to/htdocs`. See
[Building from the command line](#building-from-the-command-line).

If you received the module as an archive it may already contain `vendor/`. If it
also contains `public/`, it was packaged wrongly; see
[Build where it will run](#build-where-it-will-run).

### 2. Enable the module

**Home → Setup → Modules/Applications → Interfaces**, find **CyphtWebmail**,
switch it on.

Enabling creates the database tables (`sql/*.sql` runs automatically), registers
the triggers, and adds the menu entries. None of that happens at build time, so
this step cannot be skipped.

### 3. Build it

Cypht is a Composer dependency and is not usable as shipped, so it has to be
fetched and compiled. Either way of doing that fetches the dependencies for you;
there is no need a separate`composer install` step.

From a terminal, on the machine that will serve it:

```bash
cd <module directory>
php scripts/build.php
```

Or from the setup page, if the server allows it: **CyphtWebmail → Module setup
→ Generate**. The page checks first and shows the shell command instead of the
button when the webserver cannot run builds.

Both routes do the same work. See
[Setup and first build](#setup-and-first-build) for what the steps are and when
to repeat them.

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
2. `vendor/jason-munro/cypht/scripts/config_gen.php` - Cypht compiles its
   enabled module sets into `config/dynamic.php` plus bundled `site.css` /
   `site.js`. This is Cypht's own script, not the module's `scripts/build.php`
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

**No need to edit `vendor/jason-munro/cypht/.env` by hand** - Generate rewrites it.

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

If none of these fit, [How it works](CONTRIBUTING.md#how-it-works) walks through
SSO, the iframe and the build pipeline, which is usually enough to place a fault.

## Contributing

Everything about changing this module lives in
[CONTRIBUTING.md](CONTRIBUTING.md): the project layout, how the pieces fit
together, how to add a Cypht module set or a bridge endpoint, the coding
conventions, and the checklist to run before opening a PR.
