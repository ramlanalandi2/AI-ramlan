import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export const CONFIG = {
  // Paths
  PROJECT_ROOT: path.join(__dirname, '..', '..'),
  COOKIES_DIR: path.join(__dirname, '..', '..', 'cookies'),
  PROFILES_DIR: path.join(__dirname, '..', '..', 'profiles'),
  EXPORTS_DIR: path.join(__dirname, 'exports'),

  // Facebook URLs
  MESSENGER_URL: 'https://www.facebook.com/messages/t/',
  BUSINESS_INBOX_URL: 'https://business.facebook.com/latest/inbox/',

  // Automation settings
  HEADLESS: false, // Changed to false to allow user to see the browser
  DEFAULT_WAIT: 5000,
  HUMAN_DELAY_RANGE: [500, 1500],
  SCROLL_WAIT: 2000,
  MAX_SCROLLS: 1,

  // Stealth settings
  USER_AGENT: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  LOCALE: 'en-US,en;q=0.9',
  TIMEZONE: 'Asia/Jakarta',

  // CSV settings
  DEFAULT_UID_FILE: 'uids.csv'
};
