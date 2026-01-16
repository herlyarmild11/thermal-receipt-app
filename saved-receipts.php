<?php
require_once 'includes/functions.php';

if (empty($_GET['template_id'])) {
    header("Location: index.php");
    exit;
}

$template_id = (int)$_GET['template_id'];

// Ambil Info Template
$stmt = $pdo->prepare("SELECT * FROM templates WHERE id = ?");
$stmt->execute([$template_id]);
$template = $stmt->fetch();

if (!$template) die("Template hilang.");

// === LOGIC HAPUS SATUAN ===
if (!empty($_GET['delete_receipt'])) {
    $rid = (int)$_GET['delete_receipt'];
    $pdo->prepare("DELETE FROM receipts WHERE id = ?")->execute([$rid]);
    header("Location: saved-receipts.php?template_id=" . $template_id);
    exit;
}

// === LOGIC HAPUS MASSAL (BULK DELETE) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_ids'])) {
    $ids_to_delete = array_map('intval', $_POST['bulk_ids']);
    
    if (!empty($ids_to_delete)) {
        // Buat string placeholder (?,?,?) sesuai jumlah ID
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
        
        $sql = "DELETE FROM receipts WHERE id IN ($placeholders) AND template_id = ?";
        
        // Tambahkan template_id ke array parameter untuk keamanan (agar tidak menghapus milik template lain)
        $params = $ids_to_delete;
        $params[] = $template_id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    header("Location: saved-receipts.php?template_id=" . $template_id);
    exit;
}

// Ambil Data Nota
$stmt = $pdo->prepare("SELECT * FROM receipts WHERE template_id = ? ORDER BY created_at DESC");
$stmt->execute([$template_id]);
$receipts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Riwayat Nota - <?= htmlspecialchars($template['name']) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #eef2f5; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; }
        
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .header h2 { margin: 0; color: #333; }
        .btn-back { text-decoration: none; color: #555; font-weight: bold; font-size: 14px; }
        
        /* TOOLBAR HAPUS MASSAL */
        .bulk-toolbar {
            background: #fff3cd; color: #856404; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px;
            display: flex; justify-content: space-between; align-items: center; border: 1px solid #ffeeba;
            visibility: hidden; opacity: 0; transition: 0.3s; height: 0; overflow: hidden;
        }
        .bulk-toolbar.active { visibility: visible; opacity: 1; height: auto; margin-bottom: 15px; }
        
        .receipt-list { display: flex; flex-direction: column; gap: 10px; }
        
        .receipt-item { 
            background: white; padding: 15px; border-radius: 6px; 
            display: flex; gap: 15px; align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: 0.2s;
            border-left: 4px solid transparent;
        }
        .receipt-item:hover { transform: translateX(2px); border-left-color: #007bff; }
        
        /* Checkbox Style */
        .chk-wrapper { display: flex; align-items: center; }
        .chk-item { transform: scale(1.3); cursor: pointer; }

        .receipt-info { flex: 1; }
        .receipt-date { font-size: 12px; color: #888; margin-bottom: 5px; }
        .receipt-summary { font-family: 'Consolas', monospace; color: #333; font-size: 14px; line-height: 1.4; }
        .receipt-summary span { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; border: 1px solid #eee; margin-right: 5px; display: inline-block; margin-bottom: 2px; }

        .actions { display: flex; gap: 10px; }
        .btn { padding: 8px 15px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: bold; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; }
        .btn-use { background: #28a745; color: white; }
        .btn-use:hover { background: #218838; }
        .btn-del { background: white; color: #dc3545; border: 1px solid #dc3545; }
        .btn-del:hover { background: #dc3545; color: white; }
        
        .btn-bulk-del { background: #dc3545; color: white; padding: 8px 20px; }
        .btn-bulk-del:hover { background: #c82333; }

        .empty { text-align: center; padding: 40px; color: #777; }
        
        /* Select All Container */
        .controls { margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
        .select-all-label { font-size: 14px; color: #555; cursor: pointer; user-select: none; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h2>📂 Riwayat Nota</h2>
                <small style="color:#666;">Template: <b><?= htmlspecialchars($template['name']) ?></b></small>
            </div>
            <a href="index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Kembali ke Dashboard</a>
        </div>

        <form method="POST" id="bulkForm">
            <div class="controls">
                <label class="select-all-label">
                    <input type="checkbox" id="selectAll" style="transform: scale(1.2); margin-right: 5px;"> Pilih Semua
                </label>
            </div>

            <div class="bulk-toolbar" id="bulkToolbar">
                <span><b id="selectedCount">0</b> nota dipilih</span>
                <button type="submit" class="btn btn-bulk-del" onclick="return confirm('Yakin hapus data yang dipilih secara permanen?')">
                    <i class="fas fa-trash-alt"></i> Hapus Terpilih
                </button>
            </div>

            <div class="receipt-list">
                <?php if (empty($receipts)): ?>
                    <div class="empty">
                        <i class="fas fa-folder-open" style="font-size: 40px; margin-bottom: 10px; color: #ddd;"></i><br>
                        Belum ada riwayat nota untuk template ini.
                    </div>
                <?php else: ?>
                    <?php foreach ($receipts as $r): ?>
                        <?php 
                            $data = json_decode($r['data'], true);
                            // Ambil 3 data pertama sebagai preview
                            $preview_data = array_slice($data, 0, 3);
                        ?>
                        <div class="receipt-item">
                            <div class="chk-wrapper">
                                <input type="checkbox" name="bulk_ids[]" value="<?= $r['id'] ?>" class="chk-item">
                            </div>
                            
                            <div class="receipt-info">
                                <div class="receipt-date">
                                    <i class="far fa-clock"></i> <?= date('d M Y, H:i', strtotime($r['created_at'])) ?>
                                </div>
                                <div class="receipt-summary">
                                    <?php foreach ($preview_data as $key => $val): ?>
                                        <span><b><?= strtoupper(str_replace('_',' ',$key)) ?>:</b> <?= htmlspecialchars(substr($val, 0, 25)) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="actions">
                                <a href="print-preview.php?template_id=<?= $template_id ?>&load_receipt=<?= $r['id'] ?>" class="btn btn-use" title="Gunakan data ini lagi">
                                    <i class="fas fa-pen"></i> Pakai
                                </a>
                                <a href="saved-receipts.php?template_id=<?= $template_id ?>&delete_receipt=<?= $r['id'] ?>" class="btn btn-del" onclick="return confirm('Hapus satu nota ini?')" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <script>
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.chk-item');
        const bulkToolbar = document.getElementById('bulkToolbar');
        const selectedCountSpan = document.getElementById('selectedCount');

        // Fungsi Update Tampilan Toolbar
        function updateToolbar() {
            const checkedCount = document.querySelectorAll('.chk-item:checked').length;
            selectedCountSpan.innerText = checkedCount;
            
            if (checkedCount > 0) {
                bulkToolbar.classList.add('active');
            } else {
                bulkToolbar.classList.remove('active');
            }
        }

        // Event Listener Select All
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => {
                    cb.checked = this.checked;
                });
                updateToolbar();
            });
        }

        // Event Listener per Checkbox
        checkboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                // Uncheck "Select All" jika salah satu unchecked
                if (!this.checked) {
                    if(selectAll) selectAll.checked = false;
                }
                // Cek apakah semua checked manual
                else {
                    const allChecked = document.querySelectorAll('.chk-item:checked').length === checkboxes.length;
                    if(selectAll) selectAll.checked = allChecked;
                }
                updateToolbar();
            });
        });
    </script>
</body>
</html>