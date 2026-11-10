<?php
namespace Grav\Plugin;

use Grav\Common\HTTP\Client;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;
use Twig\TwigFunction;

/**
 * The Twitch channels listed in the Admin, live ones first, in the presentation chosen in the Admin; in a page,
 * [twitch layout=… channels=… feature=… feature_channel=…] (shortcode-core), each parameter left out taken from
 * the Admin.
 * Status (/streams) and channel images (/users) from the Helix API with an app token (credentials set in
 * the plugin's Admin settings), cached on the server. Images are served by
 * Twitch's CDN; the player is loaded by the browser only when the reader clicks a live channel.
 */
class TwitchLivePlugin extends Plugin
{
    // Keys; the French labels are what the versions before 1.0.0 saved (their Admin options used labels as values)
    private const LAYOUTS = ['grid' => 'grid', 'compact' => 'compact', 'feature' => 'feature', 'strip' => 'strip',
        'Grille de cartes' => 'grid', 'Liste compacte' => 'compact', 'Une en vedette' => 'feature', 'Bande défilante' => 'strip'];
    private const PICKS = ['top' => 'top', 'random' => 'random', 'chosen' => 'chosen',
        'La plus regardée' => 'top', 'Au hasard parmi les directs' => 'random', 'Une chaîne choisie' => 'chosen'];
    private const CDN = 'https://static-cdn.jtvnw.net/';
    private const PLACEHOLDER = '<!--twitch-live:';
    /** Blocks rendered in this request: each gets its own ids (heading, player) */
    private static int $blocks = 0;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onShortcodeHandlers' => ['onShortcodeHandlers', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
            'onFeedItemContent' => ['onFeedItemContent', 0],
        ];
    }

    public function onTwigTemplatePaths(): void
    {
        // Appended: a theme's own partials/twitch-live.html.twig wins
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFunction(new TwigFunction('twitch_live', [$this, 'live']));
    }

    /**
     * [twitch …] leaves a placeholder in the page content, rendered on each request in onOutputGenerated:
     * Grav caches page content, and the live status and the random feature must not be frozen there.
     */
    public function onShortcodeHandlers(): void
    {
        $this->grav['shortcode']->getHandlers()->add('twitch', function (ShortcodeInterface $sc) {
            // Kept in the page cache meta by shortcode-core, so cached pages load them too
            $this->grav['shortcode']->addAssets('css', 'plugin://twitch-live/assets/twitch-live.css');
            $this->grav['shortcode']->addAssets('js', ['plugin://twitch-live/assets/twitch-live.js', ['group' => 'bottom', 'loading' => 'defer']]);
            // The page the block belongs to: in another page's output (a blog list showing its summary) it is left out.
            // A module is only ever shown inside its parent: no page, always rendered.
            $page = $this->grav['shortcode']->getPage();
            $p = $page && !$page->isModule() ? ['_page' => md5((string) $page->path())] : [];
            foreach (['layout', 'channels', 'feature', 'feature_channel'] as $k) {
                $v = trim((string) $sc->getParameter($k, ''));
                if ($v !== '') {
                    $p[$k] = $v;
                }
            }

            // Base64: nothing an author types can end the comment
            return self::PLACEHOLDER . base64_encode(json_encode($p)) . '-->';
        });
    }

    /**
     * HTML pages only: the block of the page shown (or of its modules) rendered, the placeholders of other pages
     * (summaries in a blog list) removed. Other formats (feeds, JSON) are left as they are.
     */
    public function onOutputGenerated(): void
    {
        $out = $this->grav->output;
        $page = $this->grav['page'] ?? null;
        if (!is_string($out) || !str_contains($out, self::PLACEHOLDER) || !$page || $page->templateFormat() !== 'html') {
            return;
        }
        $self = md5((string) $page->path());
        $this->grav->output = self::placeholders($out, fn(array $p) => isset($p['_page']) && $p['_page'] !== $self ? ''
            : $this->grav['twig']->processTemplate('partials/twitch-live.html.twig', ['twitch_params' => $p]));
    }

    /** Feed plugin: an item's content goes to feed readers without the block (no player there) nor its placeholder. */
    public function onFeedItemContent(Event $event): void
    {
        $event['content'] = self::placeholders((string) $event['content'], static fn() => '');
    }

    /** $html with each placeholder replaced by $render(its parameters). */
    private static function placeholders(string $html, callable $render): string
    {
        // A shortcode alone on its line ends up in a paragraph, which cannot hold the section: <p> dropped
        return preg_replace_callback('#(<p>\s*)?' . self::PLACEHOLDER . '([A-Za-z0-9+/=]*)-->(?(1)\s*</p>)#',
            static fn($m) => $render((array) json_decode((string) base64_decode($m[2]), true)), $html);
    }

    /**
     * {id, layout, feature, channels}: feature is the channel shown large (layout "feature"), channels the
     * others, live first, Admin order otherwise. Each channel:
     * {login, name, live (null when unknown), title, game, viewers, thumb, avatar}.
     * $params (from the shortcode) replace the Admin settings of the same name; channels: comma separated.
     */
    public function live(array $params = []): array
    {
        $cfg = array_merge((array) $this->config->get('plugins.twitch-live'), array_intersect_key($params, array_flip(['layout', 'channels', 'feature', 'feature_channel'])));
        $id = 'twitch' . (++self::$blocks > 1 ? '-' . self::$blocks : '');
        $layout = self::LAYOUTS[$cfg['layout'] ?? ''] ?? 'grid';
        $channels = is_string($cfg['channels'] ?? null) ? explode(',', $cfg['channels']) : (array) ($cfg['channels'] ?? []);
        $out = [];
        foreach ($channels as $name) {
            $name = trim((string) $name);
            $login = strtolower($name);
            // Twitch logins: 3 to 25 letters, digits or underscores. Helix takes 100 at most.
            if (preg_match('/^[a-z0-9_]{3,25}$/', $login) && !isset($out[$login]) && count($out) < 100) {
                $out[$login] = ['login' => $login, 'name' => $name, 'live' => null, 'thumb' => null, 'avatar' => null];
            }
        }
        if (!$out) {
            return ['id' => $id, 'layout' => $layout, 'feature' => null, 'channels' => []];
        }
        $logins = array_keys($out);
        $users = $this->cached('users', $logins, 86400, fn() => $this->fetchUsers($logins));
        $streams = $this->cached('streams', $logins, max(30, (int) ($cfg['cache_lifetime'] ?? 120)), fn() => $this->fetchStreams($logins));
        foreach ($out as $login => &$c) {
            $u = $users[$login] ?? [];
            $c['avatar'] = self::cdn($u['profile_image_url'] ?? '');
            $c['thumb'] = self::cdn($u['offline_image_url'] ?? '') ?: $c['avatar'];
            if ($streams === null) {
                continue;
            }
            $s = $streams[$login] ?? null;
            $c['live'] = $s !== null;
            if ($s !== null) {
                $c['title'] = (string) ($s['title'] ?? '');
                $c['game'] = (string) ($s['game_name'] ?? '');
                $c['viewers'] = (int) ($s['viewer_count'] ?? 0);
                // The preview changes every few minutes; the query string keeps browsers from showing an old one
                $thumb = self::cdn(str_replace(['{width}', '{height}'], ['640', '360'], (string) ($s['thumbnail_url'] ?? '')));
                $c['thumb'] = $thumb ? $thumb . '?t=' . intdiv(time(), 300) : $c['thumb'];
            }
        }
        unset($c);
        $list = array_values($out);
        // usort is stable: the Admin order stays within each group
        usort($list, static fn($a, $b) => $b['live'] <=> $a['live']);

        // The feature is always shown: the chosen channel even offline; most watched or random among the
        // live ones, else among all (the first of the list for "most watched")
        $feature = null;
        if ($layout === 'feature') {
            $lives = array_values(array_filter($list, static fn($c) => $c['live']));
            $pool = $lives ?: $list;
            $pick = self::PICKS[$cfg['feature'] ?? ''] ?? 'top';
            $chosen = strtolower(trim((string) ($cfg['feature_channel'] ?? '')));
            $top = $lives ? array_reduce($lives, static fn($m, $c) => $m === null || $c['viewers'] > $m['viewers'] ? $c : $m) : $list[0];
            $feature = match ($pick) {
                'random' => $pool[random_int(0, count($pool) - 1)],
                'chosen' => array_values(array_filter($list, static fn($c) => $c['login'] === $chosen))[0] ?? $top,
                default => $top,
            };
            $list = array_values(array_filter($list, static fn($c) => $c['login'] !== $feature['login']));
        }

        return ['id' => $id, 'layout' => $layout, 'feature' => $feature, 'channels' => $list];
    }

    /** Twitch image URLs only: anything else from the API is dropped. */
    private static function cdn(string $url): ?string
    {
        return str_starts_with($url, self::CDN) && !preg_match('/[\s"<>]/', $url) ? $url : null;
    }

    /** Value from the server cache, else from $fetch; null (unknown) on failure, cached 60 s. */
    private function cached(string $what, array $logins, int $life, callable $fetch): ?array
    {
        $cache = $this->grav['cache'];
        $key = "twitch-live-$what-" . md5(implode(',', $logins));
        $hit = $cache->fetch($key);
        if (is_array($hit)) {
            return $hit['v'];
        }
        $v = null;
        try {
            $v = $fetch();
        } catch (\Throwable $e) {
            $this->grav['log']->warning("twitch-live: $what: " . $e->getMessage());
        }
        // ponytail: no lock, a few visitors at the same second may each ask Twitch once; fine at this audience.
        // A failure is cached too, for less time: Twitch is not asked again on every page view.
        $cache->save($key, ['v' => $v], $v === null ? 60 : $life);

        return $v;
    }

    private function fetchStreams(array $logins): ?array
    {
        $out = [];
        foreach ($this->api('streams', 'user_login', $logins) ?? [] as $s) {
            if (($s['type'] ?? '') === 'live' && isset($s['user_login'])) {
                $out[strtolower($s['user_login'])] = $s;
            }
        }

        return $this->creds() ? $out : null;
    }

    private function fetchUsers(array $logins): ?array
    {
        $out = [];
        foreach ($this->api('users', 'login', $logins) ?? [] as $u) {
            if (isset($u['login'])) {
                $out[strtolower($u['login'])] = $u;
            }
        }

        return $this->creds() ? $out : null;
    }

    /** [client id, secret] from the plugin's settings (Admin), or null. */
    private function creds(): ?array
    {
        $id = trim((string) $this->config->get('plugins.twitch-live.client_id', ''));
        $secret = trim((string) $this->config->get('plugins.twitch-live.client_secret', ''));

        return $id !== '' && $secret !== '' ? [$id, $secret] : null;
    }

    /** data[] of a Helix endpoint for these logins, or null without credentials. */
    private function api(string $endpoint, string $param, array $logins): ?array
    {
        if (!$creds = $this->creds()) {
            return null;
        }
        [$id, $secret] = $creds;
        $cache = $this->grav['cache'];
        // A slow Twitch must not hold the page: 3 s per request at most
        $http = Client::getClient(['timeout' => 3, 'max_duration' => 3]);
        $tokenKey = 'twitch-live-token-' . md5($id . $secret);
        $token = $cache->fetch($tokenKey);
        if (!is_string($token) || $token === '') {
            $r = $http->request('POST', $this->config->get('plugins.twitch-live.token_url'), [
                'body' => ['client_id' => $id, 'client_secret' => $secret, 'grant_type' => 'client_credentials'],
            ])->toArray();
            $token = (string) ($r['access_token'] ?? '');
            if ($token === '') {
                throw new \RuntimeException('no access token in the answer of Twitch');
            }
            // App tokens last about 60 days; renewed a little before they end
            $cache->save($tokenKey, $token, max(60, (int) ($r['expires_in'] ?? 0) - 3600));
        }
        // /streams pages its answer (20 by default); /users always answers for every login given
        $query = implode('&', array_map(static fn($l) => $param . '=' . rawurlencode($l), $logins)) . ($endpoint === 'streams' ? '&first=100' : '');
        $resp = $http->request('GET', rtrim($this->config->get('plugins.twitch-live.api_url'), '/') . "/$endpoint?$query", [
            'headers' => ['Client-Id' => $id, 'Authorization' => 'Bearer ' . $token],
        ]);
        if ($resp->getStatusCode() === 401) {
            // Revoked or expired before its time: a new one is asked on the next try
            $cache->delete($tokenKey);
            throw new \RuntimeException('token refused by Twitch');
        }

        return $resp->toArray()['data'] ?? [];
    }
}
