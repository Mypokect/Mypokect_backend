#!/bin/bash

# Verification script for Budget System implementation
# Checks that all necessary files and configurations are in place

set -e

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}╔════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║  Sistema de Presupuestos - Verificación Final  ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════╝${NC}"
echo ""

# Counter for checks
TOTAL=0
PASSED=0
FAILED=0

# Function to check file existence
check_file() {
    local file=$1
    local description=$2
    TOTAL=$((TOTAL + 1))
    
    if [ -f "$file" ]; then
        echo -e "${GREEN}✅${NC} $description"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌${NC} $description (NOT FOUND: $file)"
        FAILED=$((FAILED + 1))
    fi
}

# Function to check directory existence
check_dir() {
    local dir=$1
    local description=$2
    TOTAL=$((TOTAL + 1))
    
    if [ -d "$dir" ]; then
        echo -e "${GREEN}✅${NC} $description"
        PASSED=$((PASSED + 1))
    else
        echo -e "${RED}❌${NC} $description (NOT FOUND: $dir)"
        FAILED=$((FAILED + 1))
    fi
}

# Function to check configuration
check_config() {
    local file=$1
    local key=$2
    local description=$3
    TOTAL=$((TOTAL + 1))
    
    if grep -q "$key" "$file" 2>/dev/null; then
        echo -e "${GREEN}✅${NC} $description"
        PASSED=$((PASSED + 1))
    else
        echo -e "${YELLOW}⚠️${NC}  $description (NOT FOUND: $key in $file)"
        FAILED=$((FAILED + 1))
    fi
}

echo -e "${BLUE}📁 Verificando Modelos...${NC}"
check_file "app/Models/Budget.php" "Budget Model"
check_file "app/Models/BudgetCategory.php" "BudgetCategory Model"
echo ""

echo -e "${BLUE}🤖 Verificando Servicios...${NC}"
check_file "app/Services/BudgetAIService.php" "BudgetAIService"
echo ""

echo -e "${BLUE}🎮 Verificando Controladores...${NC}"
check_file "app/Http/Controllers/SmartBudgetController.php" "SmartBudgetController"
echo ""

echo -e "${BLUE}💾 Verificando Migraciones...${NC}"
check_file "database/migrations/2025_12_12_000001_create_budgets_table.php" "Budgets Migration"
check_file "database/migrations/2025_12_12_000002_create_budget_categories_table.php" "Budget Categories Migration"
echo ""

echo -e "${BLUE}🏭 Verificando Factories...${NC}"
check_file "database/factories/BudgetFactory.php" "Budget Factory"
check_file "database/factories/BudgetCategoryFactory.php" "BudgetCategory Factory"
echo ""

echo -e "${BLUE}🧪 Verificando Tests...${NC}"
check_file "tests/Feature/BudgetSystemTest.php" "Budget System Tests"
echo ""

echo -e "${BLUE}📖 Verificando Documentación...${NC}"
check_file "BUDGET_SYSTEM_GUIDE.md" "Complete Budget System Guide"
check_file "README_PRESUPUESTOS.md" "Spanish README"
check_file "BUDGET_API_EXAMPLES.json" "API Examples (Postman)"
check_file "setup-budget-system.sh" "Setup Script"
echo ""

echo -e "${BLUE}🔧 Verificando Configuración...${NC}"
check_file "routes/api.php" "API Routes"
check_file ".env" "Environment Configuration"
check_config ".env" "GROQ_API_KEY" "Groq API Key in .env"
echo ""

echo -e "${BLUE}🛣️  Verificando Rutas Implementadas...${NC}"
if grep -q "GET.*\/api\/budgets" routes/api.php; then
    echo -e "${GREEN}✅${NC} Budget Routes Configured"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌${NC} Budget Routes NOT Configured"
    FAILED=$((FAILED + 1))
fi
TOTAL=$((TOTAL + 1))
echo ""

echo -e "${BLUE}📋 Verificando Estructura de Directorios...${NC}"
check_dir "app/Models" "Models Directory"
check_dir "app/Services" "Services Directory"
check_dir "app/Http/Controllers" "Controllers Directory"
check_dir "database/migrations" "Migrations Directory"
check_dir "database/factories" "Factories Directory"
check_dir "tests/Feature" "Tests Directory"
echo ""

echo -e "${BLUE}🔍 Verificando Contenido de Archivos Clave...${NC}"

# Check Budget model has required methods
if grep -q "getCategoriesTotal\|isValid\|categories" app/Models/Budget.php 2>/dev/null; then
    echo -e "${GREEN}✅${NC} Budget Model has required methods"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌${NC} Budget Model missing required methods"
    FAILED=$((FAILED + 1))
fi
TOTAL=$((TOTAL + 1))

# Check BudgetAIService has required methods
if grep -q "generateBudgetWithAI\|detectLanguage\|classifyPlanType" app/Services/BudgetAIService.php 2>/dev/null; then
    echo -e "${GREEN}✅${NC} BudgetAIService has required methods"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌${NC} BudgetAIService missing required methods"
    FAILED=$((FAILED + 1))
fi
TOTAL=$((TOTAL + 1))

# Check SmartBudgetController has CRUD operations
if grep -q "createManualBudget\|generateAIBudget\|saveAIBudget\|updateBudget\|deleteBudget" app/Http/Controllers/SmartBudgetController.php 2>/dev/null; then
    echo -e "${GREEN}✅${NC} SmartBudgetController has all CRUD operations"
    PASSED=$((PASSED + 1))
else
    echo -e "${RED}❌${NC} SmartBudgetController missing CRUD operations"
    FAILED=$((FAILED + 1))
fi
TOTAL=$((TOTAL + 1))

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo ""

if [ $FAILED -eq 0 ]; then
    echo -e "${GREEN}🎉 ¡VERIFICACIÓN EXITOSA!${NC}"
    echo -e "   Total checks: ${GREEN}${PASSED}/${TOTAL}${NC} ✅"
else
    echo -e "${YELLOW}⚠️  VERIFICACIÓN CON PROBLEMAS${NC}"
    echo -e "   Passed: ${GREEN}${PASSED}${NC}"
    echo -e "   Failed: ${RED}${FAILED}${NC}"
    echo -e "   Total:  ${BLUE}${TOTAL}${NC}"
fi

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════${NC}"
echo ""

echo -e "${BLUE}📝 Próximos Pasos:${NC}"
echo "1. Asegúrate de tener GROQ_API_KEY en .env"
echo "2. Ejecuta: php artisan migrate"
echo "3. Ejecuta: php artisan test tests/Feature/BudgetSystemTest.php"
echo "4. Inicia el servidor: php artisan serve"
echo "5. Prueba los endpoints con Postman"
echo ""

echo -e "${BLUE}📖 Documentación:${NC}"
echo "- BUDGET_SYSTEM_GUIDE.md      → Guía completa del sistema"
echo "- README_PRESUPUESTOS.md      → README en español"
echo "- BUDGET_API_EXAMPLES.json    → Ejemplos de API"
echo ""

if [ $FAILED -gt 0 ]; then
    exit 1
else
    exit 0
fi
