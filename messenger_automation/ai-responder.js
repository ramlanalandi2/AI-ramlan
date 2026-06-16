import { chromium } from 'playwright';
import { CONFIG } from './config.js';
import { log, loadCookies, humanDelay, verifyFacebookLogin } from './utils.js';
import axios from 'axios';
import path from 'path';
import crypto from 'crypto';
import dotenv from 'dotenv';
import { OpenRouter } from "@openrouter/sdk";

// Load environment variables from the root .env file
dotenv.config({ path: path.join(process.cwd(), '..', '.env') });

const openrouter = new OpenRouter({
    apiKey: process.env.OPENROUTER_API_KEY
});

const DEFAULT_MODEL = process.env.OPENROUTER_MODEL || "openai/gpt-oss-120b:free";
const FALLBACK_MODELS = [
    "nvidia/nemotron-3-super-120b-a12b:free",
    "google/gemini-2.0-flash-001",
    "mistralai/mistral-7b-instruct:free",
    "microsoft/phi-3-mini-128k-instruct:free"
];

// --- CONFIG ---
const TARGET_URL = "https://www.facebook.com/messages/t/";
const HOME_URL = "https://www.facebook.com/messages/e2ee/t/24172466432359980";
const AI_API_URL = "https://chasing-jalapeno-discolor.ngrok-free.dev/api/test-chat";
const COOKIE_FILE_PATH = path.join(process.cwd(), '..', 'storage', 'cookies', 'cookies.json');

const BLACKLIST = [
    'privacy & support', 'media & files', 'customise chat', 'customize chat',
    'chat info', 'compose', 'write to', 'help', 'settings', 'search',
    'notifications', 'mute', 'block', 'report', 'end-to-end encrypted',
    'ramlan', 'ramlan al', 'sent', 'seen', 'delivered', 'active now',
    'announcement', 'restoring messages', 'media, files and links',
    'profile', 'view profile', 'write to...', 'message...',
    'unread', 'approved your post', 'you can now post', 'joined the group',
    'changed the group', 'set the nickname', 'started a video chat',
    'to your message', 'responded to', 'reacted to'
];

async function isValidMessage(bubble) {
    try {
        const txt = (await bubble.innerText()).trim();
        if (!txt || txt.length < 2) return false;

        const lower = txt.toLowerCase();

        // Cek Blacklist
        if (BLACKLIST.some(b => lower.includes(b))) return false;

        // Cek apakah ini hanya jam (misal 10:54 AM)
        if (/^\d{1,2}:\d{2}\s?(am|pm)?$/i.test(lower)) return false;

        // Cek pola notifikasi FB (biasanya tidak ada di dalam bubble chat asli)
        if (lower.includes('admin approved') || lower.includes('you can now')) return false;
        if (lower.includes('reacted to') || lower.includes('menanggapi')) return false;
        if (lower.includes('tanggapan terhadap')) return false;

        const isHeader = await bubble.evaluate(el => !!el.closest('h1, h2, h3, [role="heading"]'));
        if (isHeader) return false;

        return true;
    } catch (e) { return false; }
}

function cleanContactName(raw) {
    if (!raw) return "Unknown";
    
    // Ambil baris pertama saja (biasanya Nama)
    let name = raw.split('\n')[0].trim();
    
    // Bersihkan karakter aneh dan prefix umum
    name = name
        .replace(/\bYou:\s.*$/i, '')
        .replace(/\bAnda:\s.*$/i, '')
        .replace(/·.*$/i, '')
        .replace(/\d+:\d+\s*(AM|PM)/i, '')
        .replace(/^\s*[\u2022\u00b7]\s*/, '') // Hapus bullet/dot di awal
        .trim();
    
    // Jika mengandung kata-kata teknis Messenger, anggap Unknown
    const technicalTerms = ['messenger', 'chats', 'obrolan', 'unread', 'belum dibaca', 'active now', 'aktif sekarang', 'facebook', 'meta'];
    if (technicalTerms.includes(name.toLowerCase())) return "Unknown";

    return name || "Unknown";
}

function hashMessage(text) {
    if (!text) return "";
    const normalized = text.normalize('NFKC').toLowerCase().replace(/\s+/g, ' ').trim();
    return crypto.createHash('md5').update(normalized).digest('hex');
}

async function safeEvaluate(locator, fn, fallback = false) {
    try {
        return await locator.evaluate(fn);
    } catch {
        return fallback;
    }
}

const replyCooldowns = new Map(); // [threadId] => timestamp
const watchdogMap = new Map();    // [threadId] => { count, lastReset }

async function getAiReply(message, contactName, profileUrl = null, context = []) {
    const maxRetries = 3;
    let currentModel = DEFAULT_MODEL;
    let attempt = 0;

    while (attempt < maxRetries) {
        attempt++;
        try {
            log.info(`Menanyakan ke RAMLAN AI (Hybrid Mode)... ⏳ (Percobaan ${attempt})`);

            const stableId = profileUrl || contactName.replace(/\s+/g, '_');

            // 1. Ambil Prompt yang sudah dirakit dari Laravel
            const promptEndpoint = AI_API_URL.replace('test-chat', 'generate-prompt');
            const promptResponse = await axios.post(promptEndpoint, {
                phone: stableId,
                message,
                sender_name: contactName,
                fb_profile_url: profileUrl,
                context
            }, {
                timeout: 60000,
                validateStatus: () => true
            });

            if (promptResponse.status !== 200 || !promptResponse.data?.success) {
                log.error(`Gagal ambil prompt dari Laravel (Status: ${promptResponse.status})`);
                return null;
            }

            const { system_prompt, messages, conversation_id } = promptResponse.data.data;

            // 2. Eksekusi via OpenRouter SDK
            log.info(`Memanggil OpenRouter SDK: ${currentModel} 🚀`);

            const allMessages = [
                { role: "system", content: system_prompt },
                ...messages.map(m => ({ role: m.role, content: m.content })),
                { role: "user", content: message }
            ];

            const response = await openrouter.chat.send({
                chatRequest: {
                    model: currentModel,
                    messages: allMessages,
                    temperature: 0.85,
                },
                stream: false
            });

            let aiReply = response.choices[0]?.message?.content;

            if (aiReply) {
                log.success(`Respon diterima dari OpenRouter SDK (${currentModel}).`);

                // Hapus Markdown (Bintang) yang mungkin masih lolos
                aiReply = aiReply.replace(/\*\*/g, '').replace(/\*/g, '');

                // 3. Simpan balasan
                const saveEndpoint = AI_API_URL.replace('test-chat', 'save-reply');
                await axios.post(saveEndpoint, {
                    conversation_id,
                    reply: aiReply,
                    model: currentModel
                }).catch(e => log.error(`Gagal simpan history: ${e.message}`));

                return aiReply;
            }

            log.warn(`AI memberikan balasan kosong menggunakan model ${currentModel}.`);
        } catch (error) {
            log.error(`Attempt ${attempt} failed with model ${currentModel}: ${error.message}`);
            
            // Jika error adalah JSON parse error, kemungkinan model/API sedang bermasalah
            if (error.message.includes("Unexpected end of JSON input") || error.message.includes("JSON")) {
                log.warn("Terdeteksi error parsing JSON. Mencoba model fallback...");
                currentModel = FALLBACK_MODELS[attempt % FALLBACK_MODELS.length];
            } else if (attempt < maxRetries) {
                log.info("Menunggu 5 detik sebelum mencoba lagi...");
                await new Promise(r => setTimeout(r, 5000));
            }
        }
    }

    log.error(`Gagal mendapatkan balasan AI setelah ${maxRetries} percobaan.`);
    return null;
}

async function startAiResponder() {
    log.step("Starting RAMLAN AI Responder (Identity Aware + Cool Mode)...");

    const browser = await chromium.launch({ headless: true });
    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    const context = await browser.newContext({ 
        viewport: { width: 1280, height: 720 },
        userAgent: userAgent
    });
    const cookies = await loadCookies(COOKIE_FILE_PATH);
    if (cookies.length > 0) await context.addCookies(cookies);

    const page = await context.newPage();
    page.setDefaultNavigationTimeout(60000); // 60s for navigations
    page.setDefaultTimeout(30000);           // 30s for general actions

    // VERIFIKASI LOGIN
    log.info("Verifikasi sesi Facebook...");
    await page.goto("https://www.facebook.com/", { waitUntil: 'domcontentloaded' });
    const isLoggedIn = await verifyFacebookLogin(page);
    
    if (!isLoggedIn) {
        log.error("Sesi Facebook tidak valid atau expired!");
        log.warn("Pastikan file cookies.json berisi data terbaru dan Anda sudah login di browser.");
        const failPath = path.join(process.cwd(), 'login_failed.png');
        await page.screenshot({ path: failPath });
        log.info(`Screenshot kegagalan login disimpan di: ${failPath}`);
        await browser.close();
        process.exit(1);
    }
    log.success("Sesi Facebook Terverifikasi! ✅");

    // Inject detectIsFromMe ke browser context agar bisa dipanggil berkali-kali
    await page.addInitScript(() => {
        window.detectIsFromMe = function (el) {
            const style = window.getComputedStyle(el);
            const bgColor = style.backgroundColor;

            // Sinyal 1: Warna (Blueish - Fallback jika tidak ada tema)
            const isBlueish = bgColor.includes('rgb(0, 132, 255)') || bgColor.includes('rgb(45, 136, 255)') || bgColor.includes('rgb(0, 153, 255)');

            // Sinyal 2: Posisi (Flex-end & Sisi Kanan) - SANGAT STABIL
            const isFlexEnd = !!el.closest('div[style*="align-items: flex-end"], div[style*="align-items:flex-end"], div[style*="justify-content: flex-end"]');
            const rect = el.getBoundingClientRect();
            const isRightSide = rect.left > (window.innerWidth * 0.45);

            // Sinyal 3: Struktur CSS Messenger
            const isSelfStructure = !!el.closest('.x1n2on86, .x10l6tqk, .xieb3on, .x1gnnqk1');

            // Sinyal 4: Absensi Avatar (Pesan kita tidak punya avatar di barisnya)
            const row = el.closest('[role="row"], .x1n2on86');
            const hasNoAvatar = row ? !row.querySelector('img') : true;

            // Sinyal 5: Nama Pengirim (Jika ada elemen nama di atas bubble)
            const senderNameEl = row ? row.querySelector('span[dir="auto"], div[dir="auto"]') : null;
            const isMeByName = senderNameEl ? (senderNameEl.innerText.toLowerCase().includes('ramlan') || senderNameEl.innerText.toLowerCase() === 'you' || senderNameEl.innerText.toLowerCase() === 'anda') : false;

            const score =
                (isFlexEnd ? 3 : 0) +
                (isRightSide ? 3 : 0) + // Tingkatkan bobot sisi kanan
                (isSelfStructure ? 2 : 0) +
                (isBlueish ? 1 : 0) +
                (hasNoAvatar ? 1 : 0) +
                (isMeByName ? 2 : 0);

            return score >= 4;
        };
    });

    try {
        log.info("Navigating to Home URL...");
        await page.goto(HOME_URL, { waitUntil: 'load', timeout: 90000 });
        log.info("Page loaded. Waiting for stability...");
        await page.waitForTimeout(5000); // Tunggu ekstra agar UI render sempurna
    } catch (e) {
        log.warn(`Navigation to HOME_URL timed out or failed: ${e.message}. Attempting to proceed anyway...`);
    }

    try {
        await page.waitForLoadState('networkidle', { timeout: 15000 });
    } catch (e) {
        log.info("Initial page ready (timeout idle).");
    }

    // PIN E2EE
    try {
        const pinInput = page.locator('#mw-numeric-code-input-prevent-composer-focus-steal');
        if (await pinInput.isVisible({ timeout: 10000 })) {
            log.info("E2EE PIN detected. Entering code...");
            await pinInput.click();
            await page.keyboard.type("181292", { delay: 150 });
            log.success("PIN entered.");
            try {
                await page.waitForLoadState('networkidle', { timeout: 10000 });
            } catch (e) {
                log.info("E2EE Pin ready (timeout idle).");
            }
        }
    } catch (e) { 
        log.warn("PIN check skipped or failed: " + e.message);
    }

    let lastSeenMessageHash = "";
    let currentContact = "";
    let currentProfileUrl = null;
    let shouldReplyNext = false;
    const bubbleSelector = [
        'div[dir="auto"]', 
        'span[dir="auto"]', 
        '[role="row"] [dir="auto"]', 
        '[data-testid="message_container"] [dir="auto"]',
        '.x0n86y2 .x1n2on86', // E2EE bubbles
        '[role="log"] [dir="auto"]'
    ].join(', ');

    log.success("Robot Identity-Aware + Cool Mode sudah standby! 😎🔵");
    
    try {
        const title = await page.title();
        log.info(`Current Page Title: ${title}`);
    } catch (e) {}

    // --- INITIAL STATE SYNC ---
    try {
        // Tunggu bubble muncul (Max 15 detik)
        await page.waitForSelector(bubbleSelector, { timeout: 15000 }).catch(() => {});
        
        const initialBubbles = await page.locator(bubbleSelector).all();
        if (initialBubbles.length > 0) {
            const lastOne = initialBubbles[initialBubbles.length - 1];
            const txt = (await lastOne.innerText()).trim();
            lastSeenMessageHash = hashMessage(txt);
            log.info(`Konteks awal tersinkronisasi. (${initialBubbles.length} pesan terdeteksi)`);
        } else {
            log.warn("Tidak ada pesan terdeteksi pada sinkronisasi awal. Mungkin halaman masih kosong atau selector salah.");
            // Ambil screenshot untuk debug
            const screenshotPath = path.join(process.cwd(), 'debug_startup.png');
            await page.screenshot({ path: screenshotPath });
            log.info(`Screenshot debug disimpan di: ${screenshotPath}`);
        }
    } catch (e) {
        log.warn("Gagal sinkronisasi konteks awal: " + e.message);
    }

    let loopCount = 0;
    let scanResult = { count: 0, totalRows: 0 };

    while (true) {
        loopCount++;
        try {
            // 1. CEK SIDEBAR UNREAD (Batch Scan Validation)
            scanResult = await page.evaluate(() => {
                const selectors = [
                    '[role="row"]', 
                    '[role="gridcell"]', 
                    'a[href*="/messages/"]', // Broaden link selector
                    '[data-testid="mwthreadlist_item"]',
                    'div[data-testid="thread_list_item"]',
                    '[role="navigation"] a',
                    'div[style*="height: 72px"]', // Common Messenger row height
                    'div[style*="height: 64px"]'
                ];
                
                let rows = [];
                selectors.forEach(sel => {
                    const found = Array.from(document.querySelectorAll(sel));
                    rows = rows.concat(found);
                });
                
                // Deduplicate rows by comparing their text and position
                const uniqueRows = [];
                const seen = new Set();
                for (const row of rows) {
                    const text = row.innerText.substring(0, 50);
                    const rect = row.getBoundingClientRect();
                    const key = `${text}-${Math.round(rect.top)}`;
                    if (!seen.has(key)) {
                        seen.add(key);
                        uniqueRows.push(row);
                    }
                }

                let unreadCount = 0;
                let firstRow = null;

                for (const row of uniqueRows) {
                    const rowText = row.innerText.toLowerCase();
                    // Abaikan jika pesan terakhir dari kita (Anda/You)
                    if (rowText.includes('you:') || rowText.includes('anda:') || rowText.includes('you ·') || rowText.includes('anda ·')) continue;

                    // Sinyal Unread: Aria Label, Titik Biru, atau Bold Text
                    const hasUnreadSignal = !!row.querySelector('[aria-label*="unread" i], [aria-label*="belum dibaca" i]') ||
                        Array.from(row.querySelectorAll('div, span, svg')).some(el => {
                            const style = window.getComputedStyle(el);
                            const bg = style.backgroundColor;
                            const fill = style.fill;
                            const fontWeight = style.fontWeight;
                            
                            const isBlueish = (color) => {
                                const m = color.match(/\d+/g);
                                if (!m || m.length < 3) return false;
                                const r = parseInt(m[0]), g = parseInt(m[1]), b = parseInt(m[2]);
                                return b > r && b > g && b > 150;
                            };

                            const hasNoText = (el.innerText || el.textContent || "").trim().length === 0;
                            const isSmall = el.offsetWidth >= 6 && el.offsetWidth <= 25; 
                            const isBlue = isBlueish(bg) || isBlueish(fill);
                            
                            return isBlue && hasNoText && isSmall;
                        }) ||
                        // Cek apakah ada span dengan font-weight bold (seringkali nama di unread chat jadi bold)
                        Array.from(row.querySelectorAll('span')).some(s => {
                            const fw = window.getComputedStyle(s).fontWeight;
                            return parseInt(fw) >= 700 && s.innerText.length > 2;
                        });

                    if (hasUnreadSignal) {
                        unreadCount++;
                        if (!firstRow) firstRow = row;
                    }
                }
                return { count: unreadCount, found: !!firstRow, totalRows: uniqueRows.length };
            });

            if (scanResult.count > 0) {
                log.info(`[VALIDASI] Terdeteksi ${scanResult.count} pesan unread total dari ${scanResult.totalRows} baris.`);

                // Cari ulang row pertama untuk diklik
                let unreadChat = await page.evaluateHandle(() => {
                    const rows = Array.from(document.querySelectorAll('[role="row"], [role="gridcell"], a[href*="/messages/t/"], a[href*="/messages/e2ee/t/"], [data-testid="mwthreadlist_item"]'));
                    for (const row of rows) {
                        const rowText = row.innerText.toLowerCase();
                        if (rowText.includes('you:') || rowText.includes('anda:') || rowText.includes('you ·') || rowText.includes('anda ·')) continue;

                        const hasUnreadSignal = !!row.querySelector('[aria-label*="unread" i], [aria-label*="belum dibaca" i]') ||
                            Array.from(row.querySelectorAll('div, span, svg')).some(el => {
                                const style = window.getComputedStyle(el);
                                const bg = style.backgroundColor;
                                const fill = style.fill;
                                const isBlueish = (color) => {
                                    const m = color.match(/\d+/g);
                                    if (!m || m.length < 3) return false;
                                    const r = parseInt(m[0]), g = parseInt(m[1]), b = parseInt(m[2]);
                                    return b > r && b > g && b > 150;
                                };
                                const hasNoText = (el.innerText || el.textContent || "").trim().length === 0;
                                const isSmall = el.offsetWidth >= 5 && el.offsetWidth <= 20;
                                const isBlue = isBlueish(bg) || isBlueish(fill);
                                return isBlue && hasNoText && isSmall;
                            });

                        if (hasUnreadSignal) return row;
                    }
                    return null;
                }).then(handle => handle.asElement());

                if (unreadChat) {
                    // --- JUAL MAHAL DELAY (5-15 Detik) ---
                    const delay = Math.floor(Math.random() * (12000 - 5000 + 1)) + 5000;
                    log.info(`Pesan Baru Terdeteksi! Jual mahal dulu selama ${Math.round(delay / 1000)} detik... 😎`);
                    await page.waitForTimeout(delay);

                    log.info("Oke, sekarang baru kita buka chat-nya...");
                    try {
                        await unreadChat.click({ force: true, timeout: 5000 });
                    } catch (e) {
                        log.warn(`Click standar gagal (${e.message}), mencoba via DOM...`);
                        await page.evaluate(el => el.click(), unreadChat);
                    }
                    shouldReplyNext = true;
                    
                    // --- SYNC HASH AWAL ---
                    await page.waitForTimeout(2000);
                    const initialBubbles = await page.locator(bubbleSelector).all();
                    if (initialBubbles.length > 0) {
                        const lastOne = initialBubbles[initialBubbles.length - 1];
                        const txt = (await lastOne.innerText()).trim();
                        lastSeenMessageHash = hashMessage(txt);
                    }

                    try {
                        await page.waitForLoadState('networkidle', { timeout: 5000 });
                    } catch (e) { }
                }
            }

            // 2. DETEKSI IDENTITAS (ADVANCED SIDEBAR & URL MATCHER)
            let contactName = "Unknown";
            let profileUrl = null;
            let activeThreadId = null;

            try {
                // 2.1 Ambil Thread ID dari URL Browser
                const currentUrl = page.url();
                const match = currentUrl.match(/\/messages\/(?:e2ee\/|archived\/)?t\/([^\/?#]+)/i);
                activeThreadId = match ? match[1] : null;

                if (activeThreadId) {
                    // 2.2 Cari Nama dari Sidebar (Link Matcher) - LEBIH AKURAT
                    const sidebarInfo = await page.evaluate((threadId) => {
                        const links = Array.from(document.querySelectorAll('a[href*="/messages/"]'));
                        // Cari link yang mengandung threadId
                        const target = links.find(a => a.href.includes(threadId));
                        if (!target) return null;

                        // Ambil semua span di dalam link tersebut
                        const spans = Array.from(target.querySelectorAll('span'));
                        // Nama biasanya ada di span pertama yang punya teks cukup panjang
                        // Dan seringkali punya font-weight bold jika unread, atau normal jika read
                        const nameSpan = spans.find(s => s.innerText.trim().length > 2 && !s.innerText.includes(':'));
                        
                        return {
                            rawText: target.innerText,
                            spanText: nameSpan ? nameSpan.innerText : null
                        };
                    }, activeThreadId);

                    if (sidebarInfo) {
                        contactName = cleanContactName(sidebarInfo.spanText || sidebarInfo.rawText);
                    }

                    // 2.3 Set Profile URL menggunakan ID Stabil
                    profileUrl = `https://www.facebook.com/messages/t/${activeThreadId}/`;
                }

                // 2.4 FALLBACK 1: Header (Selector yang lebih kuat)
                if (contactName === "Unknown" || contactName === "Messenger") {
                    const headerSelectors = [
                        '[role="main"] [role="heading"] h1',
                        '[role="main"] h1',
                        'div[role="main"] h2 span',
                        'div[role="main"] div[role="button"] span[dir="auto"]',
                        'div[role="main"] span[role="link"]',
                        '[role="main"] [role="heading"]',
                        '#seo_h1_tag' // Terkadang FB punya ini
                    ];

                    for (const sel of headerSelectors) {
                        const el = page.locator(sel).first();
                        if (await el.isVisible({ timeout: 1000 })) {
                            const txt = (await el.innerText()).split('\n')[0].trim();
                            if (txt && txt.length > 2 && !['messenger', 'chats', 'obrolan', 'facebook', 'meta'].includes(txt.toLowerCase())) {
                                contactName = txt;
                                break;
                            }
                        }
                    }
                }

                // 2.5 FALLBACK 2: Page Title
                if (contactName === "Unknown" || contactName === "Messenger" || contactName === "Facebook") {
                    const title = await page.title();
                    // Title biasanya formatnya: "Nama Orang | Messenger" atau "(1) Nama Orang | Messenger"
                    let cleanTitle = title.replace(/^\(\d+\)\s+/, '').replace(/\s*\|\s*Messenger.*$/i, '').replace(/\s*\|\s*Facebook.*$/i, '').trim();
                    if (cleanTitle && cleanTitle.length > 2 && !['messenger', 'chats', 'obrolan', 'facebook', 'meta'].includes(cleanTitle.toLowerCase())) {
                        contactName = cleanTitle;
                    }
                }
            } catch (e) { log.error("Extraction Error: " + e.message); }

            if (contactName !== currentContact || (profileUrl !== currentProfileUrl && profileUrl)) {
                log.info(`Identitas Aktif: ${contactName} | ID: ${profileUrl || 'Unknown'}`);
                currentContact = contactName;
                currentProfileUrl = profileUrl;
                lastSeenMessageHash = "";
            }

            // 3. PROSES CHAT
            const chatBubbles = await page.locator(bubbleSelector).all();
            if (chatBubbles.length > 0) {
                let lastBubble = null;
                for (let i = chatBubbles.length - 1; i >= 0; i--) {
                    if (await isValidMessage(chatBubbles[i])) {
                        lastBubble = chatBubbles[i];
                        break;
                    }
                }

                if (lastBubble) {
                    const currentText = (await lastBubble.innerText()).trim();
                    const currentHash = hashMessage(currentText);
                    const isFromMe = await safeEvaluate(lastBubble, el => window.detectIsFromMe(el), true);

                    // LOGIC: Cegah balas diri sendiri
                    if (isFromMe) {
                        if (currentHash !== lastSeenMessageHash) {
                            log.info(`Pesan terkirim/terdeteksi dari Ramlan. Sinkronisasi hash.`);
                            lastSeenMessageHash = currentHash;
                        }
                        shouldReplyNext = false; // Reset jika kita yang terakhir ngomong
                        continue;
                    }

                    const isNewLiveMessage = !isFromMe && currentHash !== lastSeenMessageHash && lastSeenMessageHash !== "" && currentText.length > 0;

                    if (shouldReplyNext || isNewLiveMessage) {

                        // --- PRODUCTION HARDENING: WATCHDOG & COOLDOWN ---
                        const threadId = profileUrl || contactName;
                        const now = Date.now();

                        // 1. Cooldown Check (Minimal 15 detik per reply ke thread yang sama)
                        const lastReplyAt = replyCooldowns.get(threadId) || 0;
                        if (now - lastReplyAt < 15000) {
                            log.info(`[Cooldown] Skip chat ${contactName} untuk menghindari spam.`);
                            continue;
                        }

                        // 2. Watchdog Anti-Loop (Circuit Breaker)
                        let watchdog = watchdogMap.get(threadId) || { count: 0, lastReset: now };
                        if (now - watchdog.lastReset > 180000) { // Reset setiap 3 menit
                            watchdog = { count: 0, lastReset: now };
                        }

                        if (watchdog.count >= 20) {
                            log.error(`[WATCHDOG] Limit 20 pesan tercapai untuk ${contactName}. Menunggu reset cooldown...`);
                            continue;
                        }

                        // --- PROSES BALASAN ---
                        const jualMahalDelay = Math.floor(Math.random() * (10000 - 3000 + 1)) + 3000;
                        log.info(`Menunggu ${Math.round(jualMahalDelay / 1000)} detik...`);
                        await page.waitForTimeout(jualMahalDelay);

                        // Ambil Konteks
                        let contextMessages = [];
                        try {
                            const allBubbles = await page.locator(bubbleSelector).all();
                            const lastFive = allBubbles.slice(-5);
                            for (const b of lastFive) {
                                const text = (await b.innerText()).trim();
                                if (text && text.length > 1 && !BLACKLIST.some(bl => text.toLowerCase().includes(bl))) {
                                    const isBubbleMe = await safeEvaluate(b, el => window.detectIsFromMe(el), false);
                                    contextMessages.push({ role: isBubbleMe ? 'assistant' : 'user', text: text });
                                }
                            }
                        } catch (e) { }

                        const aiReply = await getAiReply(currentText, contactName, profileUrl, contextMessages);
                        if (!aiReply) {
                            log.warn(`[AI NULL] Skip dulu thread ${contactName}, pasang cooldown agar tidak spam API.`);
                            replyCooldowns.set(threadId, Date.now());
                            lastSeenMessageHash = currentHash;
                            // Tetap TRUE agar bot tidak lupa membalas thread ini di loop berikutnya
                            shouldReplyNext = true; 

                            watchdog.count++;
                            watchdogMap.set(threadId, watchdog);

                            continue;
                        }

                        if (aiReply) {
                            // Selector Kotak Chat yang lebih kuat (Anti-Log Container)
                            const inputSelectors = [
                                'div[role="textbox"][contenteditable="true"]',
                                'div[aria-label="Message"]',
                                'div[aria-label="Tulis pesan"]',
                                'div[aria-label="Tulis pesan..."]',
                                'div[aria-placeholder="Aa"]',
                                '[contenteditable="true"]'
                            ];
                            
                            let input = null;
                            for (const sel of inputSelectors) {
                                const found = page.locator(sel).first();
                                // Pastikan bukan elemen role="log" (container pesan)
                                const isLog = await safeEvaluate(found, el => el.getAttribute('role') === 'log', false);
                                if (await found.isVisible() && !isLog) {
                                    input = found;
                                    break;
                                }
                            }

                            if (input) {
                                log.info(`Mengetik balasan ke ${contactName}...`);
                                await input.click({ force: true });
                                await page.waitForTimeout(500);
                                
                                // Gunakan Type agar event React terpicu sempurna
                                // Bersihkan dulu jika ada sisa (jarang terjadi tapi aman)
                                await page.keyboard.down('Control');
                                await page.keyboard.press('a');
                                await page.keyboard.up('Control');
                                await page.keyboard.press('Backspace');
                                
                                await page.keyboard.type(aiReply, { delay: 20 });
                                await page.waitForTimeout(1000);
                                await page.keyboard.press('Enter');

                                // --- FALLBACK: Klik tombol "Send" jika Enter tidak mempan ---
                                try {
                                    const sendButtonSelectors = [
                                        'div[aria-label="Send"]',
                                        'div[aria-label="Kirim"]',
                                        'div[aria-label="Kirim pesan"]',
                                        'svg[aria-label="Send"]',
                                        'path[d*="M16.691 19.685C17.476"]' // Path icon send messenger
                                    ];
                                    
                                    for (const btnSel of sendButtonSelectors) {
                                        const btn = page.locator(btnSel).first();
                                        if (await btn.isVisible({ timeout: 1000 })) {
                                            log.info("Mengklik tombol Send manual...");
                                            await btn.click();
                                            break;
                                        }
                                    }
                                } catch (e) {}

                                // VERIFIKASI: Tunggu pesan muncul di bubble
                                await page.waitForTimeout(2000);
                                
                                // UPDATE STATE SETELAH BERHASIL
                                watchdog.count++;
                                watchdogMap.set(threadId, watchdog);

                                lastSeenMessageHash = hashMessage(aiReply);
                                replyCooldowns.set(threadId, Date.now()); // Set cooldown
                                shouldReplyNext = false;
                                log.success(`Terbalas!`);

                                // --- SCREENSHOT BERHASIL (Untuk Debug) ---
                                const successPath = path.join(process.cwd(), `sent_${activeThreadId || 'unknown'}.png`);
                                await page.screenshot({ path: successPath }).catch(() => {});

                                // --- KEMBALI KE HOME (DEFAULT VIEW) ---
                                if (page.url() !== HOME_URL) {
                                    log.info("Kembali ke Home Thread...");
                                    try {
                                        await page.goto(HOME_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
                                    } catch (e) {
                                        log.warn(`Return to Home timed out: ${e.message}`);
                                    }
                                }
                                
                                try {
                                    await page.waitForLoadState('networkidle', { timeout: 5000 });
                                } catch (e) {
                                    log.info("Page settled (timeout idle). Proceeding...");
                                }
                            } else {
                                log.error(`Tidak dapat menemukan input chat untuk ${contactName}!`);
                                // Ambil screenshot kegagalan input
                                const errorPath = path.join(process.cwd(), `error_input_${Date.now()}.png`);
                                await page.screenshot({ path: errorPath });
                            }
                        }
                    }

                    // --- BUG FIX: Hanya update hash jika pesan dari kita atau sudah dibalas ---
                    if (isFromMe) {
                        lastSeenMessageHash = currentHash;
                    }
                } else {
                    // Jika tidak ada bubble valid (misal cuma gambar/stiker), reset flag agar tidak nyangkut
                    if (shouldReplyNext) {
                        log.info(`Pesan di ${contactName} bukan teks valid (mungkin gambar/stiker). Skip balasan.`);
                        shouldReplyNext = false;
                    }
                }
            }
        } catch (e) { log.error("Loop Error: " + e.message); }
        
        if (loopCount % 15 === 0) {
            log.info(`[HEARTBEAT] RAMLAN standby. Mengawasi ${scanResult?.totalRows || 0} percakapan...`);
            if (scanResult?.totalRows === 0) {
                const diagPath = path.join(process.cwd(), `diag_heartbeat_${Date.now()}.png`);
                await page.screenshot({ path: diagPath }).catch(() => {});
                log.info(`[DIAGNOSTIC] Row tidak ditemukan. Screenshot disimpan: ${diagPath}`);
            }
        }
        
        await page.waitForTimeout(4000);
    }
}

startAiResponder().catch(err => {
    log.error("CRITICAL ERROR: " + err.message);
    console.error(err);
    process.exit(1);
});
