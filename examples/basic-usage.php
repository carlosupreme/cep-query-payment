<?php

/**
 * Basic Usage Example for CEP Query Service
 *
 * This example demonstrates how to use the CEPQueryService
 * to query payment status from Banco de México's CEP system.
 */

require __DIR__ . '/../vendor/autoload.php';

use Carlosupreme\CEPQueryPayment\CEPQueryService;

// Create service instance with custom logger
$logger = function(string $level, string $message, array $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] [$level] $message\n";
    if (!empty($context)) {
        echo "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
    }
};

$scriptPath = __DIR__ . '/../resources/js/cep-form-filler.js';
$cepService = new CEPQueryService($scriptPath, $logger);

// Example 1: Get available banks
echo "=== Example 1: Getting Available Banks ===\n\n";
try {
    $banks = $cepService->getBankOptions();
    echo "Found " . count($banks) . " banks:\n";
    foreach (array_slice($banks, 0, 5) as $code => $name) {
        echo "  $code => $name\n";
    }
    echo "  ...\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Example 2: Find bank code by name
echo "=== Example 2: Finding Bank Code by Name ===\n\n";
try {
    $bankCode = $cepService->getBankCodeByName('BBVA');
    echo "BBVA Bank Code: $bankCode\n\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Example 3: Query payment status
echo "=== Example 3: Querying Payment Status ===\n\n";

// Prepare payment query data
$formData = [
    'fecha' => CEPQueryService::formatDate(new DateTime('2025-10-01')),
    'tipoCriterio' => 'T',  // T = tracking key, R = reference number
    'criterio' => '50118824TRANSBPI99261289',  // 30 char max for tracking
    'emisor' => '40137',    // BANCOPPEL
    'receptor' => '40012',  // BBVA
    'cuenta' => '012180015913484661',  // 18-digit CLABE
    'monto' => '500.00',
];

try {
    echo "Querying payment with data:\n";
    echo json_encode([
        'fecha' => $formData['fecha'],
        'tipoCriterio' => $formData['tipoCriterio'],
        'criterio' => substr($formData['criterio'], 0, 10) . '...',
        'emisor' => $formData['emisor'],
        'receptor' => $formData['receptor'],
        'cuenta' => '***' . substr($formData['cuenta'], -4),
        'monto' => $formData['monto'],
    ], JSON_PRETTY_PRINT) . "\n\n";

    echo "Executing query (this may take 30-60 seconds)...\n";

    // Custom options for this query
    $options = [
        'headless' => true,   // Run in headless mode
        'slowMo' => 100,      // Slow down by 100ms
        'timeout' => 45000,   // 45 second timeout
    ];

    $result = $cepService->queryPayment($formData, $options);

    if ($result === null) {
        echo "Payment not found in CEP system\n";
    } elseif (isset($result['type']) && $result['type'] === 'text') {
        echo "Text response received:\n";
        echo $result['content'] . "\n";
    } elseif (isset($result['type']) && $result['type'] === 'table') {
        echo "Payment found! Table data:\n";
        echo "Headers: " . implode(', ', $result['headers']) . "\n";
        echo "Rows: " . count($result['rows']) . "\n";
        foreach ($result['rows'] as $i => $row) {
            echo "  Row $i: " . implode(', ', $row) . "\n";
        }
    }

} catch (Exception $e) {
    echo "Error querying payment: " . $e->getMessage() . "\n";
}

echo "\n=== Examples Complete ===\n";
