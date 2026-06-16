import { spawn, exec } from 'child_process';
import axios from 'axios';
import chalk from 'chalk';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const log = {
  info: (msg) => console.log(chalk.blue('ℹ ') + msg),
  success: (msg) => console.log(chalk.green('✔ ') + msg),
  error: (msg) => console.log(chalk.red('✘ ') + msg),
  warn: (msg) => console.log(chalk.yellow('⚠ ') + msg),
};

const CONFIG = {
  LARAVEL_PATH: path.join(__dirname, '..'),
  NGROK_DOMAIN: 'chasing-jalapeno-discolor.ngrok-free.dev',
  LOCAL_PORT: 8000
};

async function isPortActive(port) {
  return new Promise((resolve) => {
    exec(`netstat -ano | findstr :${port}`, (err, stdout) => {
      resolve(stdout && stdout.includes('LISTENING'));
    });
  });
}

async function isNgrokActive() {
  try {
    const response = await axios.get('http://127.0.0.1:4040/api/tunnels', { timeout: 2000 });
    return response.data.tunnels.some(t => t.public_url.includes(CONFIG.NGROK_DOMAIN));
  } catch (e) {
    return false;
  }
}

async function checkDatabase() {
  if (await isPortActive(3306)) {
    log.success('Database MySQL sudah aktif (Port 3306).');
    return true;
  } else {
    log.error('MySQL BELUM AKTIF! Silakan nyalakan MySQL di XAMPP Control Panel dulu.');
    return false;
  }
}

async function startLaravel() {
  if (await isPortActive(CONFIG.LOCAL_PORT)) {
    log.success('Laravel Backend sudah jalan (Port 8000).');
    return;
  }

  log.info('Memulai Laravel Backend (php artisan serve)...');
  const artisan = spawn('php', ['artisan', 'serve', '--port=' + CONFIG.LOCAL_PORT], {
    cwd: CONFIG.LARAVEL_PATH,
    detached: true,
    stdio: 'ignore'
  });
  artisan.unref();

  // Tunggu sebentar sampai server naik
  await new Promise(r => setTimeout(r, 3000));
}

async function startNgrok() {
  if (await isNgrokActive()) {
    log.success('Ngrok Tunnel sudah aktif.');
    return;
  }

  log.info(`Mengaktifkan Ngrok Tunnel: ${CONFIG.NGROK_DOMAIN}...`);
  const ngrok = spawn('ngrok', ['http', `--domain=${CONFIG.NGROK_DOMAIN}`, CONFIG.LOCAL_PORT.toString()], {
    detached: true,
    stdio: 'ignore'
  });
  ngrok.unref();

  // Tunggu ngrok inisialisasi
  await new Promise(r => setTimeout(r, 3000));
}

async function main() {
  console.clear();
  console.log(chalk.cyan('========================================'));
  console.log(chalk.bold.white('      RAMLAN AI SYSTEM BOOTSTRAP       '));
  console.log(chalk.cyan('========================================'));

  try {
    const dbOk = await checkDatabase();
    if (!dbOk) {
      log.warn('Bot akan tetap dijalankan, tapi kemungkinan besar akan Error 500 jika MySQL mati.');
      console.log(chalk.yellow('----------------------------------------'));
    }

    await startLaravel();
    await startNgrok();
    
    log.success('Semua sistem Engine sudah siap!');
    log.info('Menjalankan RAMLAN Multi-Platform Responder...');
    console.log(chalk.cyan('----------------------------------------\n'));

    // 1. Jalankan Facebook Responder
    const fbResponder = spawn('node', ['ai-responder.js'], {
      cwd: __dirname,
      stdio: 'inherit'
    });

    // 2. Jalankan Telegram Userbot
    const tgResponder = spawn('node', ['telegram-userbot.js'], {
      cwd: __dirname,
      stdio: 'inherit'
    });

    fbResponder.on('close', (code) => {
      log.warn(`Facebook Responder berhenti dengan kode: ${code}`);
    });

    tgResponder.on('close', (code) => {
      log.warn(`Telegram Userbot berhenti dengan kode: ${code}`);
    });

  } catch (error) {
    log.error('Gagal memulai sistem: ' + error.message);
  }
}

main();
