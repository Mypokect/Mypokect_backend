# 📊 Sistema de Presupuestos Dual (Manual + IA)

## Resumen

Sistema completo de gestión de presupuestos con **dos modos**:
- **MODO 1 (MANUAL)**: El usuario crea su presupuesto desde cero. Puede agregar, editar y eliminar categorías libremente.
- **MODO 2 (IA)**: La IA analiza el contexto del plan y sugiere categorías inteligentes. **Las sugerencias SIEMPRE son editables por el usuario.**

## Características

✅ Detección automática de idioma (Español/Inglés)  
✅ Clasificación automática del tipo de plan (viaje, evento, fiesta, compra, proyecto, otro)  
✅ Validación automática: la suma de categorías debe ser exactamente igual al total  
✅ Porcentajes automáticos por categoría  
✅ Gestión completa de categorías (agregar, editar, eliminar)  
✅ Historial de presupuestos (draft, active, archived)  
✅ Integración con Groq AI (4 modelos con fallback)  

---

## Endpoints Disponibles

### 1. Obtener todos los presupuestos del usuario
```http
GET /api/budgets?status=active
```

**Query Parameters:**
- `status`: `draft` | `active` | `archived` (por defecto: `active`)

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "user_id": 5,
        "title": "Viaje a Machu Picchu",
        "description": "Vacaciones 2025",
        "total_amount": 2000,
        "mode": "ai",
        "language": "es",
        "plan_type": "travel",
        "status": "active",
        "categories": [
          {
            "id": 1,
            "budget_id": 1,
            "name": "Vuelos",
            "amount": 800,
            "percentage": 40,
            "reason": "Pasajes aéreos ida y vuelta",
            "order": 0
          }
        ],
        "created_at": "2025-12-12T10:00:00Z"
      }
    ],
    "total": 15,
    "per_page": 10
  }
}
```

---

### 2. Obtener un presupuesto específico
```http
GET /api/budgets/{budget_id}
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 5,
    "title": "Viaje a Machu Picchu",
    "total_amount": 2000,
    "mode": "ai",
    "categories": [
      {
        "id": 1,
        "name": "Vuelos",
        "amount": 800,
        "percentage": 40,
        "reason": "Pasajes aéreos"
      }
    ]
  },
  "is_valid": true,
  "categories_total": 2000
}
```

---

### 3. MODO 1: Crear presupuesto MANUAL
```http
POST /api/budgets/manual
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
      "reason": "3 noches en hotel 4 estrellas"
    },
    {
      "name": "Comida",
      "amount": 400,
      "reason": "Desayunos y cenas incluidas"
    },
    {
      "name": "Actividades",
      "amount": 200,
      "reason": "Entrada a Machu Picchu y guía"
    }
  ]
}
```

**Validaciones:**
- ✅ La suma de todas las categorías DEBE ser exactamente igual a `total_amount`
- ✅ Cada categoría requiere `name` y `amount`
- ✅ El campo `reason` es opcional

**Error si no suma:**
```json
{
  "error": "Invalid budget",
  "message": "Sum of categories ($2100) does not match total amount ($2000)",
  "categories_sum": 2100,
  "total_amount": 2000
}
```

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "Budget created successfully",
  "data": {
    "id": 1,
    "user_id": 5,
    "title": "Viaje a Machu Picchu",
    "total_amount": 2000,
    "mode": "manual",
    "language": "es",
    "plan_type": "travel",
    "status": "draft",
    "categories": [
      {
        "id": 1,
        "name": "Vuelos",
        "amount": 800,
        "percentage": 40
      }
    ]
  },
  "is_valid": true
}
```

---

### 4. MODO 2 - Paso 1: Generar sugerencias de IA
```http
POST /api/budgets/ai/generate
```

**Body:**
```json
{
  "title": "Fiesta de cumpleaños de María",
  "description": "Cumpleaños número 30 de mi hermana. Queremos una fiesta con 50 personas en casa, con comida, decoración y entretenimiento.",
  "total_amount": 1500
}
```

**Comportamiento:**
- 🤖 La IA **detecta automáticamente** que el idioma es Spanish
- 🤖 La IA **clasifica automáticamente** el plan como `party`
- 🤖 La IA **sugiere categorías específicas** para una fiesta (lugar/logística, comida, decoración, entretenimiento, imprevistos)
- 📝 Las sugerencias son **100% editables** - el usuario puede agregar, modificar o eliminar cualquier categoría

**Respuesta:**
```json
{
  "success": true,
  "message": "AI suggestions generated. Review and save as budget.",
  "data": {
    "title": "Fiesta de cumpleaños de María",
    "description": "Cumpleaños número 30...",
    "total_amount": 1500,
    "categories": [
      {
        "name": "Alquiler del lugar",
        "amount": 300,
        "reason": "Renta de salón o casa con capacidad para 50 personas"
      },
      {
        "name": "Comida",
        "amount": 600,
        "reason": "Catering para 50 personas con entrada, plato principal y postre"
      },
      {
        "name": "Decoración",
        "amount": 250,
        "reason": "Globos, flores, luces y detalles temáticos"
      },
      {
        "name": "Entretenimiento",
        "amount": 200,
        "reason": "DJ, música o animador"
      },
      {
        "name": "Imprevistos",
        "amount": 150,
        "reason": "Gastos adicionales no previstos"
      }
    ],
    "general_advice": "Procura que la comida sea abundante y variada para agradar a todos los gustos.",
    "language": "es",
    "plan_type": "party"
  },
  "note": "These are AI suggestions. You can edit, add, or remove categories before saving."
}
```

---

### 5. MODO 2 - Paso 2: Guardar presupuesto de IA (después de revisar)
```http
POST /api/budgets/ai/save
```

**Body** (el usuario puede haber editado las categorías):
```json
{
  "title": "Fiesta de cumpleaños de María",
  "description": "Cumpleaños número 30...",
  "total_amount": 1500,
  "language": "es",
  "plan_type": "party",
  "categories": [
    {
      "name": "Alquiler del lugar",
      "amount": 400,
      "reason": "Renta de salón"
    },
    {
      "name": "Comida",
      "amount": 600,
      "reason": "Catering para 50 personas"
    },
    {
      "name": "Decoración",
      "amount": 200,
      "reason": "Globos y flores"
    },
    {
      "name": "Entretenimiento",
      "amount": 150,
      "reason": "DJ"
    },
    {
      "name": "Imprevistos",
      "amount": 150,
      "reason": "Gastos adicionales"
    }
  ]
}
```

**Validaciones:**
- ✅ La suma DEBE ser exactamente igual a `total_amount`
- ✅ Se valida como si fuera un presupuesto manual

**Respuesta exitosa:**
```json
{
  "success": true,
  "message": "AI budget saved successfully",
  "data": {
    "id": 2,
    "user_id": 5,
    "title": "Fiesta de cumpleaños de María",
    "total_amount": 1500,
    "mode": "ai",
    "language": "es",
    "plan_type": "party",
    "status": "draft",
    "categories": [...]
  },
  "is_valid": true
}
```

---

### 6. Actualizar presupuesto
```http
PUT /api/budgets/{budget_id}
```

**Body:**
```json
{
  "title": "Viaje a Machu Picchu - Actualizado",
  "total_amount": 2500,
  "status": "active"
}
```

**Comportamiento:**
- Si cambias `total_amount`, los porcentajes se **recalculan automáticamente**

---

### 7. Eliminar presupuesto
```http
DELETE /api/budgets/{budget_id}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Budget deleted"
}
```

---

## Gestión de Categorías

### 8. Agregar categoría a un presupuesto existente
```http
POST /api/budgets/{budget_id}/categories
```

**Body:**
```json
{
  "name": "Seguros",
  "amount": 150,
  "reason": "Seguro de viaje"
}
```

**Validaciones:**
- ✅ No puedes agregar categorías cuya suma exceda el `total_amount` del presupuesto

**Respuesta:**
```json
{
  "success": true,
  "message": "Category added",
  "data": {
    "id": 15,
    "budget_id": 1,
    "name": "Seguros",
    "amount": 150,
    "percentage": 7.5
  }
}
```

---

### 9. Actualizar categoría
```http
PUT /api/budgets/{budget_id}/categories/{category_id}
```

**Body:**
```json
{
  "name": "Vuelos (actualizado)",
  "amount": 850,
  "reason": "Pasajes con conexión"
}
```

**Validaciones:**
- ✅ La nueva cantidad no puede exceder el presupuesto total

---

### 10. Eliminar categoría
```http
DELETE /api/budgets/{budget_id}/categories/{category_id}
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Category deleted"
}
```

---

### 11. Validar presupuesto
```http
POST /api/budgets/{budget_id}/validate
```

**Respuesta si es válido:**
```json
{
  "success": true,
  "is_valid": true,
  "categories_total": 2000,
  "total_amount": 2000,
  "difference": 0,
  "message": "Budget is valid"
}
```

**Respuesta si NO es válido:**
```json
{
  "success": true,
  "is_valid": false,
  "categories_total": 1900,
  "total_amount": 2000,
  "difference": 100,
  "message": "Budget is invalid. Difference: $100"
}
```

---

## Flujo de trabajo recomendado

### Escenario 1: Usuario quiere crear presupuesto manualmente
```
1. POST /api/budgets/manual
   ↓
2. Presupuesto creado en estado "draft"
   ↓
3. PUT /api/budgets/{id} para cambiar status a "active"
```

### Escenario 2: Usuario quiere ayuda de IA
```
1. POST /api/budgets/ai/generate
   ↓ [Se muestran sugerencias]
   ↓
2. Usuario revisa y edita categorías en el frontend
   ↓
3. POST /api/budgets/ai/save (con categorías editadas)
   ↓
4. Presupuesto creado en estado "draft"
   ↓
5. PUT /api/budgets/{id} para cambiar status a "active"
```

### Escenario 3: Usuario quiere editar categorías después de crear presupuesto
```
1. POST /api/budgets/{id}/categories (para agregar)
   ↓
2. PUT /api/budgets/{id}/categories/{cat_id} (para editar)
   ↓
3. DELETE /api/budgets/{id}/categories/{cat_id} (para eliminar)
   ↓
4. POST /api/budgets/{id}/validate (para verificar)
```

---

## Detección automática de idioma

El sistema **detecta automáticamente** el idioma basándose en palabras clave:

**Español:** viaje, fiesta, boda, proyecto, compra, evento, reforma, hogar, dinero, presupuesto, plan, vacaciones, cumpleaños, casa, coche, mueble

**English:** travel, party, wedding, project, purchase, event, home, money, budget, vacation, birthday, house, car, furniture

Si se detecta español → todos los valores de la IA serán en español  
Si se detecta inglés → todos los valores serán en inglés

---

## Clasificación automática de plan

El sistema **clasifica automáticamente** el tipo de plan:

- `travel`: Detecta palabras como "viaje", "vuelo", "vacaciones", "hotel"
- `event`: Detecta "evento", "conferencia", "reunión", "seminario"
- `party`: Detecta "fiesta", "cumpleaños", "celebración", "boda"
- `purchase`: Detecta "compra", "producto", "tienda", "online"
- `project`: Detecta "proyecto", "reforma", "construcción", "desarrollo"
- `other`: Clasificación por defecto

---

## Validación de presupuesto

**Regla de Oro:** La suma de todas las categorías DEBE ser exactamente igual a `total_amount`

**Tolerancia:** 0.01 (por redondeo decimal)

**Ejemplo:**
```
Total: $100.00
Categoría 1: $33.33
Categoría 2: $33.33
Categoría 3: $33.33
Suma: $99.99 ✅ Válido (diferencia < 0.01)

Total: $100.00
Categoría 1: $40.00
Categoría 2: $30.00
Suma: $70.00 ❌ Inválido (diferencia > 0.01)
```

---

## Integración con IA (Groq)

**Modelos utilizados (con fallback automático):**
1. `llama-3.1-8b-instant` (rápido, precisión media)
2. `llama-3.1-70b-versatile` (más potente)
3. `mixtral-8x7b-32768` (excelente para instrucciones)
4. `gemma2-9b-it` (especializado en instrucciones)

**Rate Limiting:**
- `/api/budgets/ai/generate`: 10 requests por minuto

**Timeout:** 30 segundos

---

## Seguridad

- ✅ Todos los endpoints requieren autenticación: `middleware('auth:sanctum')`
- ✅ Un usuario NO puede ver/editar presupuestos de otros usuarios
- ✅ Validación de integridad: sum(categories) = total_amount
- ✅ Validación de entrada: max length, tipos de datos

---

## Ejemplos de uso (cURL)

### Crear presupuesto manual
```bash
curl -X POST http://localhost:8000/api/budgets/manual \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Viaje a Machu Picchu",
    "description": "Vacaciones de verano",
    "total_amount": 2000,
    "categories": [
      {"name": "Vuelos", "amount": 800, "reason": "Pasajes aéreos"},
      {"name": "Alojamiento", "amount": 600, "reason": "Hotel"},
      {"name": "Comida", "amount": 400, "reason": "Restaurantes"},
      {"name": "Actividades", "amount": 200, "reason": "Tours"}
    ]
  }'
```

### Generar sugerencias de IA
```bash
curl -X POST http://localhost:8000/api/budgets/ai/generate \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Fiesta de cumpleaños",
    "description": "Cumpleaños con 50 personas",
    "total_amount": 1500
  }'
```

### Guardar presupuesto de IA
```bash
curl -X POST http://localhost:8000/api/budgets/ai/save \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Fiesta de cumpleaños",
    "description": "Cumpleaños con 50 personas",
    "total_amount": 1500,
    "language": "es",
    "plan_type": "party",
    "categories": [...]
  }'
```

---

## Estado del presupuesto

- `draft`: Presupuesto en borrador, no está activo
- `active`: Presupuesto activo, el usuario lo está utilizando
- `archived`: Presupuesto archivado, histórico

Cambiar estado:
```http
PUT /api/budgets/{id}
{
  "status": "active"
}
```

---

## Próximas mejoras

- [ ] Análisis de gastos vs presupuesto
- [ ] Alertas cuando se excede una categoría
- [ ] Historial de versiones de presupuestos
- [ ] Compartir presupuestos entre usuarios
- [ ] Exportar presupuesto a PDF/Excel
- [ ] Presupuestos recurrentes

---

**Sistema creado:** 2025-12-12  
**API Version:** 1.0.0  
**Status:** ✅ En producción
