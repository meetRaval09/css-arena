/* The lab report: two PCs, SAME name-email-phone-seat. The second took over
   the first — the first student's name changed to the second student's on his
   own waiting screen. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

let browser;
async function freshPC() {
  const c = await browser.newContext({ viewport: { width: 1200, height: 800 } });
  const p = await c.newPage();
  p.on('dialog', async d => await d.accept());
  await p.goto(BASE + '/');
  return p;
}
async function reg(p, { name, email, phone, seat }) {
  await p.fill('#rn', name); await p.fill('#re', email);
  await p.fill('#rp', phone); await p.fill('#rs', seat);
  await p.click('#regGo');
  try { await p.waitForSelector('#s-wait.on', { timeout: 4000 }); return { in: true }; }
  catch (e) { return { in: false, error: (await p.textContent('#regHint')).trim() }; }
}
const waitingName = p => p.textContent('#waitName').then(t => t.trim());

(async () => {
  browser = await chromium.launch();

  const pc1 = await freshPC();
  const r1 = await reg(pc1, { name: 'Meet Raval', email: 'same@c.edu', phone: '9876543210', seat: '1' });
  console.log('PC1  Meet Raval / same@c.edu / seat 1   ->', r1.in ? 'IN' : 'REFUSED');
  console.log('PC1  waiting room shows                 ->', await waitingName(pc1));

  await pc1.waitForTimeout(1500);                 // PC1 is polling, i.e. alive

  const pc2 = await freshPC();
  const r2 = await reg(pc2, { name: 'Prem Shah', email: 'same@c.edu', phone: '9123456780', seat: '1' });
  console.log('\nPC2  Prem Shah / same@c.edu / seat 1    ->',
              r2.in ? 'IN  ** TAKEOVER **' : 'REFUSED — ' + r2.error);

  await pc1.waitForTimeout(2500);
  console.log('PC1  waiting room still shows           ->', await waitingName(pc1));
  console.log('PC1  still in the waiting room?         ->',
              await pc1.$('#s-wait.on') ? 'YES' : 'NO — he was thrown out');

  /* and the roster must hold one person, not one impostor */
  const admin = await (await browser.newContext()).newPage();
  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw', 'vivacity2k26'); await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');
  await admin.waitForTimeout(1400);
  console.log('\nconsole roster:');
  (await admin.$$eval('#live tr', rs => rs.map(r => r.children[0].innerText.replace(/\n+/g, ' / '))))
    .forEach(r => console.log('   ', r));

  /* "start over" must let the SAME person back in at once */
  console.log('\nPC1 presses start over, then re-registers immediately:');
  await pc1.goto(BASE + '/?leave');
  const r3 = await reg(pc1, { name: 'Meet Raval', email: 'same@c.edu', phone: '9876543210', seat: '1' });
  console.log('   ', r3.in ? 'IN — no waiting' : 'REFUSED — ' + r3.error);

  await browser.close();
})();
