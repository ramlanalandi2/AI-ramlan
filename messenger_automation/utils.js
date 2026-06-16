import fs from 'fs-extra';
import path from 'path';
import chalk from 'chalk';
import { CONFIG } from './config.js';

const LOG_FILE = path.join(process.cwd(), 'run_log.txt');

/**
 * Custom logger with file support
 */
export const log = {
  _write: (prefix, msg) => {
    const timestamp = new Date().toLocaleString();
    const cleanMsg = msg.replace(/\x1b\[[0-9;]*m/g, ''); // Remove chalk colors
    const line = `[${timestamp}] ${prefix} ${cleanMsg}\n`;
    try {
      fs.appendFileSync(LOG_FILE, line);
    } catch (e) { /* ignore */ }
  },
  info: (msg) => {
    console.log(chalk.blue('ℹ ') + msg);
    log._write('INFO', msg);
  },
  success: (msg) => {
    console.log(chalk.green('✔ ') + msg);
    log._write('SUCCESS', msg);
  },
  warn: (msg) => {
    console.log(chalk.yellow('⚠ ') + msg);
    log._write('WARN', msg);
  },
  error: (msg) => {
    console.log(chalk.red('✘ ') + msg);
    log._write('ERROR', msg);
  },
  step: (msg) => {
    console.log(chalk.magenta('➜ ') + msg);
    log._write('STEP', msg);
  },
};

import readline from 'readline';

/**
 * Human-like delay
 */
export const humanDelay = async (
  min = CONFIG.HUMAN_DELAY_RANGE[0],
  max = CONFIG.HUMAN_DELAY_RANGE[1]
) => {
  const delay = Math.floor(Math.random() * (max - min + 1) + min);
  await new Promise(resolve => setTimeout(resolve, delay));
};

/**
 * Interactice selection from CLI
 * @param {Array} items List of objects
 * @param {String} labelField Field to display
 * @returns {Promise<Object>} Selected item
 */
export const selectFromList = async (items, labelField = 'name') => {
  if (!items || !items.length) return null;

  console.log('\n' + chalk.yellow('Available Fanpages:'));
  items.forEach((item, index) => {
    console.log(`${chalk.green(index + 1)}: ${item[labelField]} ${chalk.gray(`(ID: ${item.id})`)}`);
  });

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise((resolve) => {
    rl.question(`\nSelect fanpage (1-${items.length}) or press Enter to skip: `, (answer) => {
      rl.close();
      const num = parseInt(answer);
      if (!isNaN(num) && num > 0 && num <= items.length) {
        resolve(items[num - 1]);
      } else {
        resolve(null);
      }
    });
  });
};

/**
 * Wait for user to press Enter
 * @param {String} message Message to display
 */
export const waitAnyKey = async (message = 'Press Enter to continue...') => {
  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise((resolve) => {
    rl.question(`\n${chalk.cyan(message)}`, () => {
      rl.close();
      resolve();
    });
  });
};

/**
 * Load & normalize cookies (FIXED per user instruction)
 */
export const loadCookies = async (cookiePath) => {
  if (!await fs.pathExists(cookiePath)) {
    log.warn(`Cookie file not found: ${cookiePath}`);
    return [];
  }

  try {
    const cookies = await fs.readJson(cookiePath);

    if (!Array.isArray(cookies)) {
      log.error(`Invalid cookie format (must be array)`);
      return [];
    }

    const mapped = cookies.map((c, index) => {
      try {
        if (!c.name || !c.value) return null;

        /**
         * ✅ SAME SITE FIX
         */
        let sameSite;
        if (c.sameSite) {
          const ss = String(c.sameSite).toLowerCase();

          if (ss === 'lax') sameSite = 'Lax';
          else if (ss === 'strict') sameSite = 'Strict';
          else if (ss === 'no_restriction' || ss === 'none') sameSite = 'None';
          // kalau unknown → skip (biar Playwright handle default)
        }

        // Simplify domain to .facebook.com for all cookies (matched the successful test)
        const domain = '.facebook.com';

        const normalized = {
          name: c.name,
          value: String(c.value),
          domain: '.facebook.com', // 🔥 GLOBAL DOMAIN (Proven in tests)
          path: c.path || '/',
          httpOnly: !!c.httpOnly,
          secure: c.secure !== false,
        };

        if (sameSite) {
          normalized.sameSite = sameSite;
        }

        let expiry = c.expirationDate || c.expires;
        if (expiry && expiry > 0) {
          normalized.expires = Math.floor(expiry);
        }

        return normalized;

      } catch (e) {
        log.warn(`Skipping cookie ${index}: ${e.message}`);
        return null;
      }
    }).filter(Boolean);

    return mapped;

  } catch (err) {
    log.error(`Error loading cookies: ${err.message}`);
    return [];
  }
};


/**
 * Verify Facebook login (IMPROVED per user instruction)
 */
export const verifyFacebookLogin = async (page) => {
  try {
    await page.waitForTimeout(5000);

    const url = page.url();

    if (url.includes('login') || url.includes('checkpoint')) {
      log.warn(`Not logged in → ${url}`);
      return false;
    }

    /**
     * ✅ cek cookies session
     */
    const cookies = await page.context().cookies();
    
    log.info(`[DEBUG] Checking for c_user: ${cookies.find(c => c.name === 'c_user')?.value || 'NOT FOUND'}`);
    log.info(`[DEBUG] Checking for xs: ${cookies.find(c => c.name === 'xs')?.value || 'NOT FOUND'}`);

    const hasSession =
      cookies.some(c => c.name === 'c_user') &&
      cookies.some(c => c.name === 'xs');

    if (!hasSession) {
      log.warn('Session cookies missing (c_user/xs)');
      const cookieNames = cookies.map(c => c.name);
      log.info(`[DEBUG] Cookies currently in context: ${cookieNames.join(', ') || 'NONE'}`);
      return false;
    }

    /**
     * ✅ cek UI login indicator
     */
    const isLogged = await page.evaluate(() => {
      return !!document.querySelector('[aria-label="Account"]') ||
             !!document.querySelector('[role="navigation"]') ||
             !!document.querySelector('a[href*="logout"]') ||
             !!document.querySelector('[aria-label="Facebook"]') ||
             !!document.querySelector('[aria-label="Messenger"]');
    });

    if (isLogged) {
      log.success('Login verified (UI + cookies)');
      return true;
    } else {
      const pageTitle = await page.title();
      log.info(`[DEBUG] Page Title: ${pageTitle}`);
      log.info(`[DEBUG] Current URL: ${url}`);
      
      // Strict verification: Facebook Feed UI or profile indicator must be present,
      // or at the very least URL shouldn't be Facebook's root login screen.
      // If we see "Log in to Facebook" in page title, it's a hard fail.
      if (hasSession && url !== 'https://www.facebook.com/login.php' && !pageTitle.match(/log in|masuk/i)) {
         log.success('Login verified (Cookies present, no login redirect)');
         return true;
      }
      
      log.warn(`UI indicator not found! (Logged out)`);
    }

    return false;

  } catch (err) {
    log.error(`Verification error: ${err.message}`);
    return false;
  }
};

/**
 * Interactive file selection from a directory
 */
export const selectFile = async (dir, extension, label = 'file') => {
  if (!await fs.pathExists(dir)) {
    log.error(`Directory not found: ${dir}`);
    return null;
  }

  const files = (await fs.readdir(dir)).filter(f => !extension || f.endsWith(extension));
  if (files.length === 0) {
    log.warn(`No ${extension} files found in ${dir}`);
    return null;
  }

  console.log('\n' + chalk.yellow(`Available ${label}s:`));
  files.forEach((f, index) => {
    console.log(`${chalk.green(index + 1)}: ${f}`);
  });

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise((resolve) => {
    rl.question(`\nSelect ${label} (1-${files.length}) or press Enter to skip: `, (answer) => {
      rl.close();
      const num = parseInt(answer);
      if (!isNaN(num) && num > 0 && num <= files.length) {
        resolve(path.join(dir, files[num - 1]));
      } else {
        resolve(null);
      }
    });
  });
};

import { parse } from 'csv-parse/sync';

/**
 * Load and parse a CSV file
 */
export const loadCsv = async (filePath) => {
  if (!await fs.pathExists(filePath)) return null;
  const content = await fs.readFile(filePath, 'utf-8');
  return parse(content, { columns: true, skip_empty_lines: true });
};

/**
 * Main Menu selection from CLI
 */
export const selectMenu = async () => {
  console.clear();
  console.log(chalk.cyan('========================================'));
  console.log(chalk.bold.white('       FACEBOOK FACTORY MANAGER V5      '));
  console.log(chalk.cyan('========================================'));
  console.log(`${chalk.green('1.')} Get List Fanpage (Discover & Save)`);
  console.log(`${chalk.green('2.')} Get UID (Extract from Inbox)`);
  console.log(`${chalk.green('3.')} Get UID via Graph API (New)`);
  console.log(`${chalk.green('4.')} Search API Template Library (WhatsApp/Business)`);
  console.log(`${chalk.green('5.')} API Bulk Message (High Performance)`);
  console.log(`${chalk.green('6.')} Auto Message (Browser Workflow)`);
  console.log(chalk.cyan('----------------------------------------'));
  console.log(`${chalk.red('0.')} Exit`);
  console.log(chalk.cyan('========================================'));

  const rl = readline.createInterface({
    input: process.stdin,
    output: process.stdout
  });

  return new Promise((resolve) => {
    rl.question(`\nSelect option (0-6): `, (answer) => {
      rl.close();
      resolve(parseInt(answer));
    });
  });
};

/**
 * Profile directory
 */
export const getProfileDir = (cookieFile) => {
  const hash = Buffer.from(cookieFile).toString('hex').slice(0, 8);
  const dir = path.join(CONFIG.PROFILES_DIR, `node_messenger_${hash}`);
  fs.ensureDirSync(dir);
  return dir;
};
