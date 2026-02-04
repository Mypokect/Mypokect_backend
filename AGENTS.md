# AGENTS.md - Guía para Agentes de IA

Este documento proporciona información esencial para agentes de IA que trabajan en este proyecto Laravel 12 Finance API.

---

## Comandos de Build, Lint y Test

### PHP/Laravel
- `composer test` - Ejecutar todos los tests PHPUnit
- `php artisan test` - Ejecutar todos los tests
- `php artisan test --filter testMethodName` - Ejecutar un test específico
- `php artisan test --filter TestClassName` - Ejecutar todos los tests de una clase
- `vendor/bin/pint` - Formatear código con Laravel Pint
- `vendor/bin/pint --test` - Verificar estilo sin modificar código
- `php artisan migrate` - Ejecutar migraciones de base de datos
- `php artisan serve` - Iniciar servidor de desarrollo
- `composer dev` - Iniciar entorno completo (server, queue, vite)

### Frontend
- `npm run dev` - Iniciar servidor de desarrollo Vite
- `npm run build` - Construir assets de producción

---

## Guías de Estilo de Código

### Formateo
- Usar **4 espacios** para indentación (no tabs)
- Laravel Pint 1.22.1 es el linter/formateador oficial
- Siempre ejecutar `vendor/bin/pint` antes de commitear
- Archivos deben usar encoding UTF-8 con finales de línea LF

### Estándares PHP
- **Versión PHP**: 8.2+ (tipado estricto preferido)
- **Tipos de retorno**: Declarar siempre tipos de retorno en métodos (`: JsonResponse`, `: array`, `: void`, `: bool`, `: string`, `: float`)
- **Declaraciones de tipo**: Usar propiedades tipadas en clases (`private BudgetAIService $aiService;`, `protected string $apiKey;`)
- **Strict mode**: Importar todos los tipos, evitar tipos mixed cuando sea posible

### Convenciones de Nombres
- **Clases**: PascalCase (ej., `SmartBudgetController`, `BudgetAIService`, `User`)
- **Métodos**: camelCase (ej., `getBudgets`, `createManualBudget`, `detectLanguage`)
- **Variables**: camelCase (ej., `$totalAmount`, `$budgetData`, `$categories`)
- **Tablas de BD**: snake_case (ej., `budgets`, `budget_categories`)
- **Constantes**: UPPER_SNAKE_CASE (raramente usado en este codebase)
- **Propiedades privadas**: snake_case o camelCase (ambos vistos: `$fillable` vs `$aiService`)

### Imports
- Orden: 1) Imports de Illuminate, 2) Paquetes externos, 3) Imports del namespace App
- Agrupar por namespace con líneas en blanco entre grupos
- Remover imports no usados
- Ejemplo:
  ```php
  use Illuminate\Http\Request;
  use Illuminate\Support\Facades\Validator;
  use Illuminate\Support\Facades\Auth;
  use App\Models\Budget;
  use App\Services\BudgetAIService;
  ```

### Modelos
- Usar `$fillable` para protección de mass-assignment
- Usar `$casts` para type casting (ej., `protected $casts = ['total_amount' => 'float'];`)
- Definir relaciones con tipos de retorno: `public function categories(): HasMany`
- Usar factory methods para datos de test: `Budget::factory()->for($user)->has(BudgetCategory::factory()->count(3))->create()`
- Agregar métodos helper para lógica de negocio (ej., `isValid()`, `getCategoriesTotal()`)

### Controladores
- Siempre retornar `JsonResponse` para endpoints de API
- Usar `response()->json([...], $statusCode)` para respuestas
- Incluir `'success' => true/false` en todas las respuestas
- Formato de error: `['error' => '...', 'message' => '...']` o `['error' => '...', 'messages' => [...]]`
- Verificación de autorización: `if ($budget->user_id !== Auth::id()) { return response()->json(['error' => 'Unauthorized'], 403); }`
- Envolver operaciones de base de datos en `DB::transaction()` para consistencia de datos

### Validación
- Usar Form Requests en lugar de `Validator::make()` en línea
- Retornar 422 para fallos de validación con errores estructurados
- Incluir reglas de validación como `required`, `string`, `numeric`, `min`, `max`, `array`
- Validar arrays anidados: `'categories.*.amount' => 'required|numeric|min:0.01'`

### Manejo de Errores
- Usar bloques try-catch para todas las llamadas a APIs externas y operaciones de BD
- Registrar errores: `Log::error('Contexto: ' . $e->getMessage());`
- Retornar códigos HTTP apropiados:
  - 200/201 para éxito
  - 400 para bad requests
  - 401 para unauthorized
  - 403 para forbidden
  - 422 para errores de validación
  - 500 para errores del servidor
- Usar tipos de excepción específicos: `\InvalidArgumentException`, `\Exception`

### Logging
- Usar `Log::info()` para eventos importantes y debugging
- Usar `Log::warning()` para fallos de validación
- Usar `Log::error()` para excepciones y fallos
- Incluir contexto: `Log::info("User {$user->id} is requesting budgets list.");`

### Migrations
- Usar Schema Builder con nombres de columnas descriptivos
- Agregar restricciones de foreign key: `->foreignId('user_id')->constrained()->onDelete('cascade')`
- Agregar índices para optimización de queries: `$table->index(['user_id', 'status']);`
- Usar tipos de columna apropiados: `decimal(12, 2)` para dinero, `string(255)` para nombres
- Incluir `$table->timestamps()` para created_at y updated_at

### Tests
- Usar PHPUnit con trait `use RefreshDatabase;` para tests de base de datos
- Nombres de métodos de test: `test_` + snake_case + descripción
- Patrón Arrange-Act-Assert
- Usar assertions: `assertStatus()`, `assertJsonPath()`, `assertDatabaseHas()`, `assertDatabaseCount()`
- Crear datos de test en método `setUp()` cuando se usan en múltiples tests
- Probar tanto escenarios de éxito como de fallo

### Services
- Mantener lógica de negocio en clases de servicio
- Inyectar dependencias vía constructor: `public function __construct(BudgetAIService $aiService)`
- Retornar arrays u objetos desde métodos de servicio
- Manejar llamadas a API externas (servicios de IA, requests HTTP) en servicios
- Usar inyección de dependencias para testing

### Base de Datos
- Usar SQLite `:memory:` para testing (configurado en phpunit.xml)
- Usar variable de entorno `DB_CONNECTION` para base de datos de producción
- Seeders deben usar `$this->call()` para llamar a otros seeders
- Usar métodos `factory()` para crear datos de test

### Rutas de API
- Agrupar rutas con middleware: `Route::middleware('auth:sanctum')->group(...)`
- Usar rate limiting: `->middleware('throttle:10,1')` (10 requests por minuto)
- Nombrar rutas de forma descriptiva y RESTful: `/api/budgets`, `/api/budgets/{budget}`
- Usar controladores de recursos cuando aplique: `Route::apiResource('scheduled-transactions', ScheduledTransactionController::class)`

### Documentación
- Agregar comentarios PHPDoc para métodos complejos: `/** * Obtener todos los presupuestos. */`
- Incluir documentación de parámetros y tipos de retorno para métodos no obvios
- Agregar comentarios en línea para explicaciones de lógica de negocio
- Usar español o inglés consistentemente dentro del mismo contexto

### Seguridad
- Nunca registrar datos sensibles (passwords, tokens)
- Usar Laravel Sanctum para autenticación de API
- Validar todos los inputs de usuario
- Usar bcrypt para passwords: `bcrypt('password')`
- Mantener archivos `.env` fuera del control de versiones

### Estructura de Archivos
- Controladores: `app/Http/Controllers/`
- Modelos: `app/Models/`
- Services: `app/Services/`
- Migrations: `database/migrations/`
- Factories: `database/factories/`
- Tests: `tests/Feature/` y `tests/Unit/`
- Rutas: `routes/api.php`
- Requests: `app/Http/Requests/`
- Resources: `app/Http/Resources/`

### Patrones Comunes
- **Validación de budget**: Asegurar que la suma de categorías igual el total con tolerancia de 0.01
- **Fallback de IA**: Implementar lógica de fallback cuando el servicio de IA falla
- **Manejo de transacciones**: Usar `DB::transaction()` para operaciones multi-tabla
- **Autorización**: Siempre verificar propiedad del usuario: `if ($resource->user_id !== Auth::id())`

### Testing de Features de IA
- Mock de respuestas de servicio de IA cuando sea posible para tests más rápidos y determinísticos
- Usar `Http::fake()` para mockear requests HTTP a APIs de IA externas
- Probar con llamadas a API reales solo cuando sea necesario (marcados como tests de integración)

---

## Configuración del Proyecto

### Base de Datos
- SQLite para testing (en memoria)
- MySQL/PostgreSQL para producción
- Configuración en `.env`

### Integración de IA
- Provider: Groq AI
- Modelos: Llama 3.1, Gemma 2, Mixtral
- API Key en `GROQ_API_KEY` en `.env`

### Autenticación
- Laravel Sanctum para API tokens
- Middleware `auth:sanctum` en rutas protegidas

### Rate Limiting
- Login/Register: 10 requests/minuto
- AI Generation: 10 requests/minuto

---

## Reglas Específicas del Proyecto

### Sistema de Presupuestos
- Budget tiene modos: manual, ai
- Cada budget debe tener categorías que sumen exactamente el total
- IA genera 3-7 categorías con auto-corrección
- Detección automática de idioma (ES/EN)
- Clasificación automática de tipo de plan

### Controladores por Dominio
- `app/Http/Controllers/Budget/` - Controladores de presupuestos
- `app/Http/Controllers/Finance/` - Controladores financieros
- `app/Http/Controllers/Shared/` - Controladores compartidos
- `app/Http/Controllers/Auth/` - Controladores de autenticación

### Validaciones
- Usar Form Requests para validación centralizada
- Validar autorización en método `authorize()` del Request
- Validar suma de categorías en método del Request
- Retornar 422 con errores estructurados

### API Resources
- Usar Resources para transformación de modelos a JSON
- Tipar todos los valores numéricos: `(float) $this->amount`
- Formatear fechas a ISO 8601: `$this->created_at->toIso8601String()`
- Usar `whenLoaded()` para relaciones condicionales

---

## Checklist Antes de Commitear

- [ ] Ejecutar `vendor/bin/pint` para formatear código
- [ ] Ejecutar `composer test` - todos los tests deben pasar
- [ ] Verificar que no haya imports no usados
- [ ] Verificar que las rutas funcionen correctamente
- [ ] Agregar tests para nuevas features
- [ ] Actualizar documentación si es necesario
- [ ] Revisar logs de Laravel para errores

---

**Versión**: 1.0.0
**Última actualización**: Enero 2026
**Laravel**: 12.0
**PHP**: 8.2+
