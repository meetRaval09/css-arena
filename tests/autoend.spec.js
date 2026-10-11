const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

const DESIGN = `<style>
  body { margin:0; height:300px; background:#0F5257; display:grid; place-items:center }
  div { width:150px; height:150px; background:#F2C14E; border-radius:24px }
</style><div></div>`;

const PEOPLE = [
  { name: 'Meet Patel', email: 'meet.patel@college.edu', phone: '98765 43210', seat: 'LAB2-09' },
  { name: 'Prem Shah',  email: 'prem@college.edu',       phone: '91234 56780', seat: 'LAB2-10' },
];

(async () => {
  const b = await chromium.launch();
  const errs = [];

  const ac = await b.newContext({ viewport: { width: 1440, height: 1100 } });
  const admin = await ac.newPage();
  admin.on('pageerror', e => errs.push('admin: ' + e.message));
  admin.on('dialog', async d => { d.type() === 'prompt' ? await d.accept('30') : await d.accept(); });

  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw', 'vivacity2k26');
  await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');

  await admin.goto(BASE + '/admin/designs.php');
  await admin.fill('textarea[name=code]', DESIGN);
  await admin.fill('input[name=name]', 'Rounded Square');
  await admin.selectOption('select[name=round]', '1');
  await admin.click('#renderGo');
  await admin.waitForSelector('#approve', { timeout: 40000 });
  console.log('1 design attached        : ok');

  const pcs = [];
  for (const person of PEOPLE) {
    const c = await b.newContext({ viewport: { width: 1500, height: 900 } });
    const p = await c.newPage();
    p.on('pageerror', e => errs.push(person.email + ': ' + e.message));
    p.on('dialog', async d => await d.accept());
    await p.goto(BASE + '/');
    await p.fill('#rn', person.name);
    await p.fill('#re', person.email);
    await p.fill('#rp', person.phone);
    await p.fill('#rs', person.seat);
    await p.click('#regGo');
    await p.waitForSelector('#s-wait.on', { timeout: 8000 });
    pcs.push(p);
  }
  console.log('2 two registered         : ok');

  await admin.goto(BASE + '/admin/index.php');
  await admin.waitForSelector('[data-start="1"]');
  await admin.click('[data-start="1"]');
  for (const p of pcs) await p.waitForURL(/play\.php/, { timeout: 20000 });
  console.log('3 both in arena          : ok  (30 minute limit — the clock is nowhere near zero)');

  const status = async () => (await admin.evaluate(async () =>
    (await fetch('state.php', { cache: 'no-store' }).then(r => r.json())).rounds[0].status));

  // first person submits — the round must KEEP running
  await pcs[0].evaluate(c => { const t = document.getElementById('code'); t.value = c; t.dispatchEvent(new Event('input')); }, DESIGN);
  await pcs[0].waitForTimeout(500);
  await pcs[0].click('#submitBtn');
  await pcs[0].waitForURL(/thanks\.php/, { timeout: 20000 });
  await admin.waitForTimeout(800);
  console.log('4 after 1 of 2 submitted : round is "' + (await status()) + '"  (expected running)');

  // confetti on the thank-you page
  const canvasNow = await pcs[0].evaluate(() => document.querySelectorAll('canvas').length);
  console.log('5 confetti canvas        : ' + (canvasNow > 0 ? 'drawing' : 'MISSING'));
  await pcs[0].screenshot({ path: 'tests/g2-confetti.png' });

  // second person submits — the round must stop by itself
  await pcs[1].evaluate(c => { const t = document.getElementById('code'); t.value = c; t.dispatchEvent(new Event('input')); }, DESIGN);
  await pcs[1].waitForTimeout(500);
  await pcs[1].click('#submitBtn');
  await pcs[1].waitForURL(/thanks\.php/, { timeout: 20000 });
  await admin.waitForTimeout(900);
  console.log('6 after 2 of 2 submitted : round is "' + (await status()) + '"  (expected ended)');

  // the finisher must NOT be thrown back to the front door
  await pcs[0].waitForTimeout(3000);
  console.log('7 finisher still on      : ' + new URL(pcs[0].url()).pathname);

  // and the console should be offering to score
  await admin.reload();
  await admin.waitForSelector('#scoreBtn:visible', { timeout: 15000 });
  console.log('8 console offers Score   : ok');

  // the "not scored yet" hint on the standings page
  await admin.goto(BASE + '/results.php?round=1');
  const hint = await admin.evaluate(() => {
    const c = [...document.querySelectorAll('.card')].find(x => /not scored yet/.test(x.textContent));
    return c ? c.innerText.replace(/\s+/g, ' ').trim() : null;
  });
  console.log('9 standings hint         : ' + hint);
  await admin.screenshot({ path: 'tests/g3-results-hint.png', fullPage: true });

  console.log('ERRORS', JSON.stringify(errs));
  await b.close();
})();
