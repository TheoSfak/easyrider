<?php
/**
 * EasyRide - Member Document Download
 * Serves member documents securely (admins only)
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('members_view');

$docId      = (int) get('id');
$memberId = (int) get('member');

if (!$docId || !$memberId) {
    setFlash('error', 'Μη έγκυρο αίτημα.');
    redirect('members.php');
}

$doc = dbFetchOne(
    "SELECT * FROM member_documents WHERE id = ? AND user_id = ?",
    [$docId, $memberId]
);

if (!$doc) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε.');
    redirect('member-view.php?id=' . $memberId);
}

$filePath = __DIR__ . '/uploads/member-docs/' . $doc['stored_name'];

if (!file_exists($filePath)) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε στο σύστημα.');
    redirect('member-view.php?id=' . $memberId);
}

// Log the access
logAudit('download_document', 'member_documents', $docId, $doc['label']);

// Serve the file
$safeName = preg_replace('/[^\w\s\-.]/', '', $doc['original_name']);
header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
