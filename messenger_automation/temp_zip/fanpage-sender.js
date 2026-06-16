import { chromium } from 'playwright';
import { CONFIG } from './config.js';
import { log, loadCookies, getProfileDir, humanDelay, verifyFacebookLogin } from './utils.js';
import { parse } from 'csv-parse/sync';
import fs from 'fs-extra';
import path from 'path';

/**
 * Auto DM to UID using Fanpage
 * @param {Object} options 
 */
export const sendAutoDm = async (options = {}) => {
  const { cookieFile, csvFile, message, pageId } = options;
  if (!cookieFile || !csvFile || !message) {
    throw new Error('Cookie file, CSV file, and message are required');
  }

  let cookiePath = cookieFile;
  if (!path.isAbsolute(cookieFile) && !await fs.pathExists(cookieFile)) {
    cookiePath = path.join(CONFIG.COOKIES_DIR, cookieFile);
  }

  const csvPath = path.join(CONFIG.EXPORTS_DIR, csvFile);
  if (!await fs.pathExists(csvPath)) {
    throw new Error(`CSV file not found: ${csvPath}`);
  }

  const fileContent = await fs.readFile(csvPath, 'utf-8');
  const records = parse(fileContent, { columns: true, skip_empty_lines: true });

  log.step(`Launching browser for DM sending using ${cookieFile}...`);
  
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
      const screenshotPath = path.join(CONFIG.EXPORTS_DIR, `login_fail_dm_${Date.now()}.png`);
      await page.screenshot({ path: screenshotPath });
      log.error(`Cookie login FAILED. Diagnostic screenshot saved to: ${screenshotPath}`);
      await context.close();
      return;
    }
    log.success('Login success 🚀');

    log.info(`Broadcasting message to ${records.length} users...`);

    for (let i = 0; i < records.length; i++) {
        const { uid, name } = records[i];
        log.step(`[${i + 1}/${records.length}] Sending DM to ${name || uid}...`);

        try {
            const targetUrl = records[i].profile || `https://www.facebook.com/profile.php?id=${uid}`;
            log.step(`[${i + 1}/${records.length}] Visiting profile: ${targetUrl}`);

            await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
            await page.waitForTimeout(4000);

            // ⛔ CHECK FOR LOCKED PROFILE (mengunci profil)
            const isLocked = await page.evaluate(() => {
                const text = document.body.innerText.toLowerCase();
                return text.includes('locked their profile') || 
                       text.includes('locks his profile') || 
                       text.includes('locks her profile') ||
                       text.includes('mengunci profilnya');
            });

            if (isLocked) {
                log.warn(`Profile ${uid} is LOCKED. Skipping...`);
                continue;
            }

            // 📩 DETECT IF ALREADY IN CHAT (Messenger/Direct Thread)
            const isDirectChat = targetUrl.includes('messenger.com') || targetUrl.includes('facebook.com/messages');
            let clicked = isDirectChat;

            if (!isDirectChat) {
                // 📩 CLICK MESSAGE BUTTON
                log.info('Looking for Message button...');
                const messageButtons = [
                    'div[role="button"]:has-text("Message")',
                    'div[aria-label="Message"]',
                    'div[aria-label="Kirim pesan"]',
                    'div[role="button"]:has-text("Pesan")'
                ];

                for (const selector of messageButtons) {
                    try {
                        const btn = page.locator(selector).first();
                        if (await btn.isVisible()) {
                            await btn.click();
                            clicked = true;
                            log.success('Message button clicked.');
                            break;
                        }
                    } catch (e) {}
                }

                if (!clicked) {
                    // Fallback attempt: find by text in span
                    try {
                        const textBtn = page.locator('span').filter({ hasText: /^Message$|^Pesan$/ }).first();
                        if (await textBtn.isVisible()) {
                            await textBtn.click();
                            clicked = true;
                            log.success('Message button (text-based) clicked.');
                        }
                    } catch (e) {}
                }
            } else {
                log.info('Direct chat URL detected. Skipping button click.');
            }

            if (!clicked) {
                log.error(`Could not find Message button for ${uid}. Skipping.`);
                continue;
            }

            await page.waitForTimeout(3000);

            // 🔥 DYNAMIC PLACEHOLDER REPLACEMENT
            let clientMessage = message;
            for (const key in records[i]) {
                const value = records[i][key] || '';
                const regex = new RegExp(`\\[${key}\\]`, 'g');
                clientMessage = clientMessage.replace(regex, value);
            }

            // ✍️ TYPE MESSAGE IN CHAT WINDOW
            const editorSelector = 'div[contenteditable="true"][role="textbox"], div[aria-label="Message"], div[aria-label="Tulis pesan..."]';
            const editor = page.locator(editorSelector).last(); // Small chat window is usually the last one
            
            await editor.waitFor({ state: 'visible', timeout: 15000 });
            await editor.click();
            await page.keyboard.type(clientMessage, { delay: 50 });
            await page.waitForTimeout(1000);
            
            await page.keyboard.press('Enter');
            log.success(`Message sent successfully to ${uid}`);
            
            // Wait for cooldown
            await humanDelay(CONFIG.DEFAULT_WAIT, CONFIG.DEFAULT_WAIT + 5000);

        } catch (err) {
            log.error(`Failed to send message to ${uid}: ${err.message}`);
        }
    }

    log.success('Broadcasting completed!');

  } catch (err) {
    log.error(`Broadcast process failed: ${err.message}`);
  } finally {
    if (context) await context.close();
    if (typeof browser !== 'undefined' && browser) await browser.close();
  }
};
