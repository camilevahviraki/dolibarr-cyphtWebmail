<?php

/**
 * Read-only email template source backed by bridge/mail_templates.php in the
 * Dolibarr module. Deliberately a near copy of Hm_Dolibarr_Contacts: same
 * signing scheme, same transport, same failure handling. The two differ only
 * in the purpose tag and the env keys, and keeping them parallel is worth
 * more than the handful of lines that could be shared.
 *
 * @package modules
 * @subpackage dolibarr_mail_templates
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage dolibarr_mail_templates/lib
 */
class Hm_Dolibarr_Mail_Templates {

    /** @var string Endpoint URL, injected via .env at build time */
    private $url;
    /** @var string Shared HMAC secret, same one the SSO login uses */
    private $secret;

    public function __construct() {
        /* Hm_Environment::get() rather than the env() helper, for the same
         * reason documented in the generated modules/site/lib.php. */
        $this->url = Hm_Environment::get('DOLIBARR_MAIL_TEMPLATES_URL', '');
        $this->secret = Hm_Environment::get('SSO_SHARED_SECRET', '');
    }

    /**
     * @return bool true if this source has everything it needs to run
     */
    public function configured() {
        return $this->url !== '' && $this->secret !== '';
    }

    /**
     * Seconds a fetched list stays good for. Templates change far less often
     * than contacts, so the default is longer.
     *
     * @return int
     */
    public function ttl() {
        return (int) Hm_Environment::get('DOLIBARR_MAIL_TEMPLATES_TTL', 900);
    }

    /**
     * Fetch the template list for a Dolibarr login.
     *
     * @param string $login Dolibarr username, as put in the session by SSO
     * @return array|false array('templates' => array, 'hint' => string), or
     *                     false on any transport or protocol failure
     */
    public function fetch($login) {
        if (!$this->configured()) {
            return false;
        }

        $timestamp = time();
        /* The '|templates' tag is what stops a contacts token being replayed
         * against this endpoint; bridge/mail_templates.php checks for it. */
        $signature = hash_hmac('sha256', $login.'|'.$timestamp.'|templates', $this->secret);

        $url = $this->url.(strpos($this->url, '?') === false ? '?' : '&');
        $url .= http_build_query(array(
            'login' => $login,
            'token' => $timestamp.'.'.$signature,
        ));

        $body = $this->request($url);
        if ($body === false) {
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('templates', $data)) {
            Hm_Debug::add('dolibarr_mail_templates: unexpected response: '.substr($body, 0, 200));
            return false;
        }

        return array(
            'templates' => is_array($data['templates']) ? $data['templates'] : array(),
            /* Distinct types, already labelled and counted by the bridge, so
             * the picker does not have to derive them from the template list
             * and get the ordering or the labels subtly different. */
            'types' => (array_key_exists('types', $data) && is_array($data['types'])) ? $data['types'] : array(),
            'hint' => array_key_exists('hint', $data) ? (string) $data['hint'] : '',
        );
    }

    /**
     * @param string $url Fully built, signed request URL
     * @return string|false Response body, or false on transport failure
     */
    private function request($url) {
        $timeout = (int) Hm_Environment::get('DOLIBARR_MAIL_TEMPLATES_TIMEOUT', 5);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            /* The token is short lived but still a bearer credential, so
             * never follow a redirect that could carry it off-host. */
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            /* HTTP auth in front of Dolibarr answers 401 before the request
             * reaches PHP, so send credentials when one is configured. */
            $proxyAuth = Hm_Environment::get('DOLIBARR_BRIDGE_HTTP_AUTH', '');
            if ($proxyAuth !== '') {
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $proxyAuth);
            }
            if (Hm_Environment::get('DOLIBARR_MAIL_TEMPLATES_INSECURE', 'false') === 'true') {
                /* For local XAMPP setups serving Dolibarr over a self-signed
                 * certificate. Off by default. */
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status !== 200) {
                Hm_Debug::add('dolibarr_mail_templates: HTTP '.$status.' '.$err);
                return false;
            }
            return $body;
        }

        $streamOpts = array(
            'timeout' => $timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        );
        $proxyAuth = Hm_Environment::get('DOLIBARR_BRIDGE_HTTP_AUTH', '');
        if ($proxyAuth !== '') {
            $streamOpts['header'] = 'Authorization: Basic '.base64_encode($proxyAuth)."\r\n";
        }
        $context = stream_context_create(array('http' => $streamOpts));
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            Hm_Debug::add('dolibarr_mail_templates: request failed, no curl and file_get_contents returned false');
            return false;
        }
        return $body;
    }
}
