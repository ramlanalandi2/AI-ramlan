import { OpenRouter } from "@openrouter/sdk";
import axios from 'axios';
import dotenv from 'dotenv';
import path from 'path';
import chalk from 'chalk';

// Load .env
dotenv.config({ path: path.join(process.cwd(), '..', '.env') });

const openrouter = new OpenRouter({
    apiKey: process.env.OPENROUTER_API_KEY
});

const API_URL = "https://chasing-jalapeno-discolor.ngrok-free.dev/api/generate-prompt";

async function testHybridSystem() {
    console.log(chalk.blue("--- TESTING HYBRID AI SYSTEM (Laravel Context + OpenRouter SDK) ---"));
    
    const testData = {
        phone: "628123456789",
        message: "Halo Lan, lagi apa nih? Udah makan belum?",
        sender_name: "Budi",
        fb_profile_url: null
    };

    try {
        // 1. Ambil Prompt dari Laravel
        console.log(chalk.cyan("\n1. Mengambil Konteks & Prompt dari Laravel... ⏳"));
        const promptResponse = await axios.post(API_URL, testData).catch(e => {
            console.error(chalk.red("Laravel API Error: " + e.message));
            console.log(chalk.yellow("Pastikan server php artisan serve atau XAMPP menyala di http://localhost"));
            return null;
        });

        if (!promptResponse || !promptResponse.data?.success) {
            console.error(chalk.red("Gagal mendapatkan data dari Laravel."));
            return;
        }

        const { system_prompt, messages, conversation_id } = promptResponse.data.data;
        console.log(chalk.green("Prompt Berhasil Dirakit!"));
        console.log(chalk.gray("System Prompt Length: " + system_prompt.length));

        // 2. Panggil OpenRouter SDK
        const DEFAULT_MODEL = process.env.OPENROUTER_MODEL || "openai/gpt-oss-120b:free";
        console.log(chalk.cyan(`\n2. Mengeksekusi via OpenRouter SDK (${DEFAULT_MODEL})... 🚀`));
        
        const allMessages = [
            { role: "system", content: system_prompt },
            ...messages.map(m => ({ role: m.role, content: m.content })),
            { role: "user", content: testData.message }
        ];

        let response;
        try {
            response = await openrouter.chat.send({
                chatRequest: {
                    model: DEFAULT_MODEL,
                    messages: allMessages,
                }
            });
        } catch (e) {
            console.log(chalk.yellow("Primary model failed. Trying fallback..."));
            response = await openrouter.chat.send({
                chatRequest: {
                    model: "nvidia/nemotron-3-super-120b-a12b:free",
                    messages: allMessages,
                }
            });
        }

        const reply = response.choices[0]?.message?.content;

        if (reply) {
            console.log(chalk.green("\n--- RESPON FINAL RAMLAN AI ---"));
            console.log(chalk.white(reply));
            console.log(chalk.green("------------------------------"));
            
            // 3. Test Save Reply
            console.log(chalk.cyan("\n3. Mencoba Sinkronisasi Riwayat ke DB... ⏳"));
            await axios.post(API_URL.replace('generate-prompt', 'save-reply'), {
                conversation_id,
                reply: reply,
                model: "nvidia/nemotron-3-super-120b-a12b:free"
            });
            console.log(chalk.green("Sinkronisasi Berhasil!"));
        } else {
            console.error(chalk.red("\nAI tidak memberikan balasan."));
        }

    } catch (error) {
        console.error(chalk.red("\nERROR: " + error.message));
        if (error.response) {
            console.log(JSON.stringify(error.response.data, null, 2));
        }
    }
}

testHybridSystem();
