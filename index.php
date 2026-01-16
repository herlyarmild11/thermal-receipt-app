<?php
require_once 'includes/functions.php';

// === 1. LOGIC DELETE ===
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT logo_path FROM templates WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && $row['logo_path'] && file_exists($row['logo_path'])) {
        unlink($row['logo_path']);
    }
    $stmt = $pdo->prepare("DELETE FROM templates WHERE id = ?");
    $stmt->execute([$id]);
    $pdo->prepare("DELETE FROM receipts WHERE template_id = ?")->execute([$id]);
    header("Location: index.php?msg=deleted");
    exit;
}

// === 2. LOGIC EXPORT (FULL BACKUP) ===
if (isset($_GET['export'])) {
    $id = (int)$_GET['export'];
    $stmt = $pdo->prepare("SELECT name, paper_size, structure FROM templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($template) {
        $stmtR = $pdo->prepare("SELECT data, created_at FROM receipts WHERE template_id = ? ORDER BY id ASC");
        $stmtR->execute([$id]);
        $receipts = $stmtR->fetchAll(PDO::FETCH_ASSOC);
        $exportData = ['type' => 'full_backup', 'version' => '2.0', 'template' => $template, 'receipts' => $receipts];
        $filename = 'backup_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($template['name'])) . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($exportData, JSON_PRETTY_PRINT);
        exit;
    }
}

// === 3. LOGIC IMPORT ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $jsonContent = file_get_contents($file['tmp_name']);
        $data = json_decode($jsonContent, true);
        $imported_count = 0;
        if ($data) {
            $templateData = isset($data['type']) && $data['type'] === 'full_backup' ? $data['template'] : $data;
            if (isset($templateData['name']) && isset($templateData['structure'])) {
                $stmt = $pdo->prepare("INSERT INTO templates (name, paper_size, structure) VALUES (?, ?, ?)");
                $stmt->execute([$templateData['name'] . ' (Imp)', $templateData['paper_size'] ?? '80mm', $templateData['structure']]);
                $newTemplateId = $pdo->lastInsertId();
                if (isset($data['receipts']) && is_array($data['receipts'])) {
                    $stmtReceipt = $pdo->prepare("INSERT INTO receipts (template_id, data, created_at) VALUES (?, ?, ?)");
                    foreach ($data['receipts'] as $r) {
                        $stmtReceipt->execute([$newTemplateId, $r['data'], $r['created_at']]);
                        $imported_count++;
                    }
                }
                header("Location: index.php?msg=imported&count=" . $imported_count);
                exit;
            } else { $error_msg = "Format JSON tidak valid!"; }
        } else { $error_msg = "File rusak."; }
    }
}

$stmt = $pdo->query("SELECT * FROM templates ORDER BY created_at DESC");
$templates = $stmt->fetchAll();

// --- MEMANGGIL HEADER (SIDEBAR + TOPBAR) ---
require_once 'includes/header.php';
?>

<style>
    /* Content Area */
    .dashboard-content { padding: 25px; overflow-y: auto; height: calc(100vh - 60px); }

    /* Hero Section */
    .hero-card {
        background: #fff;
        border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 25px; margin-bottom: 25px;
        display: flex; justify-content: space-between; align-items: center;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero-text h2 { margin: 0 0 5px 0; font-size: 1.4rem; color: #1e293b; letter-spacing: -0.5px; }
    .hero-text p { margin: 0; color: #64748b; font-size: 0.9rem; }
    
    /* Buttons */
    .action-bar { display: flex; gap: 10px; }
    .btn { 
        padding: 10px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; 
        cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
        border: 1px solid transparent; transition: all 0.2s;
    }
    .btn-primary { background: var(--primary); color: white; box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3); }
    .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); }
    .btn-outline { background: white; border-color: #cbd5e1; color: #334155; }
    .btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }

    /* Grid System */
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; }

    /* Compact Card */
    .card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        overflow: hidden; display: flex; flex-direction: column;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); border-color: #cbd5e1; }

    .card-header { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; background: #fff; }
    .card-title { font-weight: 700; font-size: 0.95rem; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; }
    .card-meta { font-size: 0.75rem; color: #64748b; display: flex; gap: 8px; align-items: center; }
    .badge { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-weight: 600; color: #475569; }
    .badge-green { background: #dcfce7; color: #166534; }

    .card-preview {
        height: 100px; /* Compact Height */
        background: #f8fafc; display: flex; align-items: center; justify-content: center; 
        padding: 15px; position: relative; overflow: hidden; border-bottom: 1px solid #f1f5f9;
    }
    .template-logo { max-width: 80%; max-height: 80%; object-fit: contain; filter: grayscale(100%); opacity: 0.7; }
    .no-logo { font-size: 2rem; color: #e2e8f0; }

    .card-actions {
        padding: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #fff;
    }
    .btn-card {
        padding: 6px 0; font-size: 0.75rem; justify-content: center; border-radius: 4px;
        border: 1px solid #e2e8f0; background: #fff; color: #64748b; font-weight: 500;
        text-align: center; display: flex; align-items: center; text-decoration: none;
        gap: 6px; transition: 0.1s;
    }
    .btn-card:hover { background: #f8fafc; color: #334155; border-color: #cbd5e1; }
    
    .btn-print { grid-column: span 2; background: #eff6ff; color: #2563eb; border-color: #bfdbfe; font-weight: 600; padding: 8px 0; }
    .btn-print:hover { background: #dbeafe; border-color: #93c5fd; }
    
    .btn-delete { color: #ef4444; border-color: #fee2e2; background: #fef2f2; }
    .btn-delete:hover { border-color: #fca5a5; }

    .toast { position: fixed; top: 80px; right: 20px; padding: 12px 20px; background: #10b981; color: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); animation: fadeOut 4s forwards; z-index: 2000; font-size: 0.9rem; font-weight: 500; }
    @keyframes fadeOut { 0% { opacity: 1; } 80% { opacity: 1; } 100% { opacity: 0; visibility: hidden; } }
    
    /* Responsive Hero */
    @media (max-width: 768px) {
        .hero-card { flex-direction: column; text-align: center; gap: 15px; }
    }
</style>

<div class="dashboard-content">
    
    <?php if (isset($_GET['msg'])): ?>
        <div class="toast" style="background: <?= $_GET['msg']=='deleted'?'#ef4444':'#10b981' ?>">
            <?php 
                if($_GET['msg']=='created') echo '<i class="fas fa-check-circle"></i> Template baru siap digunakan!';
                elseif($_GET['msg']=='deleted') echo '<i class="fas fa-trash"></i> Template & data berhasil dihapus.';
                elseif($_GET['msg']=='imported') echo '<i class="fas fa-file-import"></i> Impor Berhasil! ' . (isset($_GET['count']) ? $_GET['count'].' nota dipulihkan.' : '');
            ?>
        </div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="toast" style="background: #ef4444"><?= $error_msg ?></div>
    <?php endif; ?>

    <div class="hero-card">
        <div class="hero-text">
            <h2>Selamat Datang, Admin!</h2>
            <p>Kelola semua template struk dan transaksi pembayaran Anda.</p>
        </div>
        <div class="action-bar">
            <form method="POST" enctype="multipart/form-data" id="importForm" style="display:none;">
                <input type="file" name="import_file" id="importInput" accept=".json" onchange="document.getElementById('importForm').submit()">
            </form>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('importInput').click()">
                <i class="fas fa-file-import"></i> Impor
            </button>
            <a href="designer.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Template Baru
            </a>
        </div>
    </div>

    <div class="grid">
        <?php foreach ($templates as $t): ?>
            <?php $count = $pdo->query("SELECT COUNT(*) FROM receipts WHERE template_id=".$t['id'])->fetchColumn(); ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title" title="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></div>
                    <div class="card-meta">
                        <span class="badge"><?= $t['paper_size'] ?></span>
                        <span class="badge badge-green"><?= $count ?> Nota</span>
                    </div>
                </div>
                
                <div class="card-preview">
                    <?php if (!empty($t['logo_path']) && file_exists($t['logo_path'])): ?>
                        <img src="<?= $t['logo_path'] ?>" alt="Logo" class="template-logo">
                    <?php else: ?>
                        <i class="fas fa-image no-logo"></i>
                    <?php endif; ?>
                </div>

                <div class="card-actions">
                    <a href="print-preview.php?template_id=<?= $t['id'] ?>" class="btn-card btn-print">
                        <i class="fas fa-plus-circle"></i> Buat Nota
                    </a>
                    
                    <a href="saved-receipts.php?template_id=<?= $t['id'] ?>" class="btn-card" title="Riwayat">
                        <i class="fas fa-history"></i> Riwayat
                    </a>
                    
                    <a href="designer.php?edit=<?= $t['id'] ?>" class="btn-card" title="Edit Desain">
                        <i class="fas fa-pen-nib"></i> Desain
                    </a>

                    <a href="index.php?export=<?= $t['id'] ?>" class="btn-card" title="Backup Data">
                        <i class="fas fa-download"></i> Backup
                    </a>

                    <a href="index.php?delete=<?= $t['id'] ?>" class="btn-card btn-delete" onclick="return confirm('PERINGATAN: Hapus template ini akan MENGHILANGKAN SEMUA DATA NOTA terkait. Lanjutkan?')" title="Hapus Permanen">
                        <i class="fas fa-trash"></i> Hapus
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (count($templates) == 0): ?>
        <div style="text-align:center; padding:60px; color:#94a3b8; border: 2px dashed #e2e8f0; border-radius: 12px; margin-top:20px;">
            <i class="far fa-folder-open" style="font-size:3rem; margin-bottom:15px; opacity:0.5;"></i>
            <p>Belum ada template. Silakan buat baru atau impor backup.</p>
        </div>
    <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>