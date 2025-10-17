/**
 * CEP Form Filler - Banco de México Electronic Payment Receipt Query
 * Fills the SPEI payment query form and extracts table results
 */

class CEPFormFiller {
    constructor() {
        this.form = document.getElementById('fConsulta');
        this.submitButton = document.getElementById('btn_Consultar');
        this.fields = this.detectFields();
    }

    /**
     * Detect all form fields that need to be filled
     */
    detectFields() {
        return {
            // Date field - payment date
            fecha: {
                element: document.getElementById('input_fecha'),
                type: 'date',
                required: true,
                description: 'Fecha en la que realizó el pago'
            },
            
            // Search criteria type - tracking key or reference number
            tipoCriterio: {
                element: document.getElementById('input_tipoCriterio'),
                type: 'select',
                required: true,
                options: {
                    'T': 'Clave de rastreo',
                    'R': 'Número de referencia'
                },
                description: 'Criterio de búsqueda'
            },
            
            // Search criteria value
            criterio: {
                element: document.getElementById('input_criterio'),
                type: 'text',
                required: true,
                maxLength: 7,
                description: 'Número de referencia o clave de rastreo'
            },
            
            // Sender institution
            emisor: {
                element: document.getElementById('input_emisor'),
                type: 'select',
                required: true,
                description: 'Institución emisora del pago'
            },
            
            // Receiver institution
            receptor: {
                element: document.getElementById('input_receptor'),
                type: 'select',
                required: true,
                description: 'Institución receptora del pago'
            },
            
            // Beneficiary account (CLABE, debit card, or phone number)
            cuenta: {
                element: document.getElementById('input_cuenta'),
                type: 'text',
                required: false, // Required only for CEP download, not for payment query
                maxLength: 18,
                description: 'Cuenta Beneficiaria (CLABE, tarjeta de débito o número de celular)'
            },
            
            // Payment to bank checkbox
            receptorParticipante: {
                element: document.getElementById('input_benef_es_part'),
                type: 'checkbox',
                required: false,
                description: 'Pago a Banco'
            },
            
            // Payment amount
            monto: {
                element: document.getElementById('input_monto'),
                type: 'text',
                required: false, // Required only for CEP download, not for payment query
                maxLength: 15,
                description: 'Monto del pago'
            },
            
            // CAPTCHA (if visible)
            captcha: {
                element: document.getElementById('input_captcha'),
                type: 'text',
                required: false, // Only required if CAPTCHA is shown
                description: 'Código de seguridad'
            }
        };
    }

    /**
     * Fill form with provided data
     * @param {Object} data - Form data to fill
     */
    fillForm(data) {
        Object.keys(data).forEach(fieldName => {
            const field = this.fields[fieldName];
            if (field && field.element) {
                const value = data[fieldName];
                
                switch (field.type) {
                    case 'text':
                    case 'date':
                        field.element.value = value;
                        // Trigger change event
                        field.element.dispatchEvent(new Event('change', { bubbles: true }));
                        break;
                        
                    case 'select':
                        field.element.value = value;
                        field.element.dispatchEvent(new Event('change', { bubbles: true }));
                        break;
                        
                    case 'checkbox':
                        field.element.checked = Boolean(value);
                        field.element.dispatchEvent(new Event('change', { bubbles: true }));
                        break;
                }
            }
        });
    }

    /**
     * Enable the submit button by removing disabled class
     */
    enableSubmitButton() {
        if (this.submitButton) {
            this.submitButton.classList.remove('disabled');
            this.submitButton.style.cursor = 'pointer';
        }
    }

    /**
     * Submit the form and wait for response
     * @returns {Promise} Promise that resolves with the table data or null
     */
    async submitForm() {
        return new Promise((resolve, reject) => {
            // Enable submit button first
            this.enableSubmitButton();

            // Set up mutation observer to watch for the result modal
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                        const target = mutation.target;
                        
                        // Check if the validation modal is shown
                        if (target.id === 'divValidacionPertenencia' && 
                            target.style.display !== 'none') {
                            
                            // Wait a bit for content to load
                            setTimeout(() => {
                                const tableBody = this.extractTableData();
                                observer.disconnect();
                                resolve(tableBody);
                            }, 1000);
                        }
                    }
                });
            });

            // Start observing
            const modalDiv = document.getElementById('divValidacionPertenencia');
            if (modalDiv) {
                observer.observe(modalDiv, {
                    attributes: true,
                    attributeFilter: ['style']
                });
            }

            // Click the submit button
            if (this.submitButton && !this.submitButton.classList.contains('disabled')) {
                this.submitButton.click();
            } else {
                reject(new Error('Submit button is disabled or not found'));
            }

            // Set timeout to avoid infinite waiting
            setTimeout(() => {
                observer.disconnect();
                reject(new Error('Timeout waiting for response'));
            }, 30000); // 30 seconds timeout
        });
    }

    /**
     * Extract table data from the response and format as JSON
     * @returns {Object|null} Table data as JSON or null if no table found
     */
    extractTableData() {
        // Look for table within the consultation area
        const consultaDiv = document.querySelector('#consultaMISPEI');
        if (!consultaDiv) {
            return null;
        }

        // Check for table with tbody
        const table = consultaDiv.querySelector('table');
        if (!table) {
            return null;
        }

        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return null;
        }

        // Extract table data
        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (rows.length === 0) {
            return null;
        }

        const tableData = {
            headers: [],
            rows: []
        };

        // Extract headers from thead or first row
        const thead = table.querySelector('thead');
        if (thead) {
            const headerRow = thead.querySelector('tr');
            if (headerRow) {
                tableData.headers = Array.from(headerRow.querySelectorAll('th, td'))
                    .map(cell => cell.textContent.trim());
            }
        }

        // Extract data rows
        tableData.rows = rows.map(row => {
            return Array.from(row.querySelectorAll('td, th'))
                .map(cell => cell.textContent.trim());
        });

        return tableData;
    }

    /**
     * Get available bank options for emisor/receptor selects
     * @returns {Object} Bank options with codes and names
     */
    getBankOptions() {
        const emisorSelect = document.getElementById('input_emisor');
        const options = {};
        
        if (emisorSelect) {
            Array.from(emisorSelect.options).forEach(option => {
                if (option.value) {
                    options[option.value] = option.textContent.trim();
                }
            });
        }
        
        return options;
    }

    /**
     * Complete form filling and submission workflow
     * @param {Object} formData - Data to fill in the form
     * @returns {Promise} Promise that resolves with table data or null
     */
    async fillAndSubmit(formData) {
        try {
            // Fill the form
            this.fillForm(formData);
            
            // Submit and wait for response
            const result = await this.submitForm();
            
            return result;
        } catch (error) {
            console.error('Error in form submission:', error);
            throw error;
        }
    }
}

// Usage example:
// const formFiller = new CEPFormFiller();
// 
// const formData = {
//     fecha: '03/08/2025',
//     tipoCriterio: 'R', // 'R' for reference number, 'T' for tracking key
//     criterio: '1234567',
//     emisor: '40002', // BANAMEX code
//     receptor: '40014' // SANTANDER code
// };
// 
// formFiller.fillAndSubmit(formData)
//     .then(result => {
//         console.log('Query result:', result);
//     })
//     .catch(error => {
//         console.error('Error:', error);
//     });

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CEPFormFiller;
}

// Make available globally
window.CEPFormFiller = CEPFormFiller;