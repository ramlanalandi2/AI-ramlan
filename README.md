# AI Ramlan

Laravel-based web application with additional automation scripts for AI-assisted workflows.

## Tech Stack

- PHP / Laravel
- Composer
- Node.js / Vite
- Python automation scripts

## Local Setup

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
npm run dev
php artisan serve
```

## Notes

- Keep `.env` private and do not commit credentials.
- Use `composer install` and `npm install` after cloning the repository.
- Runtime/cache folders and dependencies should be ignored by Git.

