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
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
