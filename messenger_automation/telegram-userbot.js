import { TelegramClient, Api } from "telegram";
import { StringSession } from "telegram/sessions/index.js";
import { NewMessage } from "telegram/events/index.js";
import input from "input";
import axios from "axios";
import dotenv from "dotenv";
import path from "path";
import chalk from "chalk";
import fs from "fs-extra";

// Load environment variables
dotenv.config({ path: path.join(process.cwd(), '..', '.env') });

const apiId = parseInt(process.env.TELEGRAM_API_ID);
const apiHash = process.env.TELEGRAM_API_HASH;
const SESSION_FILE = path.join(process.cwd(), "telegram_session.txt");
const AI_API_URL = "https://chasing-jalapeno-discolor.ngrok-free.dev/api/generate-prompt";

const log = {
    info: (msg) => console.log(chalk.blue('ℹ ') + msg),
    success: (msg) => console.log(chalk.green('✔ ') + msg),
    error: (msg) => console.log(chalk.red('✘ ') + msg),
    warn: (msg) => console.log(chalk.yellow('⚠ ') + msg),
    step: (msg) => console.log(chalk.cyan('➜ ') + msg),
};

async function main() {
    console.clear();
    console.log(chalk.cyan('========================================'));
    console.log(chalk.bold.white('      RAMLAN TELEGRAM USERBOT          '));
    console.log(chalk.cyan('========================================'));

    if (!apiId || !apiHash) {
        log.error("API_ID atau API_HASH tidak ditemukan di .env!");
        process.exit(1);
    }

    let savedSession = "";
    if (fs.existsSync(SESSION_FILE)) {
        savedSession = fs.readFileSync(SESSION_FILE, "utf-8");
        log.info("Sesi tersimpan ditemukan. Menghubungkan...");
    }

    const client = new TelegramClient(new StringSession(savedSession), apiId, apiHash, {
        connectionRetries: 5,
    });

    await client.start({
        phoneNumber: async () => await input.text("Masukkan nomor HP (format: +628...): "),
        password: async () => await input.text("Masukkan password 2FA (jika ada): "),
        phoneCode: async () => await input.text("Masukkan kode verifikasi dari Telegram: "),
        onError: (err) => log.error(err.message),
    });

    log.success("Berhasil terhubung ke Telegram!");
    
    // Simpan sesi agar tidak perlu login ulang
    const currentSession = client.session.save();
    fs.writeFileSync(SESSION_FILE, currentSession);
    log.info("Sesi disimpan untuk penggunaan berikutnya.");

    // Dapatkan info diri sendiri
    const me = await client.getMe();
    log.success(`Aktif sebagai: ${me.firstName} ${me.lastName || ''} (@${me.username || 'no_username'})`);

    // --- FUNGSI UTAMA PROSES PESAN ---
    async function processMessage(message) {
        // 1. Abaikan jika bukan chat pribadi (PM)
        if (!message.isPrivate) return;

        // 2. Abaikan pesan dari diri sendiri
        if (message.out) return;

        const sender = await message.getSender();
        const senderName = (sender?.firstName || '') + ' ' + (sender?.lastName || '');
        const text = message.text;
        const chatId = message.peerId.userId.toString();

        if (!text) return;

        log.info(`Pesan terdeteksi dari ${senderName}: "${text.substring(0, 50)}${text.length > 50 ? '...' : ''}"`);

        // --- PROSES VIA AI SERVICE ---
        try {
            // Tampilkan status "Typing..."
            await client.invoke(new Api.messages.SetTyping({
                peer: message.peerId,
                action: new Api.SendMessageTypingAction(),
            }));

            const stableId = 'telegram_' + chatId;
            const profileUrl = sender?.username ? `https://t.me/${sender.username}` : `https://t.me/user_${chatId}`;

            log.info(`Mengeksekusi otak RAMLAN untuk ${senderName}... ⏳`);
            
            // Panggil endpoint Laravel test-chat
            const chatResult = await axios.post(AI_API_URL.replace('generate-prompt', 'test-chat'), {
                phone: stableId,
                message: text,
                sender_name: senderName,
                fb_profile_url: profileUrl
            }, { timeout: 60000 });

            const aiReply = chatResult.data?.ai_reply;

            if (aiReply) {
                const typingDelay = Math.min(Math.max(aiReply.length * 50, 3000), 10000);
                await new Promise(r => setTimeout(r, typingDelay));

                await client.sendMessage(message.peerId, { message: aiReply });
                log.success(`Terbalas ke ${senderName}!`);
                
                // Tandai sudah dibaca agar tidak diproses lagi saat restart
                await client.invoke(new Api.messages.ReadHistory({
                    peer: message.peerId,
                    maxId: message.id
                }));
            }
        } catch (error) {
            log.error(`Gagal membalas ke ${senderName}: ${error.message}`);
        }
    }

    // --- STARTUP SCAN: Cek pesan unread ---
    log.info("Memeriksa pesan yang belum dibaca...");
    const dialogs = await client.getDialogs({ limit: 15 });
    for (const dialog of dialogs) {
        if (dialog.unreadCount > 0 && dialog.isUser) {
            log.info(`Ditemukan ${dialog.unreadCount} pesan baru dari ${dialog.title}.`);
            const messages = await client.getMessages(dialog.entity, { limit: 1 });
            if (messages.length > 0) {
                await processMessage(messages[0]);
            }
        }
    }

    // --- EVENT LISTENER: Pesan Baru ---
    client.addEventHandler(async (event) => {
        await processMessage(event.message);
    }, new NewMessage({}));

    log.step("RAMLAN Telegram Userbot Standby... (Menunggu chat pribadi)");
}

main().catch(err => {
    log.error("CRITICAL ERROR: " + err.message);
    process.exit(1);
});
