# CEP Query Service - Architecture

This document describes the architecture and design of the CEP Query Service library.

## System Architecture

```
┌────────────────────────────────────────────────────────────────┐
│                          USAGE                                 │
│                                                                │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                   Laravel Application                    │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          │ implements                          │
│                          ▼                                     │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │     Carlosupreme\CEPQuery\CEPQueryService                │  │
│  │              (Core Library)                              │  │
│  │         [Framework-Agnostic]                             │  │
│  │                                                          │  │
│  │  ┌─────────────────────────────────────────────────┐     │  │
│  │  │  Public Methods:                                │     │  │
│  │  │  • queryPayment(array, array): ?array           │     │  │
│  │  │  • getBankOptions(): array                      │     │  │
│  │  │  • getBankCodeByName(string): ?string           │     │  │
│  │  │  • formatDate(mixed): string [static]           │     │  │
│  │  └─────────────────────────────────────────────────┘     │  │
│  │                                                          │  │
│  │  ┌─────────────────────────────────────────────────┐     │  │
│  │  │  Private Methods:                               │     │  │
│  │  │  • validateFormData(array): void                │     │  │
│  │  │  • createExecutionScript(array, array): string  │     │  │
│  │  │  • createBankOptionsScript(): string            │     │  │
│  │  │  • createTempScript(string): string             │     │  │
│  │  │  • executeScript(string, string): string        │     │  │
│  │  │  • parseScriptOutput(string): ?array            │     │  │
│  │  │  • sanitizeLogData(array): array                │     │  │
│  │  │  • log(string, string, array): void             │     │  │
│  │  └─────────────────────────────────────────────────┘     │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          │ uses                                │
│                          ▼                                     │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │         Symfony\Component\Process\Process                │  │
│  │              (Process Execution                          │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          │ executes                            │
└──────────────────────────┼─────────────────────────────────────┘
                           ▼
         ┌─────────────────────────────────────────┐
         │         Node.js + Puppeteer             │
         │   (cep-form-filler.js script)           │
         │                                         │
         │  ┌───────────────────────────────────┐  │
         │  │ 1. Launch Headless Chrome         │  │
         │  │ 2. Navigate to CEP Website        │  │
         │  │ 3. Fill Form Fields               │  │
         │  │ 4. Submit Query                   │  │
         │  │ 5. Extract Table Data             │  │
         │  │ 6. Return JSON Result             │  │
         │  └───────────────────────────────────┘  │
         └──────────────────┬──────────────────────┘
                            ▼
              ┌───────────────────────────┐
              │  Banco de México CEP      │
              │  https://banxico.org.mx   │
              └───────────────────────────┘
```

## Data Flow

### Query Payment Flow

```
User Request
    │
    ├─> Laravel Controller/Service
    │       │
    │       ├─> CEPQueryService::queryPayment($formData, $options)
    │       │       │
    │       │       ├─> validateFormData()      [Validate input]
    │       │       │
    │       │       ├─> createExecutionScript() [Generate JS]
    │       │       │
    │       │       ├─> createTempScript()      [Write to /tmp]
    │       │       │
    │       │       ├─> executeScript()         [Run Node.js]
    │       │       │       │
    │       │       │       ├─> Puppeteer Launch
    │       │       │       │       │
    │       │       │       │       ├─> Navigate to CEP
    │       │       │       │       │
    │       │       │       │       ├─> Fill Form
    │       │       │       │       │
    │       │       │       │       ├─> Submit
    │       │       │       │       │
    │       │       │       │       ├─> Wait for Modal
    │       │       │       │       │
    │       │       │       │       ├─> Extract Table
    │       │       │       │       │
    │       │       │       │       └─> Return JSON
    │       │       │       │
    │       │       │       └─> Process Output
    │       │       │
    │       │       ├─> parseScriptOutput()     [Parse JSON]
    │       │       │
    │       │       ├─> log()                   [Log result]
    │       │       │
    │       │       └─> return ?array
    │       │
    │       └─> Process Result
    │
    └─> Return Response to User
```

### Get Bank Options Flow

```
User Request
    │
    ├─> CEPQueryService::getBankOptions()
    │       │
    │       ├─> createBankOptionsScript()  [Generate JS]
    │       │
    │       ├─> createTempScript()         [Write to /tmp]
    │       │
    │       ├─> executeScript()            [Run Node.js]
    │       │       │
    │       │       ├─> Puppeteer Launch
    │       │       │
    │       │       ├─> Navigate to CEP
    │       │       │
    │       │       ├─> Extract Select Options
    │       │       │
    │       │       └─> Return JSON
    │       │
    │       ├─> parseScriptOutput()        [Parse JSON]
    │       │
    │       └─> return array
    │
    └─> Return Bank Codes
```

## Class Diagram

```
┌─────────────────────────────────────────────────────────┐
│              CEPQueryService                             │
├─────────────────────────────────────────────────────────┤
│ - scriptPath: string                                     │
│ - timeout: int                                           │
│ - defaultOptions: array                                  │
│ - logger: ?callable                                      │
├─────────────────────────────────────────────────────────┤
│ + __construct(?string, ?callable)                       │
│ + queryPayment(array, array): ?array                    │
│ + getBankOptions(): array                               │
│ + getBankCodeByName(string): ?string                    │
│ + static formatDate(DateTime|string): string            │
│ + setWorkingDirectory(string): self                     │
├─────────────────────────────────────────────────────────┤
│ - validateFormData(array&): void                        │
│ - createExecutionScript(array, array): string           │
│ - createBankOptionsScript(): string                     │
│ - createTempScript(string): string                      │
│ - executeScript(string, ?string): string                │
│ - parseScriptOutput(string): ?array                     │
│ - sanitizeLogData(array): array                         │
│ - log(string, string, array): void                      │
└─────────────────────────────────────────────────────────┘
               
```

## Service Provider Diagram

```
┌─────────────────────────────────────────────────────────┐
│       CEPQueryServiceProvider                           │
│     (Laravel Service Provider)                          │
├─────────────────────────────────────────────────────────┤
│ + register(): void                                      │
│   ├─> Binds CEPQueryService as singleton                │
│   ├─> Configures script path                            │
│   ├─> Configures Laravel logger                         │
│   └─> Creates alias 'cep-query'                         │
│                                                         │
│ + boot(): void                                          │
│   └─> (Currently empty)                                 │
└─────────────────────────────────────────────────────────┘
                        │
                        │ registered in
                        ▼
┌─────────────────────────────────────────────────────────┐
│         bootstrap/providers.php                         │
├─────────────────────────────────────────────────────────┤
│ [                                                       │
│     AppServiceProvider,                                 │
│     FilamentPanelProviders,                             │
│     ...                                                 │
│     CEPQueryServiceProvider,  ◄── Added                 │
│ ]                                                       │
└─────────────────────────────────────────────────────────┘
```

## Validation Pipeline

```
Input Form Data
    │
    ├─> Check Required Fields
    │   ├─ fecha
    │   ├─ tipoCriterio
    │   ├─ criterio
    │   ├─ emisor
    │   ├─ receptor
    │   ├─ cuenta
    │   └─ monto
    │
    ├─> Validate tipoCriterio
    │   └─ Must be 'T' (tracking) or 'R' (reference)
    │
    ├─> Validate Criterio Length
    │   ├─ If 'R': max 7 chars
    │   └─ If 'T': max 30 chars
    │
    ├─> Normalize Date Format
    │   ├─ Accept: dd/mm/yyyy or dd-mm-yyyy
    │   └─ Convert to: dd-mm-yyyy
    │
    ├─> Validate CLABE Format
    │   └─ Must be 18 numeric digits
    │
    ├─> Validate Amount Format
    │   └─ Must be numeric (commas allowed)
    │
    ├─> Validate Bank Codes
    │   └─ Must be numeric
    │
    └─> ✓ Validated Data
```

## Error Handling Strategy

```
┌────────────────────────────────────────────────────┐
│              Error Handling Layers                 │
├────────────────────────────────────────────────────┤
│                                                    │
│  Layer 1: Input Validation                         │
│  ├─> Missing fields                                │
│  ├─> Invalid format                                │
│  └─> Throw Exception with descriptive message      │
│                                                    │
│  Layer 2: Script Execution                         │
│  ├─> Process timeout                               │
│  ├─> Script not found                              │
│  └─> Throw ProcessFailedException                  │
│                                                    │
│  Layer 3: Output Parsing                           │
│  ├─> Empty output                                  │
│  ├─> Invalid JSON                                  │
│  ├─> Script execution failure                      │
│  └─> Throw Exception with output context           │
│                                                    │
│  Layer 4: Logging                                  │
│  ├─> Log errors with sanitized data                │
│  ├─> Mask sensitive fields (CLABE, criterio)       │
│  └─> Preserve debugging context                    │
│                                                    │
└────────────────────────────────────────────────────┘
```

## Security Considerations

### Data Sanitization

```
Sensitive Fields:
  - cuenta (CLABE)     → Show only last 4 digits
  - criterio (tracking) → Show only last 3 chars

Sanitization Flow:
  Input → Process → Log (Sanitized) → Output
```

### Process Security

```
Puppeteer Execution:
  ├─> Sandboxed browser environment
  ├─> Temporary script files (auto-cleanup)
  ├─> No permanent storage of credentials
  └─> Isolated Node.js process
```

## Performance Characteristics

### Timing

```
Operation                          Time
────────────────────────────────────────
Input Validation                   < 1ms
Script Generation                  < 5ms
Script Execution                   30-60s
  ├─> Browser Launch               5-10s
  ├─> Page Load                    3-5s
  ├─> Form Fill                    15-20s
  ├─> Submit & Wait                10-20s
  └─> Data Extraction              1-2s
Output Parsing                     < 10ms
Total                              ~30-60s
```

### Resource Usage

```
Memory:
  - PHP Process: ~10-20 MB
  - Node.js: ~50-100 MB
  - Puppeteer: ~100-200 MB
  Total: ~200-300 MB per query

CPU:
  - Moderate during browser operation
  - Light during form filling

Disk:
  - Temporary script files (~5-10 KB)
  - Auto-cleaned after execution
```

## Extension Points

### Custom Logger

```php
$logger = function(string $level, string $message, array $context) {
    // Custom implementation
    MyLogger::log($level, $message, $context);
};

$service = new CEPQueryService(null, $logger);
```

### Custom Script Path

```php
$customScriptPath = '/path/to/custom/scraper.js';
$service = new CEPQueryService($customScriptPath);
```

### Custom Browser Options

```php
$options = [
    'headless' => false,
    'slowMo' => 500,
    'timeout' => 60000,
];

$result = $service->queryPayment($formData, $options);
```

## Testing Strategy

### Unit Tests (Planned)

```
Tests to Implement:
  ├─> Validation Tests
  │   ├─> Test missing required fields
  │   ├─> Test invalid formats
  │   ├─> Test date normalization
  │   └─> Test CLABE validation
  │
  ├─> Helper Method Tests
  │   ├─> Test formatDate()
  │   ├─> Test sanitizeLogData()
  │   └─> Test getBankCodeByName()
  │
  └─> Integration Tests
      ├─> Mock Puppeteer responses
      ├─> Test successful queries
      ├─> Test failure scenarios
      └─> Test timeout handling
```

### Manual Testing

```bash
# Test service resolution
php artisan tinker --execute="app(CEPQueryService::class)"

# Run example script
php ./examples/basic-usage.php

# Test with real data (requires valid credentials)
# See examples/basic-usage.php
```

## Design Patterns Used

1. **Singleton Pattern**: Service Provider registers as singleton
2. **Facade Pattern**: Laravel wrapper provides simplified interface
3. **Strategy Pattern**: Injectable logger for different environments
4. **Template Method**: Script generation with configurable data
5. **Factory Pattern**: Dynamic script creation
6. **Builder Pattern**: Fluent interface with setWorkingDirectory()

## Dependencies Graph

```
CEPQueryService
    │
    ├─> Symfony\Component\Process\Process
    │       └─> System Process Execution
    │
    ├─> Node.js + Puppeteer
    │       └─> Browser Automation
    │
    └─> Optional: PSR-3 Logger Interface
            └─> For logging functionality
```

## Future Architecture Improvements

1. **Queue Support**: Async job processing
2. **Caching Layer**: Redis/Memcached integration
3. **Rate Limiting**: Request throttling
4. **Circuit Breaker**: Failure protection
5. **Health Checks**: Service monitoring
6. **Metrics Collection**: Performance tracking
7. **Event System**: Hook points for extensions
8. **Plugin Architecture**: Third-party extensions

---

**Document Version**: 1.0.0
**Last Updated**: 17 October 2025 
