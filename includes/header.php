<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thermal Printer Manager</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #f1f5f9;
            --bg-card: #ffffff;
            --sidebar-bg: #0f172a;
            --sidebar-text: #94a3b8;
            --sidebar-width: 250px; /* Lebar Normal */
            --sidebar-mini: 65px;   /* Lebar Mini (Icon Only) */
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --text-main: #334155;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --header-height: 60px;
        }

        body { font-family: 'Inter', sans-serif; background: var(--bg-body); margin: 0; padding: 0; display: flex; overflow: hidden; height: 100vh; }

        /* === 1. SIDEBAR STRUCTURE === */
        #sidebar-wrapper {
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            height: 100vh;
            position: fixed; left: 0; top: 0;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); /* Smooth Transition */
            z-index: 1000;
            display: flex; flex-direction: column;
            overflow: hidden; /* Penting agar teks tidak bocor saat mengecil */
        }

        /* Header Sidebar */
        .sidebar-brand {
            height: var(--header-height);
            display: flex; align-items: center; padding: 0 20px;
            font-size: 1.1rem; font-weight: 700; color: #fff;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            white-space: nowrap; /* Teks tidak turun baris */
        }
        .brand-icon { min-width: 30px; text-align: center; color: var(--primary); font-size: 1.2rem; }
        .brand-text { margin-left: 10px; transition: opacity 0.2s; opacity: 1; }

        /* Menu List */
        .sidebar-menu { list-style: none; padding: 15px 0; margin: 0; flex: 1; overflow-y: auto; overflow-x: hidden; }
        .sidebar-menu li a {
            display: flex; align-items: center;
            padding: 12px 20px; color: var(--sidebar-text);
            text-decoration: none; transition: 0.2s; font-size: 0.9rem;
            border-left: 3px solid transparent;
            white-space: nowrap;
        }
        .sidebar-menu li a i { 
            min-width: 30px; /* Lebar tetap untuk icon agar sejajar */
            text-align: center; font-size: 1.1rem;
        }
        .menu-text { margin-left: 10px; opacity: 1; transition: opacity 0.2s; }

        /* Hover & Active State */
        .sidebar-menu li a:hover, .sidebar-menu li a.active {
            background: rgba(255,255,255,0.08); color: #fff; border-left-color: var(--primary);
        }

        /* Footer Sidebar */
        .sidebar-footer { 
            padding: 15px 20px; font-size: 0.75rem; color: var(--sidebar-text); 
            border-top: 1px solid rgba(255,255,255,0.1); 
            white-space: nowrap; 
        }

        /* === 2. CONTENT WRAPPER === */
        #page-content-wrapper {
            width: 100%;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            display: flex; flex-direction: column;
            height: 100vh;
        }

        /* === 3. MODE: MINI SIDEBAR (Icon Only) === */
        /* Aktif jika body punya class 'mini-sidebar' */
        body.mini-sidebar #sidebar-wrapper { width: var(--sidebar-mini); }
        body.mini-sidebar #page-content-wrapper { margin-left: var(--sidebar-mini); }
        
        /* Sembunyikan Teks di Mode Mini */
        body.mini-sidebar .brand-text,
        body.mini-sidebar .menu-text,
        body.mini-sidebar .sidebar-footer { 
            opacity: 0; pointer-events: none; display: none; 
        }
        
        /* Center Icons di Mode Mini */
        body.mini-sidebar .sidebar-menu li a { padding: 15px 0; justify-content: center; }
        body.mini-sidebar .sidebar-menu li a i { margin: 0; }
        body.mini-sidebar .sidebar-brand { justify-content: center; padding: 0; }
        body.mini-sidebar .brand-icon { margin: 0; }

        /* === 4. MODE: TOGGLED (Hidden Completely for Mobile) === */
        body.toggled #sidebar-wrapper { margin-left: calc(var(--sidebar-width) * -1); }
        body.toggled #page-content-wrapper { margin-left: 0; }

        /* TOPBAR */
        .topbar {
            height: var(--header-height);
            background: #fff;
            border-bottom: 1px solid var(--border-color);
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 20px; flex-shrink: 0;
        }
        .toggle-btn { background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--text-main); }
        .profile-menu { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .profile-img { width: 32px; height: 32px; background: #e0e7ff; color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            #sidebar-wrapper { margin-left: calc(var(--sidebar-width) * -1); }
            #page-content-wrapper { margin-left: 0; }
            body.toggled #sidebar-wrapper { margin-left: 0; }
        }
    </style>
</head>
<?php
// DETEKSI HALAMAN
// Jika halaman adalah 'designer.php', tambahkan class 'mini-sidebar'
$current_page = basename($_SERVER['PHP_SELF']);
$body_class = ($current_page == 'designer.php') ? 'mini-sidebar' : '';
?>
<body class="<?= $body_class ?>">

<div id="sidebar-wrapper">
    <div class="sidebar-brand">
        <i class="fas fa-receipt brand-icon"></i>
        <span class="brand-text">Thermal Pro</span>
    </div>
    <ul class="sidebar-menu">
        <li>
            <a href="index.php" class="<?= $current_page == 'index.php' ? 'active' : '' ?>" title="Dashboard">
                <i class="fas fa-th-large"></i> 
                <span class="menu-text">Dashboard</span>
            </a>
        </li>
        <li>
            <a href="designer.php" class="<?= $current_page == 'designer.php' ? 'active' : '' ?>" title="Desainer Struk">
                <i class="fas fa-pencil-ruler"></i> 
                <span class="menu-text">Desainer</span>
            </a>
        </li>
        <li>
            <a href="#" onclick="alert('Fitur Laporan akan segera hadir!')" title="Laporan">
                <i class="fas fa-chart-line"></i> 
                <span class="menu-text">Laporan</span>
            </a>
        </li>
        <li>
            <a href="#" onclick="alert('Fitur Pengguna akan segera hadir!')" title="Pengguna">
                <i class="fas fa-users"></i> 
                <span class="menu-text">Pengguna</span>
            </a>
        </li>
        <li>
            <a href="#" onclick="alert('Pengaturan akan segera hadir!')" title="Pengaturan">
                <i class="fas fa-cog"></i> 
                <span class="menu-text">Setting</span>
            </a>
        </li>
    </ul>
    <div class="sidebar-footer">
        v2.0 Beta System
    </div>
</div>

<div id="page-content-wrapper">
    <nav class="topbar">
        <button class="toggle-btn" id="menu-toggle"><i class="fas fa-bars"></i></button>
        
        <div class="profile-menu" onclick="alert('Menu Profil Admin akan dikembangkan nanti!')">
            <span style="font-size:0.85rem; font-weight:600; color:var(--text-main);">Administrator</span>
            <div class="profile-img">A</div>
        </div>
    </nav>