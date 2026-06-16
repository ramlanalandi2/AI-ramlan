import { sendMessageViaApi } from './graph-service.js';
import { log, loadCsv, humanDelay } from './utils.js';
import { CONFIG } from './config.js';
import path from 'path';
import fs from 'fs-extra';

/**
 * Bulk send messages using Graph API
 * @param {Object} options 
 */
export const bulkApiSend = async (options = {}) => {
  const { csvFile, accessToken, messageTemplate } = options;

  if (!csvFile || !accessToken || !messageTemplate) {
    throw new Error('CSV file, Access Token, and Message Template are required');
  }

  const csvPath = path.isAbsolute(csvFile) ? csvFile : path.join(CONFIG.EXPORTS_DIR, csvFile);
  if (!await fs.pathExists(csvPath)) {
    throw new Error(`CSV file not found: ${csvPath}`);
  }

  const records = await loadCsv(csvPath);
  log.info(`Starting API Bulk Send to ${records.length} users...`);

  for (let i = 0; i < records.length; i++) {
    const record = records[i];
    const uid = record.uid || record.id;
    
    if (!uid) {
      log.warn(`Skipping record ${i + 1}: No UID found.`);
      continue;
    }

    log.step(`[${i + 1}/${records.length}] Preparing message for ${record.name || uid}...`);

    // Dynamic Placeholder Replacement
    let personalizedMessage = messageTemplate;
    for (const key in record) {
      const value = record[key] || '';
      const regex = new RegExp(`\\[${key}\\]`, 'g');
      personalizedMessage = personalizedMessage.replace(regex, value);
    }

    try {
      await sendMessageViaApi(uid, accessToken, personalizedMessage);
      
      // Cooldown to avoid rate limiting
      if (i < records.length - 1) {
        await humanDelay(1000, 3000); 
      }
    } catch (err) {
      log.error(`Failed to send to ${uid}: ${err.message}`);
    }
  }

  log.success('API Bulk Send completed!');
};
