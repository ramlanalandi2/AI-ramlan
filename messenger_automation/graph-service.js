import { log } from './utils.js';
import fs from 'fs-extra';
import path from 'path';
import { CONFIG } from './config.js';
import { stringify } from 'csv-stringify/sync';

/**
 * Fetch messages from Facebook Graph API
 * @param {String} threadId Thread ID (e.g. t_123456)
 * @param {String} accessToken Facebook Access Token
 * @param {Number} limit Number of messages to fetch
 */
export const fetchMessagesFromApi = async (threadId, accessToken, limit = 25) => {
  const url = `https://graph.facebook.com/v18.0/${threadId}/messages?access_token=${accessToken}&fields=id,created_time,from,to,message&limit=${limit}&method=get&pretty=0&sdk=joey&suppress_http_code=1`;

  log.step(`Fetching messages for thread: ${threadId}...`);
  
  try {
    const response = await fetch(url);
    const data = await response.json();

    if (data.error) {
      throw new Error(`Graph API Error: ${data.error.message}`);
    }

    if (!data.data || data.data.length === 0) {
      log.warn('No messages found in this thread.');
      return [];
    }

    log.success(`Successfully fetched ${data.data.length} messages.`);
    return data.data;

  } catch (err) {
    log.error(`API Fetch failed: ${err.message}`);
    throw err;
  }
};

/**
 * Send message via Facebook Graph API
 * @param {String} recipientId User ID or PSID
 * @param {String} accessToken Page Access Token
 * @param {String} messageText Text to send
 * @param {String} tag Optional Message Tag (e.g. ACCOUNT_UPDATE, CONFIRMED_EVENT_UPDATE)
 */
export const sendMessageViaApi = async (recipientId, accessToken, messageText, tag = null) => {
  const url = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;

  log.step(`Sending API message to ${recipientId}${tag ? ` with tag ${tag}` : ''}...`);

  try {
    const body = {
      recipient: { id: recipientId },
      message: { text: messageText }
    };

    if (tag) {
      body.messaging_type = 'MESSAGE_TAG';
      body.tag = tag;
    } else {
      body.messaging_type = 'RESPONSE';
    }

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body)
    });

    let data = await response.json();

    // Fallback: try user_id if id fails
    if (data.error && data.error.code === 100) {
      log.info(`Retrying with user_id key for ${recipientId}...`);
      const retryResponse = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          recipient: { user_id: recipientId },
          message: { text: messageText }
        })
      });
      data = await retryResponse.json();
    }

    if (data.error) {
      throw new Error(`Graph API Send Error: ${data.error.message}`);
    }

    log.success(`Message sent successfully via API to ${recipientId}. ID: ${data.message_id || 'N/A'}`);
    return data;

  } catch (err) {
    log.error(`API Send failed: ${err.message}`);
    throw err;
  }
};

/**
 * Send message to a specific Thread ID via Graph API
 * @param {String} threadId Thread ID (e.g. t_123...)
 * @param {String} accessToken 
 * @param {String} messageText 
 */
export const sendToThreadViaApi = async (threadId, accessToken, messageText) => {
  const url = `https://graph.facebook.com/v18.0/${threadId}/messages?access_token=${accessToken}`;

  log.step(`Sending API message to thread ${threadId}...`);

  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        message: { text: messageText }
      })
    });

    const data = await response.json();

    if (data.error) {
      throw new Error(`Graph API Thread Send Error: ${data.error.message}`);
    }

    log.success(`Message sent successfully to thread ${threadId}.`);
    return data;

  } catch (err) {
    log.error(`API Thread Send failed: ${err.message}`);
    throw err;
  }
};

/**
 * Export messages to CSV and JSON
 * @param {Array} messages List of message objects
 * @param {String} threadId 
 */
export const exportApiData = async (messages, threadId) => {
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  const baseName = `api_export_${threadId}_${timestamp}`;
  
  await fs.ensureDir(CONFIG.EXPORTS_DIR);

  // 1. Save JSON (Full data)
  const jsonPath = path.join(CONFIG.EXPORTS_DIR, `${baseName}.json`);
  await fs.writeJson(jsonPath, messages, { spaces: 2 });
  log.success(`Full JSON data saved to: ${jsonPath}`);

  // 2. Save CSV (UID Export Format)
  // This format matches the existing UID export for compatibility with auto-send
  const csvData = messages.map(msg => {
    const from = msg.from || {};
    return {
      uid: from.id || '',
      name: from.name || '',
      profile: from.id ? `https://www.facebook.com/profile.php?id=${from.id}` : '',
      message: msg.message || '',
      created_time: msg.created_time || ''
    };
  }).filter(item => item.uid); // Only items with UIDs

  // Deduplicate by UID
  const uniqueData = Array.from(new Map(csvData.map(item => [item.uid, item])).values());

  const csvPath = path.join(CONFIG.EXPORTS_DIR, `${baseName}.csv`);
  const csvOutput = stringify(uniqueData, { header: true });
  await fs.writeFile(csvPath, csvOutput);
  log.success(`CSV export (UID format) saved to: ${csvPath}`);

  return { jsonPath, csvPath, count: uniqueData.length };
};

/**
 * Fetch Message Templates from Library
 * @param {String} accessToken 
 * @param {String} query Search query (name or content)
 * @param {String} language Language code (e.g. en, id)
 */
export const fetchMessageTemplates = async (accessToken, query = 'order', language = 'en') => {
  const url = `https://graph.facebook.com/v25.0/message_template_library?name_or_content=${query}&language=${language}&access_token=${accessToken}`;

  log.step(`Fetching message templates for query: ${query}...`);

  try {
    const response = await fetch(url);
    const data = await response.json();

    if (data.error) {
      throw new Error(`Graph API Templates Error: ${data.error.message}`);
    }

    if (!data.data || data.data.length === 0) {
      log.warn('No templates found for this query.');
      return [];
    }

    log.success(`Successfully fetched ${data.data.length} templates.`);
    return data.data;

  } catch (err) {
    log.error(`API Templates Fetch failed: ${err.message}`);
    throw err;
  }
};
/**
 * Send a named template message via Facebook Graph API (Utility/Marketing templates)
 * @param {String} recipientId User ID or PSID
 * @param {String} accessToken Page Access Token
 * @param {String} templateName Name of the template (e.g. utility_messenger)
 * @param {String} language Language code (default: en_US)
 */
export const sendTemplateViaApi = async (recipientId, accessToken, templateName, language = 'en_US') => {
  const url = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;

  log.step(`Sending template "${templateName}" to ${recipientId}...`);

  try {
    const body = {
      recipient: { id: recipientId },
      message: {
        template: {
          name: templateName,
          language: {
            code: language
          }
        }
      }
    };

    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(body)
    });

    const data = await response.json();

    if (data.error || !response.ok) {
      log.error(`Full Error Response: ${JSON.stringify(data, null, 2)}`);
      throw new Error(`Graph API Template Send Error: ${data.error ? data.error.message : 'Unknown Error'}`);
    }

    log.success(`Template message sent successfully to ${recipientId}. ID: ${data.message_id || 'N/A'}`);
    return data;

  } catch (err) {
    log.error(`API Template Send failed: ${err.message}`);
    throw err;
  }
};

/**
 * Send a structured message (Generic or Button template) via Facebook Graph API
 * @param {String} recipientId User ID or PSID
 * @param {String} accessToken Page Access Token
 * @param {String} type 'generic' or 'button'
 * @param {Object} payload Template payload
 */
export const sendStructuredMessage = async (recipientId, accessToken, type, payload) => {
  const url = `https://graph.facebook.com/v18.0/me/messages?access_token=${accessToken}`;

  log.step(`Sending ${type} template to ${recipientId}...`);

  try {
    const body = {
      recipient: { id: recipientId },
      message: {
        attachment: {
          type: 'template',
          payload: {
            template_type: type,
            ...payload
          }
        }
      }
    };

    const response = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    });

    const data = await response.json();

    if (data.error || !response.ok) {
      log.error(`Structured Send Error: ${JSON.stringify(data, null, 2)}`);
      throw new Error(`Graph API Structured Send Error: ${data.error ? data.error.message : 'Unknown Error'}`);
    }

    log.success(`${type.toUpperCase()} template sent successfully to ${recipientId}.`);
    return data;

  } catch (err) {
    log.error(`API Structured Send failed: ${err.message}`);
    throw err;
  }
};
