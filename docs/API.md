# 📚 API Documentation

Finance API REST - Sistema completo de finanzas personales con inteligencia artificial.

---

## 📑 Table of Contents

- [Authentication](#-authentication)
- [Budget Management](#-budget-management)
- [Movements](#-movements)
- [Tags](#-tags)
- [Scheduled Transactions](#-scheduled-transactions)
- [Savings Analysis](#-savings-analysis)
- [Tax Management](#-tax-management)
- [Home Data](#-home-data)

---

## 🔐 Authentication

All endpoints except login and register require a Bearer token.

### Register
```http
POST /api/register
Content-Type: application/json
```

**Body:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    },
    "token": "2|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  }
}
```

### Login
```http
POST /api/login
Content-Type: application/json
```

**Body:**
```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "token": "2|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    }
  }
}
```

---

## 📊 Budget Management

Dual-mode budget system: Manual creation or AI-powered suggestions.

### Get All Budgets
```http
GET /api/budgets
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Viaje a Perú",
      "description": "Vacaciones 2025",
      "total_amount": 2000.00,
      "mode": "manual",
      "language": "es",
      "plan_type": "travel",
      "status": "active",
      "categories": [...]
    }
  ]
}
```

### Get Single Budget
```http
GET /api/budgets/{id}
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Viaje a Perú",
    "total_amount": 2000.00,
    "mode": "ai",
    "categories": [...]
  },
  "is_valid": true,
  "categories_total": 2000.00
}
```

### Create Manual Budget
```http
POST /api/budgets/manual
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Viaje a Machu Picchu",
  "description": "Vacaciones de verano 2025",
  "total_amount": 2000,
  "categories": [
    {
      "name": "Vuelos",
      "amount": 800,
      "reason": "Pasajes aéreos"
    },
    {
      "name": "Alojamiento",
      "amount": 600,
      "reason": "Hotel 4 estrellas"
    },
    {
      "name": "Comida",
      "amount": 400,
      "reason": "Restaurantes"
    },
    {
      "name": "Actividades",
      "amount": 200,
      "reason": "Tours"
    }
  ]
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "Budget created successfully",
  "data": {
    "id": 1,
    "title": "Viaje a Machu Picchu",
    "total_amount": 2000.00,
    "mode": "manual",
    "language": "es",
    "plan_type": "travel",
    "status": "draft",
    "categories": [...]
  },
  "is_valid": true
}
```

### Generate AI Budget Suggestions
```http
POST /api/budgets/ai/generate
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Fiesta de cumpleaños",
  "description": "Cumpleaños con 50 personas",
  "total_amount": 1500
}
```

**Response (200):**
```json
{
  "success": true,
  "message": "AI suggestions generated. Review and save as budget.",
  "data": {
    "title": "Fiesta de cumpleaños",
    "total_amount": 1500,
    "categories": [
      {
        "name": "Lugar",
        "amount": 400,
        "percentage": 26.67,
        "reason": "Salón"
      },
      {
        "name": "Comida",
        "amount": 700,
        "percentage": 46.67,
        "reason": "Catering"
      },
      {
        "name": "Decoración",
        "amount": 250,
        "percentage": 16.67,
        "reason": "Flores y globos"
      },
      {
        "name": "Entretenimiento",
        "amount": 150,
        "percentage": 10.0,
        "reason": "DJ"
      }
    ],
    "general_advice": "Reserva el lugar con 2 meses de anticipación.",
    "language": "es",
    "plan_type": "party"
  },
  "note": "These are AI suggestions. You can edit, add, or remove categories before saving."
}
```

### Save AI Budget
```http
POST /api/budgets/ai/save
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Fiesta de cumpleaños",
  "description": "Cumpleaños con 50 personas",
  "total_amount": 1500,
  "language": "es",
  "plan_type": "party",
  "categories": [
    {
      "name": "Lugar",
      "amount": 400,
      "reason": "Salón"
    },
    {
      "name": "Comida",
      "amount": 700,
      "reason": "Catering"
    },
    {
      "name": "Decoración",
      "amount": 250,
      "reason": "Flores y globos"
    },
    {
      "name": "Entretenimiento",
      "amount": 150,
      "reason": "DJ"
    }
  ]
}
```

**Response (201):**
```json
{
  "success": true,
  "message": "AI budget saved successfully",
  "data": {
    "id": 1,
    "title": "Fiesta de cumpleaños",
    "total_amount": 1500.00,
    "mode": "ai",
    "language": "es",
    "plan_type": "party",
    "status": "draft",
    "categories": [...]
  },
  "is_valid": true
}
```

### Update Budget
```http
PUT /api/budgets/{id}
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Updated Title",
  "description": "Updated description",
  "total_amount": 1500,
  "categories": [
    {
      "id": 1,
      "name": "Lugar",
      "amount": 400
    }
  ]
}
```

### Delete Budget
```http
DELETE /api/budgets/{id}
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "message": "Budget deleted"
}
```

### Add Category to Budget
```http
POST /api/budgets/{id}/categories
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "name": "New Category",
  "amount": 300,
  "reason": "Test category"
}
```

### Update Category
```http
PUT /api/budgets/{budget_id}/categories/{category_id}
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "name": "Updated Category",
  "amount": 600
}
```

### Delete Category
```http
DELETE /api/budgets/{budget_id}/categories/{category_id}
Authorization: Bearer {token}
```

### Validate Budget
```http
POST /api/budgets/{id}/validate
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "is_valid": true,
  "categories_total": 2000.00,
  "total_amount": 2000.00,
  "difference": 0.00,
  "message": "Budget is valid"
}
```

---

## 💰 Movements

### Get All Movements
```http
GET /api/movements
Authorization: Bearer {token}
```

### Create Movement
```http
POST /api/movements
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "amount": 100.50,
  "type": "expense",
  "category": "Food",
  "description": "Lunch at restaurant"
}
```

### Voice Command (AI)
```http
POST /api/movements/sugerir-voz
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "text": "gasté 50 dólares en comida ayer"
}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "type": "expense",
    "amount": 50.00,
    "category": "Food",
    "description": "Gasté en comida",
    "payment_method": "cash"
  }
}
```

---

## 🏷️ Tags

### Get Tags
```http
GET /api/tags
Authorization: Bearer {token}
```

### Create Tag
```http
POST /api/tags/create
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "name": "Vacation",
  "color": "#FF5733"
}
```

### Get AI Tag Suggestions
```http
POST /api/tags/suggestion
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "text": "compré pasajes para viajar a la playa"
}
```

---

## 📅 Scheduled Transactions

Scheduled transactions API using Laravel API Resource conventions.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/scheduled-transactions` | List all scheduled transactions |
| POST | `/api/scheduled-transactions` | Create new scheduled transaction |
| GET | `/api/scheduled-transactions/{id}` | Get specific scheduled transaction |
| PUT | `/api/scheduled-transactions/{id}` | Update scheduled transaction |
| DELETE | `/api/scheduled-transactions/{id}` | Delete scheduled transaction |
| POST | `/api/scheduled-transactions/{id}/toggle-paid` | Toggle paid status |

---

## 📈 Savings Analysis

### Analyze Savings (50/30/20 Rule)
```http
GET /api/savings/analyze
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "total_income": 5000.00,
    "needs": 2500.00,
    "wants": 1500.00,
    "savings": 1000.00,
    "actual_needs": 2400.00,
    "actual_wants": 1600.00,
    "actual_savings": 1000.00,
    "score": 85
  }
}
```

---

## 🧾 Tax Management

### Get Tax Data
```http
GET /api/taxes/data
Authorization: Bearer {token}
```

### Get Tax Alerts (Semaforo Fiscal)
```http
GET /api/taxes/alerts
Authorization: Bearer {token}
```

---

## 🏠 Home Data

### Get Dashboard Data
```http
GET /api/home-data
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "success": true,
  "data": {
    "user": {...},
    "recent_movements": [...],
    "total_income": 5000.00,
    "total_expenses": 3200.00,
    "balance": 1800.00
  }
}
```

---

## 📝 Error Responses

All error responses follow this format:

```json
{
  "error": "Error type",
  "message": "Detailed error message"
}
```

Or for validation errors:

```json
{
  "error": "Validation failed",
  "messages": {
    "title": ["The title field is required."],
    "total_amount": ["The total amount must be at least 0.01."]
  }
}
```

### Common HTTP Status Codes

| Code | Description |
|------|-------------|
| 200 | Success |
| 201 | Created |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 422 | Validation Error |
| 500 | Internal Server Error |

---

## 🔄 Rate Limiting

Some endpoints have rate limiting:
- Login/Register: 10 requests per minute
- AI Generation: 10 requests per minute

---

## 📚 Additional Documentation

- [Budget System Guide](./BUDGET_SYSTEM.md) - Complete budget system documentation
- [Flutter Integration](./FLUTTER_INTEGRATION.md) - Flutter app integration guide
- [Troubleshooting](./TROUBLESHOOTING.md) - Common issues and solutions
- [Installation](./INSTALLATION.md) - Installation and setup instructions

---

**Version:** 1.0.0
**Last Updated:** January 2026
