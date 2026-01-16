<?php
require_once __DIR__ . '/includes/functions.php';

// Matikan warning
error_reporting(0); 

$template = null;
$receipt_data = [];
$loaded_values = [];

// === 1. LOGIC DATA ===
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['template_id'])) {
    $template_id = (int)$_GET['template_id'];
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch();
    if (!$template) die("Template tidak ditemukan!");

    if (!empty($_GET['load_receipt'])) {
        $receipt_id = (int)$_GET['load_receipt'];
        $stmtReceipt = $pdo->prepare("SELECT data FROM receipts WHERE id = ? AND template_id = ?");
        $stmtReceipt->execute([$receipt_id, $template_id]);
        $loaded_json = $stmtReceipt->fetchColumn();
        if ($loaded_json) $loaded_values = json_decode($loaded_json, true);
    }
}

// === 2. LOGIC SIMPAN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt_data = $_POST;
    unset($receipt_data['template_id']);
    $template_id = (int)$_POST['template_id'];
    
    $stmt = $pdo->prepare("INSERT INTO receipts (template_id, data) VALUES (?, ?)");
    $stmt->execute([$template_id, json_encode($receipt_data)]);
    
    $stmt = $pdo->prepare("SELECT * FROM templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch();
    $receipt_data['receipt_id'] = $pdo->lastInsertId();

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $template) {
    // === 3. FORM INPUT MODE ===
    $structure = json_decode($template['structure'], true);
    $content = $structure['content'] ?? '';
    
    // REGEX MASTER
    $pattern = '/\{\{\s*(?<hidden>!)?(?:\s*\[(?<sort>\d+)\])?(?:\s*(?:(?<cur>\${1,2})|(?<hash>\#{1,2})(?<hash_conf>[0-9\|]+(?:[:]\d+[-]\d+)?)?|(?<date>\@{1,3})(?::(?<date_fmt>[dmyDMY]+))?|(?<time>\%{1,4})|(?<patt>\?)(?<patt_conf>[a-zA-Z0-9\-\/\.]+)))?(?<case_pre>[\^_\*])?(?:\((?<maxlen>\d+)\))?(?<case_post>[\^_\*])?(?:\[)?(?<name>[a-zA-Z0-9_]+)(?:\])?(?:\s*=\s*(?<formula>.+?))?\s*\}\}/';
    
    preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
    
    $defined_fields = [];
    $generated_defaults = [];
    $original_index = 0;

    foreach ($matches as $m) {
        $name = $m['name'];
        $is_hidden = !empty($m['hidden']);
        $sort_order = !empty($m['sort']) ? (int)$m['sort'] : 9999;
        $max_len = !empty($m['maxlen']) ? (int)$m['maxlen'] : 0;
        
        $casing_char = !empty($m['case_pre']) ? $m['case_pre'] : ($m['case_post'] ?? '');
        $casing_mode = '';
        if ($casing_char === '^') $casing_mode = 'upper';
        if ($casing_char === '_') $casing_mode = 'lower';
        if ($casing_char === '*') $casing_mode = 'proper';

        $formula = $m['formula'] ?? null;
        
        $format_mode = 'default';
        $sub_mode = ''; 
        $picker_format = ''; 
        $use_month_plugin = false;

        if (!empty($m['cur'])) {
            $format_mode = ($m['cur'] === '$') ? 'koma' : 'rupiah';
        }
        
        // [UPDATE] Date Mode Logic
        if (!empty($m['date'])) {
            $format_mode = 'date';
            
            // Default Mapping
            if ($m['date'] === '@') { 
                $sub_mode = 'slash'; // 20/05/2025
                $picker_format = 'd/m/Y'; 
            }
            if ($m['date'] === '@@') { 
                $sub_mode = 'dot'; 
                $picker_format = 'd.m.y'; // [FIXED] 2 DIGIT TAHUN (d.m.y)
            }
            if ($m['date'] === '@@@') { 
                $sub_mode = 'long'; // 20 Mei 2025
                $picker_format = 'j F Y'; 
            }
            
            // Custom Config Override
            if (!empty($m['date_fmt'])) {
                $sub_mode = strtolower($m['date_fmt']); 
                if ($sub_mode === 'my') { $picker_format = 'F Y'; $use_month_plugin = true; }
                else if ($sub_mode === 'dm') $picker_format = 'd F'; 
                else if ($sub_mode === 'm') { $picker_format = 'F'; $use_month_plugin = true; }
                else if ($sub_mode === 'y') { $format_mode = 'year_only'; $max_len = 4; }
                else if ($sub_mode === 'd') { $format_mode = 'day_only'; $max_len = 2; }
                else $picker_format = 'Y-m-d'; 
            }
        }

        if (!empty($m['time'])) {
            $format_mode = 'time';
            if ($m['time'] === '%') $sub_mode = 'short_colon';
            if ($m['time'] === '%%') $sub_mode = 'long_colon';
            if ($m['time'] === '%%%') $sub_mode = 'short_dot';
            if ($m['time'] === '%%%%') $sub_mode = 'long_dot';
        }

        if (!empty($m['hash']) && !empty($m['hash_conf']) && empty($formula)) {
             $clean_config = explode(':', $m['hash_conf'])[0];
             $conf = explode('|', $clean_config);
             $total_len = (int)$conf[0];
             if (!isset($generated_defaults[$name])) {
                 $raw_str = '';
                 if ($m['hash'] === '#') {
                     for ($i=0; $i<$total_len; $i++) $raw_str .= mt_rand(0, 9);
                 } else {
                     $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                     $raw_str = substr(str_shuffle(str_repeat($chars, ceil($total_len/strlen($chars)) + 1)), 0, $total_len);
                 }
                 $generated_defaults[$name] = $raw_str;
             }
        }

        if ($format_mode === 'date' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('Y-m-d');
        if ($format_mode === 'year_only' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('Y');
        if ($format_mode === 'day_only' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('d');
        if ($format_mode === 'time' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('H:i:s');

        if (!isset($defined_fields[$name])) {
            $defined_fields[$name] = [
                'name' => $name, 'formula' => $formula, 
                'mode' => $format_mode, 'sub_mode' => $sub_mode,
                'picker_fmt' => $picker_format, 
                'use_month' => $use_month_plugin,
                'is_hidden' => $is_hidden, 
                'sort' => $sort_order,
                'max_len' => $max_len,
                'casing' => $casing_mode,
                'original_index' => $original_index 
            ];
        } else {
            if ($format_mode !== 'default') {
                $defined_fields[$name]['mode'] = $format_mode;
                $defined_fields[$name]['sub_mode'] = $sub_mode;
                $defined_fields[$name]['picker_fmt'] = $picker_format;
                $defined_fields[$name]['use_month'] = $use_month_plugin;
            }
            if ($is_hidden) $defined_fields[$name]['is_hidden'] = true;
            if ($sort_order !== 9999) $defined_fields[$name]['sort'] = $sort_order;
            if ($max_len > 0) $defined_fields[$name]['max_len'] = $max_len;
            if ($casing_mode !== '') $defined_fields[$name]['casing'] = $casing_mode;
            if (!empty($formula)) $defined_fields[$name]['formula'] = $formula;
        }
        $original_index++;
    }

    foreach ($defined_fields as $field) {
        if (!empty($field['formula'])) {
            $tempFormula = $field['formula'];
            if (preg_match_all('/\#{1,2}(?:[0-9:\|\-]+)?\[([a-zA-Z0-9_]+)\]/', $tempFormula, $matches)) {
                foreach($matches[1] as $src) {
                    if (!isset($defined_fields[$src])) $defined_fields[$src] = ['name' => $src, 'formula'=>null, 'mode'=>'default', 'sub_mode'=>'', 'is_hidden'=>false, 'sort'=>9999, 'max_len'=>0, 'casing'=>'', 'original_index'=>99999];
                }
                $tempFormula = preg_replace('/\#{1,2}(?:[0-9:\|\-]+)?\[[a-zA-Z0-9_]+\]/', '', $tempFormula);
            }
            if (preg_match_all('/\?([a-zA-Z0-9\-\/\.]+)\[([a-zA-Z0-9_]+)\]/', $tempFormula, $matches)) {
                foreach($matches[2] as $src) {
                    if (!isset($defined_fields[$src])) $defined_fields[$src] = ['name' => $src, 'formula'=>null, 'mode'=>'default', 'sub_mode'=>'', 'is_hidden'=>false, 'sort'=>9999, 'max_len'=>0, 'casing'=>'', 'original_index'=>99999];
                }
                $tempFormula = preg_replace('/\?[a-zA-Z0-9\-\/\.]+\[[a-zA-Z0-9_]+\]/', '', $tempFormula);
            }
            preg_match_all('/[a-zA-Z0-9_]+/', $tempFormula, $vars);
            foreach ($vars[0] as $var) {
                if (in_array($var, ['clean', 'koma', 'titik', 'Math']) || is_numeric($var)) continue;
                if (!isset($defined_fields[$var])) $defined_fields[$var] = ['name' => $var, 'formula'=>null, 'mode'=>'default', 'sub_mode'=>'', 'is_hidden'=>false, 'sort'=>9999, 'max_len'=>0, 'casing'=>'', 'original_index'=>99999];
            }
        }
    }
    
    $fields = array_values($defined_fields); 
    usort($fields, function($a, $b) {
        if ($a['sort'] === $b['sort']) return $a['original_index'] <=> $b['original_index'];
        return $a['sort'] <=> $b['sort'];
    });

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Input Data</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/material_blue.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/style.css">
        <style>
            body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f4f6f9; display: flex; justify-content: center; min-height: 100vh; align-items: center; }
            .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); width: 100%; max-width: 480px; }
            .form-group { margin: 18px 0; position: relative; }
            .form-group.hidden { display: none; } 
            .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color:#444; letter-spacing: 0.5px; }
            .form-group input, .form-group textarea { 
                width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; 
                box-sizing: border-box; font-size: 14px; transition: border 0.2s;
                background-color: #fff; color: #333; font-family: inherit;
            }
            .form-group input:focus, .form-group textarea:focus { border-color: #007bff; outline: none; }
            .form-group input:read-only, .form-group textarea:read-only { background-color: #f1f3f5; color: #6c757d; font-weight: bold; cursor: not-allowed; }
            .form-group input.date-picker-modern, .form-group input.time-picker-modern { cursor: pointer; background-color: #fff !important; }
            .btn { width: 100%; padding: 14px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight:bold; margin-top: 10px; transition: background 0.2s; }
            .btn:hover { background: #0056b3; }
            .alert { background: #d1e7dd; color: #0f5132; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #badbcc; }
            .btn-cancel { display:block; text-align:center; margin-top:15px; text-decoration:none; color:#6c757d; font-size:14px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2 style="text-align:center; margin-top:0; color:#333;">📝 Isi Data Struk</h2>
            <?php if (!empty($loaded_values)): ?><div class="alert">✅ Data lama berhasil dimuat.</div><?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                <?php foreach ($fields as $field): ?>
                    <?php 
                        if (isset($generated_defaults[$field['name']])) {
                            $val = $generated_defaults[$field['name']];
                        } else {
                            $val = $loaded_values[$field['name']] ?? '';
                        }
                        
                        $is_calc = !empty($field['formula']);
                        $use_textarea = strlen($val) > 40; 
                        
                        $input_class = $is_calc ? 'calc-target' : 'calc-source';
                        if ($field['mode'] === 'koma') $input_class .= ' mode-koma';
                        if ($field['mode'] === 'rupiah') $input_class .= ' mode-rupiah';
                        if ($field['mode'] === 'time') $input_class .= ' time-picker-modern';
                        if ($field['mode'] === 'date') $input_class .= ' date-picker-modern'; 
                        
                        $label = strtoupper(str_replace('_', ' ', $field['name']));
                        
                        $badge = '';
                        if ($is_calc) $badge .= '⚡ ';
                        if ($field['mode'] === 'rupiah') $badge .= '(Rp)';
                        if ($field['mode'] === 'koma') $badge .= '(0,000)';
                        if ($field['mode'] === 'date') $badge .= '📅 '; 
                        if ($field['mode'] === 'year_only') $badge .= '📆 '; 
                        if ($field['mode'] === 'time') $badge .= '⏰ '; 
                        if ($field['casing'] === 'upper') $badge .= '🔠 ';
                        if ($field['casing'] === 'lower') $badge .= '🔡 ';
                        if ($field['casing'] === 'proper') $badge .= 'Aa ';

                        if (isset($generated_defaults[$field['name']]) && !in_array($field['mode'], ['date','time','year_only','day_only'])) $badge .= '🎲 ';
                        
                        $inputType = 'text';
                        if ($field['mode'] === 'year_only' || $field['mode'] === 'day_only') $inputType = 'number';
                        
                        $enableSeconds = (strpos($field['sub_mode'], 'long') !== false) ? 'true' : 'false';
                        $isHidden = $field['is_hidden'];
                        $wrapperClass = $isHidden ? 'form-group hidden' : 'form-group';
                        $maxLen = $field['max_len'] > 0 ? $field['max_len'] : '';
                    ?>
                    
                    <div class="<?= $wrapperClass ?>">
                        <?php if(!$isHidden): ?>
                            <label><?= $label ?> <small style="color:#888;"><?= $badge ?></small></label>
                        <?php endif; ?>

                        <?php if($use_textarea && $inputType === 'text' && !$isHidden): ?>
                            <textarea name="<?= $field['name'] ?>" rows="2"
                                      <?= $maxLen ? "maxlength='$maxLen'" : '' ?>
                                      <?= $is_calc || (isset($generated_defaults[$field['name']]) && !in_array($field['mode'], ['date','time'])) ? 'readonly' : '' ?>
                                      class="<?= $input_class ?>"
                                      data-format-mode="<?= $field['mode'] ?>"
                                      data-sub-mode="<?= $field['sub_mode'] ?>"
                                      data-casing="<?= $field['casing'] ?>"
                                      data-formula="<?= htmlspecialchars($field['formula'] ?? '') ?>"><?= htmlspecialchars($val) ?></textarea>
                        <?php else: ?>
                            <input type="<?= $inputType ?>" 
                                   name="<?= $field['name'] ?>" 
                                   value="<?= htmlspecialchars($val) ?>"
                                   <?= $maxLen ? "maxlength='$maxLen'" : '' ?>
                                   placeholder="<?= $is_calc ? 'Otomatis...' : 'Isi data' ?>"
                                   <?= (!$isHidden && ($is_calc || (isset($generated_defaults[$field['name']]) && !in_array($field['mode'], ['date','time','year_only','day_only'])))) ? 'readonly' : '' ?>
                                   class="<?= $input_class ?>"
                                   data-format-mode="<?= $field['mode'] ?>"
                                   data-sub-mode="<?= $field['sub_mode'] ?>"
                                   data-picker-fmt="<?= $field['picker_fmt'] ?? '' ?>"
                                   data-use-month="<?= $field['use_month'] ? 'true' : 'false' ?>"
                                   data-enable-seconds="<?= $enableSeconds ?>"
                                   data-casing="<?= $field['casing'] ?>"
                                   data-formula="<?= htmlspecialchars($field['formula'] ?? '') ?>"
                                   autocomplete="off">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn">🚀 Lanjut Cetak</button>
            </form>
            <a href="saved-receipts.php?template_id=<?= $template['id'] ?>" class="btn-cancel">Batal / Kembali ke Riwayat</a>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/index.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/blueimp-md5/2.19.0/js/md5.min.js"></script>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                flatpickr.localize(flatpickr.l10ns.id); 

                const timeInputs = document.querySelectorAll('.time-picker-modern');
                timeInputs.forEach(input => {
                    const showSeconds = input.dataset.enableSeconds === 'true';
                    flatpickr(input, {
                        enableTime: true, noCalendar: true, dateFormat: showSeconds ? "H:i:S" : "H:i",
                        time_24hr: true, enableSeconds: showSeconds, minuteIncrement: 1, allowInput: true,
                        onChange: function() { calculateAll(); }
                    });
                });

                const dateInputs = document.querySelectorAll('.date-picker-modern');
                dateInputs.forEach(input => {
                    const fmt = input.dataset.pickerFmt || 'Y-m-d';
                    const useMonth = input.dataset.useMonth === 'true';
                    let config = { dateFormat: fmt, allowInput: true, onChange: function() { calculateAll(); } };
                    if (useMonth) {
                        config.plugins = [ new monthSelectPlugin({ shorthand: true, dateFormat: fmt, altFormat: fmt, theme: "light" }) ];
                    }
                    if (!input.value) { input.value = flatpickr.formatDate(new Date(), fmt); }
                    flatpickr(input, config);
                });
            });

            // ... (Fungsi JS Lainnya)
            function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt){return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();}); }
            function applyCasing(e) {
                let target = e.target; let casing = target.dataset.casing; if (!casing) return;
                let start = target.selectionStart; let end = target.selectionEnd;
                let original = target.value; let converted = original;
                if (casing === 'upper') converted = original.toUpperCase();
                else if (casing === 'lower') converted = original.toLowerCase();
                else if (casing === 'proper') converted = toTitleCase(original);
                if (original !== converted) { target.value = converted; try { if(target.type === 'text' || target.tagName === 'TEXTAREA') target.setSelectionRange(start, end); } catch(err) {} }
            }
            function generateHash(sourceValue, length, min = 0, max = 0) {
                if (!sourceValue) return ''; sourceValue = sourceValue.toUpperCase();
                let hash = 0; for (let i = 0; i < sourceValue.length; i++) { hash = ((hash << 5) - hash) + sourceValue.charCodeAt(i); hash = hash & hash; }
                let seedInt = Math.abs(hash);
                if (max > 0) { let range = max - min + 1; let resultInt = (seedInt % range) + min; return resultInt.toString().padStart(length, '0'); }
                let seedStr = seedInt.toString(); while(seedStr.length < length) seedStr += Math.abs(hash * seedStr.length).toString(); return seedStr.substring(0, length);
            }
            function generateAlphaHash(sourceValue, length) {
                if (!sourceValue) return ''; sourceValue = sourceValue.toUpperCase();
                let hex = md5(sourceValue).toUpperCase(); while(hex.length < length) hex += md5(hex).toUpperCase(); return hex.substring(0, length);
            }
            function generatePatternHash(sourceValue, mask) {
                if (!sourceValue) return ''; sourceValue = sourceValue.toUpperCase();
                let seed = md5(sourceValue); while(seed.length < mask.length * 2) { seed += md5(seed); }
                let result = ''; let seedIdx = 0;
                for (let i = 0; i < mask.length; i++) {
                    let char = mask[i]; let hexChunk = seed.substr(seedIdx, 2); let numVal = parseInt(hexChunk, 16); seedIdx += 2;
                    if (char === '0') result += (numVal % 10).toString(); else if (char === 'A') result += String.fromCharCode(65 + (numVal % 26)); else { result += char; seedIdx -= 2; }
                } return result;
            }
            function formatInputLive(e) {
                applyCasing(e);
                let target = e.target;
                if (!target.classList.contains('format-currency') && !target.classList.contains('mode-rupiah') && !target.classList.contains('mode-koma')) return;
                let val = target.value; let cleanVal = val.replace(/\D/g, ''); 
                if (cleanVal === '') { target.value = ''; return; }
                if (target.classList.contains('mode-rupiah')) target.value = 'Rp. ' + new Intl.NumberFormat('id-ID').format(cleanVal);
                else if (target.classList.contains('mode-koma')) target.value = new Intl.NumberFormat('en-US').format(cleanVal);
            }
            function parseLocaleNumber(stringNumber) { if (!stringNumber) return 0; let cleaned = stringNumber.toString().replace(/[^0-9-]/g, ''); return parseFloat(cleaned) || 0; }
            function formatRupiah(number) { return 'Rp. ' + new Intl.NumberFormat('id-ID').format(number); }
            function formatTime(timeStr, mode) {
                if (!timeStr || !timeStr.includes(':')) return timeStr;
                let parts = timeStr.split(':'); if (parts.length < 2) return timeStr;
                let H = parts[0]; let i = parts[1]; let s = parts[2] || '00';
                if (mode === 'short_colon') return `${H}:${i}`;
                if (mode === 'long_colon') return `${H}:${i}:${s}`;
                if (mode === 'short_dot') return `${H}.${i}`;
                if (mode === 'long_dot') return `${H}.${i}.${s}`;
                return timeStr;
            }
            function parseCustomDate(str) {
                if(!str) return null;
                let parts = str.match(/(\d{1,2})[\/\.\-](\d{1,2})[\/\.\-](\d{4})/);
                if(parts) { return new Date(parts[3], parts[2]-1, parts[1]); }
                let d = new Date(str); if(!isNaN(d.getTime())) return d;
                return null;
            }
            function formatDate(dateStr, mode) {
                if (!dateStr) return '';
                let d = parseCustomDate(dateStr); if (!d) return dateStr; 
                let day = ('0' + d.getDate()).slice(-2);
                let month = ('0' + (d.getMonth() + 1)).slice(-2);
                let year = d.getFullYear();
                let shortYear = year.toString().substr(-2);
                const months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
                let monthName = months[d.getMonth()];

                if (mode === 'slash') return `${day}/${month}/${year}`;
                if (mode === 'dot') return `${day}.${month}.${shortYear}`; // [FIXED] 2 DIGIT
                if (mode === 'long') return `${d.getDate()} ${monthName} ${year}`;
                if (mode === 'my') return `${monthName} ${year}`;
                if (mode === 'dm') return `${d.getDate()} ${monthName}`;
                if (mode === 'm') return monthName;
                if (mode === 'y') return year.toString();
                if (mode === 'd') return d.getDate().toString();
                return dateStr;
            }

            function calculateAll() {
                const targets = document.querySelectorAll('.calc-target');
                targets.forEach(target => {
                    let formula = target.dataset.formula;
                    if(!formula) return;
                    let formatMode = target.dataset.formatMode; 
                    let subMode = target.dataset.subMode; 

                    if (/^[a-zA-Z0-9_]+$/.test(formula)) {
                        let sourceInput = document.querySelector(`input[name="${formula}"], textarea[name="${formula}"]`);
                        if (sourceInput) {
                            let rawVal = sourceInput.value;
                            if (formatMode === 'date') target.value = formatDate(rawVal, subMode);
                            else if (formatMode === 'time') target.value = formatTime(rawVal, subMode);
                            else target.value = rawVal;
                        }
                        return; 
                    }
                    let pattMatch = formula.match(/^\?([a-zA-Z0-9\-\/\.]+)\[([a-zA-Z0-9_]+)\]$/);
                    if (pattMatch) {
                        let mask = pattMatch[1]; let sourceName = pattMatch[2];
                        let sourceInput = document.querySelector(`input[name="${sourceName}"], textarea[name="${sourceName}"]`);
                        if (sourceInput) { let sourceVal = sourceInput.value; target.value = generatePatternHash(sourceVal, mask); }
                        return;
                    }
                    let hashMatch = formula.match(/^(\#{1,2})(\d+)(?::(\d+)-(\d+))?\[([a-zA-Z0-9_]+)\]$/);
                    if (hashMatch) {
                        let type = hashMatch[1]; let length = parseInt(hashMatch[2]);
                        let min = hashMatch[3] ? parseInt(hashMatch[3]) : 0; let max = hashMatch[4] ? parseInt(hashMatch[4]) : 0;
                        let sourceName = hashMatch[5];
                        let sourceInput = document.querySelector(`input[name="${sourceName}"], textarea[name="${sourceName}"]`);
                        if (sourceInput) {
                            let sourceVal = sourceInput.value;
                            if (type === '##') target.value = generateAlphaHash(sourceVal, length);
                            else target.value = generateHash(sourceVal, length, min, max);
                        }
                        return; 
                    }
                    let cleanFormula = formula.replace(/\$/g, '');
                    let parsedFormula = cleanFormula.replace(/[a-zA-Z0-9_]+/g, (match) => {
                        if (['clean', 'koma', 'titik', 'Math'].includes(match)) return match;
                        let sourceInput = document.querySelector(`input[name="${match}"], textarea[name="${match}"]`);
                        return sourceInput ? parseLocaleNumber(sourceInput.value) : 0;
                    });
                    try {
                        let result = eval(parsedFormula);
                        if (result === undefined || isNaN(result)) { target.value = (typeof result === 'string') ? result : ''; } 
                        else if (typeof result === 'number') {
                            if (target.classList.contains('mode-rupiah')) target.value = formatRupiah(result);
                            else if (target.classList.contains('mode-koma')) target.value = new Intl.NumberFormat('en-US').format(result);
                            else target.value = result; 
                        } else { target.value = result; }
                    } catch (e) { }
                });
            }

            document.querySelectorAll('input, textarea').forEach(el => {
                el.addEventListener('input', function(e) { formatInputLive(e); calculateAll(); });
                el.addEventListener('change', function(e) { calculateAll(); });
            });
            setTimeout(calculateAll, 100);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// Render Mode (Updated 2-digit Year Logic in PHP as well)
$structure = json_decode($template['structure'], true, 512, JSON_THROW_ON_ERROR);
$content = $structure['content'] ?? '';
$logo_width = $structure['logo_width'] ?? 100;
$footer_width = $structure['footer_width'] ?? 100;
$global_font_size = $structure['font_size'] ?? 12;
$font_family = $structure['font_family'] ?? 'Consolas';
$spacing_top = $structure['spacing_top'] ?? 0;
$spacing_bottom = $structure['spacing_bottom'] ?? 0;

$pattern = '/\{\{\s*(?<hidden>!)?(?:\s*\[(?<sort>\d+)\])?(?:\s*(?:(?<cur>\${1,2})|(?<hash>\#{1,2})(?<hash_conf>[0-9\|]+(?:[:]\d+[-]\d+)?)?|(?<date>\@{1,3})(?::(?<date_fmt>[dmyDMY]+))?|(?<time>\%{1,4})|(?<patt>\?)(?<patt_conf>[a-zA-Z0-9\-\/\.]+)))?(?<case_pre>[\^_\*])?(?:\((?<maxlen>\d+)\))?(?<case_post>[\^_\*])?(?:\[)?(?<name>[a-zA-Z0-9_]+)(?:\])?(?:\s*=\s*(?<formula>.+?))?\s*\}\}/';

$content = preg_replace_callback(
    $pattern,
    function($matches) use ($receipt_data) {
        $key = $matches['name'] ?? '';
        $val = $receipt_data[$key] ?? '';

        $casing_char = !empty($matches['case_pre']) ? $matches['case_pre'] : ($matches['case_post'] ?? '');
        if ($casing_char === '^') $val = strtoupper($val);
        if ($casing_char === '_') $val = strtolower($val);
        if ($casing_char === '*') $val = ucwords(strtolower($val));

        if (!empty($matches['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            $ts = strtotime($val);
            $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            
            if ($matches['date'] === '@') return date('d/m/Y', $ts);
            if ($matches['date'] === '@@') return date('d.m.y', $ts); // [FIXED] 2-digit Year PHP
            if ($matches['date'] === '@@@') return date('d', $ts) . ' ' . $bulan[(int)date('m', $ts)] . ' ' . date('Y', $ts);
            
            if (!empty($matches['date_fmt'])) {
                $fmt = strtolower($matches['date_fmt']);
                if ($fmt === 'my') return $bulan[(int)date('m', $ts)] . ' ' . date('Y', $ts);
                if ($fmt === 'dm') return date('d', $ts) . ' ' . $bulan[(int)date('m', $ts)];
                if ($fmt === 'm') return $bulan[(int)date('m', $ts)];
                if ($fmt === 'y') return date('Y', $ts);
                if ($fmt === 'd') return date('d', $ts);
            }
        }
        
        if (!empty($matches['time'])) {
            $parts = explode(':', $val);
            if (count($parts) >= 2) {
                $H = $parts[0]; $i = $parts[1]; $s = $parts[2] ?? '00';
                if ($matches['time'] === '%') return "$H:$i"; 
                if ($matches['time'] === '%%') return "$H:$i:$s"; 
                if ($matches['time'] === '%%%') return "$H.$i";
                if ($matches['time'] === '%%%%') return "$H.$i.$s";
            }
        }

        $cleanVal = preg_replace('/[^0-9\-]/', '', $val);
        if (is_numeric($cleanVal) && $cleanVal !== '') {
            $num = (float)$cleanVal;
            if (!empty($matches['cur'])) {
                if ($matches['cur'] === '$$') return 'Rp. ' . number_format($num, 0, ',', '.');
                if ($matches['cur'] === '$') return number_format($num, 0, '.', ',');
            }
        }

        if (!empty($matches['hash_conf']) && strpos($matches['hash_conf'], '|') !== false) {
            $clean_conf = explode(':', $matches['hash_conf'])[0];
            $conf = explode('|', $clean_conf);
            $chunk_len = isset($conf[1]) ? (int)$conf[1] : 0;
            $indent_len = isset($conf[2]) ? (int)$conf[2] : 0;
            if ($chunk_len > 0) {
                $parts = str_split($val, $chunk_len);
                $formatted = array_shift($parts);
                foreach ($parts as $p) {
                    $formatted .= "\n" . str_repeat(" ", $indent_len) . $p;
                }
                return $formatted;
            }
        }
        
        return $val;
    },
    $content
);

$paper_size = $template['paper_size'] ?? '80mm';
$content_width = ($paper_size === '58mm') ? '48mm' : '72mm';
$logoUrl = (!empty($template['logo_path']) && file_exists($template['logo_path'])) ? 'uploads/' . basename($template['logo_path']) : '';
$footerUrl = (!empty($structure['footer_path']) && file_exists($structure['footer_path'])) ? 'uploads/' . basename($structure['footer_path']) : '';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Struk Pembayaran</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: '<?= $font_family ?>', monospace; font-size: <?= $global_font_size ?>px; line-height: 1.2; color: black; background: white; }
        @media screen { body { background: #555; padding: 40px 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; } .receipt { background: white; width: <?= $paper_size === '58mm' ? '58mm' : '80mm' ?>; padding: 5mm; box-shadow: 0 0 15px rgba(0,0,0,0.5); margin-bottom: 20px; min-height: 100px; } .no-print { text-align: center; margin-top: 20px; } }
        @media print { @page { size: auto; margin: 0; } body * { visibility: hidden; } .receipt, .receipt * { visibility: visible; } html, body { width: 100%; height: 100%; margin: 0 !important; padding: 0 !important; background: white; overflow: hidden; } .receipt { position: absolute; left: 0; top: 0; width: <?= $content_width ?>; padding: 0 2mm; margin: 0; border: none; box-shadow: none; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; } .no-print { display: none !important; } }
        .print-line { display: block; width: 100%; margin-top: <?= $spacing_top ?>px; margin-bottom: <?= $spacing_bottom ?>px; white-space: pre-wrap; word-wrap: break-word; position: relative; z-index: 1; overflow: visible; min-height: 1em; }
        .split-line { display: flex; justify-content: space-between; width: 100%; }
        .text-layer { position: relative; z-index: 2; }
        .img-absolute { position: absolute; top: 0; z-index: 0; opacity: 1; filter: grayscale(100%) contrast(200%); image-rendering: pixelated; }
    </style>
</head>
<body>
    <div class="receipt" id="receiptContent">
        <?php 
        $clean_content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $clean_content);
        while (!empty($lines)) {
            $lastLine = end($lines);
            if (trim($lastLine) === '' && strpos($lastLine, '[LOGO]') === false && strpos($lastLine, '[QR]') === false) array_pop($lines);
            else break;
        }
        foreach ($lines as $line) {
            $finalContent = $line;
            $imgHtml = ''; $textAlign = 'left'; $isSplit = false; $leftText = ''; $rightText = '';
            $lineFontSize = $global_font_size . 'px';
            if (preg_match('/\[S:(\d+)\]/', $finalContent, $matches)) {
                $lineFontSize = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
                $line = $finalContent;
            }
            $generateImg = function($url, $width, $lineStr, $tag) {
                $len = strlen($lineStr);
                $pos = strpos($lineStr, $tag);
                $realLen = ($len > strlen($tag)) ? ($len - strlen($tag)) : 1;
                $percent = ($pos / $realLen) * 100;
                if ($percent < 0) $percent = 0; if ($percent > 100) $percent = 100;
                $translateX = $percent * -1;
                return ['html' => '<img src="' . $url . '" class="img-absolute" style="width:' . $width . 'px; left:' . $percent . '%; transform: translate(' . $translateX . '%, -10%);">', 'clean' => str_replace($tag, '', $lineStr)];
            };
            if (strpos($line, '[LOGO]') !== false && $logoUrl) {
                $res = $generateImg($logoUrl, $logo_width, $line, '[LOGO]');
                $imgHtml .= $res['html']; $finalContent = $res['clean']; $line = $finalContent;
            }
            if (strpos($line, '[QR]') !== false && $footerUrl) {
                $res = $generateImg($footerUrl, $footer_width, $line, '[QR]');
                $imgHtml .= $res['html']; $finalContent = $res['clean']; $line = $finalContent;
            }
            if (strpos($line, '[R]') > 0) {
                $isSplit = true; $parts = explode('[R]', $line); $leftText = $parts[0]; $rightText = $parts[1];
            } elseif (strpos($line, '[C]') === 0) { $textAlign = 'center'; $finalContent = substr($line, 3); }
            elseif (strpos($line, '[R]') === 0) { $textAlign = 'right'; $finalContent = substr($line, 3); }
            elseif (strpos($line, '[J]') === 0) { $textAlign = 'justify'; $finalContent = substr($line, 3); }
            elseif (strpos($line, '[L]') === 0) { $textAlign = 'left'; $finalContent = substr($line, 3); }
            else { $finalContent = $line; }

            if (trim($finalContent) === '' && $imgHtml === '' && !$isSplit) {
                echo '<div class="print-line" style="font-size:'.$lineFontSize.';">&nbsp;</div>';
            } else {
                if ($isSplit) echo '<div class="print-line split-line" style="font-size:'.$lineFontSize.';"><span>'.$leftText.'</span><span>'.$rightText.'</span>'.$imgHtml.'</div>';
                else echo '<div class="print-line" style="text-align:'.$textAlign.'; font-size:'.$lineFontSize.';">'.$imgHtml.'<span class="text-layer">'.$finalContent.'</span></div>';
            }
        }
        ?>
        <div style="height: 3mm;"></div>
    </div>
    <div class="no-print">
        <button onclick="window.print()" style="padding:12px 25px; font-size:16px; background:#28a745; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:bold;">🖨️ CETAK STRUK</button>
        <br><br>
        <a href="index.php" style="color:white; text-decoration:underline;">« Kembali</a>
    </div>
</body>
</html>