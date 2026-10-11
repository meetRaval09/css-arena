/* The round must survive being started a SECOND time.
   Last time's submissions are still in the table; if they were counted, the
   new round would end in the same second it began. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

(async () => {
  const b = await chromium.launch();
  const admin = await (await b.newContext({ viewport:{width:1440,height:1000} })).newPage();
  admin.on('dialog', async d => { d.type()==='prompt' ? await d.accept('30') : await d.accept(); });
  admin.on('pageerror', e => console.log('PAGEERROR', e.message));

  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw','vivacity2k26'); await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');

  const st = async () => (await admin.evaluate(async () =>
    (await fetch('state.php',{cache:'no-store'}).then(r=>r.json())).rounds[0].status));

  console.log('before        : round 1 is "' + await st() + '"  (ended, everyone submitted last run)');

  // the organiser starts Round 1 again
  await admin.click('[data-start="1"]');
  await admin.waitForTimeout(3000);
  console.log('after restart : round 1 is "' + await st() + '"  (expected running)');

  await admin.waitForTimeout(4000);
  console.log('7s later      : round 1 is "' + await st() + '"  (expected running)');

  await b.close();
})();
