# 📊 Sistema de Presupuestos Inteligentes

Sistema completo de gestión de presupuestos con dos modos de creación: manual e inteligencia artificial.

---

## 🌟 Características Principales

### Modo 1: Presupuesto Manual
- Creación completa de presupuestos desde cero
- Validación automática de suma de categorías
- Detección de idioma (Español/Inglés)
- Clasificación automática del tipo de plan

### Modo 2: Presupuesto con IA
- Generación inteligente de categorías con Groq AI
- Sugerencias personalizadas según el plan
- Edición flexible antes de guardar
- Validación y corrección automática de montos
- Consejos generales del presupuesto

### Funcionalidades Adicionales
- ✅ Validación automática de suma exacta
- ✅ Detección de idioma automática (ES/EN)
- ✅ Clasificación de plan tipo (travel, event, party, purchase, project, other)
- ✅ CRUD completo de presupuestos
- ✅ Gestión individual de categorías
- ✅ Estados de presupuesto (draft, active, archived)
- ✅ Porcentajes calculados automáticamente

---

## 🏗️ Arquitectura del Sistema

### Modelos de Base de Datos

#### Budget
```php
- id
- user_id (foreign key)
- title (string)
- description (text, nullable)
- total_amount (decimal 12,2)
- mode (enum: manual, ai)
- language (string: es, en)
- plan_type (enum: travel, event, party, purchase, project, other)
- status (enum: draft, active, archived)
- created_at, updated_at
```

#### BudgetCategory
```php
- id
- budget_id (foreign key)
- name (string)
- amount (decimal 12,2)
- percentage (float)
- reason (text, nullable)
- order (integer)
- created_at, updated_at
```

### Servicios

#### BudgetAIService
- **detectLanguage(string $text): string** - Detecta idioma del texto
- **classifyPlanType(string $text): string** - Clasifica tipo de plan
- **generateBudgetWithAI(string $title, float $amount, string $description): array** - Genera presupuesto con IA
- **interpretVoiceCommand(string $text): array** - Interpreta comandos de voz

### Controladores

#### SmartBudgetController
- `getBudgets()` - Lista todos los presupuestos del usuario
- `getBudget(Budget $budget)` - Obtiene un presupuesto específico
- `createManualBudget()` - Crea presupuesto manual
- `generateAIBudget()` - Genera sugerencias con IA
- `saveAIBudget()` - Guarda presupuesto generado por IA
- `updateBudget()` - Actualiza presupuesto
- `deleteBudget()` - Elimina presupuesto
- `addCategory()` - Agrega categoría
- `updateCategory()` - Actualiza categoría
- `deleteCategory()` - Elimina categoría
- `validateBudget()` - Valida presupuesto
- `processVoiceCommand()` - Procesa comando de voz

---

## 📡 API Endpoints

### Listar Presupuestos
```http
GET /api/budgets
Authorization: Bearer {token}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Viaje a Perú",
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

### Crear Presupuesto Manual

**Paso 1: Crear**
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
      "reason": "Pasajes aéreos ida y vuelta"
    },
    {
      "name": "Alojamiento",
      "amount": 600,
      "reason": "3 noches en hotel"
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

**Validación:**
- Title: required, string, max 255
- Description: nullable, string, max 2000
- Total Amount: required, numeric, min 0.01
- Categories: required, array, min 1
- Category Name: required, string, max 255
- Category Amount: required, numeric, min 0.01

**Validación Automática:**
- La suma de todas las categorías DEBE ser exactamente igual al total_amount
- Tolerancia: ±0.01

**Response (201):**
```json
{
  "success": true,
  "message": "Budget created successfully",
  "data": {
    "id": 1,
    "title": "Viaje a Machu Picchu",
    "description": "Vacaciones de verano 2025",
    "total_amount": 2000.00,
    "mode": "manual",
    "language": "es",
    "plan_type": "travel",
    "status": "draft",
    "categories": [
      {
        "id": 1,
        "name": "Vuelos",
        "amount": 800.00,
        "percentage": 40.00,
        "reason": "Pasajes aéreos ida y vuelta",
        "order": 0
      }
    ]
  },
  "is_valid": true
}
```

### Generar Presupuesto con IA

**Paso 1: Generar sugerencias**
```http
POST /api/budgets/ai/generate
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Fiesta de cumpleaños",
  "description": "Cumpleaños con 50 personas en jardín",
  "total_amount": 1500
}
```

**Proceso Interno:**
1. Detecta idioma del título y descripción
2. Clasifica tipo de plan (travel, event, party, etc.)
3. Envía prompt a Groq AI
4. Recibe y valida JSON de categorías
5. Verifica que las categorías sumen exactamente el total
6. Ajusta proporcionalmente si hay diferencia
7. Calcula porcentajes

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
        "reason": "Alquiler de jardín"
      },
      {
        "name": "Comida",
        "amount": 700,
        "percentage": 46.67,
        "reason": "Catering para 50 personas"
      },
      {
        "name": "Decoración",
        "amount": 250,
        "percentage": 16.67,
        "reason": "Flores, globos y mesa"
      },
      {
        "name": "Entretenimiento",
        "amount": 150,
        "percentage": 10.0,
        "reason": "DJ y música"
      }
    ],
    "general_advice": "Reserva el lugar con 2 meses de anticipación para mejor precio.",
    "language": "es",
    "plan_type": "party"
  },
  "note": "These are AI suggestions. You can edit, add, or remove categories before saving."
}
```

**Paso 2: Guardar presupuesto (después de revisar)**
```http
POST /api/budgets/ai/save
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "title": "Fiesta de cumpleaños",
  "description": "Cumpleaños con 50 personas en jardín",
  "total_amount": 1500,
  "language": "es",
  "plan_type": "party",
  "categories": [
    {
      "name": "Lugar",
      "amount": 400,
      "reason": "Alquiler de jardín"
    },
    {
      "name": "Comida",
      "amount": 700,
      "reason": "Catering para 50 personas"
    },
    {
      "name": "Decoración",
      "amount": 250,
      "reason": "Flores, globos y mesa"
    },
    {
      "name": "Entretenimiento",
      "amount": 150,
      "reason": "DJ y música"
    }
  ]
}
```

---

## 🎯 Tipos de Plan

El sistema clasifica automáticamente los planes en 6 categorías:

| Tipo | Descripción | Palabras clave |
|------|-------------|----------------|
| **travel** | Viajes y vacaciones | viaje, travel, vacaciones, vacaci, trip |
| **event** | Eventos corporativos y sociales | evento, event, conferencia, meeting |
| **party** | Fiestas y celebraciones | fiesta, boda, party, wedding, cumpleaños |
| **purchase** | Compras grandes | compr, buy, laptop, car, coche |
| **project** | Proyectos de construcción/reforma | proyect, remodel, construcción, constru |
| **other** | Otros tipos de planes | Default |

---

## 🌍 Detección de Idioma

El sistema detecta automáticamente el idioma basándose en el texto:

### Español (es)
Se detecta si contiene palabras como:
- el, la, los, las, en, de, que, y, a

### Inglés (en)
Default si no se detectan palabras en español

---

## 🔢 Cálculo Automático de Porcentajes

Los porcentajes se calculan automáticamente:

```php
$percentage = round(($category_amount / $total_amount) * 100, 2);
```

**Ejemplo:**
- Total: 2000
- Categoría Vuelos: 800
- Porcentaje: (800 / 2000) * 100 = 40.00%

---

## ✅ Validaciones del Sistema

### 1. Validación de Suma
```php
if (abs($categoriesSum - $totalAmount) > 0.01) {
    throw new InvalidArgumentException("Sum of categories does not match total amount");
}
```

### 2. Validación de Monto por Categoría
```php
if ($currentSum + $newAmount > $budget->total_amount) {
    return response()->json([
        'error' => 'Amount exceeds budget'
    ], 422);
}
```

### 3. Validación de IA
El servicio de IA valida:
- JSON response es válido
- Existe el campo `categories`
- Array de categorías no está vacío
- Todos los montos son números positivos
- La suma es aproximadamente correcta (5% tolerancia)

---

## 🤖 IA: Configuración y Modelos

### Modelos Groq Utilizados (en orden de prioridad):
1. `llama-3.1-8b-instant`
2. `gemma2-9b-it`
3. `llama3-8b-8192`

### Prompt de IA (resumido):
```
Create a realistic budget for a "$plan_type" plan.

Rules:
- Create 3-7 relevant categories
- Each category must have: name, amount, reason
- Sum of all amounts MUST equal total exactly
- Return ONLY valid JSON, no extra text

Format:
{
  "categories": [
    {"name": "", "amount": 0, "reason": ""}
  ],
  "general_advice": ""
}
```

### Corrección Automática
Si la IA no suma exactamente el total:
1. Calcula factor de escala: `total / current_sum`
2. Escala todas las categorías proporcionalmente
3. Ajusta la última categoría para compensar errores de redondeo
4. Recalcula todos los porcentajes

---

## 🧪 Testing

### Ejecutar Tests
```bash
composer test
```

### Test Individual
```bash
php artisan test --filter test_create_manual_budget
```

### Tests Implementados
- `test_create_manual_budget` - Crear presupuesto manual válido
- `test_create_manual_budget_with_invalid_sum` - Error cuando la suma no coincide
- `test_generate_ai_budget_suggestions` - Generar sugerencias con IA
- `test_save_ai_budget` - Guardar presupuesto de IA
- `test_get_all_budgets` - Listar presupuestos
- `test_get_single_budget` - Obtener presupuesto específico
- `test_cannot_access_other_user_budget` - Seguridad: no acceder a otros usuarios
- `test_update_budget` - Actualizar presupuesto
- `test_delete_budget` - Eliminar presupuesto
- `test_add_category_to_budget` - Agregar categoría
- `test_cannot_add_category_exceeding_budget` - Error al exceder presupuesto
- `test_update_category` - Actualizar categoría
- `test_delete_category` - Eliminar categoría
- `test_validate_budget` - Validar presupuesto
- `test_language_detection_spanish` - Detección de español
- `test_language_detection_english` - Detección de inglés
- `test_plan_type_detection` - Clasificación de tipo de plan

---

## 📊 Ejemplo Completo de Flujo

### Flujo Manual:
1. Usuario define título, descripción y monto total
2. Usuario crea categorías manualmente con montos
3. Sistema valida que la suma sea exacta
4. Sistema detecta idioma y tipo de plan automáticamente
5. Sistema calcula porcentajes
6. Presupuesto se crea con status "draft"

### Flujo IA:
1. Usuario define título, descripción y monto total
2. Usuario solicita generar con IA
3. Sistema llama a Groq AI
4. IA devuelve categorías sugeridas
5. Usuario puede editar, agregar o eliminar categorías
6. Usuario confirma y guarda
7. Sistema valida suma exacta
8. Presupuesto se crea con mode "ai" y status "draft"

---

## 🔒 Seguridad

- Autenticación requerida para todos los endpoints
- Usuarios solo pueden acceder a sus propios presupuestos
- Validación estricta de entradas
- Rate limiting en endpoints de IA (10 req/min)
- Transacciones de base de datos para integridad de datos

---

## 📞 Soporte y Troubleshooting

Para problemas específicos del sistema de presupuestos, consulta:
- [API Documentation](./API.md) - Documentación completa de la API
- [Troubleshooting](./TROUBLESHOOTING.md) - Solución de problemas comunes
- [Flutter Integration](./FLUTTER_INTEGRATION.md) - Guía de integración con Flutter

---

**Última actualización:** Enero 2026
**Versión:** 2.0.0
**Estado:** ✅ Producción
