#!/usr/bin/env bash
# Bench of the plugin: a throwaway Grav with shortcode-core, the plugin, test/pages and a fake Twitch
# (test/twitch-mock.php), then markup checks and, when Playwright is available, the browser checks
# (test/browser.js). Needs php 8.3 (gd, zip, curl), curl, unzip, node.
# Usage, from the repository root: bash test/run.sh [screenshot-dir]
# GRAV_ZIP: a local Grav archive instead of the download. HOLD=<seconds>: keep the servers up that long after
# the markup checks, for a browser run from elsewhere.
set -uo pipefail
REPO="$(cd "$(dirname "$0")/.." && pwd)"
SHOTS="${1:-}"
PORT="${PORT:-8099}"; MOCK=$((PORT + 1))
W="$(mktemp -d)"
trap 'kill $(jobs -p) 2>/dev/null; rm -rf "$W"' EXIT
if [[ -z "${GRAV_ZIP:-}" ]]; then GRAV_ZIP="$W/grav.zip"; curl -fsSL -o "$GRAV_ZIP" https://getgrav.org/download/core/grav/latest || { echo "Grav download failed"; exit 1; }; fi
cd "$W" && unzip -q "$GRAV_ZIP" && cd "$(ls -d "$W"/grav*/ | head -1)" || exit 1
G="$PWD"
# A grav-admin archive (local cache) would redirect to the admin setup: keep the plain Grav
rm -rf user/plugins/{admin,admin2,login,form,email,flex-objects,api,problems} 2>/dev/null
for i in 1 2 3; do php bin/gpm install -y shortcode-core feed >/dev/null; [[ -d user/plugins/shortcode-core && -d user/plugins/feed ]] && break; sleep 5; done
[[ -d user/plugins/shortcode-core && -d user/plugins/feed ]] || { echo "shortcode-core or feed not installed"; exit 1; }
mkdir user/plugins/twitch-live && cp -r "$REPO"/{twitch-live.php,twitch-live.yaml,blueprints.yaml,languages.yaml,assets,templates} user/plugins/twitch-live/
rm -rf user/pages/* && cp -r "$REPO"/test/pages/. user/pages/
printf 'title: Test\n' > user/config/site.yaml
cp user/config/system.yaml "$W/system.yaml"
# conf [YAML lines] [CREDS]: the plugin settings of the bench plus these lines (CREDS given, even empty, replaces
# the test credentials); the cache is cleared
conf() {
  printf "enabled: true\n${2-client_id: test-id\nclient_secret: test-secret\n}channels: [ExeProd, Other_Chan, \"bad name!\", third, exeprod]\ntoken_url: http://127.0.0.1:$MOCK/oauth2/token\napi_url: http://127.0.0.1:$MOCK/helix\n${1:-}" > user/config/plugins/twitch-live.yaml
  php bin/grav cache -q --no-ansi
}
mkdir -p user/config/plugins && printf 'enabled: true\nenable_json_feed: true\n' > user/config/plugins/feed.yaml && conf
export TWITCH_LOG="$W/twitch.log"
php -S 127.0.0.1:"$MOCK" "$REPO/test/twitch-mock.php" >"$W/mock.log" 2>&1 &
php -S 127.0.0.1:"$PORT" system/router.php >"$W/php.log" 2>&1 &
B="http://127.0.0.1:$PORT"
for i in $(seq 30); do curl -fs -o /dev/null "$B/" && break; sleep 1; done

fail=0
ok() { echo "ok   $*"; }
ko() { echo "FAIL $*"; fail=$((fail+1)); }
get() { curl -s -o "$W/page.html" -w '%{http_code}' "$B$1"; }
has() { grep -qF -- "$1" "$W/page.html"; }
# blk ID: the section of that block in the last page fetched; names: the channel names in stdin
blk() { sed -n "/<section [^>]*aria-labelledby=\"$1-title\"/,/<\/section>/p" "$W/page.html"; }
names() { grep -o '<span class="twitch-name">[^<]*' | sed 's/.*>//' | paste -sd,; }
feat() { sed -n '/<article class="tw-feature/,/<\/article>/p'; }

echo "== markup (site without language: English)"
get /home >/dev/null; code=$(get /home)
[[ $code == 200 ]] && ok "/home 200 (second view, content from the page cache)" || ko "/home $code"
ids=$(grep -o 'id="twitch[-0-9]*-stage"' "$W/page.html" | paste -sd,)
[[ $(grep -c '<section class="twitch twitch--' "$W/page.html") == 3 && $ids == 'id="twitch-stage",id="twitch-2-stage",id="twitch-3-stage"' ]] && ok "3 blocks, each with its own ids ($ids); the one without a valid channel left out" || ko "blocks: $ids"
b=$(blk twitch); n=$(names <<<"$b")
grep -q 'class="twitch twitch--grid"' <<<"$b" && [[ $n == Other_Chan,third,ExeProd ]] && ok "[twitch]: Admin settings, live first in Admin order, invalid and duplicate names dropped ($n)" || ko "block 1: $n"
[[ $(grep -c 'class="tw-badge">Live<' <<<"$b") == 2 && $(grep -c 'class="tw-off">Offline<' <<<"$b") == 1 ]] && grep -q '>1,234 viewers<' <<<"$b" && grep -q '>1 viewer<' <<<"$b" && grep -q 'id="twitch-title">On Twitch<' <<<"$b" && ok "English: 2 Live, 1 Offline, 1,234 viewers / 1 viewer, heading" || ko "English texts: $(grep -oE 'tw-(viewers|badge|off)">[^<]*' <<<"$b" | paste -sd'|')"
grep -q 'Draft &lt;b&gt;Foundations&lt;/b&gt; &amp; co' <<<"$b" && grep -q '<p class="tw-game">Magic: The Gathering</p>' <<<"$b" && ok "stream title escaped, game shown" || ko "title: $(grep -o 'tw-title">[^<]*' <<<"$b")"
[[ $(grep -o 'data-channel="[^"]*"' <<<"$b" | paste -sd,) == 'data-channel="other_chan",data-channel="other_chan",data-channel="third",data-channel="third"' ]] && grep -q 'href="https://www.twitch.tv/exeprod"' <<<"$b" && ok "player on click for live channels only, links to twitch.tv" || ko "data-channel: $(grep -o 'data-channel="[^"]*"' <<<"$b" | paste -sd,)"
t=$(( $(date +%s) / 300 ))
grep -q "src=\"https://static-cdn.jtvnw.net/previews-ttv/live_user_other_chan-640x360.jpg?t=$t\"" <<<"$b" && grep -q 'exeprod-channel_offline_image-1920x1080.png' <<<"$b" && ok "live preview refreshed every 5 minutes, offline image" || ko "images: $(grep -o 'src="[^"]*"' <<<"$b" | head -3 | paste -sd' ')"
! grep -q 'evil.example' "$W/page.html" && [[ $(grep -o '<img [^>]*src="[^"]*"' "$W/page.html" | grep -vc 'src="https://static-cdn.jtvnw.net/') == 0 ]] && grep -q 'referrerpolicy="no-referrer"' "$W/page.html" && ok "images from Twitch's CDN only, no referrer" || ko "image sources"
! grep -qE 'test-secret|tok123' "$W/page.html" && ok "secret and token never in the page" || ko "secret or token in the page"
! grep -q '<iframe' "$W/page.html" && grep -q 'id="twitch-stage" hidden' "$W/page.html" && ok "no player before a click, stage hidden" || ko "player before a click"
b=$(blk twitch-2)
grep -q 'twitch--compact' <<<"$b" && [[ $(names <<<"$b") == third,exeprod ]] && ok "[twitch layout=compact channels=…]: its own channels" || ko "block 2: $(names <<<"$b")"
b=$(blk twitch-3); f=$(feat <<<"$b")
[[ $(names <<<"$f") == ExeProd ]] && grep -q 'Offline' <<<"$f" && grep -q '<a class="tw-watch tw-visit" href="https://www.twitch.tv/exeprod">See the channel on Twitch</a>' <<<"$f" && ! grep -q 'data-channel' <<<"$f" && [[ $(names <<<"$(sed -n '/class="tw-items"/,/<\/ul>/p' <<<"$b")") == Other_Chan,third ]] && ok "[twitch layout=feature feature=chosen …]: chosen channel kept offline, the others below" || ko "block 3: $(names <<<"$f")"
! grep -q 'twitch-live:' "$W/page.html" && ! tr '\n' ' ' < "$W/page.html" | grep -qE '<p>[[:space:]]*<section|</section>[[:space:]]*</p>' && has '<p>Between two blocks.</p>' && ok "no placeholder left, no block inside a paragraph" || ko "placeholder or paragraph around a block"
has 'twitch-live.js' && has 'twitch-live.css' && ok "assets on the cached page" || ko "assets missing"
get /plain >/dev/null; ! has 'twitch-live' && ok "nothing on a page without the shortcode" || ko "assets on a page without the shortcode"
calls=$(cat "$TWITCH_LOG")
[[ $(grep -c '^POST /oauth2/token' <<<"$calls") == 1 && $(grep -c '^GET /helix/streams' <<<"$calls") == 2 && $(grep -c '^GET /helix/users' <<<"$calls") == 2 ]] && grep -q 'GET /helix/streams?user_login=exeprod&user_login=other_chan&user_login=third&first=100$' <<<"$calls" && ok "2 views, 2 channel sets: 1 token, 2 status and 2 users requests (cached)" || ko "Twitch calls: $(paste -sd'|' <<<"$calls")"
seen=$(for i in $(seq 16); do get /random >/dev/null; blk twitch | feat | names; done | sort -u | paste -sd,)
[[ $seen == Other_Chan,third ]] && ok "random feature among live channels, drawn at each view despite the page cache ($seen)" || ko "random: $seen"
for f in twitch-live.js twitch-live.css; do code=$(curl -s -o /dev/null -w '%{http_code}' "$B/user/plugins/twitch-live/assets/$f"); [[ $code == 200 ]] || ko "asset $f $code"; done; ok "assets served"

echo "== summaries and feeds"
get /blog/post >/dev/null; [[ $(grep -c '<section class="twitch twitch--' "$W/page.html") == 1 ]] && ok "post: its own block rendered" || ko "post: block missing"
get /modular >/dev/null; [[ $(grep -c '<section class="twitch twitch--' "$W/page.html") == 1 ]] && ! has 'twitch-live:' && ok "modular page: the block of its module rendered" || ko "modular page: block missing"
get /blog >/dev/null; has 'Text after the block' && ! has 'twitch-live:' && ! has '<section class="twitch' && ok "blog list: summary without the block nor its placeholder" || ko "blog list: $(grep -c '<section class="twitch' "$W/page.html") block(s), $(grep -c 'twitch-live:' "$W/page.html") placeholder(s), summary text $(grep -c 'Text after the block' "$W/page.html")"
for f in rss atom json; do
  code=$(get /blog.$f)
  if [[ $f == json ]]; then php -r 'exit(json_decode(file_get_contents($argv[1])) === null ? 1 : 0);' "$W/page.html"; else php -r 'exit(simplexml_load_file($argv[1]) === false ? 1 : 0);' "$W/page.html" 2>/dev/null; fi; valid=$?
  [[ $code == 200 && $valid == 0 ]] && has 'Text after the block' && ! has 'twitch-live' && ! has 'twitch--' && ok "$f feed: valid, item text kept, no block nor placeholder" || ko "$f feed: $code, valid=$valid, $(grep -o 'twitch-live[^ ]\{0,30\}' "$W/page.html" | head -1)"
done

echo "== French site, no credentials"
printf 'languages:\n  supported: [fr]\n  include_default_lang: false\n' >> user/config/system.yaml && php bin/grav cache -q --no-ansi
get /home >/dev/null; b=$(blk twitch)
grep -q 'id="twitch-title">Sur Twitch<' <<<"$b" && grep -q $'>1 234 spectateurs<' <<<"$b" && grep -q 'class="tw-badge">En direct<' <<<"$b" && grep -q 'data-player-title="Lecteur Twitch : %s"' <<<"$b" && ! grep -q 'PLUGIN_TWITCH_LIVE' "$W/page.html" && ok "French texts (system.languages.supported: [fr]), same URL" || ko "French: $(grep -oE 'tw-(viewers|badge)">[^<]*|twitch-title">[^<]*' <<<"$b" | paste -sd'|')"
cp "$W/system.yaml" user/config/system.yaml; conf '' 'client_id: test-id\n'; : > "$TWITCH_LOG"
get /home >/dev/null; b=$(blk twitch)
[[ $(names <<<"$b") == ExeProd,Other_Chan,third ]] && ! grep -qE 'Live|Offline|data-channel|<img|tw-note' <<<"$b" && [[ ! -s $TWITCH_LOG ]] && ok "no credentials: Admin order, no status, no image, Twitch not asked" || ko "without credentials: $(names <<<"$b")"
conf
grep -qiE 'fatal|exception' "$W/php.log" && ko "PHP errors: $(grep -iE 'fatal|exception' "$W/php.log" | head -2)" || ok "no PHP error in the log"

echo "== browser"
if [[ -n "${HOLD:-}" ]]; then echo "ready $B"; sleep "$HOLD"; elif command -v node >/dev/null && (cd "$REPO" && node -e "require('playwright-core')") 2>/dev/null; then
  node "$REPO/test/browser.js" "$B" ${SHOTS:+"$SHOTS"} || fail=$((fail+1))
else ko "playwright-core not found (npm i --no-save playwright-core && npx playwright-core install chromium)"; fi
[[ $fail == 0 ]] && echo "ALL OK" || { echo "$fail FAILED"; exit 1; }
