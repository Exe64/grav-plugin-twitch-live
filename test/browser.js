// Browser checks, Twitch's CDN and player answered locally: node test/browser.js <base-url> [screenshot-dir]
// With a screenshot dir, also writes the README captures (feature.png, grid.png).
const { chromium } = require('playwright-core');
const [base, out] = process.argv.slice(2);
let fails = 0;
const say = (good, msg) => { if (!good) fails++; console.log(`${good ? 'ok  ' : 'FAIL'} ${msg}`); };
// Neutral images: a gradient per image address, no third-party picture
const svg = u => { const h = [...u].reduce((a, c) => (a * 31 + c.charCodeAt(0)) % 360, 7);
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 9"><defs><linearGradient id="g" x2="1" y2="1"><stop offset="0" stop-color="hsl(${h} 45% 38%)"/><stop offset="1" stop-color="hsl(${(h + 70) % 360} 50% 22%)"/></linearGradient></defs><rect width="16" height="9" fill="url(#g)"/></svg>`; };
const fake = (c, asked) => c.route(/(static-cdn\.jtvnw\.net|twitch\.tv)/, r => { const u = r.request().url(); asked.push(u);
  return /static-cdn/.test(u) ? r.fulfill({ status: 200, contentType: 'image/svg+xml', body: svg(u.replace(/\?.*/, '')) })
    : r.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>player</title><body style="background:#333">' }); });
const stages = p => p.evaluate(() => [...document.querySelectorAll('.tw-stage')].map(s => s.id + ':' + (s.hidden ? '-' : new URL(s.querySelector('iframe').src).searchParams.get('channel'))).join(' '));
(async () => {
  const browser = await chromium.launch();
  {
    const asked = [], c = await browser.newContext({ viewport: { width: 1100, height: 900 } }); await fake(c, asked);
    const p = await c.newPage(); await p.goto(base + '/home', { waitUntil: 'networkidle' });
    const players = () => asked.filter(u => /player\.twitch/.test(u)).length;
    const before = players(), imgs = asked.filter(u => /static-cdn/.test(u)).length, s0 = await stages(p);
    await p.click('section[aria-labelledby="twitch-title"] .tw-thumb[data-channel="other_chan"]'); await p.waitForTimeout(300);
    const s1 = await stages(p), f = p.locator('#twitch-stage iframe');
    const src = await f.getAttribute('src'), title = await f.getAttribute('title');
    const exp = await p.getAttribute('section[aria-labelledby="twitch-title"] .twitch-link[data-channel="other_chan"]', 'aria-expanded');
    say(before === 0 && imgs > 0 && s0 === 'twitch-stage:- twitch-2-stage:- twitch-3-stage:-' && s1 === 'twitch-stage:other_chan twitch-2-stage:- twitch-3-stage:-'
      && /^https:\/\/player\.twitch\.tv\/\?channel=other_chan&parent=127\.0\.0\.1&/.test(src) && title === 'Twitch player: Other_Chan' && exp === 'true',
      `click on a live image: ${before} player request before (${imgs} images), ${s1}, "${title}", parent=127.0.0.1, aria-expanded ${exp}`);
    await p.click('section[aria-labelledby="twitch-2-title"] .twitch-link[data-channel="third"]'); await p.waitForTimeout(300);
    const s2 = await stages(p);
    say(s2 === 'twitch-stage:other_chan twitch-2-stage:third twitch-3-stage:-', `a second block opens its own player, the first stays: ${s2}`);
    await p.click('section[aria-labelledby="twitch-2-title"] .twitch-link[data-channel="third"]'); await p.waitForTimeout(200);
    const s3 = await stages(p);
    await p.click('#twitch-stage .tw-close'); await p.waitForTimeout(200);
    const s4 = await stages(p), foc = await p.evaluate(() => document.activeElement.dataset.channel + ' ' + !!document.activeElement.closest('[aria-labelledby="twitch-title"]'));
    say(s3 === 'twitch-stage:other_chan twitch-2-stage:- twitch-3-stage:-' && s4 === 'twitch-stage:- twitch-2-stage:- twitch-3-stage:-' && foc === 'other_chan true' && new URL(p.url()).pathname === '/home',
      `second click closes (${s3}); close button closes (${s4}), focus back on ${foc}; stays on the page`);
    const pg = await c.newPage(); const target = await Promise.all([c.waitForEvent('page'), pg.goto(base + '/home', { waitUntil: 'networkidle' }).then(() => pg.click('section[aria-labelledby="twitch-title"] .twitch-link[data-channel="third"]', { modifiers: ['Control'] }))]).then(([np]) => np.url()).catch(() => 'none');
    say(target === 'https://www.twitch.tv/third' && await stages(pg) === 'twitch-stage:- twitch-2-stage:- twitch-3-stage:-', `Ctrl+click keeps the link to Twitch: ${target}`);
    if (out) {
      // README captures: demo channels (test/pages/demo), each block with a margin of the page around it
      await p.setViewportSize({ width: 1000, height: 900 }); await p.goto(base + '/demo', { waitUntil: 'networkidle' });
      for (const [id, name] of [['twitch', 'feature'], ['twitch-2', 'grid']]) {
        const b = await p.locator(`section[aria-labelledby="${id}-title"]`).boundingBox();
        await p.screenshot({ path: `${out}/${name}.png`, fullPage: true, clip: { x: b.x - 24, y: b.y - 16, width: b.width + 48, height: b.height + 32 } });
      }
    }
    await c.close();
  }
  {
    const c = await browser.newContext({ viewport: { width: 390, height: 800 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 }); await fake(c, []);
    const p = await c.newPage(); await p.goto(base + '/strip', { waitUntil: 'networkidle' });
    const ctl = () => p.evaluate(() => { const c = document.querySelector('.tw-strip-ctl'); return [c.hidden, c.querySelector('.tw-prev').disabled, c.querySelector('.tw-next').disabled, Math.round(document.querySelector('.tw-items').scrollLeft)].join(' '); });
    const a = await ctl(); await p.click('.tw-next'); await p.waitForTimeout(700); const b = await ctl();
    say(a === 'false true false 0' && /^false false (true|false) [1-9]\d*$/.test(b), `strip on a phone: buttons shown, previous disabled (${a}); next scrolls (${b})`);
    await c.close();
  }
  await browser.close();
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
