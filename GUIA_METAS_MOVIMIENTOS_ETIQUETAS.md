# 📘 Guía Completa: Metas, Movimientos y Etiquetas

**API de Finanzas Personales**
**Versión:** 1.0.0
**Fecha:** Enero 2026

---

## 📑 Índice

1. [Introducción](#introducción)
2. [Autenticación](#autenticación)
3. [Etiquetas (Tags)](#etiquetas-tags)
4. [Movimientos](#movimientos)
5. [Metas de Ahorro](#metas-de-ahorro)
6. [Contribuciones a Metas](#contribuciones-a-metas)
7. [Vista Unificada](#vista-unificada)
8. [Flujos Completos](#flujos-completos)
9. [Códigos de Estado HTTP](#códigos-de-estado-http)

---

## 🎯 Introducción

Esta API te permite gestionar tus finanzas personales con tres conceptos principales:

- **Etiquetas (Tags)**: Categorías para organizar tus movimientos y metas
- **Movimientos (Movements)**: Gastos e ingresos diarios con IA para voz
- **Metas de Ahorro (Saving Goals)**: Objetivos de ahorro a largo plazo
- **Contribuciones (Goal Contributions)**: Abonos a tus metas (separados de movimientos para cumplimiento tributario)

### Base URL
```
http://localhost:8000/api
```

---

## 🔐 Autenticación

Todos los endpoints requieren autenticación con Laravel Sanctum.

### 1. Registro
```bash
curl -X POST "http://localhost:8000/api/register" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Carlos Moreno",
    "email": "carlos@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

**Respuesta:**
```json
{
  "message": "Usuario registrado correctamente",
  "user": {
    "id": 1,
    "name": "Carlos Moreno",
    "email": "carlos@example.com"
  },
  "token": "1|abc123xyz..."
}
```

### 2. Login
```bash
curl -X POST "http://localhost:8000/api/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "carlos@example.com",
    "password": "password123"
  }'
```

**Respuesta:**
```json
{
  "access_token": "1|abc123xyz...",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Carlos Moreno",
    "email": "carlos@example.com"
  }
}
```

**⚠️ IMPORTANTE:** Usa el token en todas las siguientes peticiones:
```bash
-H "Authorization: Bearer 1|abc123xyz..."
```

---

## 🏷️ Etiquetas (Tags)

Las etiquetas son categorías para organizar tus gastos, ingresos y metas.

### 1. Listar todas las etiquetas
```bash
curl -X GET "http://localhost:8000/api/tags" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name_tag": "Comida",
      "created_at": "2026-01-15T10:00:00Z"
    },
    {
      "id": 2,
      "name_tag": "Transporte",
      "created_at": "2026-01-15T11:00:00Z"
    }
  ]
}
```

### 2. Crear una etiqueta
```bash
curl -X POST "http://localhost:8000/api/tags/create" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name_tag": "Entretenimiento"
  }'
```

**Respuesta:**
```json
{
  "tag": {
    "id": 3,
    "name_tag": "Entretenimiento",
    "created_at": "2026-01-28T12:00:00Z"
  }
}
```

**✅ Características:**
- Los nombres se normalizan automáticamente (primera letra en mayúscula)
- No permite duplicados (si existe, devuelve la etiqueta existente)
- Ejemplo: "comida", "COMIDA", "Comida" → todas se guardan como "Comida"

### 3. Sugerir etiqueta con IA (Solo sugerencia, NO guarda)
```bash
curl -X POST "http://localhost:8000/api/tags/suggestion" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "descripcion": "Pizza Dominos",
    "monto": 35000
  }'
```

**Respuesta:**
```json
{
  "status": "success",
  "data": {
    "tag": "Comida"
  }
}
```

**🤖 IA de Etiquetas:**
- Analiza tus etiquetas existentes
- Prioriza etiquetas que ya usas (90%+ de coincidencia)
- Sugiere nueva etiqueta si no hay coincidencia
- Detecta idioma automáticamente (español/inglés)
- **NO guarda la etiqueta**, solo la sugiere
- Rate limit: 20 requests/minuto

---

## 💸 Movimientos

Los movimientos son tus gastos e ingresos diarios.

### 1. Listar movimientos
```bash
curl -X GET "http://localhost:8000/api/movements" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": 1,
      "type": "expense",
      "amount": 50000,
      "description": "Almuerzo restaurante",
      "payment_method": "digital",
      "has_invoice": false,
      "tag": {
        "id": 1,
        "name_tag": "Comida"
      },
      "created_at": "2026-01-28T12:30:00Z"
    },
    {
      "id": 2,
      "type": "income",
      "amount": 2000000,
      "description": "Salario enero",
      "payment_method": "digital",
      "has_invoice": false,
      "tag": {
        "id": 5,
        "name_tag": "Salario"
      },
      "created_at": "2026-01-01T08:00:00Z"
    }
  ]
}
```

### 2. Crear movimiento manual
```bash
curl -X POST "http://localhost:8000/api/movements" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "expense",
    "amount": 45000,
    "description": "Uber al trabajo",
    "payment_method": "digital",
    "has_invoice": false,
    "tag_name": "Transporte"
  }'
```

**Respuesta:**
```json
{
  "message": "Movimiento creado",
  "data": {
    "id": 3,
    "type": "expense",
    "amount": 45000,
    "description": "Uber al trabajo",
    "payment_method": "digital",
    "has_invoice": false,
    "tag": {
      "id": 2,
      "name_tag": "Transporte"
    },
    "created_at": "2026-01-28T14:00:00Z"
  }
}
```

**📋 Campos:**
- `type`: **"expense"** (gasto) o **"income"** (ingreso) - Requerido
- `amount`: Monto (número decimal) - Requerido
- `description`: Descripción del movimiento - Opcional (default: "Movimiento")
- `payment_method`: **"cash"** (efectivo) o **"digital"** (tarjeta/transferencia) - Requerido
- `has_invoice`: true/false (¿tiene factura electrónica?) - Opcional (default: false)
- `tag_name`: Nombre de la etiqueta - Opcional

**✅ Lógica de etiquetas:**
- Si `tag_name` existe en tus etiquetas → lo usa
- Si `tag_name` NO existe → lo crea automáticamente
- Si no envías `tag_name` → movimiento sin etiqueta

### 3. Crear movimiento desde voz con IA 🎤

Este endpoint analiza una transcripción de voz y extrae TODOS los campos automáticamente.

```bash
curl -X POST "http://localhost:8000/api/movements/sugerir-voz" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "transcripcion": "Gasté 50 mil pesos en comida con tarjeta de crédito"
  }'
```

**Respuesta:**
```json
{
  "movement_suggestion": {
    "amount": 50000,
    "description": "Comida",
    "type": "expense",
    "payment_method": "digital",
    "suggested_tag": "Comida",
    "has_invoice": false
  }
}
```

**Más ejemplos de voz:**

```bash
# Ejemplo 1: Ingreso en efectivo
{
  "transcripcion": "Recibí 100 mil en efectivo por un trabajo"
}
# Respuesta: {amount: 100000, type: "income", payment_method: "cash", suggested_tag: "Trabajo"}

# Ejemplo 2: Gasto con factura
{
  "transcripcion": "Pagué 200 mil con tarjeta en el supermercado con factura electrónica"
}
# Respuesta: {amount: 200000, type: "expense", payment_method: "digital", has_invoice: true, suggested_tag: "Supermercado"}

# Ejemplo 3: Abono a meta (si tienes una meta llamada "Viaje")
{
  "transcripcion": "Aporté 300 mil para mi viaje a París"
}
# Respuesta: {amount: 300000, suggested_tag: "💰 Meta: Viaje"}
```

**🤖 IA de Voz:**
- Extrae **6 campos** automáticamente: amount, description, type, payment_method, suggested_tag, has_invoice
- Convierte unidades verbales: "mil" → 1000, "k" → 1000, "millón" → 1000000
- Detecta tipo: "gasté", "pagué" → expense | "recibí", "me pagaron" → income
- Detecta método: "efectivo", "plata", "billetes" → cash | "tarjeta", "nequi", "transferencia" → digital
- Detecta factura: "factura", "rut", "electrónica" → true
- Integra con tus metas de ahorro (si mencionas una meta, sugiere "💰 Meta: X")
- Detecta idioma automáticamente (español/inglés)
- Rate limit: 20 requests/minuto

**⚠️ IMPORTANTE:**
- Este endpoint **NO guarda el movimiento**, solo devuelve la sugerencia
- El frontend debe mostrar los datos al usuario para que los confirme/edite
- Luego el usuario debe llamar a `POST /api/movements` para guardar

**Flujo completo con voz:**
```
1. Usuario presiona botón de micrófono
2. App graba audio → transcribe a texto (STT)
3. App llama: POST /api/movements/sugerir-voz
4. API responde con sugerencia completa
5. App muestra formulario pre-llenado
6. Usuario confirma o edita
7. App llama: POST /api/movements (guarda)
```

---

## 🎯 Metas de Ahorro

Las metas de ahorro son objetivos financieros a largo plazo.

### Concepto importante
Cuando creas una meta:
1. Se crea automáticamente una **etiqueta especial** con el nombre "Meta: {nombre}"
2. El progreso se calcula automáticamente usando:
   - **Movimientos** tipo "income" con esa etiqueta (método antiguo, aún soportado)
   - **Contribuciones** directas a la meta (método nuevo recomendado)

### 1. Listar metas
```bash
curl -X GET "http://localhost:8000/api/saving-goals" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
[
  {
    "id": 1,
    "name": "Viaje a París",
    "target_amount": 5000000,
    "saved_amount": 1500000,
    "percentage": 30.0,
    "deadline": "2026-12-31",
    "color": "#3B82F6",
    "emoji": "✈️",
    "tag": {
      "id": 10,
      "name_tag": "Meta: Viaje a París"
    },
    "created_at": "2026-01-15T10:00:00Z"
  }
]
```

### 2. Crear meta
```bash
curl -X POST "http://localhost:8000/api/saving-goals" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Laptop nueva",
    "target_amount": 3000000,
    "deadline": "2026-06-30",
    "color": "#10B981",
    "emoji": "💻"
  }'
```

**Respuesta:**
```json
{
  "message": "Saving goal created successfully",
  "saving_goal": {
    "id": 2,
    "name": "Laptop nueva",
    "target_amount": 3000000,
    "saved_amount": 0,
    "percentage": 0,
    "deadline": "2026-06-30",
    "color": "#10B981",
    "emoji": "💻",
    "tag": {
      "id": 11,
      "name_tag": "Meta: Laptop nueva"
    },
    "created_at": "2026-01-28T15:00:00Z"
  }
}
```

**📋 Campos:**
- `name`: Nombre de la meta - Requerido
- `target_amount`: Monto objetivo - Requerido
- `deadline`: Fecha límite (YYYY-MM-DD) - Opcional
- `color`: Color en hex (#RRGGBB) - Opcional (default: #3B82F6)
- `emoji`: Emoji de la meta - Opcional

### 3. Ver detalle de una meta
```bash
curl -X GET "http://localhost:8000/api/saving-goals/1" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:** (igual al formato de listar)

### 4. Actualizar meta
```bash
curl -X PUT "http://localhost:8000/api/saving-goals/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "target_amount": 6000000,
    "deadline": "2027-01-31",
    "emoji": "🗼"
  }'
```

**Nota:** Todos los campos son opcionales, solo envía los que quieres cambiar.

### 5. Eliminar meta
```bash
curl -X DELETE "http://localhost:8000/api/saving-goals/1" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "message": "Saving goal deleted successfully"
}
```

---

## 💰 Contribuciones a Metas

Las contribuciones son **abonos a tus metas de ahorro**, almacenadas en una tabla separada de movimientos.

### ¿Por qué están separadas?
- **Cumplimiento tributario**: Para autoridades fiscales (DIAN en Colombia), las contribuciones NO son movimientos tributarios
- **Sin método de pago**: Las contribuciones no tienen `payment_method` porque no son transacciones comerciales
- **Auditoría clara**: Separación limpia entre gastos/ingresos y ahorro interno
- **Vista unificada disponible**: Puedes ver todo junto con el endpoint `/api/transactions/unified`

### 1. Listar contribuciones de una meta
```bash
curl -X GET "http://localhost:8000/api/goal-contributions/1" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": "gc_1",
      "goal_id": 1,
      "goal_name": "Viaje a París",
      "amount": 500000,
      "description": "Primer abono enero",
      "date": "2026-01-15T10:00:00Z",
      "created_at": "2026-01-15T10:00:00Z",
      "updated_at": "2026-01-15T10:00:00Z"
    },
    {
      "id": "gc_2",
      "goal_id": 1,
      "goal_name": "Viaje a París",
      "amount": 1000000,
      "description": "Abono febrero",
      "date": "2026-02-01T12:00:00Z",
      "created_at": "2026-02-01T12:00:00Z",
      "updated_at": "2026-02-01T12:00:00Z"
    }
  ],
  "total": 2
}
```

**Nota:** Los IDs llevan prefijo "gc_" (goal contribution) para diferenciarse de movimientos.

### 2. Crear contribución
```bash
curl -X POST "http://localhost:8000/api/goal-contributions" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "goal_id": 1,
    "amount": 300000,
    "description": "Abono de febrero"
  }'
```

**Respuesta:**
```json
{
  "message": "Contribution created successfully",
  "data": {
    "id": "gc_3",
    "goal_id": 1,
    "goal_name": "Viaje a París",
    "amount": 300000,
    "description": "Abono de febrero",
    "date": "2026-02-15T10:00:00Z",
    "created_at": "2026-02-15T10:00:00Z",
    "updated_at": "2026-02-15T10:00:00Z"
  }
}
```

**📋 Campos:**
- `goal_id`: ID de la meta - Requerido
- `amount`: Monto del abono (mínimo 0.01) - Requerido
- `description`: Descripción - Opcional (default: "Abono a meta")

### 3. Eliminar contribución
```bash
curl -X DELETE "http://localhost:8000/api/goal-contributions/gc_3" \
  -H "Authorization: Bearer {token}"
```

**Nota:** Usa el ID completo con prefijo "gc_" o solo el número (ambos funcionan).

### 4. Ver estadísticas de una meta
```bash
curl -X GET "http://localhost:8000/api/goal-contributions/1/stats" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "total_contributions": 3,
  "total_amount": 1800000,
  "average_contribution": 600000,
  "largest_contribution": 1000000,
  "smallest_contribution": 300000,
  "last_contribution_date": "2026-02-15T10:00:00Z",
  "percentage_of_goal": 36.0
}
```

---

## 🔄 Vista Unificada

El endpoint de transacciones unificadas combina **movimientos** y **contribuciones** en una sola vista cronológica.

### 1. Ver todas las transacciones
```bash
curl -X GET "http://localhost:8000/api/transactions/unified" \
  -H "Authorization: Bearer {token}"
```

**Respuesta:**
```json
{
  "data": [
    {
      "id": "m_1",
      "type": "expense",
      "type_badge": "Gasto",
      "amount": 50000,
      "description": "Almuerzo",
      "category": "Comida",
      "goal_name": null,
      "date": "2026-01-27T12:00:00Z",
      "payment_method": "cash",
      "source": "movement"
    },
    {
      "id": "gc_1",
      "type": "contribution",
      "type_badge": "Abono",
      "amount": 500000,
      "description": "Primer abono",
      "category": null,
      "goal_name": "Viaje a París",
      "date": "2026-01-27T14:30:00Z",
      "payment_method": null,
      "source": "goal_contribution"
    },
    {
      "id": "m_2",
      "type": "income",
      "type_badge": "Ingreso",
      "amount": 2000000,
      "description": "Salario",
      "category": "Salario",
      "goal_name": null,
      "date": "2026-01-28T08:00:00Z",
      "payment_method": "digital",
      "source": "movement"
    }
  ],
  "pagination": {
    "total": 3,
    "per_page": 50,
    "current_page": 1,
    "last_page": 1,
    "from": 1,
    "to": 3
  }
}
```

### 2. Filtrar por tipo
```bash
# Solo gastos
curl -X GET "http://localhost:8000/api/transactions/unified?type=expense" \
  -H "Authorization: Bearer {token}"

# Gastos y contribuciones
curl -X GET "http://localhost:8000/api/transactions/unified?type=expense,contribution" \
  -H "Authorization: Bearer {token}"

# Solo contribuciones
curl -X GET "http://localhost:8000/api/transactions/unified?type=contribution" \
  -H "Authorization: Bearer {token}"
```

### 3. Filtrar por rango de fechas
```bash
curl -X GET "http://localhost:8000/api/transactions/unified?start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer {token}"
```

### 4. Filtrar contribuciones de una meta específica
```bash
curl -X GET "http://localhost:8000/api/transactions/unified?type=contribution&goal_id=1" \
  -H "Authorization: Bearer {token}"
```

### 5. Paginación
```bash
# 10 resultados por página (página 1)
curl -X GET "http://localhost:8000/api/transactions/unified?per_page=10&page=1" \
  -H "Authorization: Bearer {token}"

# 100 resultados por página (página 2)
curl -X GET "http://localhost:8000/api/transactions/unified?per_page=100&page=2" \
  -H "Authorization: Bearer {token}"
```

### 6. Combinando filtros
```bash
# Todos los gastos de enero con paginación
curl -X GET "http://localhost:8000/api/transactions/unified?type=expense&start_date=2026-01-01&end_date=2026-01-31&per_page=50" \
  -H "Authorization: Bearer {token}"
```

**🔍 Parámetros disponibles:**
- `type`: expense, income, contribution (separados por comas)
- `start_date`: Fecha inicio (YYYY-MM-DD)
- `end_date`: Fecha fin (YYYY-MM-DD)
- `goal_id`: ID de meta (solo para contributions)
- `page`: Número de página (default: 1)
- `per_page`: Resultados por página - 10, 50 o 100 (default: 50)

**📊 Ordenamiento:**
- Por defecto: orden cronológico ascendente (más antiguos primero)
- No se puede cambiar el orden (diseñado así para vista de timeline)

---

## 🎬 Flujos Completos

### Flujo 1: Crear meta y hacer contribuciones

```bash
# Paso 1: Crear meta
curl -X POST "http://localhost:8000/api/saving-goals" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Vacaciones",
    "target_amount": 4000000,
    "deadline": "2026-12-31",
    "emoji": "🏖️"
  }'
# Respuesta: { "saving_goal": { "id": 5 } }

# Paso 2: Hacer primera contribución
curl -X POST "http://localhost:8000/api/goal-contributions" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "goal_id": 5,
    "amount": 400000,
    "description": "Primer abono"
  }'

# Paso 3: Ver progreso
curl -X GET "http://localhost:8000/api/saving-goals/5" \
  -H "Authorization: Bearer {token}"
# Respuesta: { "saved_amount": 400000, "percentage": 10.0 }

# Paso 4: Ver estadísticas
curl -X GET "http://localhost:8000/api/goal-contributions/5/stats" \
  -H "Authorization: Bearer {token}"
```

### Flujo 2: Registrar gasto con voz

```bash
# Paso 1: Usuario graba voz → transcribe
transcripcion = "Gasté 80 mil en el supermercado con tarjeta"

# Paso 2: Solicitar sugerencia de IA
curl -X POST "http://localhost:8000/api/movements/sugerir-voz" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "transcripcion": "Gasté 80 mil en el supermercado con tarjeta"
  }'
# Respuesta: {
#   "amount": 80000,
#   "type": "expense",
#   "payment_method": "digital",
#   "suggested_tag": "Supermercado",
#   "description": "Supermercado",
#   "has_invoice": false
# }

# Paso 3: Usuario confirma/edita en el frontend

# Paso 4: Guardar movimiento
curl -X POST "http://localhost:8000/api/movements" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "expense",
    "amount": 80000,
    "description": "Supermercado",
    "payment_method": "digital",
    "has_invoice": false,
    "tag_name": "Supermercado"
  }'
```

### Flujo 3: Ver resumen de finanzas

```bash
# Paso 1: Ver todas las transacciones del mes
curl -X GET "http://localhost:8000/api/transactions/unified?start_date=2026-01-01&end_date=2026-01-31&per_page=100" \
  -H "Authorization: Bearer {token}"

# Paso 2: Ver solo gastos del mes
curl -X GET "http://localhost:8000/api/transactions/unified?type=expense&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer {token}"

# Paso 3: Ver contribuciones a metas
curl -X GET "http://localhost:8000/api/transactions/unified?type=contribution&start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer {token}"

# Paso 4: Ver estadísticas de cada meta
curl -X GET "http://localhost:8000/api/goal-contributions/1/stats" \
  -H "Authorization: Bearer {token}"

curl -X GET "http://localhost:8000/api/goal-contributions/2/stats" \
  -H "Authorization: Bearer {token}"
```

### Flujo 4: Crear movimiento manual con etiqueta sugerida por IA

```bash
# Paso 1: Solicitar sugerencia de etiqueta
curl -X POST "http://localhost:8000/api/tags/suggestion" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "descripcion": "Netflix mensual",
    "monto": 45000
  }'
# Respuesta: { "data": { "tag": "Entretenimiento" } }

# Paso 2: Usuario ve sugerencia y la acepta

# Paso 3: Crear movimiento con etiqueta sugerida
curl -X POST "http://localhost:8000/api/movements" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "type": "expense",
    "amount": 45000,
    "description": "Netflix mensual",
    "payment_method": "digital",
    "has_invoice": false,
    "tag_name": "Entretenimiento"
  }'
```

---

## 📊 Códigos de Estado HTTP

| Código | Significado | Cuándo ocurre |
|--------|-------------|---------------|
| **200** | OK | GET, PUT, DELETE exitosos |
| **201** | Created | POST exitoso (creación) |
| **400** | Bad Request | Parámetros inválidos |
| **401** | Unauthorized | Token faltante o inválido |
| **404** | Not Found | Recurso no existe o no te pertenece |
| **422** | Validation Error | Validación de campos falló |
| **500** | Server Error | Error interno del servidor |

---

## 🎯 Mejores Prácticas

### 1. Manejo de Etiquetas
```javascript
// ✅ CORRECTO: Normalizar antes de enviar
const tagName = "comida"; // Del usuario
const normalized = tagName.charAt(0).toUpperCase() + tagName.slice(1).toLowerCase();
// Resultado: "Comida"

// ❌ INCORRECTO: Enviar sin normalizar
// El backend lo normalizará, pero mejor hacerlo en frontend para UI consistente
```

### 2. Manejo de Contribuciones vs Movimientos
```javascript
// ✅ CORRECTO: Usar contribuciones para ahorros
// Guardar abono a meta:
POST /api/goal-contributions { goal_id: 1, amount: 500000 }

// ❌ EVITAR (aunque aún funciona): Usar movimientos para ahorros
// POST /api/movements { type: "income", tag_name: "Meta: Viaje", amount: 500000 }
// Funciona pero mezcla conceptos tributarios
```

### 3. Validación de Respuestas IA
```javascript
// ✅ CORRECTO: Siempre validar y permitir edición
const voiceSuggestion = await fetch('/api/movements/sugerir-voz', {...});
const data = voiceSuggestion.movement_suggestion;

// Mostrar formulario pre-llenado para que usuario confirme/edite
showForm({
  amount: data.amount,        // Usuario puede cambiar
  description: data.description, // Usuario puede cambiar
  type: data.type,
  payment_method: data.payment_method,
  suggested_tag: data.suggested_tag,
  has_invoice: data.has_invoice
});

// ❌ INCORRECTO: Guardar automáticamente sin confirmar
// Siempre permitir que el usuario revise antes de guardar
```

### 4. Paginación Eficiente
```javascript
// ✅ CORRECTO: Usar per_page apropiado según UI
// Vista móvil (scroll infinito): per_page=10
// Vista desktop (tabla): per_page=50
// Exportar/reportes: per_page=100

// ❌ INCORRECTO: Traer todo sin paginación
// Puede sobrecargar el servidor con usuarios con muchas transacciones
```

### 5. Filtrado Combinado
```javascript
// ✅ CORRECTO: Combinar filtros para reportes específicos
// Reporte de gastos del mes con paginación
GET /api/transactions/unified?type=expense&start_date=2026-01-01&end_date=2026-01-31&per_page=100

// ✅ CORRECTO: Ver contribuciones específicas de una meta
GET /api/transactions/unified?type=contribution&goal_id=1

// ❌ INCORRECTO: Traer todo y filtrar en frontend
// Es ineficiente, usa los filtros del backend
```

---

## 🔒 Seguridad

### Validación de Propiedad (Ownership)
Todos los endpoints verifican que el recurso pertenece al usuario autenticado:

```bash
# Usuario A (id: 1) intenta acceder a meta de Usuario B (id: 2)
curl -X GET "http://localhost:8000/api/saving-goals/5" \
  -H "Authorization: Bearer {token_usuario_A}"

# Respuesta: 404 Not Found
# (No 403 Forbidden para evitar enumeration attack)
```

### Rate Limiting
Los endpoints de IA tienen límites:
- `/api/tags/suggestion`: 20 requests/minuto
- `/api/movements/sugerir-voz`: 20 requests/minuto
- `/api/budgets/ai/generate`: 10 requests/minuto

Si excedes el límite:
```json
{
  "error": "Too Many Requests",
  "message": "Rate limit exceeded. Try again in 60 seconds."
}
```

---

## 📞 Soporte

Si tienes problemas:
1. Revisa los logs del servidor: `php artisan pail` o `tail -f storage/logs/laravel.log`
2. Verifica que tu token sea válido
3. Consulta el código de estado HTTP
4. Lee el mensaje de error en la respuesta JSON

---

**Versión:** 1.0.0
**Última actualización:** 28 Enero 2026
**Estado:** ✅ Producción
