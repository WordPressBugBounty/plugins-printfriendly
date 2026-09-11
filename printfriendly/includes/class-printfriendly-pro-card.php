<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

/**
 * Pro card v2: the plan panel on the settings page, driven by a server-side
 * licence check instead of a browser-side guess.
 *
 * Live since 5.5.13, after two releases dark. An install renders this card
 * unless PRINTFRIENDLY_PRO_CARD_V2 is defined false, or the
 * printfriendly_pro_card_v2 filter returns false, either of which falls back to
 * the legacy views/pro.php.
 *
 * Constants for testing without touching production:
 *   PRINTFRIENDLY_PRO_CARD_V2  bool    switch the new panel OFF (default on)
 *   PRINTFRIENDLY_API_BASE     string  API origin, default https://www.printfriendly.com
 *   PRINTFRIENDLY_API_STUB     bool    fake the API locally; walk states with
 *                                      ?page=printfriendly&pf_stub_state=trial (see STUB_STATES)
 *
 * API contract (all JSON):
 *   POST {base}/api/v3/wp/handoff  {site, host, email, plan, plugin_version, return_url, temporary_host}
 *        -> 200 {token, start_url}   start_url must be on the API host (see allow_api_host)
 *   POST {base}/api/v3/wp/exchange {token, host}
 *        -> 200 {site_token} | 409 {error:"pending"} while the user is still on the site
 *        -> 404/410 {error} for an unknown or expired token
 *   GET  {base}/api/v3/license?host=  headers X-PF-Site-Token, X-PF-Plugin-Version
 *        -> 200 {plan: free|trial|expired|pro|past_due, trial_ends_at, ends_at,
 *                cancel_at_period_end, failed_at, grace_until, last_trial_ended_at,
 *                eligible_for_trial, account_email, dev_domain,
 *                account_url, portal_url, update_card_url, resume_url}
 *                (ends_at is only sent once a cancellation is scheduled)
 *   The card talks about money only at the offer itself and when something needs
 *   attention (payment failed, cancelled). Trial and Pro stay about the product.
 *        -> 401 when the site token was revoked
 *   Dates are unix seconds or ISO 8601. URLs must be stable site URLs that mint a
 *   fresh Stripe session on visit; the plugin caches this response for 12 hours.
 *
 * @package PrintFriendly_WordPress
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable PSR12.Properties.ConstantVisibility -- the plugin still supports PHP < 7.1.

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('PrintFriendly_Pro_Card')) {
    class PrintFriendly_Pro_Card
    {
        const OPT_SITE_TOKEN = 'printfriendly_pro_site_token';
        const OPT_HANDOFF = 'printfriendly_pro_handoff';
        const OPT_STATUS = 'printfriendly_pro_status';
        const OPT_PREFS = 'printfriendly_pro_prefs';
        const OPT_STUB_STATE = 'printfriendly_pro_stub_state';

        const CACHE_TTL = 43200;
        const HANDOFF_TTL = 172800;
        const EXCHANGE_RETRY = 60;
        const NONCE_ACTION = 'printfriendly_pro_card';
        const SUPPORT_EMAIL = 'support@printfriendly.com';

        /**
         * Every state the view can render. In stub mode each one can be forced
         * with ?pf_stub_state=<name>. A trial in its last days is still 'trial':
         * the reminder email covers that moment, the card just counts down.
         *
         * @var array
         */
        private static $states = array(
            'offer',
            'free',
            'free_connected',
            'trial',
            'expired',
            'expired_eligible',
            'pro',
            'past_due',
            'cancelled',
        );

        /** @var string */
        private $plugin_version;

        /** @var string admin page slug */
        private $hook;

        /** @var string absolute path of pf.php, for plugins_url() */
        private $plugin_file;

        /** @var string|null one notice key to show after a redirect */
        private $notice = null;

        /**
         * HTTP status behind $notice, when the notice came from a failed API
         * call. 0 means "no response at all", which is a genuinely different
         * thing from a response we did not like and is worded differently.
         *
         * @var int
         */
        private $notice_status = 0;

        /**
         * Whether the v2 card is switched on.
         *
         * On by default since 5.5.13. The constant is now an opt-OUT: define it
         * false to go back to views/pro.php on a single install, and the
         * printfriendly_pro_card_v2 filter still has the last word either way.
         * It was opt-in for the two releases the card was dark launched over.
         *
         * @return bool
         */
        public static function enabled()
        {
            $on = ! defined('PRINTFRIENDLY_PRO_CARD_V2') || PRINTFRIENDLY_PRO_CARD_V2;
            return (bool) apply_filters('printfriendly_pro_card_v2', $on);
        }

        /**
         * API origin without a trailing slash.
         *
         * @return string
         */
        public static function api_base()
        {
            $base = defined('PRINTFRIENDLY_API_BASE') ? PRINTFRIENDLY_API_BASE : 'https://www.printfriendly.com';
            return untrailingslashit((string) apply_filters('printfriendly_api_base', $base));
        }

        /**
         * Whether the API is being faked locally.
         *
         * @return bool
         */
        public static function stub()
        {
            return defined('PRINTFRIENDLY_API_STUB') && PRINTFRIENDLY_API_STUB;
        }

        /**
         * @return array
         */
        public static function states()
        {
            return self::$states;
        }

        /**
         * @param string $plugin_version
         * @param string $hook
         * @param string $plugin_file
         */
        public function __construct($plugin_version, $hook, $plugin_file)
        {
            $this->plugin_version = $plugin_version;
            $this->hook = $hook;
            $this->plugin_file = $plugin_file;

            add_action('admin_init', array($this, 'handle_admin_actions'));
            add_filter('allowed_redirect_hosts', array($this, 'allow_api_host'));
            add_action('update_option_home', array($this, 'on_home_changed'), 10, 2);
        }

        /* ------------------------------------------------------------------ */
        /* Site facts                                                          */
        /* ------------------------------------------------------------------ */

        /**
         * The reader-facing host: home_url(), not siteurl, because readers visit home.
         *
         * @return string
         */
        public function site_host()
        {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            return is_string($host) ? strtolower($host) : '';
        }

        /**
         * @return string
         */
        public function admin_email()
        {
            return (string) get_option('admin_email');
        }

        /**
         * Hosts that are almost certainly temporary. The site makes the final call;
         * this only sets a hint so the account page can ask for the real domain.
         *
         * @param string $host
         * @return bool
         */
        public static function looks_temporary($host)
        {
            $host = strtolower((string) $host);
            if ($host === '' || $host === 'localhost') {
                return true;
            }
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return true;
            }
            foreach (array('.local', '.test', '.example', '.invalid', '.localhost', '.internal') as $tld) {
                if (substr($host, -strlen($tld)) === $tld) {
                    return true;
                }
            }
            foreach (array('staging.', 'stage.', 'dev.', 'test.', 'local.') as $prefix) {
                if (strpos($host, $prefix) === 0) {
                    return true;
                }
            }
            $hosts = array(
                'tastewp.com',
                'wpengine.com',
                'wpenginepowered.com',
                'myftpupload.com',
                'kinsta.cloud',
                'pantheonsite.io',
                'wpsandbox.',
                'instawp.',
                'ngrok',
                'cloudwaysapps.com',
                'flywheelsites.com',
                'wpcomstaging.com',
            );
            foreach ($hosts as $needle) {
                if (strpos($host, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }

        /**
         * The settings page URL, optionally with extra query args.
         *
         * @param array $args
         * @return string
         */
        public function settings_url($args = array())
        {
            $url = admin_url('options-general.php?page=' . $this->hook);
            return empty($args) ? $url : add_query_arg($args, $url);
        }

        /**
         * A nonced settings-page action link.
         *
         * @param array $args
         * @return string
         */
        public function action_url($args)
        {
            return wp_nonce_url($this->settings_url($args), self::NONCE_ACTION);
        }

        /**
         * Let wp_safe_redirect() send the user to the API host.
         *
         * @param array $hosts
         * @return array
         */
        public function allow_api_host($hosts)
        {
            $host = wp_parse_url(self::api_base(), PHP_URL_HOST);
            if (is_string($host) && $host !== '' && ! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
            return $hosts;
        }

        /* ------------------------------------------------------------------ */
        /* HTTP                                                                */
        /* ------------------------------------------------------------------ */

        /**
         * @param string $method  GET or POST
         * @param string $path
         * @param array  $data    query args for GET, JSON body for POST
         * @param array  $headers
         * @return array|WP_Error decoded JSON on 2xx, WP_Error otherwise
         */
        private function api($method, $path, $data = array(), $headers = array())
        {
            $args = array(
                'timeout' => 10,
                'headers' => array_merge(
                    array(
                        'Accept' => 'application/json',
                        'X-PF-Plugin-Version' => $this->plugin_version,
                    ),
                    $headers
                ),
            );
            if ($method === 'POST') {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = wp_json_encode($data);
                $response = wp_remote_post(self::api_base() . $path, $args);
            } else {
                $response = wp_remote_get(add_query_arg($data, self::api_base() . $path), $args);
            }

            if (is_wp_error($response)) {
                return $response;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code < 200 || $code >= 300) {
                $message = (is_array($body) && isset($body['error'])) ? (string) $body['error'] : 'HTTP ' . $code;
                return new WP_Error('printfriendly_api', $message, array('status' => $code));
            }
            if (! is_array($body)) {
                return new WP_Error('printfriendly_api', 'Unexpected response', array('status' => $code));
            }
            return $body;
        }

        /**
         * Record why an API call failed, for WP_DEBUG installs only. Normal sites
         * stay silent: the plugin writes nothing to debug.log in ordinary use.
         *
         * @param string   $where
         * @param WP_Error $error
         */
        /**
         * The HTTP status a WP_Error carries, or 0 when the request never got
         * an answer (DNS, TLS, timeout). request() puts it in the error data.
         *
         * @param  WP_Error $error
         * @return int
         */
        private static function error_status(WP_Error $error)
        {
            $data = $error->get_error_data();
            return (is_array($data) && isset($data['status'])) ? (int) $data['status'] : 0;
        }

        private function log_api_failure($where, WP_Error $error)
        {
            if (! defined('WP_DEBUG') || ! WP_DEBUG) {
                return;
            }
            $status = self::error_status($error);
            error_log(sprintf(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated by WP_DEBUG.
                'PrintFriendly: %s failed against %s -- %s: %s%s',
                $where,
                self::api_base(),
                $error->get_error_code(),
                $error->get_error_message(),
                $status === 404
                    ? ' (that origin has no such route; PRINTFRIENDLY_API_BASE is probably pointing somewhere without it)'
                    : ''
            ));
        }

        /* ------------------------------------------------------------------ */
        /* Handoff: plugin -> site -> back                                     */
        /* ------------------------------------------------------------------ */

        /**
         * @return array|null the handoff in progress, or null
         */
        private function pending_handoff()
        {
            $handoff = get_option(self::OPT_HANDOFF);
            if (! is_array($handoff) || empty($handoff['token']) || empty($handoff['created'])) {
                return null;
            }
            if ((time() - (int) $handoff['created']) > self::HANDOFF_TTL) {
                delete_option(self::OPT_HANDOFF);
                return null;
            }
            return $handoff;
        }

        /**
         * Ask the site for a one-time handoff token and the URL to send the user to.
         *
         * @param string $plan  trial | buy | free
         * @return string|WP_Error the URL to redirect to
         */
        public function begin_handoff($plan)
        {
            $plan = in_array($plan, array('trial', 'buy', 'free'), true) ? $plan : 'trial';
            // Built with add_query_arg(), not wp_nonce_url(): that escapes & as &amp;
            // for HTML output, and this URL travels to the site as JSON.
            $return_url = add_query_arg(
                '_wpnonce',
                wp_create_nonce('printfriendly_pro_connect'),
                $this->settings_url(array('pf_connected' => 1))
            );

            if (self::stub()) {
                $this->save_handoff('stub-' . wp_generate_password(24, false), $plan);
                return $return_url;
            }

            $result = $this->api(
                'POST',
                '/api/v3/wp/handoff',
                array(
                    'site' => home_url(),
                    'host' => $this->site_host(),
                    'email' => $this->admin_email(),
                    'plan' => $plan,
                    'plugin_version' => $this->plugin_version,
                    'return_url' => $return_url,
                    'temporary_host' => self::looks_temporary($this->site_host()),
                )
            );
            if (is_wp_error($result)) {
                return $result;
            }
            if (empty($result['token']) || empty($result['start_url'])) {
                return new WP_Error('printfriendly_api', 'Handoff response was incomplete');
            }
            $this->save_handoff((string) $result['token'], $plan);
            return esc_url_raw((string) $result['start_url']);
        }

        /**
         * @param string $token
         * @param string $plan
         */
        private function save_handoff($token, $plan)
        {
            update_option(
                self::OPT_HANDOFF,
                array('token' => $token, 'plan' => $plan, 'created' => time(), 'tried' => 0),
                false
            );
        }

        /**
         * Swap the one-time handoff token for a site token. The site token never
         * travels in a URL. Retried on settings-page loads while the handoff is
         * pending, at most once a minute unless forced.
         *
         * @param bool $force
         * @return true|WP_Error  error code 'pending' while the user has not finished on the site
         */
        private function complete_handoff($force = false)
        {
            $handoff = $this->pending_handoff();
            if ($handoff === null) {
                return new WP_Error('none', 'No handoff in progress');
            }
            if (! $force && (time() - (int) $handoff['tried']) < self::EXCHANGE_RETRY) {
                return new WP_Error('pending', 'Checked recently');
            }
            $handoff['tried'] = time();
            update_option(self::OPT_HANDOFF, $handoff, false);

            if (self::stub()) {
                $plan = isset($handoff['plan']) ? $handoff['plan'] : 'trial';
                $states = array('free' => 'free_connected', 'buy' => 'pro', 'trial' => 'trial');
                update_option(self::OPT_STUB_STATE, isset($states[$plan]) ? $states[$plan] : 'trial', false);
                $site_token = 'stub-site-' . wp_generate_password(16, false);
            } else {
                $result = $this->api(
                    'POST',
                    '/api/v3/wp/exchange',
                    array('token' => (string) $handoff['token'], 'host' => $this->site_host())
                );
                if (is_wp_error($result)) {
                    $data = $result->get_error_data();
                    $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
                    if ($status === 409 || $status === 202) {
                        return new WP_Error('pending', $result->get_error_message());
                    }
                    if ($status === 404 || $status === 410) {
                        delete_option(self::OPT_HANDOFF);
                    }
                    return $result;
                }
                if (empty($result['site_token'])) {
                    return new WP_Error('printfriendly_api', 'Exchange response was incomplete');
                }
                $site_token = (string) $result['site_token'];
            }

            update_option(self::OPT_SITE_TOKEN, $site_token, false);
            delete_option(self::OPT_HANDOFF);
            delete_option(self::OPT_PREFS);
            delete_option(self::OPT_STATUS);
            $this->fetch_status(true);
            return true;
        }

        /* ------------------------------------------------------------------ */
        /* Status                                                              */
        /* ------------------------------------------------------------------ */

        /**
         * @return string
         */
        public function site_token()
        {
            return (string) get_option(self::OPT_SITE_TOKEN, '');
        }

        /**
         * Licence status, cached for CACHE_TTL. Never calls home without a site
         * token, so a site that has not connected makes no request at all.
         *
         * @param bool $force
         * @return array
         */
        public function fetch_status($force = false)
        {
            $token = $this->site_token();
            if ($token === '') {
                return array('plan' => 'free', 'connected' => false);
            }

            $cached = get_option(self::OPT_STATUS);
            $fresh = is_array($cached)
                && isset($cached['fetched_at'])
                && (time() - (int) $cached['fetched_at']) < self::CACHE_TTL;
            if (! $force && $fresh) {
                return $cached;
            }

            if (self::stub()) {
                $status = $this->stub_status();
            } else {
                $result = $this->api(
                    'GET',
                    '/api/v3/license',
                    array('host' => $this->site_host()),
                    array('X-PF-Site-Token' => $token)
                );
                if (is_wp_error($result)) {
                    $data = $result->get_error_data();
                    if (is_array($data) && isset($data['status']) && (int) $data['status'] === 401) {
                        // The site revoked this token: back to a clean Free state.
                        delete_option(self::OPT_SITE_TOKEN);
                        delete_option(self::OPT_STATUS);
                        return array('plan' => 'free', 'connected' => false);
                    }
                    if (is_array($cached)) {
                        $cached['stale'] = true;
                        return $cached;
                    }
                    return array('plan' => 'free', 'connected' => true, 'unreachable' => true);
                }
                $status = $result;
            }

            $status['connected'] = true;
            $status['fetched_at'] = time();
            unset($status['stale'], $status['unreachable']);
            update_option(self::OPT_STATUS, $status, false);
            return $status;
        }

        /**
         * A believable status for each stub state, dated relative to now.
         *
         * @return array
         */
        private function stub_status()
        {
            $state = (string) get_option(self::OPT_STUB_STATE, 'free_connected');
            $day = DAY_IN_SECONDS;
            $base = array(
                'plan' => 'free',
                'account_email' => $this->admin_email(),
                'account_url' => self::api_base() . '/account/print-button',
                'portal_url' => self::api_base() . '/account/print-button/billing',
                'dev_domain' => 'staging.' . preg_replace('/^www\./', '', $this->site_host()),
                'eligible_for_trial' => true,
            );
            switch ($state) {
                case 'trial':
                    return array_merge($base, array('plan' => 'trial', 'trial_ends_at' => time() + 22 * $day));
                case 'expired':
                    return array_merge(
                        $base,
                        array('plan' => 'expired', 'last_trial_ended_at' => time() - 2 * $day, 'eligible_for_trial' => false)
                    );
                case 'expired_eligible':
                    return array_merge(
                        $base,
                        array('plan' => 'expired', 'last_trial_ended_at' => time() - 200 * $day, 'eligible_for_trial' => true)
                    );
                case 'pro':
                    return array_merge($base, array('plan' => 'pro'));
                case 'past_due':
                    return array_merge(
                        $base,
                        array('plan' => 'past_due', 'failed_at' => time() - $day, 'grace_until' => time() + 7 * $day)
                    );
                case 'cancelled':
                    return array_merge(
                        $base,
                        array('plan' => 'pro', 'ends_at' => time() + 40 * $day, 'cancel_at_period_end' => true)
                    );
                default:
                    return $base;
            }
        }

        /**
         * @return array
         */
        private function prefs()
        {
            $prefs = get_option(self::OPT_PREFS);
            return is_array($prefs) ? $prefs : array();
        }

        /**
         * @param string $key
         * @param mixed  $value
         */
        private function set_pref($key, $value)
        {
            $prefs = $this->prefs();
            $prefs[$key] = $value;
            update_option(self::OPT_PREFS, $prefs, false);
        }

        /**
         * Turn the raw status into the one state the view renders.
         *
         * @param array $status
         * @return string one of self::$states
         */
        public function derive_state($status)
        {
            $prefs = $this->prefs();

            if (empty($status['connected'])) {
                // A half-finished handoff is not a state of its own: the offer shows
                // again and clicking it starts fresh, while the site resumes wherever
                // the person stopped. The quiet retry in handle_admin_actions() still
                // connects a signup that finished but never came back.
                return empty($prefs['dismissed_offer']) ? 'offer' : 'free';
            }

            $plan = isset($status['plan']) ? (string) $status['plan'] : 'free';
            switch ($plan) {
                case 'trial':
                    return 'trial';
                case 'expired':
                    $ended = isset($status['last_trial_ended_at']) ? (string) $status['last_trial_ended_at'] : '1';
                    if (isset($prefs['dismissed_expired']) && (string) $prefs['dismissed_expired'] === $ended) {
                        return 'free_connected';
                    }
                    return ! empty($status['eligible_for_trial']) ? 'expired_eligible' : 'expired';
                case 'pro':
                    return (! empty($status['cancel_at_period_end']) || ! empty($status['ends_at'])) ? 'cancelled' : 'pro';
                case 'past_due':
                    return 'past_due';
                default:
                    return 'free_connected';
            }
        }

        /**
         * @param mixed $timestamp
         * @return int|null whole days from now, never negative
         */
        public function days_until($timestamp)
        {
            $ts = $this->to_timestamp($timestamp);
            if ($ts === null) {
                return null;
            }
            return max(0, (int) ceil(($ts - time()) / DAY_IN_SECONDS));
        }

        /**
         * Accepts unix seconds or an ISO 8601 string.
         *
         * @param mixed $value
         * @return int|null
         */
        private function to_timestamp($value)
        {
            if ($value === null || $value === '' || $value === false) {
                return null;
            }
            if (is_numeric($value)) {
                return (int) $value;
            }
            $ts = strtotime((string) $value);
            return $ts === false ? null : $ts;
        }

        /**
         * A date in the site's timezone and date format, or null when unknown.
         *
         * @param mixed $timestamp
         * @return string|null
         */
        public function fmt_date($timestamp)
        {
            $ts = $this->to_timestamp($timestamp);
            if ($ts === null) {
                return null;
            }
            $format = (string) get_option('date_format');
            if (function_exists('wp_date')) {
                return wp_date($format, $ts);
            }
            return date_i18n($format, $ts + (int) ((float) get_option('gmt_offset') * HOUR_IN_SECONDS));
        }

        /**
         * A recent post slug for the preview mock, so the address bar looks like
         * this site rather than a placeholder.
         *
         * @return string
         */
        private function sample_path()
        {
            $posts = get_posts(array('numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids'));
            if (! empty($posts)) {
                $slug = get_post_field('post_name', $posts[0]);
                if (is_string($slug) && $slug !== '') {
                    return '/' . $slug;
                }
            }
            return '/your-post';
        }

        /**
         * Everything the view needs. URLs are raw here and escaped in the view.
         *
         * @return array
         */
        public function view_model()
        {
            $status = $this->fetch_status();
            $state = $this->derive_state($status);
            $host = $this->site_host();
            $get = function ($key, $default = null) use ($status) {
                return isset($status[$key]) ? $status[$key] : $default;
            };
            $account_url = (string) $get('account_url', self::api_base() . '/account');
            $portal_url = (string) $get('portal_url', $account_url);
            // Not connected yet, or the account has never used its trial: offer the trial.
            $eligible = empty($status['connected']) || ! empty($get('eligible_for_trial'));

            return array(
                'state' => $state,
                'host' => $host,
                'admin_email' => $this->admin_email(),
                'account_email' => (string) $get('account_email', $this->admin_email()),
                'days_left' => $this->days_until($get('trial_ends_at')),
                'ends_date' => $this->fmt_date($get('ends_at')),
                'grace_date' => $this->fmt_date($get('grace_until')),
                'failed_date' => $this->fmt_date($get('failed_at')),
                'last_trial_date' => $this->fmt_date($get('last_trial_ended_at')),
                'dev_domain' => (string) $get('dev_domain', ''),
                'sample_path' => $this->sample_path(),
                'account_url' => $account_url,
                'update_card_url' => (string) $get('update_card_url', $portal_url),
                'resume_url' => (string) $get('resume_url', $portal_url),
                'support_url' => 'mailto:' . self::SUPPORT_EMAIL,
                'privacy_url' => 'https://www.printfriendly.com/privacy',
                'eligible' => $eligible,
                'start_url' => $this->action_url(array('pf_start' => $eligible ? 'trial' : 'buy')),
                'buy_url' => $this->action_url(array('pf_start' => 'buy')),
                'stay_free_url' => $this->action_url(array('pf_stay_free' => 1)),
                'refresh_url' => $this->action_url(array('pf_refresh' => 1)),
                'unreachable' => ! empty($status['unreachable']),
                'stale' => ! empty($status['stale']),
                'notice' => $this->notice,
                'notice_status' => $this->notice_status,
                'stub' => self::stub(),
                'stub_states' => self::$states,
                'stub_state' => (string) get_option(self::OPT_STUB_STATE, 'offer'),
                'stub_url' => $this->settings_url(),
                'price_line' => (string) apply_filters('printfriendly_pro_price_line', '$8.25 a month, billed yearly'),
                // The amount that actually leaves the card. Stated wherever the
                // trial is being sold, because a first charge this size arriving
                // unannounced is a refund request rather than a surprise.
                'charge_amount' => (string) apply_filters('printfriendly_pro_charge_amount', '$99'),
            );
        }

        /* ------------------------------------------------------------------ */
        /* Admin actions                                                       */
        /* ------------------------------------------------------------------ */

        /**
         * Query-string actions on the settings page. Every branch redirects to a
         * clean URL so a refresh never repeats the action.
         */
        public function handle_admin_actions()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- each branch verifies its own nonce.
            if (! isset($_GET['page']) || $_GET['page'] !== $this->hook || ! current_user_can('manage_options')) {
                return;
            }

            if (isset($_GET['pf_notice'])) {
                $this->notice = sanitize_key(wp_unslash($_GET['pf_notice']));
                // absint() rather than a cast: a hand-edited pf_status of
                // "-1" or "abc" must not reach the view as a plausible code.
                $this->notice_status = isset($_GET['pf_status']) ? absint(wp_unslash($_GET['pf_status'])) : 0;
                return;
            }

            if (isset($_GET['pf_connected'])) {
                // Back from printfriendly.com. The nonce was minted before leaving; if it
                // has aged out, the page load below still retries the exchange quietly.
                if ($this->site_token() !== '' && $this->pending_handoff() === null) {
                    // A quiet retry already finished the exchange before the user came back.
                    $this->redirect('connected');
                }
                $force = isset($_REQUEST['_wpnonce'])
                    && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'printfriendly_pro_connect');
                $result = $this->complete_handoff($force);
                if ($result === true) {
                    $this->redirect('connected');
                }
                $this->redirect($result->get_error_code() === 'pending' ? 'pending' : 'handoff_failed');
            }

            if (self::stub() && isset($_GET['pf_stub_state'])) {
                $this->apply_stub_state(sanitize_key(wp_unslash($_GET['pf_stub_state'])));
                $this->redirect();
            }

            if (isset($_GET['pf_start'])) {
                check_admin_referer(self::NONCE_ACTION);
                $url = $this->begin_handoff(sanitize_key(wp_unslash($_GET['pf_start'])));
                if (is_wp_error($url)) {
                    // "Could not reach PrintFriendly" is the honest summary for an
                    // admin, but it hides the difference between a network failure
                    // and a perfectly reachable server that has no such route. A
                    // plugin pointed at an origin without /api/v3/wp/handoff gets a
                    // 404 here and looks exactly like an outage, which cost real
                    // debugging time. The status check uses /api/v3/license, which
                    // production does have, so the card renders fine and only this
                    // button fails, hiding the misconfiguration further.
                    $this->log_api_failure('begin_handoff', $url);
                    $this->redirect('start_failed', self::error_status($url));
                }
                wp_safe_redirect($url);
                exit;
            }

            if (isset($_GET['pf_stay_free'])) {
                check_admin_referer(self::NONCE_ACTION);
                if ($this->site_token() === '') {
                    $this->set_pref('dismissed_offer', 1);
                } else {
                    $status = $this->fetch_status();
                    $this->set_pref('dismissed_expired', isset($status['last_trial_ended_at']) ? (string) $status['last_trial_ended_at'] : '1');
                }
                $this->redirect();
            }

            if (isset($_GET['pf_refresh'])) {
                check_admin_referer(self::NONCE_ACTION);
                if ($this->site_token() === '' && $this->pending_handoff() !== null) {
                    $result = $this->complete_handoff(true);
                    if ($result === true) {
                        $this->redirect('connected');
                    }
                    $this->redirect($result->get_error_code() === 'pending' ? 'pending' : 'handoff_failed');
                }
                $this->fetch_status(true);
                $this->redirect('refreshed');
            }

            // Plain settings-page load while a handoff is pending: retry quietly.
            if ($this->site_token() === '' && $this->pending_handoff() !== null) {
                $this->complete_handoff(false);
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
        }

        /**
         * Stub only: put the plugin into one of the states so it can be eyeballed.
         *
         * @param string $state
         */
        private function apply_stub_state($state)
        {
            if (! in_array($state, self::$states, true)) {
                return;
            }
            delete_option(self::OPT_STATUS);
            delete_option(self::OPT_PREFS);
            delete_option(self::OPT_HANDOFF);
            update_option(self::OPT_STUB_STATE, $state, false);

            if ($state === 'offer' || $state === 'free') {
                delete_option(self::OPT_SITE_TOKEN);
                if ($state === 'free') {
                    $this->set_pref('dismissed_offer', 1);
                }
                return;
            }
            if ($this->site_token() === '') {
                update_option(self::OPT_SITE_TOKEN, 'stub-site-' . wp_generate_password(16, false), false);
            }
        }

        /**
         * @param string|null $notice
         * @param int         $status HTTP status behind the notice, 0 if none
         */
        private function redirect($notice = null, $status = 0)
        {
            $args = $notice ? array('pf_notice' => $notice) : array();
            if ($notice && $status > 0) {
                $args['pf_status'] = (int) $status;
            }
            wp_safe_redirect($this->settings_url($args));
            exit;
        }

        /**
         * The site moved (staging to production, say): the cached licence was for
         * the old host, so forget it and let the next settings load re-check.
         *
         * @param mixed $old
         * @param mixed $new
         */
        public function on_home_changed($old, $new)
        {
            if ((string) $old !== (string) $new) {
                delete_option(self::OPT_STATUS);
            }
        }

        /* ------------------------------------------------------------------ */
        /* Assets and rendering                                                */
        /* ------------------------------------------------------------------ */

        /**
         * Called from the plugin's admin_enqueue_scripts, settings page only.
         */
        public function enqueue_assets()
        {
            wp_enqueue_style(
                'pf-pro-card',
                plugins_url('assets/css/pro-card.css', $this->plugin_file),
                array(),
                $this->plugin_version
            );
            wp_enqueue_script(
                'pf-pro-card',
                plugins_url('assets/js/pro-card.js', $this->plugin_file),
                array('jquery'),
                $this->plugin_version,
                true
            );
        }

        /**
         * Render the card. Called from views/tabs.php in place of views/pro.php.
         */
        public function render()
        {
            $card = $this->view_model();
            include dirname($this->plugin_file) . '/views/pro-card.php';
        }
    }
}
