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

function contactCustomValue($customFields, $field) {
    if (!is_array($customFields)) {
        return '';
    }

    $target = strtolower(preg_replace('/[^a-z0-9]+/', '', $field));
    foreach ($customFields as $key => $value) {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$key));
        if ($normalized === $target) {
            return (string)$value;
        }
    }

    return '';
}

$pageTitle = $list['name'];
require_once __DIR__ . '/../includes/header.php';

// Handle single contact add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_contact') {
    validateCSRF();
    $email = trim($_POST['email'] ?? '');
    $name = trim($_POST['contact_name'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $badgeNumber = trim($_POST['badge_number'] ?? '');

    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $customFields = [];
        if ($city !== '') {
            $customFields['City'] = $city;
        }
        if ($state !== '') {
            $customFields['State'] = $state;
        }
        if ($badgeNumber !== '') {
            $customFields['Badge Number'] = $badgeNumber;
        }

        dbInsert(
            "INSERT INTO contacts (list_id, email, name, custom_fields) VALUES (?, ?, ?, ?)",
            [$listId, $email, $name, $customFields ? json_encode($customFields) : null]
        );
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
    $whereClause .= " AND (email LIKE ? OR name LIKE ? OR custom_fields LIKE ?)";
    $params[] = "%{$search}%";
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
        <h1><span class="header-icon">Contacts</span><?= e($list['name']) ?></h1>
        <div class="subtitle"><?= number_format($totalContacts) ?> contacts<?= $list['description'] ? ' - ' . e($list['description']) : '' ?></div>
    </div>
    <div class="btn-group">
        <button class="btn btn-outline" type="button" onclick="scrollToContactActions()">Bulk Delete</button>
        <button class="btn btn-outline" type="button" onclick="Modal.open('pasteContactsModal')">Paste Contacts</button>
        <button class="btn btn-primary" type="button" onclick="Modal.open('addContactModal')">Add Contact</button>
        <a href="<?= $basePath ?>/pages/contacts.php" class="btn btn-outline">Back</a>
    </div>
</div>

<!-- Search -->
<div class="card mb-6">
    <div class="card-body" style="padding: 16px 24px;">
        <form method="GET" action="" class="d-flex gap-2">
            <input type="hidden" name="id" value="<?= $listId ?>">
            <input type="text" name="search" class="form-control" placeholder="Search by name, email, city, state, or badge number..." value="<?= e($search) ?>" style="max-width: 520px;">
            <button type="submit" class="btn btn-outline">Search</button>
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
                <div class="empty-icon">Contacts</div>
                <h3><?= $search ? 'No contacts found' : 'No contacts in this list' ?></h3>
                <p><?= $search ? 'Try a different search term.' : 'Add contacts manually, paste rows, or import from CSV.' ?></p>
            </div>
        <?php else: ?>
            <form method="POST" id="contactsBulkDeleteForm" onsubmit="return confirmBulkDeleteContacts(event)">
                <input type="hidden" name="action" value="bulk_delete_contacts">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= e(getCSRFToken()) ?>">
                <input type="hidden" name="search" value="<?= e($search) ?>">
                <input type="hidden" name="page" value="<?= $page ?>">
                <div id="contactActionsBar" style="display:flex; justify-content: space-between; align-items:center; gap:12px; padding: 16px 24px 0; flex-wrap: wrap;">
                    <div class="text-muted fs-sm">Edit table cells directly. Changes save automatically.</div>
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <button class="btn btn-outline btn-sm" type="button" onclick="Modal.open('pasteContactsModal')">Paste Contacts</button>
                        <span class="text-muted fs-sm"><span id="contactSelectedCount">0</span> selected</span>
                        <button type="submit" class="btn btn-outline" style="color: var(--color-danger); border-color: var(--color-danger);" id="contactBulkDeleteBtn" disabled>Delete Selected</button>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 36px;">
                                    <input type="checkbox" id="contactSelectAll" onchange="toggleAllContacts(this.checked)" style="width:auto;">
                                </th>
                                <th>Name</th>
                                <th>Email Address</th>
                                <th>City</th>
                                <th>State</th>
                                <th>Badge Number</th>
                                <th>Status</th>
                                <th>Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contacts as $contact):
                                $customFields = $contact['custom_fields'] ? json_decode($contact['custom_fields'], true) : [];
                                if (!is_array($customFields)) {
                                    $customFields = [];
                                }
                                $city = contactCustomValue($customFields, 'City');
                                $state = contactCustomValue($customFields, 'State');
                                $badgeNumber = contactCustomValue($customFields, 'Badge Number');
                            ?>
                            <tr data-contact-id="<?= (int)$contact['id'] ?>">
                                <td>
                                    <input type="checkbox" name="contact_ids[]" value="<?= (int)$contact['id'] ?>" class="contact-checkbox" onchange="updateContactBulkDeleteState()" style="width:auto;">
                                </td>
                                <td>
                                    <input type="text" class="form-control contact-inline-input" data-field="name" value="<?= e($contact['name']) ?>" placeholder="Name" style="min-width: 150px;">
                                </td>
                                <td>
                                    <input type="email" class="form-control contact-inline-input" data-field="email" value="<?= e($contact['email']) ?>" placeholder="email@example.com" style="min-width: 220px;">
                                </td>
                                <td>
                                    <input type="text" class="form-control contact-inline-input" data-field="city" value="<?= e($city) ?>" placeholder="City" style="min-width: 130px;">
                                </td>
                                <td>
                                    <input type="text" class="form-control contact-inline-input" data-field="state" value="<?= e($state) ?>" placeholder="State" style="min-width: 110px;">
                                </td>
                                <td>
                                    <input type="text" class="form-control contact-inline-input" data-field="badge_number" value="<?= e($badgeNumber) ?>" placeholder="Badge Number" style="min-width: 140px;">
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
                                    <div class="contact-save-status text-muted fs-sm" id="contactSaveStatus-<?= (int)$contact['id'] ?>" style="min-height: 18px; margin-bottom: 4px;"></div>
                                    <a href="?id=<?= $listId ?>&delete_contact=<?= (int)$contact['id'] ?>&token=<?= e(getCSRFToken()) ?>"
                                       class="btn btn-ghost btn-sm"
                                       onclick="return confirm('Delete this contact?')">Delete</a>
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
                        <a href="?id=<?= $listId ?>&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-outline btn-sm">Prev</a>
                    <?php endif; ?>
                    <span class="text-muted fs-sm">Page <?= $page ?> of <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?id=<?= $listId ?>&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="btn btn-outline btn-sm">Next</a>
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
            <button class="modal-close" onclick="Modal.close('addContactModal')">x</button>
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
                <div class="form-group">
                    <label>City</label>
                    <input type="text" name="city" class="form-control" placeholder="Dallas">
                </div>
                <div class="form-group">
                    <label>State</label>
                    <input type="text" name="state" class="form-control" placeholder="TX">
                </div>
                <div class="form-group">
                    <label>Badge Number</label>
                    <input type="text" name="badge_number" class="form-control" placeholder="Batch 001">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="Modal.close('addContactModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Contact</button>
            </div>
        </form>
    </div>
</div>

<!-- Paste Contacts Modal -->
<div class="modal-overlay" id="pasteContactsModal">
    <div class="modal" style="max-width: 760px;">
        <div class="modal-header">
            <h3>Paste Contacts</h3>
            <button class="modal-close" onclick="Modal.close('pasteContactsModal')">x</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Contact rows</label>
                <textarea id="pasteContactsData" class="form-control" rows="12" placeholder="Name, Email Address, City, State, Badge Number&#10;John Doe, john@example.com, Dallas, TX, Batch 001"></textarea>
                <div class="form-text">Paste CSV columns or spreadsheet rows in this order: Name, Email Address, City, State, Badge Number.</div>
            </div>
            <div id="pasteContactsPreview" class="text-muted fs-sm"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="Modal.close('pasteContactsModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="pasteContactsImportBtn" onclick="importPastedContacts()">Import Contacts</button>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'JS'
const contactListId = __CONTACT_LIST_ID__;
const contactSaveTimers = {};

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

function setContactSaveStatus(contactId, message, tone = 'muted') {
    const status = document.getElementById(`contactSaveStatus-${contactId}`);
    if (!status) return;

    status.textContent = message;
    status.style.color = tone === 'error' ? 'var(--color-danger)' : (tone === 'success' ? 'var(--color-success)' : '');
}

function getContactRowPayload(contactId) {
    const row = document.querySelector(`tr[data-contact-id="${contactId}"]`);
    const payload = { id: Number(contactId), list_id: contactListId };

    row.querySelectorAll('.contact-inline-input').forEach((input) => {
        payload[input.dataset.field] = input.value.trim();
    });

    return payload;
}

function scheduleContactSave(input) {
    const row = input.closest('tr[data-contact-id]');
    if (!row) return;

    const contactId = row.dataset.contactId;
    clearTimeout(contactSaveTimers[contactId]);
    setContactSaveStatus(contactId, 'Saving...');

    contactSaveTimers[contactId] = setTimeout(() => saveContactRow(contactId), 700);
}

async function saveContactRow(contactId) {
    try {
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        const response = await apiCall(`${basePath}/api/contact-update.php`, getContactRowPayload(contactId));
        if (response.success) {
            setContactSaveStatus(contactId, 'Saved', 'success');
            setTimeout(() => setContactSaveStatus(contactId, ''), 1800);
        } else {
            setContactSaveStatus(contactId, response.message || 'Not saved', 'error');
        }
    } catch (error) {
        setContactSaveStatus(contactId, error.message || 'Not saved', 'error');
    }
}

function parseDelimitedLine(line) {
    const delimiter = line.includes('\t') ? '\t' : ',';
    const values = [];
    let current = '';
    let inQuotes = false;

    for (let i = 0; i < line.length; i++) {
        const char = line[i];
        const next = line[i + 1];

        if (char === '"' && inQuotes && next === '"') {
            current += '"';
            i++;
            continue;
        }

        if (char === '"') {
            inQuotes = !inQuotes;
            continue;
        }

        if (char === delimiter && !inQuotes) {
            values.push(current.trim());
            current = '';
            continue;
        }

        current += char;
    }

    values.push(current.trim());
    return values;
}

function normalizeContactHeader(value) {
    return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function parsePastedContacts(text) {
    const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    if (!lines.length) return [];

    let startIndex = 0;
    let columnMap = { name: 0, email: 1, city: 2, state: 3, badge_number: 4 };
    const firstLine = parseDelimitedLine(lines[0]).map(normalizeContactHeader);
    const headerIndexes = {
        name: firstLine.findIndex((value) => ['name', 'fullname', 'contactname'].includes(value)),
        email: firstLine.findIndex((value) => ['email', 'emailaddress', 'mail'].includes(value)),
        city: firstLine.findIndex((value) => value === 'city'),
        state: firstLine.findIndex((value) => ['state', 'province', 'region'].includes(value)),
        badge_number: firstLine.findIndex((value) => ['badgenumber', 'badge', 'batchnumber', 'batch'].includes(value)),
    };

    if (headerIndexes.email !== -1) {
        startIndex = 1;
        columnMap = {
            name: headerIndexes.name,
            email: headerIndexes.email,
            city: headerIndexes.city,
            state: headerIndexes.state,
            badge_number: headerIndexes.badge_number,
        };
    }

    return lines.slice(startIndex).map((line) => {
        const columns = parseDelimitedLine(line);
        return {
            name: columnMap.name >= 0 ? (columns[columnMap.name] || '') : '',
            email: columnMap.email >= 0 ? (columns[columnMap.email] || '') : '',
            city: columnMap.city >= 0 ? (columns[columnMap.city] || '') : '',
            state: columnMap.state >= 0 ? (columns[columnMap.state] || '') : '',
            badge_number: columnMap.badge_number >= 0 ? (columns[columnMap.badge_number] || '') : '',
        };
    }).filter((row) => row.email);
}

function updatePasteContactsPreview() {
    const textarea = document.getElementById('pasteContactsData');
    const preview = document.getElementById('pasteContactsPreview');
    if (!textarea || !preview) return;

    const rows = parsePastedContacts(textarea.value);
    preview.textContent = rows.length ? `${rows.length} contact row(s) ready to import.` : '';
}

async function importPastedContacts() {
    const textarea = document.getElementById('pasteContactsData');
    const btn = document.getElementById('pasteContactsImportBtn');
    const rows = parsePastedContacts(textarea?.value || '');

    if (!rows.length) {
        Toast.error('Paste at least one contact row with an email address.');
        return;
    }

    try {
        if (btn) btn.disabled = true;
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        const response = await apiCall(`${basePath}/api/contact-paste-import.php`, {
            list_id: contactListId,
            rows,
        });

        if (response.success) {
            Toast.success(response.message || 'Contacts imported.');
            window.location.reload();
        } else {
            Toast.error(response.message || 'Import failed.');
        }
    } catch (error) {
        Toast.error(error.message || 'Import failed.');
    } finally {
        if (btn) btn.disabled = false;
    }
}

document.querySelectorAll('.contact-inline-input').forEach((input) => {
    input.addEventListener('input', () => scheduleContactSave(input));
});

document.getElementById('pasteContactsData')?.addEventListener('input', updatePasteContactsPreview);
updateContactBulkDeleteState();
JS;

$pageScript = str_replace('__CONTACT_LIST_ID__', (string)$listId, $pageScript);
require_once __DIR__ . '/../includes/footer.php';
?>
