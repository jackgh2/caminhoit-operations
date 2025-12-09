// Order View JavaScript Functions
document.addEventListener('DOMContentLoaded', function() {
    console.log('Order view JavaScript loaded');
    
    // Initialize any Bootstrap components
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Status update modal function
function updateOrderStatus() {
    try {
        const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
        modal.show();
        console.log('Status modal opened');
    } catch (error) {
        console.error('Error opening status modal:', error);
        showAlertModal('Error', 'Error opening status modal. Please refresh the page and try again.', 'danger');
    }
}

// Quick status update function
function quickStatusUpdate(status) {
    try {
        const statusName = status.replace('_', ' ').toUpperCase();
        showConfirmModal(
            'Confirm Status Change',
            'Are you sure you want to change the order status to "' + statusName + '"?',
            function() {
                // Create form and submit
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                // Add hidden inputs
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'new_status';
                statusInput.value = status;

                const notesInput = document.createElement('input');
                notesInput.type = 'hidden';
                notesInput.name = 'status_notes';
                notesInput.value = 'Quick status update';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'update_status';
                actionInput.value = '1';

                form.appendChild(statusInput);
                form.appendChild(notesInput);
                form.appendChild(actionInput);

                document.body.appendChild(form);
                form.submit();
            }
        );
    } catch (error) {
        console.error('Error updating status:', error);
        showAlertModal('Error', 'Error updating status. Please try again.', 'danger');
    }
}

// Seamless, unified PDF generation function
function printOrderPDF() {
    try {
        console.log('Starting PDF generation...');
        
        // Extract comprehensive order data from the page
        const orderData = extractOrderData();
        
        // Create modern PDF window
        const printWindow = window.open('', '_blank', 'width=800,height=600');
        
        const seamlessPDFContent = `
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Order ${orderData.orderNumber} - CaminhoIT</title>
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
                    
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
                        line-height: 1.6;
                        color: #1e293b;
                        background: #f8fafc;
                        padding: 0;
                        margin: 0;
                    }
                    
                    .container {
                        max-width: 800px;
                        margin: 0 auto;
                        padding: 0;
                        background: white;
                        min-height: 100vh;
                    }
                    
                    /* Header - Matching your brand theme */
                    .header {
                        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                        color: white;
                        padding: 50px 40px;
                        text-align: center;
                        position: relative;
                        overflow: hidden;
                    }
                    
                    .header::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="20" cy="20" r="1" fill="white" opacity="0.1"/><circle cx="80" cy="80" r="1" fill="white" opacity="0.1"/><circle cx="40" cy="60" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>') repeat;
                        opacity: 0.1;
                    }
                    
                    .header-content {
                        position: relative;
                        z-index: 1;
                    }
                    
                    .company-logo {
                        font-size: 2.8rem;
                        font-weight: 700;
                        margin-bottom: 8px;
                        letter-spacing: -1px;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    
                    .company-tagline {
                        font-size: 1.1rem;
                        opacity: 0.9;
                        margin-bottom: 30px;
                        font-weight: 300;
                    }
                    
                    .order-title {
                        font-size: 2.2rem;
                        font-weight: 600;
                        margin-bottom: 12px;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    
                    .order-meta {
                        font-size: 1rem;
                        opacity: 0.9;
                        margin-bottom: 20px;
                    }
                    
                    /* Status Badge - Brand colors */
                    .status-badge {
                        display: inline-block;
                        padding: 10px 20px;
                        border-radius: 25px;
                        font-size: 0.9rem;
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                        background: rgba(255,255,255,0.2);
                        border: 2px solid rgba(255,255,255,0.3);
                        backdrop-filter: blur(10px);
                    }
                    
                    /* Content area */
                    .content {
                        padding: 40px;
                    }
                    
                    /* Info Grid - Brand styled */
                    .info-grid {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 30px;
                        margin-bottom: 40px;
                    }
                    
                    .info-card {
                        background: white;
                        border: none;
                        border-radius: 16px;
                        padding: 30px;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                        position: relative;
                        overflow: hidden;
                    }
                    
                    .info-card::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 4px;
                        background: linear-gradient(90deg, #1e3a8a, #3b82f6);
                    }
                    
                    .info-card h3 {
                        font-size: 1.2rem;
                        font-weight: 600;
                        color: #1e3a8a;
                        margin-bottom: 20px;
                        display: flex;
                        align-items: center;
                    }
                    
                    .info-card h3::before {
                        content: '';
                        width: 8px;
                        height: 8px;
                        margin-right: 12px;
                        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
                        border-radius: 50%;
                        display: inline-block;
                    }
                    
                    .info-row {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 12px 0;
                        border-bottom: 1px solid rgba(241, 245, 249, 0.3);
                    }
                    
                    .info-row:last-child {
                        border-bottom: none;
                    }
                    
                    .info-label {
                        font-weight: 500;
                        color: #64748b;
                        font-size: 0.9rem;
                    }
                    
                    .info-value {
                        font-weight: 600;
                        color: #1e3a8a;
                        text-align: right;
                        font-size: 0.9rem;
                    }
                    
                    /* Items Section */
                    .items-section {
                        margin: 40px 0;
                    }
                    
                    .section-title {
                        font-size: 1.4rem;
                        font-weight: 600;
                        color: #1e3a8a;
                        margin-bottom: 25px;
                        padding-bottom: 10px;
                        border-bottom: 2px solid #e2e8f0;
                        display: flex;
                        align-items: center;
                    }
                    
                    .section-title::before {
                        content: '';
                        width: 20px;
                        height: 20px;
                        margin-right: 10px;
                        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
                        border-radius: 4px;
                        display: inline-block;
                    }
                    
                    /* Completely Seamless Table Design */
                    .items-table {
                        width: 100%;
                        border-collapse: collapse;
                        border-radius: 16px;
                        overflow: hidden;
                        box-shadow: 0 8px 25px rgba(30, 58, 138, 0.15);
                        border: none;
                        background: white;
                    }
                    
                    /* Single unified header background */
                    .items-table thead {
                        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                        position: relative;
                    }
                    
                    .items-table thead::before {
                        content: '';
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
                        z-index: 1;
                    }
                    
                    .items-table th {
                        background: transparent;
                        color: white;
                        padding: 20px 18px;
                        font-weight: 600;
                        font-size: 0.9rem;
                        text-align: left;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                        border: none;
                        position: relative;
                        z-index: 2;
                    }
                    
                    /* Remove all header separators */
                    .items-table th:not(:last-child) {
                        border-right: none;
                    }
                    
                    /* Unified header row */
                    .items-table thead tr {
                        background: transparent;
                    }
                    
                    .items-table tbody {
                        background: white;
                    }
                    
                    .items-table td {
                        padding: 20px 18px;
                        font-size: 0.9rem;
                        border: none;
                        border-bottom: 1px solid rgba(226, 232, 240, 0.2);
                        transition: all 0.3s ease;
                    }
                    
                    .items-table tr:hover td {
                        background: rgba(59, 130, 246, 0.02);
                        transform: translateY(-1px);
                    }
                    
                    .items-table tr:last-child td {
                        border-bottom: none;
                    }
                    
                    /* Remove all cell separators */
                    .items-table td:not(:last-child) {
                        border-right: none;
                    }
                    
                    .item-name {
                        font-weight: 600;
                        color: #1e3a8a;
                        margin-bottom: 4px;
                    }
                    
                    .item-description {
                        color: #64748b;
                        font-size: 0.8rem;
                        margin-top: 4px;
                        font-style: italic;
                    }
                    
                    .price-cell {
                        text-align: right;
                        font-weight: 600;
                        color: #1e3a8a;
                        font-variant-numeric: tabular-nums;
                    }
                    
                    .quantity-cell {
                        text-align: center;
                        font-weight: 600;
                        color: #1e3a8a;
                    }
                    
                    /* Currency Info */
                    .currency-info {
                        background: linear-gradient(135deg, rgba(254, 243, 199, 0.8), rgba(253, 230, 138, 0.8));
                        border: none;
                        border-radius: 12px;
                        padding: 20px;
                        margin: 30px 0;
                        text-align: center;
                        color: #92400e;
                        font-weight: 500;
                        box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.1);
                    }
                    
                    /* Summary Section */
                    .summary-section {
                        background: linear-gradient(135deg, rgba(248, 250, 252, 0.8), rgba(226, 232, 240, 0.8));
                        border: none;
                        border-radius: 16px;
                        padding: 30px;
                        margin: 40px 0;
                        box-shadow: 0 8px 25px rgba(30, 58, 138, 0.08);
                    }
                    
                    .summary-title {
                        font-size: 1.3rem;
                        font-weight: 600;
                        color: #1e3a8a;
                        margin-bottom: 20px;
                        text-align: center;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    
                    .summary-title::before {
                        content: '';
                        width: 16px;
                        height: 16px;
                        margin-right: 10px;
                        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
                        border-radius: 50%;
                        display: inline-block;
                    }
                    
                    .summary-table {
                        width: 100%;
                        max-width: 400px;
                        margin: 0 auto;
                    }
                    
                    .summary-row {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 12px 0;
                        font-size: 1rem;
                        border-bottom: 1px solid rgba(226, 232, 240, 0.3);
                    }
                    
                    .summary-row:last-child {
                        border-bottom: none;
                    }
                    
                    .summary-row.subtotal {
                        color: #64748b;
                    }
                    
                    .summary-row.total {
                        border-top: 2px solid rgba(30, 58, 138, 0.2);
                        padding-top: 15px;
                        margin-top: 15px;
                        font-size: 1.3rem;
                        font-weight: 700;
                        color: #1e3a8a;
                        background: linear-gradient(135deg, rgba(219, 234, 254, 0.5), rgba(191, 219, 254, 0.5));
                        padding: 15px;
                        border-radius: 8px;
                        margin: 15px -15px 0;
                        border-top: none;
                        box-shadow: 0 2px 4px rgba(30, 58, 138, 0.1);
                    }
                    
                    /* Notes Section */
                    .notes-section {
                        background: linear-gradient(135deg, rgba(219, 234, 254, 0.5), rgba(191, 219, 254, 0.5));
                        border: none;
                        border-radius: 16px;
                        padding: 25px;
                        margin: 30px 0;
                        box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1);
                    }
                    
                    .notes-section h3 {
                        font-size: 1.2rem;
                        font-weight: 600;
                        color: #1e3a8a;
                        margin-bottom: 15px;
                        display: flex;
                        align-items: center;
                    }
                    
                    .notes-section h3::before {
                        content: '';
                        width: 16px;
                        height: 16px;
                        margin-right: 10px;
                        background: #1e3a8a;
                        border-radius: 50%;
                        display: inline-block;
                    }
                    
                    .notes-text {
                        color: #1e40af;
                        line-height: 1.6;
                        font-style: italic;
                    }
                    
                    /* Footer */
                    .footer {
                        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
                        color: white;
                        text-align: center;
                        padding: 40px;
                        margin-top: 50px;
                        border: none;
                    }
                    
                    .footer-logo {
                        font-size: 1.8rem;
                        font-weight: 700;
                        margin-bottom: 10px;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    
                    .footer-tagline {
                        font-size: 1.1rem;
                        margin-bottom: 20px;
                        opacity: 0.9;
                    }
                    
                    .footer-meta {
                        font-size: 0.9rem;
                        opacity: 0.8;
                        line-height: 1.6;
                    }
                    
                    /* Print Controls */
                    .print-controls {
                        text-align: center;
                        margin: 30px 0;
                        padding: 30px;
                        background: rgba(248, 250, 252, 0.5);
                        border-radius: 12px;
                        border: none;
                    }
                    
                    .print-btn {
                        background: linear-gradient(135deg, #1e3a8a, #3b82f6);
                        color: white;
                        border: none;
                        padding: 14px 28px;
                        border-radius: 8px;
                        font-size: 1rem;
                        font-weight: 600;
                        cursor: pointer;
                        margin: 0 10px;
                        transition: all 0.3s ease;
                        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                    }
                    
                    .print-btn:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 8px 12px -2px rgba(0, 0, 0, 0.2);
                    }
                    
                    .close-btn {
                        background: #6b7280;
                        color: white;
                        border: none;
                        padding: 14px 28px;
                        border-radius: 8px;
                        font-size: 1rem;
                        cursor: pointer;
                        margin: 0 10px;
                        transition: all 0.3s ease;
                    }
                    
                    .close-btn:hover {
                        background: #4b5563;
                        transform: translateY(-1px);
                    }
                    
                    /* Print Styles */
                    @media print {
                        .print-controls {
                            display: none;
                        }
                        .container {
                            padding: 0;
                        }
                        .content {
                            padding: 20px;
                        }
                        body {
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                            background: white;
                        }
                        .footer {
                            margin-top: 20px;
                        }
                    }
                    
                    /* Mobile Responsive */
                    @media (max-width: 768px) {
                        .info-grid {
                            grid-template-columns: 1fr;
                        }
                        .content {
                            padding: 20px;
                        }
                        .header {
                            padding: 30px 20px;
                        }
                        .company-logo {
                            font-size: 2.2rem;
                        }
                        .order-title {
                            font-size: 1.8rem;
                        }
                        .items-table th,
                        .items-table td {
                            padding: 12px 8px;
                            font-size: 0.8rem;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <!-- Header - Brand matched -->
                    <div class="header">
                        <div class="header-content">
                            <div class="company-logo">CaminhoIT</div>
                            <div class="company-tagline">Professional IT Services & Solutions</div>
                            <div class="order-title">Order ${orderData.orderNumber}</div>
                            <div class="order-meta">Generated on ${orderData.generatedDate}</div>
                            <div class="status-badge">${orderData.status}</div>
                        </div>
                    </div>
                    
                    <div class="content">
                        <!-- Order Information Grid -->
                        <div class="info-grid">
                            <div class="info-card">
                                <h3>Order Information</h3>
                                <div class="info-row">
                                    <span class="info-label">Order Type:</span>
                                    <span class="info-value">${orderData.orderType}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Billing Cycle:</span>
                                    <span class="info-value">${orderData.billingCycle}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Start Date:</span>
                                    <span class="info-value">${orderData.startDate}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Created:</span>
                                    <span class="info-value">${orderData.createdAt}</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Created By:</span>
                                    <span class="info-value">${orderData.staffName}</span>
                                </div>
                            </div>
                            
                            <div class="info-card">
                                <h3>Customer Information</h3>
                                <div class="info-row">
                                    <span class="info-label">Company:</span>
                                    <span class="info-value">${orderData.companyName}</span>
                                </div>
                                ${orderData.companyAddress ? `
                                <div class="info-row">
                                    <span class="info-label">Address:</span>
                                    <span class="info-value">${orderData.companyAddress}</span>
                                </div>
                                ` : ''}
                                ${orderData.companyPhone ? `
                                <div class="info-row">
                                    <span class="info-label">Phone:</span>
                                    <span class="info-value">${orderData.companyPhone}</span>
                                </div>
                                ` : ''}
                                <div class="info-row">
                                    <span class="info-label">Currency:</span>
                                    <span class="info-value">${orderData.currencySymbol} ${orderData.currency}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Currency Information -->
                        <div class="currency-info">
                            <strong>Currency & Tax Information:</strong>
                            Order Currency: ${orderData.currencySymbol} ${orderData.currency}
                            ${orderData.vatEnabled ? ` • VAT Enabled (${orderData.vatPercentage}%)` : ' • VAT Not Applicable'}
                        </div>
                        
                        <!-- Order Items - Completely Seamless Table -->
                        <div class="items-section">
                            <h2 class="section-title">Order Items</h2>
                            <table class="items-table">
                                <thead>
                                    <tr>
                                        <th>Item Description</th>
                                        <th>Quantity</th>
                                        <th>Unit Price</th>
                                        <th>Setup Fee</th>
                                        <th>Line Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${orderData.items.map(item => `
                                    <tr>
                                        <td>
                                            <div class="item-name">${item.name}</div>
                                            ${item.description ? `<div class="item-description">${item.description}</div>` : ''}
                                        </td>
                                        <td class="quantity-cell">${item.quantity}</td>
                                        <td class="price-cell">${orderData.currencySymbol}${item.unitPrice}</td>
                                        <td class="price-cell">${item.setupFee > 0 ? orderData.currencySymbol + item.setupFee : '-'}</td>
                                        <td class="price-cell">${orderData.currencySymbol}${item.lineTotal}</td>
                                    </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Order Summary -->
                        <div class="summary-section">
                            <h3 class="summary-title">Order Summary</h3>
                            <div class="summary-table">
                                <div class="summary-row subtotal">
                                    <span>Subtotal:</span>
                                    <span>${orderData.currencySymbol}${orderData.subtotal}</span>
                                </div>
                                <div class="summary-row subtotal">
                                    <span>Setup Fees:</span>
                                    <span>${orderData.currencySymbol}${orderData.setupFees}</span>
                                </div>
                                ${orderData.vatEnabled ? `
                                <div class="summary-row subtotal">
                                    <span>VAT (${orderData.vatPercentage}%):</span>
                                    <span>${orderData.currencySymbol}${orderData.taxAmount}</span>
                                </div>
                                ` : ''}
                                <div class="summary-row total">
                                    <span>Total Amount:</span>
                                    <span>${orderData.currencySymbol}${orderData.total}</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notes Section -->
                        ${orderData.notes ? `
                        <div class="notes-section">
                            <h3>Order Notes</h3>
                            <div class="notes-text">${orderData.notes.replace(/\n/g, '<br>')}</div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <!-- Footer -->
                    <div class="footer">
                        <div class="footer-logo">CaminhoIT</div>
                        <div class="footer-tagline">Professional IT Services & Solutions</div>
                        <div class="footer-meta">
                            Document generated on ${orderData.generatedDate}<br>
                            For support, contact us at support@caminhoit.com
                        </div>
                    </div>
                    
                    <!-- Print Controls -->
                    <div class="print-controls">
                        <button class="print-btn" onclick="window.print()">🖨️ Print Document</button>
                        <button class="close-btn" onclick="window.close()">✕ Close Window</button>
                    </div>
                </div>
                
                <script>
                    // Auto-print after a short delay
                    setTimeout(function() {
                        window.print();
                    }, 1000);
                </script>
            </body>
            </html>
        `;
        
        printWindow.document.write(seamlessPDFContent);
        printWindow.document.close();
        printWindow.focus();
        
        console.log('Seamless PDF generated successfully');
        
    } catch (error) {
        console.error('Error generating PDF:', error);
        showAlertModal('Error', 'Error generating PDF. Please try again.', 'danger');
    }
}

// Function to extract comprehensive order data from the page
function extractOrderData() {
    try {
        // Get basic order info
        const orderNumber = document.querySelector('h1').textContent.replace(/Order #|Order/g, '').trim();
        const companyName = document.querySelector('.info-value').textContent.trim();
        
        // Get status
        const statusBadge = document.querySelector('.status-badge');
        const status = statusBadge ? statusBadge.textContent.trim() : 'Unknown';
        
        // Extract order items
        const items = [];
        const itemCards = document.querySelectorAll('.order-item-card');
        
        itemCards.forEach(card => {
            const nameElement = card.querySelector('h6');
            const descElement = card.querySelector('.text-muted.small');
            const badges = card.querySelectorAll('.badge');
            const totalElement = card.querySelector('.h5');
            
            let itemData = {
                name: nameElement ? nameElement.textContent.trim() : 'Item',
                description: descElement ? descElement.textContent.trim() : '',
                quantity: 1,
                unitPrice: '0.00',
                setupFee: '0.00',
                lineTotal: '0.00'
            };
            
            // Parse badges
            badges.forEach(badge => {
                const text = badge.textContent.trim();
                if (text.includes('Qty:')) {
                    itemData.quantity = parseInt(text.replace('Qty:', '').trim()) || 1;
                } else if (text.includes('each')) {
                    const match = text.match(/[\d,]+\.?\d*/);
                    if (match) itemData.unitPrice = parseFloat(match[0].replace(',', '')).toFixed(2);
                } else if (text.includes('Setup:')) {
                    const match = text.match(/[\d,]+\.?\d*/);
                    if (match) itemData.setupFee = parseFloat(match[0].replace(',', '')).toFixed(2);
                }
            });
            
            // Parse total
            if (totalElement) {
                const match = totalElement.textContent.match(/[\d,]+\.?\d*/);
                if (match) itemData.lineTotal = parseFloat(match[0].replace(',', '')).toFixed(2);
            }
            
            items.push(itemData);
        });
        
        // Extract financial data
        const summaryRows = document.querySelectorAll('.summary-row');
        let subtotal = '0.00', setupFees = '0.00', taxAmount = '0.00', total = '0.00';
        
        summaryRows.forEach(row => {
            const text = row.textContent.toLowerCase();
            const valueMatch = row.textContent.match(/[\d,]+\.?\d*/);
            const value = valueMatch ? parseFloat(valueMatch[0].replace(',', '')).toFixed(2) : '0.00';
            
            if (text.includes('subtotal')) subtotal = value;
            else if (text.includes('setup')) setupFees = value;
            else if (text.includes('vat') || text.includes('tax')) taxAmount = value;
            else if (text.includes('total')) total = value;
        });
        
        // Get currency info
        const currencyBadge = document.querySelector('.currency-badge');
        const currencyText = currencyBadge ? currencyBadge.textContent : '£ GBP';
        const currencyMatch = currencyText.match(/([£$€¥₹₽₩₪₺₾₼₽₴₦₨₡₱₹₨₩₪₺₾₼₽₴₦₨₡₱]+)\s*(\w+)/);
        const currencySymbol = currencyMatch ? currencyMatch[1] : '£';
        const currency = currencyMatch ? currencyMatch[2] : 'GBP';
        
        // Get VAT info
        const vatInfo = document.querySelector('.vat-info');
        const vatEnabled = vatInfo && !vatInfo.classList.contains('disabled');
        const vatPercentage = vatEnabled ? vatInfo.textContent.match(/\d+\.?\d*/) : null;
        
        // Get other details
        const infoValues = document.querySelectorAll('.info-value');
        const orderType = infoValues[1] ? infoValues[1].textContent.trim() : 'Standard';
        const billingCycle = infoValues[2] ? infoValues[2].textContent.trim() : 'Monthly';
        const startDate = infoValues[3] ? infoValues[3].textContent.trim() : new Date().toLocaleDateString();
        const staffName = infoValues[4] ? infoValues[4].textContent.trim() : 'Staff';
        const createdAt = infoValues[5] ? infoValues[5].textContent.trim() : new Date().toLocaleDateString();
        
        // Get notes
        const notesElement = document.querySelector('.notes-text');
        const notes = notesElement ? notesElement.textContent.trim() : '';
        
        // Get company details
        const companyAddress = document.querySelector('.bi-geo-alt') ? 
            document.querySelector('.bi-geo-alt').parentElement.textContent.trim() : '';
        const companyPhone = document.querySelector('.bi-telephone') ? 
            document.querySelector('.bi-telephone').parentElement.textContent.trim() : '';
        
        return {
            orderNumber,
            companyName,
            companyAddress,
            companyPhone,
            status,
            orderType,
            billingCycle,
            startDate,
            staffName,
            createdAt,
            currency,
            currencySymbol,
            subtotal,
            setupFees,
            taxAmount,
            total,
            vatEnabled,
            vatPercentage: vatPercentage ? vatPercentage[0] : '0',
            notes,
            items,
            generatedDate: new Date().toLocaleDateString('en-GB', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            })
        };
        
    } catch (error) {
        console.error('Error extracting order data:', error);
        return {
            orderNumber: 'Unknown',
            companyName: 'Unknown Company',
            status: 'Unknown',
            items: [],
            currencySymbol: '£',
            currency: 'GBP',
            subtotal: '0.00',
            total: '0.00',
            generatedDate: new Date().toLocaleDateString()
        };
    }
}

// Alternative status update using a dropdown
function showStatusDropdown() {
    try {
        const dropdown = document.getElementById('statusDropdown');
        if (dropdown) {
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }
    } catch (error) {
        console.error('Error showing status dropdown:', error);
    }
}

// Handle status change from dropdown
function changeStatus(selectElement) {
    try {
        const newStatus = selectElement.value;
        if (newStatus) {
            quickStatusUpdate(newStatus);
        }
    } catch (error) {
        console.error('Error changing status:', error);
    }
}

// Custom Modal Functions
function showConfirmModal(title, message, onConfirm, onCancel) {
    // Create modal HTML
    const modalId = 'customConfirmModal';
    let modal = document.getElementById(modalId);

    if (!modal) {
        const modalHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                        <div class="modal-header" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white; border: none; border-radius: 12px 12px 0 0; padding: 1.5rem;">
                            <h5 class="modal-title" id="${modalId}Label" style="font-weight: 600;">
                                <i class="bi bi-question-circle me-2"></i>
                                <span id="${modalId}Title"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="padding: 2rem; font-size: 1.05rem; line-height: 1.6; color: #1e293b;">
                            <p id="${modalId}Message" style="margin: 0;"></p>
                        </div>
                        <div class="modal-footer" style="border: none; padding: 1rem 1.5rem 1.5rem; background: #f8fafc; border-radius: 0 0 12px 12px;">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 500;">
                                <i class="bi bi-x-circle me-2"></i>Cancel
                            </button>
                            <button type="button" class="btn btn-primary" id="${modalId}Confirm" style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); border: none; border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 500;">
                                <i class="bi bi-check-circle me-2"></i>Confirm
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modal = document.getElementById(modalId);
    }

    // Set content
    document.getElementById(modalId + 'Title').textContent = title;
    document.getElementById(modalId + 'Message').textContent = message;

    // Set up confirm button
    const confirmBtn = document.getElementById(modalId + 'Confirm');
    confirmBtn.onclick = function() {
        const bsModal = bootstrap.Modal.getInstance(modal);
        if (bsModal) bsModal.hide();
        if (onConfirm) onConfirm();
    };

    // Set up cancel handling
    modal.addEventListener('hidden.bs.modal', function handler() {
        if (onCancel && !confirmBtn.clicked) {
            onCancel();
        }
        modal.removeEventListener('hidden.bs.modal', handler);
        confirmBtn.clicked = false;
    });

    confirmBtn.addEventListener('click', function() {
        confirmBtn.clicked = true;
    });

    // Show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

function showAlertModal(title, message, type = 'info') {
    const modalId = 'customAlertModal';
    let modal = document.getElementById(modalId);

    const iconMap = {
        'success': 'bi-check-circle-fill',
        'danger': 'bi-exclamation-triangle-fill',
        'warning': 'bi-exclamation-circle-fill',
        'info': 'bi-info-circle-fill'
    };

    const colorMap = {
        'success': '#10B981',
        'danger': '#EF4444',
        'warning': '#F59E0B',
        'info': '#3B82F6'
    };

    if (!modal) {
        const modalHTML = `
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content" style="border: none; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
                        <div class="modal-header" id="${modalId}Header" style="border: none; border-radius: 12px 12px 0 0; padding: 1.5rem; color: white;">
                            <h5 class="modal-title" id="${modalId}Label" style="font-weight: 600;">
                                <i id="${modalId}Icon" class="me-2"></i>
                                <span id="${modalId}Title"></span>
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="padding: 2rem; font-size: 1.05rem; line-height: 1.6; color: #1e293b;">
                            <p id="${modalId}Message" style="margin: 0;"></p>
                        </div>
                        <div class="modal-footer" style="border: none; padding: 1rem 1.5rem 1.5rem; background: #f8fafc; border-radius: 0 0 12px 12px;">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal" style="border-radius: 8px; padding: 0.6rem 1.5rem; font-weight: 500;">
                                <i class="bi bi-check-lg me-2"></i>OK
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        modal = document.getElementById(modalId);
    }

    // Set content
    document.getElementById(modalId + 'Title').textContent = title;
    document.getElementById(modalId + 'Message').textContent = message;
    document.getElementById(modalId + 'Icon').className = iconMap[type] || iconMap['info'];
    document.getElementById(modalId + 'Header').style.background = colorMap[type] || colorMap['info'];

    // Show modal
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

console.log('Seamless order view functions loaded successfully');