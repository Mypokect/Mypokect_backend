# 📱 Guía de Migración Frontend - API Refactorizada

**Fecha:** 1 Febrero 2026
**Versión Backend:** 3.0.0

---

## 🎯 Resumen de Cambios

El backend ha sido completamente refactorizado con respuestas JSON **estandarizadas**. Todos los endpoints ahora siguen el mismo formato de respuesta.

---

## 📊 Nuevo Formato de Respuestas

### ✅ Respuestas Exitosas (200, 201)

```json
{
  "status": "success",
  "message": "Mensaje descriptivo (opcional)",
  "data": {
    // Los datos solicitados van aquí
  }
}
```

### ❌ Respuestas de Error (400, 401, 403, 404, 422, 500)

```json
{
  "status": "error",
  "message": "Descripción del error",
  "errors": {
    // Detalles adicionales (opcional, solo en errores de validación)
  }
}
```

---

## 🔧 Cambios por Endpoint

### 1. **Movimientos - Sugerencia por Voz**

**Endpoint:** `POST /api/movements/sugerir-voz`

**ANTES:**
```json
{
  "movement_suggestion": {
    "amount": 50000,
    "type": "expense",
    "tag_name": "Hotel",
    "description": "Hotel 50.000 pesos"
  }
}
```

**DESPUÉS:**
```json
{
  "status": "success",
  "data": {
    "movement_suggestion": {
      "amount": 50000,
      "type": "expense",
      "tag_name": "Hotel",
      "description": "Hotel 50.000 pesos"
    }
  }
}
```

**Código Flutter - ANTES:**
```dart
final response = await http.post(
  Uri.parse('$baseUrl/api/movements/sugerir-voz'),
  headers: headers,
  body: jsonEncode({'transcripcion': texto}),
);

if (response.statusCode == 200) {
  final data = jsonDecode(response.body);
  final suggestion = data['movement_suggestion']; // ❌ YA NO FUNCIONA
  return suggestion;
}
```

**Código Flutter - DESPUÉS:**
```dart
final response = await http.post(
  Uri.parse('$baseUrl/api/movements/sugerir-voz'),
  headers: headers,
  body: jsonEncode({'transcripcion': texto}),
);

if (response.statusCode == 200) {
  final data = jsonDecode(response.body);

  // ✅ NUEVO: Primero acceder a 'data'
  if (data['status'] == 'success' && data['data'] != null) {
    final suggestion = data['data']['movement_suggestion'];
    return suggestion;
  }
}

// ✅ NUEVO: Manejar errores
if (response.statusCode != 200) {
  final error = jsonDecode(response.body);
  throw Exception(error['message'] ?? 'Error desconocido');
}
```

---

### 2. **Presupuestos - Listar**

**Endpoint:** `GET /api/budgets`

**ANTES:**
```json
{
  "success": true,
  "data": [
    { "id": 1, "title": "Viaje", ... }
  ]
}
```

**DESPUÉS:**
```json
{
  "status": "success",
  "data": [
    { "id": 1, "title": "Viaje", ... }
  ]
}
```

**Código Flutter - ACTUALIZAR:**
```dart
// ANTES
if (data['success'] == true) { ... }

// DESPUÉS
if (data['status'] == 'success') { ... }
```

---

### 3. **Presupuestos - Crear Manual**

**Endpoint:** `POST /api/budgets/manual`

**DESPUÉS:**
```json
{
  "status": "success",
  "message": "Budget created successfully",
  "data": {
    "budget": {
      "id": 1,
      "title": "Viaje a Paris",
      "total_amount": "3000000.00",
      "categories": [...]
    },
    "is_valid": true
  }
}
```

**Código Flutter:**
```dart
final response = await http.post(
  Uri.parse('$baseUrl/api/budgets/manual'),
  headers: headers,
  body: jsonEncode({
    'title': title,
    'description': description,
    'total_amount': amount,
    'categories': categories,
  }),
);

if (response.statusCode == 201) {
  final data = jsonDecode(response.body);

  if (data['status'] == 'success') {
    final budget = data['data']['budget'];
    final isValid = data['data']['is_valid'];
    print('Presupuesto creado: ${budget['title']}');
    return budget;
  }
}
```

---

### 4. **Presupuestos - Generar con IA**

**Endpoint:** `POST /api/budgets/ai/generate`

**DESPUÉS:**
```json
{
  "status": "success",
  "message": "AI suggestions generated. Review and save as budget.",
  "data": {
    "title": "Viaje a Cartagena",
    "total_amount": 5000000,
    "categories": [
      {
        "name": "Transporte",
        "amount": 1500000,
        "percentage": 30,
        "reason": "Vuelos y taxis"
      }
    ],
    "general_advice": "Considera...",
    "language": "es",
    "plan_type": "travel",
    "note": "These are AI suggestions..."
  }
}
```

---

### 5. **Metas de Ahorro - Listar**

**Endpoint:** `GET /api/saving-goals`

**ANTES:**
```json
[
  {
    "id": 1,
    "name": "Vacaciones",
    "target_amount": "2000000",
    "saved_amount": 500000,
    "percentage": 25
  }
]
```

**DESPUÉS:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "name": "Vacaciones",
      "target_amount": "2000000",
      "saved_amount": 500000,
      "percentage": 25
    }
  ]
}
```

**Código Flutter:**
```dart
final response = await http.get(
  Uri.parse('$baseUrl/api/saving-goals'),
  headers: headers,
);

if (response.statusCode == 200) {
  final data = jsonDecode(response.body);

  // ✅ NUEVO: Acceder a data['data']
  if (data['status'] == 'success' && data['data'] != null) {
    final goals = (data['data'] as List)
        .map((json) => SavingGoal.fromJson(json))
        .toList();
    return goals;
  }
}
```

---

### 6. **Metas de Ahorro - Crear**

**Endpoint:** `POST /api/saving-goals`

**DESPUÉS:**
```json
{
  "status": "success",
  "message": "Saving goal created successfully",
  "data": {
    "saving_goal": {
      "id": 1,
      "name": "Casa nueva",
      "target_amount": "50000000",
      "saved_amount": 0,
      "percentage": 0
    }
  }
}
```

---

### 7. **Movimientos - Listar**

**Endpoint:** `GET /api/movements`

**DESPUÉS:**
```json
{
  "status": "success",
  "data": [
    {
      "id": 1,
      "type": "expense",
      "amount": "50000.00",
      "description": "Almuerzo",
      "tag": { "name": "Comida" }
    }
  ]
}
```

---

### 8. **Movimientos - Crear**

**Endpoint:** `POST /api/movements`

**DESPUÉS:**
```json
{
  "status": "success",
  "message": "Movimiento creado",
  "data": {
    "id": 1,
    "type": "expense",
    "amount": "50000.00",
    "description": "Hotel",
    "tag": { "name": "Viaje" }
  }
}
```

---

### 9. **Tags - Listar**

**Endpoint:** `GET /api/tags`

**DESPUÉS:**
```json
{
  "status": "success",
  "data": [
    { "id": 1, "name": "Comida" },
    { "id": 2, "name": "Transporte" }
  ]
}
```

---

### 10. **Contribuciones - Crear**

**Endpoint:** `POST /api/goal-contributions`

**DESPUÉS:**
```json
{
  "status": "success",
  "message": "Contribution created successfully",
  "data": {
    "id": 1,
    "goal_id": 5,
    "amount": "100000.00",
    "description": "Ahorro mensual"
  }
}
```

---

## 🛠️ Función Helper Recomendada (Flutter)

Crea una función helper para manejar todas las respuestas:

```dart
// lib/utils/api_helper.dart

class ApiResponse<T> {
  final bool success;
  final String? message;
  final T? data;
  final Map<String, dynamic>? errors;

  ApiResponse({
    required this.success,
    this.message,
    this.data,
    this.errors,
  });

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic)? fromJsonT,
  ) {
    final status = json['status'] as String?;
    final success = status == 'success';

    return ApiResponse(
      success: success,
      message: json['message'] as String?,
      data: success && json['data'] != null && fromJsonT != null
          ? fromJsonT(json['data'])
          : json['data'] as T?,
      errors: !success ? json['errors'] as Map<String, dynamic>? : null,
    );
  }
}

// Función helper para llamadas HTTP
Future<ApiResponse<T>> apiCall<T>({
  required Future<http.Response> Function() request,
  T Function(dynamic)? fromJson,
}) async {
  try {
    final response = await request();
    final jsonData = jsonDecode(response.body);

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return ApiResponse.fromJson(jsonData, fromJson);
    } else {
      return ApiResponse(
        success: false,
        message: jsonData['message'] ?? 'Error desconocido',
        errors: jsonData['errors'],
      );
    }
  } catch (e) {
    return ApiResponse(
      success: false,
      message: 'Error de conexión: $e',
    );
  }
}
```

---

## 📝 Ejemplo de Uso del Helper

```dart
// Ejemplo 1: Sugerencia por voz
Future<Map<String, dynamic>?> sugerirPorVoz(String texto) async {
  final result = await apiCall<Map<String, dynamic>>(
    request: () => http.post(
      Uri.parse('$baseUrl/api/movements/sugerir-voz'),
      headers: headers,
      body: jsonEncode({'transcripcion': texto}),
    ),
    fromJson: (data) => data['movement_suggestion'] as Map<String, dynamic>,
  );

  if (result.success) {
    return result.data;
  } else {
    print('Error: ${result.message}');
    return null;
  }
}

// Ejemplo 2: Listar presupuestos
Future<List<Budget>> listarPresupuestos() async {
  final result = await apiCall<List<Budget>>(
    request: () => http.get(
      Uri.parse('$baseUrl/api/budgets'),
      headers: headers,
    ),
    fromJson: (data) => (data as List)
        .map((json) => Budget.fromJson(json))
        .toList(),
  );

  if (result.success && result.data != null) {
    return result.data!;
  } else {
    throw Exception(result.message ?? 'Error al cargar presupuestos');
  }
}

// Ejemplo 3: Crear movimiento
Future<Movement?> crearMovimiento(MovementData data) async {
  final result = await apiCall<Movement>(
    request: () => http.post(
      Uri.parse('$baseUrl/api/movements'),
      headers: headers,
      body: jsonEncode(data.toJson()),
    ),
    fromJson: (data) => Movement.fromJson(data),
  );

  if (result.success) {
    print('Éxito: ${result.message}');
    return result.data;
  } else {
    print('Error: ${result.message}');
    if (result.errors != null) {
      print('Detalles: ${result.errors}');
    }
    return null;
  }
}
```

---

## ⚠️ Errores de Validación (422)

**Formato:**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": {
    "title": ["The title field is required."],
    "total_amount": ["The total amount must be at least 0.01."]
  }
}
```

**Manejo en Flutter:**
```dart
if (!result.success && result.errors != null) {
  // Mostrar errores de validación
  result.errors!.forEach((field, messages) {
    print('$field: ${messages.join(", ")}');
  });
}
```

---

## 🔍 Debugging

### Log de Request
```dart
print('📤 Request: $url');
print('📤 Body: ${jsonEncode(body)}');
```

### Log de Response
```dart
print('📥 Status: ${response.statusCode}');
print('📥 Body: ${response.body}');
```

### Verificar estructura de respuesta
```dart
final data = jsonDecode(response.body);
print('Keys: ${data.keys}'); // Debe incluir 'status', 'data'
print('Status: ${data['status']}'); // Debe ser 'success' o 'error'
```

---

## ✅ Checklist de Migración

- [ ] Actualizar servicio de movimientos (`sugerir-voz`, `store`, `index`)
- [ ] Actualizar servicio de presupuestos (`index`, `store`, `generateAI`, `saveAI`)
- [ ] Actualizar servicio de metas de ahorro (`index`, `store`, `show`)
- [ ] Actualizar servicio de tags (`index`, `store`, `suggest`)
- [ ] Actualizar servicio de contribuciones (`store`, `index`)
- [ ] Implementar función helper `ApiResponse`
- [ ] Actualizar manejo de errores en toda la app
- [ ] Probar todos los flujos críticos:
  - [ ] Login/Registro
  - [ ] Crear movimiento por voz
  - [ ] Crear presupuesto con IA
  - [ ] Crear meta de ahorro
  - [ ] Listar movimientos/presupuestos/metas

---

## 🐛 Solución al Error Actual

**Tu problema específico:** `🤖 Respuesta IA: null`

**Causa:** El código Flutter está buscando directamente `movement_suggestion` pero ahora está dentro de `data`.

**Solución:**

```dart
// ❌ ANTES (línea donde obtienes null)
final suggestion = jsonData['movement_suggestion'];

// ✅ DESPUÉS
final data = jsonDecode(response.body);

// Verificar que la respuesta es exitosa
if (data['status'] == 'success' && data['data'] != null) {
  final suggestion = data['data']['movement_suggestion'];
  print('✅ Sugerencia obtenida: $suggestion');
  return suggestion;
} else {
  print('❌ Error: ${data['message']}');
  return null;
}
```

---

## 📞 Endpoints Afectados (Todos)

Todos los endpoints ahora usan el formato estandarizado:

- ✅ `/api/movements` - GET, POST
- ✅ `/api/movements/sugerir-voz` - POST
- ✅ `/api/budgets` - GET, POST
- ✅ `/api/budgets/manual` - POST
- ✅ `/api/budgets/ai/generate` - POST
- ✅ `/api/budgets/ai/save` - POST
- ✅ `/api/budgets/{id}` - GET, PUT, DELETE
- ✅ `/api/saving-goals` - GET, POST
- ✅ `/api/saving-goals/{id}` - GET, PUT, DELETE
- ✅ `/api/goal-contributions` - POST
- ✅ `/api/goal-contributions/{goalId}` - GET
- ✅ `/api/tags` - GET, POST
- ✅ `/api/tags/suggest` - POST
- ✅ `/api/taxes/data` - GET
- ✅ `/api/taxes/check-limits` - GET

---

## 🎯 Resumen

1. **Todas las respuestas exitosas** tienen `status: "success"` y los datos en `data`
2. **Todas las respuestas de error** tienen `status: "error"` y descripción en `message`
3. **Actualiza tu código Flutter** para acceder primero a `data['data']`
4. **Implementa la función helper** `ApiResponse` para simplificar el código
5. **Prueba endpoint por endpoint** siguiendo el checklist

---

**Ejecutado por:** Claude Code
**Fecha:** 1 Febrero 2026
**Versión:** Frontend Migration Guide v1.0
