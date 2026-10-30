<?php
/**
 * Fix duplicate unit_sequence values across freezers.
 * Keeps the oldest row per sequence; reassigns a unique 6-char letter+digit mix
 * to later duplicates and updates unique_freezer_id suffix + QR if present.
 */
require_once __DIR__ . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\FreezerIdGenerator;
use App\Services\QRCodeService;

try {
    $pdo = Database::getConnection();
    $pdo->beginTransaction();

    $rows = $pdo->query(
        "SELECT `id`, `unique_freezer_id`, `unit_sequence`, `qr_code_path`
         FROM `freezers`
         ORDER BY `id` ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    $fixed = 0;

    foreach ($rows as $row) {
        $oldSeq = strtoupper(trim((string)$row['unit_sequence']));
        $id = (int)$row['id'];
        $oldId = (string)$row['unique_freezer_id'];

        // Keep first occurrence of each non-empty sequence
        if ($oldSeq !== '' && !isset($seen[$oldSeq])) {
            $seen[$oldSeq] = $id;
            continue;
        }

        // Empty or duplicate → new unique 6-char letter+digit mix
        $newSeq = null;
        $newUniqueId = null;
        for ($attempt = 0; $attempt < 500; $attempt++) {
            $candidate = FreezerIdGenerator::randomUnitSequence();
            if (isset($seen[$candidate])) {
                continue;
            }
            if (FreezerIdGenerator::isUnitSequenceDuplicate($candidate, $id)) {
                continue;
            }

            if (preg_match('/^(.*-)([A-Z0-9]{3,10})$/i', $oldId, $m)) {
                $candidateId = strtoupper($m[1] . $candidate);
            } else {
                $candidateId = strtoupper(rtrim($oldId, '-') . '-' . $candidate);
            }

            if (FreezerIdGenerator::isDuplicate($candidateId, $id)) {
                continue;
            }

            $newSeq = $candidate;
            $newUniqueId = $candidateId;
            break;
        }

        if ($newSeq === null || $newUniqueId === null) {
            throw new RuntimeException("Could not allocate unique unit_sequence for freezer #{$id}");
        }

        $qrPath = $row['qr_code_path'] ?? null;
        try {
            $qr = QRCodeService::generateAndSave($newUniqueId);
            $qrPath = $qr['path'] ?? $qrPath;
        } catch (Throwable $e) {
            // Keep old QR path if regenerate fails
        }

        $stmt = $pdo->prepare(
            "UPDATE `freezers`
             SET `unit_sequence` = :seq,
                 `unique_freezer_id` = :uid,
                 `qr_code_path` = :qr,
                 `qr_code_data` = :uid2
             WHERE `id` = :id"
        );
        $stmt->execute([
            'seq' => $newSeq,
            'uid' => $newUniqueId,
            'qr' => $qrPath,
            'uid2' => $newUniqueId,
            'id' => $id,
        ]);

        $seen[$newSeq] = $id;
        $fixed++;
        echo "Fixed #{$id}: {$oldSeq} → {$newSeq} | {$oldId} → {$newUniqueId}\n";
    }

    $pdo->commit();
    echo "Done. Updated {$fixed} freezer(s) with duplicate/empty unit_sequence.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
