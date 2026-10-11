/* The shell change: body must fill the canvas, so body-level paint covers the
   whole picture — including when the code has no elements at all. And the
   server shell must agree with the browser preview, or the score contradicts
   what the participant saw. */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8112';

const FRAME   = `<style>body{background:#000;box-shadow:0 0 0 20px inset #54C144}</style>`;
const FRAMED  = `<div></div><style>div{width:100px;height:100px;background:#B3D4A0}
body{background:#000;box-shadow:0 0 0 20px inset #54C144}</style>`;

(async () => {
  const b = await chromium.launch();
  const admin = await (await b.newContext({ viewport:{width:1400,height:1000} })).newPage();
  admin.on('dialog', async d => { d.type()==='prompt' ? await d.accept('30') : await d.accept(); });
  admin.on('pageerror', e => console.log('PAGEERROR', e.message));

  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw','vivacity2k26'); await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');

  /* a target made ONLY of body rules — no elements whatsoever */
  await admin.goto(BASE + '/admin/designs.php');
  await admin.fill('textarea[name=code]', FRAME);
  await admin.fill('input[name=name]', 'Body Frame');
  await admin.selectOption('select[name=round]', '1');
  await admin.click('#renderGo');
  await admin.waitForSelector('#approve', { timeout: 60000 });

  const png = await admin.getAttribute('#approve img', 'src');
  const buf = await admin.evaluate(async src => {
    const r = await fetch(src); const b = new Uint8Array(await r.arrayBuffer());
    return Array.from(b);
  }, png);
  fs.writeFileSync('tests/probe-target.png', Buffer.from(buf));
  console.log('1 target from body rules only : rendered, ' + buf.length + ' bytes');

  /* how green is it? a body-only design used to render completely empty */
  const { execSync } = require('child_process');
  const green = execSync(`php -r "
    \\$im = imagecreatefrompng('tests/probe-target.png');
    \\$g = 0; \\$t = 0;
    for (\\$y = 0; \\$y < 300; \\$y += 3) for (\\$x = 0; \\$x < 400; \\$x += 3) {
      \\$c = imagecolorat(\\$im, \\$x, \\$y); \\$t++;
      if (((\\$c >> 8) & 255) > 120 && (\\$c & 255) < 120) \\$g++;
    }
    echo round(100 * \\$g / \\$t, 1);"`).toString();
  console.log('2 green pixels in target      : ' + green + '%  (0% = the old bug)');

  /* the participant's own preview must match */
  const pc = await (await b.newContext({ viewport:{width:1500,height:900} })).newPage();
  pc.on('dialog', async d => await d.accept());
  await pc.goto(BASE + '/');
  await pc.fill('#rn','Meet'); await pc.fill('#re','m@c.edu');
  await pc.fill('#rp','9876543210'); await pc.fill('#rs','1');
  await pc.click('#regGo'); await pc.waitForSelector('#s-wait.on');

  await admin.goto(BASE + '/admin/index.php');
  await admin.waitForSelector('[data-start="1"]');
  await admin.click('[data-start="1"]');
  await pc.waitForURL(/play\.php/, { timeout: 20000 });

  console.log('3 arena header says           : "' + (await pc.textContent('.nm')).trim() + '"');

  await pc.evaluate(c => { const t=document.getElementById('code'); t.value=c; t.dispatchEvent(new Event('input')); }, FRAME);
  await pc.waitForTimeout(700);
  const previewGreen = await pc.evaluate(() => {
    const f = document.querySelector('#out, iframe');
    const d = f.contentDocument;
    return getComputedStyle(d.body).boxShadow.indexOf('inset') >= 0
        && Math.round(d.body.getBoundingClientRect().height);
  });
  console.log('4 preview body height         : ' + previewGreen + 'px  (300 = fills the canvas)');

  await pc.click('#submitBtn');
  await pc.waitForURL(/thanks\.php/, { timeout: 20000 });
  await admin.waitForTimeout(1200);
  await admin.reload();
  await admin.waitForSelector('#scoreBtn:visible', { timeout: 15000 });
  for (let i=0;i<4;i++){
    await admin.click('#scoreBtn');
    try { await admin.waitForFunction(() => /1 of 1 scored/.test(document.getElementById('qNote').textContent), null, {timeout:40000}); break; }
    catch(e){ execSync(`php -r "require '${__dirname}/db_sqlite.php'; db()->exec(\\"UPDATE submissions SET status='queued' WHERE status='scoring'\\");"`);
              await admin.reload(); await admin.waitForSelector('#scoreBtn:visible',{timeout:15000}); }
  }
  await admin.goto(BASE + '/results.php?round=1');
  await admin.waitForSelector('table.live tbody tr');
  const row = await admin.$$eval('table.live tbody tr td', ts => ts.map(t=>t.textContent.trim()).join(' | '));
  console.log('5 identical code scores       : ' + row);

  await b.close();
})();
