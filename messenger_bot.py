import asyncio
import json
import time
import requests
from playwright.async_api import async_playwright

# --- CONFIGURATION ---
TARGET_URL = "https://www.messenger.com/e2ee/t/24172466432359980"
LARAVEL_API_URL = "http://localhost/AI-ramlan/public/api/test-chat" # Sesuaikan jika URL berbeda
COOKIES_FILE = "storage/cookies/cookies.json"

def load_cookies():
    try:
        with open(COOKIES_FILE, 'r') as f:
            cookies = json.load(f)
            # Bersihkan cookies agar sesuai standar Playwright
            cleaned_cookies = []
            for c in cookies:
                # Playwright hanya mau Strict, Lax, atau None
                if 'sameSite' in c:
                    val = str(c['sameSite']).lower()
                    if val == 'no_restriction':
                        c['sameSite'] = 'None'
                    else:
                        c['sameSite'] = val.capitalize()
                
                # Playwright pakai 'expires' (float), bukan 'expirationDate'
                if 'expirationDate' in c:
                    c['expires'] = float(c['expirationDate'])
                    del c['expirationDate']
                
                # Hapus field yang tidak didukung Playwright
                c.pop('hostOnly', None)
                c.pop('session', None)
                c.pop('storeId', None)
                
                cleaned_cookies.append(c)
            return cleaned_cookies
    except Exception as e:
        print(f"Error loading cookies file: {e}")
        return []

async def get_ai_reply(message):
    try:
        response = requests.post(LARAVEL_API_URL, json={
            "message": message,
            "sender_name": "User Messenger"
        })
        if response.status_code == 200:
            return response.json().get("ai_reply")
    except Exception as e:
        print(f"Error AI API: {e}")
    return None

async def run_bot():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False) # Headless=False biar Anda bisa lihat dia ngetik
        context = await browser.new_context()
        
        # Set Cookies agar otomatis Login
        cookies = load_cookies()
        if cookies:
            await context.add_cookies(cookies)
        else:
            print("Peringatan: Tidak ada cookies yang dimuat!")
        
        page = await context.new_page()
        print(f"Menuju TKP: {TARGET_URL}...")
        await page.goto(TARGET_URL)
        
        # Tunggu halaman E2EE terbuka
        await page.wait_for_timeout(5000)
        
        last_message = ""
        
        print("Robot RAMLAN Aktif & Mengawasi Chat... 🤖👀")
        
        while True:
            try:
                # Cari bubble chat terakhir (selector Messenger sering berubah, ini pendekatan umum)
                # Kita cari elemen teks terakhir yang bukan dikirim oleh kita
                messages = await page.query_selector_all('div[role="row"] span[dir="auto"]')
                if messages:
                    current_text = await messages[-1].inner_text()
                    
                    # Jika ada pesan baru dan bukan pesan yang sama dengan sebelumnya
                    if current_text != last_message and current_text.strip() != "":
                        print(f"Pesan Baru Terdeteksi: {current_text}")
                        last_message = current_text
                        
                        # Minta jawaban ke AI RAMLAN
                        ai_reply = await get_ai_reply(current_text)
                        
                        if ai_reply:
                            print(f"Membalas: {ai_reply}")
                            # Klik kotak pesan dan ketik
                            await page.fill('div[role="textbox"]', ai_reply)
                            await page.press('div[role="textbox"]', 'Enter')
                
                await asyncio.sleep(3) # Cek setiap 3 detik
                
            except Exception as e:
                print(f"Error dalam loop: {e}")
                await asyncio.sleep(5)

if __name__ == "__main__":
    asyncio.run(run_bot())
