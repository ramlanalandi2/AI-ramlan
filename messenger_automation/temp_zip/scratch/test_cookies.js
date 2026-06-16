import { chromium } from 'playwright';

async function test() {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  
  const testCookies = [
    {
      name: 'test_dot',
      value: '1',
      domain: '.facebook.com',
      path: '/',
      secure: true,
      sameSite: 'None'
    },
    {
      name: 'test_no_dot',
      value: '1',
      domain: 'facebook.com',
      path: '/',
      secure: true,
      sameSite: 'None'
    },
    {
      name: 'test_url',
      value: '1',
      url: 'https://www.facebook.com',
      path: '/',
      secure: true,
      sameSite: 'None'
    }
  ];

  for (const c of testCookies) {
    try {
      await context.addCookies([c]);
      console.log(`✅ Success: ${c.name} (${JSON.stringify(c)})`);
    } catch (e) {
      console.log(`❌ Fail: ${c.name} (${JSON.stringify(c)}) - ${e.message}`);
    }
  }

  await browser.close();
}

test();
