# 💰 Finance API

Sistema completo de finanzas personales con inteligencia artificial. API REST construida con Laravel 12 que incluye gestión de movimientos, presupuestos inteligentes, transacciones programadas y más.

---

## 🌟 Características Principales

### Core Features
- ✅ **Authentication** - Registro y login con Laravel Sanctum
- ✅ **Movements Management** - CRUD completo de movimientos financieros
- ✅ **Smart Budgets** - Sistema de presupuestos con dos modos:
  - 📝 **Manual**: Crea presupuestos desde cero
  - 🤖 **AI-Powered**: Genera categorías inteligentes con Groq AI
- ✅ **Scheduled Transactions** - Transacciones recurrentes con cálculo automático
- ✅ **Tags & Categories** - Organización flexible de gastos
- ✅ **Savings Analysis** - Análisis basado en regla 50/30/20
- ✅ **Tax Management** - Radar fiscal y gestión de impuestos
- ✅ **Voice Commands** - IA para procesar comandos de voz
- ✅ **Multi-language** - Detección automática de español e inglés

### AI Features
- 🧠 Generación inteligente de categorías de presupuesto
- 🌍 Detección automática de idioma (ES/EN)
- 📊 Clasificación automática de tipo de plan
- 🎯 Sugerencias personalizadas basadas en el contexto
- 💬 Procesamiento de voz para movimientos rápidos

---

## 🚀 Quick Start

### Prerequisites
- PHP 8.2+
- Composer
- Node.js & NPM
- MySQL 8+ / PostgreSQL 13+
- Groq API Key (gratuita en [groq.com](https://groq.com))

### Installation

```bash
# 1. Clone repository
git clone <repository-url>
cd Api_finanzas

# 2. Install dependencies
composer install
npm install

# 3. Setup environment
cp .env.example .env
php artisan key:generate

# 4. Configure database in .env
# DB_DATABASE=your_database
# DB_USERNAME=your_username
# DB_PASSWORD=your_password

# 5. Add Groq API key in .env
# GROQ_API_KEY=gsk_your_api_key

# 6. Run migrations
php artisan migrate

# 7. Start development server
composer dev
```

The API will be available at `http://localhost:8000`

For detailed installation instructions, see [docs/INSTALLATION.md](docs/INSTALLATION.md)

---

## 📡 API Endpoints

### Authentication
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | Register new user |
| POST | `/api/login` | Login user |

### Budget Management
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/budgets` | List all budgets |
| GET | `/api/budgets/{id}` | Get specific budget |
| POST | `/api/budgets/manual` | Create manual budget |
| POST | `/api/budgets/ai/generate` | Generate AI suggestions |
| POST | `/api/budgets/ai/save` | Save AI budget |
| PUT | `/api/budgets/{id}` | Update budget |
| DELETE | `/api/budgets/{id}` | Delete budget |
| POST | `/api/budgets/{id}/validate` | Validate budget |
| POST | `/api/budgets/{id}/categories` | Add category |
| PUT | `/api/budgets/{id}/categories/{cat_id}` | Update category |
| DELETE | `/api/budgets/{id}/categories/{cat_id}` | Delete category |

### Movements
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/movements` | List all movements |
| POST | `/api/movements` | Create movement |
| POST | `/api/movements/sugerir-voz` | Voice command (AI) |

### Tags
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/tags` | List tags |
| POST | `/api/tags/create` | Create tag |
| POST | `/api/tags/suggestion` | Get AI tag suggestions |

### Scheduled Transactions
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/scheduled-transactions` | List scheduled transactions |
| POST | `/api/scheduled-transactions` | Create scheduled transaction |
| GET | `/api/scheduled-transactions/{id}` | Get scheduled transaction |
| PUT | `/api/scheduled-transactions/{id}` | Update scheduled transaction |
| DELETE | `/api/scheduled-transactions/{id}` | Delete scheduled transaction |
| POST | `/api/scheduled-transactions/{id}/toggle-paid` | Toggle paid status |

### Analysis
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/savings/analyze` | Analyze savings (50/30/20) |
| GET | `/api/taxes/data` | Get tax data |
| GET | `/api/taxes/alerts` | Get tax alerts |
| GET | `/api/home-data` | Get dashboard data |

For complete API documentation with examples, see [docs/API.md](docs/API.md)

---

## 📚 Documentation

| Document | Description |
|----------|-------------|
| [API Documentation](docs/API.md) | Complete API reference with all endpoints |
| [Budget System](docs/BUDGET_SYSTEM.md) | Detailed budget system guide |
| [Installation](docs/INSTALLATION.md) | Installation and setup instructions |
| [Flutter Integration](docs/FLUTTER_INTEGRATION.md) | Flutter app integration guide |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Common issues and solutions |

---

## 🧪 Testing

### Run all tests
```bash
composer test
```

### Run specific test
```bash
php artisan test --filter test_create_manual_budget
```

### Run test suite
```bash
php artisan test --testsuite=Feature
```

### Code quality
```bash
# Format code with Laravel Pint
vendor/bin/pint

# Check code style without modifying
vendor/bin/pint --test
```

---

## 🏗️ Tech Stack

### Backend
- **Framework**: Laravel 12.0
- **PHP**: 8.2+
- **Database**: MySQL / PostgreSQL / SQLite
- **Authentication**: Laravel Sanctum
- **Queue**: Database Queue
- **Testing**: PHPUnit 11.5+
- **Code Style**: Laravel Pint 1.22.1

### AI Integration
- **Provider**: Groq AI
- **Models**: Llama 3.1, Gemma 2, Mixtral
- **Features**: Budget generation, voice commands, tag suggestions

### Frontend
- **Build Tool**: Vite 6.2+
- **CSS**: TailwindCSS 4.0
- **HTTP Client**: Axios 1.8+

---

## 📊 Budget System Modes

### Mode 1: Manual Budget
Users create budgets from scratch with full control:
1. Define title, description, and total amount
2. Create categories manually with amounts
3. System validates exact sum
4. Auto-detects language and plan type
5. Calculates percentages automatically

### Mode 2: AI-Powered Budget
AI generates intelligent budget suggestions:
1. User provides title, description, and amount
2. AI analyzes context and plan type
3. Generates 3-7 relevant categories
4. Provides amounts, reasons, and general advice
5. User can edit before saving
6. System ensures exact sum with auto-correction

### Plan Types
The system automatically classifies plans:
- 🌍 **Travel** - Viajes y vacaciones
- 🎉 **Event** - Eventos corporativos y sociales
- 🎂 **Party** - Fiestas y celebraciones
- 🛒 **Purchase** - Compras grandes
- 🔨 **Project** - Proyectos de construcción/reforma
- 📦 **Other** - Otros tipos de planes

---

## 🔐 Security

- ✅ Authentication with Laravel Sanctum
- ✅ Authorization checks on all endpoints
- ✅ Input validation and sanitization
- ✅ SQL injection protection (Eloquent ORM)
- ✅ XSS protection
- ✅ Rate limiting on sensitive endpoints
- ✅ CSRF protection
- ✅ CORS configuration

---

## 📈 Performance

- ✅ Database query optimization with eager loading
- ✅ Redis caching support
- ✅ Queue system for background jobs
- ✅ Response caching where appropriate
- ✅ Optimized database indexes

---

## 🤝 Contributing

1. Fork repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Run tests (`composer test`)
5. Format code (`vendor/bin/pint`)
6. Push to branch (`git push origin feature/AmazingFeature`)
7. Open a Pull Request

### Coding Standards
- Follow PSR-12 coding standards
- Use Laravel Pint for formatting
- Write tests for new features
- Add documentation for new endpoints

---

## 📄 License

This project is licensed under MIT License.

---

## 🆘 Support

For issues and questions:

1. Check [Troubleshooting Guide](docs/TROUBLESHOOTING.md)
2. Review [API Documentation](docs/API.md)
3. Check Laravel Documentation: https://laravel.com/docs
4. Review Groq API Documentation: https://console.groq.com/docs

---

## 🙏 Acknowledgments

- Laravel Framework
- Groq AI API
- Open Source Community

---

**Version**: 1.0.0
**Last Updated**: January 2026
**Status**: ✅ Production Ready
