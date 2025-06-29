// Wizard state management
let currentStep = 1;
const totalSteps = 3;
let tripData = {
    metadata: { name: null },
    files: [],
    expenses: [],
    travelDocuments: [],
    hasItinerary: false,
    needsReview: false,
    sessionTripName: null // Consistent trip name for the session
};

// Initialize wizard
document.addEventListener('DOMContentLoaded', function() {
    feather.replace();
    initializeWizard();
    setupFileUpload();
    setupTravelDocumentUpload();
});

function initializeWizard() {
    updateProgressBar();
    updateStepVisibility();
    updateNavigationButtons();
    setupMobileCamera();
}

// Navigation functions
function nextStep() {
    if (validateCurrentStep()) {
        saveCurrentStepData();
        
        if (currentStep < totalSteps) {
            currentStep++;
            
            // Skip step 3 entirely - processing is automatic
            if (currentStep === 3) {
                // Automatically trigger processing and move to step 4
                startAutomaticProcessing().then(() => {
                    currentStep++;
                    updateWizard();
                });
                return; // Don't update wizard yet, wait for processing
            }
            
            updateWizard();
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
    
    // Next button - hide on final step
    if (currentStep === totalSteps) {
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

async function handleFiles(files) {
    // Upload all files first
    const validFiles = Array.from(files).filter(file => isValidFile(file));
    
    if (validFiles.length === 0) {
        showErrorMessage('No valid files selected. Please upload PDF or image files.');
        return;
    }
    
    // Upload files with retry logic and better error handling
    let uploadedCount = 0;
    for (const file of validFiles) {
        try {
            await uploadFileOnly(file);
            uploadedCount++;
            console.log(`Successfully uploaded: ${file.name} (${uploadedCount}/${validFiles.length})`);
            
            // Small delay between uploads to prevent server overload
            if (uploadedCount < validFiles.length) {
                await new Promise(resolve => setTimeout(resolve, 100));
            }
        } catch (error) {
            console.error(`Upload failed for ${file.name}:`, error);
            showErrorMessage(`Failed to upload ${file.name}: ${error.message}`);
            
            // Try one retry for failed uploads
            try {
                console.log(`Retrying upload for ${file.name}...`);
                await new Promise(resolve => setTimeout(resolve, 500));
                await uploadFileOnly(file);
                uploadedCount++;
                console.log(`Retry successful for: ${file.name}`);
            } catch (retryError) {
                console.error(`Retry also failed for ${file.name}:`, retryError);
            }
        }
    }
    
    console.log(`Final upload count: ${tripData.files.length} files uploaded of ${validFiles.length} attempted`);
    
    if (tripData.files.length === 0) {
        showErrorMessage('No files were successfully uploaded. Please try again.');
        return;
    }
    
    if (tripData.files.length < validFiles.length) {
        showErrorMessage(`Only ${tripData.files.length} of ${validFiles.length} files were uploaded successfully. Proceeding with available files.`);
    }
    
    // Start automatic processing
    if (tripData.files.length > 0) {
        await startAutomaticProcessing();
    }
}

function isValidFile(file) {
    const validTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    const extension = file.name.toLowerCase().split('.').pop();
    const validExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
    
    // Accept based on MIME type or file extension for better compatibility
    return validTypes.includes(file.type) || validExtensions.includes(extension);
}

async function uploadFileOnly(file) {
    // Use session trip name or generate one for the first file
    if (!tripData.sessionTripName) {
        tripData.sessionTripName = 'temp_' + Date.now();
        tripData.metadata.name = tripData.sessionTripName;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    formData.append('tripName', tripData.sessionTripName);
    
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
                type: file.type,
                documentType: result.documentType || 'receipt'
            });
            displayUploadedFile(file, result.path);
        } else {
            throw new Error(result.error);
        }
    } catch (error) {
        console.error('Upload error:', error);
        throw error;
    }
}

async function startAutomaticProcessing() {
    // Stay on step 1 but show processing interface
    const processingStatus = document.getElementById('processingStatus');
    const uploadSection = document.querySelector('#step1 .upload-area');
    
    // Hide upload section and show processing
    uploadSection.style.display = 'none';
    processingStatus.style.display = 'block';
    processingStatus.innerHTML = `
        <div class="processing-container">
            <h3>Processing your files...</h3>
            <div class="processing-steps">
                <div class="processing-step" id="step-detect">
                    <i data-feather="search"></i>
                    <span>Analyzing file types</span>
                </div>
                <div class="processing-step" id="step-extract">
                    <i data-feather="file-text"></i>
                    <span>Extracting trip details</span>
                </div>
                <div class="processing-step" id="step-receipts">
                    <i data-feather="receipt"></i>
                    <span>Processing receipts</span>
                </div>
                <div class="processing-step" id="step-complete">
                    <i data-feather="check"></i>
                    <span>Finalizing trip</span>
                </div>
            </div>
        </div>
    `;
    feather.replace();
    
    // Show processing steps
    const steps = ['step-detect', 'step-extract', 'step-receipts', 'step-complete'];
    
    try {
        // Step 1: Detect file types
        updateProcessingStep('step-detect', 'active');
        await detectFileTypes();
        updateProcessingStep('step-detect', 'completed');
        
        // Step 2: Extract trip details if travel document found
        updateProcessingStep('step-extract', 'active');
        await extractTripDetailsFromUploads();
        updateProcessingStep('step-extract', 'completed');
        
        // Step 3: Process receipts
        updateProcessingStep('step-receipts', 'active');
        await processAllReceipts();
        updateProcessingStep('step-receipts', 'completed');
        
        // Step 4: Complete or review
        updateProcessingStep('step-complete', 'active');
        await finalizeTrip();
        updateProcessingStep('step-complete', 'completed');
        
    } catch (error) {
        console.error('Processing error:', error);
        showErrorMessage('Processing failed: ' + error.message);
        tripData.needsReview = true;
        
        // Hide processing interface and show review step
        const processingStatus = document.getElementById('processingStatus');
        const uploadSection = document.querySelector('#step1 .upload-area');
        processingStatus.style.display = 'none';
        uploadSection.style.display = 'block';
        
        currentStep = 2; // Go to review step
        updateWizard();
        setupReviewStep();
    }
}

function updateProcessingStep(stepId, status) {
    const step = document.getElementById(stepId);
    if (step) {
        step.className = `processing-step ${status}`;
    }
}

async function detectFileTypes() {
    // Everything is treated as receipts
    for (const file of tripData.files) {
        file.documentType = 'receipt';
    }
}

async function extractTripDetailsFromUploads() {
    if (tripData.hasItinerary && tripData.travelDocuments.length > 0) {
        try {
            // Extract from the first travel document
            const travelDoc = tripData.travelDocuments[0];
            const response = await fetch('extract_trip_details.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filePath: travelDoc.path,
                    fileName: travelDoc.name
                })
            });
            
            const result = await response.json();
            
            if (result.success && result.tripDetails) {
                // Update trip metadata with extracted details
                const oldName = tripData.metadata.name;
                
                // Create proper trip name from destination and date
                let tripName = result.tripDetails.trip_name || 'Trip';
                if (result.tripDetails.destination) {
                    // Extract city name from full destination (e.g., "Austin, TX" -> "Austin")
                    tripName = result.tripDetails.destination.split(',')[0].trim();
                }
                
                // Add date suffix if start date is available
                if (result.tripDetails.start_date) {
                    const startDate = new Date(result.tripDetails.start_date);
                    const monthYear = startDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                    tripName += ' ' + monthYear;
                }
                
                tripData.metadata = {
                    name: tripName,
                    trip_name: tripName,
                    destination: result.tripDetails.destination || tripName,
                    start_date: result.tripDetails.start_date || result.tripDetails.departure_date || '',
                    end_date: result.tripDetails.end_date || result.tripDetails.return_date || '',
                    notes: result.tripDetails.notes || result.tripDetails.description || ''
                };
                
                // If we got a better trip name, we should move files to the correct directory
                if (tripData.metadata.name !== oldName && tripData.metadata.name !== 'Trip') {
                    await moveFilesToNewTripName(oldName, tripData.metadata.name);
                }
            }
        } catch (error) {
            console.error('Trip detail extraction failed:', error);
        }
    }
}

async function moveFilesToNewTripName(oldName, newName) {
    try {
        const response = await fetch('api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'rename_trip',
                oldName: oldName,
                newName: newName
            })
        });
        
        const result = await response.json();
        if (result.success) {
            // Update file paths in tripData
            tripData.files.forEach(file => {
                file.path = file.path.replace(`/trips/${oldName}/`, `/trips/${newName}/`);
            });
        }
    } catch (error) {
        console.error('Failed to move files to new trip name:', error);
    }
}

async function processAllReceipts() {
    const receiptFiles = tripData.files.filter(f => f.documentType === 'receipt');
    
    for (const file of receiptFiles) {
        try {
            await processSingleReceipt(file.file || { name: file.name }, file.path);
        } catch (error) {
            console.error(`Failed to process ${file.name}:`, error);
            tripData.needsReview = true;
        }
    }
}

async function finalizeTrip() {
    // Process ALL uploaded files with comprehensive Gemini analysis
    try {
        const response = await fetch('process_all_files.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                tripName: tripData.sessionTripName || tripData.metadata.name
            })
        });
        
        const result = await response.json();
        console.log('Comprehensive processing result:', result);
        
        if (result.success) {
            // Update trip data with comprehensive processing results
            tripData.metadata = result.tripMetadata;
            
            // Load processed expenses from the updated trip
            const expensesResponse = await fetch(`api.php?action=trip&name=${result.tripName}`);
            const tripInfo = await expensesResponse.json();
            
            if (tripInfo.success) {
                tripData.expenses = tripInfo.expenses || [];
                
                // Update UI with new trip name if changed
                if (result.tripName !== tripData.sessionTripName) {
                    tripData.sessionTripName = result.tripName;
                    tripData.metadata.name = result.tripName;
                }
                
                console.log(`Processed ${result.totalFilesAnalyzed} files with Gemini`);
                console.log(`Found ${result.expenseCount} expenses and ${result.travelDocumentCount} travel documents`);
                
                // Go to completion step
                currentStep = 3;
                updateWizard();
                setupCompletionStep();
                return;
            }
        } else {
            console.error('Processing failed:', result.error);
        }
    } catch (error) {
        console.error('Processing error:', error);
    }
    
    // Check if we have any expenses processed
    if (tripData.expenses && tripData.expenses.length > 0) {
        // If we have expenses, go to completion
        currentStep = 3;
        updateWizard();
        setupCompletionStep();
    } else {
        // Only show review step if we have uploaded files but no expenses
        tripData.needsReview = true;
        currentStep = 2;
        updateWizard();
        setupReviewStep();
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
            // Check if this is a travel document based on file path or filename
            const isTravelDoc = path.includes('/travel_documents/') ||
                              file.name.toLowerCase().includes('itinerary') || 
                              file.name.toLowerCase().includes('itenary') ||
                              file.name.toLowerCase().includes('confirmation') ||
                              file.name.toLowerCase().includes('boarding') ||
                              file.name.toLowerCase().includes('flight') ||
                              file.name.toLowerCase().includes('hotel') ||
                              file.name.toLowerCase().includes('travel') ||
                              file.name.toLowerCase().includes('booking') ||
                              file.name.toLowerCase().includes('reservation');
            
            // Determine correct source path based on document type
            let sourcePath;
            if (isTravelDoc) {
                sourcePath = path.includes('/travel_documents/') ? 
                    'travel_documents/' + file.name : 
                    'travel_documents/' + file.name;
            } else {
                sourcePath = path.includes('/receipts/') ? 
                    'receipts/' + file.name : 
                    'receipts/' + file.name;
            }
            
            const expense = {
                id: generateId(),
                ...result.expense,
                source: sourcePath,
                is_travel_document: isTravelDoc
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
                // Check if this is a travel document based on filename or content
                const isTravelDoc = file.name.toLowerCase().includes('itinerary') || 
                                  file.name.toLowerCase().includes('itenary') ||
                                  file.name.toLowerCase().includes('confirmation') ||
                                  file.name.toLowerCase().includes('boarding') ||
                                  file.name.toLowerCase().includes('flight') ||
                                  file.name.toLowerCase().includes('hotel') ||
                                  (result.expense.category && result.expense.category.toLowerCase() === 'travel');
                
                // Add processed expense to tripData
                const expense = {
                    id: generateId(),
                    ...result.expense,
                    source: 'receipts/' + file.name,
                    is_travel_document: isTravelDoc
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
    
    // Auto-advance to completion step if processing was successful
    if (processedCount > 0 && errors === 0) {
        setTimeout(() => {
            currentStep = 3;
            updateWizard();
            setupCompletionStep();
        }, 2000);
    } else if (errors > 0) {
        // Only show review step if there were errors
        setTimeout(() => {
            currentStep = 2;
            updateWizard();
            setupReviewStep();
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
    // Update review summary
    const reviewSummary = document.getElementById('reviewSummary');
    const totalAmount = tripData.expenses.reduce((sum, expense) => sum + parseFloat(expense.amount || 0), 0);
    
    reviewSummary.innerHTML = `
        <h3>Trip Summary</h3>
        <div class="trip-summary">
            <div class="summary-item">
                <strong>Trip Name:</strong> ${tripData.metadata.name || 'Untitled Trip'}
            </div>
            <div class="summary-item">
                <strong>Dates:</strong> ${tripData.metadata.start_date || 'Not set'} to ${tripData.metadata.end_date || 'Not set'}
            </div>
            <div class="summary-item">
                <strong>Files Processed:</strong> ${tripData.files.length}
            </div>
            <div class="summary-item">
                <strong>Expenses Found:</strong> ${tripData.expenses.length}
            </div>
            <div class="summary-item">
                <strong>Total Amount:</strong> $${totalAmount.toFixed(2)}
            </div>
        </div>
    `;
    
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
            <div class="expenses-header">
                <h3>Review Extracted Expenses</h3>
                <p>Please review and edit the extracted expense information below:</p>
            </div>
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
            currentStep = 3;
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
    const expenseCount = tripData.expenses.length;
    
    completionSummary.innerHTML = `
        <div class="success-icon">
            <i data-feather="check-circle"></i>
        </div>
        <h3>Trip Created Successfully!</h3>
        <p>Your trip "${tripData.metadata.name}" has been created successfully!</p>
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

// Travel document upload functionality
function setupTravelDocumentUpload() {
    const dropzone = document.getElementById('travelDocsDropzone');
    const fileInput = document.getElementById('travelDocsInput');
    
    if (!dropzone || !fileInput) return;
    
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
        handleTravelDocumentFiles(e.dataTransfer.files);
    });
    
    // File input change
    fileInput.addEventListener('change', (e) => {
        handleTravelDocumentFiles(e.target.files);
    });
}

function handleTravelDocumentFiles(files) {
    Array.from(files).forEach(file => {
        if (isValidFile(file)) {
            uploadTravelDocument(file);
        } else {
            showErrorMessage(`Invalid file type: ${file.name}. Please upload PDF or image files.`);
        }
    });
}

async function uploadTravelDocument(file) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('trip', 'temp_travel_docs');
    formData.append('type', 'travel_document');
    
    try {
        // Upload the file first
        const uploadResponse = await fetch('upload.php', {
            method: 'POST',
            body: formData
        });
        
        const uploadResult = await uploadResponse.json();
        
        if (uploadResult.success) {
            displayTravelDocument(file, uploadResult.path);
            
            // Store in trip data for later saving
            if (!tripData.travelDocuments) {
                tripData.travelDocuments = [];
            }
            tripData.travelDocuments.push({
                originalName: file.name,
                path: uploadResult.path,
                size: file.size,
                type: file.type
            });
            
            // Extract trip details from the document
            extractTripDetails(uploadResult.path, file.name);
        } else {
            showErrorMessage(`Failed to upload ${file.name}: ${uploadResult.error}`);
        }
    } catch (error) {
        console.error('Upload error:', error);
        showErrorMessage(`Error uploading ${file.name}`);
    }
}

function displayTravelDocument(file, path) {
    const uploadedDocs = document.getElementById('uploadedTravelDocs');
    
    const docItem = document.createElement('div');
    docItem.className = 'file-item';
    docItem.dataset.path = path;
    
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
    
    docItem.innerHTML = `
        ${preview}
        <div class="file-name">${file.name}</div>
        <div class="file-size">${fileSize}</div>
        <button class="btn btn-small btn-danger" onclick="removeTravelDocument('${path}')">
            <i data-feather="trash-2"></i> Remove
        </button>
    `;
    
    uploadedDocs.appendChild(docItem);
    feather.replace();
}

function removeTravelDocument(path) {
    const docItem = document.querySelector(`[data-path="${path}"]`);
    if (docItem) {
        docItem.remove();
    }
    
    // Remove from trip data if stored
    if (tripData.travelDocuments) {
        tripData.travelDocuments = tripData.travelDocuments.filter(doc => doc.path !== path);
    }
}

async function extractTripDetails(filePath, fileName) {
    const extractionStatus = document.getElementById('extractionStatus');
    
    // First try to extract basic info from filename
    const basicDetails = extractDetailsFromFilename(fileName);
    if (basicDetails) {
        extractionStatus.className = 'extraction-status success';
        extractionStatus.innerHTML = `
            <i data-feather="check-circle"></i> Trip details extracted from filename!
        `;
        fillTripDetailsForm(basicDetails);
        feather.replace();
        return;
    }
    
    // Show processing status for AI analysis
    extractionStatus.className = 'extraction-status processing';
    extractionStatus.innerHTML = `
        <i data-feather="loader"></i> Analyzing ${fileName} for trip details...
    `;
    feather.replace();
    
    try {
        const response = await fetch('extract_trip_details.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                filePath: filePath,
                fileName: fileName
            })
        });
        
        const result = await response.json();
        
        if (result.success && result.tripDetails) {
            // Show success status
            extractionStatus.className = 'extraction-status success';
            extractionStatus.innerHTML = `
                <i data-feather="check-circle"></i> Trip details extracted successfully!
            `;
            
            // Auto-fill form fields
            fillTripDetailsForm(result.tripDetails);
            
        } else {
            extractionStatus.className = 'extraction-status error';
            extractionStatus.innerHTML = `
                <i data-feather="x-circle"></i> Could not extract trip details from ${fileName}
            `;
        }
        
        feather.replace();
        
    } catch (error) {
        console.error('Extraction error:', error);
        extractionStatus.className = 'extraction-status error';
        extractionStatus.innerHTML = `
            <i data-feather="x-circle"></i> Error analyzing ${fileName}
        `;
        feather.replace();
    }
}

function extractDetailsFromFilename(fileName) {
    // Extract trip details from filename patterns like:
    // "Gmail - FW_ Confirmation_ Trip - Austin, 06_12_2025 - 06_16_2025.pdf"
    
    const patterns = [
        // Pattern for "City, MM_DD_YYYY - MM_DD_YYYY"
        /([A-Za-z\s]+),\s*(\d{2}_\d{2}_\d{4})\s*-\s*(\d{2}_\d{2}_\d{4})/,
        // Pattern for "City MM_DD_YYYY - MM_DD_YYYY"
        /([A-Za-z\s]+)\s+(\d{2}_\d{2}_\d{4})\s*-\s*(\d{2}_\d{2}_\d{4})/,
        // Pattern for "Trip - City"
        /Trip\s*-\s*([A-Za-z\s]+)/i
    ];
    
    for (const pattern of patterns) {
        const match = fileName.match(pattern);
        if (match) {
            const details = {};
            
            if (match[1]) {
                // Clean up city name
                details.tripName = match[1].trim().replace(/[_-]/g, ' ');
            }
            
            if (match[2] && match[3]) {
                // Convert MM_DD_YYYY to YYYY-MM-DD
                details.startDate = convertDateFormat(match[2]);
                details.endDate = convertDateFormat(match[3]);
            }
            
            // Only return if we found meaningful data
            if (details.tripName || details.startDate) {
                return details;
            }
        }
    }
    
    return null;
}

function convertDateFormat(dateStr) {
    // Convert MM_DD_YYYY to YYYY-MM-DD
    const parts = dateStr.split('_');
    if (parts.length === 3) {
        const [month, day, year] = parts;
        return `${year}-${month}-${day}`;
    }
    return null;
}

function fillTripDetailsForm(tripDetails) {
    const tripNameField = document.getElementById('tripName');
    const startDateField = document.getElementById('startDate');
    const endDateField = document.getElementById('endDate');
    const tripNotesField = document.getElementById('tripNotes');
    
    // Only fill empty fields to avoid overwriting user input
    if (tripDetails.tripName && !tripNameField.value) {
        tripNameField.value = tripDetails.tripName;
    }
    
    if (tripDetails.startDate && !startDateField.value) {
        startDateField.value = tripDetails.startDate;
    }
    
    if (tripDetails.endDate && !endDateField.value) {
        endDateField.value = tripDetails.endDate;
    }
    
    if (tripDetails.notes && !tripNotesField.value) {
        tripNotesField.value = tripDetails.notes;
    }
}

// Mobile Camera Functions
function setupMobileCamera() {
    // Detect if user is on mobile device
    const isMobile = window.innerWidth <= 768 || /Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    const cameraSection = document.getElementById('mobileCameraSection');
    const desktopTip = document.getElementById('desktopMobileTip');
    const cameraBtn = document.getElementById('cameraBtn');
    
    if (isMobile) {
        // Show camera button on mobile
        if (cameraSection) {
            cameraSection.style.display = 'block';
        }
        // Hide desktop tip on mobile
        if (desktopTip) {
            desktopTip.style.display = 'none';
        }
        // Add click event to camera button
        if (cameraBtn) {
            cameraBtn.addEventListener('click', openCamera);
            console.log('Camera button event listener added');
        }
    } else {
        // Show desktop tip on desktop
        if (desktopTip) {
            desktopTip.style.display = 'block';
            feather.replace();
        }
        // Hide camera button on desktop
        if (cameraSection) {
            cameraSection.style.display = 'none';
        }
    }
}

let cameraStream = null;

async function openCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    
    // Check if camera is supported
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showErrorMessage('Camera not supported on this device');
        return;
    }
    
    try {
        // First try with back camera
        let constraints = {
            video: {
                facingMode: 'environment', // Back camera
                width: { ideal: 1280 },
                height: { ideal: 720 }
            }
        };
        
        try {
            cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (backCameraError) {
            console.log('Back camera failed, trying front camera:', backCameraError);
            // Fallback to front camera or any camera
            constraints = {
                video: {
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            };
            cameraStream = await navigator.mediaDevices.getUserMedia(constraints);
        }
        
        video.srcObject = cameraStream;
        modal.style.display = 'flex';
        
        // Wait for video to be ready
        video.addEventListener('loadedmetadata', () => {
            video.play();
        });
        
        feather.replace();
        
    } catch (error) {
        console.error('Camera access error:', error);
        let errorMessage = 'Unable to access camera. ';
        
        if (error.name === 'NotAllowedError') {
            errorMessage += 'Please allow camera permissions and try again.';
        } else if (error.name === 'NotFoundError') {
            errorMessage += 'No camera found on this device.';
        } else if (error.name === 'NotSupportedError') {
            errorMessage += 'Camera not supported in this browser.';
        } else {
            errorMessage += 'Please check camera permissions.';
        }
        
        showErrorMessage(errorMessage);
    }
}

function closeCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    
    if (cameraStream) {
        cameraStream.getTracks().forEach(track => track.stop());
        cameraStream = null;
    }
    
    video.srcObject = null;
    modal.style.display = 'none';
}

async function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    const ctx = canvas.getContext('2d');
    
    // Check if video is ready
    if (video.videoWidth === 0 || video.videoHeight === 0) {
        showErrorMessage('Camera not ready. Please wait a moment and try again.');
        return;
    }
    
    // Set canvas dimensions to match video
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    
    // Draw video frame to canvas
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Convert canvas to blob
    canvas.toBlob(async (blob) => {
        if (blob) {
            // Create a file object from the blob
            const timestamp = Date.now();
            const filename = `receipt-${timestamp}.jpg`;
            const file = new File([blob], filename, { type: 'image/jpeg' });
            
            console.log('Photo captured:', filename, 'Size:', blob.size);
            
            // Close camera first
            closeCamera();
            
            // Show capturing status
            showSuccessMessage('Photo captured! Uploading...');
            
            // Add to upload queue
            try {
                await uploadFileOnly(file);
                showSuccessMessage('Photo uploaded successfully!');
            } catch (error) {
                console.error('Upload error:', error);
                showErrorMessage('Failed to upload photo. Please try again.');
            }
        } else {
            showErrorMessage('Failed to capture photo. Please try again.');
        }
    }, 'image/jpeg', 0.8);
}
