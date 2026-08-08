<?php

/**
 * Installed by CyphtModuleInstaller from cypht/modules/site/lib.php.
 *
 * AUTH_TYPE=custom routes credential checking to Custom_Auth below, using
 * a short-lived HMAC token instead of a real mailbox password.
 *
 * SESSION_TYPE=custom activates Custom_Session below, storing session data
 * in its own files rather than PHP's native session_start()/$_SESSION,
 * since performSsoLogin() runs inside Dolibarr's own already-active
 * session and Cypht's stock "PHP" session type would collide with it.
 *
 * @package modules
 * @subpackage site
 */
class Custom_Session extends Hm_Session {

    use Hm_Session_Auth;

    /** @var bool true once a new login has established data to persist */
    private $existing = false;

    private function session_dir() {
        // Hm_Environment::get() reads $_ENV directly. The global env()
        // helper instead reads getenv(), backed by a process-wide table
        // that putenv() is not thread-safe for; on Windows (Apache
        // mpm_winnt, one process/many threads) a concurrent request could
        // observe it mid-update and fall back to the wrong directory.
        $base = dirname(rtrim(Hm_Environment::get('USER_SETTINGS_DIR', sys_get_temp_dir()), '/\\'));
        $dir = $base.'/sso_sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private function session_file($key) {
        return $this->session_dir().'/'.preg_replace('/[^a-f0-9]/', '', (string) $key).'.session';
    }

    /**
     * Diagnostic log for pinning a hung/crashed request to an exact line
     * even when it returns a raw 503 with no catchable PHP error.
     *
     * Leave SESSION_DEBUG off outside of active debugging: 18 call sites,
     * roughly 100KB per user per day, all appended to one shared file.
     */
    private function dbg($line) {
        if (Hm_Environment::get('SESSION_DEBUG', 'false') !== 'true') {
            return;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '?';
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '?';
        // Written under the module root, not USER_SETTINGS_DIR, so it's
        // directly reachable on disk.
        $logPath = dirname(rtrim(APP_PATH, '/\\'), 3) . '/session_debug.log';
        @file_put_contents(
            $logPath,
            sprintf("[%s] pid=%d %s %s key=%s :: %s\n", date('H:i:s.u'), getmypid(), $method, $uri, substr((string) $this->session_key, 0, 8), $line),
            FILE_APPEND
        );
    }

    /**
     * Delete abandoned session files.
     *
     * Nothing else ever removes one: destroy() unlinks on an explicit logout,
     * and closing the tab (what most people do) leaves the file behind
     * forever. Measured at ~4.8 new files per user per day, so a 1000-user
     * site adds ~4,800 files a day to one flat directory. The disk cost is
     * trivial; the file count is not, and every request does fopen()/flock()
     * in that same directory.
     *
     * Probabilistic rather than scheduled, mirroring PHP's own
     * session.gc_probability: no cron to install, and the cost lands on one
     * request in SESSION_GC_DIVISOR instead of all of them.
     *
     * @return void
     */
    private function gc() {
        $divisor = (int) Hm_Environment::get('SESSION_GC_DIVISOR', 200);
        if ($divisor < 1 || random_int(1, $divisor) !== 1) {
            return;
        }

        $ttl = (int) Hm_Environment::get('SESSION_TTL', 604800);
        if ($ttl < 3600) {
            $ttl = 3600;
        }

        $cutoff = time() - $ttl;
        $files = @glob($this->session_dir().'/*.session');
        if (!is_array($files)) {
            return;
        }

        foreach ($files as $file) {
            // mtime, not ctime: start() rewrites the file on every request,
            // so an in-use session keeps refreshing its own timestamp and is
            // never a candidate here.
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    public function check($request, $user = false, $pass = false, $fingerprint = true) {
        if ($user !== false && $pass !== false) {
            if ($this->auth($user, $pass)) {
                $this->set_key($request);
                $this->session_key = bin2hex(random_bytes(16));
                $this->loaded = true;
                $this->data = [];
                $this->active = true;
                $this->dbg('NEW LOGIN established');
                // Runs on login rather than on every request: this is the
                // moment a new file is about to be created, so it is the
                // right point to clear the ones nobody came back for.
                $this->gc();
                if ($fingerprint) {
                    $this->set_fingerprint($request);
                } else {
                    $this->set('fingerprint', '');
                }
                $this->save_auth_detail();
                $this->just_started();
            } else {
                $this->dbg('auth() returned false for new login attempt');
            }
        } elseif (array_key_exists($this->cname, $request->cookie)) {
            $this->session_key = $request->cookie[$this->cname];
            $this->dbg('checking existing session cookie');
            $this->get_key($request);
            $this->existing = true;
            $this->start($request, true);
            if ($this->active) {
                $this->check_fingerprint($request);
                $this->dbg('active after fingerprint check = '.($this->active ? 'yes' : 'no'));
            }
        } else {
            $this->dbg('no cookie, no user/pass; anonymous request');
        }
        return $this->is_active();
    }

    public function start($request, $existing_session = false) {
        if (!$existing_session) {
            return;
        }
        $file = $this->session_file($this->session_key);
        $this->dbg('start(): about to check is_readable on '.$file);
        if (!is_readable($file)) {
            $this->active = false;
            $this->dbg('start(): file not readable, marking inactive');
            return;
        }
        // Locked read: Cypht fires several parallel AJAX calls on page
        // load, each reading/writing this same file. Without a lock, a
        // read racing an in-progress write returns a partial file, fails
        // to decrypt, and reports the session inactive.
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            $this->active = false;
            $this->dbg('start(): fopen failed');
            return;
        }
        $this->dbg('start(): fopen ok, requesting LOCK_SH');
        flock($fh, LOCK_SH);
        $this->dbg('start(): LOCK_SH acquired, reading');
        $raw = stream_get_contents($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        $this->dbg('start(): read '.strlen($raw).' bytes, unlocked');

        $data = $this->plaintext($raw);
        if (is_array($data)) {
            $this->data = $data;
            $this->active = true;
            $this->dbg('start(): decrypt OK, session active');
        } else {
            $this->active = false;
            $this->dbg('start(): decrypt FAILED (raw len '.strlen($raw).'), session inactive');
        }
    }

    public function get($name, $default = false, $user = false) {
        if ($user) {
            return (array_key_exists('user_data', $this->data) && array_key_exists($name, $this->data['user_data']))
                ? $this->data['user_data'][$name] : $default;
        }
        return array_key_exists($name, $this->data) ? $this->data[$name] : $default;
    }

    public function set($name, $value, $user = false) {
        if ($user) {
            $this->data['user_data'][$name] = $value;
        } else {
            $this->data[$name] = $value;
        }
    }

    public function del($name) {
        if (array_key_exists($name, $this->data)) {
            unset($this->data[$name]);
            return true;
        }
        return false;
    }

    public function end() {
        if ($this->active && !$this->session_closed) {
            $this->dbg('end(): persisting via write_locked()');
            $this->write_locked();
        }
        $this->active = false;
    }

    /**
     * Persists and closes the session early, before a slow operation
     * (e.g. testing IMAP/SMTP credentials) so the file lock isn't held.
     * Every stock Cypht session backend implements this; Hm_Session
     * itself does not provide a default, so leaving it unimplemented
     * here caused a fatal "Call to undefined method close_early()"
     * (uncaught Error, raw 503) in modules like nux's "Add an E-mail
     * Account".
     */
    public function close_early() {
        if ($this->active && !$this->session_closed) {
            $this->dbg('close_early(): persisting via write_locked()');
            $this->write_locked();
        }
        $this->session_closed = true;
    }

    private function write_locked() {
        // Exclusive lock, same reasoning as start() above.
        $fh = @fopen($this->session_file($this->session_key), 'cb');
        if ($fh !== false) {
            flock($fh, LOCK_EX);
            $this->dbg('write_locked(): LOCK_EX acquired, writing');
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $this->ciphertext($this->data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            $this->dbg('write_locked(): write complete, unlocked');
        } else {
            $this->dbg('write_locked(): fopen failed');
        }
    }

    public function destroy($request) {
        @unlink($this->session_file($this->session_key));
        $this->delete_cookie($request, $this->cname);
        $this->delete_cookie($request, 'hm_id');
        $this->active = false;
    }
}

class Custom_Auth extends Hm_Auth_DB {

    /**
     * @param string $user username (the Dolibarr login)
     * @param string $pass "{timestamp}.{hmac}" token from
     *                      CyphtToken::generateSsoLoginToken(), not a
     *                      real password
     * @return bool true if the token is a valid, fresh SSO assertion
     */
    public function check_credentials($user, $pass) {
        // See Custom_Session::session_dir() above re: avoiding env().
        $secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
        if ($secret === '' || strpos($pass, '.') === false) {
            return false;
        }

        list($timestamp, $signature) = explode('.', $pass, 2);
        if (!ctype_digit($timestamp)) {
            return false;
        }

        // Anti-replay window: a captured token is only valid for a minute.
        if (abs(time() - (int) $timestamp) > 60) {
            return false;
        }

        $expected = hash_hmac('sha256', $user.'|'.$timestamp, $secret);

        return hash_equals($expected, $signature);
    }
}

/**
 * Replaces Hm_User_Config_File as the user-settings backend (via
 * USER_CONFIG_TYPE=custom:Custom_User_Config in .env).
 *
 * Hm_User_Config_File encrypts settings using the load()/save() "password"
 * as the literal key. Our SSO login passes a fresh per-request HMAC token
 * as that password, a different key every page load, so nothing saved
 * under one request's key could ever be decrypted the next. This class
 * ignores the key and stores plain JSON instead, same fix Tiki's
 * integration uses (Tiki_Hm_User_Config). Dolibarr's own auth already
 * gates access to this page, so there's no secret being protected anyway.
 *
 * @package modules
 * @subpackage site
 */
class Custom_User_Config extends Hm_Config {

    /** @var object Hm_Site_Config_File-like site config */
    private $site_config;

    /** @var string current username, set on load()/reload() */
    private $username;

    /** @var bool a set() happened since the last write */
    private $dirty = false;

    /** @var bool shutdown flush already registered for this request */
    private $flush_registered = false;

    /** @var object|null PDO handle onto Dolibarr's database */
    private $dbh = null;

    /** @var int|null Dolibarr user id, resolved once from the login */
    private $fk_user = null;

    /** @var int Dolibarr entity the user belongs to */
    private $entity = 1;

    /** @var bool this config came from a pre-database file, delete it once written */
    private $migrated_from_file = false;

    public function __construct($config) {
        $this->site_config = $config;
        $this->config = array_merge($this->config, $config->user_defaults);
    }

    /**
     * Settings file for a user.
     *
     * The readable part is sanitised for the filesystem, but sanitising alone
     * is not safe as an identity: Dolibarr does not restrict login characters
     * (User::create() only rejects an empty login), so "jean dupont" and
     * "jean_dupont" both collapse to the same string and would then share one
     * file, and with it each other's mail accounts and passwords. The hash of
     * the untouched login is what actually makes the name unique; the prefix
     * is only there so a human can tell whose file it is.
     *
     * @param string $username
     * @return string
     */
    private function get_path($username) {
        $dir = rtrim((string) $this->site_config->get('user_settings_dir', false), '/\\');
        $safe = preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $username);
        $safe = substr($safe, 0, 64);
        $fingerprint = substr(hash('sha256', (string) $username), 0, 12);

        return $dir.'/'.$safe.'-'.$fingerprint.'.json';
    }

    /**
     * Pre-collision-fix filename, still read once so nobody loses their
     * settings on upgrade. The next save() writes the new path.
     *
     * @param string $username
     * @return string
     */
    private function get_legacy_path($username) {
        $dir = rtrim((string) $this->site_config->get('user_settings_dir', false), '/\\');
        $safe = preg_replace('/[^a-zA-Z0-9_.@-]/', '_', (string) $username);

        return $dir.'/'.$safe.'.json';
    }

    /**
     * @param string $username username
     * @param string $key intentionally ignored, see class doc comment
     */
    /**
     * @param string $username username
     * @param string $key intentionally ignored, see class doc comment
     */
    public function load($username, $key = null) {
        $this->username = $username;

        $str_data = $this->db_load($username);

        if ($str_data === false) {
            // Nothing in the database yet. Either a new user, or one whose
            // settings still live in the pre-database file: read it, and let
            // the next save() land it in the table. The file is removed by
            // migrate_file_away() once that has happened, so this path runs
            // at most once per user.
            $str_data = $this->file_load($username);
            if ($str_data !== false) {
                $this->migrated_from_file = true;
                $this->dirty = true;
            }
        }

        if ($str_data === false || $str_data === '') {
            return;
        }

        $data = $this->decode($str_data);
        if (is_array($data)) {
            $this->config = array_merge($this->config, $this->decrypt_passwords($data));
            $this->set_tz();
        }
    }

    /**
     * PDO handle onto Dolibarr's database, via Cypht's own DB layer. The
     * DB_* settings are written into .env from $conf->db at build time.
     *
     * @return object|false
     */
    private function db() {
        if ($this->dbh === null) {
            $this->dbh = Hm_DB::connect($this->site_config);
            if (!$this->dbh) {
                Hm_Debug::add('cyphtWebmail: could not connect to the Dolibarr database');
            }
        }

        return $this->dbh;
    }

    /**
     * Dolibarr table prefix. Configurable in conf.php, so never assumed.
     *
     * @return string
     */
    private function prefix() {
        $prefix = (string) Hm_Environment::get('DOLIBARR_DB_PREFIX', 'llx_');

        // Interpolated into SQL, so it is constrained rather than trusted.
        return preg_replace('/[^a-zA-Z0-9_]/', '', $prefix);
    }

    /**
     * Resolve a login to the Dolibarr user id and entity.
     *
     * The config row is keyed on the numeric id, not the login, which is
     * what makes renaming a user a non-event here.
     *
     * @param string $username
     * @return array|false [fk_user, entity]
     */
    private function resolve_user($username) {
        if ($this->fk_user !== null) {
            return array($this->fk_user, $this->entity);
        }
        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        $row = Hm_DB::execute(
            $dbh,
            'select rowid, entity from '.$this->prefix().'user where login = ?',
            array($username),
            'select'
        );
        if (!is_array($row) || empty($row['rowid'])) {
            Hm_Debug::add('cyphtWebmail: no Dolibarr user matches login '.$username);
            return false;
        }

        $this->fk_user = (int) $row['rowid'];
        $this->entity = (int) (empty($row['entity']) ? 1 : $row['entity']);

        return array($this->fk_user, $this->entity);
    }

    /**
     * @param string $username
     * @return string|false Decrypted JSON, or false when there is no row
     */
    private function db_load($username) {
        $ids = $this->resolve_user($username);
        if ($ids === false) {
            return false;
        }
        list($fkUser, $entity) = $ids;

        $row = Hm_DB::execute(
            $this->db(),
            'select config from '.$this->prefix().'cyphtwebmail_userconfig where fk_user = ? and entity = ?',
            array($fkUser, $entity),
            'select'
        );
        if (!is_array($row) || !isset($row['config']) || $row['config'] === '') {
            return false;
        }

        return $this->decrypt_payload($row['config'], $username);
    }

    /**
     * Read the pre-database settings file, newest naming scheme first.
     *
     * @param string $username
     * @return string|false
     */
    private function file_load($username) {
        foreach (array($this->get_path($username), $this->get_legacy_path($username)) as $source) {
            if (!is_readable($source)) {
                continue;
            }
            $raw = file_get_contents($source);
            if ($raw === false || $raw === '') {
                continue;
            }
            Hm_Debug::add('cyphtWebmail: migrating settings out of '.$source);

            return $this->decrypt_payload($raw, $username);
        }

        return false;
    }

    /**
     * @param string $raw Stored payload
     * @param string $username
     * @return string JSON
     */
    private function decrypt_payload($raw, $username) {
        // Current format is plain JSON with only the passwords encrypted, so
        // anything starting with { needs no unwrapping.
        if (substr(ltrim($raw), 0, 1) === '{') {
            return $raw;
        }

        $secret = $this->config_secret();
        if ($secret === '') {
            return $raw;
        }

        // Older rows and files wrapped the whole blob. Unwrap them once; the
        // next save rewrites in the current format.
        $decrypted = Hm_Crypt::plaintext($raw, $secret);
        if ($decrypted !== false) {
            Hm_Debug::add('cyphtWebmail: converting whole-blob encrypted config for '.$username);
            return $decrypted;
        }

        Hm_Debug::add('cyphtWebmail: config for '.$username.' is neither JSON nor decryptable');

        return $raw;
    }

    /**
     * Marker on an encrypted password, so decrypt_passwords() can tell a
     * ciphertext from a password that happens to look like base64.
     */
    const PASS_PREFIX = 'enc:v1:';

    /**
     * Encrypt just the mailbox passwords, leaving the rest of the config as
     * readable JSON so the column stays queryable.
     *
     * @param array $config
     * @return array
     */
    private function encrypt_passwords($config) {
        $secret = $this->config_secret();
        if ($secret === '') {
            return $config;
        }
        foreach (array('imap_servers', 'smtp_servers', 'pop3_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (!is_array($server) || empty($server['pass'])) {
                    continue;
                }
                if (strpos($server['pass'], self::PASS_PREFIX) === 0) {
                    continue; // already encrypted
                }
                $config[$key][$index]['pass'] = self::PASS_PREFIX.Hm_Crypt::ciphertext($server['pass'], $secret);
            }
        }

        return $config;
    }

    /**
     * Reverse of encrypt_passwords(). Values without the marker are passed
     * through untouched, so a config written before this existed still works.
     *
     * @param array $config
     * @return array
     */
    private function decrypt_passwords($config) {
        $secret = $this->config_secret();
        if ($secret === '') {
            return $config;
        }
        foreach (array('imap_servers', 'smtp_servers', 'pop3_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (!is_array($server) || empty($server['pass'])) {
                    continue;
                }
                if (strpos($server['pass'], self::PASS_PREFIX) !== 0) {
                    continue;
                }
                $plain = Hm_Crypt::plaintext(substr($server['pass'], strlen(self::PASS_PREFIX)), $secret);
                // A failure means the key changed. Blanking it makes Cypht
                // prompt for the password again, which is recoverable;
                // leaving ciphertext in place would send it to the mail
                // server as if it were the password.
                $config[$key][$index]['pass'] = ($plain === false ? '' : $plain);
            }
        }

        return $config;
    }

    /**
     * Delete the file a user's settings came from, once they are safely in
     * the table. Only ever called after a successful write.
     *
     * @param string $username
     * @return void
     */
    private function migrate_file_away($username) {
        if (!$this->migrated_from_file) {
            return;
        }
        foreach (array($this->get_path($username), $this->get_legacy_path($username)) as $path) {
            if (file_exists($path) && @unlink($path)) {
                Hm_Debug::add('cyphtWebmail: removed migrated settings file '.$path);
            }
        }
        $this->migrated_from_file = false;
    }


    /**
     * Server-held key for the settings file, injected from Dolibarr's
     * llx_const via .env. Empty means the module was built before this
     * existed, in which case we fall back to writing clear text rather than
     * losing the user's settings outright.
     *
     * @return string
     */
    private function config_secret() {
        return (string) Hm_Environment::get('USER_CONFIG_SECRET', '');
    }

    /**
     * Called from Cypht's load_user_data handler with whatever the session
     * holds. Also persists, for the same reason Tiki's integration does
     * (Tiki_Hm_User_Config::reload): nothing in this setup ever warns the
     * user about unsaved settings before the session ends.
     *
     * @param array $data new user data
     * @param string|false $username
     */
    public function reload($data, $username = false) {
        $this->username = $username;
        $this->config = $data;
        $this->set_tz();

        if (!$username) {
            return;
        }

        // Deferred, not written here. Hm_Handler_load_user_data calls reload()
        // on every page load, so doing the read-compare-write inline meant a
        // decrypt plus a probable encrypt+rewrite on every single request.
        // Marking dirty routes it through the same end-of-request flush as
        // set(), so a request writes at most once whatever happens during it.
        $this->dirty = true;
        if (!$this->flush_registered) {
            $this->flush_registered = true;
            register_shutdown_function(array($this, 'flush_pending'));
        }
    }



    /**
     * Strip values that change every request but carry no meaning once
     * reloaded, so comparing two configs answers "did anything the user cares
     * about change" rather than "is this a different request".
     *
     * 'object' is a live connection handle and 'connected' a socket state;
     * without dropping them, any request that opened an IMAP connection looks
     * different from the stored copy and triggers a pointless rewrite.
     *
     * @param array $config
     * @return string Canonical form, safe to compare with ===
     */
    private function comparable($config) {
        unset($config['updated_at']);
        foreach (array('pop3_servers', 'imap_servers', 'smtp_servers') as $key) {
            if (empty($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $index => $server) {
                if (is_array($server)) {
                    unset($config[$key][$index]['object'], $config[$key][$index]['connected']);
                }
            }
        }
        ksort($config);

        return json_encode($config);
    }

    /**
     * Persist on every single write.
     *
     * Upstream leaves persistence to the Save page, which cannot succeed
     * here: save_user_settings() re-checks the password through
     * Custom_Auth, and that only accepts a 60 second HMAC token no user can
     * type. Settings and mail accounts therefore only ever lived in the
     * session and died with it. Tiki's integration hit the same wall and
     * resolved it the same way (Tiki_Hm_User_Config::set).
     *
     * @param string $name config value name
     * @param mixed $value config value
     */
    public function set($name, $value) {
        $this->config[$name] = $value;

        if (!$this->username) {
            return;
        }

        // Coalesced rather than written straight through. Tiki saves inside
        // set(), but Hm_Handler_save_user_settings loops set() over every
        // changed setting, so that rewrites the whole file once per setting -
        // thirty-odd full writes for one visit to the settings page. Tiki
        // guards this with a 'skip_saving_on_set' flag, but that key exists
        // nowhere in upstream Cypht, so nothing ever sets it.
        //
        // Deferring is safe because save() only touches disk; every reader in
        // the request works off $this->config in memory, which is already
        // current.
        $this->dirty = true;
        if (!$this->flush_registered) {
            $this->flush_registered = true;
            register_shutdown_function(array($this, 'flush_pending'));
        }
    }

    /**
     * Write once at end of request if any set() marked the config dirty.
     * Public because register_shutdown_function() needs to reach it.
     */
    public function flush_pending() {
        if (!$this->dirty || !$this->username) {
            return;
        }
        $this->dirty = false;

        // Everything here runs after the response has been sent, so anything
        // that leaks out - a PHP warning, a PDO notice, a stray echo - lands
        // on the end of an already-complete AJAX payload and Cypht reports
        // "Server Error" for a request that actually succeeded. The retry
        // then finds nothing dirty, skips this, and appears to work, which
        // is exactly the every-other-click symptom. Nothing escapes.
        ob_start();
        try {
            // Reuses $this->dbh rather than constructing another config
            // object, which would open a second connection during shutdown.
            $raw = $this->db_load($this->username);
            $existing = ($raw === false || $raw === '') ? array() : $this->decode($raw);
            if (!is_array($existing)) {
                $existing = array();
            }
            $existing = $this->decrypt_passwords($existing);

            if ($this->comparable($existing) === $this->comparable($this->config)) {
                ob_end_clean();
                return; // nothing meaningful changed, skip the write entirely
            }

            // Last write wins on the updated_at stamp, so a stale second tab
            // cannot overwrite newer settings with what it loaded minutes ago.
            if (!empty($existing['updated_at']) && !empty($this->config['updated_at'])
                && $existing['updated_at'] > $this->config['updated_at']) {
                ob_end_clean();
                return;
            }

            $this->save($this->username);
        } catch (Exception $e) {
            Hm_Debug::add('cyphtWebmail: deferred save failed: '.$e->getMessage());
        } catch (Throwable $e) {
            Hm_Debug::add('cyphtWebmail: deferred save failed: '.$e->getMessage());
        }
        ob_end_clean();
    }

    /**
     * @param string $username username
     * @param string $key intentionally ignored, see class doc comment
     */
    public function save($username, $key = null) {
        $this->dirty = false;
        $this->shuffle();

        $removed = $this->filter_servers();

        // Stamped so reload() above can tell which copy is newer.
        $this->config['updated_at'] = microtime(true);
        ksort($this->config);

        // Readable JSON with only the passwords encrypted. This is the single
        // store for accounts and settings, so keeping the rest queryable is
        // what lets anything outside Cypht report on it.
        $payload = json_encode($this->encrypt_passwords($this->config));

        if ($this->db_save($username, $payload)) {
            $this->migrate_file_away($username);
        }

        $this->restore_servers($removed);
    }

    /**
     * Upsert the config row.
     *
     * Update first, insert only if nothing was updated, rather than MySQL's
     * ON DUPLICATE KEY UPDATE: this has to work on PostgreSQL too, and
     * Dolibarr supports both. The unique key on (entity, fk_user) is what
     * makes the race between the two statements harmless - a concurrent
     * insert loses, and the next save writes the same data anyway.
     *
     * @param string $username
     * @param string $payload Encrypted config
     * @return bool
     */
    private function db_save($username, $payload) {
        $ids = $this->resolve_user($username);
        if ($ids === false) {
            return false;
        }
        list($fkUser, $entity) = $ids;

        $dbh = $this->db();
        if (!$dbh) {
            return false;
        }

        // Type passed explicitly: Hm_DB::execute() infers it from the first
        // character of the query and only recognises lower case, so an
        // uppercase UPDATE would be treated as a select and never report a
        // row count.
        $updated = Hm_DB::execute(
            $dbh,
            'update '.$this->prefix().'cyphtwebmail_userconfig set config = ? where fk_user = ? and entity = ?',
            array($payload, $fkUser, $entity),
            'modify'
        );

        if ($updated === false) {
            return false;
        }
        if ((int) $updated > 0) {
            return true;
        }

        $inserted = Hm_DB::execute(
            $dbh,
            'insert into '.$this->prefix().'cyphtwebmail_userconfig (entity, fk_user, config, date_creation) values (?, ?, ?, ?)',
            array($entity, $fkUser, $payload, date('Y-m-d H:i:s')),
            'insert'
        );

        return $inserted !== false;
    }

    /**
     * Clear transient per-request state off server entries before they are
     * written, then hand the rest to the parent implementation. Copied from
     * Tiki_Hm_User_Config::filter_servers: 'object' holds a live connection
     * handle and 'connected' a socket state, neither of which means anything
     * once reloaded, and both of which bloat the stored blob.
     * Clear transient per-request state off server entries before they are
     * stored, then hand the rest to the parent implementation.
     *
     * Must not remove servers: this config is the only store, so anything
     * dropped here is deleted permanently.
     *
     * @return array
     */
    public function filter_servers() {
        foreach ($this->config as $key => $vals) {
            if (in_array($key, array('pop3_servers', 'imap_servers', 'smtp_servers'), true) && is_array($vals)) {
                foreach ($vals as $index => $server) {
                    if (is_array($server)) {
                        $this->config[$key][$index]['object'] = false;
                        $this->config[$key][$index]['connected'] = false;
                    }
                }
            }
        }

        return parent::filter_servers();
    }
}
