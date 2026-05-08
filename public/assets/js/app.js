// Toast Notification System
function showToast(message, type = 'success') {
    const toastContainer = document.getElementById('toast-container') || createToastContainer();
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type} show`;
    toast.innerHTML = `
        <div class="toast-header">
            <span class="material-icons">${getToastIcon(type)}</span>
            <strong class="me-auto">${getToastTitle(type)}</strong>
            <button type="button" class="btn-close" onclick="this.parentElement.parentElement.remove()"></button>
        </div>
        <div class="toast-body"></div>
    `;
    toast.querySelector('.toast-body').textContent = message;
    
    toastContainer.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

function createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toast-container';
    container.style.cssText = 'position: fixed; top: 80px; left: 20px; z-index: 9999;';
    document.body.appendChild(container);
    return container;
}

function getToastIcon(type) {
    const icons = {
        success: 'check_circle',
        error: 'error',
        warning: 'warning',
        info: 'info'
    };
    return icons[type] || 'info';
}

function getToastTitle(type) {
    const titles = {
        success: 'موفق',
        error: 'خطا',
        warning: 'هشدار',
        info: 'اطلاعات'
    };
    return titles[type] || 'پیام';
}

// AJAX Form Submit
function handleAjaxForm(formElement, successCallback) {
    formElement.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        // Disable button
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال ارسال...';
        
        try {
            const response = await fetch(this.action, {
                method: this.method,
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast(result.message, 'success');
                if (successCallback) successCallback(result);
            } else {
                showToast(result.message || 'خطایی رخ داده است', 'error');
                
                // نمایش خطاهای validation
                if (result.errors) {
                    Object.keys(result.errors).forEach(field => {
                        const input = formElement.querySelector(`[name="${field}"]`);
                        if (input) {
                            input.classList.add('is-invalid');
                            const feedback = input.nextElementSibling;
                            if (feedback && feedback.classList.contains('invalid-feedback')) {
                                feedback.textContent = result.errors[field][0];
                            }
                        }
                    });
                }
            }
        } catch (error) {
            showToast('خطا در ارتباط با سرور', 'error');
            console.error(error);
        } finally {
            // Enable button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });
}

// Remove validation errors on input
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('is-invalid')) {
        e.target.classList.remove('is-invalid');
    }
});