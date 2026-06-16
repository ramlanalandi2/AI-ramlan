import { fetchMessagesFromApi, exportApiData, sendMessageViaApi, sendToThreadViaApi, fetchMessageTemplates } from './graph-service.js';
import { sendAutoDm } from './fanpage-sender.js';
import { bulkApiSend } from './bulk-api-sender.js';
import { log, selectFromList, selectFile, loadCsv, selectMenu } from './utils.js';
import chalk from 'chalk';
import { stringify } from 'csv-stringify/sync';
import fs from 'fs-extra';
import path from 'path';
import { CONFIG } from './config.js';
import readline from 'readline';

const usage = () => {
  console.log(`
Usage:
  node index.js                        (Interactive Menu)
  node index.js list-pages <cookie_file> [max_scrolls]
  node index.js export cookies.json [max_scrolls] [page_id]
  node index.js api-fetch <thread_id> <access_token> [limit]
  node index.js api-send <recipient_id> <access_token> <message> [tag]
  node index.js api-templates <access_token> [query] [language]
  node index.js api-bulk <csv_file> <access_token> <message_template_path>
  node index.js auto-send <cookie_file>
  `);
};

const main = async () => {
  const args = process.argv.slice(2);
  let command = args[0];
  let cookieFile = args[1];

  // --- MENU MODE ---
  if (!command) {
    if (!cookieFile) {
        // Try to find any json in cookies/ or root
        const cookieDir = CONFIG.COOKIES_DIR;
        const rootCwd = process.cwd();
        
        log.info('No command or cookie file provided. Entering Interactive Mode...');
        const pick = await selectFile(rootCwd, '.json', 'Cookie File') || 
                     await selectFile(cookieDir, '.json', 'Cookie File');
        
        if (!pick) {
            log.error('Cookie file is required to start.');
            return;
        }
        cookieFile = pick; // 🔥 Use full path instead of basename
    }

    const choice = await selectMenu();
    
    if (choice === 1) command = 'list-pages';
    else if (choice === 2) command = 'export';
    else if (choice === 3) command = 'api-fetch';
    else if (choice === 4) command = 'api-templates';
    else if (choice === 5) command = 'api-bulk';
    else if (choice === 6) command = 'auto-send';
    else {
        log.info('Exiting...');
        return;
    }
  }

  try {
    if (command === 'export') {
      let maxScrolls = args[2];
      let pageId = args[3];

      if (!pageId) {
        let pages = [];
        const localListPath = path.join(process.cwd(), 'fanpages_list.csv');
        
        if (await fs.pathExists(localListPath)) {
            log.info('Found existing fanpage list in CSV. Loading...');
            pages = await loadCsv(localListPath);
        }

        if (!pages || pages.length === 0) {
            log.info('No local list found. Scanning for available fanpages in browser...');
            pages = await listFanpages({ cookieFile });
        }

        if (!pages || pages.length === 0) {
            log.error('No fanpages found. Please use Option 1 first.');
            return;
        }

        const selected = await selectFromList(pages);
        if (!selected) return;
        pageId = selected.id;
      }

      await exportMessengerUids({ 
        cookieFile, 
        maxScrolls: maxScrolls ? parseInt(maxScrolls) : undefined,
        pageId
      });
    } 
    else if (command === 'list-pages') {
      let maxScrolls = args[2];
      const pages = await listFanpages({ cookieFile, maxScrolls: maxScrolls ? parseInt(maxScrolls) : 5 });
      
      if (pages && pages.length > 0) {
        const filePath = path.join(process.cwd(), 'fanpages_list.csv');
        const csvContent = stringify(pages, { header: true });
        await fs.writeFile(filePath, csvContent);
        log.success(`Fanpage list saved to: ${filePath}`);
      }
    }
    else if (command === 'auto-send') {
      // 1. Pick Fanpage list CSV
      const fanpageCsvPath = await selectFile(process.cwd(), '.csv', 'Fanpage List CSV');
      if (!fanpageCsvPath) return;

      const fanpages = await loadCsv(fanpageCsvPath);
      if (!fanpages || fanpages.length === 0) return;

      // 2. Select Sender Fanpage
      const selectedFanpage = await selectFromList(fanpages);
      if (!selectedFanpage) return;

      // 3. Pick Target UID CSV
      const exportDir = path.join(process.cwd(), 'exports');
      const targetCsvPath = await selectFile(exportDir, '.csv', 'Target UID CSV');
      if (!targetCsvPath) return;

      // 4. Pick Message Template
      const templateDir = path.join(process.cwd(), 'templates');
      const templatePath = await selectFile(templateDir, '.txt', 'Message Template');
      if (!templatePath) return;
      const message = await fs.readFile(templatePath, 'utf-8');

      await sendAutoDm({ 
        cookieFile, 
        csvFile: path.basename(targetCsvPath), 
        message, 
        pageId: selectedFanpage.id 
      });
    }
    else if (command === 'api-fetch') {
      let threadId = args[1];
      let accessToken = args[2];
      let limit = args[3] || 25;

      const rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout
      });

      if (!threadId || !accessToken) {
        log.info('Entering API Fetch Mode...');
        threadId = threadId || await new Promise(r => rl.question('Enter Thread ID (e.g. t_123...): ', r));
        accessToken = accessToken || await new Promise(r => rl.question('Enter Access Token: ', r));
      }
      rl.close();

      if (!threadId || !accessToken) {
        log.error('Thread ID and Access Token are required.');
        return;
      }

      const messages = await fetchMessagesFromApi(threadId, accessToken, limit);
      if (messages.length > 0) {
        await exportApiData(messages, threadId);
      }
    }
    else if (command === 'api-send') {
      let recipientId = args[1];
      let accessToken = args[2];
      let message = args[3];
      let tag = args[4];

      if (!recipientId || !accessToken || !message) {
        log.error('Recipient ID, Access Token, and Message are required.');
        return;
      }

      if (recipientId.startsWith('t_')) {
        await sendToThreadViaApi(recipientId, accessToken, message);
      } else {
        await sendMessageViaApi(recipientId, accessToken, message, tag);
      }
    }
    else if (command === 'api-bulk') {
      let csvFile = args[1];
      let accessToken = args[2];
      let templatePath = args[3];

      if (!csvFile || !accessToken || !templatePath) {
        log.info('Entering API Bulk Mode...');
        
        const exportDir = path.join(process.cwd(), 'exports');
        csvFile = csvFile || await selectFile(exportDir, '.csv', 'Target UID CSV');
        
        const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
        accessToken = accessToken || await new Promise(r => rl.question('Enter Page Access Token: ', r));
        rl.close();

        const templateDir = path.join(process.cwd(), 'templates');
        templatePath = templatePath || await selectFile(templateDir, '.txt', 'Message Template');
      }

      if (!csvFile || !accessToken || !templatePath) {
        log.error('All arguments are required for API Bulk Send.');
        return;
      }

      const messageTemplate = await fs.readFile(templatePath, 'utf-8');
      await bulkApiSend({ csvFile, accessToken, messageTemplate });
    }
    else if (command === 'api-templates') {
      let accessToken = args[1];
      let query = args[2] || 'order';
      let language = args[3] || 'en';

      const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
      if (!accessToken) {
        log.info('Entering Templates Search Mode...');
        accessToken = await new Promise(r => rl.question('Enter Access Token: ', r));
        query = await new Promise(r => rl.question('Enter search query (default: order): ', r)) || 'order';
      }
      rl.close();

      if (!accessToken) {
        log.error('Access Token is required.');
        return;
      }

      const templates = await fetchMessageTemplates(accessToken, query, language);
      if (templates.length > 0) {
          console.log('\n' + chalk.yellow('Available Templates:'));
          console.table(templates.map(t => ({ Name: t.name, Status: t.status, Language: t.language })));
      }
    }
    else if (command === 'send') {
      let [csvFile, message, pageId] = args.slice(2);
      if (!cookieFile || !csvFile || !message) {
        log.error('Missing required arguments for send.');
        usage();
        return;
      }
      await sendAutoDm({ cookieFile, csvFile, message, pageId });
    } 
    else {
      log.error(`Unknown command: ${command}`);
      usage();
    }
  } catch (err) {
    log.error(`Application error: ${err.message}`);
    process.exit(1);
  }
};

main();
