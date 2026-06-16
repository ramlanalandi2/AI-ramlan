import { chromium } from 'playwright';
import { CONFIG } from './config.js';
import { log, loadCookies, getProfileDir, humanDelay, verifyFacebookLogin, waitAnyKey } from './utils.js';
import { stringify } from 'csv-stringify/sync';
import fs from 'fs-extra';
import path from 'path';

/**
 * Auto Export UID from Messenger History
 * @param {Object} options 
 */
export const exportMessengerUids = async (options = {}) => {
  const { cookieFile, maxScrolls = CONFIG.MAX_SCROLLS, pageId, messengerUrl } = options;
  if (!cookieFile) throw new Error('Cookie file is required');

  let cookiePath = cookieFile;
  if (!path.isAbsolute(cookieFile) && !await fs.pathExists(cookieFile)) {
    cookiePath = path.join(CONFIG.COOKIES_DIR, cookieFile);
  }

  const browser = await chromium.launch({
    headless: CONFIG.HEADLESS,
  });

  const context = await browser.newContext({
    viewport: { width: 1280, height: 720 },
    locale: CONFIG.LOCALE,
    timezoneId: CONFIG.TIMEZONE || 'Asia/Jakarta',
    extraHTTPHeaders: {
      'Accept-Language': 'en-US,en;q=0.9'
    }
  });

  // 🔥 ANTI DETECT TRICK
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
  });

  try {
    const cookies = await loadCookies(cookiePath);
    if (cookies.length > 0) {
      await context.addCookies(cookies);
      log.success(`Pre-injected ${cookies.length} cookies into context`);
    }

    const page = await context.newPage();
    
    log.step('Navigating to Facebook...');
    await page.goto('https://www.facebook.com/', { waitUntil: 'domcontentloaded' });
    
    /**
     * ✅ VERIFY LOGIN
     */
    log.info('Verifying cookie-based login...');
    const isLoggedIn = await verifyFacebookLogin(page);
    
    if (!isLoggedIn) {
      await fs.ensureDir(CONFIG.EXPORTS_DIR);
      const screenshotPath = path.join(CONFIG.EXPORTS_DIR, `login_fail_${Date.now()}.png`);
      await page.screenshot({ path: screenshotPath });
      log.error(`Cookie login FAILED. Diagnostic screenshot saved to: ${screenshotPath}`);
      await context.close();
      return;
    }
    
    log.success('Login success 🚀');

    /**
     * ✅ NAVIGATE TO INBOX
     */
    if (messengerUrl) {
      log.step(`Navigating to provided Messenger URL...`);
      await page.goto(messengerUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForTimeout(5000);
    } else if (pageId) {
      log.step(`Navigating to Meta Business Suite Inbox for Page: ${pageId}...`);
      const inboxUrl = `https://business.facebook.com/latest/inbox/all?page_id=${pageId}`;
      await page.goto(inboxUrl, { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(5000);
    } else {
      log.step('Navigating to standard Messenger...');
      await page.goto('https://www.facebook.com/messages/t/', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(3000);
    }

    // 🔥 ENSURE MESSENGER FILTER IS ACTIVE (Meta Business Suite)
    log.info('Ensuring Messenger filter is active...');
    const messengerFilters = [
      'div[aria-label="Messenger"]',
      'div[data-testid="inbox_sidebar_messenger_tab"]',
      'div[role="listitem"] div[aria-label*="Messenger"]',
      'a[href*="selected_item_id"][href*="messenger"]'
    ];

    for (const selector of messengerFilters) {
      try {
        const btn = page.locator(selector).first();
        if (await btn.isVisible()) {
          log.info(`Activating filter: ${selector}`);
          await btn.click();
          await page.waitForTimeout(3000);
          break;
        }
      } catch (e) {}
    }
    
    log.info('Starting conversation list scraping (Regex HTML Scraper)...');
    const uids = new Set();
    const results = [];

    let scrolls = 0;
    while (scrolls < maxScrolls) {
      log.info(`Scroll ${scrolls + 1}/${maxScrolls}...`);
      
      const found = await page.evaluate(() => {
        const localResults = [];
        const seenInBatch = new Set();

        // --- PHASE 1: REGEX HTML SCANNER (User provided) ---
        const html = document.documentElement.innerHTML;
        const regex = /"fb_attributes":\{"user_id":"(\d+)"\}.*?"full_name":"([^"]+)".*?"profile_uris":\[\{"uri":"([^"]+)"/gs;
        
        let match;
        while ((match = regex.exec(html)) !== null) {
          const uid = match[1];
          const name = match[2];
          const profile = match[3].replace(/\\\//g, '/'); // fix escaped URL

          if (uid && !seenInBatch.has(uid)) {
            seenInBatch.add(uid);
            localResults.push({ uid, name, profile });
          }
        }

        // --- PHASE 2: INBOX ROW FALLBACK ---
        const bsRows = Array.from(document.querySelectorAll('div[role="row"], div[data-testid="inbox_thread_list_item"]'));
        bsRows.forEach(row => {
          const text = row.innerText || '';
          const name = text.split('\n')[0];
          const link = row.querySelector('a[href*="selected_item_id="]');
          if (link) {
            const m = link.getAttribute('href').match(/selected_item_id=([0-9]+)/);
            const uid = m ? m[1] : null;
            if (uid && !seenInBatch.has(uid)) {
              seenInBatch.add(uid);
              localResults.push({ 
                uid, 
                name, 
                profile: `https://www.facebook.com/profile.php?id=${uid}` 
              });
            }
          }
        });

        return localResults;
      });

      found.forEach(item => {
        if (!uids.has(item.uid)) {
          uids.add(item.uid);
          results.push({
            uid: item.uid,
            name: item.name,
            profile: item.profile
          });
        }
      });

      log.info(`Found ${uids.size} unique UIDs so far.`);

      // --- PHASE 4: SMART SCROLLING ---
      await page.evaluate(() => {
        const scroller = document.querySelector('div[data-testid="inbox_thread_list_scroller"]') || 
                           document.querySelector('div[aria-label="Conversations"]') ||
                           document.querySelector('div[role="navigation"] [role="grid"]') ||
                           window;
        if (scroller.scrollBy) {
          scroller.scrollBy(0, 1000);
        } else {
          window.scrollBy(0, 1000);
        }
      });

      await humanDelay(CONFIG.SCROLL_WAIT, CONFIG.SCROLL_WAIT + 1000);
      scrolls++;
    }

    log.success(`Export finished! Total UIDs: ${uids.size}`);

    await fs.ensureDir(CONFIG.EXPORTS_DIR);
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const fileName = `export_${timestamp}.csv`;
    const filePath = path.join(CONFIG.EXPORTS_DIR, fileName);
    
    const csvOutput = stringify(results, { header: true });
    await fs.writeFile(filePath, csvOutput);
    
    log.success(`Data saved to ${filePath}`);
    return filePath;

  } catch (err) {
    log.error(`Export failed: ${err.message}`);
  } finally {
    if (context) await context.close();
    if (typeof browser !== 'undefined' && browser) await browser.close();
  }
};

/**
 * List Fanpages available to the account
 * @param {Object} options 
 */
export const listFanpages = async (options = {}) => {
  const { cookieFile } = options;
  if (!cookieFile) throw new Error('Cookie file is required');

  let cookiePath = cookieFile;
  if (!path.isAbsolute(cookieFile) && !await fs.pathExists(cookieFile)) {
    cookiePath = path.join(CONFIG.COOKIES_DIR, cookieFile);
  }

  const cookies = await loadCookies(cookiePath);
  log.step(`Launching browser to list Fanpages using ${cookieFile}...`);
  
  const browser = await chromium.launch({
    headless: CONFIG.HEADLESS,
  });

  const context = await browser.newContext({
    viewport: { width: 1280, height: 720 },
    locale: CONFIG.LOCALE,
    timezoneId: CONFIG.TIMEZONE || 'Asia/Jakarta',
    extraHTTPHeaders: {
      'Accept-Language': 'en-US,en;q=0.9'
    }
  });

  // 🔥 ANTI DETECT TRICK
  await context.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
  });

  try {
    const cookies = await loadCookies(cookiePath);
    if (cookies.length > 0) {
      await context.addCookies(cookies);
      log.success(`Pre-injected ${cookies.length} cookies into context`);
    }

    const page = await context.newPage();
    
    log.step('Navigating to Facebook...');
    await page.goto('https://www.facebook.com/', { waitUntil: 'domcontentloaded' });
    
    log.info('Verifying cookie-based login...');
    const isLoggedIn = await verifyFacebookLogin(page);
    
    if (!isLoggedIn) {
      await fs.ensureDir(CONFIG.EXPORTS_DIR);
      const screenshotPath = path.join(CONFIG.EXPORTS_DIR, `login_fail_list_${Date.now()}.png`);
      await page.screenshot({ path: screenshotPath });
      log.error(`Cookie login FAILED. Diagnostic screenshot saved to: ${screenshotPath}`);
      await context.close();
      return;
    }

    log.success('Login success 🚀');

    const verifyCookies = await page.context().cookies();
    const activeUid = verifyCookies.find(c => c.name === 'i_user' || c.name === 'c_user')?.value || '';
    
    log.step(`Navigating to Profile to test stability: https://www.facebook.com/profile.php?id=${activeUid}...`);
    await page.goto(`https://www.facebook.com/profile.php?id=${activeUid}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(3000);
    
    log.step(`Navigating to Your Pages list...`);
    await page.goto('https://www.facebook.com/pages/?category=your_pages', { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(3000);
    
    log.info('Scrolling to load all pages...');
    await page.evaluate(async () => {
      for (let i = 0; i < 5; i++) {
        window.scrollBy(0, 1000);
        await new Promise(r => setTimeout(r, 1000));
      }
    });

    log.info('Extracting Fanpages (DOM Sequence v7 logic)...');
    const pages = await page.evaluate(() => {
      const results = [];
      
      // 1️⃣ Collect all unique Profile Links in the order they appear on the page
      const allProfileLinks = Array.from(document.querySelectorAll('a[href*="profile.php?id="]'));
      const uniqueProfiles = [];
      const seenUrls = new Set();
      
      allProfileLinks.forEach(el => {
        const url = el.href;
        const name = el.innerText.trim();
        // Skip crumbs or empty links
        if (name.length > 2 && !seenUrls.has(url) && !url.includes('/pages/')) {
          seenUrls.add(url);
          uniqueProfiles.push({ el, name, url });
        }
      });

      // 2️⃣ Collect all Messenger Links in the order they appear on the page
      const allMessengerLinks = Array.from(document.querySelectorAll('a[href]'))
        .filter(el => {
          const href = el.getAttribute('href') || '';
          return href.includes('/latest/inbox/all') || href.includes('/latest/inbox');
        });

      // 3️⃣ Pair by sequence (Link N of Type A matches Link N of Type B)
      // This is extremely robust for structured lists.
      uniqueProfiles.forEach((profile, index) => {
        const messengerEl = allMessengerLinks[index]; 
        
        let mUrl = messengerEl ? messengerEl.href : null;
        if (mUrl && !mUrl.startsWith('http')) {
          mUrl = 'https://www.facebook.com' + (mUrl.startsWith('/') ? '' : '/') + mUrl;
        }

        let assetId = 'Unknown';
        if (mUrl) {
          const urlParams = new URLSearchParams(mUrl.split('?')[1]);
          assetId = urlParams.get('asset_id') || urlParams.get('page_id') || 'Unknown';
          
          // Fallback if ID not in main URL: check the surrounding content of this specific link
          if (assetId === 'Unknown' && messengerEl) {
             const row = messengerEl.closest('div[role="article"]') || 
                         messengerEl.closest('div[role="row"]') || 
                         profile.el.closest('div[role="article"]');
             if (row) {
                const match = row.innerHTML.match(/[?&](asset_id|page_id)=([0-9]+)/);
                if (match) assetId = match[2];
             }
          }
        }

        results.push({
          name: profile.name,
          id: assetId,
          url: profile.url,
          'url messenger': mUrl || 'N/A'
        });
      });

      return results;
    });

    if (pages.length === 0) {
      log.warn('No Fanpages found. You might need to scroll or wait longer.');
    } else {
      log.success(`Found ${pages.length} Fanpages:`);
      console.table(pages);
    }
    
    return pages;

  } catch (err) {
    log.error(`Listing pages failed: ${err.message}`);
    return [];
  } finally {
    if (context) await context.close();
    if (typeof browser !== 'undefined' && browser) await browser.close();
  }
};
