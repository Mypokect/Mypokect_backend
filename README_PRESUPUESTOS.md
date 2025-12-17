# 💰 Sistema de Presupuestos Inteligentes con IA

## 📋 Descripción General

Sistema **dual-mode completo** para gestión de presupuestos con soporte para:

### MODO 1: Creación Manual
- El usuario crea su presupuesto desde cero
- Define categorías libremente
- Validación automática: suma debe coincidir exactamente con el total
- Edición y eliminación flexible de categorías

### MODO 2: Asistencia de IA
- La IA **sugiere** categorías basadas en el contexto del plan
- El usuario **revisa y edita** todas las sugerencias
- **La IA NUNCA decide por el usuario** - todo es editable
- Detección automática de idioma (Español/Inglés)
- Clasificación automática del tipo de plan

---

## 🚀 Instalación Rápida

### Paso 1: Configurar variable de entorno

```bash
# En tu archivo .env
GROQ_API_KEY=tu_clave_api_de_groq
```

Obtén tu clave gratuita en: https://console.groq.com

### Paso 2: Ejecutar migraciones

```bash
php artisan migrate
```

Esto crea:
- Tabla `budgets` con campos: title, description, total_amount, mode, language, plan_type, status
- Tabla `budget_categories` con campos: name, amount, percentage, reason, order

### Paso 3: (Opcional) Ejecutar script de setup

```bash
bash setup-budget-system.sh
```

Este script:
- Ejecuta migraciones
- Verifica que todos los archivos estén en su lugar
- Limpia el caché de configuración
- Muestra rutas disponibles

---

## 📁 Estructura de Archivos

```
app/
├── Models/
│   ├── Budget.php                      # Modelo principal
│   └── BudgetCategory.php              # Categorías de presupuesto
├── Http/
│   └── Controllers/
│       └── SmartBudgetController.php   # Todas las operaciones CRUD
├── Services/
│   └── BudgetAIService.php             # Lógica de IA (Groq, idioma, clasificación)
└── Providers/
    └── AppServiceProvider.php          # Binding del servicio

database/
├── migrations/
│   ├── 2025_12_12_000001_create_budgets_table.php
│   └── 2025_12_12_000002_create_budget_categories_table.php
└── factories/
    ├── BudgetFactory.php
    └── BudgetCategoryFactory.php

routes/
└── api.php                             # Todas las rutas

tests/
└── Feature/
    └── BudgetSystemTest.php            # Suite de tests

DOCUMENTACIÓN:
├── BUDGET_SYSTEM_GUIDE.md              # Guía completa (este archivo)
├── BUDGET_API_EXAMPLES.json            # Ejemplos para Postman
├── setup-budget-system.sh              # Script de instalación
└── README_PRESUPUESTOS.md              # (Este archivo)
```

---

## 🔌 Endpoints Disponibles

### 📊 Consulta de Presupuestos

```bash
# Obtener todos los presupuestos del usuario
GET /api/budgets?status=active

# Obtener un presupuesto específico
GET /api/budgets/{id}
```

### ➕ Crear Presupuestos

```bash
# MODO 1: Crear presupuesto manual
POST /api/budgets/manual
Body:
{
  "title": "Viaje a Machu Picchu",
  "description": "Vacaciones 2025",
  "total_amount": 2000,
  "categories": [
    {"name": "Vuelos", "amount": 800},
    {"name": "Hotel", "amount": 600},
    {"name": "Comida", "amount": 400},
    {"name": "Tours", "amount": 200}
  ]
}

# MODO 2 - PASO 1: Generar sugerencias de IA
POST /api/budgets/ai/generate
Body:
{
  "title": "Fiesta de cumpleaños",
  "description": "50 personas en casa",
  "total_amount": 1500
}

# MODO 2 - PASO 2: Guardar presupuesto de IA (después de editar)
POST /api/budgets/ai/save
Body:
{
  "title": "Fiesta de cumpleaños",
  "total_amount": 1500,
  "categories": [...categorías editadas...]
}
```

### ✏️ Actualizar y Eliminar

```bash
# Actualizar presupuesto
PUT /api/budgets/{id}
Body: {"title": "...", "status": "active", "total_amount": 2500}

# Eliminar presupuesto y sus categorías
DELETE /api/budgets/{id}

# Validar presupuesto (check suma = total)
POST /api/budgets/{id}/validate
```

### 🏷️ Gestión de Categorías

```bash
# Agregar categoría
POST /api/budgets/{id}/categories
Body: {"name": "Seguros", "amount": 150, "reason": "Seguro de viaje"}

# Actualizar categoría
PUT /api/budgets/{id}/categories/{category_id}
Body: {"name": "...", "amount": 200}

# Eliminar categoría
DELETE /api/budgets/{id}/categories/{category_id}
```

---

## 🤖 Cómo Funciona la IA

### 1. **Detección de Idioma**

El sistema analiza el título y descripción en busca de palabras clave:

**Español (es):**
- viaje, vuelo, hotel, playa, vacaciones
- fiesta, cumpleaños, boda, evento, celebración
- compra, producto, tienda, online
- proyecto, reforma, construcción, obra
- dinero, presupuesto, plan

**Inglés (en):**
- travel, flight, hotel, beach, vacation
- party, birthday, wedding, event, celebration
- purchase, product, store, shopping
- project, renovation, construction, build
- money, budget, plan

### 2. **Clasificación del Plan**

Clasifica automáticamente en:
- `travel`: Para viajes y vacaciones
- `event`: Para eventos corporativos o conferencias
- `party`: Para fiestas y celebraciones
- `purchase`: Para compras de productos
- `project`: Para proyectos o reformas
- `other`: Clasificación por defecto

### 3. **Generación de Categorías**

Basada en el tipo de plan, sugiere categorías contextuales:

**Para VIAJE (travel):**
- Vuelos, Alojamiento, Comida, Actividades, Imprevistos

**Para FIESTA (party):**
- Lugar/Logística, Comida, Decoración, Entretenimiento, Imprevistos

**Para PROYECTO (project):**
- Materiales, Recursos humanos, Herramientas, Imprevistos

### 4. **Validación de Suma**

La IA garantiza que:
- Suma de categorías = total_amount (exactamente)
- Tolerancia: 0.01 por redondeo decimal
- Si hay pequeña diferencia, auto-ajusta la primera categoría

### 5. **Respuesta Bilingüe**

Si se detecta español:
```json
{
  "categories": [
    {
      "name": "Vuelos",
      "amount": 800,
      "reason": "Pasajes aéreos ida y vuelta"
    }
  ],
  "general_advice": "Procura reservar vuelos con antelación"
}
```

Si se detecta inglés:
```json
{
  "categories": [
    {
      "name": "Flights",
      "amount": 800,
      "reason": "Round-trip airfare"
    }
  ],
  "general_advice": "Try to book flights well in advance"
}
```

---

## 🧪 Testing

### Ejecutar tests

```bash
php artisan test tests/Feature/BudgetSystemTest.php

# O con verbosity
php artisan test tests/Feature/BudgetSystemTest.php -v

# O un test específico
php artisan test tests/Feature/BudgetSystemTest.php::test_create_manual_budget
```

### Tests incluidos

- ✅ Crear presupuesto manual
- ✅ Validar suma de categorías
- ✅ Generar sugerencias de IA
- ✅ Guardar presupuesto de IA
- ✅ Obtener todos los presupuestos
- ✅ Actualizar presupuesto
- ✅ Eliminar presupuesto
- ✅ Agregar categorías
- ✅ Actualizar categorías
- ✅ Eliminar categorías
- ✅ Validar presupuesto
- ✅ Detección de idioma (es/en)
- ✅ Clasificación de plan (travel/event/party/purchase/project/other)
- ✅ Seguridad: No acceder a presupuestos de otros usuarios

---

## 🔐 Seguridad

- ✅ **Autenticación:** Todos los endpoints requieren token Bearer (Sanctum)
- ✅ **Autorización:** Los usuarios solo pueden ver/editar sus propios presupuestos
- ✅ **Validación:** Integridad de datos, límites de cantidad de caracteres
- ✅ **Rate Limiting:** 10 requests/min para el endpoint de IA
- ✅ **HTTPS:** Recomendado en producción

---

## 📊 Ejemplos de Uso

### Ejemplo 1: Crear presupuesto manual para un viaje

```bash
curl -X POST http://localhost:8000/api/budgets/manual \
  -H "Authorization: Bearer token_aqui" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Viaje a Machu Picchu",
    "description": "Vacaciones de verano con la familia",
    "total_amount": 2000,
    "categories": [
      {"name": "Vuelos", "amount": 800, "reason": "Pasajes aéreos para 2"},
      {"name": "Alojamiento", "amount": 600, "reason": "3 noches en hotel"},
      {"name": "Comida", "amount": 400, "reason": "Desayunos y cenas"},
      {"name": "Actividades", "amount": 200, "reason": "Entrada a Machu Picchu"}
    ]
  }'
```

**Respuesta:**
```json
{
  "success": true,
  "message": "Budget created successfully",
  "data": {
    "id": 1,
    "title": "Viaje a Machu Picchu",
    "total_amount": 2000,
    "mode": "manual",
    "language": "es",
    "plan_type": "travel",
    "status": "draft",
    "categories": [...]
  },
  "is_valid": true
}
```

### Ejemplo 2: Usar IA para sugerir presupuesto de fiesta

```bash
# PASO 1: Generar sugerencias
curl -X POST http://localhost:8000/api/budgets/ai/generate \
  -H "Authorization: Bearer token_aqui" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Fiesta de cumpleaños de María",
    "description": "Cumpleaños número 30. Queremos una fiesta sorpresa con 50 personas",
    "total_amount": 1500
  }'
```

**Respuesta con sugerencias:**
```json
{
  "success": true,
  "data": {
    "title": "Fiesta de cumpleaños de María",
    "total_amount": 1500,
    "categories": [
      {"name": "Alquiler del lugar", "amount": 400, "reason": "Salón para 50 personas"},
      {"name": "Catering", "amount": 700, "reason": "Comida y bebidas"},
      {"name": "Decoración", "amount": 250, "reason": "Flores, globos, luces"},
      {"name": "Entretenimiento", "amount": 150, "reason": "DJ"}
    ],
    "language": "es",
    "plan_type": "party"
  }
}
```

```bash
# PASO 2: Usuario revisa y edita (en frontend), luego guarda
curl -X POST http://localhost:8000/api/budgets/ai/save \
  -H "Authorization: Bearer token_aqui" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Fiesta de cumpleaños de María",
    "total_amount": 1500,
    "language": "es",
    "plan_type": "party",
    "categories": [
      {"name": "Alquiler del lugar", "amount": 400},
      {"name": "Catering", "amount": 700},
      {"name": "Decoración", "amount": 200},
      {"name": "Entretenimiento", "amount": 200}
    ]
  }'
```

---

## 🎯 Flujos de Trabajo Recomendados

### Workflow 1: Usuario experto (quiere control total)
```
1. POST /api/budgets/manual
   ↓ (Usuario crea categorías)
2. PUT /api/budgets/{id} (cambiar status a 'active')
3. GET /api/budgets/{id} (ver detalles)
4. PUT /api/budgets/{id}/categories/{cat_id} (editar si es necesario)
5. DELETE /api/budgets/{id} (eliminar cuando termine)
```

### Workflow 2: Usuario que quiere ayuda de IA
```
1. POST /api/budgets/ai/generate
   ↓ (Ve sugerencias de IA)
2. POST /api/budgets/ai/save (con categorías editadas o no)
   ↓ (Se crea presupuesto en estado 'draft')
3. PUT /api/budgets/{id} (cambiar status a 'active')
4. POST /api/budgets/{id}/categories (agregar más si es necesario)
5. POST /api/budgets/{id}/validate (verificar antes de usar)
```

### Workflow 3: Usuario que quiere editar después de crear
```
1. POST /api/budgets/manual (crear presupuesto)
2. POST /api/budgets/{id}/categories (agregar categoria)
3. PUT /api/budgets/{id}/categories/{cat_id} (editar categoria)
4. DELETE /api/budgets/{id}/categories/{cat_id} (eliminar categoria)
5. POST /api/budgets/{id}/validate (verificar suma)
6. PUT /api/budgets/{id} (cambiar status a 'active')
```

---

## ⚙️ Configuración

### En `config/services.php` (ya configurado):

```php
'groq' => [
    'key' => env('GROQ_API_KEY'),
    'base_url' => 'https://api.groq.com/openai/v1',
],
```

### Variables de entorno necesarias:

```env
# Obligatorio
GROQ_API_KEY=gsk_...

# Opcionales (ya tienen valores por defecto)
GROQ_MODEL=llama-3.1-8b-instant
GROQ_TEMPERATURE=0.5
GROQ_MAX_TOKENS=2000
```

---

## 🚨 Validaciones Importantes

### 1. Suma de categorías
```
❌ Total: $100, Categorías: $90 + $20 = $110 → ERROR
✅ Total: $100, Categorías: $50 + $50 = $100 → OK
✅ Total: $100, Categorías: $33.33 + $33.33 + $33.34 = $100 → OK (con tolerancia)
```

### 2. Límites de cantidad de caracteres
- `title`: máximo 255 caracteres
- `description`: máximo 2000 caracteres
- `category name`: máximo 255 caracteres
- `reason`: máximo 500 caracteres

### 3. Límites de dinero
- `total_amount`: mínimo $0.01
- `category amount`: mínimo $0.01
- Las categorías no pueden exceder el total

### 4. Autenticación
- Todos los endpoints requieren header: `Authorization: Bearer {token}`
- Los usuarios NO pueden ver/editar presupuestos de otros

---

## 🔧 Troubleshooting

### Error: "GROQ_API_KEY not found"
```bash
# Verifica que .env tenga:
GROQ_API_KEY=tu_clave_aqui

# Luego ejecuta:
php artisan config:cache
```

### Error: "Sum of categories doesn't match total"
- Revisa que la suma exacta de categorías sea igual a total_amount
- Tolerancia: 0.01 para errores de redondeo
- Ejemplo válido: Total $100, categorías $33.33 + $33.33 + $33.34

### Error: "Unauthorized" (403)
- Verifica que uses el token correcto
- Verifica que sea presupuesto del usuario autenticado
- Cada usuario solo ve sus propios presupuestos

### Error: "Rate limit exceeded"
- Endpoint `/api/budgets/ai/generate` está limitado a 10 req/min
- Espera un minuto antes de intentar de nuevo

---

## 📈 Métricas y Monitoreo

### Ver logs:
```bash
tail -f storage/logs/laravel.log
```

### Monitorear llamadas a Groq:
```php
// En SmartBudgetController.php o BudgetAIService.php
Log::info('Calling Groq API', [
    'model' => $model,
    'tokens' => 2000,
    'temperature' => 0.5
]);
```

---

## 🚀 Próximas Mejoras Planeadas

- [ ] Análisis de gastos vs presupuesto
- [ ] Alertas cuando una categoría se excede
- [ ] Historial de versiones de presupuestos
- [ ] Comparar presupuestos vs gastos reales
- [ ] Compartir presupuestos entre usuarios
- [ ] Exportar a PDF/Excel
- [ ] Presupuestos recurrentes (mensual, anual)
- [ ] Presupuestos colaborativos
- [ ] Soporte para más idiomas (FR, DE, PT)
- [ ] Gráficas de distribución (pie charts, barras)

---

## 📞 Soporte

Para reportar bugs o sugerencias:
1. Revisa los tests en `tests/Feature/BudgetSystemTest.php`
2. Consulta `BUDGET_SYSTEM_GUIDE.md` para documentación detallada
3. Revisa los ejemplos en `BUDGET_API_EXAMPLES.json`

---

## 📝 Licencia

Este sistema es parte de la API de Finanzas y sigue la misma licencia del proyecto principal.

---

**Versión:** 1.0.0  
**Última actualización:** 2025-12-12  
**Status:** ✅ Producción
