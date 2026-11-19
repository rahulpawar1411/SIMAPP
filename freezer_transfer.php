<?php
/**
 * Standalone transfer page removed with AMC/KYC.
 * Keep old bookmarks working.
 */
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id > 0 ? ('profile_view.php?id=' . $id) : 'profiles.php'));
exit;
