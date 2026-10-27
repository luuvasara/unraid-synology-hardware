// webgui-shot.cjs - real webGui screenshots of the synology-hardware pages (build host only). Written: 2026-10-26.
// usage: SYNOHW_PW=... node webgui-shot.cjs <base-url> <out-dir> <name>=<path>[#<tab title>][@<css selector>][%<click selector>] ...
// The password comes from the environment only (never written to a file). Unraid's footer is position:fixed; for a whole-page
// capture the viewport is grown to the document height first, so the footer sits below the content instead of over it.
const PW = process.env.SYNOHW_PW;
const pc = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
(async () => {
  const [base, out, ...shots] = process.argv.slice(2);
  if (!PW || !base || !out) { console.error('usage: SYNOHW_PW=... node webgui-shot.cjs <base> <out> name=path[#tab][@sel] ...'); process.exit(2); }
  const b = await pc.chromium.launch({ executablePath: '/usr/bin/google-chrome', headless: true, args: ['--disable-gpu', '--disable-dev-shm-usage'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  let page = await ctx.newPage();
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=username]', 'root'); await page.fill('input[name=password]', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit], input[type=submit]')]);
  for (const s of shots) {
    const m = s.match(/^([^=]+)=([^#@%]+)(?:#([^@%]+))?(?:@([^%]+))?(?:%(.+))?$/); if (!m) continue;
    const [, name, path, tab, sel, click] = m;   // %<selector>: click it after the tab (e.g. the report's Collect button)
    await page.close().catch(() => {}); page = await ctx.newPage();   // a fresh page per shot (a reused tall page crashed Chrome)
    await page.goto(base + path, { waitUntil: 'networkidle' }).catch(() => {});
    if (tab) { await page.locator('[role=tab]', { hasText: tab }).first().click(); }
    if (click) { await page.waitForTimeout(1500); await page.locator(click).first().click(); }
    await page.waitForTimeout(click ? 15000 : 7000);   // one live poll (5 s) + nchan render; a report collect takes a few seconds
    if (sel) {
      const el = page.locator(sel).first(); await el.scrollIntoViewIfNeeded();
      const h = await page.evaluate(() => document.documentElement.scrollHeight);
      await page.setViewportSize({ width: 1440, height: Math.min(h + 60, 12000) }); await page.waitForTimeout(800);
      await el.screenshot({ path: `${out}/${name}.png` });
    } else {
      const h = await page.evaluate(() => document.documentElement.scrollHeight);
      await page.setViewportSize({ width: 1440, height: Math.min(h + 60, 12000) }); await page.waitForTimeout(1200);
      await page.screenshot({ path: `${out}/${name}.png` });
    }
    console.log('shot', name, path, tab || '', sel || '');
  }
  await b.close();
})().catch(e => { console.error(e.message); process.exit(1); });
