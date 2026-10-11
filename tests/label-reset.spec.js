/* (3) the arena must name the ROUND, not the design's old name
   (4) full reset must clear "live on R_" without deleting the designs */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';
const D = bg => `<style>body{background:${bg};box-shadow:0 0 0 20px inset #54C144}</style>`;

(async () => {
  const b = await chromium.launch();
  const admin = await (await b.newContext({ viewport:{width:1400,height:1000} })).newPage();
  admin.on('dialog', async d => { d.type()==='prompt' ? await d.accept('30') : await d.accept(); });
  admin.on('pageerror', e => console.log('PAGEERROR', e.message));
  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw','vivacity2k26'); await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');

  /* a design left with its default name, then attached to Round 2 */
  await admin.goto(BASE + '/admin/designs.php');
  await admin.fill('textarea[name=code]', D('#231942'));
  await admin.fill('input[name=name]', 'Round 1 design');
  await admin.selectOption('select[name=round]', '2');
  await admin.click('#renderGo');
  await admin.waitForSelector('#approve', { timeout: 60000 });
  console.log('1 design named "Round 1 design" attached to Round 2');

  const pc = await (await b.newContext({ viewport:{width:1500,height:900} })).newPage();
  pc.on('dialog', async d => await d.accept());
  await pc.goto(BASE + '/');
  await pc.fill('#rn','Prem'); await pc.fill('#re','p@c.edu');
  await pc.fill('#rp','9123456780'); await pc.fill('#rs','2');
  await pc.click('#regGo'); await pc.waitForSelector('#s-wait.on');

  await admin.goto(BASE + '/admin/index.php');
  await admin.waitForSelector('[data-start="2"]');
  await admin.click('[data-start="2"]');
  await pc.waitForURL(/play\.php/, { timeout: 25000 });
  console.log('2 arena header now says      : "' + (await pc.textContent('.nm')).trim()
            + '"   (must be Round 2)');

  /* full reset */
  await admin.goto(BASE + '/admin/designs.php');
  const before = await admin.$$eval('.card img[loading=lazy]', e => e.length);
  await admin.goto(BASE + '/admin/index.php');
  await admin.waitForSelector('#btnFullReset');
  await admin.click('#btnFullReset');
  await admin.waitForTimeout(1500);

  await admin.goto(BASE + '/admin/designs.php');
  const after = await admin.$$eval('.card img[loading=lazy]', e => e.length);
  const live  = await admin.$$eval('.pill.q', e => e.length);
  const kv    = await admin.$$eval('.kv', e => e.map(x => x.innerText.replace(/\n/g,' -> ')));
  console.log('\n3 designs in library before   : ' + before);
  console.log('4 designs in library after    : ' + after + '   (must be the same)');
  console.log('5 "live on R_" badges after   : ' + live + '   (must be 0)');
  console.log('6 attached list after reset   :');
  kv.forEach(x => console.log('     ', x));

  await b.close();
})();
