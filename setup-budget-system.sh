#!/bin/bash

# Script para configurar e inicializar el sistema de presupuestos
# Usage: bash setup-budget-system.sh

set -e  # Exit on error

echo "🚀 Iniciando configuración del Sistema de Presupuestos..."
echo ""

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Run migrations
echo -e "${BLUE}📦 Ejecutando migraciones de base de datos...${NC}"
php artisan migrate

echo -e "${GREEN}✅ Migraciones ejecutadas correctamente${NC}"
echo ""

# Step 2: Verify models exist
echo -e "${BLUE}🔍 Verificando modelos...${NC}"
if [ -f "app/Models/Budget.php" ] && [ -f "app/Models/BudgetCategory.php" ]; then
    echo -e "${GREEN}✅ Modelos encontrados${NC}"
else
    echo -e "${YELLOW}⚠️  Modelos no encontrados. Por favor, verifica la instalación.${NC}"
    exit 1
fi
echo ""

# Step 3: Verify service exists
echo -e "${BLUE}🔍 Verificando servicio de IA...${NC}"
if [ -f "app/Services/BudgetAIService.php" ]; then
    echo -e "${GREEN}✅ Servicio BudgetAIService encontrado${NC}"
else
    echo -e "${YELLOW}⚠️  Servicio no encontrado${NC}"
    exit 1
fi
echo ""

# Step 4: Verify controller
echo -e "${BLUE}🔍 Verificando controlador...${NC}"
if [ -f "app/Http/Controllers/SmartBudgetController.php" ]; then
    echo -e "${GREEN}✅ Controlador SmartBudgetController encontrado${NC}"
else
    echo -e "${YELLOW}⚠️  Controlador no encontrado${NC}"
    exit 1
fi
echo ""

# Step 5: Check Groq configuration
echo -e "${BLUE}🔍 Verificando configuración de Groq API...${NC}"
if grep -q "GROQ_API_KEY" .env; then
    echo -e "${GREEN}✅ GROQ_API_KEY configurada en .env${NC}"
else
    echo -e "${YELLOW}⚠️  GROQ_API_KEY no encontrada en .env${NC}"
    echo -e "${YELLOW}   Asegúrate de agregar: GROQ_API_KEY=tu_clave_api${NC}"
fi
echo ""

# Step 6: Cache config
echo -e "${BLUE}📝 Limpiando caché de configuración...${NC}"
php artisan config:cache
echo -e "${GREEN}✅ Caché limpiado${NC}"
echo ""

# Step 7: List available routes
echo -e "${BLUE}📋 Rutas disponibles del sistema de presupuestos:${NC}"
php artisan route:list | grep -E "(budgets|categories)" || true
echo ""

echo -e "${GREEN}🎉 ¡Configuración completada exitosamente!${NC}"
echo ""
echo -e "${BLUE}Próximos pasos:${NC}"
echo "1. Asegúrate de tener GROQ_API_KEY en tu archivo .env"
echo "2. Inicia el servidor: php artisan serve"
echo "3. Prueba los endpoints con el token de autenticación"
echo "4. Lee BUDGET_SYSTEM_GUIDE.md para documentación completa"
echo ""
