<?php
/**
 * PhotonBench — Fast & Easy Multicore CPU Benchmark
 * Powered by photon-mapping rendering engine, runs entirely in browser.
 * License: GPLv3 — https://www.gnu.org/licenses/gpl-3.0.html
 */

// === SQLite Database Setup ===
$dbPath = __DIR__ . '/photonbench.db';
try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec("CREATE TABLE IF NOT EXISTS results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_name TEXT NOT NULL DEFAULT 'Anonymous',
        device_name TEXT NOT NULL DEFAULT 'Unknown Device',
        cpu_threads INTEGER NOT NULL DEFAULT 1,
        test_duration INTEGER NOT NULL DEFAULT 180,
        score REAL NOT NULL DEFAULT 0,
        points INTEGER NOT NULL DEFAULT 0,
        platform TEXT DEFAULT '',
        user_agent TEXT DEFAULT '',
        share_hash TEXT UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    // Migration: add user_name if not exists
    try { $db->exec("ALTER TABLE results ADD COLUMN user_name TEXT NOT NULL DEFAULT 'Anonymous'"); } catch(Exception $e) {}
    $db->exec("CREATE INDEX IF NOT EXISTS idx_points ON results(points DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_created ON results(created_at DESC)");
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// === API Endpoints ===
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    if ($_GET['action'] === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['score'])) {
            echo json_encode(['error' => 'Data tidak valid']);
            exit;
        }
        $shareHash = bin2hex(random_bytes(8));
        $threads = max(1, intval($input['threads']));
        $points = round(($input['score'] / $threads) * 10);
        $stmt = $db->prepare("INSERT INTO results (user_name, device_name, cpu_threads, test_duration, score, points, platform, user_agent, share_hash) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            mb_substr($input['user_name'] ?? 'Anonymous', 0, 60),
            mb_substr($input['device_name'] ?? 'Unknown Device', 0, 60),
            $threads,
            intval($input['duration']),
            floatval($input['score']),
            $points,
            mb_substr($input['platform'] ?? '', 0, 40),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            $shareHash
        ]);
        echo json_encode(['success' => true, 'id' => $db->lastInsertId(), 'hash' => $shareHash, 'points' => $points]);
        exit;
    }

    if ($_GET['action'] === 'leaderboard') {
        $limit = min(100, max(1, intval($_GET['limit'] ?? 10)));
        $stmt = $db->prepare("SELECT id, user_name, device_name, cpu_threads, test_duration, score, points, platform, share_hash, created_at FROM results ORDER BY points DESC LIMIT ?");
        $stmt->execute([$limit]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($_GET['action'] === 'history') {
        $limit = min(50, max(1, intval($_GET['limit'] ?? 15)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        $stmt = $db->prepare("SELECT id, user_name, device_name, cpu_threads, test_duration, score, points, platform, share_hash, created_at FROM results ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $countStmt = $db->query("SELECT COUNT(*) FROM results");
        $total = $countStmt->fetchColumn();
        echo json_encode(['data' => $rows, 'total' => intval($total), 'offset' => $offset, 'limit' => $limit]);
        exit;
    }

    if ($_GET['action'] === 'shared') {
        $hash = preg_replace('/[^a-f0-9]/', '', $_GET['hash'] ?? '');
        if (strlen($hash) !== 16) { echo json_encode(null); exit; }
        $stmt = $db->prepare("SELECT * FROM results WHERE share_hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: null);
        exit;
    }

    echo json_encode(['error' => 'Aksi tidak dikenali']);
    exit;
}

$sharedResult = null;
if (isset($_GET['share'])) {
    $hash = preg_replace('/[^a-f0-9]/', '', $_GET['share']);
    if (strlen($hash) === 16) {
        $stmt = $db->prepare("SELECT * FROM results WHERE share_hash = ?");
        $stmt->execute([$hash]);
        $sharedResult = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhotonBench — Multicore CPU Benchmark di Browser</title>
    <meta name="description" content="PhotonBench: Benchmark CPU multicore gratis yang berjalan langsung di browser. Menggunakan photon-mapping rendering engine untuk mengukur performa CPU secara akurat.">
    <meta name="keywords" content="CPU benchmark, multicore benchmark, browser benchmark, photon mapping, performance test, stress test">
    <meta name="author" content="Pratama Digital">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://bench.pratamadigital.com/">
    <meta property="og:title" content="PhotonBench — Multicore CPU Benchmark">
    <meta property="og:description" content="Benchmark CPU multicore yang berjalan di browser menggunakan photon-mapping rendering engine.">
    <meta property="og:url" content="https://bench.pratamadigital.com/">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="PhotonBench — CPU Benchmark">
    <meta name="twitter:description" content="Benchmark CPU multicore gratis langsung di browser Anda.">
    <link rel="license" href="https://www.gnu.org/licenses/gpl-3.0.html">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        display: ['"Space Grotesk"', 'sans-serif'],
                        body: ['"DM Sans"', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --bg: #080808;
            --bg-surface: #101010;
            --bg-card: #151515;
            --bg-card-hover: #1c1c1c;
            --border: #222222;
            --border-light: #2e2e2e;
            --text: #ececec;
            --text-secondary: #999999;
            --text-muted: #666666;
            --accent: #F59E0B;
            --accent-dim: #B45309;
            --accent-glow: rgba(245, 158, 11, 0.15);
            --accent-glow-strong: rgba(245, 158, 11, 0.3);
            --danger: #EF4444;
            --danger-glow: rgba(239, 68, 68, 0.15);
            --success: #10B981;
            --success-glow: rgba(16, 185, 129, 0.15);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            overflow-x: hidden;
        }
        h1, h2, h3, h4, h5, h6, .font-display { font-family: 'Space Grotesk', sans-serif; }

        .bg-ambient {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 600px 400px at 15% 20%, rgba(245,158,11,0.04) 0%, transparent 70%),
                radial-gradient(ellipse 500px 500px at 85% 80%, rgba(239,68,68,0.03) 0%, transparent 70%),
                radial-gradient(ellipse 800px 300px at 50% 50%, rgba(245,158,11,0.02) 0%, transparent 70%);
        }
        .bg-grid {
            position: fixed; inset: 0; z-index: 0; pointer-events: none; opacity: 0.03;
            background-image:
                linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.3s ease;
        }
        .card:hover { border-color: var(--border-light); background: var(--bg-card-hover); }
        .card-active {
            border-color: var(--accent) !important;
            box-shadow: 0 0 20px var(--accent-glow), inset 0 0 20px var(--accent-glow);
        }

        .btn-primary {
            background: var(--accent);
            color: #000;
            font-weight: 600;
            padding: 12px 28px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 15px;
            transition: all 0.25s ease;
            letter-spacing: 0.02em;
        }
        .btn-primary:hover { background: #D97706; transform: translateY(-1px); box-shadow: 0 4px 20px var(--accent-glow-strong); }
        .btn-primary:active { transform: translateY(0); }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; transform: none; box-shadow: none; }

        .btn-outline {
            background: transparent;
            color: var(--text);
            font-weight: 500;
            padding: 10px 22px;
            border-radius: 10px;
            border: 1px solid var(--border-light);
            cursor: pointer;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px;
            transition: all 0.25s ease;
        }
        .btn-outline:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-glow); }

        .btn-danger {
            background: transparent;
            color: var(--danger);
            font-weight: 600;
            padding: 10px 22px;
            border-radius: 10px;
            border: 1px solid var(--danger);
            cursor: pointer;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px;
            transition: all 0.25s ease;
        }
        .btn-danger:hover { background: var(--danger-glow); }

        /* Benchmark canvas glow */
        .canvas-wrapper {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border);
            position: relative;
            background: #000;
        }
        .canvas-wrapper.active {
            border-color: var(--accent);
            animation: canvasPulse 2.5s ease-in-out infinite;
        }
        .canvas-wrapper.stress-active {
            border-color: var(--danger);
            animation: canvasPulseRed 1.5s ease-in-out infinite;
        }
        @keyframes canvasPulse {
            0%, 100% { box-shadow: 0 0 15px var(--accent-glow), 0 0 40px rgba(245,158,11,0.05); }
            50% { box-shadow: 0 0 30px var(--accent-glow-strong), 0 0 60px rgba(245,158,11,0.1); }
        }
        @keyframes canvasPulseRed {
            0%, 100% { box-shadow: 0 0 15px var(--danger-glow), 0 0 40px rgba(239,68,68,0.05); }
            50% { box-shadow: 0 0 30px rgba(239,68,68,0.3), 0 0 60px rgba(239,68,68,0.1); }
        }

        /* Thread bar */
        .thread-bar {
            height: 6px;
            border-radius: 3px;
            background: var(--border);
            overflow: hidden;
        }
        .thread-bar-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--accent);
            transition: width 0.3s ease;
            animation: threadPulse 1s ease-in-out infinite;
        }
        @keyframes threadPulse {
            0%, 100% { opacity: 0.7; }
            50% { opacity: 1; }
        }

        /* Score animation */
        .score-reveal {
            animation: scoreIn 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes scoreIn {
            from { opacity: 0; transform: scale(0.8) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* Toast */
        .toast {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
            padding: 14px 24px; border-radius: 10px;
            background: var(--bg-card); border: 1px solid var(--border-light);
            color: var(--text); font-size: 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            transform: translateY(100px); opacity: 0;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .toast.show { transform: translateY(0); opacity: 1; }

        /* Modal overlay */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(6px);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; pointer-events: none;
            transition: opacity 0.3s ease;
            padding: 16px;
        }
        .modal-overlay.show { opacity: 1; pointer-events: all; }
        .modal-box {
            background: var(--bg-card); border: 1px solid var(--border-light);
            border-radius: 16px; padding: 32px; max-width: 560px; width: 100%;
            transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 90vh; overflow-y: auto;
        }
        .modal-overlay.show .modal-box { transform: scale(1); }
        .modal-box-wide {
            max-width: 780px;
        }
        .modal-box-bench {
            max-width: 640px;
        }

        /* Leaderboard */
        .lb-row { transition: background 0.2s ease; }
        .lb-row:hover { background: var(--bg-card-hover); }
        .rank-badge {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12px; font-family: 'Space Grotesk', sans-serif;
            flex-shrink: 0;
        }
        .rank-1 { background: linear-gradient(135deg, #F59E0B, #D97706); color: #000; }
        .rank-2 { background: linear-gradient(135deg, #9CA3AF, #6B7280); color: #000; }
        .rank-3 { background: linear-gradient(135deg, #D97706, #92400E); color: #fff; }
        .rank-other { background: var(--border); color: var(--text-secondary); }

        /* Fade-in animation */
        .fade-in { animation: fadeIn 0.6s ease forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

        .stat-value {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 1.5rem;
            line-height: 1;
        }

        .scanline {
            position: absolute; inset: 0; pointer-events: none;
            background: repeating-linear-gradient(
                0deg, transparent, transparent 2px,
                rgba(0,0,0,0.03) 2px, rgba(0,0,0,0.03) 4px
            );
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb { background: var(--border-light); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-muted); }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
        }

        input:focus, select:focus { outline: 2px solid var(--accent); outline-offset: 2px; }
        input, textarea {
            background: var(--bg-surface);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
        }

        /* Onboarding modal special */
        .onboard-box {
            background: linear-gradient(135deg, var(--bg-card) 0%, rgba(245,158,11,0.04) 100%);
            border-color: rgba(245,158,11,0.3);
        }

        /* Pagination */
        .page-btn {
            padding: 6px 12px; border-radius: 6px; font-size: 13px; font-family: 'Space Grotesk', sans-serif;
            border: 1px solid var(--border-light); background: transparent; color: var(--text-secondary); cursor: pointer;
            transition: all 0.2s ease;
        }
        .page-btn:hover { border-color: var(--accent); color: var(--accent); }
        .page-btn.active { background: var(--accent); color: #000; border-color: var(--accent); font-weight: 600; }
        .page-btn:disabled { opacity: 0.3; cursor: not-allowed; }

        /* Table util */
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th {
            padding: 10px 12px; text-align: left; font-family: 'Space Grotesk', sans-serif;
            font-size: 11px; letter-spacing: 0.05em; text-transform: uppercase;
            color: var(--text-muted); background: var(--bg-surface);
            border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        .data-table td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: rgba(255,255,255,0.02); }

        /* Test card */
        .test-card { cursor: pointer; }
    </style>
</head>
<body class="font-body">
    <div class="bg-ambient"></div>
    <div class="bg-grid"></div>

    <div class="relative z-10 min-h-screen flex flex-col">
        <!-- Header -->
        <header class="border-b sticky top-0" style="border-color: var(--border); background: rgba(8,8,8,0.92); backdrop-filter: blur(12px); z-index: 100;">
            <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background: var(--accent-glow-strong);">
                        <i class="fas fa-bolt" style="color: var(--accent); font-size: 16px;"></i>
                    </div>
                    <div>
                        <h1 class="font-display font-bold text-lg leading-tight" style="color: var(--text);">PhotonBench</h1>
                        <p class="text-xs" style="color: var(--text-muted);">Multicore CPU Benchmark</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button onclick="openHistoryModal()" class="btn-outline text-xs hidden sm:inline-flex items-center gap-2">
                        <i class="fas fa-clock-rotate-left" style="color: var(--text-secondary);"></i> History
                    </button>
                    <button onclick="openLeaderboardModal()" class="btn-outline text-xs hidden sm:inline-flex items-center gap-2">
                        <i class="fas fa-trophy" style="color: var(--accent);"></i> Papan Peringkat
                    </button>
                    <button onclick="openHistoryModal()" class="btn-outline text-xs sm:hidden px-3">
                        <i class="fas fa-clock-rotate-left"></i>
                    </button>
                    <button onclick="openLeaderboardModal()" class="btn-outline text-xs sm:hidden px-3">
                        <i class="fas fa-trophy"></i>
                    </button>
                    <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener" class="text-xs hidden sm:inline" style="color: var(--text-muted);" title="GPLv3 License">
                        <i class="fab fa-osi"></i> GPLv3
                    </a>
                </div>
            </div>
        </header>

        <main class="flex-1 max-w-6xl mx-auto w-full px-4 py-8">

            <!-- Shared Result Banner -->
            <?php if ($sharedResult): ?>
            <div id="sharedBanner" class="card p-6 mb-8 fade-in" style="border-color: var(--accent); background: linear-gradient(135deg, var(--bg-card), rgba(245,158,11,0.03));">
                <div class="flex items-center gap-2 mb-3">
                    <i class="fas fa-share-nodes" style="color: var(--accent);"></i>
                    <span class="font-display font-semibold text-sm" style="color: var(--accent);">Hasil Benchmark yang Dibagikan</span>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 text-sm">
                    <div><span style="color: var(--text-muted);">Pengguna</span><br><strong><?= htmlspecialchars($sharedResult['user_name']) ?></strong></div>
                    <div><span style="color: var(--text-muted);">Perangkat / CPU</span><br><strong><?= htmlspecialchars($sharedResult['device_name']) ?></strong></div>
                    <div><span style="color: var(--text-muted);">Skor</span><br><strong class="font-display" style="color: var(--accent);"><?= number_format($sharedResult['score']) ?></strong></div>
                    <div><span style="color: var(--text-muted);">Thread CPU</span><br><strong><?= $sharedResult['cpu_threads'] ?></strong></div>
                    <div><span style="color: var(--text-muted);">Poin</span><br><strong class="font-display" style="color: var(--success);"><?= number_format($sharedResult['points']) ?></strong></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Hero & System Info -->
            <section class="text-center mb-10">
                <h2 class="font-display font-bold text-3xl sm:text-5xl mb-3 leading-tight" style="color: var(--text);">
                    Ukur Kekuatan <span style="color: var(--accent);">CPU</span> Anda
                </h2>
                <p class="text-base sm:text-lg max-w-2xl mx-auto mb-8" style="color: var(--text-secondary);">
                    Benchmark multicore berbasis photon-mapping rendering engine yang berjalan langsung di browser. Tanpa instalasi, tanpa GPU — murni kekuatan CPU.
                </p>

                <!-- System Info Cards -->
                <div class="flex flex-wrap justify-center gap-3 mb-8">
                    <div class="card px-5 py-3 flex items-center gap-3">
                        <i class="fas fa-microchip" style="color: var(--accent); font-size: 18px;"></i>
                        <div class="text-left">
                            <div class="text-xs" style="color: var(--text-muted);">CPU Threads</div>
                            <div class="font-display font-bold text-lg" id="cpuThreads">Mendeteksi...</div>
                        </div>
                    </div>
                    <div class="card px-5 py-3 flex items-center gap-3">
                        <i class="fas fa-desktop" style="color: var(--accent); font-size: 18px;"></i>
                        <div class="text-left">
                            <div class="text-xs" style="color: var(--text-muted);">Platform</div>
                            <div class="font-display font-bold text-lg" id="platformInfo">Mendeteksi...</div>
                        </div>
                    </div>
                    <div class="card px-5 py-3 flex items-center gap-3">
                        <i class="fas fa-user-circle" style="color: var(--accent); font-size: 18px;"></i>
                        <div class="text-left">
                            <div class="text-xs" style="color: var(--text-muted);">Pengguna</div>
                            <div class="font-display font-bold text-lg" id="currentUserDisplay">-</div>
                        </div>
                    </div>
                    <div class="card px-5 py-3 flex items-center gap-3">
                        <i class="fas fa-memory" style="color: var(--accent); font-size: 18px;"></i>
                        <div class="text-left">
                            <div class="text-xs" style="color: var(--text-muted);">Engine</div>
                            <div class="font-display font-bold text-lg">Photon Map</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Test Selection -->
            <section id="testSelection" class="mb-10">
                <h3 class="font-display font-semibold text-xl mb-5 text-center" style="color: var(--text);">Pilih Durasi Test</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-3xl mx-auto">
                    <div class="card p-6 cursor-pointer test-card" data-duration="3" data-depth="3" data-samples="1" onclick="selectTest(this)">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-display font-bold text-2xl" style="color: var(--accent);">3</span>
                            <span class="text-xs px-2 py-1 rounded-full font-medium" style="background: var(--accent-glow); color: var(--accent);">Performance</span>
                        </div>
                        <div class="font-display font-semibold text-lg mb-1">Menit</div>
                        <p class="text-xs" style="color: var(--text-muted);">Ray depth 3, 1 sample/pixel. Cepat dan akurat untuk estimasi performa.</p>
                    </div>
                    <div class="card p-6 cursor-pointer test-card" data-duration="10" data-depth="5" data-samples="2" onclick="selectTest(this)">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-display font-bold text-2xl" style="color: #F97316;">10</span>
                            <span class="text-xs px-2 py-1 rounded-full font-medium" style="background: rgba(249,115,22,0.12); color: #F97316;">Extreme</span>
                        </div>
                        <div class="font-display font-semibold text-lg mb-1">Menit</div>
                        <p class="text-xs" style="color: var(--text-muted);">Ray depth 5, 2 samples/pixel. Uji menyeluruh untuk skor yang lebih stabil.</p>
                    </div>
                    <div class="card p-6 cursor-pointer test-card" data-duration="25" data-depth="7" data-samples="4" onclick="selectTest(this)">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-display font-bold text-2xl" style="color: var(--danger);">25</span>
                            <span class="text-xs px-2 py-1 rounded-full font-medium" style="background: var(--danger-glow); color: var(--danger);">Stress</span>
                        </div>
                        <div class="font-display font-semibold text-lg mb-1">Menit</div>
                        <p class="text-xs" style="color: var(--text-muted);">Ray depth 7, 4 samples/pixel. Burn-in test untuk cek stabilitas dan thermal.</p>
                    </div>
                </div>
                <div class="text-center mt-6">
                    <button id="startBtn" class="btn-primary text-base" disabled onclick="startBenchmark()">
                        <i class="fas fa-play mr-2"></i> Mulai Benchmark
                    </button>
                </div>
            </section>

            <!-- Leaderboard Preview (top 5) -->
            <section id="leaderboardPreview" class="mb-10">
                <div class="max-w-4xl mx-auto">
                    <div class="flex items-center justify-between mb-5">
                        <h3 class="font-display font-semibold text-xl" style="color: var(--text);">
                            <i class="fas fa-trophy mr-2" style="color: var(--accent);"></i> Top 5 Peringkat
                        </h3>
                        <button class="btn-outline text-xs" onclick="openLeaderboardModal()">
                            <i class="fas fa-expand mr-1"></i> Lihat Semua
                        </button>
                    </div>
                    <div class="card overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px">#</th>
                                        <th>Pengguna</th>
                                        <th>CPU / Perangkat</th>
                                        <th class="hidden sm:table-cell">Thread</th>
                                        <th class="hidden sm:table-cell">Durasi</th>
                                        <th class="text-right">Skor</th>
                                        <th class="text-right">Poin</th>
                                    </tr>
                                </thead>
                                <tbody id="leaderboardPreviewBody">
                                    <tr><td colspan="7" class="text-center py-8" style="color: var(--text-muted);">Memuat data...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Footer -->
        <footer class="border-t py-6 px-4" style="border-color: var(--border); background: rgba(8,8,8,0.9);">
            <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3 text-xs" style="color: var(--text-muted);">
                <div class="flex items-center gap-2">
                    <span>PhotonBench</span>
                    <span>&middot;</span>
                    <a href="https://bench.pratamadigital.com" class="hover:underline" style="color: var(--text-secondary);">bench.pratamadigital.com</a>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <a href="https://www.pratamadigital.com" target="_blank" rel="noopener" class="hover:underline" style="color: var(--text-secondary);">
                        <i class="fas fa-globe mr-1"></i> pratamadigital.com
                    </a>
                    <span>&middot;</span>
                    <a href="https://github.com/retno-W/cpu_benchmark" target="_blank" rel="noopener" class="hover:underline" style="color: var(--text-secondary);">
                        <i class="fab fa-github mr-1"></i> GitHub
                    </a>
                    <span>&middot;</span>
                    <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener" class="hover:underline" style="color: var(--text-secondary);">
                        <i class="fab fa-osi mr-1"></i> GPLv3 License
                    </a>
                </div>
            </div>
        </footer>
    </div>

    <!-- ============ MODAL: Onboarding User Info ============ -->
    <div class="modal-overlay" id="onboardModal">
        <div class="modal-box onboard-box" style="max-width: 480px;">
            <div class="text-center mb-6">
                <div class="w-14 h-14 rounded-xl flex items-center justify-center mx-auto mb-4" style="background: var(--accent-glow-strong);">
                    <i class="fas fa-bolt" style="color: var(--accent); font-size: 24px;"></i>
                </div>
                <h3 class="font-display font-bold text-2xl mb-2" style="color: var(--text);">Selamat Datang di PhotonBench</h3>
                <p class="text-sm" style="color: var(--text-secondary);">Isi nama Anda dan CPU yang digunakan untuk tampil di papan peringkat. Atau langsung lanjut untuk nama acak.</p>
            </div>
            <div class="space-y-4 mb-6">
                <div>
                    <label class="block text-sm font-medium mb-1.5" style="color: var(--text-secondary);">
                        <i class="fas fa-user mr-1"></i> Nama Anda
                    </label>
                    <input type="text" id="onboardUserName" placeholder="Contoh: Budi Santoso" class="w-full" maxlength="60">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5" style="color: var(--text-secondary);">
                        <i class="fas fa-microchip mr-1"></i> Nama CPU / Perangkat
                    </label>
                    <input type="text" id="onboardCpuName" placeholder="Contoh: Ryzen 7 7800X3D" class="w-full" maxlength="60">
                    <p class="text-xs mt-1" style="color: var(--text-muted);">Terdeteksi otomatis: <span id="autoDetectCpu">-</span></p>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
                <button class="btn-primary flex-1" onclick="confirmOnboarding(false)">
                    <i class="fas fa-check mr-2"></i> Simpan & Mulai
                </button>
                <button class="btn-outline" onclick="confirmOnboarding(true)">
                    <i class="fas fa-forward mr-1"></i> Lanjut Tanpa Nama
                </button>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: Benchmark Running ============ -->
    <div class="modal-overlay" id="benchmarkModal">
        <div class="modal-box modal-box-bench" style="padding: 24px;">
            <!-- Stats Bar -->
            <div class="grid grid-cols-4 gap-2 mb-4">
                <div class="card p-3 text-center" style="border-radius: 8px;">
                    <div class="text-xs mb-1" style="color: var(--text-muted);">Waktu</div>
                    <div class="stat-value text-lg" style="color: var(--accent);" id="statTime">00:00</div>
                </div>
                <div class="card p-3 text-center" style="border-radius: 8px;">
                    <div class="text-xs mb-1" style="color: var(--text-muted);">Skor</div>
                    <div class="stat-value text-base" id="statScore">0</div>
                </div>
                <div class="card p-3 text-center" style="border-radius: 8px;">
                    <div class="text-xs mb-1" style="color: var(--text-muted);">Total Rays</div>
                    <div class="stat-value text-sm" id="statRays">0</div>
                </div>
                <div class="card p-3 text-center" style="border-radius: 8px;">
                    <div class="text-xs mb-1" style="color: var(--text-muted);">Pass</div>
                    <div class="stat-value text-lg" id="statPass">0</div>
                </div>
            </div>

            <!-- Canvas -->
            <div class="canvas-wrapper" id="canvasWrapper">
                <canvas id="renderCanvas" width="480" height="360" style="width: 100%; display: block; image-rendering: auto;"></canvas>
                <div class="scanline"></div>
                <div class="absolute top-3 left-3 text-xs px-3 py-1.5 rounded-lg" style="background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); color: var(--text-secondary);" id="renderInfo">
                    Photon-Mapping Ray Tracer — Inisialisasi...
                </div>
                <div class="absolute top-3 right-3 text-xs px-3 py-1.5 rounded-lg" style="background: rgba(0,0,0,0.7); backdrop-filter: blur(4px);">
                    <span style="color: var(--accent);" id="modeLabel">Performance</span>
                </div>
                <div class="absolute bottom-0 left-0 right-0 h-1" style="background: rgba(0,0,0,0.5);">
                    <div class="h-full transition-all duration-500" style="background: var(--accent);" id="timeProgress"></div>
                </div>
            </div>

            <!-- Thread Utilization -->
            <div class="mt-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-medium" style="color: var(--text-secondary);">
                        <i class="fas fa-bars-staggered mr-1"></i> Utilisasi Thread
                    </span>
                    <span class="text-xs" style="color: var(--text-muted);" id="threadCount">0 thread aktif</span>
                </div>
                <div id="threadBars" class="space-y-1.5"></div>
            </div>

            <!-- Cancel Button -->
            <div class="text-center mt-5">
                <button class="btn-danger" onclick="cancelBenchmark()">
                    <i class="fas fa-stop mr-2"></i> Batalkan Benchmark
                </button>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: Results ============ -->
    <div class="modal-overlay" id="resultsModal">
        <div class="modal-box" style="max-width: 560px; text-align: center;">
            <div class="mb-2 text-sm font-medium" style="color: var(--text-muted);">Skor Benchmark Anda</div>
            <div class="font-display font-bold mb-1 score-reveal" style="font-size: clamp(2.5rem, 8vw, 4rem); color: var(--accent); line-height: 1;" id="finalScore">0</div>
            <div class="text-sm mb-6" style="color: var(--text-secondary);">rays per second</div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
                <div class="p-3 rounded-lg" style="background: var(--bg-surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Poin</div>
                    <div class="font-display font-bold text-lg" style="color: var(--success);" id="finalPoints">0</div>
                </div>
                <div class="p-3 rounded-lg" style="background: var(--bg-surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Durasi</div>
                    <div class="font-display font-bold text-lg" id="finalDuration">0m</div>
                </div>
                <div class="p-3 rounded-lg" style="background: var(--bg-surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Total Rays</div>
                    <div class="font-display font-bold text-lg" id="finalRays">0</div>
                </div>
                <div class="p-3 rounded-lg" style="background: var(--bg-surface);">
                    <div class="text-xs" style="color: var(--text-muted);">Pass Selesai</div>
                    <div class="font-display font-bold text-lg" id="finalPasses">0</div>
                </div>
            </div>

            <div class="flex flex-wrap justify-center gap-3">
                <button class="btn-primary" onclick="showShareModal()">
                    <i class="fas fa-share-nodes mr-2"></i> Bagikan Hasil
                </button>
                <button class="btn-outline" onclick="resetBenchmark()">
                    <i class="fas fa-redo mr-2"></i> Uji Lagi
                </button>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: Leaderboard ============ -->
    <div class="modal-overlay" id="leaderboardModal">
        <div class="modal-box modal-box-wide">
            <div class="flex items-center justify-between mb-5">
                <h4 class="font-display font-bold text-lg flex items-center gap-2">
                    <i class="fas fa-trophy" style="color: var(--accent);"></i> Papan Peringkat
                    <span class="text-xs font-normal px-2 py-0.5 rounded-full" style="background: var(--accent-glow); color: var(--accent);">Top 10</span>
                </h4>
                <button onclick="closeLeaderboardModal()" class="w-8 h-8 rounded-lg flex items-center justify-center" style="background: var(--bg-surface); color: var(--text-muted);">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="overflow-x-auto rounded-lg" style="border: 1px solid var(--border);">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:44px">#</th>
                            <th>Pengguna</th>
                            <th>CPU / Perangkat</th>
                            <th class="hidden sm:table-cell">Thread</th>
                            <th class="hidden sm:table-cell">Durasi</th>
                            <th class="text-right">Skor</th>
                            <th class="text-right">Poin</th>
                            <th class="text-right hidden md:table-cell">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody id="leaderboardModalBody">
                        <tr><td colspan="8" class="text-center py-8" style="color: var(--text-muted);">Memuat...</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs mt-3 text-center" style="color: var(--text-muted);">Diurutkan dari skor tertinggi ke terendah. Poin = (skor / thread) × 10</p>
        </div>
    </div>

    <!-- ============ MODAL: History ============ -->
    <div class="modal-overlay" id="historyModal">
        <div class="modal-box modal-box-wide">
            <div class="flex items-center justify-between mb-5">
                <h4 class="font-display font-bold text-lg flex items-center gap-2">
                    <i class="fas fa-clock-rotate-left" style="color: var(--text-secondary);"></i> Riwayat Benchmark
                    <span class="text-xs font-normal" style="color: var(--text-muted);" id="historyTotalLabel"></span>
                </h4>
                <button onclick="closeHistoryModal()" class="w-8 h-8 rounded-lg flex items-center justify-center" style="background: var(--bg-surface); color: var(--text-muted);">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="overflow-x-auto rounded-lg" style="border: 1px solid var(--border);">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width:44px">#</th>
                            <th>Pengguna</th>
                            <th>CPU / Perangkat</th>
                            <th class="hidden sm:table-cell">Thread</th>
                            <th class="hidden sm:table-cell">Durasi</th>
                            <th class="text-right">Skor</th>
                            <th class="text-right">Poin</th>
                            <th class="text-right hidden md:table-cell">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody id="historyModalBody">
                        <tr><td colspan="8" class="text-center py-8" style="color: var(--text-muted);">Memuat...</td></tr>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="flex items-center justify-between mt-4">
                <div class="text-xs" style="color: var(--text-muted);" id="historyPageInfo">Halaman 1</div>
                <div class="flex items-center gap-2" id="historyPagination"></div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL: Share ============ -->
    <div class="modal-overlay" id="shareModal">
        <div class="modal-box">
            <div class="flex items-center justify-between mb-5">
                <h4 class="font-display font-bold text-lg">Bagikan Hasil</h4>
                <button onclick="closeShareModal()" class="w-8 h-8 rounded-lg flex items-center justify-center" style="background: var(--bg-surface); color: var(--text-muted);">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <textarea id="shareText" readonly rows="8" class="w-full mb-4 text-sm" style="resize: none; font-family: monospace;"></textarea>
            <div class="flex items-center gap-3">
                <button class="btn-primary flex-1" onclick="copyShareText()">
                    <i class="fas fa-copy mr-2"></i> Salin ke Clipboard
                </button>
                <button class="btn-outline" onclick="closeShareModal()">Tutup</button>
            </div>
            <div class="mt-3 text-center">
                <a id="shareLink" href="#" target="_blank" class="text-xs" style="color: var(--accent);">
                    <i class="fas fa-link mr-1"></i> Link hasil benchmark
                </a>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>
    'use strict';

    // ==================== RANDOM NAME GENERATOR ====================
    const ADJECTIVES = ['Cepat','Kuat','Tangguh','Canggih','Cerdas','Gesit','Lincah','Andal','Sigap','Tajam','Brilian','Mantap'];
    const NOUNS = ['Pejuang','Petarung','Jagoan','Pahlawan','Juara','Maestro','Pengkode','Engineer','Hacker','Dev','Pemrogram','Pengguna'];
    function randomName() {
        const adj = ADJECTIVES[Math.floor(Math.random() * ADJECTIVES.length)];
        const noun = NOUNS[Math.floor(Math.random() * NOUNS.length)];
        const num = Math.floor(Math.random() * 900) + 100;
        return `${adj}${noun}${num}`;
    }

    // ==================== SYSTEM DETECTION ====================
    const numThreads = navigator.hardwareConcurrency || 4;
    const platform = detectPlatform();
    const autoDetectedCpu = guessDeviceFromUA();

    document.getElementById('cpuThreads').textContent = numThreads + ' Thread' + (numThreads > 1 ? 's' : '');
    document.getElementById('platformInfo').textContent = platform;
    document.getElementById('autoDetectCpu').textContent = autoDetectedCpu;

    function detectPlatform() {
        const ua = navigator.userAgent;
        if (/Windows/i.test(ua)) return 'Windows';
        if (/Mac OS X/i.test(ua)) return 'macOS';
        if (/Linux/i.test(ua) && /Android/i.test(ua)) return 'Android';
        if (/Linux/i.test(ua)) return 'Linux';
        if (/iOS/i.test(ua) || /iPhone|iPad|iPod/i.test(ua)) return 'iOS';
        if (/CrOS/i.test(ua)) return 'ChromeOS';
        return 'Unknown';
    }

    function guessDeviceFromUA() {
        const ua = navigator.userAgent;
        if (/Intel.*?((?:i[3579]|Xeon|Ryzen|EPYC)[^)]*)/i.test(ua)) return RegExp.$1.trim();
        if (/Ryzen[^;)]*/i.test(ua)) return RegExp.$0.trim();
        if (/Apple.*?(M[1234][^;)]*)/i.test(ua)) return 'Apple ' + RegExp.$1.trim();
        if (/ Snapdragon[^;)]*/i.test(ua)) return RegExp.$0.trim();
        if (/Dimensity[^;)]*/i.test(ua)) return 'MediaTek ' + RegExp.$0.trim();
        if (/Exynos[^;)]*/i.test(ua)) return 'Samsung ' + RegExp.$0.trim();
        return platform + ' Device';
    }

    // ==================== USER SESSION ====================
    let currentUserName = '';
    let currentCpuName = '';

    function initUserSession() {
        const saved = localStorage.getItem('photonbench_user');
        if (saved) {
            try {
                const data = JSON.parse(saved);
                currentUserName = data.userName || randomName();
                currentCpuName = data.cpuName || autoDetectedCpu;
                document.getElementById('currentUserDisplay').textContent = currentUserName;
                return; // skip onboarding
            } catch(e) {}
        }
        // Show onboarding
        document.getElementById('onboardCpuName').value = autoDetectedCpu;
        document.getElementById('onboardModal').classList.add('show');
    }

    function confirmOnboarding(skipName) {
        if (skipName) {
            currentUserName = randomName();
            currentCpuName = document.getElementById('onboardCpuName').value.trim() || autoDetectedCpu;
        } else {
            currentUserName = document.getElementById('onboardUserName').value.trim() || randomName();
            currentCpuName = document.getElementById('onboardCpuName').value.trim() || autoDetectedCpu;
        }
        localStorage.setItem('photonbench_user', JSON.stringify({ userName: currentUserName, cpuName: currentCpuName }));
        document.getElementById('currentUserDisplay').textContent = currentUserName;
        document.getElementById('onboardModal').classList.remove('show');
        showToast(`Halo, ${currentUserName}! Siap benchmark?`, 'var(--accent)');
    }

    // ==================== TEST SELECTION ====================
    let selectedTest = null;

    function selectTest(el) {
        document.querySelectorAll('.test-card').forEach(c => c.classList.remove('card-active'));
        el.classList.add('card-active');
        selectedTest = {
            duration: parseInt(el.dataset.duration),
            depth: parseInt(el.dataset.depth),
            samples: parseInt(el.dataset.samples),
            label: el.querySelector('.text-xs.px-2').textContent
        };
        document.getElementById('startBtn').disabled = false;
    }

    // ==================== WORKER CODE (Photon-Mapping Ray Tracer) ====================
    const WORKER_CODE = `
'use strict';
const V={
    add:(a,b)=>[a[0]+b[0],a[1]+b[1],a[2]+b[2]],
    sub:(a,b)=>[a[0]-b[0],a[1]-b[1],a[2]-b[2]],
    sc:(a,s)=>[a[0]*s,a[1]*s,a[2]*s],
    dot:(a,b)=>a[0]*b[0]+a[1]*b[1]+a[2]*b[2],
    len:(a)=>Math.sqrt(a[0]*a[0]+a[1]*a[1]+a[2]*a[2]),
    norm:(a)=>{const l=Math.sqrt(a[0]*a[0]+a[1]*a[1]+a[2]*a[2]);return l>1e-10?[a[0]/l,a[1]/l,a[2]/l]:[0,0,1];},
    refl:(d,n)=>{const dn=V.dot(d,n);return[d[0]-2*dn*n[0],d[1]-2*dn*n[1],d[2]-2*dn*n[2]];},
    mulV:(a,b)=>[a[0]*b[0],a[1]*b[1],a[2]*b[2]],
    lerp:(a,b,t)=>[a[0]+(b[0]-a[0])*t,a[1]+(b[1]-a[1])*t,a[2]+(b[2]-a[2])*t]
};

const S=[
    {c:[0,1.3,-6],r:1.3,col:[0.95,0.12,0.08],rf:0.25,sp:64},
    {c:[-3,0.85,-5.2],r:0.85,col:[0.08,0.88,0.22],rf:0.4,sp:80},
    {c:[3,0.85,-5.2],r:0.85,col:[0.12,0.22,0.95],rf:0.4,sp:80},
    {c:[1.9,0.6,-3.8],r:0.6,col:[0.95,0.88,0.08],rf:0.6,sp:128},
    {c:[-1.9,0.6,-3.8],r:0.6,col:[0.88,0.08,0.82],rf:0.6,sp:128},
    {c:[0,0.42,-2.8],r:0.42,col:[1,0.52,0.02],rf:0.5,sp:100},
    {c:[3.8,0.38,-3.2],r:0.38,col:[0.02,0.92,0.85],rf:0.35,sp:60},
    {c:[-3.8,0.38,-3.2],r:0.38,col:[0.92,0.52,0.22],rf:0.35,sp:60},
    {c:[0.5,3.5,-7],r:1.5,col:[0.85,0.85,0.9],rf:0.8,sp:200},
    {c:[-2,2.2,-8],r:0.7,col:[0.9,0.3,0.3],rf:0.5,sp:90}
];

const L=[
    {p:[-5,7,-2],c:[1,0.92,0.72],i:1.3},
    {p:[5,6,-5],c:[0.72,0.8,1],i:1.0},
    {p:[0,5,-1],c:[1,0.97,0.88],i:0.75}
];

function hitSph(o,d,s){
    const oc=V.sub(o,s.c),a=V.dot(d,d),b=2*V.dot(oc,d),c=V.dot(oc,oc)-s.r*s.r;
    const disc=b*b-4*a*c;
    if(disc<0)return-1;
    const sq=Math.sqrt(disc);
    let t=(-b-sq)/(2*a);
    if(t<0.002)t=(-b+sq)/(2*a);
    return t>0.002?t:-1;
}

function hitPlane(o,d){
    if(Math.abs(d[1])<1e-7)return-1;
    const t=-o[1]/d[1];
    return t>0.002?t:-1;
}

function findHit(o,d){
    let best=-1,hit=null;
    for(const s of S){
        const t=hitSph(o,d,s);
        if(t>0&&(best<0||t<best)){
            best=t;
            const p=V.add(o,V.sc(d,t));
            hit={t,p,n:V.norm(V.sub(p,s.c)),col:s.col,rf:s.rf,sp:s.sp};
        }
    }
    const tp=hitPlane(o,d);
    if(tp>0&&(best<0||tp<best)){
        best=tp;
        const p=V.add(o,V.sc(d,tp));
        const fx=Math.floor(p[0]*0.5),fz=Math.floor(p[2]*0.5);
        const ck=((fx+fz)%2+2)%2;
        hit={t:tp,p,n:[0,1,0],col:ck?[0.82,0.82,0.82]:[0.15,0.15,0.15],rf:0.18,sp:40};
    }
    return hit;
}

function trace(o,d,depth){
    if(depth<=0)return[0,0,0];
    const h=findHit(o,d);
    if(!h){
        const t=0.5*(d[1]+1);
        return V.lerp([0.015,0.015,0.04],[0.005,0.005,0.02],t);
    }
    let col=[0,0,0];
    const sho=V.add(h.p,V.sc(h.n,0.003));
    for(const l of L){
        const tl=V.norm(V.sub(l.p,h.p));
        const ld=V.len(V.sub(l.p,h.p));
        const sh=findHit(sho,tl);
        let sf=1;
        if(sh&&sh.t<ld)sf=0.06;
        const diff=Math.max(0,V.dot(h.n,tl));
        const att=1/(1+0.035*ld*ld);
        col=V.add(col,V.sc(V.mulV(h.col,l.c),diff*l.i*att*sf));
        const vd=V.norm(V.sc(d,-1));
        const hd=V.norm(V.add(tl,vd));
        const sp=Math.pow(Math.max(0,V.dot(h.n,hd)),h.sp);
        col=V.add(col,V.sc(l.c,sp*l.i*att*sf*0.45));
    }
    col=V.add(col,V.sc(h.col,0.035));
    if(h.rf>0&&depth>1){
        const rd=V.refl(d,h.n);
        const rc=trace(V.add(h.p,V.sc(h.n,0.003)),rd,depth-1);
        col=V.add(V.sc(col,1-h.rf),V.sc(rc,h.rf));
    }
    return[Math.min(1,col[0]),Math.min(1,col[1]),Math.min(1,col[2])];
}

function photonPass(numPhotons, maxBounces){
    let totalOps=0;
    for(let i=0;i<numPhotons;i++){
        const li=Math.floor(Math.random()*L.length);
        const light=L[li];
        const theta=Math.random()*Math.PI*2;
        const phi=Math.acos(Math.random());
        const dir=V.norm([Math.sin(phi)*Math.cos(theta),-Math.cos(phi),Math.sin(phi)*Math.sin(theta)]);
        let o=[...light.p];
        let energy=[light.c[0]*light.i,light.c[1]*light.i,light.c[2]*light.i];
        for(let b=0;b<maxBounces;b++){
            const h=findHit(o,dir);
            if(!h)break;
            totalOps++;
            energy=V.mulV(energy,h.col);
            energy=V.sc(energy,0.7);
            if(energy[0]+energy[1]+energy[2]<0.01)break;
            const nt=Math.random()*Math.PI*2;
            const np=Math.acos(Math.random());
            const nd=V.norm([Math.sin(np)*Math.cos(nt),Math.cos(np),Math.sin(np)*Math.sin(nt)]);
            if(V.dot(nd,h.n)<0){nd[0]=-nd[0];nd[1]=-nd[1];nd[2]=-nd[2];}
            o=V.add(h.p,V.sc(h.n,0.003));
            dir[0]=nd[0];dir[1]=nd[1];dir[2]=nd[2];
        }
    }
    return totalOps;
}

self.onmessage=function(e){
    const{startRow,endRow,width,height,maxDepth,samples,camPos,fov,taskId,photonsPerPass}=e.data;
    const pix=new Float32Array((endRow-startRow)*width*3);
    let rays=0;
    const ar=width/height;
    const fh=Math.tan(fov*Math.PI/360);
    const fw=fh*ar;
    const photonOps=photonPass(photonsPerPass||500,maxDepth);
    for(let y=startRow;y<endRow;y++){
        for(let x=0;x<width;x++){
            let c=[0,0,0];
            for(let s=0;s<samples;s++){
                const u=((x+Math.random())/width)*2-1;
                const v=1-((y+Math.random())/height)*2;
                const dir=V.norm([u*fw,v*fh,-1]);
                const sc=trace(camPos,dir,maxDepth);
                c[0]+=sc[0];c[1]+=sc[1];c[2]+=sc[2];
                rays++;
            }
            const idx=((y-startRow)*width+x)*3;
            pix[idx]=c[0]/samples;
            pix[idx+1]=c[1]/samples;
            pix[idx+2]=c[2]/samples;
        }
        if((y-startRow)%6===0){
            self.postMessage({type:'progress',taskId,rays,photonOps,row:y-startRow,total:endRow-startRow});
        }
    }
    self.postMessage({type:'done',taskId,pixels:pix.buffer,startRow,endRow,width,rays,photonOps},[pix.buffer]);
};
`;

    // ==================== BENCHMARK ENGINE ====================
    const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    const RENDER_W = isMobile ? 320 : 480;
    const RENDER_H = isMobile ? 240 : 360;

    let workers = [];
    let running = false;
    let totalRays = 0;
    let totalPhotonOps = 0;
    let startTime = 0;
    let passCount = 0;
    let currentScore = 0;
    let benchmarkDuration = 0;
    let updateInterval = null;
    let workerBlobUrl = null;
    let lastResult = null;

    const canvas = document.getElementById('renderCanvas');
    canvas.width = RENDER_W;
    canvas.height = RENDER_H;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, RENDER_W, RENDER_H);

    function buildThreadBars() {
        const container = document.getElementById('threadBars');
        container.innerHTML = '';
        const maxShow = Math.min(numThreads, 16);
        for (let i = 0; i < maxShow; i++) {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2';
            row.innerHTML = `
                <span class="text-xs w-8 text-right" style="color: var(--text-muted);">T${i}</span>
                <div class="thread-bar flex-1"><div class="thread-bar-fill" id="tbar${i}" style="width: 0%;"></div></div>
            `;
            container.appendChild(row);
        }
        if (numThreads > 16) {
            const note = document.createElement('div');
            note.className = 'text-xs mt-1';
            note.style.color = 'var(--text-muted)';
            note.textContent = `+${numThreads - 16} thread lainnya`;
            container.appendChild(note);
        }
        document.getElementById('threadCount').textContent = numThreads + ' thread aktif';
    }

    function createWorkers() {
        terminateWorkers();
        const blob = new Blob([WORKER_CODE], { type: 'application/javascript' });
        workerBlobUrl = URL.createObjectURL(blob);
        for (let i = 0; i < numThreads; i++) {
            workers.push(new Worker(workerBlobUrl));
        }
    }

    function terminateWorkers() {
        workers.forEach(w => w.terminate());
        workers = [];
        if (workerBlobUrl) { URL.revokeObjectURL(workerBlobUrl); workerBlobUrl = null; }
    }

    function startBenchmark() {
        if (!selectedTest || running) return;

        benchmarkDuration = selectedTest.duration * 60;
        totalRays = 0;
        totalPhotonOps = 0;
        passCount = 0;
        currentScore = 0;
        running = true;
        lastResult = null;

        // Open benchmark modal
        document.getElementById('benchmarkModal').classList.add('show');

        const wrapper = document.getElementById('canvasWrapper');
        wrapper.classList.add(selectedTest.duration >= 25 ? 'stress-active' : 'active');
        document.getElementById('modeLabel').textContent = selectedTest.label;
        document.getElementById('modeLabel').style.color = selectedTest.duration >= 25 ? 'var(--danger)' : selectedTest.duration >= 10 ? '#F97316' : 'var(--accent)';

        buildThreadBars();
        createWorkers();

        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, RENDER_W, RENDER_H);

        startTime = performance.now();
        runPass(selectedTest.depth, selectedTest.samples, selectedTest.duration);

        updateInterval = setInterval(updateUI, 200);
    }

    function runPass(maxDepth, samples, duration) {
        let completed = 0;
        const rowsPerWorker = Math.ceil(RENDER_H / numThreads);
        const photonsPerPass = duration >= 25 ? 2000 : duration >= 10 ? 1000 : 500;

        document.getElementById('renderInfo').textContent =
            `Photon-Mapping Ray Tracer — Pass #${passCount + 1} | Depth: ${maxDepth} | Samples: ${samples}`;

        for (let i = 0; i < numThreads; i++) {
            const startRow = i * rowsPerWorker;
            const endRow = Math.min(startRow + rowsPerWorker, RENDER_H);
            if (startRow >= RENDER_H) { completed++; continue; }

            workers[i].onmessage = function(e) {
                if (!running) return;

                if (e.data.type === 'progress') {
                    const pct = Math.min(100, (e.data.row / e.data.total) * 100);
                    const bar = document.getElementById('tbar' + e.data.taskId);
                    if (bar) bar.style.width = pct + '%';
                }

                if (e.data.type === 'done') {
                    totalRays += e.data.rays;
                    totalPhotonOps += e.data.photonOps;

                    const pixels = new Float32Array(e.data.pixels);
                    const numPx = (e.data.endRow - e.data.startRow) * e.data.width;
                    const imgData = ctx.createImageData(e.data.width, e.data.endRow - e.data.startRow);
                    const gamma = 1 / 2.2;
                    for (let p = 0; p < numPx; p++) {
                        imgData.data[p * 4]     = Math.min(255, Math.max(0, Math.pow(pixels[p * 3], gamma)) * 255);
                        imgData.data[p * 4 + 1] = Math.min(255, Math.max(0, Math.pow(pixels[p * 3 + 1], gamma)) * 255);
                        imgData.data[p * 4 + 2] = Math.min(255, Math.max(0, Math.pow(pixels[p * 3 + 2], gamma)) * 255);
                        imgData.data[p * 4 + 3] = 255;
                    }
                    ctx.putImageData(imgData, 0, e.data.startRow);

                    const bar = document.getElementById('tbar' + e.data.taskId);
                    if (bar) bar.style.width = '0%';

                    completed++;
                    if (completed >= numThreads) {
                        passCount++;
                        if (running) runPass(maxDepth, samples, duration);
                    }
                }
            };

            workers[i].postMessage({
                startRow, endRow,
                width: RENDER_W, height: RENDER_H,
                maxDepth, samples,
                camPos: [0, 2.8, 5.5],
                fov: 55,
                taskId: i,
                photonsPerPass
            });
        }
    }

    function updateUI() {
        const elapsed = (performance.now() - startTime) / 1000;
        const timePct = Math.min(100, (elapsed / benchmarkDuration) * 100);

        const mins = Math.floor(elapsed / 60);
        const secs = Math.floor(elapsed % 60);
        document.getElementById('statTime').textContent =
            String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

        const totalOps = totalRays + totalPhotonOps;
        currentScore = elapsed > 0 ? Math.round(totalOps / elapsed) : 0;
        document.getElementById('statScore').textContent = formatNumber(currentScore);
        document.getElementById('statRays').textContent = formatNumber(totalRays);
        document.getElementById('statPass').textContent = passCount;
        document.getElementById('timeProgress').style.width = timePct + '%';

        if (elapsed >= benchmarkDuration) finishBenchmark(elapsed);
    }

    function finishBenchmark(elapsed) {
        running = false;
        clearInterval(updateInterval);
        terminateWorkers();

        const wrapper = document.getElementById('canvasWrapper');
        wrapper.classList.remove('active', 'stress-active');

        const totalOps = totalRays + totalPhotonOps;
        const finalScore = elapsed > 0 ? Math.round(totalOps / elapsed) : 0;
        const points = Math.round((finalScore / Math.max(1, numThreads)) * 10);

        lastResult = {
            user_name: currentUserName,
            device_name: currentCpuName,
            threads: numThreads,
            duration: selectedTest.duration,
            score: finalScore,
            points: points,
            platform: platform,
            rays: totalRays,
            passes: passCount,
            elapsed: elapsed
        };

        saveResult(lastResult);

        // Close benchmark modal, open results modal
        document.getElementById('benchmarkModal').classList.remove('show');
        document.getElementById('resultsModal').classList.add('show');

        animateCounter('finalScore', finalScore, 1500);
        animateCounter('finalPoints', points, 1200);
        document.getElementById('finalDuration').textContent = selectedTest.duration + 'm';
        document.getElementById('finalRays').textContent = formatNumber(totalRays);
        document.getElementById('finalPasses').textContent = passCount;

        setTimeout(() => { loadLeaderboardPreview(); }, 600);
    }

    function cancelBenchmark() {
        running = false;
        clearInterval(updateInterval);
        terminateWorkers();

        const wrapper = document.getElementById('canvasWrapper');
        wrapper.classList.remove('active', 'stress-active');

        document.getElementById('benchmarkModal').classList.remove('show');
        showToast('Benchmark dibatalkan', 'var(--text-muted)');
    }

    function resetBenchmark() {
        document.getElementById('resultsModal').classList.remove('show');
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, RENDER_W, RENDER_H);
        selectedTest = null;
        document.querySelectorAll('.test-card').forEach(c => c.classList.remove('card-active'));
        document.getElementById('startBtn').disabled = true;
    }

    // ==================== SAVE & LOAD ====================
    async function saveResult(result) {
        try {
            const resp = await fetch('?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_name: result.user_name,
                    device_name: result.device_name,
                    threads: result.threads,
                    duration: result.duration * 60,
                    score: result.score,
                    platform: result.platform
                })
            });
            const data = await resp.json();
            if (data.success) {
                lastResult.shareHash = data.hash;
                lastResult.serverPoints = data.points;
            }
        } catch (e) { console.warn('Gagal menyimpan hasil:', e); }
    }

    // ==================== LEADERBOARD PREVIEW (top 5) ====================
    async function loadLeaderboardPreview() {
        try {
            const resp = await fetch('?action=leaderboard&limit=5');
            const data = await resp.json();
            renderLeaderboardRows('leaderboardPreviewBody', data, 7, false);
        } catch(e) {
            document.getElementById('leaderboardPreviewBody').innerHTML =
                '<tr><td colspan="7" class="text-center py-6" style="color: var(--text-muted);">Gagal memuat</td></tr>';
        }
    }

    // ==================== LEADERBOARD MODAL ====================
    function openLeaderboardModal() {
        document.getElementById('leaderboardModal').classList.add('show');
        loadLeaderboardModal();
    }
    function closeLeaderboardModal() {
        document.getElementById('leaderboardModal').classList.remove('show');
    }
    async function loadLeaderboardModal() {
        try {
            const resp = await fetch('?action=leaderboard&limit=10');
            const data = await resp.json();
            renderLeaderboardRows('leaderboardModalBody', data, 8, true);
        } catch(e) {
            document.getElementById('leaderboardModalBody').innerHTML =
                '<tr><td colspan="8" class="text-center py-6" style="color: var(--text-muted);">Gagal memuat</td></tr>';
        }
    }

    function renderLeaderboardRows(tbodyId, data, colspan, showDate) {
        const tbody = document.getElementById(tbodyId);
        if (!data || data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center py-8" style="color: var(--text-muted);">Belum ada data benchmark</td></tr>`;
            return;
        }
        tbody.innerHTML = data.map((row, i) => {
            const rank = i + 1;
            const rankClass = rank === 1 ? 'rank-1' : rank === 2 ? 'rank-2' : rank === 3 ? 'rank-3' : 'rank-other';
            const durMin = Math.round(row.test_duration / 60);
            const dateStr = row.created_at ? new Date(row.created_at + 'Z').toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
            return `
                <tr>
                    <td><div class="rank-badge ${rankClass}">${rank}</div></td>
                    <td class="font-medium">${escHtml(row.user_name || 'Anonymous')}</td>
                    <td style="color: var(--text-secondary);">${escHtml(row.device_name)}</td>
                    <td class="text-center hidden sm:table-cell" style="color: var(--text-secondary);">${row.cpu_threads}T</td>
                    <td class="text-center hidden sm:table-cell" style="color: var(--text-secondary);">${durMin}m</td>
                    <td class="text-right font-display font-semibold" style="color: var(--accent);">${formatNumber(Math.round(row.score))}</td>
                    <td class="text-right font-display font-bold" style="color: var(--success);">${formatNumber(row.points)}</td>
                    ${showDate ? `<td class="text-right text-xs hidden md:table-cell" style="color: var(--text-muted);">${dateStr}</td>` : ''}
                </tr>
            `;
        }).join('');
    }

    // ==================== HISTORY MODAL ====================
    let historyPage = 0;
    const HISTORY_PER_PAGE = 15;
    let historyTotal = 0;

    function openHistoryModal() {
        document.getElementById('historyModal').classList.add('show');
        historyPage = 0;
        loadHistoryPage(0);
    }
    function closeHistoryModal() {
        document.getElementById('historyModal').classList.remove('show');
    }

    async function loadHistoryPage(offset) {
        const tbody = document.getElementById('historyModalBody');
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-6" style="color: var(--text-muted);"><i class="fas fa-spinner fa-spin mr-2"></i>Memuat...</td></tr>';
        try {
            const resp = await fetch(`?action=history&limit=${HISTORY_PER_PAGE}&offset=${offset}`);
            const result = await resp.json();
            historyTotal = result.total;
            renderHistoryRows(result.data, offset);
            renderHistoryPagination(offset, result.total);
            const label = document.getElementById('historyTotalLabel');
            label.textContent = `(${result.total} total)`;
        } catch(e) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-6" style="color: var(--text-muted);">Gagal memuat</td></tr>';
        }
    }

    function renderHistoryRows(data, offset) {
        const tbody = document.getElementById('historyModalBody');
        if (!data || data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-8" style="color: var(--text-muted);">Belum ada data</td></tr>';
            return;
        }
        tbody.innerHTML = data.map((row, i) => {
            const globalNum = offset + i + 1;
            const durMin = Math.round(row.test_duration / 60);
            const dateStr = row.created_at ? new Date(row.created_at + 'Z').toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '-';
            return `
                <tr>
                    <td style="color: var(--text-muted);">${globalNum}</td>
                    <td class="font-medium">${escHtml(row.user_name || 'Anonymous')}</td>
                    <td style="color: var(--text-secondary);">${escHtml(row.device_name)}</td>
                    <td class="text-center hidden sm:table-cell" style="color: var(--text-secondary);">${row.cpu_threads}T</td>
                    <td class="text-center hidden sm:table-cell" style="color: var(--text-secondary);">${durMin}m</td>
                    <td class="text-right font-display font-semibold" style="color: var(--accent);">${formatNumber(Math.round(row.score))}</td>
                    <td class="text-right font-display font-bold" style="color: var(--success);">${formatNumber(row.points)}</td>
                    <td class="text-right text-xs hidden md:table-cell" style="color: var(--text-muted);">${dateStr}</td>
                </tr>
            `;
        }).join('');
    }

    function renderHistoryPagination(offset, total) {
        const totalPages = Math.ceil(total / HISTORY_PER_PAGE);
        const currentPage = Math.floor(offset / HISTORY_PER_PAGE);
        const container = document.getElementById('historyPagination');
        const pageInfo = document.getElementById('historyPageInfo');
        pageInfo.textContent = `Halaman ${currentPage + 1} dari ${totalPages || 1}`;

        let html = '';
        // Prev
        html += `<button class="page-btn" onclick="loadHistoryPage(${Math.max(0, (currentPage-1)*HISTORY_PER_PAGE)})" ${currentPage === 0 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i>
        </button>`;

        // Page numbers (show max 5 around current)
        const startPage = Math.max(0, currentPage - 2);
        const endPage = Math.min(totalPages - 1, currentPage + 2);
        for (let p = startPage; p <= endPage; p++) {
            html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" onclick="loadHistoryPage(${p * HISTORY_PER_PAGE})">${p + 1}</button>`;
        }

        // Next
        html += `<button class="page-btn" onclick="loadHistoryPage(${(currentPage+1)*HISTORY_PER_PAGE})" ${currentPage >= totalPages - 1 ? 'disabled' : ''}>
            <i class="fas fa-chevron-right"></i>
        </button>`;

        container.innerHTML = html;
    }

    // ==================== SHARE ====================
    function showShareModal() {
        if (!lastResult) return;
        const hash = lastResult.shareHash || '--------';
        const shareUrl = `${location.origin}${location.pathname}?share=${hash}`;
        const text = `PhotonBench — CPU Benchmark Result
━━━━━━━━━━━━━━━━━━━━━━━━
Pengguna: ${lastResult.user_name}
Skor: ${formatNumber(lastResult.score)} ops/sec
Poin: ${formatNumber(lastResult.points || lastResult.serverPoints || 0)}
CPU / Perangkat: ${lastResult.device_name}
CPU Threads: ${lastResult.threads}
Durasi: ${lastResult.duration} menit
Total Rays: ${formatNumber(lastResult.rays)}
Pass Selesai: ${lastResult.passes}
Platform: ${lastResult.platform}
━━━━━━━━━━━━━━━━━━━━━━━━
benchmark: ${shareUrl}
Powered by PhotonBench — bench.pratamadigital.com
GitHub: https://github.com/retno-W/cpu_benchmark
License: GPLv3`;

        document.getElementById('shareText').value = text;
        document.getElementById('shareLink').href = shareUrl;
        document.getElementById('shareModal').classList.add('show');
    }

    function closeShareModal() { document.getElementById('shareModal').classList.remove('show'); }
    function copyShareText() {
        const text = document.getElementById('shareText').value;
        navigator.clipboard.writeText(text).then(() => {
            showToast('Hasil berhasil disalin ke clipboard!', 'var(--success)');
        }).catch(() => {
            document.getElementById('shareText').select();
            document.execCommand('copy');
            showToast('Hasil berhasil disalin!', 'var(--success)');
        });
    }

    // ==================== UTILITIES ====================
    function formatNumber(n) {
        if (n === undefined || n === null) return '0';
        return Math.round(n).toLocaleString('id-ID');
    }
    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    function animateCounter(elementId, target, duration) {
        const el = document.getElementById(elementId);
        const start = performance.now();
        function update(now) {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = formatNumber(Math.round(target * eased));
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    }
    function showToast(msg, color) {
        const toast = document.getElementById('toast');
        toast.innerHTML = `<i class="fas fa-circle-check mr-2" style="color: ${color || 'var(--success)'}"></i>${msg}`;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }

    // ==================== MODAL CLOSE ON OVERLAY CLICK ====================
    ['leaderboardModal','historyModal','shareModal'].forEach(id => {
        document.getElementById(id).addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            ['leaderboardModal','historyModal','shareModal','benchmarkModal'].forEach(id => {
                document.getElementById(id).classList.remove('show');
            });
        }
    });

    window.addEventListener('beforeunload', function(e) {
        if (running) { e.preventDefault(); e.returnValue = 'Benchmark sedang berjalan. Yakin ingin keluar?'; }
    });

    // ==================== INIT ====================
    initUserSession();
    loadLeaderboardPreview();

    <?php if ($sharedResult): ?>
    setTimeout(() => {
        document.getElementById('sharedBanner')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 300);
    <?php endif; ?>
    </script>
</body>
</html>
