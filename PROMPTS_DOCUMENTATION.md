# 📋 Documentación de Prompts de IA - MovementAIService

**Versión:** 2.1.0  
**Fecha:** Enero 25, 2026  
**Archivo:** `app/Services/MovementAIService.php`

---

## 🎯 Propósito

Este documento explica los dos prompts separados y sus propósitos completamente diferentes en la API de movimientos y etiquetas.

---

## 📊 Comparativa de Prompts

### Resumen Ejecutivo

| Característica | buildTagPrompt() | buildVoiceMovementPrompt() |
|----------------|------------------|---------------------------|
| **Propósito** | Sugerir UNA etiqueta | Extraer contexto COMPLETO de movimiento |
| **Endpoint** | `POST /api/tags/suggestion` | `POST /api/movements/sugerir-voz` |
| **Input** | Descripción + Monto | Transcripción de voz |
| **Output** | Solo nombre etiqueta | 6 campos (amount, description, type, etc) |
| **Uso** | Manual (cuando usuario escribe) | Voz (cuando usuario habla) |
| **Tokens** | 160 | 190 |
| **Multiidioma** | Sí (ES/EN automático) | Sí (ES/EN automático) |

---

## 🏷️ PROMPT 1: buildTagPrompt()

### **Ubicación en Código**
`app/Services/MovementAIService.php:142`

### **Método que lo usa**
```php
public function suggestTag(string $description, float $amount, User $user): string
```

### **Endpoint**
```
POST /api/tags/suggestion
```

### **Propósito**
✅ Sugerir **SOLO UNA ETIQUETA** basándose en descripción + monto  
✅ Reutilizar etiquetas existentes si coinciden 90%+  
✅ Crear etiqueta nueva si no hay coincidencia  

### **Input**
```json
{
  "descripcion": "Pizza Dominos",
  "monto": 35000
}
```

### **Output**
```json
{
  "status": "success",
  "data": {
    "tag": "Comida"
  }
}
```

### **El Prompt (MEJORADO v2.1.1)**
```
TASK: Categorize this transaction in ONE tag. Output ONLY the tag name. Nothing else.

Description: "$description"
Amount: $amount
Existing tags: [$tagsList]

IF 90%+ match with existing tag → return that tag name
ELSE → return a new ONE-word category

Return ONLY the word. No explanation. No periods. No extra text.

Examples:
Input: "Pizza" + tags ["Food","Transport"] → Output: Food
Input: "Netflix" + tags ["Food","Transport"] → Output: Entertainment

Answer:
```

**Cambios en v2.1.1:**
- ✅ "TASK:" inicio más agresivo
- ✅ "Output ONLY the tag name" explícito
- ✅ "Answer:" fuerza respuesta directa
- ✅ Eliminada sección RULES (más confusa)
- ✅ Ejemplos más claros con "Input:" y "Output:"
- ✅ Repetición de "ONLY" para reforzar

### **Características**
- ✅ Analiza tags populares (ordenados por uso)
- ✅ Detecta idioma automáticamente
- ✅ Prioriza tags existentes con criterio 90%+
- ✅ Crea nuevas etiquetas si es necesario
- ✅ Respuesta ultra-compacta (solo nombre)
- ✅ 160 tokens (-54% vs original)

### **Flujo Típico**
```
Frontend: Usuario escribe "Pizza" + monto 35000
         ↓
API: Endpoint POST /api/tags/suggestion
     ├─ Obtiene tags del usuario ordenadas por popularidad
     ├─ Detecta idioma (español por tags "Comida", "Transporte")
     ├─ Llama a Groq con buildTagPrompt()
     ├─ Recibe: "Comida"
     └─ Retorna: {"tag": "Comida"}
         ↓
Frontend: Muestra sugerencia "Comida" al usuario
         ├─ Si le gusta: la usa
         └─ Si no: escribe otra
```

### **Casos de Uso**
1. **Autocomplete en formulario:** Usuario escribe descripción, ve sugerencia de tag
2. **Categorización manual:** Usuario ingresa gasto manualmente y quiere sugerencia de categoría
3. **Análisis en tiempo real:** Mientras el usuario escribe, se sugieren tags

---

## 🎤 PROMPT 2: buildVoiceMovementPrompt()

### **Ubicación en Código**
`app/Services/MovementAIService.php:110`

### **Método que lo usa**
```php
public function suggestFromVoice(string $transcription, User $user): array
```

### **Endpoint**
```
POST /api/movements/sugerir-voz
```

### **Propósito**
✅ Extraer **CONTEXTO COMPLETO** del movimiento desde voz  
✅ Detectar: monto, descripción, tipo (gasto/ingreso), método de pago, etiqueta, factura  
✅ Enviar toda la información al frontend para que el usuario confirme  

### **Input**
```json
{
  "transcripcion": "Gasté 50 mil pesos en comida con tarjeta de crédito"
}
```

### **Output**
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

### **El Prompt (MEJORADO v2.1.1 - OPTIMIZADO -32%)**
```
TASK: Extract transaction data from voice. Return ONLY valid JSON. No explanation.

Transcription: "$transcription"
User tags: [$tagsList]
User goals: [$goalsList]

EXTRACT these fields:
- amount: numeric (convert k/mil/million to numbers), default 0
- description: cleaned text max 4 words
- type: "income" if received money, "expense" for spending
- payment_method: "cash" if efectivo/plata/billetes, else "digital"
- suggested_tag: match 90%+ with existing tag, else new word. If goal match → "💰 Meta: goalname"
- has_invoice: true if mention factura/rut/electrónica, else false

Return ONLY this JSON, nothing else:
{
  "amount": 0,
  "description": "",
  "type": "expense",
  "payment_method": "digital",
  "suggested_tag": "",
  "has_invoice": false
}
```

**Cambios en v2.1.1:**
- ✅ "TASK:" inicio más claro
- ✅ "Return ONLY valid JSON. No explanation." explícito
- ✅ "EXTRACT these fields:" mejor estructura
- ✅ Explicaciones más claras para cada campo
- ✅ "Return ONLY this JSON, nothing else" refuerza formato

### **Características**
- ✅ Extrae **6 campos** del contexto de voz (vs solo 1 del tag prompt)
- ✅ Convierte unidades verbales (k=1000, mil=1000, millón=1M)
- ✅ Detecta tipo (gasto vs ingreso)
- ✅ Detecta método de pago (efectivo vs digital)
- ✅ Sugiere etiqueta automáticamente
- ✅ Detecta menciones de factura
- ✅ Integración con metas de ahorro (💰 Meta: X)
- ✅ Descripción comprimida (max 4 palabras)
- ✅ 190 tokens (-32% vs original de 280)
- ✅ Multiidioma automático

### **Flujo Típico**
```
Frontend: Usuario presiona botón de micrófono
         ↓
Voice Recording: "Gasté 50 mil en comida con tarjeta"
         ↓
Transcription (STT): Genera texto
         ↓
API: Endpoint POST /api/movements/sugerir-voz
     ├─ Obtiene tags del usuario (ordenadas por popularidad)
     ├─ Obtiene metas de ahorro del usuario
     ├─ Detecta idioma (español de la transcripción)
     ├─ Llama a Groq con buildVoiceMovementPrompt()
     ├─ Recibe JSON: {amount, description, type, payment_method, suggested_tag, has_invoice}
     └─ Normaliza y valida respuesta
         ↓
Frontend: Muestra sugerencia al usuario
     ├─ Monto: 50,000
     ├─ Descripción: "Comida"
     ├─ Tipo: "Gasto"
     ├─ Método: "Digital"
     ├─ Etiqueta: "Comida"
     └─ Factura: No
         ↓
Usuario: Confirma o edita cada campo
         ↓
API: POST /api/movements (crear movimiento con datos finales)
```

### **Casos de Uso**
1. **Creación rápida por voz:** Usuario habla y IA extrae todo automáticamente
2. **Hands-free:** Mientras maneja o está ocupado
3. **Mayor contexto:** El frontend recibe datos completos para confirmar
4. **Multiidioma:** Funciona en español e inglés automáticamente

---

## 🔄 Comparativa Lado a Lado

### **Caso de Uso: Usuario quiere registrar una compra**

#### **Escenario 1: Escribiendo (usa buildTagPrompt)**
```
User escribe: "Pizza"
Monto: 35000

buildTagPrompt() → "Comida"

API retorna: {"tag": "Comida"}
```

#### **Escenario 2: Hablando (usa buildVoiceMovementPrompt)**
```
User dice: "Gasté treinta y cinco mil en pizza con tarjeta"

buildVoiceMovementPrompt() → Extrae todo

API retorna: {
  "amount": 35000,
  "description": "Pizza",
  "type": "expense",
  "payment_method": "digital",
  "suggested_tag": "Comida",
  "has_invoice": false
}
```

**Diferencia clave:** 
- Escribir = Solo necesita sugerir etiqueta
- Hablar = Necesita extraer CONTEXTO COMPLETO

---

## 🌐 Multiidioma Automático

Ambos prompts detectan automáticamente el idioma del usuario:

### **Detección en buildTagPrompt()**
```php
$language = $this->detectLanguage($description, $existingTags);
// Retorna: "es" o "en"
```

**Prioridad:**
1. Analiza tags existentes del usuario
2. Si encuentra palabras españolas (comida, transporte) → "es"
3. Si no, analiza texto de descripción
4. Si encuentra palabras españolas → "es"
5. Default: "en"

### **Ejemplos de Detección**
```
Usuario con tags ["Comida", "Transporte"]
detectLanguage("Pizza", [...]) → "es" ✓

Usuario con tags ["Food", "Transport"]
detectLanguage("Pizza", [...]) → "en" ✓

Usuario nuevo, sin tags
detectLanguage("Pizza en restaurante", []) → "es" ✓ (por la descripción)
detectLanguage("Pizza at restaurant", []) → "en" ✓ (por la descripción)
```

---

## 📊 Optimización de Tokens

### **buildTagPrompt()**
- **Antes:** 350 tokens
- **Después:** 160 tokens
- **Ahorro:** -54%

### **buildVoiceMovementPrompt()**
- **Antes:** 280 tokens
- **Después:** 190 tokens
- **Ahorro:** -32%

### **Total Promedio**
- **Antes:** 315 tokens/request
- **Después:** 175 tokens/request
- **Ahorro:** -44%

---

## 🔍 Lógica de Extracción

### **En buildVoiceMovementPrompt()**

**¿Cómo extrae el monto?**
```
Entrada: "Gasté 50 mil en comida"
Búsqueda: Número + unidad (mil, million, k)
Extracción: 50 × 1000 = 50000
```

**¿Cómo detecta tipo?**
```
Entrada: "Gasté 50 mil" → "expense"
Entrada: "Recibí 50 mil" → "income"
Default: "expense"
```

**¿Cómo detecta método de pago?**
```
Si menciona: "efectivo", "plata", "billetes" → "cash"
Si menciona: "tarjeta", "nequi", "banco", "transferencia" → "digital"
Default: "digital"
```

**¿Cómo sugiere etiqueta?**
```
1. Busca coincidencia 90%+ con tags existentes
2. Si encontró, retorna ese tag
3. Si no encontró, crea uno nuevo (1 palabra)
4. Si hay mención de meta de ahorro, retorna "💰 Meta: X"
```

**¿Cómo detecta factura?**
```
Si menciona: "factura", "rut", "electrónica", "soporte" → true
Else → false
```

---

## ✅ Validación y Normalización

Ambos prompts pasan por `normalizeMovementSuggestion()`:

```php
protected function normalizeMovementSuggestion(string $rawResponse): array
{
    // 1. Parse JSON
    $data = json_decode($rawResponse, true);
    
    // 2. Fallback si falla: extract JSON from text
    if (json_error) {
        $data = extractJson($rawResponse);
    }
    
    // 3. Validar y normalizar cada campo
    return [
        'description' => $data['description'] ?? 'Movimiento',
        'amount' => (float) ($data['amount'] ?? 0),
        'suggested_tag' => normalizeTag($data),
        'type' => validate_type($data['type']),
        'payment_method' => validate_payment($data['payment_method']),
        'has_invoice' => (bool) ($data['has_invoice'] ?? false),
    ];
}
```

---

## 📝 Resumen de Cambios

### **v2.0.0 → v2.1.0**
- ✅ Dos métodos claramente separados:
  - `buildTagPrompt()` - Solo etiquetas
  - `buildVoiceMovementPrompt()` - Contexto completo
- ✅ Propósitos claramente documentados
- ✅ Prompt de audio optimizado para extracción
- ✅ 190 tokens para audio (-32%)
- ✅ Documentación clara de diferencias

### **v2.1.0 → v2.1.1 (Corrección de Prompts)**
- ✅ **buildTagPrompt()**: Prompts más agresivos
  - Prefijo "TASK:" más claro
  - "Output ONLY the tag name" repetido
  - "Answer:" fuerza respuesta directa
  - Limpieza de respuesta mejorada (elimina prefijos de la IA)

- ✅ **buildVoiceMovementPrompt()**: Prompts más explícitos
  - "TASK:" al inicio
  - "Return ONLY valid JSON. No explanation." claro
  - Estructura "EXTRACT these fields:" mejorada
  - "Return ONLY this JSON, nothing else" refuerza formato

- ✅ **Limpieza de respuesta**: Ahora elimina frases comunes que Groq agrega
  - "Based on the rules, i will categorize..."
  - "Following the rules..."
  - "The category is..."
  - Extrae SOLO la primera palabra (el nombre de la etiqueta)

- ✅ **100% backward compatible**: Sin breaking changes

---

## 🎯 Integración Frontend

### **Llamar a Tag Suggestion**
```javascript
// Para sugerir solo una etiqueta
const response = await fetch('/api/tags/suggestion', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: JSON.stringify({
    descripcion: "Pizza",
    monto: 35000
  })
});

const { data } = await response.json();
console.log(data.tag); // "Comida"
```

### **Llamar a Voice Movement Suggestion**
```javascript
// Para extraer contexto completo desde audio
const response = await fetch('/api/movements/sugerir-voz', {
  method: 'POST',
  headers: { 'Authorization': `Bearer ${token}` },
  body: JSON.stringify({
    transcripcion: "Gasté 50 mil en comida con tarjeta"
  })
});

const { movement_suggestion } = await response.json();
console.log(movement_suggestion);
// {
//   amount: 50000,
//   description: "Comida",
//   type: "expense",
//   payment_method: "digital",
//   suggested_tag: "Comida",
//   has_invoice: false
// }
```

---

## 🔗 Referencias en Código

**Métodos del servicio:**
- `app/Services/MovementAIService.php:53` - `suggestTag()`
- `app/Services/MovementAIService.php:29` - `suggestFromVoice()`
- `app/Services/MovementAIService.php:142` - `buildTagPrompt()`
- `app/Services/MovementAIService.php:110` - `buildVoiceMovementPrompt()`
- `app/Services/MovementAIService.php:219` - `normalizeMovementSuggestion()`

**Controllers:**
- `app/Http/Controllers/Shared/TagController.php:103` - `suggest()` (usa buildTagPrompt)
- `app/Http/Controllers/Finance/MovementController.php:113` - `suggestFromVoice()` (usa buildVoiceMovementPrompt)

**Requests (Validación):**
- `app/Http/Requests/Tag/TagSuggestionRequest.php` - Validar entrada de tags
- `app/Http/Requests/Movement/VoiceSuggestionRequest.php` - Validar entrada de voz

---

## 📌 Notas Importantes

1. **Propósitos Separados:** No confundir los dos endpoints
   - Tags = Solo sugerir categoría
   - Voice = Extraer contexto completo

2. **Multiidioma:** Ambos detectan automáticamente, no necesita configuración del usuario

3. **Optimización:** buildVoiceMovementPrompt() es 32% más eficiente en tokens

4. **Contexto Completo:** El prompt de audio envía 6 campos, no solo la etiqueta

5. **Formato JSON:** El prompt de audio fuerza respuesta JSON en Groq API

---

**Versión:** 2.1.0  
**Estado:** ✅ Producción  
**Última actualización:** Enero 25, 2026
