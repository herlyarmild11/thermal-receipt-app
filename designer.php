<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'includes/functions.php';

$message = '';
$s = [];
$template_data = [];

// === LOGIC SIMPAN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $paper_size = ($_POST['paper_size'] ?? '80mm');
    $template_id = (int)($_POST['template_id'] ?? 0);
    $custom_content = trim($_POST['custom_template'] ?? '');
    
    // Config Data
    $logo_width = (int)($_POST['logo_width'] ?? 100);
    $logo_height = (int)($_POST['logo_height'] ?? 100);
    $logo_wrap = $_POST['logo_wrap'] ?? 'none';
    $footer_width = (int)($_POST['footer_width'] ?? 100);
    $footer_height = (int)($_POST['footer_height'] ?? 100);
    $footer_wrap = $_POST['footer_wrap'] ?? 'none';
    $global_font_size = (int)($_POST['global_font_size'] ?? 12);
    $font_family = $_POST['font_family'] ?? 'Consolas'; 
    
    // Simpan Margin
    $margin_top = (int)($_POST['margin_top'] ?? 0);
    $margin_bottom = (int)($_POST['margin_bottom'] ?? 0);
    $margin_left = (int)($_POST['margin_left'] ?? 0);
    $margin_right = (int)($_POST['margin_right'] ?? 0);

    $existing_structure = [];
    if ($template_id > 0) {
        $stmtOld = $pdo->prepare("SELECT structure FROM templates WHERE id = ?");
        $stmtOld->execute([$template_id]);
        $jsonOld = $stmtOld->fetchColumn();
        if ($jsonOld) $existing_structure = json_decode($jsonOld, true);
    }

    if (!$name) {
        $message = '❌ Nama template wajib diisi!';
    } else {
        $structure = [
            'mode' => 'custom', 
            'content' => $custom_content, 
            'logo_width' => $logo_width, 
            'logo_height' => $logo_height,
            'logo_wrap' => $logo_wrap,
            'footer_width' => $footer_width, 
            'footer_height' => $footer_height,
            'footer_wrap' => $footer_wrap,
            'footer_path' => $existing_structure['footer_path'] ?? null,
            'font_size' => $global_font_size,
            'font_family' => $font_family,
            'margin_top' => $margin_top,
            'margin_bottom' => $margin_bottom,
            'margin_left' => $margin_left,
            'margin_right' => $margin_right
        ];

        $logo_path = null; $is_update = $template_id > 0;

        if (!empty($_FILES['logo']['tmp_name'])) {
            $file = $_FILES['logo'];
            if (in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $tempPath = $uploadDir . 'logo_' . uniqid() . '.png';
                if (processThermalImage($file['tmp_name'], $tempPath, 500)) $logo_path = $tempPath;
            }
        }
        if (!empty($_FILES['footer_logo']['tmp_name'])) {
            $file = $_FILES['footer_logo'];
            if (in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])) {
                $uploadDir = 'uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $tempFooterPath = $uploadDir . 'footer_' . uniqid() . '.png';
                if (processThermalImage($file['tmp_name'], $tempFooterPath, 500)) $structure['footer_path'] = $tempFooterPath;
            }
        }

        $json_structure = json_encode($structure);

        try {
            if ($is_update) {
                if ($logo_path) {
                    $finalPath = 'uploads/logo_' . $template_id . '.png';
                    rename($logo_path, $finalPath);
                    $pdo->prepare("UPDATE templates SET logo_path = ? WHERE id = ?")->execute([$finalPath, $template_id]);
                }
                $stmt = $pdo->prepare("UPDATE templates SET name = ?, paper_size = ?, structure = ? WHERE id = ?");
                $stmt->execute([$name, $paper_size, $json_structure, $template_id]);
                header("Location: designer.php?edit=" . $template_id . "&msg=updated"); exit;
            } else {
                $stmt = $pdo->prepare("INSERT INTO templates (name, paper_size, structure, logo_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $paper_size, $json_structure, $logo_path]);
                $new_id = $pdo->lastInsertId();
                if ($logo_path) {
                    $finalPath = 'uploads/logo_' . $new_id . '.png';
                    rename($logo_path, $finalPath);
                    $pdo->prepare("UPDATE templates SET logo_path = ? WHERE id = ?")->execute([$finalPath, $new_id]);
                }
                header("Location: designer.php?edit=" . $new_id . "&msg=created"); exit;
            }
        } catch (Exception $e) { $message = '❌ Error: ' . $e->getMessage(); }
    }
}

if (!empty($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->execute([$id]);
    $template_data = $stmt->fetch();
    if ($template_data && !empty($template_data['structure'])) {
        $s = json_decode($template_data['structure'], true);
    }
    if (isset($_GET['msg'])) {
        if ($_GET['msg'] == 'updated') $message = '✅ Perubahan disimpan!';
        if ($_GET['msg'] == 'created') $message = '✅ Template dibuat!';
    }
}

require_once 'includes/header.php';
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=VT323&display=swap');
    @import url('https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;700&display=swap');

    .workspace-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f1f5f9; padding: 20px; height: calc(100vh - 100px); }
    .designer-grid { display: grid; grid-template-columns: 280px 1fr 380px; gap: 20px; height: 100%; overflow: hidden; }
    .panel-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #cbd5e1; display: flex; flex-direction: column; overflow: hidden; position: relative; }
    .panel-tabs { display: flex; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
    .panel-tab-btn { flex: 1; padding: 12px 5px; font-size: 0.75rem; font-weight: 700; color: #64748b; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; transition: 0.2s; text-transform: uppercase; user-select: none; }
    .panel-tab-btn:hover { background: #f1f5f9; color: #334155; }
    .panel-tab-btn.active { background: #fff; color: var(--primary); border-bottom-color: var(--primary); }
    .panel-content { padding: 20px; overflow-y: auto; flex: 1; display: none; }
    .panel-content.active { display: block; animation: fadeIn 0.2s; }
    .form-group { margin-bottom: 15px; }
    .form-label { display: block; margin-bottom: 5px; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; user-select: none; }
    .form-control { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; background: #f8fafc; transition: 0.2s; box-sizing: border-box; }
    .form-control:focus { outline: none; border-color: var(--primary); background: #fff; }
    .custom-select-wrapper { position: relative; width: 100%; user-select: none; }
    .custom-select-trigger { display: flex; justify-content: space-between; align-items: center; width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-size: 0.85rem; color: #334155; cursor: pointer; box-sizing: border-box; }
    .custom-select-trigger:hover { background: #fff; border-color:#94a3b8; }
    .custom-options { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; display: none; max-height: 200px; overflow-y: auto; }
    .custom-options.open { display: block; }
    .custom-option { padding: 8px 10px; font-size: 0.85rem; color: #334155; cursor: pointer; }
    .custom-option:hover { background: #f1f5f9; color: var(--primary); }
    .prop-row { display: flex; gap: 10px; margin-bottom: 12px; align-items: center; }
    .prop-col { flex: 1; display: flex; flex-direction: column; }
    .input-icon-group { position: relative; display: flex; align-items: center; }
    .input-icon { position: absolute; left: 8px; font-size: 0.8rem; color: #94a3b8; pointer-events: none; }
    .input-with-icon { padding-left: 28px; text-align: left; font-weight: 600; color: #334155; }
    .icon-toolbar { display: flex; gap: 5px; background: #f1f5f9; padding: 4px; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 15px; }
    .icon-btn { flex: 1; border: none; background: transparent; padding: 6px; border-radius: 4px; cursor: pointer; color: #64748b; font-size: 0.9rem; transition: 0.1s; display: flex; justify-content: center; align-items: center; user-select: none; }
    .icon-btn:hover { background: #fff; color: #334155; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .icon-btn.active { background: #fff; color: var(--primary); font-weight: bold; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
    .btn-save { background: var(--primary); color: white; border: none; padding: 12px; border-radius: 6px; width: 100%; font-weight: 600; cursor: pointer; margin-top: 15px; }
    .btn-save:hover { background: var(--primary-hover); }
    .col-editor { position: relative; }
    .editor-toolbar { padding: 10px 15px; background: #fff; border-bottom: 1px solid #e2e8f0; display: flex; gap: 8px; align-items: center; user-select: none; }
    .tool-btn { background: #fff; border: 1px solid #cbd5e1; padding: 5px 10px; border-radius: 4px; font-size: 0.8rem; cursor: pointer; color: #475569; }
    .tool-btn, .icon-btn, .panel-tab-btn { user-select: none; }
    #editor { flex: 1; width: 100%; padding: 25px; border: none; font-family: 'Consolas', monospace; font-size: 13px; line-height: 1.6; resize: none; outline: none; color: #334155; white-space: pre; overflow-x: auto; }
    .col-preview { background: #e2e8f0; padding: 30px; display: block; overflow-y: auto; text-align: center; }
    .preview-paper { background: white; display: inline-block; text-align: left; min-height: 200px; padding: 5mm; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2); margin-bottom: 50px; box-sizing: border-box; font-family: 'Consolas', monospace; font-size: 12px; color: #000; white-space: pre-wrap; word-wrap: break-word; transition: width 0.3s; transform-origin: top center; }
    
    /* [DIHAPUS] width: 100% dan Flexbox dihilangkan agar elemen bisa berdampingan dengan gambar */
    .preview-line { position: relative; display: block; min-height: 1em; }
    
    .cs-overlay { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 700px; max-width: 95vw; height: 600px; max-height: 90vh; background: #fff; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.3); z-index: 9999; display: none; flex-direction: column; overflow: hidden; }
    .cs-overlay.active { display: flex; animation: fadeIn 0.2s; }
    .cs-header { padding: 15px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; font-weight:700; color:#1e293b; }
    .cs-tabs { display: flex; background: #fff; border-bottom: 1px solid #e2e8f0; overflow-x: auto; }
    .cs-tab { padding: 12px 20px; font-size: 0.9rem; font-weight: 600; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; white-space: nowrap; transition:0.2s; }
    .cs-tab:hover { background: #f1f5f9; color:#334155; }
    .cs-tab.active { color: var(--primary); border-bottom-color: var(--primary); background: #f0f9ff; }
    .cs-body { flex: 1; overflow-y: auto; padding: 0; background: #fff; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
    .cs-body::-webkit-scrollbar { width: 8px; }
    .cs-body::-webkit-scrollbar-track { background: #f1f5f9; }
    .cs-body::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 4px; border: 2px solid #f1f5f9; }
    .cs-panel { display: none; padding: 25px; }
    .cs-panel.active { display: block; }
    .code-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; color: #334155; }
    .code-table th { text-align: left; padding: 10px; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 0.8rem; text-transform: uppercase; background:#f8fafc; position:sticky; top:-25px; z-index:10; }
    .code-table td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    .pill { background: #eff6ff; color: #2563eb; padding: 4px 8px; border-radius: 6px; font-family: 'Consolas', monospace; border: 1px solid #bfdbfe; font-size: 0.85rem; font-weight: 600; display: inline-block; }
    .pill-desc { color: #475569; font-size: 0.85rem; margin-top: 5px; line-height: 1.5; }
    .pill-ex { color: #059669; font-family: 'Consolas', monospace; font-size: 0.8rem; display: block; margin-top: 5px; background:#ecfdf5; padding:6px; border-radius:6px; border:1px solid #d1fae5; }
    .code-category { font-weight:800; color:#1e293b; margin-top:25px; margin-bottom:10px; border-bottom:2px solid #e2e8f0; padding-bottom:8px; font-size:1rem; letter-spacing:0.5px; }
    .toast { position: fixed; top: 80px; right: 20px; padding: 12px 20px; background: #10b981; color: white; border-radius: 8px; z-index: 2000; animation: fadeOut 3s forwards; }
    @keyframes fadeOut { to { opacity: 0; visibility: hidden; } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
</style>

<?php if ($message): ?><div class="toast"><?= $message ?></div><?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="workspace-wrapper">
    <input type="hidden" name="template_id" value="<?= $template_data['id'] ?? '' ?>">
    
    <div class="designer-grid">
        <aside class="panel-card unselectable">
            <div class="panel-tabs">
                <button type="button" class="panel-tab-btn active js-prevent-focus" onmousedown="event.preventDefault()" onclick="switchTab('tab-doc')">Dokumen</button>
                <button type="button" class="panel-tab-btn js-prevent-focus" onmousedown="event.preventDefault()" onclick="switchTab('tab-char')">Karakter</button>
                <button type="button" class="panel-tab-btn js-prevent-focus" onmousedown="event.preventDefault()" onclick="switchTab('tab-para')">Paragraf</button>
            </div>

            <div class="panel-content active" id="tab-doc">
                <div class="form-group"><label class="form-label">Nama Template</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($template_data['name'] ?? '') ?>" required></div>
                <div class="form-group"><label class="form-label">Ukuran Kertas</label>
                    <select name="paper_size" id="paperSizeSelect" class="form-control">
                        <option value="80mm" <?= ($template_data['paper_size']??'80mm')==='80mm'?'selected':'' ?>>80mm (Standar)</option>
                        <option value="58mm" <?= ($template_data['paper_size']??'80mm')==='58mm'?'selected':'' ?>>58mm (Kecil)</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Font Default (Global)</label>
                    <select name="font_family" id="globalFontSelect" class="form-control">
                        <option value="Consolas" <?= ($s['font_family']??'Consolas')==='Consolas'?'selected':'' ?>>Consolas (Default)</option>
                        <option value="VT323" <?= ($s['font_family']??'Consolas')==='VT323'?'selected':'' ?>>Indomaret (VT323)</option>
                        <option value="Inconsolata" <?= ($s['font_family']??'Consolas')==='Inconsolata'?'selected':'' ?>>Thermal Modern</option>
                        <option value="Courier New" <?= ($s['font_family']??'Consolas')==='Courier New'?'selected':'' ?>>Courier New</option>
                        <option value="Arial" <?= ($s['font_family']??'Consolas')==='Arial'?'selected':'' ?>>Arial</option>
                    </select>
                </div>
                
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Margin Atas</label><input type="number" name="margin_top" id="inputMarginTop" value="<?= $s['margin_top'] ?? 0 ?>" class="form-control" placeholder="0"></div>
                    <div class="prop-col"><label class="form-label">Margin Bawah</label><input type="number" name="margin_bottom" id="inputMarginBottom" value="<?= $s['margin_bottom'] ?? 0 ?>" class="form-control" placeholder="0"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Margin Kiri</label><input type="number" name="margin_left" id="inputMarginLeft" value="<?= $s['margin_left'] ?? 0 ?>" class="form-control" placeholder="0"></div>
                    <div class="prop-col"><label class="form-label">Margin Kanan</label><input type="number" name="margin_right" id="inputMarginRight" value="<?= $s['margin_right'] ?? 0 ?>" class="form-control" placeholder="0"></div>
                </div>

                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Logo W(px)</label><div class="input-icon-group"><i class="fas fa-ruler-horizontal input-icon"></i><input type="number" name="logo_width" id="inputLogoWidth" value="<?= $s['logo_width'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Logo H(px)</label><div class="input-icon-group"><i class="fas fa-arrows-alt-v input-icon"></i><input type="number" name="logo_height" id="inputLogoHeight" value="<?= $s['logo_height'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Upload</label><input type="file" name="logo" accept="image/*" class="form-control" style="padding:6px; font-size:0.7rem;"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col">
                        <label class="form-label">Wrap Text Logo</label>
                        <select name="logo_wrap" id="inputLogoWrap" class="form-control">
                            <option value="none" <?= ($s['logo_wrap']??'none')==='none'?'selected':'' ?>>Tidak (Inline / Bisa Center)</option>
                            <option value="left" <?= ($s['logo_wrap']??'none')==='left'?'selected':'' ?>>Kiri (Teks di Kanan Logo)</option>
                            <option value="right" <?= ($s['logo_wrap']??'none')==='right'?'selected':'' ?>>Kanan (Teks di Kiri Logo)</option>
                        </select>
                    </div>
                </div>

                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">QR W(px)</label><div class="input-icon-group"><i class="fas fa-ruler-horizontal input-icon"></i><input type="number" name="footer_width" id="inputFooterWidth" value="<?= $s['footer_width'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">QR H(px)</label><div class="input-icon-group"><i class="fas fa-arrows-alt-v input-icon"></i><input type="number" name="footer_height" id="inputFooterHeight" value="<?= $s['footer_height'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Upload</label><input type="file" name="footer_logo" accept="image/*" class="form-control" style="padding:6px; font-size:0.7rem;"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col">
                        <label class="form-label">Wrap Text QR Code</label>
                        <select name="footer_wrap" id="inputFooterWrap" class="form-control">
                            <option value="none" <?= ($s['footer_wrap']??'none')==='none'?'selected':'' ?>>Tidak (Inline / Bisa Center)</option>
                            <option value="left" <?= ($s['footer_wrap']??'none')==='left'?'selected':'' ?>>Kiri (Teks di Kanan QR)</option>
                            <option value="right" <?= ($s['footer_wrap']??'none')==='right'?'selected':'' ?>>Kanan (Teks di Kiri QR)</option>
                        </select>
                    </div>
                </div>
                
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Default Size</label><div class="input-icon-group"><i class="fas fa-text-height input-icon"></i><input type="number" name="global_font_size" id="globalFontSize" value="<?= $s['font_size'] ?? 12 ?>" class="form-control input-with-icon"></div></div>
                </div>
                <div style="flex:1;"></div>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> SIMPAN DESAIN</button>
            </div>

            <div class="panel-content" id="tab-char">
                <div class="form-group">
                    <label class="form-label">Font Family (Per Baris)</label>
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger js-prevent-focus" id="fontTrigger">
                            <span id="fontDisplay">Ganti Font Blok...</span><i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                        </div>
                        <div class="custom-options" id="fontOptions">
                            <div class="custom-option js-prevent-focus" data-val="Consolas">Consolas</div>
                            <div class="custom-option js-prevent-focus" data-val="VT323">Indomaret (VT323)</div>
                            <div class="custom-option js-prevent-focus" data-val="Inconsolata">Thermal Modern</div>
                            <div class="custom-option js-prevent-focus" data-val="Courier New">Courier New</div>
                            <div class="custom-option js-prevent-focus" data-val="Arial">Arial</div>
                        </div>
                    </div>
                </div>
                
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label" title="Font Size"><i class="fas fa-text-height"></i> Size (px)</label><input type="number" id="charSize" class="form-control" placeholder="Auto"></div>
                    <div class="prop-col"><label class="form-label" title="Line Height"><i class="fas fa-arrows-alt-v"></i> Leading (%)</label><input type="number" id="charLead" class="form-control" placeholder="120"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label" title="Letter Spacing"><i class="fas fa-text-width"></i> Tracking</label><input type="number" id="charTrack" class="form-control" placeholder="0" step="0.5"></div>
                    <div class="prop-col"><label class="form-label" title="Horizontal Scale"><i class="fas fa-arrows-alt-h"></i> Scale (%)</label><input type="number" id="charScale" class="form-control" placeholder="100" step="5"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label" title="Baseline Shift"><i class="fas fa-level-up-alt"></i> Baseline</label><input type="number" id="charBase" class="form-control" placeholder="0"></div>
                </div>
                <label class="form-label">Style</label>
                <div class="icon-toolbar">
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('B')" title="Bold"><i class="fas fa-bold"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('I')" title="Italic"><i class="fas fa-italic"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyTextTrans('upper')" title="Uppercase"><i class="fas fa-font"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault()" onclick="applyTextTrans('lower')" title="Lowercase" style="font-size:0.7rem;">tt</button>
                </div>
            </div>

            <div class="panel-content" id="tab-para">
                <label class="form-label">Alignment & Split</label>
                <div class="icon-toolbar">
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('align', 'left')" title="Left"><i class="fas fa-align-left"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('align', 'center')" title="Center"><i class="fas fa-align-center"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('align', 'right')" title="Right"><i class="fas fa-align-right"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('align', 'justify')" title="Justify"><i class="fas fa-align-justify"></i></button>
                    <button type="button" class="icon-btn js-prevent-focus" onmousedown="event.preventDefault(); applyBlockTag('split')" title="Split Columns"><i class="fas fa-arrows-alt-h"></i></button>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Indent Left</label><input type="number" id="paraIndL" class="form-control" placeholder="0"></div>
                    <div class="prop-col"><label class="form-label">Indent Right</label><input type="number" id="paraIndR" class="form-control" placeholder="0"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Space Before</label><input type="number" id="paraMT" class="form-control" placeholder="0"></div>
                    <div class="prop-col"><label class="form-label">Space After</label><input type="number" id="paraMB" class="form-control" placeholder="0"></div>
                </div>
                <label class="form-label">Special</label>
                <div class="editor-toolbar">
                    <button type="button" class="tool-btn js-prevent-focus" onmousedown="event.preventDefault(); insertText('[TAB]')" title="Insert Tab"><i class="fas fa-long-arrow-alt-right"></i> TAB</button>
                </div>
            </div>
        </aside>

        <main class="panel-card col-editor">
            <div class="editor-toolbar">
                <button type="button" class="tool-btn btn-text js-prevent-focus" onmousedown="event.preventDefault(); toggleCheatSheet()" style="background:#eff6ff; color:#2563eb; border-color:#bfdbfe;"><i class="fas fa-book"></i> Kamus Kode Lengkap</button>
                <div style="border-left:1px solid #e2e8f0; height:20px; margin:0 5px;"></div>
                <button type="button" class="tool-btn js-prevent-focus" onmousedown="event.preventDefault(); insertText('[LOGO]')" title="Logo"><i class="far fa-image"></i> Logo</button>
                <button type="button" class="tool-btn js-prevent-focus" onmousedown="event.preventDefault(); insertText('[QR]')" title="QR"><i class="fas fa-qrcode"></i> QR</button>
            </div>
            
            <textarea name="custom_template" id="editor" placeholder="Ketik struktur struk..." spellcheck="false"><?= htmlspecialchars($s['content'] ?? '') ?></textarea>
            
            <div id="cheatSheet" class="cs-overlay">
                <div class="cs-header">
                    <span><i class="fas fa-code"></i> Kamus Kode Lengkap</span>
                    <button type="button" class="js-prevent-focus" onmousedown="event.preventDefault(); toggleCheatSheet()" style="background:none;border:none;cursor:pointer;color:#64748b;font-size:1.1rem;"><i class="fas fa-times"></i></button>
                </div>
                <div class="cs-tabs">
                    <div class="cs-tab active" onclick="switchCsTab('cs-vars')">Data Input</div>
                    <div class="cs-tab" onclick="switchCsTab('cs-format')">Format Text</div>
                    <div class="cs-tab" onclick="switchCsTab('cs-datetime')">Tgl & Waktu</div>
                    <div class="cs-tab" onclick="switchCsTab('cs-advanced')">Fitur Canggih</div>
                </div>
                <div class="cs-body">
                    <div id="cs-vars" class="cs-panel active">
                        <table class="code-table">
                            <tr><th>Kode</th><th>Deskripsi</th></tr>
                            <tr><td><span class="pill">{{nama}}</span></td><td><div class="pill-desc">Input teks biasa. Variabel 'nama' akan muncul di form input.</div></td></tr>
                            <tr><td><span class="pill">{{!id}}</span></td><td><div class="pill-desc"><strong>Hidden Input:</strong> Wajib diisi (atau otomatis) tapi tidak dicetak di kertas struk. Berguna untuk QR code.</div></td></tr>
                            <tr><td><span class="pill">{{ [1] nama }}</span></td><td><div class="pill-desc"><strong>Sorting:</strong> Mengatur urutan input di form. Angka [1] akan muncul paling atas.</div></td></tr>
                            <tr><td><span class="pill">{{nama(10)}}</span></td><td><div class="pill-desc"><strong>Max Length:</strong> Membatasi input maksimal 10 karakter.</div></td></tr>
                            <tr><td><span class="pill">{{^nama}}</span></td><td><div class="pill-desc"><strong>UPPERCASE:</strong> Otomatis ubah input menjadi huruf besar semua.</div></td></tr>
                            <tr><td><span class="pill">{{*nama}}</span></td><td><div class="pill-desc"><strong>Title Case:</strong> Huruf besar di awal setiap kata.</div></td></tr>
                        </table>
                    </div>

                    <div id="cs-format" class="cs-panel">
                        <table class="code-table">
                            <tr><th>Kode</th><th>Deskripsi</th></tr>
                            <tr><td><span class="pill">[TAB]</span></td><td><div class="pill-desc">Membuat jarak spasi ke grid kolom berikutnya layaknya tombol Tab.</div></td></tr>
                            <tr><td><span class="pill">[W:20]Teks[W]</span></td><td><div class="pill-desc">Memaksa teks memiliki lebar persis 20 karakter agar baris bawahnya bisa lurus sejajar sempurna.</div></td></tr>
                            <tr><td><span class="pill">{{$harga}}</span></td><td><div class="pill-desc">Format angka dengan pemisah ribuan (koma).<br><span class="pill-ex">Input: 50000 -> Cetak: 50,000</span></div></td></tr>
                            <tr><td><span class="pill">{{$$harga}}</span></td><td><div class="pill-desc">Format Rupiah lengkap.<br><span class="pill-ex">Input: 50000 -> Cetak: Rp. 50.000</span></div></td></tr>
                            <tr><td><span class="pill">[B] Teks [B]</span></td><td><div class="pill-desc">Membuat teks menjadi <strong>Tebal (Bold)</strong>.</div></td></tr>
                            <tr><td><span class="pill">[I] Teks [I]</span></td><td><div class="pill-desc">Membuat teks menjadi <em>Miring (Italic)</em>.</div></td></tr>
                            <tr><td><span class="pill">[C] Teks</span></td><td><div class="pill-desc">Rata Tengah (Center).</div></td></tr>
                            <tr><td><span class="pill">[R] Teks</span></td><td><div class="pill-desc">Rata Kanan (Right).</div></td></tr>
                            <tr><td><span class="pill">Kiri [R] Kanan</span></td><td><div class="pill-desc">Split Column: Teks 'Kiri' di kiri, 'Kanan' mentok di kanan.</div></td></tr>
                        </table>
                    </div>

                    <div id="cs-datetime" class="cs-panel">
                        <table class="code-table">
                            <tr><th>Kode</th><th>Output Contoh</th></tr>
                            <tr><td><span class="pill">{{@tgl}}</span></td><td><div class="pill-desc">20/05/2025</div></td></tr>
                            <tr><td><span class="pill">{{@@tgl}}</span></td><td><div class="pill-desc">20.05.25 (Singkat)</div></td></tr>
                            <tr><td><span class="pill">{{@@@tgl}}</span></td><td><div class="pill-desc">20 Mei 2025 (Indo Lengkap)</div></td></tr>
                            <tr><td><span class="pill">{{@:my tgl}}</span></td><td><div class="pill-desc">Mei 2025 (Custom)</div></td></tr>
                            <tr><td><span class="pill">{{%jam}}</span></td><td><div class="pill-desc">14:30 (Jam:Menit)</div></td></tr>
                            <tr><td><span class="pill">{{%%jam}}</span></td><td><div class="pill-desc">14:30:59 (Full Detik)</div></td></tr>
                        </table>
                    </div>

                    <div id="cs-advanced" class="cs-panel">
                        <div class="code-category">SMART INDENT (Word Wrap)</div>
                        <table class="code-table">
                            <tr>
                                <td style="width:40%"><span class="pill">{{ ##Total|Potong|Indent var }}</span></td>
                                <td>
                                    <div class="pill-desc">Memecah teks panjang menjadi beberapa baris secara otomatis dengan indentasi rapi.</div>
                                    <span class="pill-ex">{{ ##100|20|5 catatan }}</span>
                                </td>
                            </tr>
                        </table>
                        <div class="code-category">FORMULA MATEMATIKA</div>
                        <table class="code-table">
                            <tr>
                                <td><span class="pill">{{ total = qty * harga }}</span></td>
                                <td><div class="pill-desc">Hitung otomatis. Support +, -, *, /</div></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </main>

        <aside class="panel-card col-preview">
            <div class="preview-label">LIVE PREVIEW</div>
            <div class="preview-paper" id="previewContainer"></div>
        </aside>
    </div>
</form>

<?php require_once 'includes/footer.php'; ?>

<script>
    const editor = document.getElementById('editor');
    const previewContainer = document.getElementById('previewContainer');
    const paperSizeSelect = document.getElementById('paperSizeSelect');
    const globalFontSelect = document.getElementById('globalFontSelect');
    
    const inputLogoWidth = document.getElementById('inputLogoWidth');
    const inputLogoHeight = document.getElementById('inputLogoHeight');
    const inputLogoWrap = document.getElementById('inputLogoWrap');
    
    const inputFooterWidth = document.getElementById('inputFooterWidth');
    const inputFooterHeight = document.getElementById('inputFooterHeight');
    const inputFooterWrap = document.getElementById('inputFooterWrap');
    
    const globalFontSize = document.getElementById('globalFontSize');
    
    const inputMarginTop = document.getElementById('inputMarginTop');
    const inputMarginBottom = document.getElementById('inputMarginBottom');
    const inputMarginLeft = document.getElementById('inputMarginLeft');
    const inputMarginRight = document.getElementById('inputMarginRight');

    let lastSel = { start: 0, end: 0 };

    function saveSel() {
        if (document.activeElement === editor) {
            lastSel.start = editor.selectionStart;
            lastSel.end = editor.selectionEnd;
        }
    }

    editor.addEventListener('mouseup', saveSel);
    editor.addEventListener('keyup', saveSel);
    editor.addEventListener('focus', saveSel);
    editor.addEventListener('select', saveSel);

    function restoreSelection() {
        editor.focus();
        editor.setSelectionRange(lastSel.start, lastSel.end);
    }

    function attachInputListeners() {
        const map = {
            'charSize': 'S', 'charLead': 'LH', 'charTrack': 'TR', 
            'charScale': 'HS', 'charBase': 'BS',
            'paraIndL': 'IL', 'paraIndR': 'IR', 'paraMT': 'MT', 'paraMB': 'MB'
        };

        for (const [id, tag] of Object.entries(map)) {
            const el = document.getElementById(id);
            if(el) {
                el.addEventListener('input', function() {
                    applyBlockTag(tag, this.value, true); 
                });
            }
        }
    }
    attachInputListeners();

    const fontTrigger = document.getElementById('fontTrigger');
    const fontOptions = document.getElementById('fontOptions');
    
    fontTrigger.addEventListener('mousedown', function(e) {
        e.preventDefault(); 
        e.stopPropagation();
        fontOptions.classList.toggle('open');
    });

    document.querySelectorAll('.custom-option').forEach(opt => {
        opt.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const val = this.getAttribute('data-val');
            document.getElementById('fontDisplay').innerText = val;
            fontOptions.classList.remove('open');
            applyBlockTag('F', val, false);
        });
    });

    document.addEventListener('mousedown', function(e) {
        if (!e.target.closest('.custom-select-wrapper')) {
            fontOptions.classList.remove('open');
        }
    });

    function switchTab(id) {
        document.querySelectorAll('.panel-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.panel-tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        event.target.classList.add('active');
        restoreSelection(); 
    }

    function toggleCheatSheet() {
        const cs = document.getElementById('cheatSheet');
        cs.style.display = (cs.style.display === 'flex') ? 'none' : 'flex';
    }

    function switchCsTab(tabId) {
        document.querySelectorAll('.cs-tab').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.cs-panel').forEach(el => el.classList.remove('active'));
        event.target.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    function applyBlockTag(key, val = null, fromInput = false) {
        let start = lastSel.start;
        let end = lastSel.end;
        const text = editor.value;

        const rangeStart = text.lastIndexOf('\n', start - 1) + 1;
        let rangeEnd = text.indexOf('\n', end);
        if (rangeEnd === -1) rangeEnd = text.length;

        const blockText = text.substring(rangeStart, rangeEnd);
        const lines = blockText.split('\n');
        
        const newLines = lines.map(line => {
            let currentLine = line;
            let tag = '';

            if (key === 'align') {
                currentLine = currentLine.replace(/\[(L|C|R|J)\]\s*/g, ""); 
                if (val === 'center') tag = '[C] ';
                else if (val === 'right') tag = '[R] ';
                else if (val === 'justify') tag = '[J] ';
                currentLine = tag + currentLine;
            }
            else if (key === 'split') {
                if (!currentLine.includes('[R]')) currentLine = currentLine + " [R] 0";
            }
            else if (key === 'B' || key === 'I') {
                let t = `[${key}]`;
                if (currentLine.includes(t)) currentLine = currentLine.replace(t, "");
                else currentLine = t + currentLine;
            }
            else if (['F','S','LH','TR','HS','BS','IL','IR','MT','MB'].includes(key)) {
                let regex = new RegExp(`\\[${key}:[^\\]]+\\]\\s*`, 'g');
                currentLine = currentLine.replace(regex, "");
                if (val && val !== '') {
                    tag = `[${key}:${val}] `;
                    currentLine = tag + currentLine;
                }
            }
            return currentLine;
        });

        const newBlockText = newLines.join('\n');
        editor.setRangeText(newBlockText, rangeStart, rangeEnd, 'select');
        
        const newLength = newBlockText.length;
        const newEndPos = rangeStart + newLength;

        if (fromInput) {
            lastSel = { start: rangeStart, end: newEndPos };
            editor.setSelectionRange(rangeStart, newEndPos);
        } else {
            lastSel = { start: newEndPos, end: newEndPos };
            restoreSelection();
        }
        update();
    }

    function applyTextTrans(type) {
        let start = lastSel.start;
        let end = lastSel.end;
        const wasSelection = (start !== end);

        if(!wasSelection) {
            const text = editor.value;
            const rangeStart = text.lastIndexOf('\n', start - 1) + 1;
            let rangeEnd = text.indexOf('\n', start);
            if(rangeEnd === -1) rangeEnd = text.length;
            
            let line = text.substring(rangeStart, rangeEnd);
            line = (type === 'upper') ? line.toUpperCase() : line.toLowerCase();
            
            editor.setRangeText(line, rangeStart, rangeEnd, 'select');
            lastSel = { start: rangeStart + line.length, end: rangeStart + line.length };
        } else {
            const selText = editor.value.substring(start, end);
            const newText = (type === 'upper') ? selText.toUpperCase() : selText.toLowerCase();
            editor.setRangeText(newText, start, end, 'select');
            lastSel = { start: start, end: start + newText.length };
        }
        restoreSelection();
        update();
    }

    function insertText(txt) {
        restoreSelection();
        editor.setRangeText(txt, editor.selectionStart, editor.selectionEnd, 'select');
        saveSel();
        update();
    }

    function update() {
        const w = paperSizeSelect.value === '58mm' ? '48mm' : '72mm'; // DIKUNCI 48mm AGAR TIDAK TERPOTONG MESIN
        const gSize = (globalFontSize.value || 12) + 'px';
        const gFont = globalFontSelect ? globalFontSelect.value : 'Consolas';
        
        const mTop = (inputMarginTop.value || 0) + 'px';
        const mBottom = (inputMarginBottom.value || 0) + 'px';
        const mLeft = (inputMarginLeft.value || 0) + 'px';
        const mRight = (inputMarginRight.value || 0) + 'px';
        
        const lW = (inputLogoWidth ? (inputLogoWidth.value || 100) : 100) + 'px';
        const lH = (inputLogoHeight ? (inputLogoHeight.value || 100) : 100) + 'px';
        const fW = (inputFooterWidth ? (inputFooterWidth.value || 100) : 100) + 'px';
        const fH = (inputFooterHeight ? (inputFooterHeight.value || 100) : 100) + 'px';

        previewContainer.style.width = w;
        previewContainer.style.padding = `${mTop} ${mRight} ${mBottom} ${mLeft}`;
        previewContainer.style.fontFamily = gFont + ', monospace'; 

        const lines = editor.value.split('\n');
        let html = '';

        lines.forEach(line => {
            let txt = line;
            let st = {
                fs: gSize, lh: '1.2', tr: '0px', scale: '1', base: '0px', align: 'left', 
                pl: '0px', pr: '0px', mt: '0px', mb: '0px', fw: 'normal', fst: 'normal', font: 'inherit'
            };

            let m;
            m = txt.match(/\[F:([^\]]+)\]\s*/); if(m){ st.font = m[1]; txt = txt.replace(m[0],''); }
            m = txt.match(/\[S:([\-\d\.]+)\]\s*/); if(m){ st.fs = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[LH:([\-\d\.]+)\]\s*/); if(m){ st.lh = (parseFloat(m[1])/100); txt = txt.replace(m[0],''); }
            m = txt.match(/\[TR:([\d\-\.]+)\]\s*/); if(m){ st.tr = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[HS:([\-\d\.]+)\]\s*/); if(m){ st.scale = (parseFloat(m[1])/100); txt = txt.replace(m[0],''); }
            m = txt.match(/\[BS:([\d\-\.]+)\]\s*/); if(m){ st.base = (-1 * parseFloat(m[1]))+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[IL:([\-\d]+)\]\s*/); if(m){ st.pl = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[IR:([\-\d]+)\]\s*/); if(m){ st.pr = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[MT:([\-\d]+)\]\s*/); if(m){ st.mt = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[MB:([\-\d]+)\]\s*/); if(m){ st.mb = m[1]+'px'; txt = txt.replace(m[0],''); }
            
            if(txt.includes('[C]')) { st.align = 'center'; txt = txt.replace(/\[C\]\s*/,''); }
            else if(txt.includes('[R]') && !txt.includes(' [R] ')) { st.align = 'right'; txt = txt.replace(/\[R\]\s*/,''); }
            else if(txt.includes('[J]')) { st.align = 'justify'; txt = txt.replace(/\[J\]\s*/,''); }
            else if(txt.includes('[L]')) { st.align = 'left'; txt = txt.replace(/\[L\]\s*/,''); }
            
            if(txt.includes('[B]')) { st.fw = 'bold'; txt = txt.replace(/\[B\]\s*/,''); }
            if(txt.includes('[I]')) { st.fst = 'italic'; txt = txt.replace(/\[I\]\s*/,''); }

            txt = txt.replace(/\{\{.*?\}\}/g, "DATA");
            
            // Tab Preview (Real Tab Stop)
            txt = txt.replace(/\[TAB\]/g, '\t');
            txt = txt.replace(/\[TAB\s*(.*?)\]/g, (match, p1) => {
                if (p1.trim() === '') return '\t';
                if (p1.startsWith(':')) return '&nbsp;'.repeat(parseInt(p1.substring(1)));
                return `<span style="display:inline-block; width:${p1.length}ch;"></span>`;
            });
            
            // Fitur Fix Width / Lebar Kolom
            txt = txt.replace(/\[W:(\d+)\](.*?)\[W\]/g, '<span style="display:inline-block; width:$1ch;">$2</span>');

            if (txt.includes('[LOGO]')) {
                const lWrap = inputLogoWrap ? inputLogoWrap.value : 'none';
                let fC = ''; let mC = 'margin:0 5px;';
                if (lWrap === 'left') { fC = 'float:left;'; mC = 'margin:0 10px 5px 0;'; }
                if (lWrap === 'right') { fC = 'float:right;'; mC = 'margin:0 0 5px 10px;'; }
                
                let imgPreview = `<div style="width:${lW}; height:${lH}; background:#e2e8f0; border:1px dashed #94a3b8; display:inline-flex; align-items:center; justify-content:center; font-size:10px; color:#64748b; vertical-align:top; ${fC} ${mC}">LOGO</div>`;
                txt = txt.replace(/\[LOGO\]/g, imgPreview);
            }
            if (txt.includes('[QR]')) {
                const fWrap = inputFooterWrap ? inputFooterWrap.value : 'none';
                let fC = ''; let mC = 'margin:0 5px;';
                if (fWrap === 'left') { fC = 'float:left;'; mC = 'margin:0 10px 5px 0;'; }
                if (fWrap === 'right') { fC = 'float:right;'; mC = 'margin:0 0 5px 10px;'; }

                let qrPreview = `<div style="width:${fW}; height:${fH}; background:#e2e8f0; border:1px dashed #94a3b8; display:inline-flex; align-items:center; justify-content:center; font-size:10px; color:#64748b; vertical-align:top; ${fC} ${mC}">QR</div>`;
                txt = txt.replace(/\[QR\]/g, qrPreview);
            }

            // Hapus transform jika skala 1, karena transform memblokir float/wrap
            let transformCss = (st.scale != 1) ? `transform:scale(${st.scale},1); transform-origin:left;` : '';
            let css = `font-family:'${st.font}', monospace; font-size:${st.fs}; line-height:${st.lh}; letter-spacing:${st.tr}; ${transformCss} position:relative; top:${st.base}; text-align:${st.align}; padding-left:${st.pl}; padding-right:${st.pr}; margin-top:${st.mt}; margin-bottom:${st.mb}; font-weight:${st.fw}; font-style:${st.fst}; display:block; white-space: pre-wrap; tab-size: 4; -moz-tab-size: 4;`;

            // [BUG FIX]: Hapus flexbox, pakai float left untuk memecah teks
            if(txt.includes(' [R] ')) {
                let parts = txt.split(' [R] ');
                html += `<div class="preview-line" style="${css} text-align:right;"><span style="float:left;">${parts[0]}</span>${parts[1]||''}</div>`;
            } else {
                html += `<div class="preview-line" style="${css}">${txt || '&nbsp;'}</div>`;
            }
        });
        
        // [BUG FIX]: Clearfix di akhir looping agar QR code/logo tidak bocor ke bawah
        html += '<div style="clear:both;"></div>';
        
        previewContainer.innerHTML = html;
    }

    editor.addEventListener('input', update);
    document.querySelectorAll('input, select').forEach(el => el.addEventListener('input', update));
    window.addEventListener('DOMContentLoaded', update);
</script>