<?php

namespace Carlosupreme\CEPQueryPayment;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;

class CEPQueryService
{
    private Client $http;

    private int $timeout;

    private array $defaultOptions;

    /** @var callable|null */
    private $logger;

    private string $baseUri = 'https://www.banxico.org.mx';

    public function __construct(?Client $httpClient = null, ?callable $logger = null)
    {
        $this->timeout = 60;

        $this->defaultOptions = [
            'timeout' => $this->timeout,
        ];

        $this->http = $httpClient ?? new Client([
            'base_uri' => $this->baseUri,
            'timeout'  => $this->timeout,
            'verify'   => true,
            'headers'  => [
                'User-Agent'        => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
                'Accept'            => '*/*',
                'X-Requested-With'  => 'XMLHttpRequest',
            ],
        ]);

        $this->logger = $logger;
    }

    /**
     * Query CEP using form data via direct POST to Banxico.
     *
     * @param  array  $formData
     * @param  array  $options  (optional timeout override, etc.)
     * @return array|null
     *
     * @throws Exception
     */
    public function queryPayment(array $formData, array $options = []): ?array
    {
        $this->validateFormData($formData);

        $timeout = $options['timeout'] ?? $this->timeout;

        $jar = new CookieJar();

        try {
            // Warm up session (cookies, etc.)
            $this->http->get('/cep/', [
                'cookies' => $jar,
                'timeout' => $timeout,
            ]);

            $payload = [
                'captcha'             => '',
                'criterio'            => $formData['criterio'],
                'cuenta'              => $formData['cuenta'],
                'emisor'              => $formData['emisor'],
                'fecha'               => $formData['fecha'],
                'monto'               => $formData['monto'],
                'receptor'            => $formData['receptor'],
                'receptorParticipante'=> 0,
                'tipoConsulta'        => 0,
                'tipoCriterio'        => $formData['tipoCriterio'],
            ];

            $this->log('debug', 'Sending CEP request', [
                'payload' => $this->sanitizeLogData($payload),
            ]);

            $response = $this->http->post('/cep/valida.do', [
                'cookies'     => $jar,
                'timeout'     => $timeout,
                'headers'     => [
                    'Content-Type'    => 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Origin'          => $this->baseUri,
                    'Referer'         => $this->baseUri . '/cep/',
                    'Sec-Fetch-Site'  => 'same-origin',
                    'Sec-Fetch-Mode'  => 'cors',
                    'Sec-Fetch-Dest'  => 'empty',
                ],
                'form_params' => $payload,
            ]);

            $html = (string) $response->getBody();

            $this->log('debug', 'Raw CEP response (truncated)', [
                'html' => mb_substr($html, 0, 2000),
            ]);

            $parsed = $this->parseHtmlResponse($html);

            $this->log('info', 'CEP response parsed', [
                'has_data'  => $parsed !== null,
                'data_type' => $parsed['type'] ?? 'null',
            ]);

            return $parsed;
        } catch (GuzzleException $e) {
            $this->log('error', 'CEP HTTP request failed', [
                'error'    => $e->getMessage(),
                'formData' => $this->sanitizeLogData($formData),
            ]);

            throw new Exception('CEP HTTP request failed: ' . $e->getMessage(), 0, $e);
        } catch (Exception $e) {
            $this->log('error', 'CEP Query failed', [
                'error'    => $e->getMessage(),
                'formData' => $this->sanitizeLogData($formData),
            ]);

            throw $e;
        }
    }

    /**
     * Get available bank options by scraping the CEP page.
     * TODO: Load them using the instituciones.do endpoint if possible.
     *
     * @return array
     *
     * @throws Exception
     */
    public function getBankOptions(): array
    {
        $jar = new CookieJar();

        try {
            $response = $this->http->get('/cep/', [
                'cookies' => $jar,
                'timeout' => $this->timeout,
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ]);

            $html = (string) $response->getBody();

            $this->log('debug', 'Bank options raw page (truncated)', [
                'html' => mb_substr($html, 0, 2000),
            ]);

            return $this->parseBankOptionsFromHtml($html);
        } catch (GuzzleException $e) {
            $this->log('error', 'Failed to get bank options', ['error' => $e->getMessage()]);
            throw new Exception('Failed to get bank options: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate form data.
     *
     * @throws Exception
     */
    private function validateFormData(array &$formData): void
    {
        $required = ['fecha', 'tipoCriterio', 'criterio', 'emisor', 'receptor', 'cuenta', 'monto'];

        foreach ($required as $field) {
            if (!isset($formData[$field]) || $formData[$field] === '') {
                throw new Exception("Required field missing: {$field}");
            }
        }

        if (!in_array($formData['tipoCriterio'], ['T', 'R'], true)) {
            throw new Exception("Invalid tipoCriterio. Must be 'T' (tracking key) or 'R' (reference number)");
        }

        if ($formData['tipoCriterio'] === 'R' && strlen($formData['criterio']) > 7) {
            throw new Exception('Reference number cannot exceed 7 characters');
        }

        if ($formData['tipoCriterio'] === 'T' && strlen($formData['criterio']) > 30) {
            throw new Exception('Tracking key cannot exceed 30 characters');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $formData['fecha'])) {
            $formData['fecha'] = str_replace('/', '-', $formData['fecha']);
        } elseif (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $formData['fecha'])) {
            throw new Exception('Invalid date format. Use dd-mm-yyyy or dd/mm/yyyy');
        }

        if (isset($formData['cuenta']) && strlen($formData['cuenta']) === 18 && !preg_match('/^\d{18}$/', $formData['cuenta'])) {
            throw new Exception('Invalid CLABE format. Must be 18 digits');
        }

        if (!is_numeric(str_replace(',', '', (string)$formData['monto']))) {
            throw new Exception('Invalid amount format');
        }

        if (!is_numeric($formData['emisor']) || !is_numeric($formData['receptor'])) {
            throw new Exception('Invalid bank codes. Must be numeric');
        }
    }

    /**
     * Parse CEP HTML response into a structured array.
     */
    private function parseHtmlResponse(string $html): ?array
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        if (!$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            $content = trim(strip_tags($html));
            return $content !== '' ? ['type' => 'text', 'content' => $content] : null;
        }

        $xpath = new \DOMXPath($dom);

        // Specific table with payment info (matches sample HTML)
        $table = $xpath->query("//div[@id='consultaMISPEI']//table[@id='xxx' or contains(@class,'styled-table')]")->item(0)
            ?: $xpath->query("//div[@id='consultaMISPEI']//table")->item(0)
            ?: $xpath->query('//table')->item(0);

        if (!$table instanceof \DOMElement) {
            $text = trim($xpath->evaluate('string(//div[@id="consultaMISPEI"] | //div[@class="cuerpo-msg"] | //body)'));

            return [
                'type'    => 'text',
                'content' => $text !== '' ? $text : trim(strip_tags($html)),
            ];
        }

        $rows = [];

        // Each row: <tr><td>Label</td><td>Value</td></tr>
        $rowNodes = $xpath->query('.//tbody//tr', $table);
        if (!$rowNodes || $rowNodes->length === 0) {
            $rowNodes = $xpath->query('.//tr', $table);
        }

        foreach ($rowNodes as $rowNode) {
            /** @var \DOMElement $rowNode */
            $cellNodes = $xpath->query('.//td|.//th', $rowNode);
            if ($cellNodes->length < 2) {
                continue;
            }

            $label = trim($cellNodes->item(0)->textContent ?? '');
            $value = trim($cellNodes->item(1)->textContent ?? '');

            if ($label === '' && $value === '') {
                continue;
            }

            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        if ($rows === []) {
            $text = trim($xpath->evaluate('string(//div[@id="consultaMISPEI"] | //div[@class="cuerpo-msg"] | //body)'));

            return [
                'type'    => 'text',
                'content' => $text !== '' ? $text : trim(strip_tags($html)),
            ];
        }

        // Summary above the table
        $summary = trim($xpath->evaluate(
            'string(//div[@id="consultaMISPEI"]//div[contains(@class,"info")]/center/strong)'
        ));

        return [
            'type'    => 'table',
            'summary' => $summary !== '' ? $summary : null,
            'headers' => ['label', 'value'],
            'rows'    => $rows,
        ];
    }

    /**
     * Parse bank options from CEP HTML page.
     */
    private function parseBankOptionsFromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        if (!$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return [];
        }

        $xpath = new \DOMXPath($dom);
        $select = $xpath->query('//select[@id="input_emisor"]')->item(0);

        if (!$select instanceof \DOMElement) {
            return [];
        }

        $options = [];
        foreach ($xpath->query('.//option', $select) as $opt) {
            /** @var \DOMElement $opt */
            $value = trim($opt->getAttribute('value'));
            $label = trim($opt->textContent);

            if ($value !== '') {
                $options[$value] = $label;
            }
        }

        return $options;
    }

    /**
     * Sanitize form data for logging (mask sensitive information).
     */
    private function sanitizeLogData(array $formData): array
    {
        $sanitized = $formData;

        if (isset($sanitized['cuenta'])) {
            $sanitized['cuenta'] = '***' . substr($sanitized['cuenta'], -4);
        }

        if (isset($sanitized['criterio'])) {
            $sanitized['criterio'] = '***' . substr($sanitized['criterio'], -3);
        }

        return $sanitized;
    }

    /**
     * Format date for CEP form (dd-mm-yyyy).
     *
     * @param  string|DateTime  $date
     */
    public static function formatDate($date): string
    {
        if ($date instanceof DateTime) {
            return $date->format('d-m-Y');
        }

        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        if ($dateTime) {
            return $dateTime->format('d-m-Y');
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return str_replace('/', '-', $date);
        }

        return $date;
    }

    /**
     * Get bank code by name (case-insensitive search).
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
     * Log a message using the provided logger or do nothing.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
