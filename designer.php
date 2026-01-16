<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'includes/functions.php';

// Fix Variabel Global
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
    $footer_width = (int)($_POST['footer_width'] ?? 100);
    $global_font_size = (int)($_POST['global_font_size'] ?? 12);
    $global_spacing = (int)($_POST['global_spacing'] ?? 0);

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
            'footer_width' => $footer_width, 
            'footer_path' => $existing_structure['footer_path'] ?? null,
            'font_size' => $global_font_size,
            'spacing_top' => $global_spacing
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
    /* UI STYLES */
    .workspace-wrapper { flex: 1; display: flex; flex-direction: column; overflow: hidden; background: #f1f5f9; padding: 20px; height: calc(100vh - 100px); }
    .designer-grid { display: grid; grid-template-columns: 280px 1fr 380px; gap: 20px; height: 100%; overflow: hidden; }
    
    .panel-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #cbd5e1; display: flex; flex-direction: column; overflow: hidden; position: relative; }
    
    /* Tabs - No Focus Steal on Click */
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

    /* Custom Dropdown */
    .custom-select-wrapper { position: relative; width: 100%; user-select: none; }
    .custom-select-trigger {
        display: flex; justify-content: space-between; align-items: center;
        width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
        background: #f8fafc; font-size: 0.85rem; color: #334155; cursor: pointer;
        box-sizing: border-box;
    }
    .custom-select-trigger:hover { background: #fff; border-color:#94a3b8; }
    .custom-options {
        position: absolute; top: 100%; left: 0; right: 0;
        background: #fff; border: 1px solid #cbd5e1; border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100;
        display: none; max-height: 200px; overflow-y: auto;
    }
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
    .tool-btn, .icon-btn, .panel-tab-btn, .custom-select-trigger, .custom-option { user-select: none; }

    #editor { flex: 1; width: 100%; padding: 25px; border: none; font-family: 'Consolas', monospace; font-size: 13px; line-height: 1.6; resize: none; outline: none; color: #334155; white-space: pre; overflow-x: auto; }

    .col-preview { background: #e2e8f0; padding: 30px; display: block; overflow-y: auto; text-align: center; }
    
    /* === PERBAIKAN CSS PREVIEW === */
    .preview-paper { 
        background: white; 
        /* Lebar akan di-handle via JS menggunakan satuan mm */
        display: inline-block; 
        text-align: left; 
        min-height: 200px; 
        /* Samakan padding dengan print-preview.php (5mm) */
        padding: 5mm; 
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2); 
        margin-bottom: 50px; 
        /* Pastikan padding tidak melebarkan kertas */
        box-sizing: border-box; 
        
        font-family: 'Consolas', monospace; 
        /* Default size awal */
        font-size: 12px; 
        color: #000;
        white-space: pre-wrap; 
        word-wrap: break-word; 
        transition: width 0.3s;
        
        /* Opsional: Efek zoom agar di monitor terlihat jelas seperti kertas asli */
        transform-origin: top center;
    }

    .preview-line { position: relative; display: block; width: 100%; min-height: 1em; }
    .split-line { display: flex; justify-content: space-between; }

    .cs-overlay {
        position: absolute; top: 55px; left: 15px; width: 500px; 
        background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; 
        box-shadow: 0 20px 40px -5px rgba(0,0,0,0.15); 
        z-index: 999; display: none; flex-direction: column;
    }
    .cs-overlay.active { display: flex; animation: fadeIn 0.2s; }
    .cs-header { padding: 10px 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; font-weight:bold; color:#475569;}
    .cs-body { padding: 15px; max-height: 300px; overflow-y: auto; }
    .guide-tbl { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
    .guide-tbl td { padding: 6px 0; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .pill { background: #eff6ff; color: #2563eb; padding: 2px 6px; border-radius: 4px; font-family: monospace; border: 1px solid #dbeafe; font-size: 0.75rem; font-weight: 600; display: inline-block; margin-right:5px; }

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
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Logo (px)</label><div class="input-icon-group"><i class="fas fa-ruler-horizontal input-icon"></i><input type="number" name="logo_width" id="inputLogoWidth" value="<?= $s['logo_width'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Upload</label><input type="file" name="logo" accept="image/*" class="form-control" style="padding:6px; font-size:0.7rem;"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">QR (px)</label><div class="input-icon-group"><i class="fas fa-ruler-horizontal input-icon"></i><input type="number" name="footer_width" id="inputFooterWidth" value="<?= $s['footer_width'] ?? 100 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Upload</label><input type="file" name="footer_logo" accept="image/*" class="form-control" style="padding:6px; font-size:0.7rem;"></div>
                </div>
                <div class="prop-row">
                    <div class="prop-col"><label class="form-label">Default Size</label><div class="input-icon-group"><i class="fas fa-text-height input-icon"></i><input type="number" name="global_font_size" id="globalFontSize" value="<?= $s['font_size'] ?? 12 ?>" class="form-control input-with-icon"></div></div>
                    <div class="prop-col"><label class="form-label">Padding Top</label><div class="input-icon-group"><i class="fas fa-arrow-down input-icon"></i><input type="number" name="global_spacing" id="globalSpacing" value="<?= $s['spacing_top'] ?? 0 ?>" class="form-control input-with-icon"></div></div>
                </div>
                <div style="flex:1;"></div>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> SIMPAN DESAIN</button>
            </div>

            <div class="panel-content" id="tab-char">
                <div class="form-group">
                    <label class="form-label">Font Family</label>
                    <div class="custom-select-wrapper">
                        <div class="custom-select-trigger js-prevent-focus" id="fontTrigger">
                            <span id="fontDisplay">Consolas</span><i class="fas fa-chevron-down" style="font-size:0.7rem;"></i>
                        </div>
                        <div class="custom-options" id="fontOptions">
                            <div class="custom-option js-prevent-focus" data-val="Consolas">Consolas</div>
                            <div class="custom-option js-prevent-focus" data-val="Arial">Arial</div>
                            <div class="custom-option js-prevent-focus" data-val="Courier New">Courier New</div>
                            <div class="custom-option js-prevent-focus" data-val="Tahoma">Tahoma</div>
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
            </div>
        </aside>

        <main class="panel-card col-editor">
            <div class="editor-toolbar">
                <button type="button" class="tool-btn btn-text js-prevent-focus" onmousedown="event.preventDefault(); toggleCheatSheet()" style="background:#eff6ff; color:#2563eb; border-color:#bfdbfe;"><i class="fas fa-book"></i> Kamus Kode</button>
                <div style="border-left:1px solid #e2e8f0; height:20px; margin:0 5px;"></div>
                <button type="button" class="tool-btn js-prevent-focus" onmousedown="event.preventDefault(); insertText('[LOGO]')" title="Logo"><i class="far fa-image"></i> Logo</button>
                <button type="button" class="tool-btn js-prevent-focus" onmousedown="event.preventDefault(); insertText('[QR]')" title="QR"><i class="fas fa-qrcode"></i> QR</button>
            </div>
            
            <textarea name="custom_template" id="editor" placeholder="Ketik struktur struk..." spellcheck="false"><?= htmlspecialchars($s['content'] ?? '') ?></textarea>
            
            <div id="cheatSheet" class="cs-overlay">
                <div class="cs-header"><span><i class="fas fa-code"></i> Referensi Kode</span><button type="button" class="js-prevent-focus" onmousedown="event.preventDefault(); toggleCheatSheet()" style="background:none;border:none;cursor:pointer;color:#64748b;"><i class="fas fa-times"></i></button></div>
                <div class="cs-body">
                    <table class="guide-tbl">
                        <tr><td><span class="pill">{{var}}</span> Input</td><td><span class="pill">{{^var}}</span> Kapital</td></tr>
                        <tr><td><span class="pill">{{$$var}}</span> Rupiah</td><td><span class="pill">{{@t}}</span> Tanggal</td></tr>
                    </table>
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
    
    const inputLogoWidth = document.getElementById('inputLogoWidth');
    const inputFooterWidth = document.getElementById('inputFooterWidth');
    const globalFontSize = document.getElementById('globalFontSize');
    const globalSpacing = document.getElementById('globalSpacing');

    // === MEMORY STATE: THE CRITICAL FIX ===
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

    // === ATTACH LISTENERS TO INPUTS (MANUAL) ===
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

    // === DROPDOWN LOGIC ===
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

    // === UI SWITCH ===
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

    // === ENGINE: BLOCK PROCESSOR ===
    function applyBlockTag(key, val = null, fromInput = false) {
        let start = lastSel.start;
        let end = lastSel.end;
        const wasSelection = (start !== end);
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
            if (wasSelection) {
                lastSel = { start: rangeStart, end: newEndPos };
            } else {
                lastSel = { start: newEndPos, end: newEndPos };
            }
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

    // === PREVIEW ENGINE (UPDATED WYSIWYG) ===
    function update() {
        // 1. GUNAKAN SATUAN MM (Sesuai fisik kertas)
        const w = paperSizeSelect.value === '58mm' ? '58mm' : '80mm';
        
        // 2. GUNAKAN SATUAN PX UNTUK FONT (Agar sama dengan print-preview.php)
        const gSize = (globalFontSize.value || 12) + 'px';
        
        const gTop = (globalSpacing.value || 0) + 'px';
        const lW = (inputLogoWidth.value || 100) + 'px';
        const fW = (inputFooterWidth.value || 100) + 'px';

        previewContainer.style.width = w;
        
        // Padding top disesuaikan, ditambah padding standar 5mm
        previewContainer.style.paddingTop = `calc(5mm + ${gTop})`;

        const lines = editor.value.split('\n');
        let html = '';

        lines.forEach(line => {
            let txt = line;
            let st = {
                fs: gSize, 
                lh: '1.2', 
                tr: '0px', 
                scale: '1', 
                base: '0px', 
                align: 'left', 
                pl: '0px', 
                pr: '0px', 
                mt: '0px', 
                mb: '0px',
                fw: 'normal', 
                fst: 'normal', 
                font: 'Consolas'
            };

            // Parsers Tag
            let m;
            m = txt.match(/\[F:([^\]]+)\]\s*/); if(m){ st.font = m[1]; txt = txt.replace(m[0],''); }
            // [UPDATE] Ubah parser size [S:xx] agar menggunakan PX jika tidak ada satuan
            m = txt.match(/\[S:([\d\.]+)\]\s*/); if(m){ st.fs = m[1]+'px'; txt = txt.replace(m[0],''); }
            
            m = txt.match(/\[LH:([\d\.]+)\]\s*/); if(m){ st.lh = (parseFloat(m[1])/100); txt = txt.replace(m[0],''); }
            m = txt.match(/\[TR:([\d\-\.]+)\]\s*/); if(m){ st.tr = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[HS:([\d\.]+)\]\s*/); if(m){ st.scale = (parseFloat(m[1])/100); txt = txt.replace(m[0],''); }
            m = txt.match(/\[BS:([\d\-\.]+)\]\s*/); if(m){ st.base = (-1 * parseFloat(m[1]))+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[IL:(\d+)\]\s*/); if(m){ st.pl = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[IR:(\d+)\]\s*/); if(m){ st.pr = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[MT:(\d+)\]\s*/); if(m){ st.mt = m[1]+'px'; txt = txt.replace(m[0],''); }
            m = txt.match(/\[MB:(\d+)\]\s*/); if(m){ st.mb = m[1]+'px'; txt = txt.replace(m[0],''); }
            
            // Alignment Tags
            if(txt.includes('[C]')) { st.align = 'center'; txt = txt.replace(/\[C\]\s*/,''); }
            else if(txt.includes('[R]') && !txt.includes(' [R] ')) { st.align = 'right'; txt = txt.replace(/\[R\]\s*/,''); }
            else if(txt.includes('[J]')) { st.align = 'justify'; txt = txt.replace(/\[J\]\s*/,''); }
            else if(txt.includes('[L]')) { st.align = 'left'; txt = txt.replace(/\[L\]\s*/,''); }
            
            // Style Tags
            if(txt.includes('[B]')) { st.fw = 'bold'; txt = txt.replace(/\[B\]\s*/,''); }
            if(txt.includes('[I]')) { st.fst = 'italic'; txt = txt.replace(/\[I\]\s*/,''); }

            // Placeholder Data
            txt = txt.replace(/\{\{.*?\}\}/g, "DATA");

            let css = `font-family:'${st.font}', monospace; font-size:${st.fs}; line-height:${st.lh}; letter-spacing:${st.tr}; transform:scale(${st.scale},1); transform-origin:left; position:relative; top:${st.base}; text-align:${st.align}; padding-left:${st.pl}; padding-right:${st.pr}; margin-top:${st.mt}; margin-bottom:${st.mb}; font-weight:${st.fw}; font-style:${st.fst}; display:block; white-space: pre-wrap;`;

            // Render HTML
            if(txt.includes(' [R] ')) {
                let parts = txt.split(' [R] ');
                // Tambahkan width:100% agar split mentok kanan kiri kertas
                html += `<div class="preview-line split-line" style="${css} display:flex; justify-content:space-between; width:100%;"><span>${parts[0]}</span><span>${parts[1]||''}</span></div>`;
            } else if(txt.includes('[LOGO]')) {
                html += `<div style="text-align:${st.align}; margin-bottom:${st.mb};"><div style="width:${lW}; height:50px; background:#e2e8f0; border:1px dashed #94a3b8; display:inline-flex; align-items:center; justify-content:center; font-size:10px; color:#64748b;">LOGO</div></div>`;
            } else if(txt.includes('[QR]')) {
                html += `<div style="text-align:${st.align}; margin-bottom:${st.mb};"><div style="width:${fW}; height:${fW}; background:#e2e8f0; border:1px dashed #94a3b8; display:inline-flex; align-items:center; justify-content:center; font-size:10px; color:#64748b;">QR</div></div>`;
            } else {
                html += `<div class="preview-line" style="${css}">${txt || '&nbsp;'}</div>`;
            }
        });

        previewContainer.innerHTML = html;
    }

    editor.addEventListener('input', update);
    document.querySelectorAll('input, select').forEach(el => el.addEventListener('input', update));
    window.addEventListener('DOMContentLoaded', update);
</script>