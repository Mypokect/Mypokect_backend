# ✅ Checklist de Instalación del Sistema de Presupuestos

## Pre-requisitos
- [ ] PHP 8.1+
- [ ] Laravel 11+
- [ ] MySQL 8+ o PostgreSQL 13+
- [ ] Composer instalado
- [ ] Cuenta en Groq.com con GROQ_API_KEY

---

## Paso 1: Configuración de Entorno

- [ ] Agregar a `.env`:
  ```
  GROQ_API_KEY=gsk_your_key_here
  ```

- [ ] Verificar que `.env` tenga configurados:
  ```
  DB_CONNECTION=mysql
  DB_HOST=127.0.0.1
  DB_PORT=3306
  DB_DATABASE=your_database
  DB_USERNAME=root
  DB_PASSWORD=
  ```

---

## Paso 2: Verificar Archivos

- [ ] Modelos existen:
  - [ ] `app/Models/Budget.php`
  - [ ] `app/Models/BudgetCategory.php`

- [ ] Servicio existe:
  - [ ] `app/Services/BudgetAIService.php`

- [ ] Controlador existe:
  - [ ] `app/Http/Controllers/SmartBudgetController.php`

- [ ] Migraciones existen:
  - [ ] `database/migrations/2025_12_12_000001_create_budgets_table.php`
  - [ ] `database/migrations/2025_12_12_000002_create_budget_categories_table.php`

- [ ] Factories existen:
  - [ ] `database/factories/BudgetFactory.php`
  - [ ] `database/factories/BudgetCategoryFactory.php`

- [ ] Rutas configuradas en:
  - [ ] `routes/api.php` (11 endpoints)

- [ ] Tests existen:
  - [ ] `tests/Feature/BudgetSystemTest.php`

---

## Paso 3: Instalar Dependencias

```bash
[ ] composer install
[ ] npm install (si es necesario)
[ ] php artisan cache:clear
[ ] php artisan config:clear
```

---

## Paso 4: Ejecutar Migraciones

```bash
[ ] php artisan migrate

# Verificar que se crearon las tablas:
[ ] php artisan tinker
    > Budget::all();
    > BudgetCategory::all();
```

---

## Paso 5: Ejecutar Tests

```bash
[ ] php artisan test tests/Feature/BudgetSystemTest.php

# Debe mostrar: ✅ PASSED
```

---

## Paso 6: Verificar Rutas

```bash
[ ] php artisan route:list | grep budgets

# Debe mostrar 11 rutas:
  - GET    /api/budgets
  - GET    /api/budgets/{budget}
  - POST   /api/budgets/manual
  - POST   /api/budgets/ai/generate
  - POST   /api/budgets/ai/save
  - PUT    /api/budgets/{budget}
  - DELETE /api/budgets/{budget}
  - POST   /api/budgets/{budget}/validate
  - POST   /api/budgets/{budget}/categories
  - PUT    /api/budgets/{budget}/categories/{category}
  - DELETE /api/budgets/{budget}/categories/{category}
```

---

## Paso 7: Iniciar Servidor

```bash
[ ] php artisan serve

# Debe mostrar:
# Laravel development server started on http://127.0.0.1:8000
```

---

## Paso 8: Probar Endpoints

### Test 1: Obtener token (si es necesario)
```bash
[ ] curl -X POST http://localhost:8000/api/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test User",
    "email": "test@example.com",
    "password": "password",
    "password_confirmation": "password"
  }'

# Guardar el token devuelto
```

### Test 2: Crear presupuesto manual
```bash
[ ] curl -X POST http://localhost:8000/api/budgets/manual \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Budget",
    "description": "Test Description",
    "total_amount": 1000,
    "categories": [
      {"name": "Category 1", "amount": 500},
      {"name": "Category 2", "amount": 500}
    ]
  }'

# Debe devolver status 201 con datos del budget
```

### Test 3: Listar presupuestos
```bash
[ ] curl -X GET "http://localhost:8000/api/budgets?status=active" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Debe devolver lista de presupuestos
```

### Test 4: Generar sugerencias de IA
```bash
[ ] curl -X POST http://localhost:8000/api/budgets/ai/generate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Viaje a Perú",
    "description": "Vacaciones de verano 2025",
    "total_amount": 2000
  }'

# Debe devolver sugerencias de IA
```

---

## Paso 9: Verificar Integridad

```bash
[ ] bash verify-budget-system.sh

# Debe mostrar: ✅ VERIFICACIÓN EXITOSA
```

---

## Paso 10: Documentación

- [ ] Leer `IMPLEMENTATION_SUMMARY.md`
- [ ] Leer `BUDGET_SYSTEM_GUIDE.md` (para detalles técnicos)
- [ ] Leer `README_PRESUPUESTOS.md` (para guía en español)
- [ ] Revisar `BUDGET_API_EXAMPLES.json` (para ejemplos en Postman)
- [ ] Revisar `FLUTTER_INTEGRATION.md` (para integración con Flutter)

---

## Paso 11: Configuración Adicional (Opcional)

### Si quieres usar Postman:
- [ ] Importar `BUDGET_API_EXAMPLES.json` en Postman
- [ ] Configurar variables:
  - [ ] `BASE_URL` = `http://localhost:8000`
  - [ ] `TOKEN` = tu token de autenticación

### Para desarrollo:
- [ ] Instalar Laravel Debugbar (opcional):
  ```bash
  composer require barryvdh/laravel-debugbar --dev
  ```

- [ ] Instalar Laravel IDE Helper (opcional):
  ```bash
  composer require --dev barryvdh/laravel-ide-helper
  php artisan ide-helper:generate
  ```

---

## Paso 12: Monitoreo

```bash
# Ver logs en tiempo real:
[ ] tail -f storage/logs/laravel.log

# Monitorear requests a Groq:
# Los logs mostrarán algo como:
# INFO Calling Groq API - model: llama-3.1-8b-instant
```

---

## Troubleshooting

### Error: GROQ_API_KEY not found
**Solución:**
```bash
[ ] Agregar GROQ_API_KEY a .env
[ ] Ejecutar: php artisan config:cache
```

### Error: "Sum of categories doesn't match total"
**Solución:**
```bash
[ ] Verificar que suma exacta sea igual a total_amount
[ ] Tolerancia: 0.01 por redondeo decimal
[ ] Ejemplo: Total 100, categorías 33.33 + 33.33 + 33.34 = 100 ✅
```

### Error: "Table 'budgets' doesn't exist"
**Solución:**
```bash
[ ] Ejecutar: php artisan migrate
[ ] Verificar conexión a base de datos en .env
```

### Error: 401 Unauthorized
**Solución:**
```bash
[ ] Verificar que estés pasando token válido en header
[ ] Header correcto: Authorization: Bearer {token}
```

### Error: 403 Forbidden
**Solución:**
```bash
[ ] Verificar que el usuario sea dueño del presupuesto
[ ] Cada usuario solo puede ver sus propios presupuestos
```

---

## Validación Final

- [ ] Todos los tests pasan ✅
- [ ] Rutas están disponibles ✅
- [ ] Modelos creados en BD ✅
- [ ] GROQ_API_KEY configurada ✅
- [ ] Endpoints responden correctamente ✅
- [ ] Documentación revisada ✅

---

## 🎉 ¡Listo para Usar!

Si todos los checks están marcados, el sistema está completamente configurado y listo para:
- ✅ Desarrollo local
- ✅ Testing
- ✅ Despliegue a producción
- ✅ Integración con Flutter

---

## Notas Importantes

1. **Groq API Key**: Obtén una gratis en https://console.groq.com
2. **Rate Limiting**: El endpoint de IA está limitado a 10 req/min
3. **Validación**: La suma de categorías DEBE ser exactamente igual a total_amount
4. **Seguridad**: Todos los usuarios solo ven sus propios presupuestos
5. **Idiomas**: El sistema detecta automáticamente español e inglés

---

**Última actualización:** 2025-12-12  
**Status:** ✅ Listo para usar
