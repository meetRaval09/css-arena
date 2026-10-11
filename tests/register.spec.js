/* Registration with the new fields — and the thing this change is for:
   two different students who happen to share a name. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

async function tryRegister(b, { name, email, phone, seat }) {
  const c = await b.newContext();
  const p = await c.newPage();
  await p.goto(BASE + '/');
  await p.fill('#rn', name);
  await p.fill('#re', email);
  await p.fill('#rp', phone);
  if (seat) await p.fill('#rs', seat);
  await p.click('#regGo');
  await p.waitForTimeout(900);
  const onWait = await p.evaluate(() =>
    document.querySelector('#s-wait').classList.contains('on'));
  const hint = (await p.textContent('#regHint')).trim();
  return { p, onWait, hint };
}

(async () => {
  const b = await chromium.launch();
  const errs = [];

  // ---- validation ----
  const bad = [
    { label: 'no email      ', name: 'Test One', email: '', phone: '9876543210' },
    { label: 'broken email  ', name: 'Test Two', email: 'not-an-email', phone: '9876543210' },
    { label: 'no phone      ', name: 'Test Three', email: 'a@b.com', phone: '' },
    { label: 'silly phone   ', name: 'Test Four', email: 'c@d.com', phone: '12' },
  ];
  for (const t of bad) {
    const r = await tryRegister(b, t);
    console.log(t.label, ':', r.onWait ? 'LET THROUGH (bad)' : JSON.stringify(r.hint));
    await r.p.context().close();
  }

  // ---- two students, same name, different emails ----
  const a = await tryRegister(b, { name: 'Meet Patel', email: 'meet.patel@college.edu',  phone: '98765 43210', seat: 'LAB2-09' });
  console.log('same name #1  :', a.onWait ? 'registered' : 'REFUSED ' + a.hint);
  const c2 = await tryRegister(b, { name: 'Meet Patel', email: 'meet.p2@college.edu',    phone: '91234 56780', seat: 'LAB2-10' });
  console.log('same name #2  :', c2.onWait ? 'registered' : 'REFUSED ' + c2.hint);

  // ---- same email again = same person coming back ----
  const again = await tryRegister(b, { name: 'Meet Patel', email: 'MEET.PATEL@college.edu', phone: '98765 43210', seat: 'LAB2-09' });
  console.log('same email    :', again.onWait ? 'reconnected' : 'REFUSED ' + again.hint);

  // what the database actually holds
  const { execSync } = require('child_process');
  const rows = execSync(`php -r "require '${__dirname}/db_sqlite.php';
    foreach (db()->query('SELECT id,name,email,phone,seat_no FROM participants') as \\$r)
      echo implode(' | ', \\$r), PHP_EOL;"`).toString().trim();
  console.log('in the database:');
  rows.split('\n').forEach(r => console.log('   ', r));

  console.log('ERRORS', JSON.stringify(errs));
  await b.close();
})();
