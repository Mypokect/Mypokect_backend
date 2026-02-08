# API de Movimientos y Tags - Ejemplos de Uso

## Endpoints Implementados

### 1. MOVIMIENTOS (Movements)

#### GET /api/movements
Obtiene todos los movimientos del usuario autenticado.

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "type": "expense",
      "amount": 50000,
      "description": "Comida",
      "tag_name": "Alimentación",
      "payment_method": "digital",
      "has_invoice": false,
      "created_at": "2026-01-25T10:30:00Z"
    },
    {
      "id": 2,
      "type": "income",
      "amount": 1000000,
      "description": "Salario enero",
      "tag_name": "Ingresos",
      "payment_method": "digital",
      "has_invoice": true,
      "created_at": "2026-01-24T08:00:00Z"
    }
  ]
}
```

---

#### POST /api/movements
Crea un nuevo movimiento.

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "type": "expense",
  "amount": 50000,
  "description": "Pizza para la cena",
  "payment_method": "digital",
  "tag_name": "Comida",
  "has_invoice": false
}
```

**Response (201):**
```json
{
  "message": "Movimiento creado",
  "data": {
    "id": 3,
    "type": "expense",
    "amount": 50000,
    "description": "Pizza para la cena",
    "tag_name": "Comida",
    "payment_method": "digital",
    "has_invoice": false,
    "created_at": "2026-01-25T14:30:00Z"
  }
}
```

**Validaciones:**
- `type`: required, debe ser "income" o "expense"
- `amount`: required, numérico, mínimo 0.01
- `description`: opcional, string, máximo 255 caracteres
- `payment_method`: required, debe ser "cash" o "digital"
- `tag_name`: opcional, string, máximo 50 caracteres
- `has_invoice`: opcional, boolean

**Nota:** Si el `tag_name` no existe, se crea automáticamente para el usuario.

---

#### POST /api/movements/sugerir-voz ⚡ IA
Extrae información de un movimiento desde una transcripción de voz usando IA.

**Rate Limit:** 20 requests/minuto

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "transcripcion": "Gasté 50 mil pesos en comida con tarjeta de crédito"
}
```

**Response (200):**
```json
{
  "movement_suggestion": {
    "description": "Comida",
    "amount": 50000,
    "suggested_tag": "Alimentación",
    "type": "expense",
    "payment_method": "digital",
    "has_invoice": false
  }
}
```

**Ejemplos de Transcripciones:**

1. **Gasto en efectivo:**
```json
{
  "transcripcion": "Pagué 30 mil en efectivo por transporte"
}
```
Response:
```json
{
  "movement_suggestion": {
    "description": "Transporte",
    "amount": 30000,
    "suggested_tag": "Transporte",
    "type": "expense",
    "payment_method": "cash",
    "has_invoice": false
  }
}
```

2. **Ingreso:**
```json
{
  "transcripcion": "Recibí un millón de pesos por mi trabajo freelance"
}
```
Response:
```json
{
  "movement_suggestion": {
    "description": "Trabajo freelance",
    "amount": 1000000,
    "suggested_tag": "Ingresos",
    "type": "income",
    "payment_method": "digital",
    "has_invoice": false
  }
}
```

3. **Con factura:**
```json
{
  "transcripcion": "Compré un computador por 2 millones con tarjeta y pedí factura electrónica"
}
```
Response:
```json
{
  "movement_suggestion": {
    "description": "Computador",
    "amount": 2000000,
    "suggested_tag": "Tecnología",
    "type": "expense",
    "payment_method": "digital",
    "has_invoice": true
  }
}
```

4. **Ahorro a meta:**
```json
{
  "transcripcion": "Guardé 100 mil para mi meta de viaje a la playa"
}
```
Response (si existe una meta llamada "Viaje a la playa"):
```json
{
  "movement_suggestion": {
    "description": "Ahorro para viaje",
    "amount": 100000,
    "suggested_tag": "💰 Meta: Viaje a la playa",
    "type": "expense",
    "payment_method": "digital",
    "has_invoice": false
  }
}
```

---

### 2. ETIQUETAS (Tags)

#### GET /api/tags
Obtiene todas las etiquetas del usuario.

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name_tag": "Alimentación"
    },
    {
      "id": 2,
      "name_tag": "Transporte"
    },
    {
      "id": 3,
      "name_tag": "Hogar"
    }
  ]
}
```

---

#### POST /api/tags/create
Crea una nueva etiqueta.

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "name_tag": "Entretenimiento"
}
```

**Response (201):**
```json
{
  "tag": {
    "id": 4,
    "name_tag": "Entretenimiento"
  }
}
```

**Validaciones:**
- `name_tag`: required, string, máximo 50 caracteres, único por usuario

**Nota:** Si la etiqueta ya existe para el usuario, retorna la existente (no duplica).

---

#### POST /api/tags/suggestion ⚡ IA
Sugiere una etiqueta basándose en la descripción y monto usando IA.

**Rate Limit:** 20 requests/minuto

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "descripcion": "Pizza Domino's",
  "monto": 35000
}
```

**Response (200):**
```json
{
  "status": "success",
  "data": {
    "tag": "Comida"
  }
}
```

**Ejemplos:**

1. **Uber:**
```json
{
  "descripcion": "Uber al centro",
  "monto": 15000
}
```
Response: `{"data": {"tag": "Transporte"}}`

2. **Netflix:**
```json
{
  "descripcion": "Suscripción Netflix",
  "monto": 45000
}
```
Response: `{"data": {"tag": "Entretenimiento"}}`

3. **Farmacia:**
```json
{
  "descripcion": "Medicamentos Cruz Verde",
  "monto": 80000
}
```
Response: `{"data": {"tag": "Salud"}}`

**Nota:** Este endpoint NO guarda la etiqueta en la base de datos, solo retorna la sugerencia. El frontend debe llamar a `/api/tags/create` si desea guardarla.

---

## Códigos de Error

### 401 Unauthorized
```json
{
  "error": "Unauthorized"
}
```

### 422 Validation Failed
```json
{
  "error": "Validation failed",
  "messages": {
    "amount": ["El monto es requerido"],
    "type": ["El tipo debe ser income o expense"]
  }
}
```

### 500 Server Error
```json
{
  "error": "An error occurred while creating the movement.",
  "message": "Error details..."
}
```

---

## Flujo Típico de Uso

### Opción 1: Crear Movimiento Manual
1. Usuario selecciona tipo, ingresa monto, descripción
2. (Opcional) Llamar a `POST /api/tags/suggestion` para obtener sugerencia de tag
3. Llamar a `POST /api/movements` con los datos completos

### Opción 2: Crear Movimiento por Voz
1. Usuario graba audio y lo transcribe a texto
2. Llamar a `POST /api/movements/sugerir-voz` con la transcripción
3. Mostrar sugerencia al usuario para confirmar/editar
4. Llamar a `POST /api/movements` con los datos finales

---

## Características de IA

### Modelos Utilizados
- **Groq API** con Llama 3.1 8B Instant y Gemma 2 9B
- Fallback automático entre modelos si uno falla
- Temperatura baja (0.1) para respuestas precisas

### Capacidades de Extracción de Voz
- ✅ Detección de montos (k, mil, millón)
- ✅ Clasificación automática (gasto vs ingreso)
- ✅ Detección de método de pago (efectivo vs digital)
- ✅ Detección de factura
- ✅ Sugerencia de categoría
- ✅ Integración con metas de ahorro
- ✅ Soporte para múltiples idiomas (ES/EN)

### Capacidades de Sugerencia de Tags
- ✅ Reutilización de tags existentes del usuario
- ✅ Creación inteligente de nuevas categorías
- ✅ Análisis contextual (descripción + monto)
- ✅ Respuestas consistentes y normalizadas

---

## Notas Técnicas

### Base de Datos
- Relación `movements.tag_id` → `tags.id` (nullable)
- Relación `movements.user_id` → `users.id` (cascade on delete)
- Índices en `user_id` y `tag_id` para performance

### Seguridad
- Todas las rutas protegidas con `auth:sanctum`
- Rate limiting en endpoints de IA
- Validación exhaustiva de inputs
- Autorización automática por usuario

### Performance
- Eager loading de relación `tag` en listados
- Uso de `firstOrCreate` para evitar duplicados
- Transacciones DB para operaciones complejas
- Cache de configuración en producción

---

**Versión:** 1.0.0  
**Fecha:** Enero 2026  
**Autor:** Finance API Team
