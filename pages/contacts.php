<?php
/**
 * Contact Lists Management
 */
$pageTitle = 'Contacts';
require_once __DIR__ . '/../includes/header.php';

// Handle create list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_list') {
    validateCSRF();
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($name) {
        dbInsert("INSERT INTO contact_lists (name, description) VALUES (?, ?)", [$name, $description]);
        setFlash('success', 'Contact list created successfully.');
        redirect($basePath . '/pages/contacts.php');
    }
}

// Handle delete list
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $token = $_GET['token'] ?? '';
    if (hash_equals(getCSRFToken(), $token)) {
        $id = (int) $_GET['delete'];
        dbExecute("DELETE FROM contact_lists WHERE id = ?", [$id]);
        setFlash('success', 'Contact list deleted.');
        redirect($basePath . '/pages/contacts.php');
    }
}

$lists = dbFetchAll("
    SELECT cl.*, 
        (SELECT COUNT(*) FROM contacts c WHERE c.list_id = cl.id) as actual_count,
        (SELECT COUNT(*) FROM contacts c WHERE c.list_id = cl.id AND c.is_unsubscribed = 0) as active_count
    FROM contact_lists cl 
    ORDER BY cl.created_at DESC
");
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">👥</span>Contact Lists</h1>
        <div class="subtitle">Manage your email subscriber lists</div>
    </div>
    <div class="btn-group">
        <button class="btn btn-primary" onclick="Modal.open('createListModal')">✚ New List</button>
        <button class="btn btn-outline" onclick="Modal.open('importModal')">📁 Import CSV</button>
    </div>
</div>

<!-- Lists Grid -->
<?php if (empty($lists)): ?>
    <div class="card">
        <div class="empty-state">
            <div class="empty-icon">👥</div>
            <h3>No contact lists yet</h3>
            <p>Create a list and import your contacts from a CSV file.</p>
            <button class="btn btn-primary" onclick="Modal.open('createListModal')">✚ Create List</button>
        </div>
    </div>
<?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
        <?php foreach ($lists as $list): ?>
        <div class="card" style="cursor: pointer;" onclick="window.location='<?= $basePath ?>/pages/contact-list.php?id=<?= $list['id'] ?>'">
            <div class="card-body">
                <div style="display: flex; align-items: start; justify-content: space-between; margin-bottom: 16px;">
                    <div>
                        <h3 style="font-size: 16px; font-weight: 600; color: var(--text-heading);"><?= e($list['name']) ?></h3>
                        <?php if ($list['description']): ?>
                            <p class="text-muted fs-sm mt-2"><?= e($list['description']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div onclick="event.stopPropagation();">
                        <a href="?delete=<?= $list['id'] ?>&token=<?= e(getCSRFToken()) ?>" 
                           class="btn btn-ghost btn-icon btn-sm" 
                           onclick="return confirm('Delete this list and all its contacts?')"
                           title="Delete">🗑️</a>
                    </div>
                </div>
                <div style="display: flex; gap: 20px;">
                    <div>
                        <div style="font-size: 24px; font-weight: 700; color: var(--text-heading);"><?= number_format($list['active_count']) ?></div>
                        <div class="text-muted fs-sm">Active</div>
                    </div>
                    <div>
                        <div style="font-size: 24px; font-weight: 700; color: var(--text-heading);"><?= number_format($list['actual_count']) ?></div>
                        <div class="text-muted fs-sm">Total</div>
                    </div>
                </div>
                <div class="text-muted fs-sm mt-4">Created <?= timeAgo($list['created_at']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Create List Modal -->
<div class="modal-overlay" id="createListModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Create Contact List</h3>
            <button class="modal-close" onclick="Modal.close('createListModal')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="create_list">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                
                <div class="form-group">
                    <label>List Name <span class="required">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g., Newsletter Subscribers" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Optional description"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="Modal.close('createListModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">✚ Create List</button>
            </div>
        </form>
    </div>
</div>

<!-- Import CSV Modal -->
<div class="modal-overlay" id="importModal">
    <div class="modal" style="max-width: 640px;">
        <div class="modal-header">
            <h3>Import Contacts from CSV</h3>
            <button class="modal-close" onclick="Modal.close('importModal')">✕</button>
        </div>
        <div class="modal-body">
            <!-- Step 1: Select list & file -->
            <div id="importStep1">
                <div class="form-group">
                    <label>Select Target List <span class="required">*</span></label>
                    <select id="importListId" class="form-control" required>
                        <option value="">— Select a list —</option>
                        <?php foreach ($lists as $list): ?>
                            <option value="<?= $list['id'] ?>"><?= e($list['name']) ?> (<?= $list['actual_count'] ?> contacts)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="file-upload-area" id="csvUploadArea">
                    <div class="upload-icon">📁</div>
                    <h4>Drop your CSV file here</h4>
                    <p>or click to browse. Max 10MB.</p>
                    <input type="file" id="csvFileInput" accept=".csv,.txt">
                </div>
                
                <div id="csvFileInfo" style="display: none; margin-top: 12px;" class="d-flex align-center gap-2">
                    <span>📄</span>
                    <span id="csvFileName" class="fw-500"></span>
                    <span id="csvFileSize" class="text-muted fs-sm"></span>
                </div>
            </div>
            
            <!-- Step 2: Column mapping -->
            <div id="importStep2" style="display: none;">
                <h4 style="color: var(--text-heading); margin-bottom: 12px;">Map CSV Columns</h4>
                <p class="text-muted fs-sm mb-4">Map your CSV columns to contact fields. At minimum, map the Email column.</p>
                
                <div id="columnMappings"></div>
                
                <h4 style="color: var(--text-heading); margin: 20px 0 12px;">Preview (first 3 rows)</h4>
                <div class="csv-preview-table" id="csvPreview"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="Modal.close('importModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="importNextBtn" onclick="importNext()" disabled>
                Next →
            </button>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
let csvData = null;
let importStep = 1;

// File upload handler
initFileUpload('csvUploadArea', 'csvFileInput', (file) => {
    document.getElementById('csvFileInfo').style.display = 'flex';
    document.getElementById('csvFileName').textContent = file.name;
    document.getElementById('csvFileSize').textContent = formatBytes(file.size);
    document.getElementById('importNextBtn').disabled = false;
    
    // Parse CSV client-side for preview
    const reader = new FileReader();
    reader.onload = function(e) {
        const text = e.target.result;
        const lines = text.split('\n').filter(l => l.trim());
        const delimiter = text.includes(';') && !text.includes(',') ? ';' : ',';
        
        // Simple CSV parse for preview
        const rows = lines.map(line => {
            const result = [];
            let current = '';
            let inQuotes = false;
            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                if (char === '"') {
                    inQuotes = !inQuotes;
                } else if (char === delimiter && !inQuotes) {
                    result.push(current.trim());
                    current = '';
                } else {
                    current += char;
                }
            }
            result.push(current.trim());
            return result;
        });
        
        csvData = { headers: rows[0] || [], rows: rows.slice(1) };
    };
    reader.readAsText(file);
});

function importNext() {
    const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
    
    if (importStep === 1) {
        if (!document.getElementById('importListId').value) {
            Toast.error('Please select a target list');
            return;
        }
        if (!csvData || !csvData.headers.length) {
            Toast.error('Please upload a CSV file');
            return;
        }
        
        // Show column mapping
        importStep = 2;
        document.getElementById('importStep1').style.display = 'none';
        document.getElementById('importStep2').style.display = 'block';
        document.getElementById('importNextBtn').textContent = '📥 Import';
        
        // Build mapping UI
        const fields = [
            { key: 'email', label: 'Email *', required: true },
            { key: 'name', label: 'Name' },
            { key: 'skip', label: '— Skip —' },
        ];
        
        let mappingHtml = '';
        csvData.headers.forEach((header, i) => {
            // Auto-detect common column names
            let autoMap = 'custom';
            const h = header.toLowerCase().trim();
            if (['email', 'e-mail', 'email_address', 'emailaddress'].includes(h)) autoMap = 'email';
            else if (['name', 'full_name', 'fullname', 'contact_name'].includes(h)) autoMap = 'name';
            else if (['first_name', 'firstname', 'first name'].includes(h)) autoMap = 'name';
            
            mappingHtml += `
                <div class="mapping-row">
                    <div class="mapping-source">${header}</div>
                    <div class="mapping-arrow">→</div>
                    <select class="form-control" id="mapping_${i}" style="font-size: 13px;">
                        <option value="email" ${autoMap === 'email' ? 'selected' : ''}>Email</option>
                        <option value="name" ${autoMap === 'name' ? 'selected' : ''}>Name</option>
                        <option value="custom" ${autoMap === 'custom' ? 'selected' : ''}>Custom Field (${header})</option>
                        <option value="skip">Skip</option>
                    </select>
                </div>
            `;
        });
        document.getElementById('columnMappings').innerHTML = mappingHtml;
        
        // Build preview table
        let previewHtml = '<table><thead><tr>';
        csvData.headers.forEach(h => previewHtml += `<th>${h}</th>`);
        previewHtml += '</tr></thead><tbody>';
        csvData.rows.slice(0, 3).forEach(row => {
            previewHtml += '<tr>';
            row.forEach(cell => previewHtml += `<td>${cell}</td>`);
            previewHtml += '</tr>';
        });
        previewHtml += '</tbody></table>';
        document.getElementById('csvPreview').innerHTML = previewHtml;
        
    } else if (importStep === 2) {
        // Do the actual import
        const btn = document.getElementById('importNextBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Importing...';
        
        const formData = new FormData();
        formData.append('file', document.getElementById('csvFileInput').files[0]);
        formData.append('list_id', document.getElementById('importListId').value);
        
        // Collect mappings
        const mappings = {};
        csvData.headers.forEach((header, i) => {
            const select = document.getElementById('mapping_' + i);
            if (select) {
                mappings[i] = { field: select.value, header: header };
            }
        });
        formData.append('mappings', JSON.stringify(mappings));
        
        apiCall(basePath + '/api/csv-import.php', formData)
            .then(result => {
                if (result.success) {
                    Toast.success(`Imported ${result.count} contacts!`);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Toast.error(result.message || 'Import failed');
                }
            })
            .catch(err => Toast.error(err.message || 'Import failed'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '📥 Import';
            });
    }
}
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
