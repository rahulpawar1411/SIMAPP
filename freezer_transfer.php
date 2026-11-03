<?php
/**
 * Transfer is folded into KYC verification.
 * Old bookmarks / links redirect here.
 */
$id = (int)($_GET['id'] ?? 0);
header('Location: freezer_kyc.php' . ($id > 0 ? '?id=' . $id : ''));
exit;
