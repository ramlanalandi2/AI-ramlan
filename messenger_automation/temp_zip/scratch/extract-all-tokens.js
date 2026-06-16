import { chromium } from 'playwright';
import { loadCookies, log } from '../utils.js';
import path from 'path';
import chalk from 'chalk';

const extractAllTokens = async () => {
    const cookiePath = path.join(process.cwd(), 'cookies.json');
    const browser = await chromium.launch({ headless: true });
    const context = await browser.newContext();
    
    const cookies = await loadCookies(cookiePath);
    await context.addCookies(cookies);
    
    const page = await context.newPage();
    log.info('Step 1: Navigating to Graph API Explorer...');
    
    try {
        await page.goto('https://developers.facebook.com/tools/explorer/', { waitUntil: 'networkidle' });
        await page.waitForTimeout(5000);
        
        // Extract User Token
        const userToken = await page.evaluate(() => {
            const inputs = Array.from(document.querySelectorAll('input'));
            const tokenInput = inputs.find(i => i.value && (i.value.startsWith('EAAB') || i.value.startsWith('EAAC') || i.value.startsWith('EAAR')));
            return tokenInput ? tokenInput.value : null;
        });
        
        if (!userToken) {
            log.error('Could not find User Token. Please check if cookies.json is valid.');
            return;
        }

        log.success('Step 2: User Token Found!');
        log.info('Step 3: Fetching all Page Access Tokens...');

        // Fetch Page Tokens using User Token
        const accountsUrl = `https://graph.facebook.com/v18.0/me/accounts?access_token=${userToken}`;
        const response = await fetch(accountsUrl);
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error.message);
        }

        if (data.data && data.data.length > 0) {
            log.success(`Found ${data.data.length} Pages!`);
            console.log('\n' + chalk.cyan('================ PAGE ACCESS TOKENS ================'));
            data.data.forEach(acc => {
                console.log(chalk.yellow(`\nPage Name: ${acc.name}`));
                console.log(chalk.white(`Page ID:   ${acc.id}`));
                console.log(chalk.green(`Token:     ${acc.access_token}`));
                console.log(chalk.cyan('----------------------------------------------------'));
            });
            console.log(chalk.cyan('====================================================\n'));
        } else {
            log.warn('No pages found for this account.');
        }

    } catch (err) {
        log.error('Extraction failed: ' + err.message);
    } finally {
        await browser.close();
    }
};

extractAllTokens();
