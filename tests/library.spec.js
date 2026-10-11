/* Rendering a second design must not make the first one disappear. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8112';

const D = (bg, fg, r) => `<style>body{margin:0;height:300px;background:${bg};display:grid;place-items:center}
div{width:150px;height:150px;background:${fg};border-radius:${r}px}</style><div></div>`;

const DESIGNS = [
  { name: 'Rounded Square', round: '1', code: D('#0F5257', '#F2C14E', 24) },
  { name: 'Pinwheel',       round: '2', code: D('#231942', '#9F86C0', 4)  },
  { name: 'Bullseye',       round: '3', code: D('#0B3954', '#FF6663', 75) },
  { name: 'Second for R1',  round: '1', code: D('#1B2021', '#30BCED', 12) },
];

const lib = p => p.$$eval('.card img[loading=lazy]', els =>
  els.map(e => e.closest('.card').innerText.split('\n').filter(Boolean).slice(0, 3).join(' · ')));

(async () => {
  const b = await chromium.launch();
  const p = await (await b.newContext({ viewport: { width: 1400, height: 1100 } })).newPage();
  p.on('pageerror', e => console.log('PAGEERROR', e.message));
  p.on('dialog', async d => await d.accept());

  await p.goto(BASE + '/admin/login.php');
  await p.fill('#pw', 'vivacity2k26'); await p.click('button[type=submit]');
  await p.waitForSelector('#roundCards .rcard');

  for (const d of DESIGNS) {
    await p.goto(BASE + '/admin/designs.php');
    await p.fill('textarea[name=code]', d.code);
    await p.fill('input[name=name]', d.name);
    await p.selectOption('select[name=round]', d.round);
    await p.click('#renderGo');
    await p.waitForSelector('#approve', { timeout: 60000 });
    console.log('rendered ' + d.name.padEnd(16) + '-> library holds ' + (await lib(p)).length);
  }

  console.log('\nlibrary after four renders:');
  (await lib(p)).forEach(x => console.log('   ', x));

  console.log('\nput the FIRST design back on Round 1:');
  const cards = await p.$$('.card:has(img[loading=lazy])');
  const oldest = cards[cards.length - 1];               // library is newest-first
  console.log('   (oldest card is:', (await oldest.innerText()).split('\n')[0] + ')');
  await oldest.$$eval('form[method=post]', fs => {
    const f = fs.find(x => x.querySelector('input[name=round][value="1"]'));
    f.querySelector('button').click();
  });
  await p.waitForTimeout(900);
  const kv = await p.$$eval('.kv', els => els.map(e => e.innerText.replace(/\n/g, ' -> ')));
  kv.forEach(x => console.log('   ', x));

  await p.screenshot({ path: 'tests/g5-library.png', fullPage: true });
  await b.close();
})();
