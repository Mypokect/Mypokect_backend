# 💰 API de Finanzas - Sistema de Presupuestos Inteligentes

## 🌟 Última Actualización: Sistema de Presupuestos Completo

Se ha implementado un **sistema dual de presupuestos con IA** que permite a los usuarios:

### ✅ MODO 1: Presupuesto Manual
- Crear presupuestos desde cero
- Definir categorías libremente
- Editar y eliminar categorías
- Validación automática de suma

### ✅ MODO 2: Presupuesto con IA
- Obtener sugerencias inteligentes de la IA
- **El usuario siempre controla**: puede editar todas las sugerencias
- Detección automática de idioma (Español/Inglés)
- Clasificación automática del tipo de plan
- Integración con Groq AI

---

## 🚀 Inicio Rápido

### 1. Configurar Groq API
```bash
# Agregar a .env
GROQ_API_KEY=gsk_your_key_here

# Obtén una clave gratuita en https://console.groq.com
```

### 2. Ejecutar migraciones
```bash
php artisan migrate
```

### 3. Iniciar servidor
```bash
php artisan serve
```

### 4. Probar endpoints
```bash
# Crear presupuesto manual
curl -X POST http://localhost:8000/api/budgets/manual \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Viaje a Machu Picchu",
    "total_amount": 2000,
    "categories": [
      {"name": "Vuelos", "amount": 800},
      {"name": "Hotel", "amount": 600},
      {"name": "Comida", "amount": 400},
      {"name": "Tours", "amount": 200}
    ]
  }'
```

---

## 📁 Archivos del Sistema

### Lógica del Negocio
- `app/Models/Budget.php` - Modelo de presupuesto
- `app/Models/BudgetCategory.php` - Modelo de categoría
- `app/Services/BudgetAIService.php` - Servicio de IA
- `app/Http/Controllers/SmartBudgetController.php` - Controlador CRUD

### Base de Datos
- `database/migrations/2025_12_12_000001_create_budgets_table.php`
- `database/migrations/2025_12_12_000002_create_budget_categories_table.php`
- `database/factories/BudgetFactory.php`
- `database/factories/BudgetCategoryFactory.php`

### Rutas y Tests
- `routes/api.php` - 11 endpoints disponibles
- `tests/Feature/BudgetSystemTest.php` - 23 test cases

### Documentación
- **`IMPLEMENTATION_SUMMARY.md`** ⭐ - Resumen de implementación
- **`INSTALLATION_CHECKLIST.md`** ⭐ - Checklist de instalación
- `BUDGET_SYSTEM_GUIDE.md` - Guía técnica completa (inglés)
- `README_PRESUPUESTOS.md` - Documentación en español
- `BUDGET_API_EXAMPLES.json` - Ejemplos de Postman
- `FLUTTER_INTEGRATION.md` - Guía de integración Flutter

### Scripts
- `setup-budget-system.sh` - Script de instalación
- `verify-budget-system.sh` - Script de verificación

---

## 🎯 Endpoints Disponibles

```
PRESUPUESTOS
├── GET    /api/budgets                           - Listar todos
├── GET    /api/budgets/{id}                      - Ver uno
├── POST   /api/budgets/manual                    - Crear manual (MODO 1)
├── POST   /api/budgets/ai/generate               - Generar IA (MODO 2)
├── POST   /api/budgets/ai/save                   - Guardar IA (MODO 2)
├── PUT    /api/budgets/{id}                      - Actualizar
├── DELETE /api/budgets/{id}                      - Eliminar
└── POST   /api/budgets/{id}/validate             - Validar

CATEGORÍAS
├── POST   /api/budgets/{id}/categories           - Agregar
├── PUT    /api/budgets/{id}/categories/{cat_id}  - Editar
└── DELETE /api/budgets/{id}/categories/{cat_id}  - Eliminar
```

---

## 🤖 Cómo Funciona la IA

### 1. Detección de Idioma
Automáticamente detecta español e inglés basándose en palabras clave:
- **Español**: viaje, fiesta, cumpleaños, proyecto, compra
- **English**: travel, party, birthday, project, purchase

### 2. Clasificación de Plan
Clasifica automáticamente en 6 tipos:
- `travel` - Viajes y vacaciones
- `event` - Eventos corporativos
- `party` - Fiestas y celebraciones
- `purchase` - Compras de productos
- `project` - Proyectos y reformas
- `other` - Otro

### 3. Generación de Categorías
Sugiere categorías contextuales según el tipo:
- **Travel**: Vuelos, Alojamiento, Comida, Actividades, Imprevistos
- **Party**: Lugar, Catering, Decoración, Entretenimiento, Imprevistos
- **Project**: Materiales, Mano de obra, Herramientas, Imprevistos

### 4. Validación de Suma
Garantiza que suma de categorías = total_amount (exactamente)

---

## 💡 Ejemplos de Uso

### Ejemplo 1: Crear presupuesto manual
```bash
POST /api/budgets/manual
{
  "title": "Viaje a Machu Picchu",
  "description": "Vacaciones de verano",
  "total_amount": 2000,
  "categories": [
    {"name": "Vuelos", "amount": 800},
    {"name": "Alojamiento", "amount": 600},
    {"name": "Comida", "amount": 400},
    {"name": "Actividades", "amount": 200}
  ]
}
```

### Ejemplo 2: Generar sugerencias de IA
```bash
POST /api/budgets/ai/generate
{
  "title": "Fiesta de cumpleaños",
  "description": "50 personas en casa",
  "total_amount": 1500
}

# Sistema:
# 1. Detecta: language = "es"
# 2. Clasifica: plan_type = "party"
# 3. Genera sugerencias de categorías
# 4. Usuario revisa y edita
# 5. Usuario guarda con POST /api/budgets/ai/save
```

---

## 🧪 Testing

```bash
# Ejecutar todos los tests
php artisan test tests/Feature/BudgetSystemTest.php

# Ejecutar test específico
php artisan test tests/Feature/BudgetSystemTest.php::test_create_manual_budget

# Con verbosity
php artisan test tests/Feature/BudgetSystemTest.php -v
```

**23 test cases** cubriendo:
- ✅ CRUD completo
- ✅ Validaciones
- ✅ Seguridad (autenticación/autorización)
- ✅ Detección de idioma
- ✅ Clasificación de plan
- ✅ Casos de error

---

## 🔐 Seguridad

- ✅ Autenticación: Bearer token (Sanctum)
- ✅ Autorización: Users solo ven sus presupuestos
- ✅ Validación: Input validation completa
- ✅ Integridad: Validación de suma de categorías
- ✅ Rate Limiting: 10 req/min en endpoints de IA
- ✅ HTTPS: Recomendado en producción

---

## 📱 Integración Flutter

Vea `FLUTTER_INTEGRATION.md` para:
- Modelos Dart completos
- Servicios HTTP
- Pantallas de ejemplo
- Mejores prácticas

---

## 📋 Documentación Disponible

| Archivo | Descripción | Audiencia |
|---------|-------------|-----------|
| **IMPLEMENTATION_SUMMARY.md** | Resumen completo | Todos |
| **INSTALLATION_CHECKLIST.md** | Pasos de instalación | DevOps/Developers |
| **BUDGET_SYSTEM_GUIDE.md** | Guía técnica (EN) | Developers |
| **README_PRESUPUESTOS.md** | Guía técnica (ES) | Developers/Users |
| **BUDGET_API_EXAMPLES.json** | Ejemplos Postman | Testers |
| **FLUTTER_INTEGRATION.md** | Integración Flutter | Mobile Developers |

---

## ✨ Características Destacadas

### Para Usuarios
✅ Presupuestos manuales OR con IA  
✅ Editar todas las sugerencias  
✅ Validación automática  
✅ Detección de idioma  
✅ Historial de presupuestos  

### Para Desarrolladores
✅ Código limpio y documentado  
✅ Tests unitarios completos  
✅ Service Layer pattern  
✅ Factories para testing  
✅ Ejemplos de Postman  

### Para Operaciones
✅ Migraciones versionadas  
✅ Error handling completo  
✅ Rate limiting  
✅ Security by default  
✅ Scripts de automatización  

---

## 🚨 Validaciones Importantes

### Suma de categorías
```
❌ Total: $100, Categorías: $90 + $20 = $110 → ERROR
✅ Total: $100, Categorías: $50 + $50 = $100 → OK
✅ Total: $100, Categorías: $33.33 + $33.33 + $33.34 = $100 → OK
```

### Límites
- Title: max 255 caracteres
- Description: max 2000 caracteres
- Category name: max 255 caracteres
- Min amount: $0.01

---

## 📊 Modelos de Base de Datos

### Tabla `budgets`
```sql
id, user_id (FK), title, description, total_amount,
mode (enum: manual/ai), language, plan_type,
status (enum: draft/active/archived), timestamps
```

### Tabla `budget_categories`
```sql
id, budget_id (FK), name, amount, percentage,
reason, order, timestamps
```

---

## 🎓 Próximas Mejoras

- [ ] Análisis de gastos vs presupuesto
- [ ] Alertas de exceso de gasto
- [ ] Compartir presupuestos
- [ ] Exportar a PDF/Excel
- [ ] Presupuestos recurrentes
- [ ] Gráficas de distribución

---

## 💬 Soporte

1. Consulta `IMPLEMENTATION_SUMMARY.md` para ver todo lo implementado
2. Consulta `INSTALLATION_CHECKLIST.md` para instalar
3. Consulta `BUDGET_SYSTEM_GUIDE.md` para detalles técnicos
4. Consulta `README_PRESUPUESTOS.md` para documentación en español

---

## 📈 Status

**✅ LISTO PARA PRODUCCIÓN**

- ✅ Todas las funcionalidades implementadas
- ✅ Tests completados y pasando
- ✅ Documentación completa
- ✅ Scripts de automatización incluidos
- ✅ Ejemplos de uso disponibles

---

## 🎉 ¿Qué Hacer Ahora?

1. **Leer**: `IMPLEMENTATION_SUMMARY.md`
2. **Seguir**: `INSTALLATION_CHECKLIST.md`
3. **Probar**: Los endpoints con los ejemplos
4. **Integrar**: Con tu frontend (Flutter/Web)

---

**Versión**: 1.0.0  
**Última actualización**: 2025-12-12  
**Mantenedor**: Sistema de Presupuestos API  
**Status**: ✅ Producción
