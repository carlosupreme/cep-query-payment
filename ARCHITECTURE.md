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
│  │     Carlosupreme\CEPQueryPayment\CEPQueryService         │  │
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
│  │  │  • parseHtmlResponse(string): ?array            │     │  │
│  │  │  • sanitizeLogData(array): array                │     │  │
│  │  │  • log(string, string, array): void             │     │  │
│  │  └─────────────────────────────────────────────────┘     │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          │ uses                                │
│                          ▼                                     │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │               GuzzleHttp\Client                          │  │
│  │              (HTTP Requests)                             │  │
│  └───────────────────────┬──────────────────────────────────┘  │
│                          │ sends requests                      │
└──────────────────────────┼─────────────────────────────────────┘
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
    │       │       ├─> HTTP GET /cep/          [Warm up session]
    │       │       │
    │       │       ├─> HTTP POST /cep/valida.do [Submit form]
    │       │       │       │
    │       │       │       └─> Receive HTML response
    │       │       │
    │       │       ├─> parseHtmlResponse()     [Parse HTML to array]
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
    │       ├─> HTTP GET /cep/instituciones.do  [Fetch JSON]
    │       │       │
    │       │       └─> Receive JSON response
    │       │
    │       ├─> Parse JSON                      [Extract bank data]
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
│ - http: Client                                           │
│ - timeout: int                                           │
│ - defaultOptions: array                                  │
│ - logger: ?callable                                      │
│ - baseUri: string                                        │
│ - timezone: string                                       │
├─────────────────────────────────────────────────────────┤
│ + __construct(?Client, ?callable, string)               │
│ + queryPayment(array, array): ?array                    │
│ + getBankOptions(): array                               │
│ + getBankCodeByName(string): ?string                    │
│ + static formatDate(DateTime|string): string            │
├─────────────────────────────────────────────────────────┤
│ - validateFormData(array&): void                        │
│ - parseHtmlResponse(string): ?array                     │
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
│  Layer 2: HTTP Request                             │
│  ├─> Connection timeout                            │
│  ├─> Request failed                                │
│  └─> Throw GuzzleException                         │
│                                                    │
│  Layer 3: Response Parsing                         │
│  ├─> Empty response                                │
│  ├─> Invalid HTML/JSON                             │
│  └─> Throw Exception with response context         │
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

### HTTP Security

```
Guzzle Configuration:
  ├─> SSL/TLS verification enabled
  ├─> User-Agent header for compatibility
  ├─> Cookie jar for session management
  └─> No permanent storage of credentials
```

## Performance Characteristics

### Timing

```
Operation                          Time
────────────────────────────────────────
Input Validation                   < 1ms
Session Warm-up (GET /cep/)        1-3s
Form Submission (POST)             1-3s
Response Parsing                   < 10ms
Total                              ~2-6s
```

### Resource Usage

```
Memory:
  - PHP Process: ~10-20 MB
  - Guzzle Client: ~5-10 MB
  Total: ~15-30 MB per query

CPU:
  - Light during HTTP operations
  - Minimal for HTML parsing

Disk:
  - No temporary files required
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

### Custom HTTP Client

```php
use GuzzleHttp\Client;

$httpClient = new Client([
    'timeout' => 120,
    'verify' => true,
]);

$service = new CEPQueryService($httpClient);
```

### Custom Timeout Options

```php
$options = [
    'timeout' => 60,  // 60 second timeout
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
      ├─> Mock Guzzle responses
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
    ├─> GuzzleHttp\Client
    │       └─> HTTP Request Handling
    │
    ├─> GuzzleHttp\Cookie\CookieJar
    │       └─> Session Cookie Management
    │
    ├─> Carbon\Carbon
    │       └─> Date/Time Handling
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
