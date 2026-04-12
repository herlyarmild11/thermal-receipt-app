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
        $sub_mode = ''; $picker_format = ''; $use_month_plugin = false;

        if (!empty($m['cur'])) { $format_mode = ($m['cur'] === '$') ? 'koma' : 'rupiah'; }
        if (!empty($m['date'])) {
            $format_mode = 'date';
            if ($m['date'] === '@') { $sub_mode = 'slash'; $picker_format = 'd/m/Y'; }
            if ($m['date'] === '@@') { $sub_mode = 'dot'; $picker_format = 'd.m.y'; }
            if ($m['date'] === '@@@') { $sub_mode = 'long'; $picker_format = 'j F Y'; }
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
                 if ($m['hash'] === '#') { for ($i=0; $i<$total_len; $i++) $raw_str .= mt_rand(0, 9); } 
                 else { $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; $raw_str = substr(str_shuffle(str_repeat($chars, ceil($total_len/strlen($chars)) + 1)), 0, $total_len); }
                 $generated_defaults[$name] = $raw_str;
             }
        }
        if ($format_mode === 'date' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('Y-m-d');
        if ($format_mode === 'year_only' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('Y');
        if ($format_mode === 'day_only' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('d');
        if ($format_mode === 'time' && !isset($loaded_values[$name])) $generated_defaults[$name] = date('H:i:s');

        if (!isset($defined_fields[$name])) {
            $defined_fields[$name] = [
                'name' => $name, 'formula' => $formula, 'mode' => $format_mode, 'sub_mode' => $sub_mode,
                'picker_fmt' => $picker_format, 'use_month' => $use_month_plugin, 'is_hidden' => $is_hidden, 
                'sort' => $sort_order, 'max_len' => $max_len, 'casing' => $casing_mode, 'original_index' => $original_index 
            ];
        } else {
            if ($format_mode !== 'default') { $defined_fields[$name]['mode'] = $format_mode; $defined_fields[$name]['sub_mode'] = $sub_mode; $defined_fields[$name]['picker_fmt'] = $picker_format; $defined_fields[$name]['use_month'] = $use_month_plugin; }
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
                foreach($matches[1] as $src) if (!isset($defined_fields[$src])) $defined_fields[$src] = ['name' => $src, 'formula'=>null, 'mode'=>'default', 'sub_mode'=>'', 'is_hidden'=>false, 'sort'=>9999, 'max_len'=>0, 'casing'=>'', 'original_index'=>99999];
                $tempFormula = preg_replace('/\#{1,2}(?:[0-9:\|\-]+)?\[[a-zA-Z0-9_]+\]/', '', $tempFormula);
            }
            if (preg_match_all('/\?([a-zA-Z0-9\-\/\.]+)\[([a-zA-Z0-9_]+)\]/', $tempFormula, $matches)) {
                foreach($matches[2] as $src) if (!isset($defined_fields[$src])) $defined_fields[$src] = ['name' => $src, 'formula'=>null, 'mode'=>'default', 'sub_mode'=>'', 'is_hidden'=>false, 'sort'=>9999, 'max_len'=>0, 'casing'=>'', 'original_index'=>99999];
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
            .form-group input, .form-group textarea { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 14px; transition: border 0.2s; background-color: #fff; color: #333; font-family: inherit; }
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
                        $val = isset($generated_defaults[$field['name']]) ? $generated_defaults[$field['name']] : ($loaded_values[$field['name']] ?? '');
                        $is_calc = !empty($field['formula']);
                        $use_textarea = strlen($val) > 40; 
                        $input_class = $is_calc ? 'calc-target' : 'calc-source';
                        if ($field['mode'] === 'koma') $input_class .= ' mode-koma';
                        if ($field['mode'] === 'rupiah') $input_class .= ' mode-rupiah';
                        if ($field['mode'] === 'time') $input_class .= ' time-picker-modern';
                        if ($field['mode'] === 'date') $input_class .= ' date-picker-modern'; 
                        $label = strtoupper(str_replace('_', ' ', $field['name']));
                        $inputType = ($field['mode'] === 'year_only' || $field['mode'] === 'day_only') ? 'number' : 'text';
                        $enableSeconds = (strpos($field['sub_mode'], 'long') !== false) ? 'true' : 'false';
                        $isHidden = $field['is_hidden'];
                        $wrapperClass = $isHidden ? 'form-group hidden' : 'form-group';
                        $maxLen = $field['max_len'] > 0 ? $field['max_len'] : '';
                    ?>
                    <div class="<?= $wrapperClass ?>">
                        <?php if(!$isHidden): ?><label><?= $label ?></label><?php endif; ?>
                        <input type="<?= $inputType ?>" name="<?= $field['name'] ?>" value="<?= htmlspecialchars($val) ?>" 
                               <?= ($maxLen > 0) ? 'maxlength="'.$maxLen.'"' : '' ?>
                               <?= ($is_calc || (isset($generated_defaults[$field['name']]) && !in_array($field['mode'], ['date','time']))) ? 'readonly' : '' ?>
                               class="<?= $input_class ?>" data-format-mode="<?= $field['mode'] ?>" data-sub-mode="<?= $field['sub_mode'] ?>" 
                               data-picker-fmt="<?= $field['picker_fmt'] ?? '' ?>" data-use-month="<?= $field['use_month'] ? 'true' : 'false' ?>" 
                               data-enable-seconds="<?= $enableSeconds ?>" data-casing="<?= $field['casing'] ?>" 
                               data-formula="<?= htmlspecialchars($field['formula'] ?? '') ?>" autocomplete="off">
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn">🚀 Lanjut Cetak</button>
            </form>
            <a href="saved-receipts.php?template_id=<?= $template['id'] ?>" class="btn-cancel">Batal</a>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
        <script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/index.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/blueimp-md5/2.19.0/js/md5.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                flatpickr.localize(flatpickr.l10ns.id); 
                document.querySelectorAll('.time-picker-modern').forEach(i => flatpickr(i, {enableTime:true, noCalendar:true, dateFormat: i.dataset.enableSeconds==='true'?"H:i:S":"H:i", time_24hr:true, enableSeconds:i.dataset.enableSeconds==='true', onChange:calculateAll}));
                document.querySelectorAll('.date-picker-modern').forEach(i => {
                    let c = {dateFormat: i.dataset.pickerFmt||'Y-m-d', allowInput:true, onChange:calculateAll};
                    if(i.dataset.useMonth==='true') c.plugins=[new monthSelectPlugin({shorthand:true, dateFormat:c.dateFormat, altFormat:c.dateFormat, theme:"light"})];
                    if(!i.value) i.value=flatpickr.formatDate(new Date(), c.dateFormat);
                    flatpickr(i, c);
                });
                calculateAll();
            });
            function toTitleCase(str) { return str.replace(/\w\S*/g, function(txt){return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase();}); }
            function applyCasing(e) {
                let target = e.target; let casing = target.dataset.casing; if (!casing) return;
                let original = target.value; let converted = original;
                if (casing === 'upper') converted = original.toUpperCase();
                else if (casing === 'lower') converted = original.toLowerCase();
                else if (casing === 'proper') converted = toTitleCase(original);
                if (original !== converted) target.value = converted;
            }
            function generatePatternHash(s, m) { if(!s)return''; s=s.toUpperCase(); let seed=md5(s); while(seed.length<m.length*2)seed+=md5(seed); let res='', idx=0; for(let c of m){ if(c==='0'||c==='A'){ let v=parseInt(seed.substr(idx,2),16); idx+=2; res+=(c==='0')?(v%10).toString():String.fromCharCode(65+(v%26)); } else res+=c; } return res; }
            function generateHash(s, l, min, max) { if(!s)return''; s=s.toUpperCase(); let h=0; for(let i=0;i<s.length;i++) h=((h<<5)-h)+s.charCodeAt(i); h=Math.abs(h); if(max>0){ return ((h%(max-min+1))+min).toString().padStart(l,'0'); } let str=h.toString(); while(str.length<l) str+=Math.abs(h*str.length).toString(); return str.substring(0,l); }
            function generateAlphaHash(s, l) { if(!s)return''; s=s.toUpperCase(); let h=md5(s).toUpperCase(); while(h.length<l)h+=md5(h).toUpperCase(); return h.substring(0,l); }
            function calculateAll() {
                document.querySelectorAll('.calc-target').forEach(t => {
                    let f = t.dataset.formula; if(!f) return;
                    if(/^[a-zA-Z0-9_]+$/.test(f)) { let src=document.querySelector(`input[name="${f}"]`); if(src) t.value=src.value; return; }
                    let pm = f.match(/^\?([a-zA-Z0-9\-\/\.]+)\[([a-zA-Z0-9_]+)\]$/); if(pm) { let src=document.querySelector(`input[name="${pm[2]}"]`); if(src) t.value=generatePatternHash(src.value, pm[1]); return; }
                    let hm = f.match(/^(\#{1,2})(\d+)(?::(\d+)-(\d+))?\[([a-zA-Z0-9_]+)\]$/);
                    if(hm) { let src=document.querySelector(`input[name="${hm[5]}"]`); if(src) { if(hm[1]==='##') t.value=generateAlphaHash(src.value, parseInt(hm[2])); else t.value=generateHash(src.value, parseInt(hm[2]), hm[3]?parseInt(hm[3]):0, hm[4]?parseInt(hm[4]):0); } return; }
                    
                    let parsed = f.replace(/\$/g,'').replace(/[a-zA-Z0-9_]+/g, (m) => {
                        if(['clean','koma','titik','Math'].includes(m)) return m;
                        let s=document.querySelector(`input[name="${m}"]`); return s ? (parseFloat(s.value.replace(/[^0-9-]/g,''))||0) : 0;
                    });
                    try { let r=eval(parsed); if(!isNaN(r) && typeof r==='number') { if(t.classList.contains('mode-rupiah')) t.value='Rp. '+new Intl.NumberFormat('id-ID').format(r); else if(t.classList.contains('mode-koma')) t.value=new Intl.NumberFormat('en-US').format(r); else t.value=r; } else t.value=r||''; } catch(e){}
                });
            }
            document.querySelectorAll('input').forEach(e => {
                e.addEventListener('input', function(ev){ applyCasing(ev); calculateAll(); });
                if(e.classList.contains('mode-rupiah')||e.classList.contains('mode-koma')) e.addEventListener('input', function(ev){
                    let v=ev.target.value.replace(/\D/g,''); if(v==='')return; 
                    if(ev.target.classList.contains('mode-rupiah')) ev.target.value='Rp. '+new Intl.NumberFormat('id-ID').format(v);
                    else ev.target.value=new Intl.NumberFormat('en-US').format(v);
                });
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// === 4. RENDER MODE ===
$structure = json_decode($template['structure'], true, 512, JSON_THROW_ON_ERROR);
$content = $structure['content'] ?? '';
$logo_width = $structure['logo_width'] ?? 100;
$footer_width = $structure['footer_width'] ?? 100;
$global_font_size = $structure['font_size'] ?? 12;
$font_family = $structure['font_family'] ?? 'Consolas';
$margin_top = $structure['margin_top'] ?? 0;
$margin_bottom = $structure['margin_bottom'] ?? 0;
$margin_left = $structure['margin_left'] ?? 0;
$margin_right = $structure['margin_right'] ?? 0;

$pattern = '/\{\{\s*(?<hidden>!)?(?:\s*\[(?<sort>\d+)\])?(?:\s*(?:(?<cur>\${1,2})|(?<hash>\#{1,2})(?<hash_conf>[0-9\|]+(?:[:]\d+[-]\d+)?)?|(?<date>\@{1,3})(?::(?<date_fmt>[dmyDMY]+))?|(?<time>\%{1,4})|(?<patt>\?)(?<patt_conf>[a-zA-Z0-9\-\/\.]+)))?(?<case_pre>[\^_\*])?(?:\((?<maxlen>\d+)\))?(?<case_post>[\^_\*])?(?:\[)?(?<name>[a-zA-Z0-9_]+)(?:\])?(?:\s*=\s*(?<formula>.+?))?\s*\}\}/';

$content = preg_replace_callback($pattern, function($matches) use ($receipt_data) {
    $key = $matches['name'] ?? '';
    $val = $receipt_data[$key] ?? '';
    $casing_char = !empty($matches['case_pre']) ? $matches['case_pre'] : ($matches['case_post'] ?? '');
    
    if ($casing_char === '^') $val = strtoupper($val);
    if ($casing_char === '_') $val = strtolower($val);
    if ($casing_char === '*') $val = ucwords(strtolower($val));

    $max_len = !empty($matches['maxlen']) ? (int)$matches['maxlen'] : 0;
    if ($max_len > 0) { $val = substr($val, 0, $max_len); }

    if (!empty($matches['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $ts = strtotime($val);
        $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        if ($matches['date'] === '@') return date('d/m/Y', $ts);
        if ($matches['date'] === '@@') return date('d.m.y', $ts); 
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
            foreach ($parts as $p) $formatted .= "\n" . str_repeat(" ", $indent_len) . $p;
            return $formatted;
        }
    }
    return $val;
}, $content);

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
        @import url('https://fonts.googleapis.com/css2?family=VT323&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;700&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: '<?= $font_family ?>', monospace; 
            font-size: <?= $global_font_size ?>px; 
            line-height: 1.2; 
            color: black; 
            background: white; 
        }
        @media screen { body { background: #555; padding: 40px 0; display: flex; flex-direction: column; align-items: center; min-height: 100vh; } .receipt { background: white; width: <?= $paper_size === '58mm' ? '58mm' : '80mm' ?>; padding: 5mm; box-shadow: 0 0 15px rgba(0,0,0,0.5); margin-bottom: 20px; min-height: 100px; } .no-print { text-align: center; margin-top: 20px; } }
        
        .receipt {
            padding-top: <?= $margin_top ?>px !important;
            padding-bottom: <?= $margin_bottom ?>px !important;
            padding-left: <?= $margin_left ?>px !important;
            padding-right: <?= $margin_right ?>px !important;
        }

        @media print { 
            @page { size: auto; margin: 0; } 
            body * { visibility: hidden; } 
            .receipt, .receipt * { visibility: visible; } 
            html, body { width: 100%; height: 100%; margin: 0 !important; padding: 0 !important; background: white; overflow: hidden; } 
            .receipt { 
                position: absolute; left: 0; top: 0; width: <?= $content_width ?>; 
                margin: 0; border: none; box-shadow: none; font-weight: bold; 
                -webkit-print-color-adjust: exact; print-color-adjust: exact; 
                padding-top: <?= $margin_top ?>px !important;
                padding-bottom: <?= $margin_bottom ?>px !important;
                padding-left: <?= $margin_left ?>px !important;
                padding-right: <?= $margin_right ?>px !important;
            } 
            .no-print { display: none !important; } 
        }
        .print-line { display: block; width: 100%; white-space: pre-wrap; word-wrap: break-word; position: relative; z-index: 1; overflow: visible; min-height: 1em; }
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
            $lineStyle = [
                'font-family' => 'inherit',
                'font-size' => $global_font_size . 'px',
                'line-height' => '1.2',
                'transform' => 'none',
                'transform-origin' => 'left',
                'letter-spacing' => '0px',
                'padding-left' => '0px',
                'padding-right' => '0px',
                'margin-top' => '0px',
                'margin-bottom' => '0px',
                'font-weight' => 'normal',
                'font-style' => 'normal',
                'position' => 'relative',
                'top' => '0px'
            ];

            if (preg_match('/\[F:([^\]]+)\]/', $finalContent, $matches)) {
                $lineStyle['font-family'] = "'" . $matches[1] . "', monospace"; 
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[S:([\d\.]+)\]/', $finalContent, $matches)) {
                $lineStyle['font-size'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[HS:([\d\.]+)\]/', $finalContent, $matches)) {
                $scale = floatval($matches[1]) / 100;
                $lineStyle['transform'] = "scale($scale, 1)";
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[LH:([\d\.]+)\]/', $finalContent, $matches)) {
                $lineStyle['line-height'] = floatval($matches[1]) / 100;
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[TR:([\-\d\.]+)\]/', $finalContent, $matches)) {
                $lineStyle['letter-spacing'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[BS:([\-\d\.]+)\]/', $finalContent, $matches)) {
                $lineStyle['top'] = (-1 * floatval($matches[1])) . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[IL:([\-\d]+)\]/', $finalContent, $matches)) {
                $lineStyle['padding-left'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[IR:([\-\d]+)\]/', $finalContent, $matches)) {
                $lineStyle['padding-right'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[MT:([\-\d]+)\]/', $finalContent, $matches)) {
                $lineStyle['margin-top'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            if (preg_match('/\[M[BD]:([\-\d]+)\]/', $finalContent, $matches)) {
                $lineStyle['margin-bottom'] = $matches[1] . 'px';
                $finalContent = str_replace($matches[0], '', $finalContent);
            }
            
            if (strpos($finalContent, '[B]') !== false) {
                $lineStyle['font-weight'] = 'bold';
                $finalContent = str_replace('[B]', '', $finalContent);
            }
            if (strpos($finalContent, '[I]') !== false) {
                $lineStyle['font-style'] = 'italic';
                $finalContent = str_replace('[I]', '', $finalContent);
            }

            // PENTING: Trim agar spasi sisa tag tidak mengganggu deteksi [C]
            $finalContent = trim($finalContent);

            // Parsing Alignment
            $textAlign = 'left'; 
            $isSplit = false; 
            $leftText = ''; 
            $rightText = '';

            if (strpos($finalContent, '[R]') > 0 && strpos($finalContent, ' [R] ') !== false) {
                $isSplit = true; 
                $parts = explode(' [R] ', $finalContent); 
                $leftText = $parts[0]; 
                $rightText = $parts[1] ?? '';
            } elseif (strpos($finalContent, '[C]') === 0) { 
                $textAlign = 'center'; $finalContent = substr($finalContent, 3); 
            } elseif (strpos($finalContent, '[R]') === 0) { 
                $textAlign = 'right'; $finalContent = substr($finalContent, 3); 
            } elseif (strpos($finalContent, '[J]') === 0) { 
                $textAlign = 'justify'; $finalContent = substr($finalContent, 3); 
            } elseif (strpos($finalContent, '[L]') === 0) { 
                $textAlign = 'left'; $finalContent = substr($finalContent, 3); 
            }

            // Proses TAB di konten utama & pecahan split
            $processTabs = function($text) {
                $text = str_replace('[TAB]', str_repeat("&nbsp;", 4), $text);
                $text = preg_replace_callback('/\[TAB:(\d+)\]/', function($m) {
                    return str_repeat("&nbsp;", intval($m[1]));
                }, $text);
                return $text;
            };

            $finalContent = $processTabs($finalContent);
            if ($isSplit) {
                $leftText = $processTabs($leftText);
                $rightText = $processTabs($rightText);
            }

            // CSS String
            $cssString = "";
            foreach($lineStyle as $prop => $val) {
                $cssString .= "$prop:$val; ";
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
            
            $imgHtml = '';
            if (strpos($finalContent, '[LOGO]') !== false && $logoUrl) {
                $res = $generateImg($logoUrl, $logo_width, $finalContent, '[LOGO]');
                $imgHtml .= $res['html']; $finalContent = $res['clean'];
            }
            if (strpos($finalContent, '[QR]') !== false && $footerUrl) {
                $res = $generateImg($footerUrl, $footer_width, $finalContent, '[QR]');
                $imgHtml .= $res['html']; $finalContent = $res['clean'];
            }

            if (trim($finalContent) === '' && $imgHtml === '' && !$isSplit) {
                echo '<div class="print-line" style="'.$cssString.'">&nbsp;</div>';
            } else {
                if ($isSplit) {
                    echo '<div class="print-line split-line" style="'.$cssString.'"><span>'.$leftText.'</span><span>'.$rightText.'</span>'.$imgHtml.'</div>';
                } else {
                    echo '<div class="print-line" style="'.$cssString.' text-align:'.$textAlign.';">'.$imgHtml.'<span class="text-layer">'.$finalContent.'</span></div>';
                }
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