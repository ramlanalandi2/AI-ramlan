import { chromium } from 'playwright';
import { loadCookies, log } from '../utils.js';
import path from 'path';

const extractToken = async () => {
    const cookiePath = path.join(process.cwd(), 'cookies.json');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    
    const cookies = await loadCookies(cookiePath);
    await context.addCookies(cookies);
    
    const page = await context.newPage();
    log.info('Navigating to Graph API Explorer to extract token...');
    
    try {
        await page.goto('https://developers.facebook.com/tools/explorer/', { waitUntil: 'networkidle' });
        await page.waitForTimeout(5000);
        
        const token = await page.evaluate(() => {
            // This is a common way to find the token in the Explorer UI
            const inputs = Array.from(document.querySelectorAll('input'));
            const tokenInput = inputs.find(i => i.value && i.value.startsWith('EAAB') || i.value.startsWith('EAAC') || i.value.startsWith('EAAR'));
            return tokenInput ? tokenInput.value : null;
        });
        
        if (token) {
            log.success('Extracted Token: ' + token);
            return token;
        } else {
            log.error('Could not find token in Graph API Explorer.');
        }
    } catch (err) {
        log.error('Extraction failed: ' + err.message);
    } finally {
        await browser.close();
    }
};

extractToken();
