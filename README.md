# CEP Query Service

A PHP library for querying Banco de México's CEP (Comprobantes Electrónicos de Pago / Electronic Payment Receipts) system using web scraping with Puppeteer.

## Overview

This library provides a simple interface to query the SPEI payment system through Banco de México's CEP website. It automates form filling and data extraction using Puppeteer/Playwright for browser automation.

## Features

- ✅ Query payment status using tracking key or reference number
- ✅ Retrieve available bank options from CEP system
- ✅ Automatic form validation and data sanitization
- ✅ Support for headless and headed browser modes
- ✅ Comprehensive error handling and logging
- ✅ Framework-agnostic with Laravel integration
- ✅ Date format normalization
- ✅ Bank code lookup by name

## Requirements

- PHP 8.1 or higher
- Node.js and npm
- Puppeteer package (`npm install puppeteer`)
- Symfony Process component

## Installation

### For Laravel Projects

1. The package is available in composer. Run composer install:

```bash
composer install carlosupreme/cep-query-payment
```

2. Install Node.js dependencies:

```bash
npm install puppeteer
```

3. The service provider is already registered in `bootstrap/providers.php`.

4. Run:

```bash
composer dump-autoload
npm install puppeteer
```

## Usage

### Basic Usage (Framework-Agnostic)

```php
use Carlosupreme\CEPQuery\CEPQueryService;

// Create service instance
$cepService = new CEPQueryService();

// Prepare form data
$formData = [
    'fecha' => '15-01-2024',           // Payment date (dd-mm-yyyy)
    'tipoCriterio' => 'T',             // 'T' for tracking key, 'R' for reference
    'criterio' => '1234567890',        // Tracking key or reference number
    'emisor' => '40012',               // Sender bank code
    'receptor' => '40002',             // Receiver bank code
    'cuenta' => '012345678901234567',  // Beneficiary CLABE (18 digits)
    'monto' => '1500.00',              // Amount
];

// Query payment
try {
    $result = $cepService->queryPayment($formData);

    if ($result !== null) {
        // Payment found
        print_r($result);
    } else {
        // Payment not found
        echo "Payment not found";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Laravel Usage

```php
use Carlosupreme\CEPQuery\CEPQueryService;

class PaymentController extends Controller
{
    public function __construct(
        private CEPQueryService $cepService
    ) {}

    public function verifyPayment(Request $request)
    {
        $formData = [
            'fecha' => CEPQueryService::formatDate($request->payment_date),
            'tipoCriterio' => 'T',
            'criterio' => $request->tracking_key,
            'emisor' => $request->sender_bank,
            'receptor' => $request->receiver_bank,
            'cuenta' => $request->clabe,
            'monto' => $request->amount,
        ];

        $result = $this->cepService->queryPayment($formData);

        return response()->json([
            'found' => $result !== null,
            'data' => $result,
        ]);
    }
}
```

### Get Available Banks

```php
$banks = $cepService->getBankOptions();

// Returns array like:
// [
//     '40002' => 'BANAMEX',
//     '40012' => 'BBVA BANCOMER',
//     ...
// ]
```

### Bank Code Lookup

```php
// Find bank code by name (case-insensitive)
$bankCode = $cepService->getBankCodeByName('BBVA');
// Returns: '40012'
```

### Date Formatting

```php
use Carlosupreme\CEPQuery\CEPQueryService;

// From DateTime object
$date = new DateTime('2024-01-15');
$formatted = CEPQueryService::formatDate($date);
// Returns: '15-01-2024'

// From Y-m-d string
$formatted = CEPQueryService::formatDate('2024-01-15');
// Returns: '15-01-2024'

// From dd/mm/yyyy
$formatted = CEPQueryService::formatDate('15/01/2024');
// Returns: '15-01-2024'
```

### Custom Logger

```php
use Carlosupreme\CEPQuery\CEPQueryService;

$logger = function(string $level, string $message, array $context = []) {
    // Custom logging implementation
    error_log("[$level] $message " . json_encode($context));
};

$cepService = new CEPQueryService(null, $logger);
```

### Browser Options

```php
$options = [
    'headless' => false,  // Show browser window
    'slowMo' => 500,      // Slow down by 500ms
    'timeout' => 60000,   // 60 second timeout
];

$result = $cepService->queryPayment($formData, $options);
```

## Form Data Structure

### Required Fields

| Field | Type | Description | Example |
|-------|------|-------------|---------|
| `fecha` | string | Payment date in dd-mm-yyyy format | `'15-01-2024'` |
| `tipoCriterio` | string | Search criteria type: 'T' (tracking) or 'R' (reference) | `'T'` |
| `criterio` | string | Tracking key (max 30) or reference (max 7) | `'1234567890'` |
| `emisor` | string | Sender bank code (numeric) | `'40012'` |
| `receptor` | string | Receiver bank code (numeric) | `'40002'` |
| `cuenta` | string | Beneficiary CLABE account (18 digits) | `'012345678901234567'` |
| `monto` | string | Payment amount | `'1500.00'` |

### Validation Rules

- **fecha**: Must be dd-mm-yyyy or dd/mm/yyyy format
- **tipoCriterio**: Must be 'T' or 'R'
- **criterio**: Max 30 chars for tracking key, max 7 for reference
- **emisor/receptor**: Must be numeric bank codes
- **cuenta**: Must be 18 digits for CLABE format
- **monto**: Must be numeric (commas allowed)

## Response Format

### Successful Query (Payment Found)

```php
[
    'type' => 'table',
    'headers' => ['Header1', 'Header2', ...],
    'rows' => [
        ['value1', 'value2', ...],
        ['value1', 'value2', ...],
    ]
]
```

### Payment Not Found

```php
[
    'type' => 'text',
    'content' => 'Operación no encontrada...',
    'html' => '<div>...</div>'
]
```

### No Result

```php
null
```

## Error Handling

The library throws `Exception` for various error conditions:

```php
try {
    $result = $cepService->queryPayment($formData);
} catch (Exception $e) {
    // Handle errors:
    // - Missing required fields
    // - Invalid data format
    // - Script execution failures
    // - Timeout errors
    // - Invalid response format

    echo "Error: " . $e->getMessage();
}
```

Common exceptions:

- `Required field missing: {field}`
- `Invalid tipoCriterio. Must be 'T' or 'R'`
- `Invalid date format. Use dd-mm-yyyy or dd/mm/yyyy`
- `Invalid CLABE format. Must be 18 digits`
- `Script execution failed`
- `No valid JSON found in script output`

## Configuration

### Script Path

By default, the service looks for the JavaScript file at:
- Laravel: `resources/js/cep-form-filler.js`
- Standalone: `{package}/resources/js/cep-form-filler.js`

You can override this:

```php
$scriptPath = '/custom/path/to/cep-form-filler.js';
$cepService = new CEPQueryService($scriptPath);
```

### Timeout

Default timeout is 120 seconds. The browser operation timeout is 45 seconds.

## Security Considerations

- Form data is sanitized before logging (sensitive fields are masked)
- CLABE accounts show only last 4 digits in logs
- Tracking keys/references show only last 3 characters in logs
- Use environment variables for sensitive configuration
- Consider rate limiting to avoid overloading CEP system

## Performance Tips

1. **Use Headless Mode**: Set `headless: true` for production (default)
2. **Cache Bank Options**: Bank codes rarely change, cache them
3. **Adjust Timeouts**: Reduce for faster failures, increase for slow networks
4. **Queue Jobs**: For Laravel, use queues for CEP queries
5. **Error Monitoring**: Log failures for debugging

## Troubleshooting

### Script Fails to Execute

- Ensure Node.js is installed: `node --version`
- Verify Puppeteer is installed: `npm list puppeteer`
- Check file permissions on script
- Review logs for detailed error messages

### Timeout Errors

- Increase timeout in options
- Check network connectivity
- Verify CEP website is accessible
- Consider server location (latency)

### Invalid Response

- CEP website may have changed structure
- Check browser console logs
- Try non-headless mode for debugging
- Verify form data is correct

### No Results Found

- Verify payment date is correct
- Check tracking key/reference number
- Ensure bank codes are correct
- Confirm CLABE account matches
- Payment may not exist in system

## Development

### Running Tests

```bash
# Install dev dependencies
composer install --dev

# Run tests
./vendor/bin/pest
```

### Debug Mode

```php
// Run with visible browser
$options = ['headless' => false, 'slowMo' => 1000];
$result = $cepService->queryPayment($formData, $options);
```

## License

MIT License - See LICENSE file for details

## Support

For issues, questions, or contributions, please feel free to open a GitHub issue.

## Credits

Developed for Carlos Sosa.

## Changelog

### Version 1.0.0 (2025)
- Initial release
- Support for payment queries
- Bank options retrieval
- Laravel integration
- Comprehensive validation
