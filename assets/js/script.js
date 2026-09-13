/**
 * JavaScript Functions for Smart ICT Helpdesk System
 * Mutare City Council
 */

// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function() {
    const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (mobileMenuToggle && sidebar) {
        mobileMenuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        }
    });
});

// Form validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;

    const requiredFields = form.querySelectorAll('[required]');
    let isValid = true;

    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            showFieldError(field, 'This field is required');
            isValid = false;
        } else {
            clearFieldError(field);
        }
    });

    return isValid;
}

// Show field error
function showFieldError(field, message) {
    clearFieldError(field);
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'field-error';
    errorDiv.textContent = message;
    errorDiv.style.color = '#ef4444';
    errorDiv.style.fontSize = '0.875rem';
    errorDiv.style.marginTop = '0.25rem';
    
    field.parentNode.appendChild(errorDiv);
    field.style.borderColor = '#ef4444';
}

// Clear field error
function clearFieldError(field) {
    const errorDiv = field.parentNode.querySelector('.field-error');
    if (errorDiv) {
        errorDiv.remove();
    }
    field.style.borderColor = '';
}

// Show alert message
function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} fade-in`;
    alertDiv.textContent = message;
    
    const container = document.querySelector('.main-content');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// Confirm action
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// AJAX request helper
function ajaxRequest(url, method, data, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onload = function() {
        if (xhr.status === 200) {
            callback(xhr.responseText);
        } else {
            showAlert('An error occurred. Please try again.', 'error');
        }
    };
    
    xhr.onerror = function() {
        showAlert('Network error. Please check your connection.', 'error');
    };
    
    xhr.send(data);
}

// Update ticket status
function updateTicketStatus(ticketId, status) {
    confirmAction('Are you sure you want to update this ticket status?', function() {
        ajaxRequest('update_ticket.php', 'POST', 
            `ticket_id=${ticketId}&status=${status}`, 
            function(response) {
                showAlert('Ticket status updated successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        );
    });
}

// Delete ticket
function deleteTicket(ticketId) {
    confirmAction('Are you sure you want to delete this ticket?', function() {
        ajaxRequest('delete_ticket.php', 'POST', 
            `ticket_id=${ticketId}`, 
            function(response) {
                showAlert('Ticket deleted successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        );
    });
}

// Search functionality
function searchTable(tableId, searchInputId) {
    const table = document.getElementById(tableId);
    const searchInput = document.getElementById(searchInputId);
    
    if (!table || !searchInput) return;
    
    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// Auto-resize textarea
function autoResizeTextarea(textarea) {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
    });
}

// Initialize all textareas with auto-resize
document.addEventListener('DOMContentLoaded', function() {
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(autoResizeTextarea);
});

// Print ticket
function printTicket(ticketId) {
    window.open('print_ticket.php?id=' + ticketId, '_blank');
}

// Export data to CSV
function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            rowData.push('"' + col.textContent.trim() + '"');
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    
    window.URL.revokeObjectURL(url);
}

// Dashboard charts (placeholder for future chart library integration)
function initializeCharts() {
    // This function can be extended with Chart.js or similar library
    console.log('Charts initialized');
}

// Real-time notifications (placeholder for WebSocket integration)
function initializeNotifications() {
    // This function can be extended with WebSocket or Server-Sent Events
    console.log('Notifications initialized');
}

// Theme toggle (for future light mode implementation)
function toggleTheme() {
    document.body.classList.toggle('light-theme');
    localStorage.setItem('theme', document.body.classList.contains('light-theme') ? 'light' : 'dark');
}

// Load saved theme
document.addEventListener('DOMContentLoaded', function() {
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.body.classList.add('light-theme');
    }
});

// Form submission with loading state
function submitFormWithLoading(formId, callback) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    const submitBtn = form.querySelector('button[type="submit"]');
    if (!submitBtn) return;
    
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
    submitBtn.disabled = true;
    
    setTimeout(() => {
        callback();
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 1000);
}

// Password strength indicator
function checkPasswordStrength(password) {
    let strength = 0;
    
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]+/)) strength++;
    if (password.match(/[A-Z]+/)) strength++;
    if (password.match(/[0-9]+/)) strength++;
    if (password.match(/[$@#&!]+/)) strength++;
    
    return strength;
}

// Show password strength
function showPasswordStrength(password, indicatorId) {
    const indicator = document.getElementById(indicatorId);
    if (!indicator) return;
    
    const strength = checkPasswordStrength(password);
    let strengthText = '';
    let strengthColor = '';
    
    switch(strength) {
        case 0:
        case 1:
            strengthText = 'Weak';
            strengthColor = '#ef4444';
            break;
        case 2:
        case 3:
            strengthText = 'Medium';
            strengthColor = '#fbbf24';
            break;
        case 4:
        case 5:
            strengthText = 'Strong';
            strengthColor = '#10b981';
            break;
    }
    
    indicator.textContent = strengthText;
    indicator.style.color = strengthColor;
}

// Auto-assign technician preview
function previewTechnicianAssignment(ticketCategory) {
    // This function can show which technician would be auto-assigned
    console.log('Previewing assignment for category:', ticketCategory);
}
