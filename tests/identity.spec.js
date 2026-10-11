/* The two faults found in the college lab:
     1. two students, one email  -> the second silently took over the first
     2. two students, one seat   -> both got in
   Plus proof that crash-recovery still works, because that is what the
   reconnect path exists for. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

let browser;
async function pc() {
  const c = await browser.newContext({ viewport: { width: 1280, height: 860 } });
  const p = await c.newPage();
  p.on('dialog', async d => await d.accept());
  await p.goto(BASE + '/');
  return p;
}
async function register(p, { name, email, phone, seat }) {
  if (!/index|\/$/.test(new URL(p.url()).pathname) || !(await p.$('#rn'))) await p.goto(BASE + '/');
  if (await p.$('#s-wait.on')) await p.goto(BASE + '/?leave');
  await p.fill('#rn', name); await p.fill('#re', email);
  await p.fill('#rp', phone); await p.fill('#rs', seat);
  await p.click('#regGo');
  try {
    await p.waitForSelector('#s-wait.on', { timeout: 4000 });
    return { in: true };
  } catch (e) {
    return { in: false, error: (await p.textContent('#regHint')).trim() };
  }
}
const show = (label, r) =>
  console.log('   ' + label.padEnd(34) + (r.in ? 'IN' : 'REFUSED — ' + r.error));

(async () => {
  browser = await chromium.launch();

  console.log('1 first student registers');
  const a = await pc();
  show('Meet / meet@c.edu / LAB-01', await register(a, {
    name: 'Meet Raval', email: 'meet@c.edu', phone: '9876543210', seat: 'LAB-01' }));

  console.log('\n2 SAME EMAIL from another machine  (the lab bug)');
  const b = await pc();
  show('Prem / meet@c.edu / LAB-02', await register(b, {
    name: 'Prem Shah', email: 'meet@c.edu', phone: '9123456780', seat: 'LAB-02' }));
  console.log('   first student still in?          ' +
    (await a.$('#s-wait.on') ? 'YES' : 'NO — he was pushed out'));

  console.log('\n3 SAME SEAT, different email       (the second bug)');
  show('Prem / prem@c.edu / LAB-01', await register(b, {
    name: 'Prem Shah', email: 'prem@c.edu', phone: '9123456780', seat: 'LAB-01' }));
  console.log('   and lower case spelling:');
  show('Prem / prem@c.edu / lab-01', await register(b, {
    name: 'Prem Shah', email: 'prem@c.edu', phone: '9123456780', seat: 'lab-01' }));

  console.log('\n4 his own free seat works');
  show('Prem / prem@c.edu / LAB-02', await register(b, {
    name: 'Prem Shah', email: 'prem@c.edu', phone: '9123456780', seat: 'LAB-02' }));

  console.log('\n5 seat is required');
  const c = await pc();
  show('Zoya / zoya@c.edu / (blank)', await register(c, {
    name: 'Zoya Khan', email: 'zoya@c.edu', phone: '9000000000', seat: '' }));

  console.log('\n6 crash recovery: same email, SAME seat, straight back in');
  await a.goto(BASE + '/?leave');                       // as if the browser died
  show('Meet / meet@c.edu / LAB-01 again', await register(a, {
    name: 'Meet Raval', email: 'meet@c.edu', phone: '9876543210', seat: 'LAB-01' }));

  console.log('\n7 nobody was duplicated');
  const admin = await (await browser.newContext()).newPage();
  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw', 'vivacity2k26'); await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');
  await admin.waitForTimeout(1400);
  const rows = await admin.$$eval('#live tr', rs =>
    rs.map(r => r.children[0].innerText.replace(/\n+/g, ' / ')));
  rows.forEach(r => console.log('   ', r));

  await browser.close();
})();
