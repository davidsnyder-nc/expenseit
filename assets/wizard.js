// Wizard state management
let currentStep = 1;
const totalSteps = 5;
let tripData = {
    metadata: {},
    files: [],
    expenses: []
};

// Initialize wizard
document.addEventListener('DOMContentLoaded', function() {
    feather.replace();
    initializeWizard();
    setupFileUpload();
});

function initializeWizard() {
    updateProgressBar();
    updateStepVisibility();
    updateNavigationButtons();
}

// Navigation functions
function nextStep() {
    if (validateCurrentStep()) {
        saveCurrentStepData();
        
        if (currentStep < totalSteps) {
            currentStep++;
            
            // Auto-advance from step 3 if receipts are processed
            if (currentStep === 3 && tripData.files.length === 0) {
                // Skip processing step if no files
                currentStep++;
            }
            
            updateWizard();
            
            // Auto-advance from step 3 after a delay to show processing
            if (currentStep === 3 && tripData.files.length > 0) {
                setTimeout(() => {
                    if (currentStep === 3) { // Still on step 3
                        currentStep++;
                        updateWizard();
                    }
                }, 2000); // 2 second delay to show processing
            }
        }
    }
}

function previousStep() {
    if (currentStep > 1) {
        currentStep--;
        updateWizard();
    }
}

function updateWizard() {
    updateProgressBar();
    updateStepVisibility();
    updateNavigationButtons();
    updateStepStates();
    feather.replace();
}

function updateProgressBar() {
    const progressFill = document.getElementById('progressFill');
    const percentage = (currentStep / totalSteps) * 100;
    progressFill.style.width = `${percentage}%`;
}

function updateStepVisibility() {
    // Hide all step contents
    document.querySelectorAll('.step-content').forEach(step => {
        step.classList.remove('active');
    });
    
    // Show current step
    const currentStepElement = document.getElementById(`step${currentStep}`);
    if (currentStepElement) {
        currentStepElement.classList.add('active');
    }
}

function updateNavigationButtons() {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    
    // Previous button
    if (currentStep === 1) {
        prevBtn.style.display = 'none';
    } else {
        prevBtn.style.display = 'inline-flex';
    }
    
    // Next button
    if (currentStep === totalSteps) {
        nextBtn.style.display = 'none';
    } else if (currentStep === 3 && window.isProcessing) {
        // Hide next button during automatic processing in step 3
        nextBtn.style.display = 'none';
    } else {
        nextBtn.innerHTML = currentStep === totalSteps - 1 ? 
            'Complete <i data-feather="check"></i>' : 
            'Next <i data-feather="arrow-right"></i>';
        nextBtn.style.display = 'inline-flex';
    }
}

function updateStepStates() {
    document.querySelectorAll('.step').forEach((step, index) => {
        const stepNumber = index + 1;
        step.classList.remove('active', 'completed');
        
        if (stepNumber === currentStep) {
            step.classList.add('active');
        } else if (stepNumber < currentStep) {
            step.classList.add('completed');
        }
    });
}

// Validation functions
function validateCurrentStep() {
    switch (currentStep) {
        case 1:
            return validateTripInfo();
        case 2:
            return true; // File upload is optional
        case 3:
            return true; // Processing is optional
        case 4:
            return true; // Review is always valid
        case 5:
            return true; // Completion step
        default:
            return true;
    }
}

function validateTripInfo() {
    const tripName = document.getElementById('tripName').value.trim();
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    if (!tripName) {
        showErrorMessage('Please enter a trip name');
        return false;
    }
    
    if (!startDate) {
        showErrorMessage('Please select a start date');
        return false;
    }
    
    if (!endDate) {
        showErrorMessage('Please select an end date');
        return false;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        showErrorMessage('End date must be after start date');
        return false;
    }
    
    return true;
}

// Data saving functions
function saveCurrentStepData() {
    switch (currentStep) {
        case 1:
            saveTripInfo();
            break;
        case 2:
            // Files are already saved in tripData.files
            break;
        case 3:
            // Processing results are already saved
            break;
        case 4:
            saveReviewedExpenses();
            break;
    }
}

function saveTripInfo() {
    tripData.metadata = {
        name: document.getElementById('tripName').value.trim(),
        start_date: document.getElementById('startDate').value,
        end_date: document.getElementById('endDate').value,
        notes: document.getElementById('tripNotes').value.trim()
    };
}

function saveReviewedExpenses() {
    // Get expenses from the review table
    const expenseRows = document.querySelectorAll('#expensesTable .expense-row');
    tripData.expenses = Array.from(expenseRows).map(row => {
        return {
            id: row.dataset.expenseId,
            date: row.querySelector('.expense-date').value,
            merchant: row.querySelector('.expense-merchant').value,
            amount: parseFloat(row.querySelector('.expense-amount').value),
            category: row.querySelector('.expense-category').value,
            note: row.querySelector('.expense-note').value,
            source: row.dataset.source || ''
        };
    });
}

// File upload functionality
function setupFileUpload() {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('fileInput');
    
    // Click to browse
    dropzone.addEventListener('click', () => {
        fileInput.click();
    });
    
    // Drag and drop
    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    
    dropzone.addEventListener('dragleave', () => {
        dropzone.classList.remove('dragover');
    });
    
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    
    // File input change
    fileInput.addEventListener('change', (e) => {
        handleFiles(e.target.files);
    });
}

function handleFiles(files) {
    Array.from(files).forEach(file => {
        if (isValidFile(file)) {
            uploadFile(file);
        } else {
            showErrorMessage(`Invalid file type: ${file.name}. Please upload PDF, JPEG, PNG, HEIC, or TIFF files.`);
        }
    });
}

function isValidFile(file) {
    const validTypes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg', 'image/heic', 'image/tiff', 'image/tif', 'image/webp', 'image/bmp', 'image/gif'];
    const extension = file.name.toLowerCase().split('.').pop();
    const validExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'tiff', 'tif', 'webp', 'bmp', 'gif'];
    
    // Accept based on MIME type or file extension for better compatibility
    return validTypes.includes(file.type) || validExtensions.includes(extension);
}

async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('tripName', tripData.metadata.name || 'temp');
    
    try {
        const response = await fetch('upload.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            tripData.files.push({
                name: file.name,
                path: result.path,
                size: file.size,
                type: file.type
            });
            displayUploadedFile(file, result.path);
            
            // Automatically process the uploaded receipt
            await processSingleReceipt(file, result.path);
        } else {
            showErrorMessage(`Upload failed: ${result.error}`);
        }
    } catch (error) {
        console.error('Upload error:', error);
        showErrorMessage('Upload failed. Please try again.');
    }
}

function displayUploadedFile(file, path) {
    const uploadedFiles = document.getElementById('uploadedFiles');
    
    const fileItem = document.createElement('div');
    fileItem.className = 'file-item';
    fileItem.dataset.path = path;
    
    const isImage = file.type.startsWith('image/');
    const isPdf = file.type === 'application/pdf';
    const fileSize = formatFileSize(file.size);
    
    let preview = '';
    if (isImage) {
        preview = `<img src="${path}" alt="${file.name}">`;
    } else if (isPdf) {
        preview = `<div class="file-icon"><i data-feather="file-text"></i></div>`;
    } else {
        preview = `<div class="file-icon"><i data-feather="file"></i></div>`;
    }
    
    fileItem.innerHTML = `
        ${preview}
        <div class="file-name">${file.name}</div>
        <div class="file-size">${fileSize}</div>
        <button class="btn btn-small btn-danger" onclick="removeFile('${path}')">
            <i data-feather="trash-2"></i> Remove
        </button>
    `;
    
    uploadedFiles.appendChild(fileItem);
    feather.replace();
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function removeFile(path) {
    // Remove from tripData.files
    tripData.files = tripData.files.filter(file => file.path !== path);
    
    // Remove from UI
    const fileItems = document.querySelectorAll('.file-item');
    fileItems.forEach(item => {
        if (item.dataset.path === path) {
            item.remove();
        }
    });
}

// Processing functions
async function processSingleReceipt(file, path) {
    try {
        const response = await fetch('gemini.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                filePath: path,
                fileName: file.name
            })
        });
        
        const result = await response.json();
        
        if (result.success && result.expense) {
            const expense = {
                id: generateId(),
                ...result.expense,
                source: 'receipts/' + file.name
            };
            tripData.expenses.push(expense);
            
            // Update processing display if on step 3
            if (currentStep === 3) {
                displayProcessingResult(file.name, expense, true);
            }
        } else {
            if (currentStep === 3) {
                displayProcessingResult(file.name, null, false, result.error);
            }
        }
    } catch (error) {
        console.error('Processing error:', error);
        if (currentStep === 3) {
            displayProcessingResult(file.name, null, false, 'Network error');
        }
    }
}

async function processReceipts() {
    const processBtn = document.getElementById('processBtn');
    const processingStatus = document.getElementById('processingStatus');
    const processingResults = document.getElementById('processingResults');
    
    if (tripData.files.length === 0) {
        showErrorMessage('Please upload some receipts first');
        return;
    }
    
    // Set processing flag and hide next button
    window.isProcessing = true;
    updateNavigationButtons();
    
    // Update UI
    processBtn.disabled = true;
    processBtn.innerHTML = '<i data-feather="loader"></i> Processing...';
    processingStatus.innerHTML = '<p>Processing receipts with AI...</p>';
    processingResults.innerHTML = '';
    
    let processedCount = 0;
    let errors = 0;
    
    for (const file of tripData.files) {
        try {
            const response = await fetch('gemini.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filePath: file.path,
                    fileName: file.name
                })
            });
            
            const result = await response.json();
            
            if (result.success && result.expense) {
                // Add processed expense to tripData
                const expense = {
                    id: generateId(),
                    ...result.expense,
                    source: 'receipts/' + file.name
                };
                tripData.expenses.push(expense);
                
                // Display success
                displayProcessingResult(file.name, expense, true);
                processedCount++;
            } else {
                displayProcessingResult(file.name, null, false, result.error);
                errors++;
            }
        } catch (error) {
            console.error('Processing error:', error);
            displayProcessingResult(file.name, null, false, 'Network error');
            errors++;
        }
    }
    
    // Clear processing flag and update navigation
    window.isProcessing = false;
    updateNavigationButtons();
    
    // Update UI
    processBtn.disabled = false;
    processBtn.innerHTML = '<i data-feather="refresh-cw"></i> Process Again';
    processingStatus.innerHTML = `
        <p>Processing complete! ${processedCount} receipts processed successfully, ${errors} errors.</p>
        ${processedCount > 0 ? '<p>Automatically advancing to review step...</p>' : ''}
    `;
    
    feather.replace();
    
    // Auto-advance to step 4 if we have processed expenses
    if (processedCount > 0) {
        setTimeout(() => {
            currentStep = 4;
            updateWizard();
        }, 2000);
    }
}

function displayProcessingResult(fileName, expense, success, error = null) {
    const processingResults = document.getElementById('processingResults');
    
    const resultItem = document.createElement('div');
    resultItem.className = `processing-item ${success ? 'success' : 'error'}`;
    
    if (success) {
        resultItem.innerHTML = `
            <h4><i data-feather="check-circle"></i> ${fileName}</h4>
            <p><strong>Date:</strong> ${expense.date}</p>
            <p><strong>Merchant:</strong> ${expense.merchant}</p>
            <p><strong>Amount:</strong> $${expense.amount}</p>
            <p><strong>Category:</strong> ${expense.category}</p>
        `;
    } else {
        resultItem.innerHTML = `
            <h4><i data-feather="x-circle"></i> ${fileName}</h4>
            <p>Failed to process: ${error || 'Unknown error'}</p>
        `;
    }
    
    processingResults.appendChild(resultItem);
    feather.replace();
}

// Review step functions
function setupReviewStep() {
    const expensesTable = document.getElementById('expensesTable');
    
    if (tripData.expenses.length === 0) {
        expensesTable.innerHTML = `
            <div class="empty-state">
                <i data-feather="inbox"></i>
                <h3>No expenses to review</h3>
                <p>No expenses were extracted from your receipts. You can add expenses manually after creating the trip.</p>
            </div>
        `;
    } else {
        expensesTable.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Merchant</th>
                        <th>Amount</th>
                        <th>Category</th>
                        <th>Note</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${tripData.expenses.map(expense => `
                        <tr class="expense-row" data-expense-id="${expense.id}" data-source="${expense.source || ''}">
                            <td><input type="date" class="expense-date" value="${expense.date}"></td>
                            <td><input type="text" class="expense-merchant" value="${expense.merchant || ''}"></td>
                            <td><input type="number" class="expense-amount" step="0.01" value="${expense.amount}"></td>
                            <td>
                                <select class="expense-category">
                                    <option value="Meals" ${expense.category === 'Meals' ? 'selected' : ''}>Meals</option>
                                    <option value="Transportation" ${expense.category === 'Transportation' ? 'selected' : ''}>Transportation</option>
                                    <option value="Lodging" ${expense.category === 'Lodging' ? 'selected' : ''}>Lodging</option>
                                    <option value="Entertainment" ${expense.category === 'Entertainment' ? 'selected' : ''}>Entertainment</option>
                                    <option value="Groceries" ${expense.category === 'Groceries' ? 'selected' : ''}>Groceries</option>
                                    <option value="Shopping" ${expense.category === 'Shopping' ? 'selected' : ''}>Shopping</option>
                                    <option value="Gas" ${expense.category === 'Gas' ? 'selected' : ''}>Gas</option>
                                    <option value="Other" ${expense.category === 'Other' ? 'selected' : ''}>Other</option>
                                </select>
                            </td>
                            <td><input type="text" class="expense-note" value="${expense.note || ''}"></td>
                            <td>
                                <button class="btn btn-small btn-danger" onclick="removeExpense('${expense.id}')">
                                    <i data-feather="trash-2"></i>
                                </button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <div style="margin-top: 1rem; padding: 0 1rem;">
                <button class="btn btn-outline" onclick="addManualExpense()">
                    <i data-feather="plus"></i> Add Manual Expense
                </button>
            </div>
        `;
    }
    
    feather.replace();
}

function removeExpense(expenseId) {
    tripData.expenses = tripData.expenses.filter(expense => expense.id !== expenseId);
    setupReviewStep();
}

function addManualExpense() {
    const newExpense = {
        id: generateId(),
        date: new Date().toISOString().split('T')[0],
        merchant: '',
        amount: 0,
        category: 'Other',
        note: '',
        source: ''
    };
    
    tripData.expenses.push(newExpense);
    setupReviewStep();
}

// Completion functions
async function completeTrip() {
    try {
        // Save trip data
        const response = await fetch('save_trip.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'create_trip',
                tripData: tripData
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentStep = 5;
            updateWizard();
            setupCompletionStep();
        } else {
            showErrorMessage('Error creating trip: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error completing trip:', error);
        showErrorMessage('Error creating trip. Please try again.');
    }
}

function setupCompletionStep() {
    const completionSummary = document.getElementById('completionSummary');
    const total = tripData.expenses.reduce((sum, expense) => sum + parseFloat(expense.amount || 0), 0);
    
    completionSummary.innerHTML = `
        <div class="success-icon">
            <i data-feather="check-circle"></i>
        </div>
        <h3>Trip Created Successfully!</h3>
        <p>Your trip "${tripData.metadata.name}" has been created with ${tripData.expenses.length} expenses totaling $${total.toFixed(2)}.</p>
        <div class="completion-actions">
            <button class="btn btn-primary" onclick="viewTrip()">
                <i data-feather="eye"></i> View Trip
            </button>
            <button class="btn btn-secondary" onclick="downloadReport()">
                <i data-feather="download"></i> Download PDF
            </button>
            <button class="btn btn-outline" onclick="createAnother()">
                <i data-feather="plus"></i> Create Another Trip
            </button>
        </div>
    `;
    
    feather.replace();
}

function viewTrip() {
    window.location.href = `trip.html?trip=${encodeURIComponent(tripData.metadata.name)}`;
}

async function downloadReport() {
    try {
        const response = await fetch(`generate_pdf.php?trip=${encodeURIComponent(tripData.metadata.name)}`);
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${tripData.metadata.name}-report.pdf`;
            a.click();
            window.URL.revokeObjectURL(url);
        } else {
            showErrorMessage('Error generating PDF report');
        }
    } catch (error) {
        console.error('Error downloading report:', error);
        showErrorMessage('Error downloading report');
    }
}

function createAnother() {
    window.location.href = 'wizard.html';
}

// Utility functions
function generateId() {
    return Math.random().toString(36).substr(2, 9);
}

// Step-specific initialization
document.addEventListener('DOMContentLoaded', function() {
    // Watch for step changes to initialize step-specific functionality
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                const target = mutation.target;
                if (target.classList.contains('active')) {
                    const stepId = target.id;
                    if (stepId === 'step4') {
                        setupReviewStep();
                    }
                }
            }
        });
    });
    
    document.querySelectorAll('.step-content').forEach(step => {
        observer.observe(step, { attributes: true });
    });
});

// Override nextStep for step 4 to complete trip
const originalNextStep = nextStep;
nextStep = function() {
    if (currentStep === 4) {
        saveCurrentStepData();
        completeTrip();
    } else {
        originalNextStep();
    }
};

// Notification functions
function showSuccessMessage(message) {
    createToast(message, 'success');
}

function showErrorMessage(message) {
    createToast(message, 'error');
}

function createToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <i data-feather="${type === 'success' ? 'check-circle' : 'alert-circle'}"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(toast);
    feather.replace();
    
    setTimeout(() => {
        toast.classList.add('show');
    }, 100);
    
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 3000);
}
