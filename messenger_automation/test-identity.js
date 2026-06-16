import { chromium } from 'playwright';
import axios from 'axios';
import fs from 'fs';
import path from 'path';

// CONFIG
const API_URL = 'http://localhost:8000/api/ai/chat'; 
const CHROME_DATA_DIR = path.join(process.env.LOCALAPPDATA || '', 'Google/Chrome/User Data/Default'); 
const MESSENGER_URL = 'https://www.facebook.com/messages/t/';

const log = {
    info: (msg) => console.log(`[\x1b[34mINFO\x1b[0m] ${msg}`),
    success: (msg) => console.log(`[\x1b[32mSUCCESS\x1b[0m] ${msg}`),
    error: (msg) => console.log(`[\x1b[31mERROR\x1b[0m] ${msg}`),
    result: (msg) => console.log(`\x1b[36m${msg}\x1b[0m`)
};

async function testIdentity() {
    log.info("Memulai Audit Identitas RAMLAN AI (Top 5 Messenger)...");
    
    const browser = await chromium.launchPersistentContext('', {
        headless: false, // Set false agar Anda bisa melihat prosesnya (atau pastikan sudah login)
        viewport: { width: 1280, height: 720 }
    });

    const page = await browser.newPage();
    await page.goto(MESSENGER_URL);

    log.info("Menunggu halaman Messenger dimuat...");
    await page.waitForTimeout(5000);

    // Ambil daftar chat di sidebar
    // Selector ini menargetkan item chat di list kiri
    const chatItems = await page.locator('div[role="gridcell"]').all();
    const limit = Math.min(chatItems.length, 5);

    log.info(`Ditemukan ${chatItems.length} percakapan. Melakukan audit pada Top ${limit}...`);
    console.log("\n" + "=".repeat(80));
    console.log(`${"NAMA".padEnd(25)} | ${"RELATION".padEnd(15)} | ${"CONF".padEnd(5)} | ${"STATUS".padEnd(10)} | ${"ID/URL"}`);
    console.log("-".repeat(80));

    for (let i = 0; i < limit; i++) {
        try {
            // Klik chat item
            await chatItems[i].click();
            await page.waitForTimeout(2000);

            // 1. Ekstrak Nama
            let contactName = "Unknown";
            const headerNameEl = page.locator('[role="main"] [role="heading"]').first();
            if (await headerNameEl.isVisible()) {
                contactName = (await headerNameEl.innerText()).split('\n')[0].trim();
            }

            // 2. Ekstrak Profile URL / ID
            let profileUrl = null;
            const profileSelectors = [
                'a[href*="facebook.com/messages/t/"]',
                'a[href*="facebook.com/"]:has-text("Profile")',
                'a[href*="facebook.com/"]:has-text("Profil")',
                'div[role="main"] a[role="link"]'
            ];

            for (const sel of profileSelectors) {
                const links = await page.locator(sel).all();
                for (const link of links) {
                    const href = await link.getAttribute('href');
                    if (href && (href.includes('facebook.com/') || href.includes('/messages/t/'))) {
                        const cleanUrl = href.split('?')[0].split('&')[0];
                        if (cleanUrl.length > 20) {
                            profileUrl = cleanUrl;
                            break;
                        }
                    }
                }
                if (profileUrl) break;
            }

            // Fallback ID dari URL jika tidak ketemu link profil
            if (!profileUrl) {
                const currentUrl = page.url();
                if (currentUrl.includes('/messages/t/')) {
                    profileUrl = currentUrl.split('/messages/t/')[1].split('/')[0];
                }
            }

            // 3. Kirim ke Backend untuk Analisa Tanpa Simpan/Balas (Dry Run)
            // Catatan: Saya akan asumsikan backend akan memproses ini secara normal tapi kita hanya ambil datanya
            const response = await axios.post(API_URL, {
                phone: profileUrl || contactName.replace(/\s+/g, '_'),
                sender_name: contactName,
                fb_profile_url: profileUrl,
                message: "AUDIT_TEST_PING", // Pesan trigger khusus
                context: []
            });

            const contact = response.data.contact;
            
            // Format Hasil
            const rel = contact.relation_type.toUpperCase();
            const conf = contact.confidence_score || 0;
            const status = contact.is_verified ? "VERIFIED" : "UNVERIFIED";
            const idDisplay = (profileUrl || "No ID").substring(0, 30);

            console.log(`${contactName.padEnd(25)} | ${rel.padEnd(15)} | ${conf.toString().padEnd(5)} | ${status.padEnd(10)} | ${idDisplay}`);

        } catch (e) {
            log.error(`Gagal audit item ke-${i+1}: ${e.message}`);
        }
    }

    console.log("=".repeat(80) + "\n");
    log.success("Audit selesai.");
    
    // Jangan tutup browser agar user bisa lihat hasil (opsional)
    // await browser.close();
}

testIdentity().catch(e => log.error(e.message));
