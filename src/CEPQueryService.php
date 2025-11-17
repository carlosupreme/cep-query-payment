<?php

namespace Carlosupreme\CEPQueryPayment;

use DateTime;
use Exception;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * CEP Query Service
 *
 * Service class for querying Banco de México Electronic Payment Receipts (CEP)
 * through automated web form filling using Puppeteer/Playwright
 */
class CEPQueryService
{
    private string $scriptPath;

    private int $timeout;

    private array $defaultOptions;

    /** @var callable|null */
    private $logger;

    /** @var string|null Explicit working directory for Node execution */
    private ?string $workingDirectory = null;

    public function __construct(?string $scriptPath = null, ?callable $logger = null)
    {
        $this->scriptPath = $scriptPath ?? __DIR__.'/../resources/js/cep-form-filler.js';
        $this->timeout = 120; // 120 seconds timeout
        $this->defaultOptions = [
            'headless' => true, // Production mode - headless
            'slowMo' => 100, // Normal speed
            'timeout' => 45000,
        ];
        $this->logger = $logger;
    }

    /**
     * Query CEP using form data
     *
     * @param  array  $formData  Form data for CEP query
     * @param  array  $options  Additional options for the browser
     * @return array|null Table data or null if not found
     *
     * @throws Exception
     */
    public function queryPayment(array $formData, array $options = []): ?array
    {
        try {
            // Validate required fields
            $this->validateFormData($formData);

            // Merge options
            $browserOptions = array_merge($this->defaultOptions, $options);

            // Create the Node.js script content
            $scriptContent = $this->createExecutionScript($formData, $browserOptions);

            // Write temporary script file
            $tempScriptPath = $this->createTempScript($scriptContent);

            try {
                // Execute the script
                $result = $this->executeScript($tempScriptPath);

                // Parse and return result
                return $this->parseScriptOutput($result);

            } finally {
                // Clean up temporary file
                if (file_exists($tempScriptPath)) {
                    unlink($tempScriptPath);
                }
            }

        } catch (Exception $e) {
            $this->log('error', 'CEP Query failed', [
                'error' => $e->getMessage(),
                'formData' => $this->sanitizeLogData($formData),
            ]);
            throw $e;
        }
    }

    /**
     * Get available bank options
     *
     * @return array Bank codes and names
     *
     * @throws Exception
     */
    public function getBankOptions(): array
    {
        try {
            $scriptContent = $this->createBankOptionsScript();
            $tempScriptPath = $this->createTempScript($scriptContent);

            try {
                $result = $this->executeScript($tempScriptPath);

                return $this->parseScriptOutput($result) ?? [];

            } finally {
                if (file_exists($tempScriptPath)) {
                    unlink($tempScriptPath);
                }
            }

        } catch (Exception $e) {
            $this->log('error', 'Failed to get bank options', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Validate form data
     *
     * @throws Exception
     */
    private function validateFormData(array &$formData): void
    {
        $required = ['fecha', 'tipoCriterio', 'criterio', 'emisor', 'receptor', 'cuenta', 'monto'];

        foreach ($required as $field) {
            if (! isset($formData[$field]) || empty($formData[$field])) {
                throw new Exception("Required field missing: {$field}");
            }
        }

        // Validate tipoCriterio
        if (! in_array($formData['tipoCriterio'], ['T', 'R'])) {
            throw new Exception("Invalid tipoCriterio. Must be 'T' (tracking key) or 'R' (reference number)");
        }

        // Validate criterio length based on type
        if ($formData['tipoCriterio'] === 'R' && strlen($formData['criterio']) > 7) {
            throw new Exception('Reference number cannot exceed 7 characters');
        }

        if ($formData['tipoCriterio'] === 'T' && strlen($formData['criterio']) > 30) {
            throw new Exception('Tracking key cannot exceed 30 characters');
        }

        // Validate and normalize date format (dd-mm-yyyy or dd/mm/yyyy, convert to dd-mm-yyyy)
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $formData['fecha'])) {
            // Convert dd/mm/yyyy to dd-mm-yyyy
            $formData['fecha'] = str_replace('/', '-', $formData['fecha']);
        } elseif (! preg_match('/^\d{2}-\d{2}-\d{4}$/', $formData['fecha'])) {
            throw new Exception('Invalid date format. Use dd-mm-yyyy or dd/mm/yyyy');
        }

        // Validate CLABE format (18 digits)
        if (isset($formData['cuenta']) && strlen($formData['cuenta']) === 18 && ! preg_match('/^\d{18}$/', $formData['cuenta'])) {
            throw new Exception('Invalid CLABE format. Must be 18 digits');
        }

        // Validate amount format
        if (! is_numeric(str_replace(',', '', $formData['monto']))) {
            throw new Exception('Invalid amount format');
        }

        // Validate bank codes (must be numeric)
        if (! is_numeric($formData['emisor']) || ! is_numeric($formData['receptor'])) {
            throw new Exception('Invalid bank codes. Must be numeric');
        }
    }

    /**
     * Create the execution script content
     */
    private function createExecutionScript(array $formData, array $options): string
    {
        $formDataJson = json_encode($formData, JSON_UNESCAPED_UNICODE);
        $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE);

        return <<<SCRIPT
const puppeteer = require('puppeteer');

async function queryCEP() {
    let browser;
    try {
        const options = {$optionsJson};

        browser = await puppeteer.launch({
            headless: options.headless,
            slowMo: options.slowMo,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--no-zygote',
                '--disable-gpu',
                '--disable-background-timer-throttling',
                '--disable-backgrounding-occluded-windows',
                '--disable-renderer-backgrounding',
                '--disable-features=TranslateUI',
                '--disable-web-security',
                '--disable-features=VizDisplayCompositor',
                '--disable-extensions',
                '--disable-plugins',
                '--disable-default-apps',
                '--no-default-browser-check',
                '--disable-popup-blocking',
                '--window-size=1920,1080'
            ]
        });

        const page = await browser.newPage();

        // Set timeout
        page.setDefaultTimeout(options.timeout);

        // Navigate to CEP page
        await page.goto('https://www.banxico.org.mx/cep/', {
            waitUntil: 'networkidle2'
        });

        // Wait for form to load
        await page.waitForSelector('#fConsulta', { timeout: 15000 });

        console.log('Form loaded, filling data...');

        // Set viewport for consistent rendering
        await page.setViewport({ width: 1920, height: 1080 });

        // Fill form fields one by one with delays to allow validation loading
        console.log('Starting sequential form fill with delays...');

        const formData = {$formDataJson};

        // Helper function to fill input with delay
        async function fillInput(selector, value, description) {
            console.log(`Filling \${description}: \${value}`);
            await page.evaluate((sel, val) => {
                const element = document.getElementById(sel);
                if (element) {
                    element.value = val;
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                    element.dispatchEvent(new Event('change', { bubbles: true }));
                    element.dispatchEvent(new Event('keyup', { bubbles: true }));
                    element.dispatchEvent(new Event('blur', { bubbles: true }));
                    console.log('✅ Input filled:', sel, '=', val);
                } else {
                    console.log('❌ Input not found:', sel);
                }
            }, selector, value);
            // Wait for validation/loading after each field
            await new Promise(resolve => setTimeout(resolve, 2500));
        }

        // Helper function to fill select with delay
        async function fillSelect(selector, value, description) {
            console.log(`Filling \${description}: \${value}`);
            await page.evaluate((sel, val) => {
                const element = document.getElementById(sel);
                if (element) {
                    const option = element.querySelector(`option[value="\${val}"]`);
                    if (option) {
                        element.value = val;
                        element.dispatchEvent(new Event('change', { bubbles: true }));
                        element.dispatchEvent(new Event('input', { bubbles: true }));
                        element.dispatchEvent(new Event('focus', { bubbles: true }));
                        element.dispatchEvent(new Event('blur', { bubbles: true }));

                        // Force redraw
                        element.style.display = 'none';
                        element.offsetHeight;
                        element.style.display = '';

                        console.log('✅ Select updated:', sel, '=', val, '(', option.text, ')');
                    } else {
                        console.log('❌ Option not found for value:', val, 'in select:', sel);
                    }
                } else {
                    console.log('❌ Select not found:', sel);
                }
            }, selector, value);
            // Wait for validation/loading after each field
            await new Promise(resolve => setTimeout(resolve, 2500));
        }

        // Fill form fields sequentially with delays
        await fillInput('input_fecha', formData.fecha, 'date field');
        await fillSelect('input_tipoCriterio', formData.tipoCriterio, 'criteria type');

        // Update criteria label after tipoCriterio selection
        await page.evaluate((formData) => {
            const criterioLabel = document.querySelector('label[for="input_criterio"]');
            if (criterioLabel) {
                criterioLabel.textContent = formData.tipoCriterio === 'T' ? 'Clave de rastreo' : 'Número de referencia';
            }
        }, formData);

        await fillInput('input_criterio', formData.criterio, 'criteria value');
        await fillSelect('input_emisor', formData.emisor, 'sender bank');
        await fillSelect('input_receptor', formData.receptor, 'receiver bank');
        await fillInput('input_cuenta', formData.cuenta, 'beneficiary account');
        await fillInput('input_monto', formData.monto, 'amount');

        console.log('✅ Sequential form fill with delays completed');

        // Wait for final form processing
        await new Promise(resolve => setTimeout(resolve, 3000));

        // Verify the form was filled correctly
        const formValidation = await page.evaluate(() => {
            const validation = {};

            // Check all critical fields
            const fields = {
                'input_fecha': 'fecha',
                'input_tipoCriterio': 'tipoCriterio',
                'input_criterio': 'criterio',
                'input_emisor': 'emisor',
                'input_receptor': 'receptor',
                'input_cuenta': 'cuenta',
                'input_monto': 'monto'
            };

            Object.keys(fields).forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    validation[fields[fieldId]] = field.value;
                    if (field.tagName === 'SELECT') {
                        const selectedOption = field.options[field.selectedIndex];
                        validation[fields[fieldId] + '_text'] = selectedOption ? selectedOption.text : 'No selection';
                    }
                } else {
                    validation[fields[fieldId]] = 'FIELD_NOT_FOUND';
                }
            });

            console.log('📊 Form validation results:', validation);
            return validation;
        });

        console.log('Form validation:', JSON.stringify(formValidation));

        // Enable the submit button by removing disabled class
        await page.evaluate(() => {
            const submitButton = document.getElementById('btn_Consultar');
            if (submitButton) {
                submitButton.classList.remove('disabled');
                submitButton.style.cursor = 'pointer';
                console.log('Submit button enabled');
            }
        });

        // Click submit button
        const buttonClicked = await page.evaluate(() => {
            const submitButton = document.getElementById('btn_Consultar');
            if (submitButton) {
                console.log('Submit button found. Classes:', submitButton.classList.toString());
                console.log('Submit button disabled?', submitButton.classList.contains('disabled'));

                if (!submitButton.classList.contains('disabled')) {
                    console.log('Clicking submit button...');
                    submitButton.click();
                    return true;
                } else {
                    console.log('Submit button is disabled, cannot click');
                    return false;
                }
            } else {
                console.log('Submit button not found');
                return false;
            }
        });

        if (!buttonClicked) {
            throw new Error('Could not click submit button - either not found or disabled');
        }

        // Wait for modal to appear with improved detection
        let result = null;
        console.log('Waiting for response modal...');

        try {
            // Wait for the modal to appear
            await page.waitForFunction(() => {
                const modal = document.getElementById('divValidacionPertenencia');
                return modal && modal.style.display !== 'none';
            }, { timeout: 45000 });

            console.log('Modal appeared, waiting for content to load...');

            // Wait for content to fully load
            await new Promise(resolve => setTimeout(resolve, 5000));

            // Extract all content from the modal
            result = await page.evaluate(() => {
                console.log('Starting data extraction...');

                const modal = document.getElementById('divValidacionPertenencia');
                if (!modal || modal.style.display === 'none') {
                    console.log('Modal not found or hidden');
                    return null;
                }

                const consultaDiv = document.querySelector('#consultaMISPEI');
                if (!consultaDiv) {
                    console.log('No consultaMISPEI div found');
                    return null;
                }

                console.log('ConsultaMISPEI content:', consultaDiv.innerHTML.substring(0, 200));

                // Look for any table in the modal
                const tables = consultaDiv.querySelectorAll('table');
                console.log('Found tables:', tables.length);

                if (tables.length === 0) {
                    console.log('No tables found');
                    // Return the text content instead
                    return {
                        type: 'text',
                        content: consultaDiv.textContent.trim(),
                        html: consultaDiv.innerHTML
                    };
                }

                // Process the first table found
                const table = tables[0];
                const tableData = {
                    type: 'table',
                    headers: [],
                    rows: []
                };

                // Extract headers from thead
                const thead = table.querySelector('thead');
                if (thead) {
                    const headerRows = thead.querySelectorAll('tr');
                    headerRows.forEach(row => {
                        const headers = Array.from(row.querySelectorAll('th, td'))
                            .map(cell => cell.textContent.trim())
                            .filter(text => text.length > 0);
                        if (headers.length > 0) {
                            tableData.headers = tableData.headers.concat(headers);
                        }
                    });
                }

                // Extract data rows from tbody
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    console.log('Found rows in tbody:', rows.length);

                    tableData.rows = rows.map(row => {
                        return Array.from(row.querySelectorAll('td, th'))
                            .map(cell => cell.textContent.trim());
                    }).filter(row => row.some(cell => cell.length > 0)); // Filter empty rows
                }

                // If no tbody, check for direct tr elements in table
                if (tableData.rows.length === 0) {
                    const directRows = Array.from(table.querySelectorAll('tr'));
                    console.log('Found direct rows in table:', directRows.length);

                    tableData.rows = directRows.map(row => {
                        return Array.from(row.querySelectorAll('td, th'))
                            .map(cell => cell.textContent.trim());
                    }).filter(row => row.some(cell => cell.length > 0));
                }

                console.log('Final table data:', tableData);
                return tableData;
            });

        } catch (error) {
            console.log('Error waiting for modal:', error.message);

            // Try to get any content that might be available
            try {
                result = await page.evaluate(() => {
                    const modal = document.getElementById('divValidacionPertenencia');
                    if (modal) {
                        return {
                            type: 'error',
                            content: modal.textContent.trim(),
                            html: modal.innerHTML,
                            display: modal.style.display
                        };
                    }
                    return null;
                });
            } catch (e) {
                console.log('Failed to extract error content:', e.message);
            }
        }

        console.log(JSON.stringify({
            success: true,
            data: result
        }));

    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

queryCEP();
SCRIPT;
    }

    /**
     * Create script to get bank options
     */
    private function createBankOptionsScript(): string
    {
        return <<<'SCRIPT'
const puppeteer = require('puppeteer');

async function getBankOptions() {
    let browser;
    try {
        browser = await puppeteer.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage'
            ]
        });

        const page = await browser.newPage();
        await page.goto('https://www.banxico.org.mx/cep/', {
            waitUntil: 'networkidle2'
        });

        await page.waitForSelector('#input_emisor');

        const bankOptions = await page.evaluate(() => {
            const select = document.getElementById('input_emisor');
            const options = {};

            Array.from(select.options).forEach(option => {
                if (option.value) {
                    options[option.value] = option.textContent.trim();
                }
            });

            return options;
        });

        console.log(JSON.stringify({
            success: true,
            data: bankOptions
        }));

    } catch (error) {
        console.log(JSON.stringify({
            success: false,
            error: error.message
        }));
    } finally {
        if (browser) {
            await browser.close();
        }
    }
}

getBankOptions();
SCRIPT;
    }

    /**
     * Create temporary script file
     *
     * @return string Path to temporary file
     *
     * @throws Exception
     */
    private function createTempScript(string $content): string
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'cep_query_').'.js';

        if (file_put_contents($tempFile, $content) === false) {
            throw new Exception('Failed to create temporary script file');
        }

        return $tempFile;
    }

    /**
     * Execute the Node.js script
     *
     * @return string Script output
     *
     * @throws Exception
     */
    private function executeScript(string $scriptPath, ?string $workingDirectory = null): string
    {
        // Determine node binary (configurable when running inside Laravel)
        $nodeBinary = 'node';
        if (function_exists('config')) {
            try {
                $nodeBinary = config('cep-query-payment.node_binary', env('CEP_QUERY_NODE_BINARY', env('NODE_BINARY', 'node')));
            } catch (\Throwable $e) {
                $nodeBinary = getenv('CEP_QUERY_NODE_BINARY') ?: getenv('NODE_BINARY') ?: 'node';
            }
        } else {
            $nodeBinary = getenv('CEP_QUERY_NODE_BINARY') ?: getenv('NODE_BINARY') ?: 'node';
        }

        // Determine working directory precedence:
        // 1) method argument, 2) explicitly set on the instance, 3) config('cep-query-payment.node_cwd'), 4) base_path() if available, 5) getcwd()
        if ($workingDirectory === null && $this->workingDirectory !== null) {
            $workingDirectory = $this->workingDirectory;
        }

        if ($workingDirectory === null && function_exists('config')) {
            try {
                $workingDirectory = config('cep-query-payment.node_cwd', null);
            } catch (\Throwable $e) {
                $workingDirectory = getenv('CEP_QUERY_NODE_CWD') ?: null;
            }
        }

        if ($workingDirectory === null) {
            $workingDirectory = getenv('CEP_QUERY_NODE_CWD') ?: null;
        }

        if ($workingDirectory === null) {
            // Try base_path() if available
            if (function_exists('base_path')) {
                try {
                    $workingDirectory = base_path();
                } catch (\Throwable $e) {
                    $workingDirectory = getcwd();
                }
            } else {
                $workingDirectory = getcwd();
            }
        }

        // Determine timeout (seconds)
        $timeout = $this->timeout;
        if (function_exists('config')) {
            try {
                $timeout = config('cep-query-payment.node_timeout', $this->timeout);
            } catch (\Throwable $e) {
                $timeout = (int) (getenv('CEP_QUERY_NODE_TIMEOUT') ?: $this->timeout);
            }
        } else {
            $timeout = (int) (getenv('CEP_QUERY_NODE_TIMEOUT') ?: $this->timeout);
        }

        // Create and run the process with the selected binary and working directory
        $process = new Process([$nodeBinary, $scriptPath], $workingDirectory);
        $process->setTimeout((int) $timeout);

        // Set environment variables for Node.js so it can resolve modules in the target working directory
        $env = array_merge($_ENV, [
            'NODE_PATH' => $workingDirectory . '/node_modules',
            'PATH' => getenv('PATH') ?: (isset($_SERVER['PATH']) ? $_SERVER['PATH'] : ''),
            'DISPLAY' => ':99', // Use virtual display by default
        ]);

        $process->setEnv($env);

        try {
            $process->mustRun();

            return $process->getOutput();

        } catch (ProcessFailedException $e) {
            throw new Exception('Script execution failed: '.$e->getMessage());
        }
    }

    /**
     * Parse script output
     *
     * @throws Exception
     */
    private function parseScriptOutput(string $output): ?array
    {
        $output = trim($output);

        $this->log('debug', 'Raw script output', ['output' => $output]);

        if (empty($output)) {
            throw new Exception('Script returned empty output');
        }

        // Try to find JSON in the output (script might have console.log statements)
        $lines = explode("\n", $output);
        $jsonLine = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '{"success"') === 0) {
                $jsonLine = $line;
                break;
            }
        }

        if (! $jsonLine) {
            throw new Exception('No valid JSON found in script output: '.substr($output, 0, 500));
        }

        $result = json_decode($jsonLine, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON output: '.json_last_error_msg().' | Line: '.$jsonLine);
        }

        if (! isset($result['success'])) {
            throw new Exception('Invalid response format. Full output: '.$output);
        }

        if (! $result['success']) {
            throw new Exception('Script execution failed: '.($result['error'] ?? 'Unknown error').' | Full output: '.$output);
        }

        $this->log('info', 'CEP script execution successful', [
            'has_data' => isset($result['data']) && ! is_null($result['data']),
            'data_type' => isset($result['data']) ? gettype($result['data']) : 'null',
        ]);

        return $result['data'] ?? null;
    }

    /**
     * Sanitize form data for logging (remove sensitive information)
     */
    private function sanitizeLogData(array $formData): array
    {
        $sanitized = $formData;

        // Mask sensitive fields
        if (isset($sanitized['cuenta'])) {
            $sanitized['cuenta'] = '***'.substr($sanitized['cuenta'], -4);
        }

        if (isset($sanitized['criterio'])) {
            $sanitized['criterio'] = '***'.substr($sanitized['criterio'], -3);
        }

        return $sanitized;
    }

    /**
     * Format date for CEP form (dd-mm-yyyy)
     *
     * @param  string|DateTime  $date
     */
    public static function formatDate($date): string
    {
        if ($date instanceof DateTime) {
            return $date->format('d-m-Y');
        }

        // Try to parse string date
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        if ($dateTime) {
            return $dateTime->format('d-m-Y');
        }

        // Convert slashes to dashes if needed
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return str_replace('/', '-', $date);
        }

        // Return as-is if already in correct format
        return $date;
    }

    /**
     * Get bank code by name (case-insensitive search)
     *
     * @throws Exception
     */
    public function getBankCodeByName(string $bankName): ?string
    {
        $banks = $this->getBankOptions();
        $bankName = strtolower(trim($bankName));

        foreach ($banks as $code => $name) {
            if (str_contains(strtolower($name), $bankName)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Set the working directory for script execution
     */
    public function setWorkingDirectory(string $path): self
    {
        $this->workingDirectory = $path;

        return $this;
    }

    /**
     * Log a message using the provided logger or do nothing
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
