// badge-flicker.cjs - checks that the Main page bay badges never disappear for a painted frame (build host only). Written: 2026-10-26.
// usage: SYNOHW_PW=... [BADGES_JS=<file>] node badge-flicker.cjs <base-url> [seconds]. BADGES_JS serves that file instead of the
// installed synohw-badges.js (control run with an older version). Samples every animation frame; prints frames drawn without
// a badge after the first one appeared, and how many times Unraid re-rendered the disk tables meanwhile (control: if there
// were no re-renders the check proves nothing).
const PW = process.env.SYNOHW_PW;
const pc = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');
(async () => {
  const [base, secs = '30'] = process.argv.slice(2);
  if (!PW || !base) { console.error('usage: SYNOHW_PW=... node badge-flicker.cjs <base> [seconds]'); process.exit(2); }
  const b = await pc.chromium.launch({ executablePath: '/usr/bin/google-chrome', headless: true, args: ['--disable-gpu', '--disable-dev-shm-usage'] });
  const page = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  if (process.env.BADGES_JS) await page.route(/synohw-badges\.js/, r => r.fulfill({ contentType: 'application/javascript', body: require('fs').readFileSync(process.env.BADGES_JS, 'utf8') }));
  await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=username]', 'root'); await page.fill('input[name=password]', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(base + '/Main', { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(5000);
  const r = await page.evaluate((ms) => new Promise(res => {
    let frames = 0, missing = 0, max = 0, rerenders = 0;
    new MutationObserver(ms2 => { for (const m of ms2) if (m.removedNodes.length && m.target.closest && m.target.closest('table')) { rerenders++; break; } })
      .observe(document.body, { childList: true, subtree: true });
    const end = performance.now() + ms;
    (function tick() {
      const n = document.querySelectorAll('.synohw-bay-badge').length;
      frames++; if (n > max) max = n; else if (max && n < max) missing++;
      if (performance.now() < end) requestAnimationFrame(tick); else res({ frames, missing, max, rerenders });
    })();
  }), Number(secs) * 1000);
  console.log(JSON.stringify(r));
  await b.close();
  process.exit(r.max > 0 && r.missing === 0 && r.rerenders > 0 ? 0 : 1);
})().catch(e => { console.error(e.message); process.exit(1); });
