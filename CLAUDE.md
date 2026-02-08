# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Personal finance API built with Laravel 12 that integrates Groq AI for intelligent budget generation, voice-to-transaction parsing, and automatic categorization. The system manages movements (expenses/income), smart budgets, scheduled transactions, saving goals, and tax analysis.

## Development Commands

### Server & Development
```bash
# Start development server with queue listener and Vite
composer dev

# Start server only
php artisan serve

# Run queue worker (for background jobs)
php artisan queue:listen --tries=1
```

### Testing
```bash
# Run all tests
composer test

# Run specific test file
php artisan test tests/Feature/BudgetSystemTest.php

# Run specific test method
php artisan test --filter test_create_manual_budget

# Run test suite
php artisan test --testsuite=Feature
```

### Code Quality
```bash
# Format code with Laravel Pint
vendor/bin/pint

# Check code style without modifying
vendor/bin/pint --test
```

### Database
```bash
# Run migrations
php artisan migrate

# Rollback last migration
php artisan migrate:rollback

# Fresh migration with seeders
php artisan migrate:fresh --seed
```

### Caching (Production)
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Architecture Overview

### Controller Organization

Controllers are organized by domain into three main directories:

**`app/Http/Controllers/Auth/`**
- `AuthController` - Registration, login, and home data aggregation

**`app/Http/Controllers/Budget/`**
- `BudgetController` - Smart budgets with AI generation and manual creation modes

**`app/Http/Controllers/Finance/`**
- `MovementController` - Expenses and income with voice-to-movement AI
- `SavingGoalController` - Long-term savings goals
- `GoalContributionController` - Contributions to savings goals (separate from movements for tax compliance)
- `ScheduledTransactionController` - Recurring transactions with occurrence calculation
- `TransactionController` - Unified view combining movements and goal contributions
- `SavingsController` - 50/30/20 rule analysis
- `TaxController` - Tax radar and fiscal alerts for Colombian DIAN compliance

**`app/Http/Controllers/Shared/`**
- `TagController` - Tag CRUD and AI-powered tag suggestions

### Service Layer

**`app/Services/BudgetAIService.php`**
- Generates budget categories using Groq AI
- Auto-detects language (ES/EN) and plan type (travel, event, party, purchase, project, other)
- Validates category sums match total amount within tolerance
- Uses models: llama-3.1-8b-instant, gemma2-9b-it, llama3-8b-8192

**`app/Services/MovementAIService.php`**
- **TWO DISTINCT AI PROMPTS** - Do not confuse:
  1. `buildTagPrompt()` - Suggests ONE tag based on description + amount (endpoint: `/api/tags/suggestion`)
  2. `buildVoiceMovementPrompt()` - Extracts COMPLETE context from voice transcription (endpoint: `/api/movements/sugerir-voz`)
     - Extracts 6 fields: amount, description, type (expense/income), payment_method (cash/digital), suggested_tag, has_invoice
     - Converts verbal amounts (k, mil, millón → numbers)
     - Detects payment method and invoice mentions
- Both prompts auto-detect language (ES/EN)
- See `PROMPTS_DOCUMENTATION.md` for detailed prompt documentation

**`app/Services/OccurrenceCalculatorService.php`**
- Calculates next occurrence dates for scheduled transactions
- Handles daily, weekly, monthly, yearly frequencies

### Database Structure

**Core Entities:**
- `users` - User accounts with FCM tokens for notifications
- `movements` - Expenses and income with `payment_method` (cash/digital) and `has_invoice` flag
- `tags` - User-created categories with usage count for popularity
- `scheduled_transactions` - Recurring transactions with frequency rules
- `transaction_occurrences` - Calculated instances of scheduled transactions
- `budgets` - Smart budgets with mode (manual/ai), language (es/en), plan_type
- `budget_categories` - Individual budget line items with amounts and reasons
- `saving_goals` - Long-term savings targets
- `goal_contributions` - Separate table for goal contributions (NOT in movements) for tax/audit separation

**Key Design Decision:**
Goal contributions are stored in a separate `goal_contributions` table rather than mixing with `movements`. This ensures:
- Clear separation for tax authorities (DIAN in Colombia)
- Contributions don't have `payment_method` field (non-tributary)
- Clean audit trail
- Unified view provided via `/api/transactions/unified` endpoint

### API Request/Response Pattern

All protected routes use `auth:sanctum` middleware. Responses follow this format:

**Success:**
```json
{
  "message": "Action completed",
  "data": { ... },
  "pagination": { ... }  // if applicable
}
```

**Error:**
```json
{
  "error": "Error Type",
  "message": "Detailed message",
  "messages": { "field": ["error"] }  // validation errors
}
```

### AI Integration (Groq)

Configuration in `config/services.php`:
```php
'groq' => [
    'key' => env('GROQ_KEY'),
    'model' => env('GROQ_MODEL', 'llama3-8b-8192'),
]
```

The system uses the `openai-php/laravel` package configured to use Groq's API endpoint. Rate limiting applied to AI endpoints:
- Budget generation: 10 requests/minute
- Voice suggestions: 20 requests/minute
- Tag suggestions: 20 requests/minute

### Important Patterns

**Ownership Verification:**
All resource endpoints verify the authenticated user owns the resource being accessed. Never skip this check.

**Language Detection:**
The system auto-detects user language from existing tags or input text. Spanish is prioritized for Colombian users. Do not require explicit language selection.

**Budget Sum Validation:**
Budget categories must sum exactly to the total amount. AI-generated budgets have auto-correction within 5% tolerance. Manual budgets require exact sums.

**Scheduled Transaction Occurrences:**
- Occurrences are calculated when scheduled transaction is created/updated
- Command `SendPaymentReminders` processes upcoming occurrences
- Users can mark individual occurrences as paid without affecting the schedule

**Unified Transactions View:**
The `/api/transactions/unified` endpoint merges movements and goal contributions into a single timeline with:
- Pagination (10/50/100 per page)
- Filtering by type (expense/income/contribution), date range, goal_id
- Sorted chronologically (oldest first by default)

## Environment Setup

Required environment variables:
```
GROQ_KEY=gsk_your_api_key           # Required for AI features
GROQ_MODEL=llama3-8b-8192           # Optional, has default
DB_CONNECTION=mysql                  # mysql, pgsql, sqlite supported
QUEUE_CONNECTION=database            # Database queue for background jobs
```

## Testing Strategy

Tests use in-memory SQLite database (configured in `phpunit.xml`). All tests create factories for:
- Users
- Movements
- Tags
- Budgets and BudgetCategories
- SavingGoals and GoalContributions
- ScheduledTransactions

Key test files:
- `tests/Feature/BudgetSystemTest.php` - Budget creation, validation, AI generation
- `tests/Feature/MovementControllerTest.php` - Movement CRUD and voice suggestions
- `tests/Feature/GoalContributionTest.php` - Goal contributions and statistics
- `tests/Feature/UnifiedTransactionTest.php` - Mixed transaction views with filtering

## Common Development Scenarios

**Adding a new AI feature:**
1. Add method to appropriate service (`BudgetAIService` or `MovementAIService`)
2. Build prompt with clear task description and output format requirements
3. Add error handling for malformed AI responses
4. Apply rate limiting middleware to endpoint
5. Write tests with mocked AI responses

**Adding a new financial entity:**
1. Create migration with proper foreign keys and indexes
2. Create model with relationships (always `belongsTo(User::class)`)
3. Create factory for testing
4. Create controller in appropriate directory (Budget/Finance/Shared)
5. Add routes with `auth:sanctum` middleware
6. Write feature tests with ownership verification

**Modifying AI prompts:**
Prompts are carefully optimized for token usage and response accuracy. When modifying:
- Keep task description clear and directive (use "TASK:", "EXTRACT:", "ONLY")
- Include examples in prompt for consistency
- Test with both Spanish and English inputs
- Document token count changes in `PROMPTS_DOCUMENTATION.md`
- Never remove language auto-detection logic

## Deployment Notes

Before deploying:
1. Run `composer test` to ensure all tests pass
2. Run `vendor/bin/pint` to format code
3. Ensure `GROQ_KEY` is set in production environment
4. Run `php artisan migrate --force` on production database
5. Cache config and routes: `php artisan config:cache && php artisan route:cache`
6. Ensure queue worker is running: `php artisan queue:listen`

## Documentation References

- `README.md` - Installation and API endpoint overview
- `PROMPTS_DOCUMENTATION.md` - Detailed AI prompt documentation
- `IMPLEMENTATION_SUMMARY.md` - Goal contributions system architecture
- `API_QUICK_REFERENCE.md` - Quick curl examples for testing
- `docs/` - Additional documentation (API, budget system, troubleshooting, Flutter integration)
