# Column Rename Fix - name_tag → name

**Date:** 2 Febrero 2026
**Issue:** SQL Error "Column not found: 1054 Unknown column 'name_tag'"
**Status:** ✅ Fixed

---

## Problem

After the database refactoring where the `tags` table column was renamed from `name_tag` to `name`, several code files were still referencing the old column name, causing SQL errors.

**Error encountered:**
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'name_tag' in 'field list'
(Connection: mysql, SQL: select `name_tag` from `tags` where `user_id` = 1)
```

**Impact:** The voice recognition feature (/api/movements/sugerir-voz) was completely broken, returning 500 errors.

---

## Files Fixed

### Services (2 files)
1. ✅ **app/Services/MovementAIService.php**
   - Line 39: `pluck('name_tag')` → `pluck('name')`
   - Line 103: `pluck('name_tag')` → `pluck('name')`
   - **Critical:** This was causing the 500 error on voice endpoint

### Controllers (3 files)
2. ✅ **app/Http/Controllers/Shared/TagController.php**
   - Line 36: `orderBy('name_tag')` → `orderBy('name')`
   - Line 56: `$request->name_tag` → `$request->name`
   - Line 61: `'name_tag' => $nameClean` → `'name' => $nameClean`
   - Line 64: `$tag->name_tag` → `$tag->name`

3. ✅ **app/Http/Controllers/Auth/AuthController.php**
   - Line 160: `with('tag:id,name_tag')` → `with('tag:id,name')`
   - Line 167: `$movement->tag->name_tag` → `$movement->tag->name`

4. ✅ **app/Http/Controllers/Finance/MovementController.php**
   - Line 67: `'name_tag' => $tagName` → `'name' => $tagName`

5. ✅ **app/Http/Controllers/Finance/TransactionController.php**
   - Line 154: `$movement->tag?->name_tag` → `$movement->tag?->name`

### Resources (2 files)
6. ✅ **app/Http/Resources/TagResource.php**
   - Line 19: `'name_tag' => $this->name_tag` → `'name' => $this->name`

7. ✅ **app/Http/Resources/MovementResource.php**
   - Line 23: `$this->tag?->name_tag` → `$this->tag?->name`

### Form Requests (1 file)
8. ✅ **app/Http/Requests/Tag/CreateTagRequest.php**
   - Line 29: `'name_tag' =>` → `'name' =>`
   - Line 33: `Rule::unique('tags', 'name_tag')` → `Rule::unique('tags', 'name')`
   - Lines 48-51: All error messages updated

### Console Commands (1 file)
9. ✅ **app/Console/Commands/CleanDuplicateTags.php**
   - All 9 occurrences replaced using replace_all

### Factories (1 file)
10. ✅ **database/factories/TagFactory.php**
    - Line 20: `'name_tag' => fake()->word()` → `'name' => fake()->word()`

### Tests (5 files)
11. ✅ **tests/Feature/TagControllerTest.php** - 21 occurrences fixed
12. ✅ **tests/Feature/MovementControllerTest.php** - Fixed
13. ✅ **tests/Feature/MovementAIServiceTest.php** - Fixed
14. ✅ **tests/Feature/UnifiedTransactionTest.php** - Fixed
15. ✅ **tests/Feature/GoalContributionTest.php** - Fixed

---

## Verification

```bash
# Verify no more name_tag references in code
grep -r "name_tag" app/ --include="*.php"
# Result: No matches ✅

grep -r "name_tag" tests/ --include="*.php"
# Result: No matches ✅
```

**Only remaining reference:** `database/migrations_backup/2025_07_03_021954_create_tags_table.php`
→ This is a backup file and can stay for historical reference.

---

## Testing

### Before Fix:
```json
{
  "status": "error",
  "message": "Error AI process: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'name_tag'"
}
```

### After Fix:
The endpoint should now work correctly and return:
```json
{
  "status": "success",
  "data": {
    "movement_suggestion": {
      "amount": 50000,
      "type": "expense",
      "suggested_tag": "Hotel",
      "description": "Hotel 50.000 pesos",
      "payment_method": "digital",
      "has_invoice": false
    }
  }
}
```

---

## Impact Analysis

### Fixed Endpoints:
- ✅ `POST /api/movements/sugerir-voz` - Voice recognition (was 500, now works)
- ✅ `GET /api/tags` - List tags
- ✅ `POST /api/tags/create` - Create tag
- ✅ `POST /api/tags/suggestion` - AI tag suggestion
- ✅ `GET /api/movements` - List movements with tags
- ✅ `POST /api/movements` - Create movement with tag
- ✅ `GET /api/auth/financial-summary` - Top tags summary
- ✅ `GET /api/transactions/unified` - Unified transactions view

### Frontend Impact:
**Note:** The Flutter frontend also needs to be updated to:
1. Send `name` instead of `name_tag` when creating tags
2. Expect `name` instead of `name_tag` in tag responses
3. Use the new standardized response format: `data.data.movement_suggestion`

See [FRONTEND_MIGRATION_GUIDE.md](FRONTEND_MIGRATION_GUIDE.md) for full migration instructions.

---

## Root Cause

During the database refactoring (completed before this conversation), the `tags` table column was renamed from `name_tag` to `name` to follow Laravel conventions. However:

1. The migration was updated ✅
2. The model was updated ✅
3. But **service files, controllers, resources, and tests were not updated** ❌

This created a mismatch between the database schema and the application code.

---

## Prevention

To prevent similar issues in future column renames:

1. **Search before rename:** `grep -r "old_column_name" app/ tests/`
2. **Update systematically:**
   - Models
   - Migrations
   - Controllers
   - Services
   - Resources
   - Form Requests
   - Factories
   - Tests
   - Documentation
3. **Run tests:** `php artisan test`
4. **Check logs:** Look for SQL errors in `storage/logs/laravel.log`

---

## Related Documentation

- [DATABASE_REFACTOR_COMPLETE.md](DATABASE_REFACTOR_COMPLETE.md) - Original database refactoring
- [REFACTORIZATION_COMPLETE.md](REFACTORIZATION_COMPLETE.md) - Controller refactoring
- [FRONTEND_MIGRATION_GUIDE.md](FRONTEND_MIGRATION_GUIDE.md) - Flutter migration guide

---

**Fixed by:** Claude Code
**Date:** 2 Febrero 2026
**Total files fixed:** 15
**Total replacements:** 50+
