# 🚀 Installation Guide

Complete guide to set up and run the Finance API project.

---

## 📋 Prerequisites

Before you begin, ensure you have:

- **PHP 8.2+**
- **Composer** (latest version)
- **Node.js & NPM** (latest LTS)
- **MySQL 8+** or **PostgreSQL 13+** (for production)
- **Git**
- **Groq API Key** (for AI features) - Get free at [groq.com](https://groq.com)

---

## 📦 Quick Installation (5 minutes)

### 1. Clone Repository
```bash
git clone <repository-url>
cd Api_finanzas
```

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Install JavaScript Dependencies
```bash
npm install
```

### 4. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 5. Configure Database
Edit `.env` file:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=finance_api
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 6. Run Migrations
```bash
php artisan migrate
```

### 7. Configure Groq AI Key
Edit `.env` file:
```env
GROQ_API_KEY=gsk_your_groq_api_key_here
```

Get your free API key at [groq.com](https://groq.com).

### 8. Start Development Server
```bash
composer dev
```

This starts:
- Laravel development server (port 8000)
- Queue worker
- Vite frontend dev server

---

## 🔧 Detailed Installation

### Step 1: System Requirements

#### macOS
```bash
# Install Homebrew if not installed
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install PHP
brew install php

# Install Composer
brew install composer

# Install Node.js
brew install node

# Install MySQL
brew install mysql
brew services start mysql
```

#### Ubuntu/Debian
```bash
# Update packages
sudo apt update

# Install PHP and extensions
sudo apt install php8.2 php8.2-cli php8.2-fpm php8.2-xml php8.2-mysql php8.2-mbstring php8.2-curl php8.2-zip

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt install -y nodejs

# Install MySQL
sudo apt install mysql-server
sudo systemctl start mysql
```

#### Windows
- Download and install [XAMPP](https://www.apachefriends.org/) (includes PHP, MySQL, Apache)
- Download and install [Node.js](https://nodejs.org/)
- Download and install [Composer](https://getcomposer.org/)

### Step 2: Clone and Install Dependencies

```bash
# Clone repository
git clone <repository-url>
cd Api_finanzas

# Install PHP dependencies
composer install --optimize-autoloader --no-dev

# Install development dependencies
composer install

# Install NPM dependencies
npm install
```

### Step 3: Environment Configuration

#### Copy Environment File
```bash
cp .env.example .env
```

#### Generate Application Key
```bash
php artisan key:generate
```

#### Configure `.env`

**Database Configuration:**
```env
APP_NAME="Finance API"
APP_ENV=local
APP_KEY=base64:your-generated-key
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=finance_api
DB_USERNAME=root
DB_PASSWORD=your_mysql_password
```

**AI Configuration:**
```env
# Groq AI API
GROQ_API_KEY=gsk_your_actual_api_key_here
```

**Queue Configuration:**
```env
QUEUE_CONNECTION=database
```

**Cache Configuration:**
```env
CACHE_DRIVER=file
SESSION_DRIVER=file
```

### Step 4: Database Setup

#### Create Database (MySQL)
```sql
CREATE DATABASE finance_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'finance_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON finance_api.* TO 'finance_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Create Database (PostgreSQL)
```sql
CREATE DATABASE finance_api;
CREATE USER finance_user WITH PASSWORD 'secure_password';
GRANT ALL PRIVILEGES ON DATABASE finance_api TO finance_user;
```

#### Run Migrations
```bash
php artisan migrate
```

**Optional: Seed Database**
```bash
php artisan db:seed
```

### Step 5: Verify Groq API Key

#### Test Groq API Connection
```bash
curl -H "Authorization: Bearer gsk_YOUR_API_KEY" \
  https://api.groq.com/openai/v1/models
```

Should return a list of available models.

#### Verify Key in `.env`
```bash
grep "GROQ_API_KEY" .env
```

Should show: `GROQ_API_KEY=gsk_...`

### Step 6: Configure Queues (Optional)

If using database queues:
```bash
php artisan queue:table
php artisan migrate
php artisan queue:work
```

### Step 7: Build Assets (Production)
```bash
npm run build
```

---

## 🚀 Running the Application

### Development Mode

#### Option 1: Full Stack (Recommended)
```bash
composer dev
```

This runs:
- Laravel server (http://localhost:8000)
- Queue worker
- Vite dev server

#### Option 2: Individual Services
```bash
# Terminal 1 - Laravel server
php artisan serve

# Terminal 2 - Queue worker
php artisan queue:listen

# Terminal 3 - Vite dev server
npm run dev
```

### Production Mode

#### Optimize Application
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

#### Build Assets
```bash
npm run build
```

#### Run with Supervisor (Queue Workers)

Create `/etc/supervisor/conf.d/finance-api.conf`:
```ini
[program:finance-api-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/Api_finanzas/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/finance-api-queue.log
```

Start supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start finance-api-queue:*
```

---

## 🧪 Testing

### Run All Tests
```bash
composer test
```

### Run Specific Test
```bash
php artisan test --filter test_create_manual_budget
```

### Run Test Suite
```bash
php artisan test --testsuite=Feature
```

### Run with Coverage (requires Xdebug)
```bash
php artisan test --coverage
```

### Test Database Configuration

Tests use SQLite in-memory (configured in `phpunit.xml`):

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
</php>
```

---

## 🔍 Troubleshooting

### Issue: Composer install fails
**Solution:**
```bash
composer clear-cache
composer install --no-interaction
```

### Issue: NPM install fails
**Solution:**
```bash
rm -rf node_modules package-lock.json
npm install
```

### Issue: Database connection error
**Solution:**
1. Verify `.env` database credentials
2. Ensure MySQL/PostgreSQL is running
3. Check database exists
4. Test connection:
```bash
php artisan tinker
>>> DB::connection()->getPdo();
```

### Issue: Groq API errors
**Solution:**
1. Verify API key is correct
2. Check API key has not expired
3. Test API connection:
```bash
curl -H "Authorization: Bearer YOUR_KEY" https://api.groq.com/openai/v1/models
```

### Issue: Permissions error on storage/logs
**Solution:**
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Issue: Queue jobs not processing
**Solution:**
```bash
# Check if queue worker is running
ps aux | grep queue

# Restart queue worker
php artisan queue:restart
```

---

## 📁 Project Structure

```
Api_finanzas/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Models/
│   └── Services/
├── config/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── docs/
│   ├── API.md
│   ├── BUDGET_SYSTEM.md
│   ├── FLUTTER_INTEGRATION.md
│   ├── INSTALLATION.md
│   └── TROUBLESHOOTING.md
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
├── routes/
│   ├── api.php
│   ├── web.php
│   └── console.php
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── vendor/
├── .env
├── .env.example
├── artisan
├── composer.json
├── package.json
└── vite.config.js
```

---

## 🔐 Security Checklist

### For Development
- [ ] Change default `APP_KEY` (already done with `php artisan key:generate`)
- [ ] Set `APP_DEBUG=false` in production
- [ ] Use strong database passwords
- [ ] Keep `.env` file out of version control
- [ ] Validate all user inputs

### For Production
- [ ] Use HTTPS
- [ ] Set up SSL certificates
- [ ] Configure firewall rules
- [ ] Set up automated backups
- [ ] Enable 2FA for admin accounts
- [ ] Use environment-specific secrets
- [ ] Implement rate limiting
- [ ] Set up monitoring and alerting
- [ ] Regular security updates

---

## 📚 Next Steps

After successful installation:

1. **Read the Documentation:**
   - [API Documentation](./API.md) - Complete API reference
   - [Budget System Guide](./BUDGET_SYSTEM.md) - Budget system details
   - [Flutter Integration](./FLUTTER_INTEGRATION.md) - Mobile app integration

2. **Test the API:**
   - Register a user
   - Create a budget manually
   - Try AI budget generation
   - Explore all endpoints

3. **Start Development:**
   - Create new features
   - Write tests
   - Follow coding standards (see AGENTS.md)

4. **Deploy to Production:**
   - Set up production server
   - Configure environment
   - Optimize application
   - Set up monitoring

---

## 🆘 Support

If you encounter issues not covered here:

1. Check [Troubleshooting](./TROUBLESHOOTING.md)
2. Review Laravel Documentation: https://laravel.com/docs
3. Check Groq API Documentation: https://console.groq.com/docs
4. Review logs: `tail -f storage/logs/laravel.log`

---

**Version:** 1.0.0
**Last Updated:** January 2026
**Laravel Version:** 12.0
**PHP Version:** 8.2+
