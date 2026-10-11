const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8112';

(async () => {
  const b = await chromium.launch();
  const errs = [];
  const c = await b.newContext({ viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
  const admin = await c.newPage();
  admin.on('pageerror', e => errs.push('admin: ' + e.message));
  admin.on('dialog', async d => { d.type() === 'prompt' ? await d.accept('30') : await d.accept(); });

  await admin.goto(BASE + '/admin/login.php');
  await admin.fill('#pw', 'vivacity2k26');
  await admin.click('button[type=submit]');
  await admin.waitForSelector('#roundCards .rcard');
  console.log('1 console still loads    : ok');

  // the console must still do its normal job
  await admin.waitForTimeout(1500);
  const live = await admin.$$eval('#live tr', r => r.length);
  console.log('2 roster still polling   : ' + live + ' rows');

  const grab = async (label) => {
    const [dl] = await Promise.all([
      admin.waitForEvent('download', { timeout: 30000 }),
      admin.click('a[href="export.php"]'),
    ]);
    const path = 'tests/' + label + '.xlsx';
    await dl.saveAs(path);
    console.log('   ' + label + ' -> ' + dl.suggestedFilename() + '  ' + fs.statSync(path).size + ' bytes');
    return path;
  };

  console.log('3 export downloads       :');
  const f = await grab('record');

  console.log('ERRORS', JSON.stringify(errs));
  await b.close();
})();
