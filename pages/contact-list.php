<?php
/**
 * View/Manage Contacts in a List
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$listId = (int) ($_GET['id'] ?? 0);
if (!$listId) {
    header('Location: contacts.php');
    exit;
}

$list = dbFetchOne("SELECT * FROM contact_lists WHERE id = ?", [$listId]);
if (!$list) {
    header('Location: contacts.php');
    exit;
}

$pageTitle = $list['name'];
require_once __DIR__ . '/../includes/header.php';

// Handle single contact add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_contact') {
    validateCSRF();
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['contact_name'] ?? '');
    
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        dbInsert("INSERT INTO contacts (list_id, email, name) VALUES (?, ?, ?)", [$listId, $email, $name]);
        dbExecute("UPDATE contact_lists SET total_contacts = (SELECT COUNT(*) FROM contacts WHERE list_id = ?) WHERE id = ?", [$listId, $listId]);
        setFlash('success', 'Contact added successfully.');
        redirect($basePath . '/pages/contact-list.php?id=' . $listId);
    } else {
        setFlash('error', 'Invalid email address.');
    }
}

// Handle bulk delete contacts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_contacts') {
    validateCSRF();

    $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['contact_ids'] ?? []), fn($id) => $id > 0)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        dbExecute("DELETE FROM contacts WHERE list_id = ? AND id IN ({$placeholders})", array_merge([$listId], $ids));
        dbExecute("UPDATE contact_lists SET total_contacts = (SELECT COUNT(*) FROM contacts WHERE list_id = ?) WHERE id = ?", [$listId, $listId]);
        setFlash('success', count($ids) . ' contact(s) deleted.');
    } else {
        setFlash('error', 'Please select at least one contact.');
    }

    $redirectUrl = $basePath . '/pages/contact-list.php?id=' . $listId;
    if (!empty($_POST['search'])) {
        $redirectUrl .= '&search=' . urlencode($_POST['search']);
    }
    if (!empty($_POST['page'])) {
        $redirectUrl .= '&page=' . (int)$_POST['page'];
    }
    redirect($redirectUrl);
}

// Handle delete contact
if (isset($_GET['delete_contact']) && is_numeric($_GET['delete_contact'])) {
    $token = $_GET['token'] ?? '';
    if (hash_equals(getCSRFToken(), $token)) {
        dbExecute("DELETE FROM contacts WHERE id = ? AND list_id = ?", [(int)$_GET['delete_contact'], $listId]);
        dbExecute("UPDATE contact_lists SET total_contacts = (SELECT COUNT(*) FROM contacts WHERE list_id = ?) WHERE id = ?", [$listId, $listId]);
        setFlash('success', 'Contact deleted.');
        redirect($basePath . '/pages/contact-list.php?id=' . $listId);
    }
}

// Search
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$whereClause = "list_id = ?";
$params = [$listId];

if ($search) {
    $whereClause .= " AND (email LIKE ? OR name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$totalContacts = (int) dbFetchValue("SELECT COUNT(*) FROM contacts WHERE {$whereClause}", $params);
$totalPages = max(1, ceil($totalContacts / $perPage));

$contacts = dbFetchAll("
    SELECT * FROM contacts 
    WHERE {$whereClause} 
    ORDER BY created_at DESC 
    LIMIT {$perPage} OFFSET {$offset}
", $params);
?>

<div class="page-header">
    <div>
        <h1><span class="header-icon">👥</span><?= e($list['name']) ?></h1>
        <div class="subtitle"><?= number_format($totalContacts) ?> contacts<?= $list['description'] ? ' — ' . e($list['description']) : '' ?></div>
    </div>
    <div class="btn-group">
        <button class="btn btn-outline" type="button" onclick="scrollToContactActions()">☑ Bulk Delete</button>
        <button class="btn btn-primary" onclick="Modal.open('addContactModal')">✚ Add Contact</button>
        <a href="<?= $basePath ?>/pages/contacts.php" class="btn btn-outline">← Back</a>
    </div>
</div>

<!-- Search -->
<div class="card mb-6">
    <div class="card-body" style="padding: 16px 24px;">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="id" value="<?= $listId ?>">
            <input type="text" name="search" class="form-control" placeholder="Search by name or email..." value="<?= e($search) ?>" style="max-width: 400px;">
            <button type="submit" class="btn btn-outline">🔍 Search</button>
            <?php if ($search): ?>
                <a href="?id=<?= $listId ?>" class="btn btn-ghost">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Contacts Table -->
<div class="card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($contacts)): ?>
            <div class="empty-state">
                <div class="empty-icon">👤</div>
                <h3><?= $search ? 'No contacts found' : 'No contacts in this list' ?></h3>
                <p><?= $search ? 'Try a different search term.' : 'Add contacts manually or import from CSV.' ?></p>
            </div>
        <?php else: ?>
            <form method="POST" id="contactsBulkDeleteForm" onsubmit="return confirmBulkDeleteContacts(event)">
                <input type="hidden" name="action" value="bulk_delete_contacts">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                <input type="hidden" name="search" value="<?= e($search) ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <div id="contactActionsBar" style="display:flex; justify-content: space-between; align-items:center; gap:12px; padding: 16px 24px 0; flex-wrap: wrap;">
                    <div class="text-muted fs-sm">Select contacts to delete them in bulk.</div>
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <span class="text-muted fs-sm"><span id="contactSelectedCount">0</span> selected</span>
                        <button type="submit" class="btn btn-outline" style="color: var(--color-danger); border-color: var(--color-danger);" id="contactBulkDeleteBtn" disabled>🗑️ Delete Selected</button>
                    </div>
                </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 36px;">
                                <input type="checkbox" id="contactSelectAll" onchange="toggleAllContacts(this.checked)" style="width:auto;">
                            </th>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Custom Fields</th>
                            <th>Status</th>
                            <th>Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): 
                            $customFields = $contact['custom_fields'] ? json_decode($contact['custom_fields'], true) : [];
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="contact_ids[]" value="<?= $contact['id'] ?>" class="contact-checkbox" onchange="updateContactBulkDeleteState()" style="width:auto;">
                            </td>
                            <td><strong style="color: var(--text-primary);"><?= e($contact['email']) ?></strong></td>
                            <td><?= e($contact['name']) ?: '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($customFields): ?>
                                    <?php foreach (array_slice($customFields, 0, 3) as $k => $v): ?>
                                        <span class="badge badge-draft" style="margin-right: 4px; font-size: 11px;"><?= e($k) ?>: <?= e($v) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($customFields) > 3): ?>
                                        <span class="text-muted fs-sm">+<?= count($customFields) - 3 ?> more</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($contact['is_unsubscribed']): ?>
                                    <span class="badge badge-failed">Unsubscribed</span>
                                <?php else: ?>
                                    <span class="badge badge-completed">Active</span>
                                <?php endif; ?>
                            </td>
                            <td><?= timeAgo($contact['created_at']) ?></td>
                            <td>
                                <a href="?id=<?= $listId ?>&delete_contact=<?= $contact['id'] ?>&token=<?= e(getCSRFToken()) ?>" 
                                   class="btn btn-ghost btn-sm"
                                   onclick="return confirm('Delete this contact?')">🗑️</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card-footer" style="justify-content: center; gap: 8px;">
                <?php if ($page > 1): ?>
                    <a href="?id=<?= $listId ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-outline btn-sm">← Prev</a>
                <?php endif; ?>
                <span class="text-muted fs-sm">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="?id=<?= $listId ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-outline btn-sm">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Add Contact Modal -->
<div class="modal-overlay" id="addContactModal">
    <div class="modal">
        <div class="modal-header">
            <h3>Add Contact</h3>
            <button class="modal-close" onclick="Modal.close('addContactModal')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_contact">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" class="form-control" placeholder="contact@example.com" required>
                </div>
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="contact_name" class="form-control" placeholder="John Doe">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="Modal.close('addContactModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">✚ Add Contact</button>
            </div>
        </form>
    </div>
</div>

<?php
$pageScript = <<<'JS'
function toggleAllContacts(checked) {
    document.querySelectorAll('.contact-checkbox').forEach((checkbox) => {
        checkbox.checked = checked;
    });
    updateContactBulkDeleteState();
}

function updateContactBulkDeleteState() {
    const selected = document.querySelectorAll('.contact-checkbox:checked').length;
    const total = document.querySelectorAll('.contact-checkbox').length;
    const selectAll = document.getElementById('contactSelectAll');
    const countEl = document.getElementById('contactSelectedCount');
    const btn = document.getElementById('contactBulkDeleteBtn');

    if (countEl) countEl.textContent = String(selected);
    if (btn) btn.disabled = selected === 0;
    if (selectAll) {
        selectAll.checked = total > 0 && selected === total;
        selectAll.indeterminate = selected > 0 && selected < total;
    }
}

function confirmBulkDeleteContacts(event) {
    const selected = document.querySelectorAll('.contact-checkbox:checked').length;
    if (!selected) {
        event.preventDefault();
        Toast.error('Please select at least one contact.');
        return false;
    }
    return confirm(`Delete ${selected} selected contact(s)?`);
}

function scrollToContactActions() {
    const bar = document.getElementById('contactActionsBar');
    if (bar) {
        bar.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

updateContactBulkDeleteState();
JS;

require_once __DIR__ . '/../includes/footer.php';
?>
