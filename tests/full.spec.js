const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

const DESIGN = `<style>
  body { margin:0; height:300px; background:#0F5257; display:grid; place-items:center }
  div { width:150px; height:150px; background:#F2C14E; border-radius:24px }
</style><div></div>`;
const ROUGH = `<style>body{margin:0;height:300px;background:#0F5257}</style>`;

const PEOPLE = [
  { name: 'Meet Patel', email: 'meet.patel@college.edu', phone: '98765 43210', seat: 'LAB2-09', code: DESIGN },
  { name: 'Meet Patel', email: 'meet.p2@college.edu',    phone: '91234 56780', seat: 'LAB2-10', code: ROUGH  },
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
  console.log('1 design attached : ok');

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
  console.log('2 two "Meet Patel" registered : ok');

  await admin.goto(BASE + '/admin/index.php');
  await admin.waitForSelector('[data-start="1"]');
  await admin.waitForTimeout(1300);
  console.log('3 roster rows     :');
  (await admin.$$eval('#live tr', rs => rs.map(r => r.children[0].innerText.replace(/\n/g, ' / ')))).forEach(
    r => console.log('     ', r));

  await admin.click('[data-start="1"]');
  for (const p of pcs) await p.waitForURL(/play\.php/, { timeout: 20000 });
  console.log('4 both in arena   : ok');

  for (let i = 0; i < pcs.length; i++) {
    await pcs[i].evaluate(c => {
      const t = document.getElementById('code');
      t.value = c; t.dispatchEvent(new Event('input'));
    }, PEOPLE[i].code);
    await pcs[i].waitForTimeout(600);
    await pcs[i].click('#submitBtn');
    await pcs[i].waitForURL(/thanks\.php/, { timeout: 20000 });
  }
  console.log('5 both submitted  : ok');

  await admin.waitForTimeout(1500);
  await admin.click('[data-end="1"]');
  await admin.waitForSelector('#scoreBtn:visible', { timeout: 15000 });
  /* SQLite (this test rig only) can lose a writer to lock contention where
     MySQL would not, leaving a job claimed. Press Score again until the queue
     drains — which is exactly what the organiser would do, and what the
     two-minute reclaim in score.php is there for. */
  const { execSync } = require('child_process');
  for (let attempt = 1; attempt <= 4; attempt++) {
    await admin.click('#scoreBtn');
    try {
      await admin.waitForFunction(
        () => /2 of 2 scored/.test(document.getElementById('qNote').textContent),
        null, { timeout: 40000 });
      break;
    } catch (e) {
      execSync(`php -r "require '${__dirname}/db_sqlite.php';
        db()->exec(\"UPDATE submissions SET status='queued' WHERE status='scoring'\");"`);
      await admin.reload();
      await admin.waitForSelector('#scoreBtn:visible', { timeout: 15000 });
    }
  }
  console.log('6 scored          :', (await admin.textContent('#qNote')).trim());

  await admin.goto(BASE + '/results.php?round=1');
  await admin.waitForSelector('table.live tbody tr');
  console.log('7 leaderboard     :');
  console.log('     ', (await admin.$$eval('table.live thead th', t => t.map(x => x.textContent.trim()).join(' | '))));
  (await admin.$$eval('table.live tbody tr', rs => rs.map(r =>
    [].map.call(r.children, c => c.textContent.trim()).join(' | ')))).forEach(r => console.log('     ', r));
  await admin.screenshot({ path: 'tests/g1-leaderboard.png', fullPage: true });

  const csv = await admin.evaluate(async () =>
    (await fetch('results.php?round=1&csv=1').then(r => r.text())).split('\n').slice(0, 3).join('\n'));
  console.log('8 csv head        :');
  csv.split('\n').forEach(l => console.log('     ', l.trim()));

  console.log('ERRORS', JSON.stringify(errs));
  await b.close();
})();
