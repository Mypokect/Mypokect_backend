#!/bin/bash

# Test script for Budget AI functionality

echo "🧪 Testing Budget AI Function"
echo "=============================="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Check if GROQ_API_KEY is set
if ! grep -q "GROQ_API_KEY" .env 2>/dev/null; then
    echo -e "${RED}❌ Error: GROQ_API_KEY not found in .env${NC}"
    echo "Please add your Groq API key to .env:"
    echo "GROQ_API_KEY=gsk_your_key_here"
    exit 1
fi

echo -e "${YELLOW}📝 Running AI Budget generation test...${NC}"
echo ""

# Create test PHP script
cat > test_ai_budget.php << 'TESTSCRIPT'
<?php

require 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\BudgetAIService;

$service = app(BudgetAIService::class);

echo "Test 1: Spanish Budget (Viaje)\n";
echo "==============================\n";

try {
    $result = $service->generateBudgetWithAI(
        'Viaje a Perú',
        2000,
        'Viaje a Machu Picchu con mi familia por 2 semanas'
    );
    
    if ($result['success']) {
        echo "✅ Success!\n";
        echo "Language: " . $result['language'] . "\n";
        echo "Plan Type: " . $result['plan_type'] . "\n";
        echo "Categories:\n";
        
        $sum = 0;
        foreach ($result['data']['categories'] as $cat) {
            echo "  - " . $cat['name'] . ": $" . $cat['amount'] . " (" . $cat['percentage'] . "%)\n";
            $sum += $cat['amount'];
        }
        
        echo "\nTotal Sum: $" . $sum . "\n";
        echo "Expected: $2000.00\n";
        
        if (abs($sum - 2000) < 0.01) {
            echo "✅ Sum is correct!\n";
        } else {
            echo "⚠️  Sum difference: " . abs($sum - 2000) . "\n";
        }
    } else {
        echo "❌ Failed\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n\nTest 2: English Budget (Party)\n";
echo "================================\n";

try {
    $result = $service->generateBudgetWithAI(
        'Birthday Party',
        1500,
        'Birthday celebration for 50 guests with dinner and bar'
    );
    
    if ($result['success']) {
        echo "✅ Success!\n";
        echo "Language: " . $result['language'] . "\n";
        echo "Plan Type: " . $result['plan_type'] . "\n";
        echo "Categories:\n";
        
        $sum = 0;
        foreach ($result['data']['categories'] as $cat) {
            echo "  - " . $cat['name'] . ": $" . $cat['amount'] . " (" . $cat['percentage'] . "%)\n";
            $sum += $cat['amount'];
        }
        
        echo "\nTotal Sum: $" . $sum . "\n";
        echo "Expected: $1500.00\n";
        
        if (abs($sum - 1500) < 0.01) {
            echo "✅ Sum is correct!\n";
        } else {
            echo "⚠️  Sum difference: " . abs($sum - 1500) . "\n";
        }
    } else {
        echo "❌ Failed\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n✅ Tests completed!\n";
TESTSCRIPT

# Run the test
php test_ai_budget.php

# Cleanup
rm -f test_ai_budget.php

echo ""
echo -e "${GREEN}✅ Test completed${NC}"
