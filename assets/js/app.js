/**
 * MailPilot - Main JavaScript
 */

// ============================================================
// TOAST NOTIFICATION SYSTEM
// ============================================================
const Toast = {
    container: null,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 4000) {
        this.init();
        
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${message}</span>
            <button class="toast-close" onclick="Toast.remove(this.parentElement)">✕</button>
        `;
        
        this.container.appendChild(toast);
        
        if (duration > 0) {
            setTimeout(() => this.remove(toast), duration);
        }
        
        return toast;
    },
    
    remove(toast) {
        if (toast && toast.parentElement) {
            toast.classList.add('removing');
            setTimeout(() => toast.remove(), 300);
        }
    },
    
    success(msg, duration) { return this.show(msg, 'success', duration); },
    error(msg, duration) { return this.show(msg, 'error', duration); },
    warning(msg, duration) { return this.show(msg, 'warning', duration); },
    info(msg, duration) { return this.show(msg, 'info', duration); },
};

// ============================================================
// MODAL SYSTEM
// ============================================================
const Modal = {
    open(id) {
        const overlay = document.getElementById(id);
        if (overlay) {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    },
    
    close(id) {
        const overlay = document.getElementById(id);
        if (overlay) {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
    },
    
    confirm(title, message, onConfirm) {
        const id = 'confirmModal';
        let overlay = document.getElementById(id);
        
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = id;
            overlay.className = 'modal-overlay';
            overlay.innerHTML = `
                <div class="modal">
                    <div class="modal-header">
                        <h3 id="confirmTitle"></h3>
                        <button class="modal-close" onclick="Modal.close('${id}')">✕</button>
                    </div>
                    <div class="modal-body">
                        <p id="confirmMessage"></p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline" onclick="Modal.close('${id}')">Cancel</button>
                        <button class="btn btn-danger" id="confirmAction">Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
        }
        
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        
        const confirmBtn = document.getElementById('confirmAction');
        const newBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
        newBtn.id = 'confirmAction';
        newBtn.addEventListener('click', () => {
            Modal.close(id);
            if (onConfirm) onConfirm();
        });
        
        this.open(id);
    }
};

// Close modal on overlay click
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// ============================================================
// AJAX HELPER
// ============================================================
async function apiCall(url, data = {}, method = 'POST') {
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        
        let options = {
            method: method,
            headers: {},
        };
        
        if (method === 'POST') {
            if (data instanceof FormData) {
                data.append('csrf_token', csrfToken);
                options.body = data;
            } else {
                data.csrf_token = csrfToken;
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data);
            }
        }
        
        const response = await fetch(url, options);
        const result = await response.json();
        
        if (!response.ok) {
            throw new Error(result.message || 'Request failed');
        }
        
        return result;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

// ============================================================
// FILE UPLOAD AREA
// ============================================================
function initFileUpload(areaId, inputId, callback) {
    const area = document.getElementById(areaId);
    const input = document.getElementById(inputId);
    
    if (!area || !input) return;
    
    area.addEventListener('dragover', (e) => {
        e.preventDefault();
        area.classList.add('dragover');
    });
    
    area.addEventListener('dragleave', () => {
        area.classList.remove('dragover');
    });
    
    area.addEventListener('drop', (e) => {
        e.preventDefault();
        area.classList.remove('dragover');
        if (e.dataTransfer.files.length) {
            input.files = e.dataTransfer.files;
            if (callback) callback(e.dataTransfer.files[0]);
        }
    });
    
    input.addEventListener('change', () => {
        if (input.files.length && callback) {
            callback(input.files[0]);
        }
    });
}

// ============================================================
// SHORTCODE INSERTION
// ============================================================
function insertShortcode(code) {
    // Try to insert into TinyMCE if active
    if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
        tinymce.activeEditor.insertContent(code);
        return;
    }
    
    // Fallback: insert into the focused textarea/input
    const activeEl = document.activeElement;
    if (activeEl && (activeEl.tagName === 'TEXTAREA' || activeEl.tagName === 'INPUT')) {
        const start = activeEl.selectionStart;
        const end = activeEl.selectionEnd;
        activeEl.value = activeEl.value.substring(0, start) + code + activeEl.value.substring(end);
        activeEl.selectionStart = activeEl.selectionEnd = start + code.length;
        activeEl.focus();
    }
}

// ============================================================
// MOBILE SIDEBAR
// ============================================================
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
    }
}

// ============================================================
// TINYMCE INITIALIZATION
// ============================================================
function initTinyMCE(selector = '#emailBody', uploadUrl = 'api/upload-image.php') {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    tinymce.init({
        selector: selector,
        height: 500,
        skin: 'oxide-dark',
        content_css: 'dark',
        plugins: 'lists link image code table hr fullscreen preview',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | link image table hr | code fullscreen preview',
        menubar: 'file edit view insert format table',
        branding: false,
        promotion: false,
        images_upload_url: uploadUrl,
        images_upload_credentials: true,
        automatic_uploads: true,
        images_reuse_filename: false,
        file_picker_types: 'image',
        
        // Custom image upload handler
        images_upload_handler: function(blobInfo) {
            return new Promise((resolve, reject) => {
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                formData.append('csrf_token', csrfToken);
                
                fetch(uploadUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success && result.location) {
                        resolve(result.location);
                    } else {
                        reject(result.message || 'Upload failed');
                    }
                })
                .catch(error => {
                    reject('Upload failed: ' + error.message);
                });
            });
        },
        
        // Content styles for the editor
        content_style: `
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                font-size: 14px; 
                color: #333; 
                line-height: 1.6;
                padding: 10px;
                background: #ffffff;
            }
            img { max-width: 100%; height: auto; }
        `,
        
        setup: function(editor) {
            // Add shortcode button
            editor.ui.registry.addMenuButton('shortcodes', {
                text: '{ } Shortcodes',
                fetch: function(callback) {
                    const items = [
                        { type: 'menuitem', text: 'Name', onAction: () => editor.insertContent('{{name}}') },
                        { type: 'menuitem', text: 'First Name', onAction: () => editor.insertContent('{{first_name}}') },
                        { type: 'menuitem', text: 'Last Name', onAction: () => editor.insertContent('{{last_name}}') },
                        { type: 'menuitem', text: 'Email', onAction: () => editor.insertContent('{{email}}') },
                        { type: 'menuitem', text: 'Date', onAction: () => editor.insertContent('{{date}}') },
                        { type: 'menuitem', text: 'Year', onAction: () => editor.insertContent('{{year}}') },
                        { type: 'menuitem', text: 'Unsubscribe Link', onAction: () => editor.insertContent('{{unsubscribe_link}}') },
                    ];
                    callback(items);
                }
            });
            
            // Modify toolbar to include shortcodes
            editor.on('init', function() {
                // Editor is ready
            });
        }
    });
}

// ============================================================
// CAMPAIGN STATUS POLLING
// ============================================================
let statusPolling = null;

function startStatusPolling(campaignId, interval = 5000) {
    stopStatusPolling();
    
    statusPolling = setInterval(async () => {
        try {
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
            const result = await apiCall(`${basePath}/api/campaign-status.php?id=${campaignId}`, {}, 'GET');
            
            if (result.success) {
                updateCampaignUI(result.data);
                
                // Stop polling if campaign is done
                if (['completed', 'paused', 'draft'].includes(result.data.status)) {
                    stopStatusPolling();
                }
            }
        } catch (e) {
            console.error('Polling error:', e);
        }
    }, interval);
}

function stopStatusPolling() {
    if (statusPolling) {
        clearInterval(statusPolling);
        statusPolling = null;
    }
}

function updateCampaignUI(data) {
    // Update progress bar
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const statusBadge = document.getElementById('statusBadge');
    
    if (progressFill && data.total_emails > 0) {
        const pct = Math.round((data.sent_count / data.total_emails) * 100);
        progressFill.style.width = pct + '%';
        if (progressText) progressText.textContent = `${data.sent_count} / ${data.total_emails} (${pct}%)`;
    }
    
    // Update stat numbers
    const sentEl = document.getElementById('statSent');
    const failedEl = document.getElementById('statFailed');
    const pendingEl = document.getElementById('statPending');
    const clickedEl = document.getElementById('statClicked');
    
    if (sentEl) sentEl.textContent = data.sent_count || 0;
    if (failedEl) failedEl.textContent = data.failed_count || 0;
    if (pendingEl) pendingEl.textContent = data.pending_count || 0;
    if (clickedEl) clickedEl.textContent = data.click_count || 0;
    
    // Update status badge
    if (statusBadge) {
        const badgeClasses = {
            draft: 'badge-draft',
            scheduled: 'badge-scheduled',
            sending: 'badge-sending',
            completed: 'badge-completed',
            paused: 'badge-paused'
        };
        statusBadge.className = 'badge ' + (badgeClasses[data.status] || 'badge-draft');
        statusBadge.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
    }
}

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

// Debounce
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// Format file size
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Copy to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        Toast.success('Copied to clipboard!');
    }).catch(() => {
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        Toast.success('Copied to clipboard!');
    });
}

// ============================================================
// DOM READY
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    // Auto-dismiss flash messages
    document.querySelectorAll('.flash-message').forEach(flash => {
        setTimeout(() => {
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-10px)';
            setTimeout(() => flash.remove(), 300);
        }, 5000);
    });
});
