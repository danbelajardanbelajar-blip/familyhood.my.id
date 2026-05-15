<?php
ob_start();

//bagus k
require_once 'init_session.php';
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

// --- 1. DEFINISIKAN ACTION DULU (WAJIB DI PALING ATAS) ---
$action = $_GET['action'] ?? 'home';

// --- 2. LOGIKA RESET TREE ---
if ($action === 'reset_tree') {
    unset($_SESSION['current_tree_id']);
    unset($_SESSION['current_tree_name']);
    session_write_close();
    header("Location: ?action=home");
    exit;
}

// --- LOGIKA LOGOUT ---
if ($action === 'logout') {
    $_SESSION = [];
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- CEK LOGIN ---
if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    $returnTo = 'index.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $returnTo .= '?' . $_SERVER['QUERY_STRING'];
    }
    header("Location: login.php?return_to=" . rawurlencode($returnTo));
    exit;
}

// Cek Admin
$myUserId = $_SESSION['user_id'] ?? 0;
$myRole   = $_SESSION['role'] ?? 'user';
$isAdmin  = ($myRole === 'admin');

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Koneksi database
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_errno) die('Gagal konek database: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');
if ($mysqli->connect_errno) die('Gagal konek database: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

// ... (Baris 62)
$mysqli->set_charset('utf8mb4');

// --- 5b. AJAX LIVE SEARCH PERSONS ---
if ($action === 'search_persons') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', 0);

    $q      = trim($_GET['q'] ?? '');
    $uid    = intval($_SESSION['user_id'] ?? 0);
    $treeId = intval($_SESSION['current_tree_id'] ?? 0);

    // Jika admin sedang melihat user lain, pakai tree yang sedang dilihat
    if (isset($_SESSION['admin_viewing_tree_id'])) {
        $treeId = intval($_SESSION['admin_viewing_tree_id']);
    }

    if ($uid === 0 || $treeId === 0 || mb_strlen($q) < 1) {
        echo json_encode([]); exit;
    }

    $like = '%' . $q . '%';
    $stmt = $mysqli->prepare(
        "SELECT id, name, gender, is_alive
         FROM persons
         WHERE tree_id = ? AND name LIKE ?
         ORDER BY LOCATE(?, name), name
         LIMIT 12"
    );
    $stmt->bind_param('iss', $treeId, $like, $q);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 6. AJAX GET COLLABORATORS (REVISI ANTI-ERROR 500) ---
if ($action === 'get_collaborators') {
    // 1. Bersihkan buffer output
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    
    // 2. Matikan display error agar tidak merusak JSON, tapi log errornya
    ini_set('display_errors', 0);
    
    $tid = intval($_GET['tree_id'] ?? 0);
    $rows = [];
    $myId = $_SESSION['user_id'] ?? 0;

    if ($myId == 0) { echo json_encode([]); exit; }

    // 3. Cek Otorisasi (Owner)
    $isAuthorized = false;

    $stmtAuth = $mysqli->prepare("SELECT id FROM family_trees WHERE id = ? AND user_id = ?");
    $stmtAuth->bind_param('ii', $tid, $myId);
    $stmtAuth->execute();
    $resultAuth = $stmtAuth->get_result();
    if ($resultAuth && $resultAuth->num_rows > 0) {
        $isAuthorized = true;
    }
    $stmtAuth->close();

    if (!$isAuthorized) {
        $stmtAuth2 = $mysqli->prepare("SELECT id FROM tree_collaborators WHERE tree_id = ? AND user_id = ?");
        $stmtAuth2->bind_param('ii', $tid, $myId);
        $stmtAuth2->execute();
        $resultAuth2 = $stmtAuth2->get_result();
        if ($resultAuth2 && $resultAuth2->num_rows > 0) {
            $isAuthorized = true;
        }
        $stmtAuth2->close();
    }

    if ($isAuthorized) {
        $stmt = $mysqli->prepare("SELECT c.user_id, u.name, u.email
                FROM tree_collaborators c
                JOIN users u ON u.id = c.user_id
                WHERE c.tree_id = ?");
        $stmt->bind_param('i', $tid);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $r['initial'] = strtoupper(substr($r['name'], 0, 1));
                $rows[] = $r;
            }
        }
        $stmt->close();
    }

    echo json_encode($rows);
    exit;
}

// --- 7. AJAX GET VIEWABLE TREES (Admin Panel) ---
if ($action === 'get_viewable_trees' && $isAdmin) {
    // 1. Bersihkan buffer output
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    // 2. Matikan display error
    ini_set('display_errors', 0);

    $targetId = intval($_GET['user_id'] ?? 0);
    $rows = [];

    if ($targetId > 0) {
        $stmt = $mysqli->prepare("SELECT id, name FROM family_trees WHERE user_id = ? AND allow_admin_view = 1 ORDER BY name ASC");
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }

        $stmt->close();
    }

    echo json_encode($rows);
    exit;
}

// --- 8. AJAX GET USER TREES (For Import) ---
if ($action === 'get_user_trees' && $isAdmin) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    ini_set('display_errors', 0);

    $userId = intval($_GET['user_id'] ?? 0);
    $rows = [];

    if ($userId > 0) {
        $stmt = $mysqli->prepare("SELECT id, name FROM family_trees WHERE user_id = ? ORDER BY name ASC");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }

        $stmt->close();
    }

    echo json_encode($rows);
    exit;
}


// --- LOGIKA AKTIVASI AKUN (Saat diklik dari email) ---
if ($action === 'activate') {
    $activationCode = $_GET['code'] ?? '';
    
    // Matikan output buffering agar tidak merusak header redirect jika error
    while (ob_get_level()) { ob_end_clean(); }

    // Koneksi ke DB lokal untuk proses aktivasi
    $mysqli_local = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    
    if (!empty($activationCode)) {
        // Cari user berdasarkan kode aktivasi dan pastikan is_active=0
        $stmt = $mysqli_local->prepare("SELECT id, email FROM users WHERE activation_code = ? AND is_active = 0");
        $stmt->bind_param("s", $activationCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $u = $result->fetch_assoc();
        $stmt->close();
        
        if ($u) {
            // AKTIFKAN AKUN
            $userId = $u['id'];
            $stmtUpdate = $mysqli_local->prepare("UPDATE users SET is_active = 1, activation_code = NULL WHERE id = ?");
            $stmtUpdate->bind_param('i', $userId);
            $stmtUpdate->execute();
            $stmtUpdate->close();

            $_SESSION['status_success'] = "Akun Anda berhasil diaktifkan kembali! Anda kini dapat login.";
        } else {
            $_SESSION['status_error'] = "Gagal: Kode aktivasi tidak valid atau akun sudah aktif.";
        }
    } else {
        $_SESSION['status_error'] = "Kode aktivasi tidak ditemukan.";
    }
    
    $mysqli_local->close();
    // Redirect ke halaman login (agar session pesan tampil)
    header("Location: login.php");
    exit;
}
// -----------------------------------------------------

// --- FUNGSI BANTUAN: KIRIM NOTIFIKASI ---
function fh_send_notification($mysqli, $userId, $title, $message, $type='info') {
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $userId, $title, $message, $type);
    $stmt->execute();
    $stmt->close();
}


// --- AUTOMATIC TRIGGERS (Saat Halaman Dibuka) ---

// 1. Cek Selamat Datang (Jika User Baru belum punya notifikasi sama sekali)
$checkWelcome = $mysqli->query("SELECT id FROM notifications WHERE user_id = $myUserId LIMIT 1");
    if ($checkWelcome->num_rows == 0) {
    fh_send_notification($mysqli, $myUserId, "Selamat Datang! 🎉", "Selamat datang di FamilyHood! Mulailah dengan menambahkan diri Anda atau orang tua Anda di menu 'Tambah'.", "info");
}

// 2. Cek Ulang Tahun Keluarga (Hari Ini)
// Hanya cek jika user membuka halaman Dashboard/Home
if ($action === 'home') {
    $todayMD = date('m-d');
    $yearNow = date('Y');
    
    // Cari anggota keluarga (milik user ini) yang ulang tahun hari ini
    $sqlBday = "SELECT id, name, date_of_birth FROM persons WHERE user_id = $myUserId AND DATE_FORMAT(date_of_birth, '%m-%d') = '$todayMD'";
    $resBday = $mysqli->query($sqlBday);
    
    while ($p = $resBday->fetch_assoc()) {
        // Cek apakah sudah dikirimi notif tahun ini? (Supaya tidak spam tiap refresh)
        $msgTitle = "Selamat Ulang Tahun, " . $p['name'] . "! 🎂";
        $checkSent = $mysqli->query("SELECT id FROM notifications WHERE user_id = $myUserId AND title = '$msgTitle' AND YEAR(created_at) = '$yearNow'");
        
        if ($checkSent->num_rows == 0) {
            // Hitung Umur
            $dob = new DateTime($p['date_of_birth']);
            $today = new DateTime();
            $age = $today->diff($dob)->y;
            
            fh_send_notification($mysqli, $myUserId, $msgTitle, "Hari ini " . $p['name'] . " berulang tahun yang ke-$age. Jangan lupa ucapkan selamat!", "birthday");
        }
    }
}

// --- LOGIKA "SIAPA MELIHAT SIAPA" ---
// Default: Kita melihat data kita sendiri
// --- LOGIKA TARGET USER (UPDATED) ---
// Default: Gunakan ID sendiri, KECUALI sedang membuka tree orang lain (sebagai kolaborator)
$targetUserId = $myUserId;

if (isset($_SESSION['current_tree_owner_id']) && isset($_SESSION['current_tree_id'])) {
    // Jika sedang dalam mode Tree aktif, semua operasi CRUD mengarah ke Pemilik Asli
    $targetUserId = $_SESSION['current_tree_owner_id'];
}
$isViewingOthers = false;

// Khusus Admin: Bisa melihat data orang lain jika diizinkan
if ($isAdmin && isset($_GET['view_user_id']) && isset($_GET['view_tree_id'])) {
    $requestedId   = intval($_GET['view_user_id']);
    $requestedTree = intval($_GET['view_tree_id']);
    
    if ($requestedId !== $myUserId) {
        // Cek 1: Apakah user target ini mengizinkan admin melihat tree ini?
        $check = $mysqli->query("SELECT t.user_id, t.name AS tree_name, u.name AS user_name 
                                FROM family_trees t 
                                JOIN users u ON u.id = t.user_id
                                WHERE t.id=$requestedTree 
                                AND t.user_id=$requestedId 
                                AND t.allow_admin_view = 1")->fetch_assoc();
        
        // Jika diizinkan:
        if ($check) {
            $targetUserId = $requestedId;
            $isViewingOthers = true;
            $targetUserName = $check['user_name'];
            
            // Simpan konteks tree yang sedang diintip ke SESSION ADMIN
            $_SESSION['admin_viewing_tree_id']   = $requestedTree;
            $_SESSION['admin_viewing_tree_name'] = $check['tree_name'];
            $_SESSION['admin_viewing_user_id']   = $requestedId;
            
        } else {
            die("Akses Ditolak: User ini tidak mengizinkan Admin melihat pohon keluarga ($requestedTree).");
        }
    }
}

// Jika admin sudah dalam mode intip, lanjutkan mode tersebut pada request berikutnya
if ($isAdmin && !$isViewingOthers && isset($_SESSION['admin_viewing_tree_id']) && isset($_SESSION['admin_viewing_user_id'])) {
    $isViewingOthers = true;
    $targetUserId = intval($_SESSION['admin_viewing_user_id']);
}

// --- HELPER FUNCTIONS ---

function fh_render_single_web_card($p, $currentActiveId) {
    $photoUrl = 'assets/l.png';
    if (($p['gender']??'') === 'P') $photoUrl = 'assets/p.png';
    if (!empty($p['photo']) && file_exists(__DIR__ . '/' . $p['photo'])) {
        $photoUrl = $p['photo'];
    }
    
    $focusedClass = ($p['id'] == $currentActiveId) ? 'node-focused' : '';
    $deceasedClass = (($p['is_alive'] ?? 1) == 0) ? 'node-deceased' : '';
    
    echo '<div class="tree-node-web '.$deceasedClass.'">';
    echo '  <div class="node-card-content '.$focusedClass.'">';
    
    // --- 1. TOMBOL EDIT (KIRI ATAS) ---
    // Mengarah langsung ke halaman edit profil
    echo '      <a href="?action=bio&id='.$p['id'].'&mode=edit" class="tree-action-btn btn-tree-edit" title="Edit Profil">✏️</a>';

    // --- 2. TOMBOL TAMBAH RELASI (KANAN ATAS) ---
    // Membuka Modal via Javascript
    // Kita kirim ID dan Nama orang tersebut ke fungsi JS
    $safeName = htmlspecialchars(addslashes($p['name']), ENT_QUOTES);
    echo '      <a href="javascript:void(0);" onclick="event.preventDefault(); event.stopPropagation(); openRelationModal('.$p['id'].', \''.$safeName.'\'); return false;" class="tree-action-btn btn-tree-add" title="Tambah Relasi">+</a>';
    
    // LINK FOTO (Untuk Fokus Tree)
    echo '      <a href="?action=tree&focus_id='.$p['id'].'" title="Lihat Relasi Keluarga">';
    echo '          <img src="'.$photoUrl.'" class="web-photo">';
    echo '      </a>';
    
    // LINK NAMA (Untuk Buka Biodata)
    $nameDisplay = htmlspecialchars($p['name']);
    if ((($p['is_alive'] ?? 1) == 0)) {
        $nameDisplay = 'alm. ' . $nameDisplay;
    }
    echo '      <a href="?action=bio&id='.$p['id'].'&mode=view" class="web-name" style="text-decoration:none; color:inherit;">';
    echo            $nameDisplay;
    echo '      </a>';
    
    echo '  </div>';
    echo '</div>';
}

function fh_get_persons_by_tree($mysqli, $treeId) {
    $rows = [];
    if (!$mysqli) return $rows;
    // Filter berdasarkan tree_id
    $sql = "SELECT id, name, place_of_birth, date_of_birth, gender, is_alive, note, created_at, updated_at, photo 
            FROM persons 
            WHERE tree_id = $treeId 
            ORDER BY name ASC";
    if ($result = $mysqli->query($sql)) {
        while ($row = $result->fetch_assoc()) { $rows[] = $row; }
        $result->free();
    }
    return $rows;
}

// --- LOGIKA GENERASI ---
// GANTI FUNGSI INI SEPENUHNYA:
function fh_compute_generations($mysqli, $treeId) {
    $persons = []; 
    $childParents = []; $parentChildren = []; 
    $spouses = []; $parents = []; $children = [];

    if (!$mysqli) return [[], 0, 0, [], [], [], []];

    // 1. Ambil Data Orang di Tree Ini
    $sqlPersons = "SELECT id, name, date_of_birth, gender, photo, is_alive, child_order FROM persons WHERE tree_id = $treeId ORDER BY id ASC";
    if ($res = $mysqli->query($sqlPersons)) {
        while ($row = $res->fetch_assoc()) {
            $id = (int)$row['id'];
            $persons[$id] = [
                'id' => $id, 
                'name' => $row['name'], 
                'dob' => $row['date_of_birth'] ?? null,
                'gender' => $row['gender'], 
                'photo' => $row['photo'],
                'is_alive' => $row['is_alive'] ?? 1,
                'child_order' => $row['child_order'] ?? null
            ];
        }
        $res->free();
    }
    
    // Jika tidak ada orang, kembalikan kosong
    if (empty($persons)) return [[], 0, 0, [], [], [], []];

    // 2. Kumpulkan ID orang untuk filter relasi
    $personIds = array_keys($persons);
    $idsStr = implode(',', $personIds); // Contoh hasil: "10,11,12"

    // 3. Ambil Relasi Orang Tua (Filter by Person ID, BUKAN User ID)
    // (Termasuk kemungkinan relasi 'anak' / parent->child dari data lama)
    $sqlRelParent = "SELECT person_id, related_person_id, relation_type FROM relations 
                     WHERE person_id IN ($idsStr) OR related_person_id IN ($idsStr)";
    
    if ($res = $mysqli->query($sqlRelParent)) {
        while ($row = $res->fetch_assoc()) {
            $relationType = strtolower(trim($row['relation_type']));
            $child = null;
            $parent = null;

            if ($relationType === 'ayah' || $relationType === 'ibu') {
                $child = (int)$row['person_id'];
                $parent = (int)$row['related_person_id'];
            } elseif ($relationType === 'anak') {
                $parent = (int)$row['person_id'];
                $child = (int)$row['related_person_id'];
            } else {
                continue;
            }

            // Validasi: Pastikan kedua orang ada di tree ini
            if ($child > 0 && $parent > 0 && isset($persons[$child]) && isset($persons[$parent])) {
                if (!isset($childParents[$child])) $childParents[$child] = [];
                $childParents[$child][$parent] = true;

                if (!isset($parentChildren[$parent])) $parentChildren[$parent] = [];
                $parentChildren[$parent][$child] = true;

                $parents[$parent] = true;
                $children[$child] = true;
            }
        }
        $res->free();
    }

    // 4. Ambil Relasi Pasangan (Filter by Person ID)
    $sqlRelSpouse = "SELECT person_id, related_person_id, spouse_order, IFNULL(is_divorced,0) as is_divorced FROM relations 
                     WHERE person_id IN ($idsStr) AND relation_type = 'pasangan'";
                     
    if ($res = $mysqli->query($sqlRelSpouse)) {
        while ($row = $res->fetch_assoc()) {
            $a = (int)$row['person_id']; 
            $b = (int)$row['related_person_id'];
            $order = (int)($row['spouse_order'] ?? 0);
            
            if (isset($persons[$a]) && isset($persons[$b])) {
                if (!isset($spouses[$a])) $spouses[$a] = [];
                if (!isset($spouses[$b])) $spouses[$b] = [];
                $isDivorced = (bool)($row['is_divorced'] ?? 0);
                $spouses[$a][$b] = ['order' => $order, 'is_divorced' => $isDivorced];
                $spouses[$b][$a] = ['order' => $order, 'is_divorced' => $isDivorced];
            }
        }
        $res->free();
    }

    // --- LOGIKA PERHITUNGAN GENERASI (TIDAK BERUBAH DARI SINI KE BAWAH) ---
    
    // Cari Root
    $rootPersons = [];
    foreach ($persons as $pid => $p) {
        $isParent = !empty($parents[$pid]);
        $isChild  = !empty($children[$pid]);
        if ($isParent && !$isChild) {
            $rootPersons[] = $pid;
        }
    }
    if (empty($rootPersons)) {
        foreach ($persons as $pid => $p) {
            if (empty($childParents[$pid])) $rootPersons[] = $pid;
        }
    }

    $generation = []; $queue = [];
    foreach ($rootPersons as $rid) {
        if (!isset($generation[$rid])) { $generation[$rid] = 1; $queue[] = $rid; }
    }

    for ($i = 0; $i < count($queue); $i++) {
        $person = $queue[$i]; $g = $generation[$person];
        if (!empty($spouses[$person])) {
            foreach ($spouses[$person] as $spouseId => $_) {
                if (!isset($generation[$spouseId])) { $generation[$spouseId] = $g; $queue[] = $spouseId; }
            }
        }
        if (!empty($parentChildren[$person])) {
            foreach ($parentChildren[$person] as $childId => $_) {
                $newGen = $g + 1;
                if (!isset($generation[$childId]) || $generation[$childId] < $newGen) {
                    $generation[$childId] = $newGen; $queue[] = $childId;
                }
            }
        }
    }

    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($spouses as $a => $list) {
            foreach ($list as $b => $_) {
                if (!isset($generation[$a]) || !isset($generation[$b])) continue;
                $maxG = max($generation[$a], $generation[$b]);
                if ($generation[$a] < $maxG) { $generation[$a] = $maxG; $changed = true; }
                if ($generation[$b] < $maxG) { $generation[$b] = $maxG; $changed = true; }
            }
        }
    }
    foreach ($persons as $pid => $_) { if (!isset($generation[$pid])) $generation[$pid] = 1; }

    $personsByGen = []; $maxGen = 0;
    foreach ($generation as $pid => $g) {
        if ($g < 1) $g = 1;
        if (!isset($personsByGen[$g])) $personsByGen[$g] = [];
        $personsByGen[$g][] = $persons[$pid]['name'];
        if ($g > $maxGen) $maxGen = $g;
    }
    
    return [$personsByGen, $maxGen, 0, $generation, $persons, $parentChildren, $spouses, $childParents];
}

// --- FUNGSI PENGGABUNG KELUARGA (UNION-FIND) ---
function fh_group_anchors($anchors, $persons, $spouses, $parentChildren) {
    $parent = [];
    foreach (array_keys($persons) as $id) $parent[$id] = $id;
    
    $find = function($i) use (&$parent) {
        $path = [];
        while ($parent[$i] != $i) { $path[] = $i; $i = $parent[$i]; }
        foreach ($path as $node) $parent[$node] = $i;
        return $i;
    };
    $union = function($i, $j) use (&$parent, $find) {
        $root_i = $find($i); $root_j = $find($j);
        if ($root_i != $root_j) $parent[$root_i] = $root_j;
    };
    
    foreach ($spouses as $a => $list) { foreach ($list as $b => $_) $union($a, $b); }
    foreach ($parentChildren as $p => $kids) { foreach ($kids as $k => $_) $union($p, $k); }
    
    $groups = [];
    foreach ($anchors as $a) {
        $root = $find($a);
        if (!isset($groups[$root])) $groups[$root] = [];
        $groups[$root][] = $a;
    }
    return array_values($groups);
}

// --- HITUNG TOTAL ANGGOTA DALAM CLUSTER (Helper Baru) ---
function fh_count_descendants($rootIds, $spouses, $parentChildren, &$visited) {
    $count = 0;
    $queue = $rootIds;
    
    while(!empty($queue)) {
        $currentId = array_shift($queue);
        if (isset($visited[$currentId])) continue;
        $visited[$currentId] = true;
        $count++;

        // Tambahkan Pasangan ke queue
        if (!empty($spouses[$currentId])) {
            foreach ($spouses[$currentId] as $sId => $_) {
                if (!isset($visited[$sId])) $queue[] = $sId;
            }
        }
        // Tambahkan Anak ke queue
        if (!empty($parentChildren[$currentId])) {
            foreach ($parentChildren[$currentId] as $cId => $_) {
                if (!isset($visited[$cId])) $queue[] = $cId;
            }
        }
    }
    return $count;
}

function fh_sort_child_ids(&$childIds, $persons) {
    usort($childIds, function($a, $b) use ($persons) {
        $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
        if ($oa && $ob) return ($oa <=> $ob);
        elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;

        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
        if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb); elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
    });
}

function fh_get_family_branches($personId, $persons, $spouses, $childParents) {
    $branches = [];
    $sortedSpouseIds = fh_get_sorted_spouse_ids($personId, $spouses, $persons);

    // Jika tidak punya istri sama sekali, tarik semua anak yang terhubung ke personId
    if (empty($sortedSpouseIds)) {
        $childIds = [];
        foreach ($childParents as $childId => $parentMap) {
            if (isset($parentMap[$personId])) $childIds[] = $childId;
        }
        fh_sort_child_ids($childIds, $persons);
        return [['spouse_id' => null, 'child_ids' => $childIds]];
    }

    // Jika punya istri, iterasi per istri
    $assignedChildIds = [];
    foreach ($sortedSpouseIds as $spouseId) {
        $childIds = [];
        foreach ($childParents as $childId => $parentMap) {
            $hasAyah = isset($parentMap[$personId]);
            $hasIbu  = isset($parentMap[$spouseId]);

            // Tampilkan anak di bawah istri ini jika:
            // 1. Terhubung ke KEDUANYA (Sutarjo & Hamidah/Muarifah)
            // 2. ATAU hanya terhubung ke IBU (Mungkin Ayah lupa diinput di tabel relations)
            if (($hasAyah && $hasIbu) || $hasIbu) {
                $childIds[] = $childId;
                $assignedChildIds[$childId] = true;
            }
        }
        fh_sort_child_ids($childIds, $persons);
        $branches[] = ['spouse_id' => $spouseId, 'child_ids' => $childIds];
    }

    // Anak yang hanya terhubung ke orang tua ini (tanpa pasangan lain),
    // sebaiknya ikut pasangan utama agar posisinya lebih dekat (antara ayah & ibu).
    $onlyParentIds = [];
    foreach ($childParents as $childId => $parentMap) {
        if (!isset($parentMap[$personId])) continue;
        if (isset($assignedChildIds[$childId])) continue;

        $hasAnySpouse = false;
        foreach ($sortedSpouseIds as $sid) {
            if (isset($parentMap[$sid])) { $hasAnySpouse = true; break; }
        }
        if (!$hasAnySpouse) {
            $onlyParentIds[] = $childId;
        }
    }

    if (!empty($onlyParentIds)) {
        fh_sort_child_ids($onlyParentIds, $persons);
        // Buat cabang terpisah meskipun parent punya istri/suami lain
        // Supaya anak tanpa ibu yang spesifik dipisah secara visual
        $branches[] = ['spouse_id' => null, 'child_ids' => $onlyParentIds];
    }

    return $branches;
}
function fh_render_tree_branch_web($personId, $spouseId, $childIds, $persons, $spouses, $parentChildren, $childParents, $currentActiveId) {
    if (!isset($persons[$personId])) return;

    echo '<li>';
    echo '<div style="display:inline-flex; align-items:center;">';
    fh_render_single_web_card($persons[$personId], $currentActiveId);
    if ($spouseId && isset($persons[$spouseId])) {
        echo '<div class="spouse-connector-web"></div>';
        fh_render_single_web_card($persons[$spouseId], $currentActiveId);
    }
    echo '</div>';

    if (!empty($childIds)) {
        echo '<ul>';
        foreach ($childIds as $childId) {
            fh_render_tree_web($childId, $persons, $spouses, $parentChildren, $childParents, $currentActiveId);
        }
        echo '</ul>';
    }
    echo '</li>';
}

function fh_render_multi_spouse_cluster_web($personId, $branches, $persons, $currentActiveId) {
    $spouseIds = [];
    foreach ($branches as $branch) {
        if (!empty($branch['spouse_id']) && isset($persons[$branch['spouse_id']])) {
            $spouseIds[] = $branch['spouse_id'];
        }
    }

    $left = [];
    $right = [];
    foreach (array_values($spouseIds) as $index => $spouseId) {
        if ($index % 2 === 0) $left[] = $spouseId;
        else $right[] = $spouseId;
    }

    $rows = max(count($left), count($right), 1);

    echo '<div class="multi-spouse-cluster">';
    for ($i = 0; $i < $rows; $i++) {
        $leftId = $left[$i] ?? null;
        $rightId = $right[$i] ?? null;
        $isMainRow = ($i === 0);

        echo '<div class="multi-spouse-row'.($isMainRow ? ' is-main-row' : '').'">';

        echo '<div class="multi-spouse-side multi-spouse-side-left">';
        if ($leftId && isset($persons[$leftId])) {
            fh_render_single_web_card($persons[$leftId], $currentActiveId);
        } else {
            echo '<div class="multi-spouse-empty"></div>';
        }
        echo '</div>';

        echo '<div class="multi-spouse-link multi-spouse-link-left"></div>';

        echo '<div class="multi-spouse-center">';
        if ($isMainRow) {
            fh_render_single_web_card($persons[$personId], $currentActiveId);
        } else {
            echo '<div class="multi-spouse-center-spacer"></div>';
        }
        echo '</div>';

        echo '<div class="multi-spouse-link multi-spouse-link-right"></div>';

        echo '<div class="multi-spouse-side multi-spouse-side-right">';
        if ($rightId && isset($persons[$rightId])) {
            fh_render_single_web_card($persons[$rightId], $currentActiveId);
        } else {
            echo '<div class="multi-spouse-empty"></div>';
        }
        echo '</div>';

        echo '</div>';
    }
    echo '</div>';
}

function fh_render_tree_web($personId, $persons, $spouses, $parentChildren, $childParents, $currentActiveId) {
    if (!isset($persons[$personId])) return;

    $branches = fh_get_family_branches($personId, $persons, $spouses, $childParents);
    
    // Ambil semua istri
    $spouseBranches = array_values(array_filter($branches, function($b) {
        return $b['spouse_id'] !== null;
    }));

    $numWives = count($spouseBranches);

    echo '<li class="family-unit-root">';

    // --- CONTAINER UTAMA PASANGAN (HORIZONTAL) ---
    echo '<div class="horizontal-parents-wrapper">';

        if ($numWives > 0) {
            $leftSpouses = [];
            $rightSpouses = [];
            $splitIndex = (int) ceil($numWives / 2);
            for ($i = 0; $i < $numWives; $i++) {
                $sid = $spouseBranches[$i]['spouse_id'] ?? 0;
                if ($sid <= 0 || !isset($persons[$sid])) continue;
                if ($i < $splitIndex) $leftSpouses[] = $sid;
                else $rightSpouses[] = $sid;
            }

            echo '<div class="marriage-grid">';
            echo '  <div class="wives-inline wives-inline-left">';
            foreach ($leftSpouses as $sid) {
                echo '<div class="marriage-inline-unit marriage-inline-unit-left">';
                echo '  <div class="node-wrapper spouse-node">';
                fh_render_single_web_card($persons[$sid], $currentActiveId);
                echo '  </div>';
                echo '  <div class="marriage-link marriage-link-zigzag"><span class="marriage-love">❤️</span></div>';
                echo '</div>';
            }
            echo '  </div>';
            echo '  <div class="node-wrapper husband-node">';
            fh_render_single_web_card($persons[$personId], $currentActiveId);
            echo '  </div>';
            echo '  <div class="wives-inline wives-inline-right">';
            foreach ($rightSpouses as $sid) {
                echo '<div class="marriage-inline-unit marriage-inline-unit-right">';
                echo '  <div class="marriage-link marriage-link-zigzag"><span class="marriage-love">❤️</span></div>';
                echo '  <div class="node-wrapper spouse-node">';
                fh_render_single_web_card($persons[$sid], $currentActiveId);
                echo '  </div>';
                echo '</div>';
            }
            echo '  </div>';
            echo '</div>';
        } else {
            echo '<div class="node-wrapper husband-node">';
            fh_render_single_web_card($persons[$personId], $currentActiveId);
            echo '</div>';
        }

    echo '</div>'; // Tutup horizontal-parents-wrapper

    // --- AREA ANAK-ANAK ---
    $hasChildren = false;
    foreach ($branches as $b) { if(!empty($b['child_ids'])) $hasChildren = true; }

if ($hasChildren) {
    echo '<ul class="children-root">'; // â¬…ï¸ڈ WAJIB UL

    foreach ($branches as $branch) {
        if (!empty($branch['child_ids'])) {

            echo '<li>'; // â¬…ï¸ڈ WAJIB LI

            echo '<div class="marriage-block">';
            echo '<div class="marriage-line-down"></div>';

            if ($branch['spouse_id']) {
                echo '<div class="mom-label">Keturunan ' . htmlspecialchars($persons[$branch['spouse_id']]['name']) . '</div>';
            }

            echo '<ul class="children-list">';
            foreach ($branch['child_ids'] as $childId) {
                fh_render_tree_web($childId, $persons, $spouses, $parentChildren, $childParents, $currentActiveId);
            }
            echo '</ul>';

            echo '</div>';

            echo '</li>'; // â¬…ï¸ڈ WAJIB LI
        }
    }

    echo '</ul>'; // â¬…ï¸ڈ WAJIB UL
}

    echo '</li>';
}
// --- DATA EXPORT HELPERS (Sama seperti sebelumnya) ---
function fh_get_group_label_for_person($id, $gen, $generation, $persons, $spouses) {
    if (!isset($persons[$id])) return '';
    $mainId = $id;
    $otherIds = fh_get_sorted_spouse_ids($mainId, $spouses, $persons);
    $groupIds = array_merge([$mainId], $otherIds);
    $names = [];
    foreach ($groupIds as $pid) $names[] = $persons[$pid]['name'];
    return implode(' & ', $names);
}

function fh_get_family_rows_recursive($personId, $currentGen, $persons, $spouses, $parentChildren, $generationData, $childNumber = null) {
    $label = fh_get_group_label_for_person($personId, $currentGen, $generationData, $persons, $spouses);
    if ($currentGen > 1 && $childNumber !== null) $label = $childNumber . '. ' . $label;

    $groupIds = array_merge([$personId], fh_get_sorted_spouse_ids($personId, $spouses, $persons));
    $childIdsMap = [];
    foreach ($groupIds as $pid) {
        if (!empty($parentChildren[$pid])) {
            foreach ($parentChildren[$pid] as $cid => $_) $childIdsMap[$cid] = true;
        }
    }
    $childIds = array_keys($childIdsMap);

    if (empty($childIds)) return [[ $currentGen => $label ]];

    usort($childIds, function($a, $b) use ($persons) {
        $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
        if ($oa && $ob) return ($oa <=> $ob);
        elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;
        
        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
        if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb); elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
    });

    $allDescendantRows = [];
    $childGen = $currentGen + 1;
    foreach ($childIds as $index => $childId) {
        $nextNumber = $index + 1;
        $childRows = fh_get_family_rows_recursive($childId, $childGen, $persons, $spouses, $parentChildren, $generationData, $nextNumber);
        foreach ($childRows as $row) $allDescendantRows[] = $row;
    }
    if (isset($allDescendantRows[0])) $allDescendantRows[0][$currentGen] = $label;
    return $allDescendantRows;
}



// === EXPORT HANDLERS (UPDATED) ===
if (isset($_GET['export'])) {
    global $mysqli;
    
    // 1. Ambil Filter ID jika ada
    $filterRootId = isset($_GET['filter_root']) ? intval($_GET['filter_root']) : 0;

    // 2. Hitung Generasi (Sama seperti View)
    $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        
        // --- PERBAIKAN: POPUP ALERT JIKA BELUM PILIH ---

        
    list($personsByGen, $maxGen, $maxHeight, $generation, $persons, $parentChildren, $spouses) = fh_compute_generations($mysqli, $activeTreeId);

    // 3. Tentukan Siapa yang akan di-Export (Roots)
    $rootsToExport = [];

    if ($filterRootId > 0) {
        // KASUS 1: Export SATU Keluarga Saja
        // Validasi: pastikan ID ini ada
        if (isset($persons[$filterRootId])) {
            $rootsToExport[] = $filterRootId;
        }
    } else {
        // KASUS 2: Export SEMUA Keluarga (Sama seperti Tampilan Awal)
        // Cari semua Gen 1
        $allRoots = [];
        foreach ($persons as $pid => $info) {
            if (($generation[$pid] ?? 999) == 1) {
                $allRoots[] = $pid;
            }
        }
        // Urutkan Nama
        usort($allRoots, function($a, $b) use ($persons) { 
            return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']); 
        });

        // Deduplikasi Pasangan (Agar suami istri tidak muncul 2x sebagai judul terpisah)
        $processed = [];
        foreach ($allRoots as $rid) {
            if (isset($processed[$rid])) continue;
            
            // Masukkan ke daftar export
            $rootsToExport[] = $rid;
            $processed[$rid] = true;

            // Tandai pasangannya agar tidak masuk lagi
            if (!empty($spouses[$rid])) {
                foreach($spouses[$rid] as $sid => $_) {
                    // Hanya tandai jika pasangan juga Gen 1
                    if (($generation[$sid]??0) == 1) $processed[$sid] = true;
                }
            }
        }
    }

   // --- LOGIC EXCEL & WORD (TABEL DATA) ---
    if ($_GET['export'] === 'excel' || $_GET['export'] === 'word') {
        
        // 1. BERSIH-BERSIH BUFFER DI AWAL (PENTING!)
        // Hapus semua output yang mungkin tidak sengaja tercetak sebelumnya (spasi, enter dari include, dll)
        if (ob_get_length()) ob_end_clean();
        
        // Matikan error display agar teks error tidak masuk ke dalam file download
        ini_set('display_errors', 0);
        error_reporting(0);
        
        // Matikan kompresi GZIP server (sering merusak file .docx/.xlsx)
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', 1);
        }
        @ini_set('zlib.output_compression', 'Off');
        
        $filename = "familyhood_" . ($filterRootId ? "bani_".$filterRootId : "all") . "_" . date('Ymd_His');

        // --- Query Data ---
        $finalRows = [];
        $realMaxGen = 0;
        foreach ($rootsToExport as $anchorId) {
            $familyRows = fh_get_family_rows_recursive($anchorId, 1, $persons, $spouses, $parentChildren, $generation, null);
            foreach ($familyRows as $fRow) {
                if (!empty($fRow)) {
                    $maxKey = max(array_keys($fRow));
                    if ($maxKey > $realMaxGen) $realMaxGen = $maxKey;
                }
                $finalRows[] = $fRow;
            }
            $finalRows[] = []; 
        }

        // Cek Library
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\Spreadsheet') || !class_exists('\\PhpOffice\\PhpWord\\PhpWord')) {
            die('Error: Library PhpSpreadsheet / PhpWord tidak terdeteksi.');
        }

        // OPSI 1: EXPORT EXCEL
        if ($_GET['export'] === 'excel') {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            for ($g = 1; $g <= $realMaxGen; $g++) {
                $sheet->setCellValueByColumnAndRow($g, 1, 'Generasi '.$g);
                $sheet->getStyleByColumnAndRow($g, 1)->getFont()->setBold(true);
                $sheet->getStyleByColumnAndRow($g, 1)->getFill()
                      ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                      ->getStartColor()->setARGB('FFE5E7EB');
            }

            $rowIndex = 2;
            foreach ($finalRows as $row) {
                if (empty($row)) { $rowIndex++; continue; }
                for ($g = 1; $g <= $realMaxGen; $g++) {
                    $val = isset($row[$g]) ? (string)$row[$g] : '';
                    $sheet->setCellValueByColumnAndRow($g, $rowIndex, $val);
                }
                $rowIndex++;
            }

            for ($g = 1; $g <= $realMaxGen; $g++) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($g);
                $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
            }

            // Header Download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
            header('Cache-Control: max-age=0');
            
            // Bersihkan buffer lagi tepat sebelum save
            if (ob_get_length()) ob_end_clean();

            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            exit;
        } 
        
        // OPSI 2: EXPORT WORD (FIXED)
        else {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            
            // Set Metadata File
            $docInfo = $phpWord->getDocInfo();
            $docInfo->setTitle("Laporan Silsilah");
            $docInfo->setSubject("Family Tree");

            // Atur Halaman Landscape
            $section = $phpWord->addSection([
                'orientation' => 'landscape',
                'marginTop' => 600, 'marginLeft' => 600, 'marginRight' => 600, 'marginBottom' => 600
            ]);

            // Judul Dokumen
            $titleText = "Laporan Silsilah Keluarga";
            if ($filterRootId > 0 && isset($persons[$filterRootId])) {
                $titleText .= " Bani " . $persons[$filterRootId]['name'];
            }
            $section->addText($titleText, ['bold'=>true, 'size'=>16], ['align'=>'center']);
            $section->addTextBreak(1);

            // Style Tabel
            $styleTable = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
            $phpWord->addTableStyle('FamilyTreeTable', $styleTable);
            $table = $section->addTable('FamilyTreeTable');

            // Header Tabel
            $table->addRow();
            $cellWidth = 2000; 
            for ($g = 1; $g <= $realMaxGen; $g++) {
                $table->addCell($cellWidth, ['bgColor' => 'E5E7EB'])->addText("Generasi $g", ['bold'=>true]);
            }

            // Isi Data
            foreach ($finalRows as $row) {
                // Baris Kosong (Pemisah)
                if (empty($row)) {
                    $table->addRow();
                    for ($g = 1; $g <= $realMaxGen; $g++) {
                        $table->addCell($cellWidth, ['borderTopSize'=>0, 'borderBottomSize'=>0, 'borderLeftSize'=>0, 'borderRightSize'=>0]); 
                    }
                    continue;
                }

                $table->addRow();
                for ($g = 1; $g <= $realMaxGen; $g++) {
                    $text = isset($row[$g]) ? (string)$row[$g] : '';
                    
                    // --- SANITASI TEKS (Sangat Penting untuk Word) ---
                    // Hapus karakter kontrol (seperti NULL bytes) yang bisa merusak XML Word
                    $cleanText = preg_replace('/[\x00-\x1F\x7F]/', '', $text); 
                    $cleanText = htmlspecialchars($cleanText); 
                    
                    $table->addCell($cellWidth)->addText($cleanText);
                }
            }

            // --- HEADER DOWNLOAD WORD ---
            header("Content-Description: File Transfer");
            header('Content-Disposition: attachment; filename="'.$filename.'.docx"');
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Expires: 0');
            
            // BERSIHKAN BUFFER LAGI (Final Check)
            // Ini memastikan tidak ada 1 byte pun sampah yang ikut terkirim
            while (ob_get_level()) { ob_end_clean(); }

            // Tulis file
            $xmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
            $xmlWriter->save("php://output");
            exit;
        }
    }

    // --- LOGIC PDF ---
    if ($_GET['export'] === 'pdf') {
        // Helper PDF (Internal function, copy dari sebelumnya tapi dirapikan)
        if (!function_exists('fh_render_single_card_pdf')) {
            function fh_render_single_card_pdf($p) {
                 $userPhoto = $p['photo'] ?? '';
                 $fullUserPath = __DIR__ . '/' . $userPhoto;
                 $gender = strtoupper($p['gender'] ?? '');
                 $imagePathToUse = '';
                 if (!empty($userPhoto) && file_exists($fullUserPath)) $imagePathToUse = $fullUserPath;
                 else {
                     $assetFile = ($gender === 'P') ? 'assets/p.png' : 'assets/l.png';
                     $fullAssetPath = __DIR__ . '/' . $assetFile;
                     if (file_exists($fullAssetPath)) $imagePathToUse = $fullAssetPath;
                 }
                 $avatarHtml = '';
                 if ($imagePathToUse) {
                     $ext = pathinfo($imagePathToUse, PATHINFO_EXTENSION) ?: 'png';
                     $fileContent = @file_get_contents($imagePathToUse);
                     if ($fileContent) {
                         $imgData = base64_encode($fileContent);
                         $src = 'data:image/' . $ext . ';base64,' . $imgData;
                         $avatarHtml = '<img src="'.$src.'">';
                     }
                 }
                 if (empty($avatarHtml)) {
                     $color = ($gender === 'P') ? '#ec4899' : '#3b82f6';
                     $txt   = ($gender ?: '?');
                     $avatarHtml = '<div style="background:'.$color.'; width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:bold; font-size:1.2rem;">'.$txt.'</div>';
                 }
                 echo '<div class="node-card"><div class="node-circle">' . $avatarHtml . '</div><div class="node-name">' . htmlspecialchars($p['name']) . '</div></div>';
            }
        }

        if (!function_exists('fh_pdf_recurse')) {
            function fh_pdf_recurse($personId, $persons, $spouses, $parentChildren) {
                if (!isset($persons[$personId])) return;
                echo '<li><div class="tree-content">';
                fh_render_single_card_pdf($persons[$personId]);
                if (!empty($spouses[$personId])) {
                    foreach ($spouses[$personId] as $spouseId => $_) {
                        echo '<div class="spouse-connector">❤</div>';
                        if (isset($persons[$spouseId])) fh_render_single_card_pdf($persons[$spouseId]);
                    }
                }
                echo '</div>';
                
                $groupIds = [$personId];
                if (!empty($spouses[$personId])) foreach ($spouses[$personId] as $sid => $_) $groupIds[] = $sid;
                
                $childIdsMap = [];
                foreach ($groupIds as $pid) {
                    if (!empty($parentChildren[$pid])) foreach ($parentChildren[$pid] as $cid => $_) $childIdsMap[$cid] = true;
                }
                $childIds = array_keys($childIdsMap);

                if (!empty($childIds)) {
                    usort($childIds, function($a, $b) use ($persons) {
                        $oa = $persons[$a]['child_order'] ?? null; $ob = $persons[$b]['child_order'] ?? null;
                        if ($oa && $ob) return ($oa <=> $ob);
                        elseif ($oa && !$ob) return -1; elseif (!$oa && $ob) return 1;
                        
                        $da = $persons[$a]['dob'] ?? null; $db = $persons[$b]['dob'] ?? null;
                        $ka = ($da && $da !== '0000-00-00') ? $da : null; $kb = ($db && $db !== '0000-00-00') ? $db : null;
                        if ($ka && $kb && $ka !== $kb) return strcmp($ka, $kb);
                        elseif ($ka && !$kb) return -1; elseif (!$ka && $kb) return 1;
                        return strnatcasecmp($persons[$a]['name'], $persons[$b]['name']);
                    });
                    echo '<ul>';
                    foreach ($childIds as $childId) fh_pdf_recurse($childId, $persons, $spouses, $parentChildren);
                    echo '</ul>';
                }
                echo '</li>';
            }
        }

        echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Pohon Keluarga PDF</title>';
        echo '<style>
        /* Tambahkan ini di dalam <style> di bagian atas */
        @keyframes popUp { 
            from { transform: scale(0.8); opacity: 0; } 
            to { transform: scale(1); opacity: 1; } 
        }
        * { box-sizing: border-box; } body { margin:0; font-family: sans-serif; }
        .tree { display: table; margin: 0 auto; }
        .tree ul { padding-top: 20px; position: relative; transition: all 0.5s; display: flex; justify-content: center; }
        .tree li { 
            display: inline-block;
    vertical-align: top; 
        
        text-align: center; list-style-type: none; position: relative; padding: 20px 5px 0 5px; transition: all 0.5s; }
        .tree li::before, .tree li::after { content: ""; position: absolute; top: 0; right: 50%; border-top: 1px solid #ccc; width: 50%; height: 20px; }
        .tree li::after { right: auto; left: 50%; border-left: 1px solid #ccc; }
        .tree li:only-child::after, .tree li:only-child::before { display: none; }
        .tree li:only-child { padding-top: 0; }
        .tree li:first-child::before, .tree li:last-child::after { border: 0 none; }
        .tree li:last-child::before { border-right: 1px solid #ccc; border-radius: 0 5px 0 0; }
        .tree li:first-child::after { border-radius: 5px 0 0 0; }
        .tree ul ul::before { content: ""; position: absolute; top: 0; left: 50%; border-left: 1px solid #ccc; width: 0; height: 20px; }
        .tree-content { display: flex; align-items: center; justify-content: center; gap: 5px; }
        .node-card { display: inline-block; background: #fff; border: 1px solid #ccc; padding: 5px; border-radius: 5px; min-width: 80px; }
        .node-circle { width: 40px; height: 40px; border-radius: 50%; overflow: hidden; margin: 0 auto 5px; background: #eee; }
        .node-circle img { width: 100%; height: 100%; object-fit: cover; }
        .node-name { font-size: 10px; font-weight: bold; }
        .spouse-connector { color: red; font-size: 10px; margin: 0 2px; }
        @media print { .no-print { display: none; } }
        /* Pastikan grup orang tua tidak terpotong dan tetap satu baris */

        /* Container Utama Pasangan */
.horizontal-parents-wrapper {
    display: flex !important;
    flex-direction: row !important;
    align-items: center;
    justify-content: center;
    background: #fff;
    padding: 20px;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    margin: 0 auto 20px auto;
    width: fit-content;
    position: relative;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

/* Sisi Istri */
.wives-side {
    display: flex;
    flex-direction: row !important;
    align-items: center;
}

/* Node (Kotak Nama) */
.node-wrapper {
    position: relative;
    z-index: 10;
    flex-shrink: 0;
}

/* Garis antar pasangan */
.line-connector {
    width: 25px;
    height: 2px;
    background: #4f46e5; /* Warna garis biru modern */
    flex-shrink: 0;
}

.marriage-bridge {
    background: #ef4444; /* Garis khusus ke suami warna merah */
}

/* Baris Anak */
.children-row {
    display: flex;
    justify-content: center;
    padding-top: 20px !important;
    position: relative;
}

/* Menghilangkan garis vertikal standar tree yang mengganggu di area pasangan */
.family-unit-root > ul::before, 
.family-unit-root > ul::after {
    display: none !important;
}

/* Garis cabang pernikahan ke bawah */
.marriage-branch {
    position: relative;
    padding-top: 25px !important;
}

.marriage-branch::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    width: 2px;
    height: 25px;
    background: #cbd5e1;
}

/* Label Kecil untuk Identitas Ibu */
.mom-label {
    font-size: 10px;
    color: #64748b;
    background: #f1f5f9;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
    margin-bottom: 5px;
    font-weight: bold;
    border: 1px solid #e2e8f0;
}

/* Perbaikan agar kotak tidak bertabrakan */
.tree-node-web {
    margin: 0 5px;
}


/* Container anak */
.children-container {
    display: flex;
    justify-content: center;
    gap: 40px;
    margin-top: 10px;
    position: relative;
}

/* Blok tiap istri */
.marriage-block {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
}

/* GARIS TURUN DARI TENGAH PASANGAN */
.marriage-line-down {
    width: 2px;
    height: 25px;
    background: #64748b;
    margin-bottom: 5px;
}

/* List anak */
.children-list {
    display: flex;
    gap: 20px;
    padding-top: 10px;
    position: relative;
}

/* Garis horizontal antar anak */
.children-list::before {
    content: "";
    position: absolute;
    top: 0;
    left: 10%;
    right: 10%;
    height: 2px;
    background: #cbd5e1;
}

/* Garis ke tiap anak */
.children-list > li {
    position: relative;
    padding-top: 20px;
}

.children-list > li::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    width: 2px;
    height: 20px;
    background: #cbd5e1;
}

.horizontal-parents-wrapper {
    gap: 30px;
}

.wives-side {
    gap: 15px;
}

.horizontal-parents-wrapper {
    display: flex !important;
    flex-direction: row !important; /* WAJIB */
    align-items: center;
    justify-content: center;
    gap: 20px;
}

.horizontal-parents-wrapper::after {
    content: "";
    position: absolute;
    bottom: -20px;
    left: 50%;
    transform: translateX(-50%);
    width: 2px;
    height: 20px;
    background: #64748b;
}

.horizontal-parents-wrapper {
    display: flex !important;
    flex-direction: row !important;
    align-items: center;
    justify-content: center;
    gap: 20px;
}



.children-root {
    display: flex;
    justify-content: center;
    margin-top: 10px;
    padding-top: 20px;
    position: relative;
}

.children-root::before {
    content: "";
    position: absolute;
    top: 0;
    left: 50%;
    width: 2px;
    height: 20px;
    background: #64748b;
}
        </style></head><body onload="window.print()">';
        echo '<a href="#" onclick="window.print(); return false;" class="no-print">🖨 Cetak PDF</a>';
        
        $title = "Diagram Keluarga Besar";
        if ($filterRootId > 0 && isset($persons[$filterRootId])) {
            $title .= " Bani " . htmlspecialchars($persons[$filterRootId]['name']);
        }
        echo '<h2 style="text-align:center;">'.$title.'</h2>';
        
        // Loop Roots yang sudah difilter
        foreach ($rootsToExport as $rootId) {
             echo '<div class="tree"><ul>';
             fh_pdf_recurse($rootId, $persons, $spouses, $parentChildren);
             echo '</ul></div><hr style="margin:40px 0; border:0; border-top:1px dashed #ccc;">';
        }
        
        echo '</body></html>';
        exit;
    }
}

function redirect($url) { header('Location: ' . $url); exit; }

function handle_photo_upload($fieldName, $oldPath = null) {
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) return $oldPath;
    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $newName = 'person_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $target  = $uploadDir . '/' . $newName;
    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
        if ($oldPath && file_exists(__DIR__ . '/' . ltrim($oldPath, '/'))) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
        return 'uploads/' . $newName;
    }
    return $oldPath;
}

function label_gender($gender) {
    $gender = strtoupper($gender ?? '');
    return ($gender === 'L') ? 'Laki-laki' : (($gender === 'P') ? 'Perempuan' : 'Tidak diketahui');
}

function label_alive($v) {
    if ($v === null || $v === '') return 'Tidak diketahui';
    return $v ? 'Hidup' : 'Wafat';
}

function render_avatar_svg($gender) {
    $gender = strtoupper($gender ?? '');
    $imgSrc = ($gender === 'P') ? 'assets/p.png' : 'assets/l.png';
    return '<img src="' . $imgSrc . '" alt="' . $gender . '" style="width:100%; height:100%; object-fit:cover; display:block;">';
}

function fh_get_sorted_spouse_ids($personId, $spouses, $persons = []) {
    if (empty($spouses[$personId]) || !is_array($spouses[$personId])) return [];

    $ids = array_keys($spouses[$personId]);
    usort($ids, function($a, $b) use ($personId, $spouses, $persons) {
        $oa = (int)($spouses[$personId][$a]['order'] ?? 0);
        $ob = (int)($spouses[$personId][$b]['order'] ?? 0);
        if ($oa > 0 && $ob > 0 && $oa !== $ob) return $oa <=> $ob;
        if ($oa > 0 && $ob <= 0) return -1;
        if ($oa <= 0 && $ob > 0) return 1;

        $na = $persons[$a]['name'] ?? '';
        $nb = $persons[$b]['name'] ?? '';
        return strnatcasecmp($na, $nb);
    });

    return $ids;
}

$action = $_GET['action'] ?? 'home';
$currentPerson = null;
$relations = [];
$parents = $children = $siblings = $spouses = [];
$otherPersons = [];
$allPersons = [];
$error = ''; $success = ''; $bio_error = ''; $bio_success = '';

// --- LOGIKA TAMBAH / EDIT / HAPUS DATA (CRUD) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Admin dapat mengubah data user ketika user mengizinkan akses admin.
    // Semua tindakan CRUD selanjutnya akan menggunakan $targetUserId dari tree yang sedang diintip.

    // 1. TAMBAH ORANG (QUICK)
    if (isset($_POST['create_person_quick'])) {
        $name = trim($_POST['name']??''); 
        $gender = trim($_POST['gender']??''); 
        $note = trim($_POST['note']??'');
        $place_of_birth = trim($_POST['place_of_birth']??'');
        $address = trim($_POST['address']??'');
        $phone_number = trim($_POST['phone_number']??'');
        $dob = empty($_POST['date_of_birth']) ? null : $_POST['date_of_birth'];
        $alive = ($_POST['is_alive'] === '') ? 1 : (int)$_POST['is_alive'];
        $dod = ($alive == 0 && !empty($_POST['date_of_death'])) ? $_POST['date_of_death'] : null;

        if ($name === '') { $error = "Nama kosong."; }
        else {
            $treeId = ($isViewingOthers && isset($_SESSION['admin_viewing_tree_id'])) ? intval($_SESSION['admin_viewing_tree_id']) : ($_SESSION['current_tree_id'] ?? 0);
            if ($treeId == 0) {
                $error = "Session Error: Tidak ada proyek keluarga yang dipilih. Silakan kembali ke Home.";
            } else {
                // Cek dulu jumlah keluarga saat ini (Untuk deteksi Keluarga Baru vs Tambah Anggota)
                $countCheck = $mysqli->query("SELECT COUNT(*) as total FROM persons WHERE user_id = $targetUserId")->fetch_assoc()['total'];
                $myName = $_SESSION['user_name']; // Ambil nama user yang login

                // INSERT dengan last_editor_name, address, dan phone_number
                $stmt = $mysqli->prepare("INSERT INTO persons (user_id, tree_id, name, gender, place_of_birth, address, phone_number, date_of_birth, is_alive, date_of_death, note, last_editor_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iissssssisss", $targetUserId, $treeId, $name, $gender, $place_of_birth, $address, $phone_number, $dob, $alive, $dod, $note, $myName);

                if ($stmt->execute()) {
                    $success = "Anggota dibuat."; 
                    
                    // --- LOGIKA NOTIFIKASI TAMBAH ---
                    if ($countCheck == 0) {
                        // Ini adalah anggota PERTAMA (Keluarga Baru)
                        fh_send_notification($mysqli, $targetUserId, "Keluarga Baru Terbentuk! 🎉", "Selamat! Anda telah memulai pohon keluarga baru dengan menambahkan $name.", "success");
                    } else {
                        // Ini adalah penambahan anggota selanjutnya
                        fh_send_notification($mysqli, $targetUserId, "Anggota Baru Ditambahkan 🎉", "$name berhasil ditambahkan ke dalam silsilah keluarga.", "success");
                    }
                    // --------------------------------
                } else {
                    $error = $stmt->error;
                }
                $stmt->close();
            }
        }
    }
    
    // --- 5. LOGIKA TOGGLE STATUS USER (Admin Only) ---
    elseif (isset($_POST['toggle_user_status']) && $isAdmin) {
        $userIdToToggle = intval($_POST['toggle_user_id']);
        $newStatus = intval($_POST['new_status']);
        
        // Jangan biarkan admin menonaktifkan dirinya sendiri
        if ($userIdToToggle == $myUserId) {
             $error = "Tidak bisa menonaktifkan akun Anda sendiri.";
        } else {
             $stmt = $mysqli->prepare("UPDATE users SET is_active=? WHERE id=? AND role='user'");
             $stmt->bind_param("ii", $newStatus, $userIdToToggle);
             if ($stmt->execute()) {
                 $statusText = ($newStatus == 1) ? 'aktifkan' : 'nonaktifkan';
                 $success = "User berhasil di$statusText.";
             } else {
                 $error = "Gagal mengubah status user.";
             }
             $stmt->close();
        }
        $action = 'admin_users'; // Kembali ke halaman admin
    }
    
    // --- 6. LOGIKA TOGGLE ADMIN VIEW PER TREE (User Side) ---
    elseif (isset($_POST['toggle_tree_admin_view'])) {
        $treeId = intval($_POST['tree_id']);
        $newStatus = intval($_POST['new_status']);
        
        // Pastikan TreeID milik user yang sedang login
        $checkTree = $mysqli->prepare("SELECT id FROM family_trees WHERE id = ? AND user_id = ?");
        $checkTree->bind_param("ii", $treeId, $myUserId);
        $checkTree->execute();
        $checkTree->store_result();
        
        if ($checkTree->num_rows > 0) {
            $stmt = $mysqli->prepare("UPDATE family_trees SET allow_admin_view=? WHERE id=?");
            $stmt->bind_param("ii", $newStatus, $treeId);
            if ($stmt->execute()) {
                $statusText = ($newStatus == 1) ? 'diizinkan' : 'dikunci';
                $success = "Akses Admin untuk Keluarga berhasil di$statusText.";
            } else {
                $error = "Gagal mengubah status akses Admin.";
            }
            $stmt->close();
        } else {
            $error = "Tree ID tidak valid atau bukan milik Anda.";
        }
        $checkTree->close();
        $action = 'settings'; // Kembali ke halaman settings
    }
    
    // --- 7. LOGIKA PERMINTAAN KIRIM EMAIL AKTIVASI ULANG ---
    elseif (isset($_POST['request_reactivation'])) {
        // Logika ini harusnya ada di login.php, tapi karena form di login.php post ke index.php, kita handle di sini.
        
        $userEmail = trim($_POST['email']);
        
        // Cari user nonaktif dengan email tersebut
        $stmt = $mysqli->prepare("SELECT id, name, is_active FROM users WHERE email = ?");
        $stmt->bind_param("s", $userEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        $u = $result->fetch_assoc();
        $stmt->close();
        
        if ($u && $u['is_active'] == 0) {
            
            // Generate kode aktivasi baru
            $newCode = md5(time() . $u['id'] . $u['name']);
            
            // Simpan kode aktivasi baru ke database
            $stmt = $mysqli->prepare("UPDATE users SET activation_code = ? WHERE id = ?");
            $stmt->bind_param("si", $newCode, $u['id']);
            $stmt->execute();
            $stmt->close();

            // Asumsi URL Aplikasi Anda adalah http://yourdomain.com/
            // GANTI DENGAN DOMAIN ASLI ANDA
            $activationLink = "http://familyhood.my.id/index.php?action=activate&code=" . $newCode;
            
            // --- KIRIM EMAIL (Simulasi) ---
            $subject = "Konfirmasi Ulang Akun FamilyHood Anda";
            $message = "Halo " . htmlspecialchars($u['name']) . ",\n\nAkun Anda memerlukan konfirmasi ulang (re-aktivasi). Klik tautan di bawah ini untuk mengaktifkan akun Anda kembali dan bisa login:\n\n" . $activationLink . "\n\nJika Anda tidak merasa mengajukan permintaan ini, abaikan email ini.\n\nSalam,\nFamilyHood Team";
            $headers = "From: no-reply@familyhood.com";
            
            // *Uncomment baris di bawah ini jika server Anda mendukung fungsi mail() PHP*
            // @mail($userEmail, $subject, $message, $headers); 
            
            $_SESSION['status_success'] = "Email konfirmasi telah dikirim ke **" . htmlspecialchars($userEmail) . "**. Silakan cek kotak masuk dan folder spam Anda, atau hubungi Admin jika Anda tidak menerimanya.";
            
        } elseif ($u && $u['is_active'] == 1) {
             $_SESSION['status_error'] = "Akun Anda sudah aktif. Silakan coba login langsung.";
        } else {
             $_SESSION['status_error'] = "Email tidak terdaftar.";
        }

        // Redirect ke login.php untuk menampilkan pesan (Success/Error)
        header("Location: login.php");
        exit;
    }
    
    
    // 2. TAMBAH ORANG (LENGKAP)
    elseif (isset($_POST['create_person_full'])) {
        
        
        
        $name = trim($_POST['name']??''); 
        $gender = trim($_POST['gender']??''); 
        $place_of_birth = trim($_POST['place_of_birth']??'');
        $address = trim($_POST['address']??'');
        $phone_number = trim($_POST['phone_number']??'');
        $dob = empty($_POST['date_of_birth']) ? null : $_POST['date_of_birth'];
        $alive = ($_POST['is_alive'] === '') ? 1 : (int)$_POST['is_alive'];
        $dod = ($alive == 0 && !empty($_POST['date_of_death'])) ? $_POST['date_of_death'] : null;
        $note = trim($_POST['note']??'');
        $child_order = (isset($_POST['child_order']) && intval($_POST['child_order']) > 0) ? intval($_POST['child_order']) : null;
        
        $from_id = intval($_POST['from_id']??0);
        $from_rel = $_POST['from_relation_type']??'';
        $spouse_order = (isset($_POST['spouse_order']) && intval($_POST['spouse_order']) > 0) ? intval($_POST['spouse_order']) : null;
        $is_divorced_new = (isset($_POST['is_divorced']) && $_POST['is_divorced'] == '1') ? 1 : 0;

        // Auto-detect gender jika relasi tertentu
        if ($from_rel === 'ibu') $gender = 'P';
        elseif ($from_rel === 'ayah') $gender = 'L';
        elseif ($from_rel === 'pasangan' && $from_id > 0) {
            // Cek gender pasangan
            $r = $mysqli->query("SELECT gender FROM persons WHERE id=$from_id AND user_id=$targetUserId")->fetch_assoc();
            if ($r) $gender = ($r['gender']=='L') ? 'P' : 'L';
        }

        if ($name === '') { 
            $error = "Nama tidak boleh kosong."; 
            $action = 'add_person'; 
        } elseif ($gender === '') { 
            $error = "Peringatan: Jenis kelamin harus dipilih."; 
            $action = 'add_person'; 
        } else {
            // Validasi input sukses, sekarang cek Tree ID
            $treeId = ($isViewingOthers && isset($_SESSION['admin_viewing_tree_id'])) ? intval($_SESSION['admin_viewing_tree_id']) : ($_SESSION['current_tree_id'] ?? 0);
            
            if ($treeId == 0) {
                $error = "Session Error: Tidak ada proyek keluarga yang dipilih. Silakan kembali ke Home.";
            } else {
                $myName = $_SESSION['user_name']; // Ambil nama user yang login
                // INSERT dengan user_id (Logika simpan data masuk di sini)
                $stmt = $mysqli->prepare("INSERT INTO persons (user_id, tree_id, name, gender, place_of_birth, address, phone_number, date_of_birth, is_alive, date_of_death, note, child_order, last_editor_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("iissssssissis", $targetUserId, $treeId, $name, $gender, $place_of_birth, $address, $phone_number, $dob, $alive, $dod, $note, $child_order, $myName);
                
                if ($stmt->execute()) {
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    
                    // LOGIKA RELASI OTOMATIS
                    if ($from_id > 0 && $from_rel !== '') {
                        $pid = $from_id; $rid = $newId; $rtype = $from_rel;
                        $relationSpouseOrder = ($rtype === 'pasangan') ? ($spouse_order ?? 0) : 0;
                        
                        // Insert Relasi (Pakai user_id)
                        $isDivInsert = ($rtype === 'pasangan') ? $is_divorced_new : 0;
                        $mysqli->query("INSERT INTO relations (user_id, person_id, related_person_id, relation_type, spouse_order, is_divorced) VALUES ($targetUserId, $pid, $rid, '$rtype', " . ($relationSpouseOrder ?: "NULL") . ", $isDivInsert)");
                        
                        // Insert Relasi Kebalikannya
                        $revType = null;
                        if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
                        elseif ($rtype == 'anak') {
                            $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$pid")->fetch_assoc()['gender']??'';
                            $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
                        } elseif ($rtype == 'saudara') $revType = 'saudara';
                        elseif ($rtype == 'pasangan') $revType = 'pasangan';
                        
                        if ($revType) {
                            $isDivRev = ($revType === 'pasangan') ? $is_divorced_new : 0;
                            $mysqli->query("INSERT INTO relations (user_id, person_id, related_person_id, relation_type, spouse_order, is_divorced) VALUES ($targetUserId, $rid, $pid, '$revType', " . (($revType === 'pasangan' && $relationSpouseOrder > 0) ? $relationSpouseOrder : "NULL") . ", $isDivRev)");
                        }

                        // Logic saudara satu orang tua
                        if ($rtype === 'saudara' && !empty($_POST['same_parents'])) {
                             $resP = $mysqli->query("SELECT related_person_id, relation_type FROM relations WHERE person_id=$pid AND relation_type IN ('ayah','ibu') AND user_id=$targetUserId");
                             while($pd = $resP->fetch_assoc()) {
                                 $parId = $pd['related_person_id']; $role = $pd['relation_type'];
                                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $newId, $parId, '$role')");
                                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type) VALUES ($targetUserId, $parId, $newId, 'anak')");
                             }
                        }
                    }
                    redirect("?action=bio&id=$newId&mode=edit");
                } else {
                    $error = "Gagal menyimpan: " . $stmt->error; 
                    $action = 'add_person';
                }
            }
        }
        }
    
    // 3. UPDATE BIODATA
    elseif ($action === 'update_bio') {
        $id = intval($_POST['id']??0);
        if ($id > 0) {
            // Pastikan hanya mengupdate data milik sendiri (AND user_id=...)
            $pOld = $mysqli->query("SELECT * FROM persons WHERE id=$id AND user_id=$targetUserId")->fetch_assoc();
            
            if ($pOld) {
                $name = trim($_POST['name']??''); 
                $gender = trim($_POST['gender']??'');
                $pob = trim($_POST['place_of_birth']??'');
                $address = trim($_POST['address']??'');
                $phone_number = trim($_POST['phone_number']??'');
                $dob = empty($_POST['date_of_birth']) ? null : $_POST['date_of_birth'];
                $alive = ($_POST['is_alive'] === '') ? 1 : (int)$_POST['is_alive'];
                $dod = ($alive == 0 && !empty($_POST['date_of_death'])) ? $_POST['date_of_death'] : null;
                $note = trim($_POST['note']??'');
                $child_order = (isset($_POST['child_order']) && intval($_POST['child_order']) > 0) ? intval($_POST['child_order']) : null;
                $photoPath = handle_photo_upload('photo', $pOld['photo']);
                $myName = $_SESSION['user_name'];

                $stmt = $mysqli->prepare("UPDATE persons SET name=?, gender=?, place_of_birth=?, address=?, phone_number=?, date_of_birth=?, is_alive=?, date_of_death=?, note=?, photo=?, child_order=?, last_editor_name=? WHERE id=? AND user_id=?");
                $stmt->bind_param("ssssssissssisi", $name, $gender, $pob, $address, $phone_number, $dob, $alive, $dod, $note, $photoPath, $child_order, $myName, $id, $targetUserId);
                

                if ($stmt->execute()) {
                    $bio_success = "Update berhasil."; 
                    
                    // Update urutan pasangan (spouse_order) jika ada
                    if (isset($_POST['spouse_order']) && is_array($_POST['spouse_order'])) {
                        foreach ($_POST['spouse_order'] as $relId => $sOrder) {
                            $relId = intval($relId);
                            $sOrder = intval($sOrder);
                            if ($relId > 0) {
                                $sOrderVal = ($sOrder > 0) ? $sOrder : "NULL";
                                $mysqli->query("UPDATE relations SET spouse_order=$sOrderVal WHERE id=$relId AND person_id=$id AND user_id=$targetUserId");
                                
                                // Update relasi sebaliknya (B -> A)
                                $relRes = $mysqli->query("SELECT related_person_id FROM relations WHERE id=$relId")->fetch_assoc();
                                if ($relRes) {
                                    $relatedPersonId = $relRes['related_person_id'];
                                    $mysqli->query("UPDATE relations SET spouse_order=$sOrderVal WHERE person_id=$relatedPersonId AND related_person_id=$id AND relation_type='pasangan' AND user_id=$targetUserId");
                                }
                            }
                        }
                    }
                } else {
                    $bio_error = $stmt->error;
                }
                $stmt->close();
                
                $action = 'bio'; $_GET['id'] = $id; $_GET['mode'] = 'view';
            } else {
                $bio_error = "Data tidak ditemukan atau bukan milik Anda.";
            }
        }
    }
    
    // 4. BUAT RELASI BARU (Manual)
    elseif (isset($_POST['create_relation'])) {
        $pid = intval($_POST['person_id']); 
        $rid = intval($_POST['related_person_id']); 
        $rtype = $_POST['relation_type'];
        $isDivorcedVal = (isset($_POST['is_divorced']) && $_POST['is_divorced'] == '1') ? 1 : 0;
        
        if ($pid > 0 && $rid > 0 && $rtype) {
             // Insert A -> B
             $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type, is_divorced) VALUES ($targetUserId, $pid, $rid, '$rtype', $isDivorcedVal)");
             // Jika sudah ada, update is_divorced
             $mysqli->query("UPDATE relations SET is_divorced=$isDivorcedVal WHERE user_id=$targetUserId AND person_id=$pid AND related_person_id=$rid AND relation_type='$rtype'");
             
             // Insert B -> A (Kebalikannya)
             $revType = null;
             if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
             elseif ($rtype == 'anak') {
                 $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$pid")->fetch_assoc()['gender']??'';
                 $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
             } elseif ($rtype == 'saudara') $revType = 'saudara';
             elseif ($rtype == 'pasangan') $revType = 'pasangan';
             
             if ($revType) {
                 $isDivRev = ($rtype === 'pasangan') ? $isDivorcedVal : 0;
                 $mysqli->query("INSERT IGNORE INTO relations (user_id, person_id, related_person_id, relation_type, is_divorced) VALUES ($targetUserId, $rid, $pid, '$revType', $isDivRev)");
                 $mysqli->query("UPDATE relations SET is_divorced=$isDivRev WHERE user_id=$targetUserId AND person_id=$rid AND related_person_id=$pid AND relation_type='$revType'");
             }
             redirect("?action=bio&id=$pid&mode=view");
        }
    }
}

// --- LOGIKA PROYEK KELUARGA (TREE) ---

// 1. BUAT TREE BARU
if (isset($_POST['create_tree'])) {
    $treeName = trim($_POST['tree_name']);
    if ($treeName) {
        $stmt = $mysqli->prepare("INSERT INTO family_trees (user_id, name) VALUES (?, ?)");
        $stmt->bind_param("is", $myUserId, $treeName);
        if($stmt->execute()) $success = "Proyek '$treeName' berhasil dibuat.";
        else $error = "Gagal membuat proyek.";
    }
}

// 2. UPDATE NAMA TREE
if (isset($_POST['update_tree'])) {
    $treeId = intval($_POST['tree_id']);
    $newName = trim($_POST['tree_name']);
    // Pastikan milik user sendiri
    $check = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($check->num_rows > 0 && $newName) {
        $stmt = $mysqli->prepare("UPDATE family_trees SET name=? WHERE id=?");
        $stmt->bind_param("si", $newName, $treeId);
        $stmt->execute();
        $success = "Nama proyek diperbarui.";
    }
}


// ... (setelah logika update_tree)

// 3. HAPUS TREE (PROYEK KELUARGA)
if (isset($_POST['delete_tree'])) {
    $delTreeId = intval($_POST['tree_id']);
    
    // Verifikasi kepemilikan sebelum menghapus
    $check = $mysqli->query("SELECT id FROM family_trees WHERE id=$delTreeId AND user_id=$myUserId");
    if ($check->num_rows > 0) {
        // Hapus Relasi yang melibatkan orang di tree ini
        $mysqli->query("DELETE FROM relations WHERE person_id IN (SELECT id FROM persons WHERE tree_id=$delTreeId) OR related_person_id IN (SELECT id FROM persons WHERE tree_id=$delTreeId)");
        
        // Hapus Orang-orang di tree ini
        $mysqli->query("DELETE FROM persons WHERE tree_id=$delTreeId");
        
        // Terakhir, Hapus Tree-nya
        $stmt = $mysqli->prepare("DELETE FROM family_trees WHERE id=?");
        $stmt->bind_param("i", $delTreeId);
        
        if ($stmt->execute()) {
            $success = "Proyek keluarga berhasil dihapus selamanya.";
            // Reset session jika yang dihapus adalah tree yang sedang aktif
            if (($_SESSION['current_tree_id'] ?? 0) == $delTreeId) {
                unset($_SESSION['current_tree_id']);
                unset($_SESSION['current_tree_name']);
            }
        } else {
            $error = "Gagal menghapus proyek.";
        }
        $stmt->close();
    } else {
        $error = "Data tidak ditemukan atau akses ditolak.";
    }
}

// --- 4. LOGIKA SHARE / UNDANG KOLABORATOR (UPDATED) ---
$shareStatus = null; // Variabel untuk SweetAlert

if (isset($_POST['share_tree'])) {
    $treeId = intval($_POST['tree_id']);
    $email  = trim($_POST['email']);
    
    // Cek Owner
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    
    if ($checkOwner->num_rows > 0) {
        // 1. Cek apakah email terdaftar di sistem?
        $resUser = $mysqli->query("SELECT id, name FROM users WHERE email = '$email'");
        
        if ($resUser->num_rows > 0) {
            // === KASUS A: USER SUDAH TERDAFTAR ===
            $uData = $resUser->fetch_assoc();
            $collabId = $uData['id'];
            
            if ($collabId == $myUserId) {
                $shareStatus = ['icon'=>'error', 'title'=>'Gagal', 'text'=>'Anda tidak bisa mengundang diri sendiri.'];
            } else {
                $stmt = $mysqli->prepare("INSERT IGNORE INTO tree_collaborators (tree_id, user_id, role) VALUES (?, ?, 'editor')");
                $stmt->bind_param("ii", $treeId, $collabId);
                $stmt->execute();
                
                    if ($stmt->affected_rows > 0) {
                    $shareStatus = ['icon'=>'success', 'title'=>'Berhasil!', 'text'=>'User '.htmlspecialchars($uData['name']).' telah ditambahkan sebagai editor.'];
                    fh_send_notification($mysqli, $collabId, "Undangan Kolaborasi 📩", "Anda diundang untuk mengedit pohon keluarga.", "success");
                } else {
                    $shareStatus = ['icon'=>'warning', 'title'=>'Sudah Ada', 'text'=>'User tersebut sudah menjadi kolaborator.'];
                }
            }
        } else {
            // === KASUS B: USER BELUM TERDAFTAR (KIRIM UNDANGAN) ===
            // Di sini kita gunakan fungsi mail() PHP standar. 
            // Note: Ini hanya akan berhasil jika server hosting Anda mendukung pengiriman email.
            
            $subject = "Undangan Bergabung di FamilyHood";
            $message = "Halo,\n\nAnda diundang untuk mengelola silsilah keluarga di FamilyHood.\nSilakan daftar di aplikasi kami menggunakan email ini ($email) untuk mulai berkolaborasi.\n\nSalam,\nFamilyHood Team";
            $headers = "From: no-reply@familyhood.com";
            
            // @mail($email, $subject, $message, $headers); // Uncomment jika server mail aktif
            
            // Kita tampilkan popup sukses seolah-olah email terkirim
            $shareStatus = ['icon'=>'info', 'title'=>'Undangan Dikirim', 'text'=>'Email ini belum terdaftar di aplikasi. Sebuah email undangan pendaftaran telah dikirim ke '.$email];
        }
    } else {
        $shareStatus = ['icon'=>'error', 'title'=>'Ditolak', 'text'=>'Hanya pemilik utama yang bisa mengundang.'];
    }
}

// --- 5. HAPUS KOLABORATOR (Tetap) ---
if (isset($_POST['remove_collab'])) {
    $treeId = intval($_POST['tree_id']);
    $collabUserId = intval($_POST['collab_user_id']);
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($checkOwner->num_rows > 0) {
        $mysqli->query("DELETE FROM tree_collaborators WHERE tree_id=$treeId AND user_id=$collabUserId");
        $success = "Akses kolaborator dicabut.";
    }
}

// --- 5. HAPUS KOLABORATOR ---
if (isset($_POST['remove_collab'])) {
    $treeId = intval($_POST['tree_id']);
    $collabUserId = intval($_POST['collab_user_id']);
    
    // Cek Owner
    $checkOwner = $mysqli->query("SELECT id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($checkOwner->num_rows > 0) {
        $mysqli->query("DELETE FROM tree_collaborators WHERE tree_id=$treeId AND user_id=$collabUserId");
        $success = "Akses kolaborator dicabut.";
    }
}

// 3. LOGIKA MASUK KE PROYEK (VIEW_TREE - UPDATED FOR COLLABORATION)
if ($action === 'view_tree') {
    $treeId = intval($_GET['tree_id'] ?? 0);
    
    // Cek: Apakah saya Pemilik? ATAU Apakah saya Kolaborator?
    $isOwner = false;
    $isCollab = false;
    
    // 1. Cek Pemilik
    $check1 = $mysqli->query("SELECT id, name, user_id FROM family_trees WHERE id=$treeId AND user_id=$myUserId");
    if ($row = $check1->fetch_assoc()) {
        $isOwner = true;
        $treeName = $row['name'];
        $treeOwnerId = $row['user_id'];
    } else {
        // 2. Cek Kolaborator
        $check2 = $mysqli->query("SELECT t.id, t.name, t.user_id FROM family_trees t 
                                  JOIN tree_collaborators c ON c.tree_id = t.id 
                                  WHERE t.id=$treeId AND c.user_id=$myUserId");
        if ($row = $check2->fetch_assoc()) {
            $isCollab = true;
            $treeName = $row['name'];
            $treeOwnerId = $row['user_id']; // Pemilik Asli (bukan saya)
        }
    }

    if ($isOwner || $isCollab) {
        // Simpan ID Tree di session
        $_SESSION['current_tree_id'] = $treeId;
        $_SESSION['current_tree_name'] = $treeName;
        $_SESSION['current_tree_owner_id'] = $treeOwnerId; // PENTING: ID Pemilik Asli
        
        // Simpan status saya di tree ini (untuk UI nanti)
        $_SESSION['is_tree_owner'] = $isOwner;
        
        header("Location: ?action=home"); 
        exit;
    } else {
        $error = "Akses ditolak atau proyek tidak ditemukan.";
        $action = 'home'; 
    }
}


// --- LOGIKA HAPUS DATA (DELETE) ---

// Toggle Status Cerai
if (isset($_GET['toggle_divorced']) && $action === 'bio') {
    $relId = intval($_GET['toggle_divorced']);
    $pid   = intval($_GET['id']);
    $r = $mysqli->query("SELECT * FROM relations WHERE id=$relId AND user_id=$targetUserId AND relation_type='pasangan'")->fetch_assoc();
    if ($r) {
        $newVal = $r['is_divorced'] ? 0 : 1;
        $mysqli->query("UPDATE relations SET is_divorced=$newVal WHERE id=$relId");
        // Update relasi sebaliknya juga
        $mysqli->query("UPDATE relations SET is_divorced=$newVal WHERE person_id={$r['related_person_id']} AND related_person_id={$r['person_id']} AND relation_type='pasangan' AND user_id=$targetUserId");
    }
    redirect("?action=bio&id=$pid&mode=view");
}

// Hapus Relasi
if (isset($_GET['delete_rel']) && $action === 'bio') {
    $relId = intval($_GET['delete_rel']); 
    $pid = intval($_GET['id']);
    
    // Cek kepemilikan relasi
    $r = $mysqli->query("SELECT * FROM relations WHERE id=$relId AND user_id=$targetUserId")->fetch_assoc();
    if ($r) {
        $rid = $r['related_person_id']; 
        $rtype = $r['relation_type'];
        
        // Hapus relasi ini
        $mysqli->query("DELETE FROM relations WHERE id=$relId");
        
        // Hapus relasi sebaliknya (B -> A)
        $revType = null;
        if ($rtype == 'ayah' || $rtype == 'ibu') $revType = 'anak';
        elseif ($rtype == 'anak') {
             $pg = $mysqli->query("SELECT gender FROM persons WHERE id=$r[person_id]")->fetch_assoc()['gender']??'';
             $revType = (strtoupper($pg)=='P') ? 'ibu' : 'ayah';
        } elseif ($rtype == 'saudara') $revType = 'saudara';
        elseif ($rtype == 'pasangan') $revType = 'pasangan';
        
        if ($revType) {
            $mysqli->query("DELETE FROM relations WHERE person_id=$rid AND related_person_id=$pid AND relation_type='$revType' AND user_id=$targetUserId");
        }
    }
    redirect("?action=bio&id=$pid&mode=view");
}

// Hapus Orang
if (isset($_GET['delete_person'])) {
    $id = intval($_GET['delete_person']);
    
    // Cek kepemilikan
    $p = $mysqli->query("SELECT photo FROM persons WHERE id=$id AND user_id=$targetUserId")->fetch_assoc();
    
    if ($p) {
        if ($p['photo']) @unlink(__DIR__ . '/' . $p['photo']);
        
        // Hapus semua relasi terkait orang ini (milik user ini)
        $mysqli->query("DELETE FROM relations WHERE (person_id=$id OR related_person_id=$id) AND user_id=$targetUserId");
        
        // Hapus orangnya
        $mysqli->query("DELETE FROM persons WHERE id=$id AND user_id=$targetUserId");
        
        $success = "Anggota dihapus."; 
        $action = 'home';
    } else {
        $error = "Gagal menghapus: Data tidak ditemukan atau bukan milik Anda.";
    }
}

// if ($action === 'home') { $allPersons = fh_get_all_persons($mysqli, $targetUserId); }
if ($action === 'add_person') { 
    $activeTreeId = ($isViewingOthers && isset($_SESSION['admin_viewing_tree_id'])) ? intval($_SESSION['admin_viewing_tree_id']) : ($_SESSION['current_tree_id'] ?? 0);
    $allPersons = fh_get_persons_by_tree($mysqli, $activeTreeId); 
}

// 3. Action Bio butuh otherPersons untuk dropdown relasi, ambil dari Tree milik orang tersebut
if ($action === 'bio') {
    $id = intval($_GET['id']??0);
    if ($id > 0) {
        $currentPerson = $mysqli->query("SELECT * FROM persons WHERE id=$id")->fetch_assoc();
        if ($currentPerson) {
            $res = $mysqli->query("SELECT r.id, r.relation_type, r.related_person_id, r.spouse_order, IFNULL(r.is_divorced,0) AS is_divorced, p.name AS related_name, p.gender AS related_gender, p.photo AS related_photo FROM relations r JOIN persons p ON p.id = r.related_person_id WHERE r.person_id=$id ORDER BY FIELD(r.relation_type,'ayah','ibu','anak','saudara','pasangan'), CASE WHEN r.relation_type='pasangan' THEN COALESCE(r.spouse_order, 999999) ELSE 999999 END, p.name");
            $relations = $res->fetch_all(MYSQLI_ASSOC);
            
            // PERBAIKAN DI SINI: Ambil orang lain dalam satu tree yang sama
            $otherPersons = fh_get_persons_by_tree($mysqli, $currentPerson['tree_id']); 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>familyHood - Pohon Keluarga</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Crect width='512' height='512' rx='100' fill='%234f46e5'/%3E%3Cpath d='M256 150V250' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L150 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L362 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Ccircle cx='256' cy='140' r='60' fill='white'/%3E%3Ccircle cx='150' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3Ccircle cx='362' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3C/svg%3E">
    <link rel="shortcut icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Crect width='512' height='512' rx='100' fill='%234f46e5'/%3E%3Cpath d='M256 150V250' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L150 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L362 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Ccircle cx='256' cy='140' r='60' fill='white'/%3E%3Ccircle cx='150' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3Ccircle cx='362' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3C/svg%3E">
    <link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Crect width='512' height='512' rx='100' fill='%234f46e5'/%3E%3Cpath d='M256 150V250' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L150 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Cpath d='M256 250L362 340' stroke='white' stroke-width='32' stroke-linecap='round'/%3E%3Ccircle cx='256' cy='140' r='60' fill='white'/%3E%3Ccircle cx='150' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3Ccircle cx='362' cy='360' r='50' fill='white' fill-opacity='.92'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="assets/style.css">

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="app-body">

    <div id="page-loader">
        <div class="loader-content">
            <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="512" height="512" rx="100" fill="#4f46e5"/>
                <path d="M256 150V250" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <path d="M256 250L150 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <path d="M256 250L362 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
                <circle cx="256" cy="140" r="60" fill="white"/>
            </svg>
        </div>
    </div>

    <header class="mobile-header">
        <svg viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="512" height="512" rx="100" fill="#4f46e5"/>
            <path d="M256 150V250" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L150 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <path d="M256 250L362 340" stroke="white" stroke-width="32" stroke-linecap="round"/>
            <circle cx="256" cy="140" r="60" fill="white"/>
        </svg>
        <!-- Search bar mobile -->
        <div class="ls-wrap" id="ls-wrap-mobile">
            <div class="ls-input-row">
                <svg class="ls-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="ls-input-mobile" class="ls-input" type="search" placeholder="Cari anggota keluarga…" autocomplete="off" />
            </div>
            <div id="ls-drop-mobile" class="ls-drop" style="display:none;"></div>
        </div>
    </header>

    <div class="desktop-header">
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <svg width="30" height="30" viewBox="0 0 512 512" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="512" height="512" rx="100" fill="white"/>
                    <path d="M256 150V250" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <path d="M256 250L150 340" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <path d="M256 250L362 340" stroke="#4f46e5" stroke-width="32" stroke-linecap="round"/>
                    <circle cx="256" cy="140" r="60" fill="#4f46e5"/>
                </svg>
                <h1 style="font-size:1.2rem; margin:0;">familyHood</h1>
            </div>
            <!-- Search bar desktop -->
            <div class="ls-wrap" id="ls-wrap-desktop" style="flex:1;max-width:340px;margin:0 24px;">
                <div class="ls-input-row">
                    <svg class="ls-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="ls-input-desktop" class="ls-input" type="search" placeholder="Cari anggota keluarga…" autocomplete="off" />
                </div>
                <div id="ls-drop-desktop" class="ls-drop" style="display:none;"></div>
            </div>
            <div style="font-size:0.9rem;">
                Halo, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>
            </div>
        </div>
    </div>

    <div class="desktop-nav">
        <div class="nav-inner">
            <a href="?action=home" class="d-link <?= ($action === 'home') ? 'active' : '' ?>">🏠 Home</a>
            <a href="?action=add_person" class="d-link <?= ($action === 'add_person') ? 'active' : '' ?>">➕ Tambah Baru</a>
            <a href="?action=tree" class="d-link <?= ($action === 'tree') ? 'active' : '' ?>">🌳 Pohon Keluarga</a>
            <a href="?action=level_line" class="d-link <?= ($action === 'level_line') ? 'active' : '' ?>">📊 Level Line</a>
            
            <?php if ($isAdmin): ?>
                <a href="?action=admin_users" class="d-link <?= ($action === 'admin_users') ? 'active' : '' ?>">👤 Admin</a>
            <?php endif; ?>
            
            <a href="?action=settings" class="d-link <?= ($action === 'settings' || $action === 'support') ? 'active' : '' ?>" style="margin-left:auto;">⚙️ Akun &amp; Bantuan</a>
            <a href="?action=notifications" class="d-link <?= ($action === 'notifications') ? 'active' : '' ?>">🔔 Info</a>
            <a href="?action=logout" class="d-link" onclick="return confirm('Keluar?')" style="color:#dc2626;">🚪 Logout</a>
        </div>
    </div>

    <div class="container" id="main-content">
    <?php if ($error): ?> <div class="alert alert-error"><?= htmlspecialchars($error) ?></div> <?php endif; ?>
    <?php if ($success): ?> <div class="alert alert-success"><?= htmlspecialchars($success) ?></div> <?php endif; ?>

       <?php if ($action === 'home'): ?>
    
        <?php 
        // Cek apakah user sudah memilih pohon keluarga?
        $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        ?>

        <?php if ($activeTreeId == 0 && !$isViewingOthers): ?>
            
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2>Daftar Keluarga Besar</h2>
                    <button onclick="document.getElementById('modalCreate').style.display='flex'" class="btn btn-primary btn-sm">+ Buat Baru</button>
                </div>
        
                <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap:15px;">
                    <?php
                        // Tampilkan Tree milik sendiri DAN Tree yang dibagikan (Union Logic)
                        $sqlTrees = "
                            SELECT t.*, 'owner' as role, (SELECT COUNT(*) FROM persons p WHERE p.tree_id = t.id) as total 
                            FROM family_trees t WHERE t.user_id = $myUserId
                            UNION
                            SELECT t.*, c.role as role, (SELECT COUNT(*) FROM persons p WHERE p.tree_id = t.id) as total 
                            FROM family_trees t 
                            JOIN tree_collaborators c ON c.tree_id = t.id 
                            WHERE c.user_id = $myUserId
                            ORDER BY id DESC
                        ";
                        $trees = $mysqli->query($sqlTrees);
                        
                        // --- MULAI LOOPING ---
                        while($t = $trees->fetch_assoc()):
                        ?>
                            <div style="border:1px solid #e5e7eb; border-radius:10px; padding:15px; background:#fff; position:relative;">
                                
                                <div style="position:absolute; top:10px; right:10px; display:flex; gap:5px;">
                                        <?php if($t['role'] === 'owner'): ?>
                                        <button onclick="openShareModal(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#e0e7ff; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#4338ca; display:flex; align-items:center; justify-content:center;" title="Bagikan / Share">
                                            🔗
                                        </button>
                                        
                                        <button onclick="editTree(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#f3f4f6; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#4b5563; display:flex; align-items:center; justify-content:center;">
                                            ✏️
                                        </button>
                                        
                                        <button onclick="deleteTree(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')" 
                                                style="border:none; background:#fee2e2; width:30px; height:30px; border-radius:50%; cursor:pointer; color:#991b1b; display:flex; align-items:center; justify-content:center;">
                                            🗑
                                        </button>
                                    
                                    <?php else: ?>
                                        <span style="font-size:0.75rem; background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:4px;">Shared with you</span>
                                    <?php endif; ?>
                                </div>
                                
                                <a href="?action=view_tree&tree_id=<?= $t['id'] ?>" style="text-decoration:none; color:inherit; display:block; padding-top:10px;">
                                    <div style="font-size:2rem; margin-bottom:10px;">🌳</div>
                                    <h3 style="margin:0 0 5px; font-size:1.1rem; color:#4f46e5; padding-right:80px;"><?= htmlspecialchars($t['name']) ?></h3>
                                    <p style="margin:0; font-size:0.85rem; color:#6b7280;"><?= $t['total'] ?> Anggota</p>
                                </a>
                        
                            </div>
                        <?php endwhile; ?>
                </div>
            </div>

            <div id="modalCreate" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:400px;">
                    <h3>Buat Proyek Baru</h3>
                    <form method="post">
                        <input type="hidden" name="create_tree" value="1">
                        <input type="text" name="tree_name" placeholder="Nama Keluarga Besar..." required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:5px;">
                        <div style="text-align:right;">
                            <button type="button" onclick="document.getElementById('modalCreate').style.display='none'" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modalEdit" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:400px;">
                    <h3>Ubah Nama Proyek</h3>
                    <form method="post">
                        <input type="hidden" name="update_tree" value="1">
                        <input type="hidden" name="tree_id" id="edit_tree_id">
                        <input type="text" name="tree_name" id="edit_tree_name" required style="width:100%; padding:10px; margin-bottom:10px; border:1px solid #ddd; border-radius:5px;">
                        <div style="text-align:right;">
                            <button type="button" onclick="document.getElementById('modalEdit').style.display='none'" class="btn btn-secondary">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div id="modalShare" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; z-index:999;">
                <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:450px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">Bagikan Keluarga</h2>
                        <button onclick="document.getElementById('modalShare').style.display='none'" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#6b7280;">&times;</button>
                    </div>
                    
                    <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">
                        Undang orang lain untuk mengedit <b id="share_tree_name_disp" style="color:#4f46e5;"></b> bersama.
                    </p>
                    
                    <form method="post">
                        <input type="hidden" name="share_tree" value="1">
                        <input type="hidden" name="tree_id" id="share_tree_id">
                        
                        <div style="display:flex; gap:10px;">
                            <input type="email" name="email" required placeholder="Masukkan email teman..." style="flex:1; padding:10px; border:1px solid #d1d5db; border-radius:8px;">
                            <button type="submit" class="btn btn-primary">Undang</button>
                        </div>
                    </form>
            
                    <div style="margin-top:25px; border-top:1px solid #f3f4f6; padding-top:15px;">
                        <h4 style="margin:0 0 10px; font-size:0.9rem; color:#374151;">🔒 Siapa yang punya akses?</h4>
                        
                        <ul id="collab_list_container" style="list-style:none; padding:0; margin:0; max-height:200px; overflow-y:auto;">
                            <li style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:10px;">Memuat data...</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div id="modalDelete" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
                <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:400px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                        <div style="text-align:center; margin-bottom:15px;">
                        <div style="font-size:3rem; margin-bottom:10px;">⚠️</div>
                        <h3 style="margin:0; color:#991b1b;">Hapus Keluarga?</h3>
                        <p style="color:#6b7280; font-size:0.9rem; margin-top:5px;">Anda akan menghapus: <br><strong id="del_tree_name_disp" style="color:#1f2937;"></strong></p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="delete_tree" value="1">
                        <input type="hidden" name="tree_id" id="del_tree_id">
                        
                        <div style="background:#fef2f2; border:1px solid #fecaca; padding:12px; border-radius:8px; font-size:0.85rem; color:#7f1d1d; margin-bottom:20px;">
                            Tindakan ini <strong>tidak dapat dibatalkan</strong>. Semua data anggota keluarga dan foto di dalam proyek ini akan hilang selamanya.
                        </div>
                        
                        <label style="display:flex; align-items:start; gap:10px; cursor:pointer; margin-bottom:20px; font-size:0.9rem; user-select:none;">
                            <input type="checkbox" id="confirm_delete_check" onchange="toggleDeleteBtn(this)" style="width:20px; height:20px; margin-top:2px;">
                            <span>Saya mengerti dan ingin menghapus data ini secara permanen.</span>
                        </label>
            
                        <div style="display:flex; gap:10px;">
                            <button type="button" onclick="document.getElementById('modalDelete').style.display='none'" class="btn btn-secondary flex-1">Batal</button>
                            <button type="submit" id="btn_delete_submit" class="btn btn-danger flex-1" disabled style="opacity:0.5; cursor:not-allowed;">🗑 Hapus</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php else: ?>

            <?php 
                // Tentukan Tree ID yang akan ditampilkan
                $treeIdToDisplay = $activeTreeId;
                $treeNameToDisplay = $_SESSION['current_tree_name'] ?? 'Daftar Anggota';

                // Jika Admin sedang mode View As, gunakan Tree ID dari session admin
                if ($isAdmin && $isViewingOthers) {
                    $treeIdToDisplay = $_SESSION['admin_viewing_tree_id'] ?? 0;
                    $treeNameToDisplay = "Melihat Pohon Keluarga " . htmlspecialchars($_SESSION['admin_viewing_tree_name'] ?? 'User Lain');
                }

                // Ambil data anggota berdasarkan Tree ID yang aktif/diintip
                $allPersons = fh_get_persons_by_tree($mysqli, $treeIdToDisplay); 
            ?>

            <div class="card modern-card">
                <div class="page-header">
                    <a href="?action=reset_tree" class="back-button-link">← Kembali</a>
                    <h2 class="tree-title"><?= htmlspecialchars($treeNameToDisplay) ?></h2>
                    <a href="?action=add_person" class="btn btn-primary btn-add-member">+ Anggota</a>
                </div>
                
                <?php if (empty($allPersons)): ?>
                    <p style="text-align:center; padding:20px; color:#6b7280;">Belum ada anggota di keluarga ini.</p>
                <?php else: ?>
                    <ul class="person-list">
                        <?php foreach ($allPersons as $p): ?>
                           <li class="person-item">
                                <a href="?action=bio&id=<?= $p['id'] ?>&mode=view" class="person-left">
                                    <div class="person-avatar">
                                        <?php if (!empty($p['photo'])): ?>
                                            <img src="<?= htmlspecialchars($p['photo']) ?>">
                                        <?php else: ?>
                                            <?= render_avatar_svg($p['gender'] ?? '') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="person-info">
                                        <span class="person-name"><?= htmlspecialchars($p['name']) ?></span>
                                        <?php if(isset($p['date_of_birth']) && $p['date_of_birth']): ?>
                                            <small style="color:#9ca3af; font-size:0.75rem;">
                                                Lahir: <?= date('Y', strtotime($p['date_of_birth'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </a>
                           </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        <?php endif; ?>
            
            
            
            
        
            
        
        <?php elseif ($action === 'view_tree'): ?>
            
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <div>
                        <a href="?action=home" style="font-size:0.8rem; color:#6b7280;">← Kembali</a>
                        <h2 style="margin-top:5px;"><?= htmlspecialchars($_SESSION['current_tree_name'] ?? 'Home') ?></h2>
                    </div>
                    <a href="?action=add_person" class="btn btn-primary btn-sm">+ Anggota</a>
                </div>
                
                <?php if (empty($allPersons)): ?>
                    <p style="text-align:center; padding:20px;">Belum ada anggota.</p>
                <?php else: ?>
                    <ul class="person-list">
                        <?php foreach ($allPersons as $p): ?>
                           <li class="person-item">
                                <a href="?action=bio&id=<?= $p['id'] ?>&mode=view" class="person-left">
                                    <div class="person-avatar">
                                        <?php if (!empty($p['photo'])): ?>
                                            <img src="<?= htmlspecialchars($p['photo']) ?>">
                                        <?php else: ?>
                                            <?= render_avatar_svg($p['gender'] ?? '') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="person-info">
                                        <span class="person-name"><?= htmlspecialchars($p['name']) ?></span>
                                        </div>
                                </a>
                                </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        
        <?php elseif ($action === 'add_person'): ?>
        <div class="card">
            
            <?php
                $req_id  = intval($_GET['from_id'] ?? 0); $req_rel = $_GET['relation_type'] ?? ''; $pre_gender = '';
                if ($req_rel === 'ibu') $pre_gender = 'P'; elseif ($req_rel === 'ayah') $pre_gender = 'L';
                elseif ($req_rel === 'pasangan' && $req_id > 0) { $r = $mysqli->query("SELECT gender FROM persons WHERE id=$req_id")->fetch_assoc(); if ($r) $pre_gender = ($r['gender'] === 'L') ? 'P' : 'L'; }
            ?>
            
            <h2>Tambah Anggota Keluarga<?php if($req_rel !== '') { echo ' : ' . ucfirst($req_rel); } ?></h2>
            <form method="post" action="?action=add_person">
                <input type="hidden" name="create_person_full" value="1">
                <input type="hidden" name="from_id" value="<?= $req_id ?>">
                <input type="hidden" name="from_relation_type" value="<?= htmlspecialchars($req_rel) ?>">
                <label>Nama Lengkap</label>
                <input type="text" name="name" required placeholder="Contoh: Budi Santoso">
                <label>Jenis Kelamin</label>
                <div style="display:flex; gap:20px; margin-top:5px; margin-bottom:15px;">
                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; white-space:nowrap;">
                        <input type="radio" name="gender" value="L" required <?= ($pre_gender==='L')?'checked':'' ?>> 
                        Laki-laki
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; white-space:nowrap;">
                        <input type="radio" name="gender" value="P" required <?= ($pre_gender==='P')?'checked':'' ?>> 
                        Perempuan
                    </label>
                </div>
                <div class="flex">
                    <div class="flex-1"><label>Tempat Lahir</label><input type="text" name="place_of_birth"></div>
                    <div class="flex-1"><label>Tanggal Lahir</label><input type="date" name="date_of_birth"></div>
                </div>
                <div class="flex">
                    <div class="flex-1"><label>Alamat</label><input type="text" name="address" placeholder="Contoh: Jl. Merdeka No. 10"></div>
                    <div class="flex-1"><label>Nomor HP</label><input type="tel" name="phone_number" placeholder="Contoh: 62812345678"></div>
                </div>
                <label>Status Hidup</label>
                <select name="is_alive" id="is_alive_add"><option value="1">Hidup</option><option value="0">Wafat</option></select>
                <div id="date_of_death_field_add" style="display:none;">
                    <label>Tanggal Wafat</label>
                    <input type="date" name="date_of_death" id="date_of_death_add">
                </div>
                <label>Catatan</label>
                <textarea name="note" placeholder="Tambahkan catatan khusus..."></textarea>
                
                <?php if ($req_rel === 'pasangan'): ?>
                    <label>Pasangan ke</label>
                    <input type="number" name="spouse_order" min="1" placeholder="Contoh: 1, 2, 3...">
                    <div class="alert alert-error" style="margin-top:8px;">
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="is_divorced" value="1" style="width:auto;"> Sudah Cerai?
                        </label>
                    </div>
                <?php endif; ?>

                <?php if ($req_rel === 'anak'): ?>
                    <label>Anak ke berapa</label>
                    <input type="number" name="child_order" min="1" placeholder="Contoh: 1, 2, 3...">
                <?php endif; ?>
                
                <?php if ($req_rel === 'saudara'): ?>
                    <div class="alert alert-success" style="margin-top:10px;">
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="same_parents" value="1" checked style="width:auto;"> Satu Orang Tua?
                        </label>
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; margin-top:5px;">
                            <input type="checkbox" name="link_siblings" value="1" checked style="width:auto;"> Hubungkan ke saudara lain?
                        </label>
                    </div>
                <?php endif; ?>
                <?php if ($req_rel === 'anak'): ?>
                    <div class="alert alert-success" style="margin-top:10px;">
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px;">
                            <input type="checkbox" name="link_spouse" value="1" checked style="width:auto;"> Jadikan pasangan sbg ortu juga?
                        </label>
                        <label style="margin:0; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:5px; margin-top:5px;">
                            <input type="checkbox" name="link_existing_children" value="1" checked style="width:auto;"> Hubungkan dengan kakak/adik?
                        </label>
                    </div>
                <?php endif; ?>

                <div style="margin-top:20px;">
                    <button type="submit" class="btn btn-primary">Simpan Data</button>
                    <a href="?action=home" class="btn btn-secondary">Batal</a>
                </div>
            </form>
        </div>

    <?php elseif ($action === 'bio' && $currentPerson): ?>
        <?php $mode = $_GET['mode'] ?? 'view'; $isEdit = ($mode === 'edit'); ?>
        <?php if ($bio_error): ?> <div class="alert alert-error"><?= $bio_error ?></div> <?php endif; ?>
        <?php if ($bio_success): ?> <div class="alert alert-success"><?= $bio_success ?></div> <?php endif; ?>

        <div class="bio-grid">
            <div class="card">
                <?php if ($isEdit): ?>
                    <h2 class="section-title">Edit Biografi: <?=htmlspecialchars($currentPerson['name'])?> </h2>
                    <form method="post" action="?action=update_bio" enctype="multipart/form-data">
                        <input type="hidden" name="id" value="<?= $currentPerson['id'] ?>">
                        <div style="text-align:center; margin-bottom:10px;">
                            <img src="<?= !empty($currentPerson['photo']) ? htmlspecialchars($currentPerson['photo']) : 'assets/l.png' ?>" style="width:100px; height:100px; object-fit:cover; border-radius:10px;">
                            <input type="file" name="photo" style="margin-top:5px;">
                        </div>
                        <label>Nama</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($currentPerson['name']) ?>" required>
                        <div class="flex">
                            <div class="flex-1"><label>Tempat Lahir</label><input type="text" name="place_of_birth" value="<?= htmlspecialchars($currentPerson['place_of_birth']??'') ?>"></div>
                            <div class="flex-1"><label>Tanggal Lahir</label><input type="date" name="date_of_birth" value="<?= htmlspecialchars($currentPerson['date_of_birth']??'') ?>"></div>
                        </div>
                        <div class="flex">
                            <div class="flex-1"><label>Alamat</label><input type="text" name="address" value="<?= htmlspecialchars($currentPerson['address']??'') ?>" placeholder="Contoh: Jl. Merdeka No. 10"></div>
                            <div class="flex-1"><label>Nomor HP</label><input type="tel" name="phone_number" value="<?= htmlspecialchars($currentPerson['phone_number']??'') ?>" placeholder="Contoh: 62812345678"></div>
                        </div>
                        <div class="flex">
                            <div class="flex-1">
                            <label>Gender</label>
                            <div style="display:flex; gap:15px; margin-top:10px; align-items:center; height:42px;"> <label style="font-weight:normal; display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="gender" value="L" <?= $currentPerson['gender']=='L'?'checked':'' ?>> L
                                </label>
                                <label style="font-weight:normal; display:flex; align-items:center; gap:5px; cursor:pointer;">
                                    <input type="radio" name="gender" value="P" <?= $currentPerson['gender']=='P'?'checked':'' ?>> P
                                </label>
                            </div>
                        </div>
                            <div class="flex-1">
                                <label>Status</label>
                                <select name="is_alive" id="is_alive_edit">
                                    <option value="1" <?= $currentPerson['is_alive']==1?'selected':'' ?>>Hidup</option>
                                    <option value="0" <?= $currentPerson['is_alive']==0?'selected':'' ?>>Wafat</option>
                                </select>
                            </div>
                        </div>
                        <div id="date_of_death_field_edit" style="display:<?= $currentPerson['is_alive']==0?'block':'none' ?>;">
                            <label>Tanggal Wafat</label>
                            <input type="date" name="date_of_death" id="date_of_death_edit" value="<?= htmlspecialchars($currentPerson['date_of_death']??'') ?>">
                        </div>
                        <label>Catatan</label><textarea name="note"><?= htmlspecialchars($currentPerson['note']??'') ?></textarea>
                        
                        <label>Anak ke berapa (jika berlaku)</label>
                        <input type="number" name="child_order" min="1" value="<?= htmlspecialchars($currentPerson['child_order']??'') ?>" placeholder="Kosongkan jika bukan anak">

                        <?php
                            $spouseRelations = array_values(array_filter($relations, function($rel) {
                                return ($rel['relation_type'] ?? '') === 'pasangan';
                            }));
                        ?>
                        <?php if (!empty($spouseRelations)): ?>
                            <label>Urutan Pasangan</label>
                            <div style="display:flex; flex-direction:column; gap:10px;">
                                <?php foreach ($spouseRelations as $spouseRel): ?>
                                    <div style="display:flex; gap:10px; align-items:center; padding:10px; border:1px solid #e5e7eb; border-radius:8px;">
                                        <div style="flex:1; font-size:0.9rem; font-weight:600; color:#374151;">
                                            <?= htmlspecialchars($spouseRel['related_name']) ?>
                                        </div>
                                        <div style="width:120px;">
                                            <input type="number" name="spouse_order[<?= (int)$spouseRel['id'] ?>]" min="1" value="<?= htmlspecialchars($spouseRel['spouse_order'] ?? '') ?>" placeholder="Pasangan ke">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div style="margin-top:20px;">
                            <button type="submit" class="btn btn-primary btn-block">💾 Simpan Perubahan</button>
                            <a href="?action=bio&id=<?= $currentPerson['id'] ?>&mode=view" class="btn btn-secondary btn-block" style="margin-top:8px;">Batal</a>
                            
                            <div style="margin-top: 25px; border-top: 1px dashed #cbd5e1; padding-top: 20px;">
                                <a href="?delete_person=<?= $currentPerson['id'] ?>" 
                                   onclick="return confirm('PERINGATAN: \nApakah Anda yakin ingin menghapus <?= htmlspecialchars($currentPerson['name']) ?>?\n\nData yang dihapus tidak bisa dikembalikan.')" 
                                   class="btn btn-danger btn-block">
                                    🗑 Hapus Orang Ini
                                </a>
                            </div>
                        </div>
                    </form>
                <?php else: ?>
                    <div style="text-align:center; margin-bottom:15px;">
                         <?php $photo = ($currentPerson['gender']=='P') ? 'assets/p.png' : 'assets/l.png'; if ($currentPerson['photo']) $photo = $currentPerson['photo']; ?>
                         <img src="<?= $photo ?>" style="width:120px; height:120px; border-radius:50%; object-fit:cover; border:4px solid #fff; box-shadow:0 2px 10px rgba(0,0,0,0.1); background:#e5e7eb;">
                         <h2 style="margin:10px 0 5px;"><?= htmlspecialchars($currentPerson['name']) ?></h2>
                         <span style="font-size:0.85rem; background:<?= $currentPerson['is_alive']?'#dcfce7':'#fee2e2' ?>; color:<?= $currentPerson['is_alive']?'#166534':'#991b1b' ?>; padding:2px 8px; border-radius:99px;">
                             <?= label_alive($currentPerson['is_alive']) ?>
                         </span>
                    </div>
                    <div style="background:#f9fafb; padding:15px; border-radius:10px; font-size:0.9rem;">
                    <?php if (!empty($currentPerson['last_editor_name'])): ?>
                        <div style="margin-top:10px; text-align:right; font-size:0.75rem; color:#9ca3af; font-style:italic;">
                            ✎ Terakhir diedit oleh: <strong><?= htmlspecialchars($currentPerson['last_editor_name']) ?></strong>
                            <?php if(!empty($currentPerson['last_updated_at'])) echo ' (' . date('d/m H:i', strtotime($currentPerson['last_updated_at'])) . ')'; ?>
                        </div>
                    <?php endif; ?>    
                        <p><strong>Lahir:</strong> <?= htmlspecialchars($currentPerson['place_of_birth']??'-') ?>, <?= $currentPerson['date_of_birth'] ? date('d M Y', strtotime($currentPerson['date_of_birth'])) : '-' ?></p>
                        <?php if ($currentPerson['is_alive'] == 0 && !empty($currentPerson['date_of_death'])): ?>
                            <p><strong>Wafat:</strong> <?= date('d M Y', strtotime($currentPerson['date_of_death'])) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($currentPerson['address'])): ?>
                            <p><strong>Alamat:</strong> <?= htmlspecialchars($currentPerson['address']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($currentPerson['phone_number'])): ?>
                            <p><strong>Nomor HP:</strong> <a href="tel:<?= htmlspecialchars($currentPerson['phone_number']) ?>" style="color:#4f46e5; text-decoration:none;"><?= htmlspecialchars($currentPerson['phone_number']) ?></a></p>
                        <?php endif; ?>
                        <p><strong>Gender:</strong> <?= label_gender($currentPerson['gender']) ?></p>
                        
                        <?php if($currentPerson['note']): ?>
                            <div style="margin-top:10px; border-top:1px dashed #d1d5db; padding-top:10px;"><strong>Catatan:</strong><br><?= nl2br(htmlspecialchars($currentPerson['note'])) ?></div>
                        <?php endif; ?>
                    
                        <?php if (!empty($currentPerson['last_editor_name'])): ?>
                            <div style="margin-top:15px; padding-top:10px; border-top:2px solid #e5e7eb; text-align:right;">
                                <div style="display:inline-block; text-align:left; background:#fff; border:1px solid #e5e7eb; padding:5px 10px; border-radius:8px;">
                                    <div style="font-size:0.7rem; color:#9ca3af; text-transform:uppercase; letter-spacing:0.5px;">Terakhir diedit oleh:</div>
                                    <div style="font-weight:bold; color:#4b5563; font-size:0.85rem;">
                                        ✏️ <?= htmlspecialchars($currentPerson['last_editor_name']) ?>
                                    </div>
                                    <?php if(!empty($currentPerson['last_updated_at'])): ?>
                                        <div style="font-size:0.7rem; color:#9ca3af; margin-top:2px;">
                                            <?= date('d M Y, H:i', strtotime($currentPerson['last_updated_at'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        </div>
                        <a href="?action=bio&id=<?= $currentPerson['id'] ?>&mode=edit" class="btn btn-primary btn-block" style="margin-top:15px;">✏️ Edit Profil</a>
                <?php endif; ?>
            </div>


            <div class="card">
                    <h3 class="section-title" style="margin-top:20px;">Hubungkan</h3>
                    <div style="font-size:0.95rem; color:#475569; font-weight:600; margin-bottom:10px;">Tambahkan</div>
                <div style="display:flex; gap:5px; flex-wrap:wrap;"> <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=ayah" class="btn btn-sm btn-secondary">+ Ayah</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=ibu" class="btn btn-sm btn-secondary">+ Ibu</a>
                    
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=pasangan" class="btn btn-sm btn-success">+ Suami/Istri</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=anak" class="btn btn-sm btn-success">+ Anak</a>
                    <a href="?action=add_person&from_id=<?= $currentPerson['id'] ?>&relation_type=saudara" class="btn btn-sm btn-secondary">+ Saudara</a>
                </div>
            </div>
            <div class="card">
            <h3 class="section-title">Hubungkan dengan yg sudah ada:</h3>
                <form method="post" style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
                    <input type="hidden" name="create_relation" value="1">
                    <input type="hidden" name="person_id" value="<?= $currentPerson['id'] ?>">
                    <div class="flex">
                        <div class="flex-1">
                            <select name="related_person_id" required>
                                <option value="">- Pilih Orang -</option>
                                <?php foreach ($otherPersons as $op): if($op['id'] == $currentPerson['id']) continue; ?>
                                    <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-1">
                            <select name="relation_type" required id="create_rel_type">
                                <option value="">- Sebagai -</option>
                                <option value="ayah">Ayah</option><option value="ibu">Ibu</option><option value="anak">Anak</option><option value="saudara">Saudara</option><option value="pasangan">Pasangan</option>
                            </select>
                        </div>
                    </div>
                    <div id="create_rel_divorced_wrap" style="display:none; margin-top:6px;">
                        <label style="font-weight:600; cursor:pointer; display:flex; align-items:center; gap:6px; color:#dc2626;">
                            <input type="checkbox" name="is_divorced" value="1" style="width:auto;"> Sudah Cerai?
                        </label>
                    </div>
                    <script>
                    document.getElementById('create_rel_type').addEventListener('change', function(){
                        document.getElementById('create_rel_divorced_wrap').style.display = this.value === 'pasangan' ? 'block' : 'none';
                    });
                    </script>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:5px;">Simpan</button>
                </form>
            </div>
            <div class="card card-relations">
            <div class="card-relations-header">
                <div>
                    <h2 class="section-title">Relasi Keluarga</h2>
                    <p class="section-subtitle">Klik nama untuk membuka detail anggota.</p>
                </div>
                <span class="badge-relations">
                    <?= count($relations) ?> relasi
                </span>
            </div>
        
            <?php if (empty($relations)): ?>
                <div class="empty-relations">
                    <div class="empty-icon">📭</div>
                    <p>Belum ada relasi yang tercatat untuk anggota ini.</p>
                </div>
            <?php else: ?>
                <div class="table-relations-wrapper">
                    <table class="relations-table">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>Hubungan</th>
                                <th style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($relations as $r): ?>
                            <tr class="relation-row" 
                                onclick="window.location='?action=bio&id=<?= $r['related_person_id'] ?>&mode=view'">
                                <td>
                                    <div class="relation-person">
                                        <div class="relation-avatar">
                                            <?= strtoupper(mb_substr($r['related_name'], 0, 1)) ?>
                                        </div>
                                        <div class="relation-text">
                                            <a href="?action=bio&id=<?= $r['related_person_id'] ?>&mode=view" 
                                               class="relation-name"
                                               onclick="event.stopPropagation();">
                                                <?= htmlspecialchars($r['related_name']) ?>
                                            </a>
                                            <span class="relation-id">
                                                ID: <?= (int)$r['related_person_id'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="relation-type-pill">
                                        <?= ucfirst($r['relation_type']) ?>
                                        <?php if (($r['relation_type'] ?? '') === 'pasangan' && !empty($r['spouse_order'])): ?>
                                            <?= ' ke-' . (int)$r['spouse_order'] ?>
                                        <?php endif; ?>
                                        <?php if (($r['relation_type'] ?? '') === 'pasangan'): ?>
                                            <?= !empty($r['is_divorced']) ? ' ✂ Cerai' : ' ❤ Menikah' ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <div class="relation-actions">
                                        
                                        <?php if (($r['relation_type'] ?? '') === 'pasangan'): ?>
                                        <a href="?action=bio&id=<?= $currentPerson['id'] ?>&toggle_divorced=<?= $r['id'] ?>" 
                                           class="btn-chip <?= !empty($r['is_divorced']) ? 'btn-chip-delete' : 'btn-chip-married' ?>"
                                           onclick="event.stopPropagation(); return confirm('<?= !empty($r['is_divorced']) ? 'Tandai sebagai masih menikah?' : 'Tandai sebagai cerai?' ?>');">
                                            <?= !empty($r['is_divorced']) ? '❤ Menikah' : '✂ Cerai' ?>
                                        </a>
                                        <?php endif; ?>
                                        <a href="?action=bio&id=<?= $currentPerson['id'] ?>&delete_rel=<?= $r['id'] ?>" 
                                           class="btn-chip btn-chip-delete"
                                           onclick="event.stopPropagation(); return confirm('Hapus relasi ini?');">
                                            🗑 Hapus
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        </div>

<?php elseif ($action === 'tree'): ?>
    <div class="card">
        <div class="export-toolbar">
            <div class="export-label">
                <span style="font-size: 1.2rem;">🌳</span>
                <span style="font-weight:600; color:#374151;">Pohon Keluarga</span>
            </div>
            <?php 
                $fr = isset($_GET['filter_root']) ? '&filter_root='.intval($_GET['filter_root']) : ''; 
            ?>
            <div class="export-actions">
                <a href="?export=excel<?= $fr ?>" class="btn btn-success btn-sm">📊 Excel</a>
                <a href="?export=word<?= $fr ?>" class="btn btn-primary btn-sm">📘 Word</a>
                <a href="?export=pdf<?= $fr ?>" class="btn btn-danger btn-sm" target="_blank">📕 PDF</a>
            </div>
        </div>

        <?php
        $activeTreeId = $_SESSION['current_tree_id'] ?? 0;
        if ($isAdmin && $isViewingOthers && isset($_SESSION['admin_viewing_tree_id'])) {
            $activeTreeId = intval($_SESSION['admin_viewing_tree_id']);
        }
        
        // --- CEK APAKAH SUDAH PILIH POHON? ---
        if ($activeTreeId == 0) { 
            // Load SweetAlert (Jaga-jaga jika belum terload)
            echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
            echo '<style>body { font-family: -apple-system, system-ui, sans-serif; background: #f3f4f6; }</style>';
            
            echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: 'Pilih Keluarga Besar',
                    text: 'Anda belum memilih keluarga besar. Silakan pilih atau buat baru untuk melihat pohon silsilah.',
                    icon: 'info',
                    iconColor: '#4f46e5',
                    
                    // Tampilan Tombol Modern
                    confirmButtonText: 'Pilih Sekarang',
                    confirmButtonColor: '#4f46e5',
                    buttonsStyling: true,
                    
                    // Pengaturan Latar (Backdrop) yang Bersih
                    backdrop: `
                        rgba(255,255,255, 0.9)
                        left top
                        no-repeat
                    `,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    
                    // Animasi Masuk/Keluar yang Halus
                    showClass: {
                        popup: 'swal2-show',
                        backdrop: 'swal2-backdrop-show',
                        icon: 'swal2-icon-show'
                    },
                    hideClass: {
                        popup: 'swal2-hide',
                        backdrop: 'swal2-backdrop-hide',
                        icon: 'swal2-icon-hide'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Redirect ke Halaman Home
                        window.location.href = '?action=home';
                    }
                });
            });
            </script>"; 
            
            // Hentikan script di sini agar background halaman tidak berantakan
            exit; 
        }

        // --- HITUNG DATA GENERASI ---
        list($personsByGen, $maxGen, $maxHeight, $generationData, $allPersonsData, $parentChildren, $spouses, $childParents)
            = fh_compute_generations($mysqli, $activeTreeId);

        // --- LOGIKA REVOLUSIONER: AUTO ROOT FINDER ---
        // Jika user klik foto (focus_id), kita cari leluhur paling atasnya otomatis
        $focusId = isset($_GET['focus_id']) ? intval($_GET['focus_id']) : 0;
        $targetRootId = null;

        // Helper kecil: cari leluhur teratas di dalam tree yang sedang diload
        function fh_find_top_ancestor($startId, $childParents, $generationData) {
            $visited = [];
            $current = [$startId];
            $best = $startId;
            while (!empty($current)) {
                $next = [];
                foreach ($current as $pid) {
                    if (isset($visited[$pid])) continue;
                    $visited[$pid] = true;

                    if (isset($generationData[$pid]) && isset($generationData[$best])) {
                        if ($generationData[$pid] < $generationData[$best]) {
                            $best = $pid;
                        }
                    }

                    $parents = array_keys($childParents[$pid] ?? []);
                    foreach ($parents as $p) {
                        if (!isset($visited[$p])) $next[] = $p;
                    }
                }
                $current = array_unique($next);
            }

            if (isset($generationData[$best]) && $generationData[$best] == 1) {
                return $best;
            }

            // Jika belum ada data generasi 1, cari nilai minimum
            $minGen = PHP_INT_MAX; $minId = $startId;
            foreach ($visited as $pid => $_) {
                if (isset($generationData[$pid]) && $generationData[$pid] < $minGen) {
                    $minGen = $generationData[$pid];
                    $minId = $pid;
                }
            }
            return $minId;
        }

        if ($focusId > 0 && isset($allPersonsData[$focusId])) {
            $targetRootId = fh_find_top_ancestor($focusId, $childParents, $generationData);
        } else {
            $targetRootId = isset($_GET['filter_root']) ? intval($_GET['filter_root']) : null;
        }

        // --- SIAPKAN MENU FILTER ---
        $rootCandidates = [];
        foreach ($allPersonsData as $pid => $info) {
            if (($generationData[$pid] ?? 999) == 1) { $rootCandidates[] = $pid; }
        }
        usort($rootCandidates, function($a, $b) use ($allPersonsData) { 
            return strnatcasecmp($allPersonsData[$a]['name'], $allPersonsData[$b]['name']); 
        });

        $menuList = [];
        $processedRoots = [];
        foreach ($rootCandidates as $pid) {
            if (isset($processedRoots[$pid])) continue;
            $pName = $allPersonsData[$pid]['name'];
            $spouseId = null;
            if (!empty($spouses[$pid])) {
                foreach ($spouses[$pid] as $sid => $_) {
                    if (isset($generationData[$sid]) && $generationData[$sid] == 1) {
                        $spouseId = $sid;
                        $processedRoots[$sid] = true;
                        if (($allPersonsData[$sid]['gender']??'') === 'L') { $pName = $allPersonsData[$sid]['name']; }
                    }
                }
            }
            $processedRoots[$pid] = true;
            $visited = [];
            $count = fh_count_descendants([$pid], $spouses, $parentChildren, $visited);
            $mainIdToRender = (($allPersonsData[$pid]['gender']??'') === 'L') ? $pid : ($spouseId ?? $pid);
            
            $menuList[] = ['id' => $mainIdToRender, 'label' => $pName, 'count' => $count];
        }

        // Tentukan siapa yang dirender
        $rootsToRender = [];
        if ($targetRootId) {
            $rootsToRender[] = $targetRootId;
        } else {
            foreach ($menuList as $m) $rootsToRender[] = $m['id'];
        }
        ?>

        <div style="margin-bottom: 20px;">
            <div style="font-size:0.85rem; color:#6b7280; margin-bottom:5px; font-weight:600;">
                Filter Bani / Leluhur:
            </div>
                <div class="pill-menu">
                <a href="?action=tree" class="pill-item <?= ($targetRootId === null) ? 'active' : '' ?>">👥 Semua</a>
                <?php foreach ($menuList as $menu): ?>
                    <a href="?action=tree&filter_root=<?= $menu['id'] ?>" 
                       class="pill-item <?= ($targetRootId == $menu['id']) ? 'active' : '' ?>">
                       • <?= htmlspecialchars($menu['label']) ?> 
                       <span style="font-size:0.75em; opacity:0.8;">(<?= $menu['count'] ?>)</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php
            $graphPersons = [];
            foreach ($allPersonsData as $pid => $person) {
                $photoUrl = (($person['gender'] ?? '') === 'P') ? 'assets/p.png' : 'assets/l.png';
                if (!empty($person['photo']) && file_exists(__DIR__ . '/' . $person['photo'])) {
                    $photoUrl = $person['photo'];
                }
                $graphPersons[$pid] = [
                    'id' => (int)$pid,
                    'name' => $person['name'] ?? ('ID '.$pid),
                    'gender' => $person['gender'] ?? '',
                    'is_alive' => (int)($person['is_alive'] ?? 1),
                    'photo' => $photoUrl
                ];
            }
        ?>

        <div class="tree-legend">
            <span class="tree-legend-item"><span class="tree-legend-line"></span> Garis Orang Tua-Anak</span>
            <span class="tree-legend-item"><span class="tree-legend-line spouse"></span> Garis Suami-Istri</span>
            <span class="tree-legend-item"><span class="tree-legend-badge married">❤</span> Masih Menikah</span>
            <span class="tree-legend-item"><span class="tree-legend-badge divorced">✂</span> Cerai</span>
        </div>

        <div class="tree-container">
            <div class="tree-graph-wrapper">
                <div id="family-graph"></div>
                <div class="graph-controls">
                    <button id="btn-reset-graph" class="btn btn-sm" title="Reset tampilan pohon">🔄 Reset</button>
                </div>
            </div>

            <div id="tree-fallback" style="display:none; margin-top:16px;">
                <div class="tree">
                    <ul>
                    <?php
                        if (empty($rootsToRender)) {
                            echo "<p style='padding:20px;'>Data tidak ditemukan.</p>";
                        } else {
                            $globalProcessed = []; 
                            foreach ($rootsToRender as $rootId) {
                                if (isset($globalProcessed[$rootId])) continue;
                                fh_render_tree_web($rootId, $allPersonsData, $spouses, $parentChildren, $childParents, $focusId);
                                $globalProcessed[$rootId] = true;
                                if (!empty($spouses[$rootId])) {
                                    foreach($spouses[$rootId] as $sid => $_) $globalProcessed[$sid] = true;
                                }
                            }
                        }
                    ?>
                    </ul>
                </div>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.29.2/cytoscape.min.js"></script>
        <script>
        (function() {
            const container = document.getElementById('family-graph');
            const fallback  = document.getElementById('tree-fallback');
            if (!container || typeof cytoscape === 'undefined') {
                if (fallback) fallback.style.display = 'block';
                return;
            }

            // ── Data dari PHP ────────────────────────────────────────────────
            const persons        = <?= json_encode($graphPersons,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const parentChildren = <?= json_encode($parentChildren,  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const childParents   = <?= json_encode($childParents,    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const spouses        = <?= json_encode($spouses,         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const roots          = <?= json_encode(array_values($rootsToRender)) ?>;
            const focusId        = <?= (int)$focusId ?>;

            // ── BFS: kumpulkan node visible ──────────────────────────────────
            const visible = new Set();
            const bfsQ    = [...roots];
            while (bfsQ.length) {
                const curr = Number(bfsQ.shift());
                if (!curr || visible.has(curr)) continue;
                visible.add(curr);
                Object.keys(parentChildren[curr] || {}).forEach(c => { const n=Number(c); if(n&&!visible.has(n)) bfsQ.push(n); });
                Object.keys(spouses[curr]        || {}).forEach(s => { const n=Number(s); if(n&&!visible.has(n)) bfsQ.push(n); });
            }
            if (!visible.size) { if (fallback) fallback.style.display='block'; return; }

            // ── Hitung level generasi via BFS dari roots ─────────────────────
            const genLevel = {};
            const genQ     = roots.map(r => ({ id: Number(r), gen: 0 }));
            const genSeen  = new Set();
            while (genQ.length) {
                const { id, gen } = genQ.shift();
                if (!id || genSeen.has(id)) continue;
                genSeen.add(id);
                if (genLevel[id] === undefined || gen < genLevel[id]) genLevel[id] = gen;
                Object.keys(spouses[id]        || {}).forEach(s => { const n=Number(s); if(visible.has(n)&&!genSeen.has(n)) genQ.push({id:n,gen}); });
                Object.keys(parentChildren[id] || {}).forEach(c => { const n=Number(c); if(visible.has(n)&&!genSeen.has(n)) genQ.push({id:n,gen:gen+1}); });
            }
            visible.forEach(id => { if (genLevel[id] === undefined) genLevel[id] = 0; });

            // ── Bangun unit keluarga: kepala → [istri-istri] ─────────────────
            // Pass 1: laki-laki jadi kepala unitnya masing-masing
            const unitOf      = {};   // personId → headId
            const unitSpouses = {};   // headId   → [istriId...]
            visible.forEach(id => {
                if (unitOf[id] !== undefined || (persons[id]?.gender||'') !== 'L') return;
                unitOf[id] = id;
                const wives = Object.keys(spouses[id]||{}).map(Number)
                              .filter(s => visible.has(s) && unitOf[s] === undefined);
                unitSpouses[id] = wives;
                wives.forEach(s => { unitOf[s] = id; });
            });
            // Pass 2: sisa (perempuan/tidak diketahui yang bukan istri siapapun)
            visible.forEach(id => {
                if (unitOf[id] !== undefined) return;
                unitOf[id] = id;
                const rem = Object.keys(spouses[id]||{}).map(Number)
                            .filter(s => visible.has(s) && unitOf[s] === undefined);
                unitSpouses[id] = rem;
                rem.forEach(s => { unitOf[s] = id; });
            });

            // ── childSet: semua anak yang ada di visible ─────────────────────
            const childSet = new Set();
            visible.forEach(pid => {
                Object.keys(parentChildren[pid]||{}).forEach(c => {
                    const n=Number(c); if(n&&visible.has(n)) childSet.add(n);
                });
            });

            // ── Assign anak ke unit kepala (proses dari generasi atas) ───────
            const heads          = [...visible].filter(id => unitOf[id] === id);
            const unitChildren   = {};   // headId → [child-unit-headId...]
            const unitChildMother = {};  // headId → { chHead: motherWid|null }
            const childClaimed   = new Set();
            heads.sort((a, b) => (genLevel[a]??0) - (genLevel[b]??0));
            heads.forEach(hId => {
                const members  = [hId, ...(unitSpouses[hId]||[])];
                const seen     = new Set();
                const arr      = [];
                const mmap     = {};
                childSet.forEach(cid => {
                    if (childClaimed.has(cid)) return;
                    const pIds = Object.keys(childParents[cid]||{}).map(Number).filter(x => visible.has(x));
                    if (!pIds.some(p => members.includes(p))) return;
                    childClaimed.add(cid);
                    const chHead = unitOf[cid] ?? cid;
                    if (!seen.has(chHead)) {
                        seen.add(chHead);
                        arr.push(chHead);
                        // Catat ibu anak ini (istri mana yang ada di pIds)
                        const motherWid = (unitSpouses[hId]||[]).find(w => pIds.includes(w)) || null;
                        mmap[chHead] = motherWid;
                    }
                });
                unitChildren[hId]    = arr;
                unitChildMother[hId] = mmap;
            });

            // ── Konstanta layout ─────────────────────────────────────────────
            const NODE_W               = 90;    // lebar efektif per node
            const SPOUSE_GAP           = 130;   // jarak antar suami-istri
            const NODE_GAP             = 56;    // jarak antar subtree saudara
            const GEN_Y_GAP            = 180;   // jarak vertikal antar generasi
            const WIFE_ROW_GAP         = 78;    // jarak vertikal antar baris istri
            const SAME_GEN_MIN_GAP     = 10;    // jarak minimum antar node dalam generasi sama
            const SAME_GEN_BASE_WIDTH  = 90;    // ukuran konsisten untuk node pada generasi yang sama

            // ── Hitung lebar subtree (bottom-up) ─────────────────────────────
            const subtW    = {};
            const calcDone = new Set();
            function calcW(hId) {
                if (calcDone.has(hId)) return subtW[hId] || NODE_W;
                calcDone.add(hId);
                const n      = (unitSpouses[hId]||[]).length;
                const numCols = Math.ceil(n / 2);
                // semua istri (termasuk row 0) melebar sesuai jumlah anak
                const kExt    = n > 0 ? Math.ceil((unitChildren[hId]||[]).length / 2) * (NODE_W / 2) : 0;
                const coupleW = 2 * (numCols * SPOUSE_GAP + kExt) + NODE_W;
                const kids    = unitChildren[hId] || [];
                const kidsW   = kids.length
                    ? kids.reduce((s,c) => s + calcW(c), 0) + (kids.length - 1) * NODE_GAP
                    : 0;
                subtW[hId] = Math.max(coupleW, kidsW, NODE_W);
                return subtW[hId];
            }
            heads.forEach(h => calcW(h));

            // ── Penempatan posisi top-down ────────────────────────────────────
            // Suami di tengah. Kolom kiri: istri 1, 3, 5, ... Kolom kanan: istri 2, 4, 6, ...
            // Setiap baris turun WIFE_ROW_GAP dari baris sebelumnya.
            const pos    = {};
            const placed = new Set();
            function placeUnit(hId, cx) {
                if (placed.has(hId)) return;
                placed.add(hId);
                const wives  = unitSpouses[hId] || [];
                const baseY  = (genLevel[hId] ?? 0) * GEN_Y_GAP;

                // Suami selalu di tengah
                pos[hId] = { x: cx, y: baseY };

                // Kolom melebar keluar; row 1+ ditambah jarak proporsional jumlah anak
                const kidsCount = (unitChildren[hId] || []).length;
                const kidsExtra = Math.ceil(kidsCount / 2) * (NODE_W / 2);
                wives.forEach((wid, i) => {
                    const col  = Math.floor(i / 2) + 1;   // 1,1,2,2,3,3,...
                    const side = (i % 2 === 0) ? -1 : 1;  // kiri,kanan,kiri,kanan,...
                    const row  = Math.floor(i / 2);
                    const colX = col * (SPOUSE_GAP + 12) + kidsExtra;
                    pos[wid] = { x: cx + side * colX, y: baseY + row * WIFE_ROW_GAP };
                    placed.add(wid);
                });

                // anak-anak: kelompokkan per ibu, urutkan kiri→kanan sesuai posisi ibu
                const kids = unitChildren[hId] || [];
                if (!kids.length) return;

                const mmap       = unitChildMother[hId] || {};
                const wivesLocal = unitSpouses[hId] || [];

                // Urutkan istri dari kiri ke kanan berdasarkan posisi X
                const sortedW = [...wivesLocal].filter(w => pos[w]).sort((a, b) => pos[a].x - pos[b].x);

                // Bangun kelompok anak per ibu
                const groups   = [];  // [{ targetX, kids }]
                const usedKids = new Set();
                sortedW.forEach(wid => {
                    const gk = kids.filter(k => !usedKids.has(k) && mmap[k] === wid);
                    if (!gk.length) return;
                    gk.forEach(k => usedKids.add(k));
                    groups.push({ targetX: pos[wid].x, kids: gk });
                });
                // Anak tanpa ibu teridentifikasi → terpusat di posisi suami
                const remain = kids.filter(k => !usedKids.has(k));
                if (remain.length) {
                    groups.push({ targetX: cx, kids: remain });
                    groups.sort((a, b) => a.targetX - b.targetX);
                }
                if (!groups.length) return;

                // Hitung lebar tiap kelompok dan posisi awal ideal (centered di targetX)
                const gw     = groups.map(g => g.kids.reduce((s,c) => s+(subtW[c]||NODE_W), 0) + (g.kids.length-1)*NODE_GAP);
                const gStart = groups.map((g, i) => g.targetX - gw[i] / 2);

                // Pass kiri→kanan: dorong ke kanan jika bertabrakan
                for (let i = 1; i < groups.length; i++) {
                    const minS = gStart[i-1] + gw[i-1] + NODE_GAP;
                    if (gStart[i] < minS) gStart[i] = minS;
                }
                // Pass kanan→kiri: dorong ke kiri jika bertabrakan (jaga simetri)
                for (let i = groups.length - 2; i >= 0; i--) {
                    const maxE = gStart[i+1] - NODE_GAP;
                    if (gStart[i] + gw[i] > maxE) gStart[i] = maxE - gw[i];
                }

                // Tempatkan tiap kelompok
                groups.forEach((g, gi) => {
                    let kx = gStart[gi] + (subtW[g.kids[0]]||NODE_W)/2;
                    g.kids.forEach((cid, i) => {
                        placeUnit(cid, kx);
                        if (i < g.kids.length-1)
                            kx += (subtW[g.kids[i]]||NODE_W)/2 + NODE_GAP + (subtW[g.kids[i+1]]||NODE_W)/2;
                    });
                });
            }

            // Temukan kepala-kepala paling atas (tidak punya orang tua yang visible)
            const topSeen = new Set();
            let curX = 0;
            heads.filter(hId => {
                const members = [hId, ...(unitSpouses[hId]||[])];
                return !members.some(m => Object.keys(childParents[m]||{}).map(Number).some(p => visible.has(p)));
            }).forEach(hId => {
                if (topSeen.has(hId)) return;
                topSeen.add(hId);
                const w = subtW[hId] || NODE_W;
                placeUnit(hId, curX + w/2);
                curX += w + NODE_W;
            });
            heads.forEach(hId => {
                if (!placed.has(hId)) { placeUnit(hId, curX); curX += (subtW[hId]||NODE_W) + NODE_W; }
            });

            // Pusatkan tree secara horizontal
            // ── Sebelum centering: posisikan extra husbands (suami lain dari istri) ──
            // Suami tanpa anak ditempatkan di sisi luar istri. Suami dengan anak dibiarkan.
            heads.forEach(hId => {
                (unitSpouses[hId] || []).forEach(wid => {
                    if (!pos[wid]) return;
                    const side = pos[wid].x <= pos[hId].x ? -1 : 1;
                    const extraHubs = Object.keys(spouses[wid] || {})
                        .map(Number)
                        .filter(h2 => h2 !== hId && visible.has(h2));
                    extraHubs.forEach((h2, idx) => {
                        if ((unitChildren[h2] || []).length === 0) {
                            // Geser ke luar dari istri
                            pos[h2] = { x: pos[wid].x + side * (idx + 1) * SPOUSE_GAP, y: pos[wid].y };
                        }
                    });
                });
            });

            const allXs = [...visible].filter(id => pos[id]).map(id => pos[id].x);
            const shift  = -(Math.min(...allXs) + Math.max(...allXs)) / 2;
            visible.forEach(id => { if (pos[id]) pos[id].x += shift; });

            // ── Pastikan semua anggota pada generasi yang sama tidak terlalu berdekatan ──
            const generations = {};
            [...visible].forEach(id => {
                if (!pos[id]) return;
                const gen = genLevel[id] ?? 0;
                if (!generations[gen]) generations[gen] = [];
                generations[gen].push(id);
            });
            Object.values(generations).forEach(ids => {
                ids.sort((a, b) => pos[a].x - pos[b].x);
                for (let i = 1; i < ids.length; i++) {
                    const prevId = ids[i - 1];
                    const currId = ids[i];
                    const minX = pos[prevId].x + SAME_GEN_BASE_WIDTH + SAME_GEN_MIN_GAP;
                    if (pos[currId].x < minX) {
                        const shiftRight = minX - pos[currId].x;
                        for (let j = i; j < ids.length; j++) {
                            pos[ids[j]].x += shiftRight;
                        }
                    }
                }
            });
            const allXs2 = [...visible].filter(id => pos[id]).map(id => pos[id].x);
            const shift2  = -(Math.min(...allXs2) + Math.max(...allXs2)) / 2;
            visible.forEach(id => { if (pos[id]) pos[id].x += shift2; });

            // ── Bangun elemen Cytoscape ──────────────────────────────────────
            const elements = [];

            // Node orang
            visible.forEach(id => {
                const p = persons[id];
                if (!p || !pos[id]) return;
                elements.push({
                    group: 'nodes',
                    data: {
                        id: String(id),
                        label: (Number(p.is_alive)===0 ? 'alm. ':'') + p.name,
                        photo: p.photo||'', gender: p.gender||'',
                        isFocus: focusId===id, personId: id
                    },
                    position: pos[id]
                });
                // Badge edit: ikon pensil kecil, ditempatkan di atas foto supaya nama tetap di bawah gambar
                elements.push({
                    group: 'nodes',
                    data: { id: `edit-${id}`, label: '', isEditBadge: true, personId: id },
                    position: { x: pos[id].x, y: pos[id].y - 36 }
                });
            });

            // ── Bangun badge pasangan + edges ──────────────────────────────
            // Setiap pasangan suami-istri mendapat badge ❤ (menikah) atau ✂ (cerai)
            // Garis suami-istri terbagi dua: suami→badge→istri
            // Anak terhubung ke badge ibu mereka masing-masing
            const builtBadges = new Set();
            heads.forEach(hId => {
                const wives = unitSpouses[hId] || [];
                const kids  = unitChildren[hId] || [];
                if (!pos[hId]) return;

                if (wives.length > 0) {
                    const badgeList = [];
                    wives.forEach((wid, wifeIdx) => {
                        if (!pos[wid]) return;
                        const wifeRow = Math.floor(wifeIdx / 2);
                        const isDiv   = !!(spouses[hId]?.[wid]?.is_divorced);
                        const bid     = `badge-${Math.min(hId,wid)}-${Math.max(hId,wid)}`;
                        const eType   = isDiv ? 'spouse-divorced' : 'spouse';
                        badgeList.push({ bid, isDiv });
                        builtBadges.add(bid);
                        if (wifeRow === 0) {
                            // Baris sama → garis lurus melalui badge di tengah
                            const mx = (pos[hId].x + pos[wid].x) / 2;
                            const my = pos[hId].y;
                            elements.push({ group:'nodes', data:{ id:bid, label: isDiv ? '\u2702' : '\u2764', isBadge:true, isDivorced:isDiv }, position:{ x:mx, y:my } });
                            elements.push({ group:'edges', data:{ id:`sp1-${hId}-${wid}`, source:String(hId), target:bid, edgeType:eType } });
                            elements.push({ group:'edges', data:{ id:`sp2-${hId}-${wid}`, source:bid, target:String(wid), edgeType:eType } });
                        } else {
                            // Baris bawah → garis L: suami turun ke elbow, lalu belok horizontal ke istri
                            const eid = `elbow-${hId}-${wid}`;
                            elements.push({ group:'nodes', data:{ id:eid, label:'', isAnchor:true }, position:{ x:pos[hId].x, y:pos[wid].y } });
                            // Badge di tengah segmen horizontal (elbow → istri)
                            const bx = (pos[hId].x + pos[wid].x) / 2;
                            const by = pos[wid].y;
                            elements.push({ group:'nodes', data:{ id:bid, label: isDiv ? '\u2702' : '\u2764', isBadge:true, isDivorced:isDiv }, position:{ x:bx, y:by } });
                            // Garis: suami→elbow (vertikal), elbow→badge, badge→istri (horizontal)
                            elements.push({ group:'edges', data:{ id:`sp0-${hId}-${wid}`, source:String(hId), target:eid, edgeType:eType } });
                            elements.push({ group:'edges', data:{ id:`sp1-${hId}-${wid}`, source:eid, target:bid, edgeType:eType } });
                            elements.push({ group:'edges', data:{ id:`sp2-${hId}-${wid}`, source:bid, target:String(wid), edgeType:eType } });
                        }
                    });

                    // Routing anak: setiap anak → badge ibu mereka masing-masing
                    if (kids.length > 0) {
                        // Build map: wid → bid
                        const widToBid = {};
                        wives.forEach(wid => {
                            widToBid[wid] = `badge-${Math.min(hId,wid)}-${Math.max(hId,wid)}`;
                        });
                        kids.forEach(chHead => {
                            // Cari ibu dari child unit ini
                            const parentIds = Object.keys(childParents[chHead]||{}).map(Number);
                            const motherWid = wives.find(wid => parentIds.includes(wid));
                            let srcId;
                            if (motherWid && widToBid[motherWid]) {
                                srcId = widToBid[motherWid];          // badge ibu spesifik
                            } else if (badgeList.length === 1) {
                                srcId = badgeList[0].bid;             // satu-satunya badge
                            } else {
                                srcId = String(hId);                  // fallback ke ayah
                            }
                            elements.push({ group:'edges', data:{ id:`pc-${srcId}-${chHead}`, source:srcId, target:String(chHead), edgeType:'parent-child' } });
                        });
                    }
                } else {
                    // Orang tua tunggal: edge langsung ke anak
                    kids.forEach(chHead => {
                        elements.push({ group:'edges', data:{ id:`pc-${hId}-${chHead}`, source:String(hId), target:String(chHead), edgeType:'parent-child' } });
                    });
                }
            });

            // ── Badge & edge untuk extra husbands (suami lain dari istri) ──
            heads.forEach(hId => {
                (unitSpouses[hId] || []).forEach(wid => {
                    if (!pos[wid]) return;
                    const extraHubs = Object.keys(spouses[wid] || {})
                        .map(Number)
                        .filter(h2 => h2 !== hId && visible.has(h2) && pos[h2]);
                    extraHubs.forEach(h2 => {
                        const bid = `badge-${Math.min(wid,h2)}-${Math.max(wid,h2)}`;
                        if (builtBadges.has(bid)) return;
                        builtBadges.add(bid);
                        const isDiv = !!(spouses[wid]?.[h2]?.is_divorced);
                        const eType = isDiv ? 'spouse-divorced' : 'spouse';
                        // Garis lurus wid → badge → h2
                        const mx = (pos[wid].x + pos[h2].x) / 2;
                        const my = (pos[wid].y + pos[h2].y) / 2;
                        elements.push({ group:'nodes', data:{ id:bid, label: isDiv ? '\u2702' : '\u2764', isBadge:true, isDivorced:isDiv }, position:{ x:mx, y:my } });
                        elements.push({ group:'edges', data:{ id:`sp1-${wid}-${h2}`, source:String(wid), target:bid, edgeType:eType } });
                        elements.push({ group:'edges', data:{ id:`sp2-${wid}-${h2}`, source:bid, target:String(h2), edgeType:eType } });
                    });
                });
            });

            // ── Inisialisasi Cytoscape ───────────────────────────────────────
            // Ikon pensil SVG (putih di atas indigo)
            const _pencilSvg = `<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'><path d='M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z'/></svg>`;
            const _pencilDataUri = 'data:image/svg+xml;base64,' + btoa(_pencilSvg);

            const cy = cytoscape({
                container,
                elements,
                layout: { name: 'preset' },
                style: [
                    {
                        selector: 'node[!isAnchor][!isBadge]',
                        style: {
                            'width': 60, 'height': 60,
                            'background-fit': 'cover', 'background-clip': 'node',
                            'background-image': function(ele) {
                                const photo = ele.data('photo');
                                if (photo) return photo;
                                const g    = ele.data('gender');
                                const fill = g==='L' ? '#bfdbfe' : g==='P' ? '#fbcfe8' : '#e5e7eb';
                                const svg  = `<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><rect width='100%' height='100%' fill='#fff'/><circle cx='100' cy='72' r='48' fill='${fill}'/><rect x='36' y='132' rx='16' width='128' height='44' fill='${fill}'/></svg>`;
                                return 'data:image/svg+xml;base64,' + btoa(svg);
                            },
                            'border-width':     function(ele) { return ele.data('isFocus') ? 4 : 2; },
                            'border-color':     function(ele) { return ele.data('isFocus') ? '#4f46e5' : '#94a3b8'; },
                            'background-color': '#ffffff',
                            'label':            'data(label)',
                            'text-valign':      'bottom', 'text-halign': 'center',
                            'font-size':        function(ele) { return ele.data('isFocus') ? 14 : 11; },
                            'font-family':      'Segoe UI, sans-serif',
                            'color':            '#0f172a',
                            'text-margin-y':    8, 'text-wrap': 'wrap', 'text-max-width': 100,
                            'shape':            'ellipse', 'overlay-padding': 4
                        }
                    },
                    {
                        selector: 'node[isAnchor]',
                        style: { 'width': 1, 'height': 1, 'background-color': 'transparent', 'border-width': 0, 'label': '', 'events': 'no', 'opacity': 0 }
                    },
                    {
                        selector: 'node[isBadge]',
                        style: {
                            'width': 22, 'height': 22, 'shape': 'ellipse',
                            'background-color': function(ele) { return ele.data('isDivorced') ? '#6b7280' : '#ef4444'; },
                            'border-width': 0,
                            'label': 'data(label)',
                            'text-valign': 'center', 'text-halign': 'center',
                            'font-size': 11, 'color': '#ffffff',
                            'events': 'no', 'z-index': 10
                        }
                    },
                    {
                        selector: 'node[isEditBadge]',
                        style: {
                            'width': 20, 'height': 20, 'shape': 'ellipse',
                            'background-color': '#4f46e5',
                            'background-image': _pencilDataUri,
                            'background-fit': 'contain',
                            'background-clip': 'none',
                            'border-width': 2, 'border-color': '#ffffff',
                            'label': '',
                            'z-index': 20, 'cursor': 'pointer'
                        }
                    },
                    {
                        selector: 'edge[edgeType="spouse"]',
                        style: { 'width': 2.4, 'line-color': '#ef4444', 'line-style': 'dashed', 'line-dash-pattern': [8,6], 'curve-style': 'straight', 'target-arrow-shape': 'none', 'source-arrow-shape': 'none' }
                    },
                    {
                        selector: 'edge[edgeType="spouse-divorced"]',
                        style: { 'width': 2.4, 'line-color': '#6b7280', 'line-style': 'dashed', 'line-dash-pattern': [6,4], 'curve-style': 'straight', 'target-arrow-shape': 'none', 'source-arrow-shape': 'none' }
                    },
                    {
                        selector: 'edge[edgeType="parent-child"]',
                        style: { 'width': 2.2, 'line-color': '#94a3b8', 'line-style': 'solid', 'curve-style': 'straight', 'target-arrow-shape': 'none', 'source-arrow-shape': 'none' }
                    }
                ],
                userZoomingEnabled:  true,
                userPanningEnabled:  true,
                boxSelectionEnabled: false,
                minZoom: 0.03,
                maxZoom: 3
            });

            function doFit() { cy.resize(); cy.fit(undefined, 40); }
            requestAnimationFrame(doFit);
            setTimeout(doFit, 400);

            cy.on('tap', 'node[isEditBadge]', function(evt) {
                evt.stopPropagation();
                const pid = evt.target.data('personId');
                if (pid) window.location.href = `?action=bio&id=${pid}&mode=edit`;
            });
            cy.on('tap', 'node[!isAnchor][!isBadge][!isEditBadge]', function(evt) {
                const pid = evt.target.data('personId');
                if (pid) window.location.href = `?action=tree&focus_id=${pid}`;
            });

            const btnReset = document.getElementById('btn-reset-graph');
            if (btnReset) btnReset.addEventListener('click', () => { cy.resize(); cy.fit(undefined, 40); });

            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(() => { cy.resize(); cy.fit(undefined, 40); }, 300);
            });
        })();
        </script>
        
        <p style="text-align:center; color:#9ca3af; font-size:0.8rem; margin-top:10px;">
            <small>Tips: Klik foto untuk menjadikan orang tersebut pusat silsilah. Klik ikon <span style="color:#4f46e5;">✎</span> untuk mengedit.</small>
        </p>

    </div>
    <div id="modalAddRelation" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
        <div style="background:#fff; padding:20px; border-radius:12px; width:90%; max-width:350px; box-shadow:0 10px 25px rgba(0,0,0,0.2); animation: popUp 0.3s;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                <h3 style="margin:0; font-size:1.1rem;">Tambah Relasi</h2>
                <button onclick="document.getElementById('modalAddRelation').style.display='none'" style="border:none; background:none; font-size:1.2rem; cursor:pointer;">&times;</button>
            </div>
            
            <p style="font-size:0.9rem; color:#6b7280; margin-bottom:15px;">
                Menambahkan keluarga untuk: <br>
                <strong id="rel_target_name" style="color:#4f46e5; font-size:1rem;"></strong>
            </p>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <a href="#" id="btn_add_father" class="btn btn-secondary btn-sm" style="justify-content:center;">👨 Ayah</a>
                <a href="#" id="btn_add_mother" class="btn btn-secondary btn-sm" style="justify-content:center;">👩 Ibu</a>
                <a href="#" id="btn_add_spouse" class="btn btn-success btn-sm" style="justify-content:center;">💑 Pasangan</a>
                <a href="#" id="btn_add_child"  class="btn btn-primary btn-sm" style="justify-content:center;">👶 Anak</a>
                <a href="#" id="btn_add_sibling" class="btn btn-secondary btn-sm" style="justify-content:center; grid-column: span 2;">👫 Saudara</a>
            </div>
        </div>
    </div>

    
    
    


    
    
    
<?php elseif ($action === 'level_line'): ?>
<?php
$activeTreeId = $_SESSION['current_tree_id'] ?? 0;
if ($isAdmin && $isViewingOthers && isset($_SESSION['admin_viewing_tree_id'])) {
    $activeTreeId = intval($_SESSION['admin_viewing_tree_id']);
}

if ($activeTreeId == 0): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <p style="color:#64748b;margin-bottom:16px;">Belum ada pohon keluarga yang dipilih.</p>
        <a href="?action=home" class="btn btn-primary">Pilih Pohon Keluarga</a>
    </div>
<?php else:
    /* ── konstanta layout ── */
    $LL = ['nw'=>200,'nph'=>52,'nsh'=>36,'hg'=>80,'vg'=>16,'px'=>40,'py'=>40,'rx'=>8];
    $LL_FILLS   = ['#dbeafe','#dcfce7','#fef3c7','#fce7f3','#ede9fe','#ffedd5','#ecfeff','#f0fdf4','#fef9c3','#fae8ff'];
    $LL_STROKES = ['#93c5fd','#86efac','#fde68a','#f9a8d4','#c4b5fd','#fdba74','#67e8f9','#6ee7b7','#fef08a','#e879f9'];
    $LL_TEXTS   = ['#1e40af','#166534','#92400e','#9d174d','#5b21b6','#9a3412','#164e63','#14532d','#854d0e','#86198f'];

    /* ── helpers layout (PHP) ── */
    function ll_nh(&$n, $LL)  { return $LL['nph'] + count($n['spouses']) * $LL['nsh']; }

    function ll_sh(&$n, $LL) {
        if (isset($n['_sh'])) return $n['_sh'];
        $myH = ll_nh($n, $LL) + $LL['vg'];
        if (empty($n['children'])) { $n['_sh'] = $myH; return $myH; }
        $ch = 0;
        foreach ($n['children'] as &$c) $ch += ll_sh($c, $LL);
        unset($c);
        $n['_sh'] = max($myH, $ch);
        return $n['_sh'];
    }

    function ll_layout(&$n, $x, $sy, $LL) {
        $sh = ll_sh($n, $LL);  $nh = ll_nh($n, $LL);
        $n['_x'] = $x;  $n['_y'] = $sy + $sh/2 - $nh/2;
        $n['_my'] = $n['_y'] + $nh/2;  $n['_nh'] = $nh;
        $cx = $x + $LL['nw'] + $LL['hg'];
        if (count($n['children']) === 1) {
            // Anak tunggal: tempatkan subtree anak tepat di tengah parent
            // sehingga _my anak == _my parent → garis lurus horizontal
            $csh = ll_sh($n['children'][0], $LL);
            $csy = $sy + $sh/2 - $csh/2;
            ll_layout($n['children'][0], $cx, $csy, $LL);
        } else {
            $cy = $sy;
            foreach ($n['children'] as &$c) {
                ll_layout($c, $cx, $cy, $LL);
                $cy += ll_sh($c, $LL);
            }
            unset($c);
        }
    }

    /* ── build data tree ── */
    list($personsByGen, $maxGen, $maxHeight, $generationData, $allPersonsData, $parentChildren, $spouses, $childParents)
        = fh_compute_generations($mysqli, $activeTreeId);

    // Cari root: punya anak tapi tidak punya orang tua
    $rootIds = [];
    foreach ($allPersonsData as $pid => $_p) {
        $isParent = !empty($parentChildren[$pid]);
        $isChild  = !empty($childParents[$pid]);
        if ($isParent && !$isChild) $rootIds[] = $pid;
    }
    if (empty($rootIds)) {
        foreach ($allPersonsData as $pid => $_p) {
            if (empty($childParents[$pid])) $rootIds[] = $pid;
        }
    }
    sort($rootIds);

    function fh_build_ll_node($pid, $persons, $parentChildren, $spouses, &$visited, $gen) {
        if (isset($visited[$pid])) return null;
        if (!isset($persons[$pid])) return null;
        $visited[$pid] = true;
        $p = $persons[$pid];
        $node = [
            'id'       => (int)$pid,
            'name'     => (string)($p['name'] ?? ''),
            'gender'   => (string)($p['gender'] ?? 'unknown'),
            'is_alive' => (int)($p['is_alive'] ?? 1),
            'gen'      => (int)$gen,
            'spouses'  => [],
            'children' => [],
        ];
        if (!empty($spouses[$pid])) {
            $sl = $spouses[$pid];
            uksort($sl, function($a, $b) use ($sl) {
                return ($sl[$a]['order'] ?? 0) - ($sl[$b]['order'] ?? 0);
            });
            foreach ($sl as $sid => $_s) {
                if (!isset($visited[$sid]) && isset($persons[$sid])) {
                    $visited[$sid] = true;
                    $sp = $persons[$sid];
                    $node['spouses'][] = [
                        'id'       => (int)$sid,
                        'name'     => (string)($sp['name'] ?? ''),
                        'gender'   => (string)($sp['gender'] ?? 'unknown'),
                        'is_alive' => (int)($sp['is_alive'] ?? 1),
                    ];
                }
            }
        }
        if (!empty($parentChildren[$pid])) {
            $cids = array_keys($parentChildren[$pid]);
            usort($cids, function($a, $b) use ($persons) {
                $oa = isset($persons[$a]) ? ($persons[$a]['child_order'] ?? 9999) : 9999;
                $ob = isset($persons[$b]) ? ($persons[$b]['child_order'] ?? 9999) : 9999;
                return $oa !== $ob ? $oa - $ob : $a - $b;
            });
            foreach ($cids as $cid) {
                $cn = fh_build_ll_node($cid, $persons, $parentChildren, $spouses, $visited, $gen + 1);
                if ($cn !== null) $node['children'][] = $cn;
            }
        }
        return $node;
    }

    $ll_visited = [];
    $treeRoots  = [];
    foreach ($rootIds as $rid) {
        $n = fh_build_ll_node($rid, $allPersonsData, $parentChildren, $spouses, $ll_visited, 1);
        if ($n !== null) $treeRoots[] = $n;
    }

    /* ── jalankan layout PHP ── */
    $ll_totalY = $LL['py'];
    foreach ($treeRoots as &$llRoot) {
        ll_layout($llRoot, $LL['px'], $ll_totalY, $LL);
        $ll_totalY += ll_sh($llRoot, $LL);
    }
    unset($llRoot);
    $ll_totalY += $LL['py'];

    // Hitung lebar max (BFS)
    $ll_maxX = 0;
    $ll_stack = $treeRoots;
    while (!empty($ll_stack)) {
        $llnd = array_pop($ll_stack);
        $rx2 = ($llnd['_x'] ?? 0) + $LL['nw'] + $LL['px'];
        if ($rx2 > $ll_maxX) $ll_maxX = $rx2;
        foreach ($llnd['children'] as $llc) $ll_stack[] = $llc;
    }

    // Hitung max gen untuk legenda
    $ll_maxGen = 0;
    $ll_stack2 = $treeRoots;
    while (!empty($ll_stack2)) {
        $llnd2 = array_pop($ll_stack2);
        if ($llnd2['gen'] > $ll_maxGen) $ll_maxGen = $llnd2['gen'];
        foreach ($llnd2['children'] as $llc2) $ll_stack2[] = $llc2;
    }

    /* ── fungsi gambar SVG (PHP) ── */
    function ll_e($s) { return htmlspecialchars((string)$s, ENT_XML1, 'UTF-8'); }
    function ll_trunc($s, $n = 24) { $s = (string)$s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }

    function ll_svg_lines(&$n, $LL) {
        if (empty($n['children'])) return '';
        $NW = $LL['nw']; $HG = $LL['hg'];
        $parentY = $n['_my'];
        $LC = '#94a3b8'; $LW = 2;
        $out = '';

        if (count($n['children']) === 1) {
            // Anak tunggal: garis lurus horizontal (layout sudah memastikan _my sejajar)
            $c = $n['children'][0];
            $out .= '<line x1="'.($n['_x']+$NW).'" y1="'.$parentY.'" x2="'.$c['_x'].'" y2="'.$parentY.'" stroke="'.$LC.'" stroke-width="'.$LW.'" stroke-linecap="round"/>';
            $out .= ll_svg_lines($c, $LL);
        } else {
            // Banyak anak: pakai tiang vertikal di tengah gap
            $stemX = $n['_x'] + $NW + $HG / 2;
            // garis horizontal dari parent ke tiang
            $out .= '<line x1="'.($n['_x']+$NW).'" y1="'.$parentY.'" x2="'.$stemX.'" y2="'.$parentY.'" stroke="'.$LC.'" stroke-width="'.$LW.'" stroke-linecap="round"/>';
            // tiang vertikal antara anak pertama dan terakhir
            $firstY = $n['children'][0]['_my'];
            $lastY  = $n['children'][count($n['children'])-1]['_my'];
            $out .= '<line x1="'.$stemX.'" y1="'.$firstY.'" x2="'.$stemX.'" y2="'.$lastY.'" stroke="'.$LC.'" stroke-width="'.$LW.'" stroke-linecap="round"/>';
            // cabang horizontal ke setiap anak
            foreach ($n['children'] as &$c) {
                $out .= '<line x1="'.$stemX.'" y1="'.$c['_my'].'" x2="'.$c['_x'].'" y2="'.$c['_my'].'" stroke="'.$LC.'" stroke-width="'.$LW.'" stroke-linecap="round"/>';
                $out .= ll_svg_lines($c, $LL);
            }
            unset($c);
        }
        return $out;
    }

    function ll_svg_nodes(&$n, $LL, $FILLS, $STROKES, $TEXTS) {
        $NW = $LL['nw']; $NPH = $LL['nph']; $NSH = $LL['nsh']; $RX = $LL['rx'];
        $gi      = ($n['gen'] - 1) % count($FILLS);
        $fillC   = $FILLS[$gi];
        $strokeC = $STROKES[$gi];
        $textC   = $TEXTS[$gi];
        $x = $n['_x']; $y = $n['_y']; $nh = $n['_nh'];
        $pid = (int)$n['id'];
        $out = '';

        // bayangan
        $out .= '<rect x="'.($x+2).'" y="'.($y+3).'" width="'.$NW.'" height="'.$nh.'" rx="'.$RX.'" fill="rgba(0,0,0,0.07)"/>';

        // kotak utama (klik → lihat profil)
        $out .= '<a href="?action=view_person&amp;id='.$pid.'">';
        $out .= '<rect x="'.$x.'" y="'.$y.'" width="'.$NW.'" height="'.$nh.'" rx="'.$RX.'" fill="'.ll_e($fillC).'" stroke="'.ll_e($strokeC).'" stroke-width="1.5" style="cursor:pointer"/>';

        // tanda almarhum
        if (!$n['is_alive']) {
            $out .= '<line x1="'.($x+$NW-18).'" y1="'.($y+4).'" x2="'.($x+$NW-4).'" y2="'.($y+18).'" stroke="#94a3b8" stroke-width="1.5"/>';
        }

        // nama utama (geser kanan sedikit agar tidak tertutup ikon edit)
        $nameLabel = ll_e(ll_trunc($n['name'], 20));
        $out .= '<text x="'.($x+$NW/2+8).'" y="'.($y+$NPH/2).'" text-anchor="middle" dominant-baseline="middle" font-size="12" font-weight="700" fill="'.ll_e($textC).'" font-family="system-ui,sans-serif">'.$nameLabel.'</text>';

        // dot gender
        $dotC = $n['gender']==='male' ? '#3b82f6' : ($n['gender']==='female' ? '#ec4899' : '#94a3b8');
        $out .= '<circle cx="'.($x+$NW-10).'" cy="'.($y+$NPH/2).'" r="4" fill="'.$dotC.'"/>';

        $out .= '</a>';

        // ── ikon edit orang utama (digambar SETELAH <a> utama agar berada di atas) ──
        // Diletakkan di pojok kiri atas kotak NPH
        $out .= '<a href="?action=bio&amp;id='.$pid.'&amp;mode=edit" title="Edit profil">';
        $out .= '<rect x="'.($x+4).'" y="'.($y+4).'" width="18" height="18" rx="4" fill="#6366f1" opacity="0.9" style="cursor:pointer"/>';
        // ikon pensil sederhana (path SVG mini)
        $out .= '<path d="M'.($x+8).' '.($y+17).' l1 -3 7 -7 2 2 -7 7z M'.($x+15).' '.($y+8).' l1 -1 2 2 -1 1z" fill="white" stroke="none"/>';
        $out .= '</a>';

        // pasangan
        foreach ($n['spouses'] as $i => $sp) {
            $spY  = $y + $NPH + $i * $NSH;
            $spid = (int)$sp['id'];
            $out .= '<line x1="'.($x+10).'" y1="'.$spY.'" x2="'.($x+$NW-10).'" y2="'.$spY.'" stroke="'.ll_e($strokeC).'" stroke-width="1"/>';

            // baris pasangan (klik → lihat profil pasangan)
            $out .= '<a href="?action=view_person&amp;id='.$spid.'">';
            $spLabel = ll_e('♥ '.ll_trunc($sp['name'], 18));
            $out .= '<text x="'.($x+$NW/2+8).'" y="'.($spY+$NSH/2).'" text-anchor="middle" dominant-baseline="middle" font-size="11" font-weight="500" fill="#6366f1" font-family="system-ui,sans-serif">'.$spLabel.'</text>';
            $sdotC = $sp['gender']==='male' ? '#3b82f6' : ($sp['gender']==='female' ? '#ec4899' : '#94a3b8');
            $out .= '<circle cx="'.($x+$NW-10).'" cy="'.($spY+$NSH/2).'" r="3.5" fill="'.$sdotC.'"/>';
            $out .= '<rect x="'.($x+26).'" y="'.$spY.'" width="'.($NW-26).'" height="'.$NSH.'" fill="transparent" style="cursor:pointer"/>';
            $out .= '</a>';

            // ikon edit pasangan (digambar terakhir agar di atas)
            $spEY = $spY + ($NSH/2) - 8;
            $out .= '<a href="?action=bio&amp;id='.$spid.'&amp;mode=edit" title="Edit profil pasangan">';
            $out .= '<rect x="'.($x+4).'" y="'.$spEY.'" width="16" height="16" rx="3" fill="#6366f1" opacity="0.75" style="cursor:pointer"/>';
            $out .= '<path d="M'.($x+8).' '.($spEY+13).' l1 -3 6 -6 2 2 -6 6z M'.($x+14).' '.($spEY+5).' l1 -1 2 2 -1 1z" fill="white" stroke="none"/>';
            $out .= '</a>';
        }

        // rekursif anak
        foreach ($n['children'] as &$c) {
            $out .= ll_svg_nodes($c, $LL, $FILLS, $STROKES, $TEXTS);
        }
        unset($c);
        return $out;
    }

    /* ── build SVG string ── */
    ob_start();
    if (empty($treeRoots)): ?>
        <div style="padding:60px 40px;color:#94a3b8;font-size:1rem;text-align:center;">Belum ada data pohon keluarga.</div>
    <?php else:
        $svgW = max($ll_maxX, 400);
        $svgH = max($ll_totalY, 200);
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="'.$svgW.'" height="'.$svgH.'" style="display:block;">';
        // garis konektor dulu
        foreach ($treeRoots as &$llR) echo ll_svg_lines($llR, $LL);
        unset($llR);
        // lalu kotak node
        foreach ($treeRoots as &$llR2) echo ll_svg_nodes($llR2, $LL, $LL_FILLS, $LL_STROKES, $LL_TEXTS);
        unset($llR2);
        echo '</svg>';
    endif;
    $ll_svgOutput = ob_get_clean();
?>

<div style="position:relative;background:#f1f5f9;border-radius:12px;border:1px solid #e2e8f0;height:88vh;overflow:hidden;user-select:none;">

    <!-- Toolbar -->
    <div style="position:absolute;top:12px;left:12px;z-index:20;display:flex;gap:6px;align-items:center;background:rgba(255,255,255,0.97);padding:8px 14px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.12);">
        <span style="font-weight:700;color:#1e293b;font-size:0.9rem;">📊 Level Line</span>
        <span style="color:#cbd5e1;margin:0 2px;">|</span>
        <button onclick="llZoom(1.2)" style="border:none;background:#f1f5f9;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:1rem;font-weight:700;color:#334155;">+</button>
        <button onclick="llZoom(0.8)" style="border:none;background:#f1f5f9;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:1rem;font-weight:700;color:#334155;">−</button>
        <button onclick="llReset()" style="border:none;background:#f1f5f9;border-radius:6px;padding:4px 9px;cursor:pointer;font-size:0.82rem;color:#334155;">⊡ Reset</button>
    </div>

    <!-- Legenda generasi -->
    <div style="position:absolute;top:12px;right:12px;z-index:20;background:rgba(255,255,255,0.97);padding:8px 12px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.12);font-size:0.78rem;color:#475569;">
        <div style="font-weight:600;margin-bottom:5px;color:#334155;">Generasi</div>
        <?php
        $llLegFills   = $LL_FILLS;
        $llLegStrokes = $LL_STROKES;
        for ($llg = 1; $llg <= min($ll_maxGen, 10); $llg++) {
            $lgi = ($llg - 1) % count($llLegFills);
            echo '<div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;">'
               . '<div style="width:13px;height:13px;border-radius:3px;background:'.$llLegFills[$lgi].';border:1.5px solid '.$llLegStrokes[$lgi].';flex-shrink:0;"></div>'
               . '<span>Gen '.$llg.'</span></div>';
        }
        ?>
    </div>

    <!-- Pan/Zoom container -->
    <div id="ll-outer"
         data-svg-w="<?= (int)($svgW ?? 400) ?>"
         data-svg-h="<?= (int)($svgH ?? 200) ?>"
         style="width:100%;height:100%;overflow:hidden;cursor:grab;touch-action:none;user-select:none;-webkit-user-select:none;">
        <div id="ll-wrapper" style="display:inline-block;transform-origin:0 0;will-change:transform;padding:0;">
            <?= $ll_svgOutput ?>
        </div>
    </div>
</div>

<script>
(function() {
    var outer   = document.getElementById('ll-outer');
    var wrapper = document.getElementById('ll-wrapper');
    if (!outer || !wrapper) return;

    var _scale = 1, _tx = 0, _ty = 0;
    var _drag = false, _sx = 0, _sy = 0;
    var _pinch = false, _pDist = 0, _pMx = 0, _pMy = 0;
    var _vx = 0, _vy = 0, _lx = 0, _ly = 0, _lt = 0, _raf = null;

    /* ── terapkan transform ── */
    function applyT() {
        wrapper.style.transform = 'translate('+_tx+'px,'+_ty+'px) scale('+_scale+')';
    }

    /* ── zoom di sekitar titik (cx,cy) dalam koordinat outer ── */
    function zoomAt(f, cx, cy) {
        var ns = Math.min(4, Math.max(0.1, _scale * f));
        _tx = cx - (cx - _tx) * (ns / _scale);
        _ty = cy - (cy - _ty) * (ns / _scale);
        _scale = ns;
        applyT();
    }

    /* ── fit seluruh diagram di layar (dengan padding) ── */
    function fitScreen() {
        var svgW = parseInt(outer.getAttribute('data-svg-w'), 10) || 800;
        var svgH = parseInt(outer.getAttribute('data-svg-h'), 10) || 400;
        var ow = outer.clientWidth  || outer.offsetWidth  || 360;
        var oh = outer.clientHeight || outer.offsetHeight || 500;
        var PAD = 32; // padding dalam px
        var s = Math.min((ow - PAD) / svgW, (oh - PAD) / svgH);
        s = Math.min(s, 1);          // jangan pernah zoom-in saat init
        s = Math.max(s, 0.05);       // batas bawah
        _scale = s;
        _tx = (ow - svgW * s) / 2;  // tengahkan horizontal
        _ty = (oh - svgH * s) / 2;  // tengahkan vertikal
        if (_ty < 0) _ty = PAD / 2; // pastikan tidak terpotong atas
        applyT();
    }

    /* ── tombol toolbar ── */
    window.llZoom = function(f) {
        var r = outer.getBoundingClientRect();
        zoomAt(f, r.width / 2, r.height / 2);
    };
    window.llReset = function() { fitScreen(); };

    /* ── inertia setelah drag dilepas ── */
    function startInertia() {
        cancelAnimationFrame(_raf);
        (function step() {
            _vx *= 0.9; _vy *= 0.9;
            if (Math.abs(_vx) < 0.3 && Math.abs(_vy) < 0.3) return;
            _tx += _vx; _ty += _vy;
            applyT();
            _raf = requestAnimationFrame(step);
        })();
    }

    /* ── blokir pointer events pada wrapper saat drag
           agar link SVG tidak mencuri event ── */
    function blockLinks()  { wrapper.style.pointerEvents = 'none'; }
    function restoreLinks(){ wrapper.style.pointerEvents = ''; }

    /* ════════════ MOUSE ════════════ */
    outer.addEventListener('mousedown', function(e) {
        if (e.button !== 0) return;
        cancelAnimationFrame(_raf);
        _drag = true;
        _sx = e.clientX - _tx; _sy = e.clientY - _ty;
        _lx = e.clientX; _ly = e.clientY; _lt = Date.now();
        _vx = _vy = 0;
        outer.style.cursor = 'grabbing';
        blockLinks();
        e.preventDefault();
    });
    window.addEventListener('mouseup', function() {
        if (!_drag) return;
        _drag = false;
        outer.style.cursor = 'grab';
        restoreLinks();
        startInertia();
    });
    window.addEventListener('mousemove', function(e) {
        if (!_drag) return;
        var now = Date.now(), dt = Math.max(1, now - _lt);
        _vx = (e.clientX - _lx) * 16 / dt;
        _vy = (e.clientY - _ly) * 16 / dt;
        _lx = e.clientX; _ly = e.clientY; _lt = now;
        _tx = e.clientX - _sx; _ty = e.clientY - _sy;
        applyT();
    });

    /* zoom scroll di sekitar kursor */
    outer.addEventListener('wheel', function(e) {
        e.preventDefault();
        cancelAnimationFrame(_raf);
        var r = outer.getBoundingClientRect();
        zoomAt(e.deltaY < 0 ? 1.12 : 0.9, e.clientX - r.left, e.clientY - r.top);
    }, { passive: false });

    /* ════════════ TOUCH ════════════ */
    outer.addEventListener('touchstart', function(e) {
        e.preventDefault();
        cancelAnimationFrame(_raf);
        _vx = _vy = 0;
        blockLinks();

        if (e.touches.length === 1) {
            _drag = true; _pinch = false;
            _sx = e.touches[0].clientX - _tx;
            _sy = e.touches[0].clientY - _ty;
            _lx = e.touches[0].clientX; _ly = e.touches[0].clientY; _lt = Date.now();
        } else if (e.touches.length >= 2) {
            _drag = false; _pinch = true;
            _pDist = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            var r = outer.getBoundingClientRect();
            _pMx = (e.touches[0].clientX + e.touches[1].clientX) / 2 - r.left;
            _pMy = (e.touches[0].clientY + e.touches[1].clientY) / 2 - r.top;
        }
    }, { passive: false });

    outer.addEventListener('touchmove', function(e) {
        e.preventDefault();
        if (e.touches.length === 1 && _drag) {
            var now = Date.now(), dt = Math.max(1, now - _lt);
            _vx = (e.touches[0].clientX - _lx) * 16 / dt;
            _vy = (e.touches[0].clientY - _ly) * 16 / dt;
            _lx = e.touches[0].clientX; _ly = e.touches[0].clientY; _lt = now;
            _tx = e.touches[0].clientX - _sx;
            _ty = e.touches[0].clientY - _sy;
            applyT();
        } else if (e.touches.length >= 2 && _pinch) {
            var d = Math.hypot(
                e.touches[0].clientX - e.touches[1].clientX,
                e.touches[0].clientY - e.touches[1].clientY
            );
            var r = outer.getBoundingClientRect();
            var mx = (e.touches[0].clientX + e.touches[1].clientX) / 2 - r.left;
            var my = (e.touches[0].clientY + e.touches[1].clientY) / 2 - r.top;
            if (_pDist > 0) zoomAt(d / _pDist, mx, my);
            _pDist = d; _pMx = mx; _pMy = my;
        }
    }, { passive: false });

    outer.addEventListener('touchend', function(e) {
        if (e.touches.length === 0) {
            if (_drag) startInertia();
            _drag = false; _pinch = false;
            restoreLinks();
        } else if (e.touches.length === 1) {
            /* dari 2 jari ke 1 jari → lanjut drag */
            _pinch = false; _drag = true;
            _sx = e.touches[0].clientX - _tx;
            _sy = e.touches[0].clientY - _ty;
            _lx = e.touches[0].clientX; _ly = e.touches[0].clientY; _lt = Date.now();
            _vx = _vy = 0;
        }
    }, { passive: false });

    outer.addEventListener('touchcancel', function() {
        _drag = false; _pinch = false;
        restoreLinks();
        cancelAnimationFrame(_raf);
    }, { passive: true });

    /* ── inisialisasi: fit diagram ke layar ── */
    // requestAnimationFrame memastikan outer sudah punya ukuran final
    requestAnimationFrame(function() {
        requestAnimationFrame(fitScreen);
    });
})();
</script>

<?php endif; ?>

<?php elseif ($action === 'notifications'): ?>
    <div class="card" style="border:none; background:transparent; box-shadow:none; padding:0;">
        <h2 style="margin-bottom:15px;">🔔 Notifikasi Anda</h2>
        
        <?php
        // Ambil notifikasi: Milik User Ini ATAU Broadcast (0)
        $sqlNotif = "SELECT * FROM notifications 
                     WHERE user_id = $myUserId OR user_id = 0 
                     ORDER BY created_at DESC LIMIT 30";
        $resNotif = $mysqli->query($sqlNotif);
        ?>

        <?php if ($resNotif && $resNotif->num_rows > 0): ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php while ($row = $resNotif->fetch_assoc()): 
                    // Styling icon berdasarkan tipe
                    $icon = 'ℹ️'; $color = '#3b82f6'; $bg = '#eff6ff';
                    if($row['type'] == 'success') { $icon = '✅'; $color = '#10b981'; $bg = '#ecfdf5'; }
                    if($row['type'] == 'birthday') { $icon = '🎂'; $color = '#f43f5e'; $bg = '#fff1f2'; }
                    if($row['type'] == 'warning') { $icon = '⚠️'; $color = '#f59e0b'; $bg = '#fffbeb'; }
                ?>
                    <div style="background:#fff; padding:15px; border-radius:12px; border-left: 5px solid <?= $color ?>; box-shadow:0 2px 4px rgba(0,0,0,0.05); display:flex; gap:12px;">
                        <div style="font-size:1.5rem; background:<?= $bg ?>; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            <?= $icon ?>
                        </div>
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <strong style="color:#1f2937; font-size:0.95rem;"><?= htmlspecialchars($row['title']) ?></strong>
                            </div>
                            <p style="color:#4b5563; margin:0; font-size:0.9rem; line-height:1.4;">
                                <?= nl2br(htmlspecialchars($row['message'])) ?>
                            </p>
                            <small style="color:#9ca3af; font-size:0.75rem; margin-top:8px; display:block;">
                                <?= date('d M Y, H:i', strtotime($row['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
                <div class="card" style="text-align:center; padding:50px 20px;">
                <div style="font-size:3rem; margin-bottom:10px; opacity:0.3;">🔕</div>
                <h3 style="color:#374151; margin:0;">Belum ada notifikasi</h2>
                <p style="color:#6b7280; font-size:0.9rem;">Info keluarga dan pengumuman akan muncul di sini.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($resNotif && $resNotif->num_rows > 0): ?>
            <form method="post" style="text-align:center; margin-top:20px;">
                <input type="hidden" name="clear_notif" value="1">
                <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Hapus semua notifikasi?')">Bersihkan Riwayat</button>
            </form>
            <?php
            if(isset($_POST['clear_notif'])) {
                // Hapus hanya milik user (jangan hapus broadcast ID 0 karena milik semua orang)
                $mysqli->query("DELETE FROM notifications WHERE user_id = $myUserId");
                echo "<script>window.location.href='?action=notifications';</script>";
            }
            ?>
        <?php endif; ?>
    </div>


    <?php elseif ($action === 'settings'): ?>
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="background: linear-gradient(135deg, #4f46e5, #6366f1); color:#fff; padding:30px 20px; text-align:center;">
            <div style="width:80px; height:80px; background:#fff; color:#4f46e5; font-size:2rem; font-weight:bold; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 10px;">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <h2 style="margin:0; font-size:1.2rem; color:#fff;"><?= htmlspecialchars($_SESSION['user_name']) ?></h2>
            <p style="margin:5px 0 0; opacity:0.8; font-size:0.9rem; color:#e0e7ff;"><?= htmlspecialchars($_SESSION['user_email']) ?></p>
        </div>

        <div class="menu-list">
            <?php if ($isAdmin): ?>
            <a href="?action=admin_users" style="color:#4f46e5;">
                <span class="menu-icon">🛠️</span> Dashboard Admin
            </a>
            <?php endif; ?>

            <a href="?action=support">
                <span class="menu-icon">💬</span> Pusat Bantuan / Feedback
            </a>
            
            <div style="padding:15px; border-bottom:1px solid #f3f4f6;">
                <h3 style="font-size:1rem; margin-top:0; margin-bottom:10px;">Pengaturan Akses Admin Per Keluarga</h3>
                <p style="font-size:0.85rem; color:#6b7280; margin-bottom:15px;">
                    Izinkan Admin sistem untuk melihat data di pohon keluarga tertentu (untuk tujuan dukungan atau troubleshooting).
                </p>

                <?php
                // Ambil semua pohon milik user ini + status allow_admin_view (Asumsi kolom sudah ditambahkan)
                $trees = $mysqli->query("SELECT id, name, allow_admin_view FROM family_trees WHERE user_id = $myUserId ORDER BY name ASC");
                if ($trees->num_rows > 0):
                ?>
                    <div style="border:1px solid #e5e7eb; border-radius:10px; padding:15px; background:#fff;">
                        <?php while ($t = $trees->fetch_assoc()): ?>
                            <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid #eee;">
                                <span style="font-weight:600; color:#1f2937;"><?= htmlspecialchars($t['name']) ?></span>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="toggle_tree_admin_view" value="1">
                                    <input type="hidden" name="tree_id" value="<?= $t['id'] ?>">
                                    <?php if ($t['allow_admin_view'] ?? 0): ?>
                                        <input type="hidden" name="new_status" value="0">
                                        <button type="submit" class="btn btn-sm" 
                                                style="background:#dc2626; color:#fff; padding:6px 10px; font-size:0.75rem; border-radius:99px;"
                                                onclick="return confirm('Yakin kunci akses Admin untuk Keluarga <?= htmlspecialchars($t['name']) ?>?');">
                                            🔓 Diizinkan
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="new_status" value="1">
                                        <button type="submit" class="btn btn-sm" 
                                                style="background:#10b981; color:#fff; padding:6px 10px; font-size:0.75rem; border-radius:99px;"
                                                onclick="return confirm('Yakin izinkan akses Admin untuk Keluarga <?= htmlspecialchars($t['name']) ?>?');">
                                            🔐 Terkunci
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        <?php endwhile; ?>
                        <p style="font-size:0.75rem; color:#9ca3af; margin:10px 0 0; text-align:center;">
                            Catatan: Hanya pohon keluarga yang Anda buat sendiri yang tampil di sini.
                        </p>
                    </div>
                <?php else: ?>
                    <p style="color:#9ca3af; font-style:italic;">Anda belum memiliki keluarga besar yang dibuat. Tidak ada yang bisa dibagikan ke admin.</p>
                <?php endif; ?>
            </div>

            <a href="?action=logout" onclick="return confirm('Keluar dari aplikasi?')" style="color:#ef4444;">
                <span class="menu-icon">🚪</span> Keluar Aplikasi
            </a>
            <a href="?action=about">
                <span class="menu-icon">ℹ️</span> Tentang Aplikasi
            </a>
            
            <a href="?action=privacy">
                <span class="menu-icon">≡ا¤</span> Kebijakan Privasi
            </a>
        </div>
    </div>

<?php elseif ($action === 'support'): ?>
    <div class="card">
        <h2>💬 Pusat Bantuan & Feedback</h2>
        
        <?php
        // Proses Kirim Tiket
        if (isset($_POST['send_ticket'])) {
            $subj = trim($_POST['subject']);
            $msg  = trim($_POST['message']);
            if ($subj && $msg) {
                $stmt = $mysqli->prepare("INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $myUserId, $subj, $msg);
                $stmt->execute();
                echo "<div class='alert alert-success'>Pesan terkirim! Admin akan segera membalas.</div>";
            }
        }
        ?>

        <form method="post" style="margin-bottom:30px;">
            <input type="hidden" name="send_ticket" value="1">
            <label>Judul Masalah / Permintaan Fitur</label>
            <input type="text" name="subject" required placeholder="Misal: Data error atau Request fitur PDF">
            <label>Pesan Detail</label>
            <textarea name="message" required placeholder="Jelaskan masalah atau ide Anda..."></textarea>
            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Kirim Pesan</button>
        </form>

        <h3 class="section-title">Riwayat Pesan Anda</h3>
        <?php
        $tickets = $mysqli->query("SELECT * FROM support_tickets WHERE user_id=$myUserId ORDER BY created_at DESC");
        if ($tickets->num_rows > 0):
            while ($t = $tickets->fetch_assoc()):
        ?>
            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:15px; margin-bottom:10px; background:#fff;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <strong><?= htmlspecialchars($t['subject']) ?></strong>
                    <span style="font-size:0.8rem; color:#9ca3af;"><?= date('d M Y H:i', strtotime($t['created_at'])) ?></span>
                </div>
                <p style="margin-bottom:10px; font-size:0.9rem;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                
                <?php if ($t['admin_reply']): ?>
                    <div style="background:#ecfdf5; padding:10px; border-radius:6px; border-left:4px solid #10b981; font-size:0.9rem;">
                        <strong>ظ£ëي╕ Balasan Admin:</strong><br>
                        <?= nl2br(htmlspecialchars($t['admin_reply'])) ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:0.8rem; color:#d97706; background:#fffbeb; display:inline-block; padding:2px 8px; border-radius:99px;">ظ│ Menunggu balasan</div>
                <?php endif; ?>
            </div>
        <?php endwhile; else: ?>
            <p style="color:#6b7280; text-align:center;">Belum ada riwayat pesan.</p>
        <?php endif; ?>
    </div>

-
<?php
// --- HALAMAN TENTANG KAMI (ABOUT) ---
elseif ($action === 'about'): ?>
    <div class="card">
        <div style="text-align:center; margin-bottom:20px;">
            <div style="width:60px; height:60px; background:#4f46e5; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 10px; color:#fff; font-size:1.5rem;">
                👪
            </div>
            <h2 style="margin:0;">Tentang FamilyHood</h2>
            <p style="color:#6b7280; font-size:0.9rem;">Menyambung Tali Silaturahmi Digital</p>
        </div>
        
        <p style="line-height:1.6; color:#374151;">
            <strong>FamilyHood</strong> adalah aplikasi silsilah keluarga digital yang dirancang untuk membantu Anda mencatat, menyimpan, dan melestarikan sejarah keluarga besar Anda. Kami percaya bahwa setiap keluarga memiliki cerita berharga yang harus dijaga agar bisa dikenang oleh generasi mendatang.
        </p>
        
        <h3 class="section-title" style="margin-top:20px;">Misi Kami</h3>
            <ul style="padding-left:20px; line-height:1.6; color:#374151;">
            <li>👪 <strong>Menghubungkan Generasi:</strong> Memudahkan anak cucu mengenal leluhur mereka.</li>
            <li>🔒 <strong>Menjaga Privasi:</strong> Data keluarga Anda aman dan hanya bisa diakses oleh Anda (dan admin sistem jika diizinkan).</li>
            <li>🖼️ <strong>Visualisasi Mudah:</strong> Melihat hubungan keluarga dalam bentuk pohon visual yang interaktif.</li>
        </ul>

        <h3 class="section-title" style="margin-top:20px;">Kontak Kami</h3>
        <div style="background:#f0f9ff; padding:15px; border-radius:10px; border:1px solid #bae6fd; margin-bottom:20px;">
            <p style="margin:0 0 8px; font-weight:600; color:#0c4a6e;">Informasi Pengembang/Admin:</p>
            <ul style="list-style:none; padding:0; margin:0; font-size:0.9rem;">
                <li style="margin-bottom:5px;">≡اôئ HP: <a href="tel:+6281234567890" style="color:#1d4ed8; text-decoration:none;">+62 85743399595</a></li>
                <li style="margin-bottom:5px;">ظ£ëي╕ Email: <a href="mailto:admin@familyhood.com" style="color:#1d4ed8; text-decoration:none;">admin@familyhood.com</a></li>
                <li>≡اô╕ Instagram: <a href="https://instagram.com/zainul.hakim" target="_blank" style="color:#1d4ed8; text-decoration:none;">@zainul.hakim</a></li>
            </ul>
        </div>
        
        <h3 class="section-title" style="margin-top:20px;">Fitur Unggulan</h3>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px;">
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>≡اî│ Pohon Dinamis</strong><br>Otomatis menyusun bagan keturunan.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>≡اôج Export Data</strong><br>Unduh ke PDF, Word, atau Excel dengan mudah.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>🎂 Pengingat Ultah</strong><br>Notifikasi ulang tahun anggota keluarga.
            </div>
            <div style="background:#f9fafb; padding:10px; border-radius:8px; font-size:0.85rem;">
                <strong>≡اû╝ Galeri Foto</strong><br>Simpan foto kenangan setiap anggota.
            </div>
        </div>

        <div style="margin-top:30px; text-align:center; border-top:1px solid #eee; padding-top:20px;">
            <p style="font-size:0.8rem; color:#9ca3af;">
                Versi Aplikasi: 1.0.0<br>
                Dibuat dengan ❤️ oleh Tim Pengembang.
            </p>
            <a href="?action=settings" class="btn btn-secondary btn-sm">← Kembali ke Pengaturan</a>
        </div>
    </div>
<?
// --- HALAMAN KEBIJAKAN PRIVASI (PRIVACY POLICY) ---
elseif ($action === 'privacy'): ?>
    <div class="card">
        <h2 style="margin-bottom:10px;">≡ا¤ Kebijakan Privasi</h2>
        <p style="font-size:0.85rem; color:#6b7280; margin-bottom:20px;">Terakhir diperbarui: <?= date('d M Y') ?></p>
        
        <div style="font-size:0.9rem; line-height:1.6; color:#374151;">
            <p>Di <strong>FamilyHood</strong>, kami sangat menghargai privasi Anda. Dokumen ini menjelaskan bagaimana kami mengumpulkan, menggunakan, dan melindungi data pribadi keluarga Anda.</p>
            
            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">1. Data yang Kami Kumpulkan</h3>
            <p>Untuk menjalankan fungsi silsilah keluarga, kami menyimpan data berikut yang Anda inputkan secara sukarela:</p>
            <ul style="padding-left:20px;">
                <li><strong>Data Akun:</strong> Nama, Email, dan Password (dienkripsi).</li>
                <li><strong>Data Anggota Keluarga:</strong> Nama, Jenis Kelamin, Tanggal Lahir, Tempat Lahir, Status Hidup, dan Catatan Tambahan.</li>
                <li><strong>Media:</strong> Foto profil anggota keluarga yang Anda unggah.</li>
            </ul>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">2. Penggunaan Data</h3>
            <p>Data Anda digunakan semata-mata untuk:</p>
            <ul style="padding-left:20px;">
                <li>Menampilkan visualisasi pohon keluarga.</li>
                <li>Mengirimkan notifikasi (seperti pengingat ulang tahun).</li>
                <li>Membuat laporan (Export PDF/Word) atas permintaan Anda.</li>
            </ul>
            <p><strong>Kami TIDAK akan pernah menjual data keluarga Anda kepada pihak ketiga.</strong></p>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">3. Keamanan & Akses</h3>
            <p>
                Akun Anda dilindungi kata sandi. Secara default, data pohon keluarga Anda bersifat <strong>Pribadi</strong>. 
                Hanya Admin sistem yang memiliki akses teknis untuk pemeliharaan, atau jika Anda secara eksplisit mengaktifkan fitur "Izinkan Admin Lihat Data" di menu Pengaturan.
            </p>

            <h3 style="font-size:1rem; margin-top:15px; color:#1f2937;">4. Hak Anda</h3>
            <p>Anda memiliki kendali penuh untuk:</p>
            <ul style="padding-left:20px;">
                <li>Mengakses dan mengubah data kapan saja.</li>
                <li>Menghapus anggota keluarga atau menghapus akun Anda secara permanen.</li>
                <li>Mengunduh salinan data Anda.</li>
            </ul>

            <div style="margin-top:20px; background:#eff6ff; padding:10px; border-radius:8px; border-left:4px solid #3b82f6;">
                <strong>Hubungi Kami</strong><br>
                Jika ada pertanyaan mengenai privasi, Anda dapat menghubungi kami:<br>
                <ul style="list-style:disc; padding-left:20px; margin:5px 0 0; font-size:0.9rem;">
                    <li>≡اôئ HP: <a href="tel:+6281234567890" style="color:#1d4ed8; text-decoration:none;">+62 85743399595</a></li>
                    <li>ظ£ëي╕ Email: <a href="mailto:admin@familyhood.com" style="color:#1d4ed8; text-decoration:none;">admin@familyhood.com</a></li>
                    <li>≡اô╕ Instagram: <a href="https://instagram.com/zainul.hakim" target="_blank" style="color:#1d4ed8; text-decoration:none;">@zainul.hakim</a></li>
                </ul>
            </div>
        </div>
        
        <div style="margin-top:20px; text-align:center;">
            <a href="?action=settings" class="btn btn-secondary btn-sm">← Kembali ke Pengaturan</a>
        </div>
    </div>

<?php elseif ($action === 'admin_users' && $isAdmin): ?>
    <div class="card">
        <h2>🛠️ Dashboard Admin</h2>
        
        <div style="margin-bottom:20px;">
            <a href="?action=import_excel" class="btn btn-primary">📊 Import Data dari Excel</a>
        </div>
        
        <h3 class="section-title">Daftar Pengguna</h3>
        <div style="overflow-x:auto;">
            <table style="min-width: 700px;">
                <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Waktu Bergabung</th><th>Akses Data</th></tr></thead>
                <tbody>
                <?php
                // Ambil data dari tabel users. Kolom allow_admin_view di users sekarang diabaikan.
                $users = $mysqli->query("SELECT id, name, email, is_active, created_at FROM users WHERE role != 'admin' ORDER BY created_at DESC");
                while ($u = $users->fetch_assoc()):
                ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <?php
                            $joinedAt = $u['created_at'] ?? null;
                            echo !empty($joinedAt) ? date('d M Y H:i', strtotime($joinedAt)) : '-';
                            ?>
                        </td>

                        <td>
                            <?php 
                            // Hitung jumlah pohon yang diizinkan admin untuk user ini
                            $countStmt = $mysqli->prepare("SELECT COUNT(*) FROM family_trees WHERE user_id = ? AND allow_admin_view = 1");
                            $countStmt->bind_param("i", $u['id']);
                            $countStmt->execute();
                            $countStmt->bind_result($viewableTreesCount);
                            $countStmt->fetch();
                            $countStmt->close();
                            ?>
                            
                            <?php if ($viewableTreesCount > 0): ?>
                                <button type="button" 
                                        onclick="showAdminViewModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')"
                                        class="btn btn-sm btn-primary"
                                        style="background:#4f46e5; color:#fff; padding:6px 10px; font-size:0.75rem; border-radius:99px;"
                                        onclick="return confirm('Yakin kunci akses Admin untuk Keluarga <?= htmlspecialchars($u['name']) ?>?');">
                                    ✅ <?= $viewableTreesCount ?> Keluarga Diizinkan
                                </button>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:0.85rem;">🔒 Private (0 Keluarga)</span>
                            <?php endif; ?>
                        </td>

                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top:40px; padding-top:20px; border-top:2px dashed #e5e7eb;">
            <h3 class="section-title">≡اôث Kirim Pengumuman / Notifikasi</h3>
            
            <?php
            if (isset($_POST['send_broadcast'])) {
                $targetUser = intval($_POST['broadcast_target']);
                $bTitle = trim($_POST['broadcast_title']);
                $bMsg = trim($_POST['broadcast_message']);
                
                if ($bTitle && $bMsg) {
                    // Fungsi helper kita tadi
                    fh_send_notification($mysqli, $targetUser, $bTitle, $bMsg, 'info');
                    echo "<div class='alert alert-success'>Notifikasi berhasil dikirim!</div>";
                }
            }
            ?>
            
            <form method="post" style="background:#f9fafb; padding:15px; border-radius:10px;">
                <input type="hidden" name="send_broadcast" value="1">
                
                <label>Tujuan:</label>
                <select name="broadcast_target" required>
                    <option value="0">≡اôث SEMUA USER (Broadcast)</option>
                    <?php 
                    // Ambil list user lagi untuk dropdown
                    $usersList = $mysqli->query("SELECT id, name FROM users WHERE role != 'admin'");
                    while($u = $usersList->fetch_assoc()) {
                        echo "<option value='".$u['id']."'>ظت ".$u['name']."</option>";
                    }
                    ?>
                </select>
                
                <label>Judul:</label>
                <input type="text" name="broadcast_title" placeholder="Contoh: Update Sistem" required>
                
                <label>Pesan:</label>
                <textarea name="broadcast_message" placeholder="Tulis pesan..." required></textarea>
                
                <button type="submit" class="btn btn-primary" style="margin-top:10px;">Kirim Notifikasi</button>
            </form>
        </div>

        <h3 class="section-title" style="margin-top:30px;">Tiket Bantuan Masuk</h3>
        
        <?php
        // Proses Balas Tiket
        if (isset($_POST['reply_ticket'])) {
            $tid = intval($_POST['ticket_id']);
            $reply = trim($_POST['reply']);
            if ($reply) {
                $stmt = $mysqli->prepare("UPDATE support_tickets SET admin_reply=?, status='answered' WHERE id=?");
                $stmt->bind_param("si", $reply, $tid);
                $stmt->execute();
                echo "<div class='alert alert-success'>Balasan terkirim.</div>";
            }
        }
        
        $allTickets = $mysqli->query("SELECT t.*, u.name as user_name FROM support_tickets t JOIN users u ON t.user_id = u.id ORDER BY t.status ASC, t.created_at DESC");
        ?>
        
        <?php if ($allTickets->num_rows > 0): ?>
            <?php while ($at = $allTickets->fetch_assoc()): ?>
                <div style="border:1px solid <?= $at['status']=='open' ? '#fcd34d' : '#e5e7eb' ?>; border-radius:8px; padding:15px; margin-bottom:15px; background:<?= $at['status']=='open' ? '#fffbeb' : '#fff' ?>;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <strong><?= htmlspecialchars($at['user_name']) ?>: <?= htmlspecialchars($at['subject']) ?></strong>
                        <span style="font-size:0.8rem;"><?= date('d M H:i', strtotime($at['created_at'])) ?></span>
                    </div>
                    <p style="margin-bottom:10px; font-size:0.9rem;"><?= nl2br(htmlspecialchars($at['message'])) ?></p>
                    
                    <?php if (empty($at['admin_reply'])): ?>
                        <form method="post" style="display:flex; gap:5px;">
                            <input type="hidden" name="ticket_id" value="<?= $at['id'] ?>">
                            <input type="text" name="reply" placeholder="Tulis balasan..." required style="flex:1;">
                            <button type="submit" name="reply_ticket" class="btn btn-sm btn-success">Kirim Balasan</button>
                        </form>
                    <?php else: ?>
                        <div style="border-top:1px dashed #ccc; padding-top:5px; font-size:0.85rem; color:#059669;">
                            <strong>Balasan Anda:</strong> <?= htmlspecialchars($at['admin_reply']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="color:#6b7280;">Tidak ada tiket bantuan.</p>
        <?php endif; ?>
    </div>

<?php elseif ($action === 'import_excel' && $isAdmin): ?>
    <div class="card">
        <h2>📊 Import Data dari Excel</h2>
        
        <p style="font-size:0.9rem; color:#6b7280; margin-bottom:20px;">
            Unggah file Excel untuk mengimpor data keluarga. Pastikan format sesuai: kolom pertama generasi 1, kolom kedua generasi 2, dst. Data dengan tanda "+" menandai suami/istri.
        </p>
        
        <?php
        if (isset($_POST['upload_excel'])) {
            $targetUserId = intval($_POST['target_user_id']);
            $treeId = intval($_POST['tree_id']);
            if ($targetUserId == 0) {
                echo "<div class='alert alert-error'>Pilih user tujuan import.</div>";
            } elseif ($treeId == 0) {
                echo "<div class='alert alert-error'>Pilih pohon keluarga.</div>";
            } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] != 0) {
                echo "<div class='alert alert-error'>File Excel tidak valid.</div>";
            } else {
                $fileTmp = $_FILES['excel_file']['tmp_name'];
                $fileName = $_FILES['excel_file']['name'];
                
                // Load Excel
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($fileTmp);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                $imported = 0;
                $errors = [];

                // addRelation: cek per arah + tipe agar tidak duplikat
                function addRelation($mysqli, $person1Id, $person2Id, $type, $userId) {
                    $stmt = $mysqli->prepare("SELECT id FROM relations WHERE person_id = ? AND related_person_id = ? AND relation_type = ? AND user_id = ?");
                    $stmt->bind_param('iiis', $person1Id, $person2Id, $type, $userId);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows == 0) {
                        $stmt = $mysqli->prepare("INSERT INTO relations (user_id, person_id, related_person_id, relation_type) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param('iiis', $userId, $person1Id, $person2Id, $type);
                        $stmt->execute();
                    }
                }

                // === STEP 1: Parse semua sel ke nama saja (belum insert ke DB) ===
                // $colData[colIndex] = [ ['row'=>int, 'names'=>[], 'ids'=>[]], ... ]
                $colData = [];

                foreach ($rows as $rowIndex => $row) {
                    foreach ($row as $colIndex => $cell) {
                        if ($cell === null || trim($cell) === '') continue;
                        $cell = trim($cell);

                        // Pisah berdasarkan "+" lalu bersihkan
                        $rawParts = explode('+', $cell);
                        $nameParts = [];
                        foreach ($rawParts as $part) {
                            $name = trim($part);
                            if ($name !== '') $nameParts[] = $name;
                        }
                        if (empty($nameParts)) continue;

                        if (!isset($colData[$colIndex])) $colData[$colIndex] = [];
                        $colData[$colIndex][] = [
                            'row'   => $rowIndex,
                            'names' => $nameParts,
                            'ids'   => [], // diisi di Step 2
                        ];
                    }
                }

                // Urutkan setiap kolom berdasarkan baris (ascending)
                foreach ($colData as &$colEntries) {
                    usort($colEntries, function($a, $b) { return $a['row'] - $b['row']; });
                }
                unset($colEntries);

                // === STEP 2: Insert persons + bangun relasi + urutan saudara ===
                //
                // ATURAN DEDUP: Jika nama utama (index 0) muncul lebih dari satu kali
                // di kolom yang sama dan memiliki orang tua yang SAMA (entri terdekat
                // di kolom sebelumnya), maka dianggap ORANG YANG SAMA — hanya beda pasangan.
                // Contoh:
                //   Kolom A     Kolom B
                //   Ahmad  →    Budi + Siti
                //               Budi + Aminah   ← Budi ini = Budi yang sama, pasangan ke-2
                //
                // $sameChildMap : "parentCol_parentRow:namaUtama" => personId
                // $childOrderMap: "parentCol_parentRow"           => counter urutan anak

                $childOrderMap = [];
                $sameChildMap  = [];

                // Proses kolom berurutan agar ids kolom sebelumnya sudah tersedia
                ksort($colData);

                foreach ($colData as $colIndex => &$colEntries) {
                    foreach ($colEntries as &$entry) {
                        $rowIndex = $entry['row'];
                        $names    = $entry['names'];

                        // --- Cari parent terlebih dulu (perlu untuk dedup) ---
                        $parentEntry    = null;
                        $parentEntryIdx = null;
                        $parentKey      = null;

                        if ($colIndex > 0 && isset($colData[$colIndex - 1])) {
                            foreach ($colData[$colIndex - 1] as $ppIdx => $pp) {
                                if ($pp['row'] <= $rowIndex) {
                                    $parentEntry    = $pp;
                                    $parentEntryIdx = $ppIdx;
                                } else {
                                    break;
                                }
                            }
                            if ($parentEntry !== null) {
                                $parentKey = ($colIndex - 1) . '_' . $parentEntry['row'];
                            }
                        }

                        // --- Tentukan ID orang utama (dengan logika dedup) ---
                        $mainName   = $names[0];
                        $dedupeKey  = ($parentKey !== null) ? ($parentKey . ':' . $mainName) : null;
                        $isExisting = false;

                        if ($dedupeKey !== null && isset($sameChildMap[$dedupeKey])) {
                            // Nama ini sudah ada sebagai anak dari orang tua yang sama → pakai ID lama
                            $mainId     = $sameChildMap[$dedupeKey];
                            $isExisting = true;
                        } else {
                            // Buat person baru
                            $stmtIns = $mysqli->prepare("INSERT INTO persons (name, user_id, tree_id, gender, is_alive) VALUES (?, ?, ?, 'unknown', 1)");
                            $stmtIns->bind_param('sii', $mainName, $targetUserId, $treeId);
                            $stmtIns->execute();
                            $mainId = $mysqli->insert_id;
                            $imported++;
                            if ($dedupeKey !== null) {
                                $sameChildMap[$dedupeKey] = $mainId;
                            }
                        }

                        // --- Buat person baru untuk setiap pasangan (index 1+, selalu baru) ---
                        $spouseIds = [];
                        for ($i = 1; $i < count($names); $i++) {
                            $spName = $names[$i];
                            $stmtSp = $mysqli->prepare("INSERT INTO persons (name, user_id, tree_id, gender, is_alive) VALUES (?, ?, ?, 'unknown', 1)");
                            $stmtSp->bind_param('sii', $spName, $targetUserId, $treeId);
                            $stmtSp->execute();
                            $spouseIds[] = $mysqli->insert_id;
                            $imported++;
                        }

                        // Simpan ids ke entry agar kolom berikutnya bisa mengambil parentIds
                        $ids         = array_merge([$mainId], $spouseIds);
                        $entry['ids'] = $ids;

                        // --- Relasi pasangan ---
                        foreach ($spouseIds as $spId) {
                            addRelation($mysqli, $mainId, $spId, 'pasangan', $targetUserId);
                            addRelation($mysqli, $spId, $mainId, 'pasangan', $targetUserId);
                        }

                        // --- Relasi orang tua → anak + urutan saudara ---
                        // Hanya untuk orang yang benar-benar baru (bukan dedup)
                        if (!$isExisting && $parentKey !== null && $parentEntryIdx !== null) {
                            $parentIds = $colData[$colIndex - 1][$parentEntryIdx]['ids'];

                            if (!empty($parentIds)) {
                                if (!isset($childOrderMap[$parentKey])) $childOrderMap[$parentKey] = 1;
                                $childOrder = $childOrderMap[$parentKey]++;

                                $stmtOrder = $mysqli->prepare("UPDATE persons SET child_order = ? WHERE id = ?");
                                $stmtOrder->bind_param('ii', $childOrder, $mainId);
                                $stmtOrder->execute();

                                foreach ($parentIds as $pi => $parentId) {
                                    addRelation($mysqli, $parentId, $mainId, 'anak', $targetUserId);
                                    $reverseType = ($pi === 0) ? 'ayah' : 'ibu';
                                    addRelation($mysqli, $mainId, $parentId, $reverseType, $targetUserId);
                                }
                            }
                        }
                    }
                    unset($entry);
                }
                unset($colEntries);
                
                echo "<div class='alert alert-success'>Import selesai. $imported data diproses.</div>";
                if (!empty($errors)) {
                    echo "<div class='alert alert-error'>Error: " . implode(', ', $errors) . "</div>";
                }
            }
        }
        ?>
        
        <form method="post" enctype="multipart/form-data" style="background:#f9fafb; padding:20px; border-radius:10px;">
            <label>Pilih User Tujuan Import:</label>
            <select name="target_user_id" id="target_user" required onchange="loadTrees()">
                <option value="">-- Pilih User --</option>
                <?php 
                $users = $mysqli->query("SELECT id, name FROM users WHERE role != 'admin'");
                while($u = $users->fetch_assoc()) {
                    echo "<option value='".$u['id']."'>".$u['name']."</option>";
                }
                ?>
            </select>
            
            <label>Pilih Pohon Keluarga:</label>
            <select name="tree_id" id="tree_select" required>
                <option value="">-- Pilih Pohon --</option>
            </select>
            
            <label>Pilih File Excel:</label>
            <input type="file" name="excel_file" accept=".xlsx,.xls" required>
            
            <button type="submit" name="upload_excel" class="btn btn-primary" style="margin-top:10px;">Import Data</button>
        </form>
        
        <script>
        function loadTrees() {
            const userId = document.getElementById('target_user').value;
            const treeSelect = document.getElementById('tree_select');
            treeSelect.innerHTML = '<option value="">Loading...</option>';
            
            if (!userId) return;
            
            fetch('?action=get_user_trees&user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    treeSelect.innerHTML = '<option value="">-- Pilih Pohon --</option>';
                    data.forEach(tree => {
                        const option = document.createElement('option');
                        option.value = tree.id;
                        option.textContent = tree.name;
                        treeSelect.appendChild(option);
                    });
                });
        }
        </script>
        
        <div style="margin-top:20px; text-align:center;">
            <a href="?action=admin_users" class="btn btn-secondary btn-sm">← Kembali ke Dashboard Admin</a>
        </div>
    </div>

<?php endif; ?>

</div>

<nav class="bottom-nav">
    <a href="?action=home" class="nav-item <?= ($action === 'home') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path></svg>
        Daftar
    </a>

    <a href="?action=tree" class="nav-item <?= ($action === 'tree') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M12 3v18m-7-6l7-7 7 7m-7 7v-4"></path><circle cx="12" cy="5" r="2"></circle></svg>
        Pohon
    </a>

    <a href="?action=add_person" class="nav-item add-btn">
        <div>
            <svg viewBox="0 0 24 24" style="width:30px; height:30px;"><path d="M12 5v14m-7-7h14"></path></svg>
        </div>
    </a>
    
    <a href="?action=level_line" class="nav-item <?= ($action === 'level_line') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="9" width="6" height="6" rx="1"/><rect x="16" y="4" width="6" height="6" rx="1"/><rect x="16" y="14" width="6" height="6" rx="1"/><line x1="8" y1="12" x2="16" y2="7"/><line x1="8" y1="12" x2="16" y2="17"/></svg>
        Level Line
    </a>

    <a href="?action=settings" class="nav-item <?= ($action === 'settings' || $action === 'support' || $action === 'admin_users') ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        Akun
    </a>
</nav>


<?php if ($isAdmin): ?>
<div id="modalAdminView" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); align-items:center; justify-content:center; z-index:9999; backdrop-filter:blur(2px);">
    <div style="background:#fff; padding:25px; border-radius:12px; width:90%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #f3f4f6; padding-bottom:10px;">
            <h3 style="margin:0;">Akses Admin untuk <span id="admin_view_target_name" style="color:#4f46e5;"></span></h3>
            <button onclick="document.getElementById('modalAdminView').style.display='none'" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#6b7280;">&times;</button>
        </div>
        
        <p style="font-size:0.9rem; color:#6b7280;">Berikut adalah daftar pohon keluarga yang **diizinkan** oleh user untuk dilihat Admin:</p>
        
        <ul id="admin_view_tree_list" style="list-style:none; padding:0; margin:15px 0 0; max-height:300px; overflow-y:auto; border:1px solid #eee; border-radius:8px; background:#fafafa;">
            </ul>
    </div>
</div>
<?php endif; ?>


<script>
// ==========================================
// 1. FUNGSI GLOBAL (WAJIB ADA DI SINI)
// ==========================================

// Fungsi Buka Modal Edit
function editTree(id, name) {
    document.getElementById('edit_tree_id').value = id;
    document.getElementById('edit_tree_name').value = name;
    document.getElementById('modalEdit').style.display = 'flex';
}

// Fungsi Buka Modal Delete
function deleteTree(id, name) {
    const modal = document.getElementById('modalDelete');
    if (!modal) return; 

    document.getElementById('del_tree_id').value = id;
    document.getElementById('del_tree_name_disp').innerText = name;
    
    const checkbox = document.getElementById('confirm_delete_check');
    if (checkbox) {
        checkbox.checked = false;
        toggleDeleteBtn(checkbox);
    }
    
    modal.style.display = 'flex';
}

// Fungsi Buka Modal Tambah Relasi (Tree)
function openRelationModal(personId, personName) {
    const modal = document.getElementById('modalAddRelation');
    if (!modal) {
        console.error("Modal Add Relation tidak ditemukan di DOM.");
        return; 
    }

    // Set Nama di Modal
    const nameTarget = document.getElementById('rel_target_name');
    if(nameTarget) nameTarget.innerText = personName;
    
    // Base URL
    const baseUrl = '?action=add_person&from_id=' + personId;
    
    // Update Link tombol (Safety check elements exist)
    if(document.getElementById('btn_add_father')) document.getElementById('btn_add_father').href  = baseUrl + '&relation_type=ayah';
    if(document.getElementById('btn_add_mother')) document.getElementById('btn_add_mother').href  = baseUrl + '&relation_type=ibu';
    if(document.getElementById('btn_add_spouse')) document.getElementById('btn_add_spouse').href  = baseUrl + '&relation_type=pasangan';
    if(document.getElementById('btn_add_child')) document.getElementById('btn_add_child').href   = baseUrl + '&relation_type=anak';
    if(document.getElementById('btn_add_sibling')) document.getElementById('btn_add_sibling').href = baseUrl + '&relation_type=saudara';
    
    // Tampilkan Modal
    modal.style.display = 'flex';
}

// Fungsi Toggle Tombol Delete
function toggleDeleteBtn(checkbox) {
    const btn = document.getElementById('btn_delete_submit');
    if (checkbox.checked) {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
}

// ==========================================
// 2. LOGIKA UTAMA (NAVIGASI & AJAX)
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    const loader = document.getElementById('page-loader');
    const mainContent = document.getElementById('main-content');

    const showLoader = () => loader && loader.classList.add('active');
    const hideLoader = () => loader && setTimeout(() => loader.classList.remove('active'), 300); 

    async function loadPage(url, pushState = true) {
        showLoader();
        try {
            const response = await fetch(url);
            // Redirect jika session habis
            if (response.url.includes('login.php')) {
                window.location.href = 'login.php';
                return;
            }

            const text = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'text/html');
            const newContent = doc.getElementById('main-content');
            const newTitle = doc.title;

            if (newContent) {
                mainContent.innerHTML = newContent.innerHTML;
                document.title = newTitle;

                // Script tags injected via innerHTML tidak dieksekusi browser secara otomatis.
                // Buat ulang setiap <script> agar benar-benar dijalankan.
                newContent.querySelectorAll('script').forEach(function(oldScript) {
                    var s = document.createElement('script');
                    Array.from(oldScript.attributes).forEach(function(attr) {
                        s.setAttribute(attr.name, attr.value);
                    });
                    s.textContent = oldScript.textContent;
                    document.body.appendChild(s);
                    document.body.removeChild(s);
                });

                mainContent.classList.remove('fade-in');
                void mainContent.offsetWidth;
                mainContent.classList.add('fade-in');

                if (pushState) history.pushState({ url: url }, newTitle, url);
                updateActiveNav(url);
                window.scrollTo(0, 0);
            }
        } catch (error) {
            console.error('Gagal memuat:', error);
        } finally {
            hideLoader();
        }
    }

    function updateActiveNav(url) {
        const navLinks = document.querySelectorAll('.bottom-nav .nav-item, .desktop-nav .d-link');
        navLinks.forEach(link => link.classList.remove('active'));
        navLinks.forEach(link => {
            const href = link.getAttribute('href');
            if (href && url.includes(href)) link.classList.add('active');
        });
    }

    document.addEventListener('click', (e) => {
        // Cegah klik pada tombol modal atau fungsi JS
        if (e.target.onclick) return;

        const link = e.target.closest('a');
        if (link && link.href && 
            link.href.includes(window.location.hostname) && 
            !link.getAttribute('target') && 
            !link.getAttribute('onclick') && 
            !link.href.includes('logout') && 
            !link.href.includes('reset_tree') && 
            !link.href.includes('action=tree') && // <--- TAMBAHKAN BARIS INI
            !link.href.includes('#') &&
            !link.href.includes('export=')) { 
            e.preventDefault(); 
            loadPage(link.href);
        }
    });

    // Toggle date_of_death field based on is_alive status (Add Person Form)
    const isAliveAdd = document.getElementById('is_alive_add');
    const dateOfDeathFieldAdd = document.getElementById('date_of_death_field_add');
    if (isAliveAdd && dateOfDeathFieldAdd) {
        isAliveAdd.addEventListener('change', function() {
            dateOfDeathFieldAdd.style.display = this.value === '0' ? 'block' : 'none';
            if (this.value === '1') {
                document.getElementById('date_of_death_add').value = '';
            }
        });
    }

    // Toggle date_of_death field based on is_alive status (Edit Bio Form)
    const isAliveEdit = document.getElementById('is_alive_edit');
    const dateOfDeathFieldEdit = document.getElementById('date_of_death_field_edit');
    if (isAliveEdit && dateOfDeathFieldEdit) {
        isAliveEdit.addEventListener('change', function() {
            dateOfDeathFieldEdit.style.display = this.value === '0' ? 'block' : 'none';
            if (this.value === '1') {
                document.getElementById('date_of_death_edit').value = '';
            }
        });
    }

    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.url) loadPage(e.state.url, false);
        else loadPage(window.location.href, false);
    });
});



// --- LOGIKA SWEETALERT POPUP HASIL SHARE ---
<?php if (isset($shareStatus)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: '<?= $shareStatus['icon'] ?>',
            title: '<?= $shareStatus['title'] ?>',
            text: '<?= $shareStatus['text'] ?>',
            confirmButtonColor: '#4f46e5'
        });
    });
<?php endif; ?>

// --- FUNGSI GLOBAL LAINNYA ---
function editTree(id, name) {
    document.getElementById('edit_tree_id').value = id;
    document.getElementById('edit_tree_name').value = name;
    document.getElementById('modalEdit').style.display = 'flex';
}

function deleteTree(id, name) {
    const modal = document.getElementById('modalDelete');
    if (!modal) return; 
    document.getElementById('del_tree_id').value = id;
    document.getElementById('del_tree_name_disp').innerText = name;
    modal.style.display = 'flex';
}

function toggleDeleteBtn(checkbox) {
    const btn = document.getElementById('btn_delete_submit');
    if (checkbox.checked) {
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    } else {
        btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
    }
}

// --- FUNGSI SHARE DENGAN LOAD LIST AJAX (UPDATED) ---
function openShareModal(id, name) {
    // 1. Set Data Form
    document.getElementById('share_tree_id').value = id;
    document.getElementById('share_tree_name_disp').innerText = name;
    document.getElementById('modalShare').style.display = 'flex';
    
    // 2. Load List Kolaborator via AJAX
    const listContainer = document.getElementById('collab_list_container');
    listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; padding:10px;">Memuat data...</li>';
    
    fetch('?action=get_collaborators&tree_id=' + id)
        .then(response => response.json())
        .then(data => {
            listContainer.innerHTML = ''; // Kosongkan
            
            if (data.length === 0) {
                listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; font-size:0.85rem; padding:10px;">Belum ada kolaborator.</li>';
            } else {
                data.forEach(user => {
                    const li = document.createElement('li');
                    li.style.display = 'flex';
                    li.style.alignItems = 'center';
                    li.style.justifyContent = 'space-between';
                    li.style.padding = '8px 0';
                    li.style.borderBottom = '1px solid #f9fafb';
                    
                    li.innerHTML = `
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div style="width:30px; height:30px; background:#e0e7ff; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#4f46e5; font-weight:bold; font-size:0.8rem;">
                                ${user.initial || user.name.charAt(0)}
                            </div>
                            <div>
                                <div style="font-size:0.9rem; font-weight:600; color:#374151;">${user.name}</div>
                                <div style="font-size:0.75rem; color:#9ca3af;">${user.email}</div>
                            </div>
                        </div>
                        <form method="post" onsubmit="return confirm('Hapus akses untuk ${user.name}?');" style="margin:0;">
                            <input type="hidden" name="remove_collab" value="1">
                            <input type="hidden" name="tree_id" value="${id}">
                            <input type="hidden" name="collab_user_id" value="${user.user_id}">
                            <button type="submit" style="background:none; border:none; color:#ef4444; font-size:0.8rem; cursor:pointer;">Hapus</button>
                        </form>
                    `;
                    listContainer.appendChild(li);
                });
            }
        })
        .catch(err => {
                console.error('Error get_collaborators:', err);
                listContainer.innerHTML = '<li style="color:red; text-align:center;">Gagal memuat data kolaborator.</li>';
            });
}

// TAMBAHKAN LOGIKA AJAX BARU UNTUK MODAL ADMIN VIEW
function showAdminViewModal(userId, userName) {
    const modal = document.getElementById('modalAdminView');
    const listContainer = document.getElementById('admin_view_tree_list');
    
    if (!modal) return;
    
    document.getElementById('admin_view_target_name').innerText = userName;
    listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; padding:15px;">Memuat data pohon...</li>';
    modal.style.display = 'flex';
    
    // AJAX untuk mengambil daftar tree yang diizinkan
    fetch('?action=get_viewable_trees&user_id=' + userId)
        .then(response => {
            // Periksa apakah ada pengalihan ke login
            if (response.url.includes('login.php')) {
                window.location.href = 'login.php';
                return;
            }
            return response.json();
        })
        .then(data => {
            listContainer.innerHTML = ''; 
            if (data.length === 0) {
                listContainer.innerHTML = '<li style="text-align:center; color:#9ca3af; padding:15px;">Tidak ada pohon keluarga yang diizinkan.</li>';
            } else {
                data.forEach(tree => {
                    const li = document.createElement('li');
                    li.style.padding = '10px 15px';
                    li.style.borderBottom = '1px solid #eee';
                    
                    li.innerHTML = `
                        <div style="font-weight:600; color:#1f2937;">🌳 ${tree.name}</div>
                        <div style="font-size:0.8rem; color:#6b7280;">ID: ${tree.id}</div>
                        <a href="?action=home&view_user_id=${userId}&view_tree_id=${tree.id}" 
                           target="_blank" 
                           class="btn btn-sm btn-primary" 
                           style="margin-top:5px; font-size:0.75rem; background:#10b981;">
                            🔎 Lihat Pohon Ini
                        </a>
                    `;
                    listContainer.appendChild(li);
                });
            }
        })
        .catch(err => {
            console.error('Error fetching viewable trees:', err);
            listContainer.innerHTML = '<li style="color:red; text-align:center; padding:15px;">Gagal memuat data.</li>';
        });
}


// ══════════════════════════════════════════════
// LIVE SEARCH — bekerja di semua halaman
// ══════════════════════════════════════════════
(function() {
    var _timer = null;
    var _lastQ = '';

    /* avatar color berdasarkan gender */
    function avatarColor(gender) {
        return gender === 'male'   ? '#3b82f6'
             : gender === 'female' ? '#ec4899'
             : '#6366f1';
    }

    /* label singkat gender */
    function genderLabel(gender, alive) {
        var g = gender === 'male' ? 'Laki-laki' : gender === 'female' ? 'Perempuan' : '';
        var a = alive == 1 ? '' : ' · Almarhum/ah';
        return g + a;
    }

    /* buat initial avatar */
    function initial(name) {
        return name ? name.trim().charAt(0).toUpperCase() : '?';
    }

    /* render hasil ke dalam dropdown div */
    function renderResults(dropEl, data, q) {
        dropEl.innerHTML = '';
        if (!data || data.length === 0) {
            dropEl.innerHTML = '<div class="ls-empty">Tidak ditemukan hasil untuk "<strong>' +
                q.replace(/</g,'&lt;') + '</strong>"</div>';
            dropEl.style.display = 'block';
            return;
        }
        data.forEach(function(p) {
            var a = document.createElement('a');
            a.className = 'ls-item';
            a.href = '?action=view_person&id=' + p.id;
            a.setAttribute('tabindex', '0');
            var col = avatarColor(p.gender);
            var sub = genderLabel(p.gender, p.is_alive);
            a.innerHTML =
                '<div class="ls-avatar" style="background:' + col + '">' + initial(p.name) + '</div>' +
                '<div class="ls-info">' +
                  '<span class="ls-name">' + p.name.replace(/</g,'&lt;') + '</span>' +
                  (sub ? '<span class="ls-sub">' + sub + '</span>' : '') +
                '</div>';
            /* navigasi via AJAX loadPage jika tersedia, fallback ke href */
            a.addEventListener('click', function(e) {
                e.preventDefault();
                closeAll();
                if (typeof loadPage === 'function') loadPage(a.href);
                else window.location.href = a.href;
            });
            dropEl.appendChild(a);
        });
        dropEl.style.display = 'block';
    }

    /* tutup semua dropdown */
    function closeAll() {
        ['ls-drop-mobile','ls-drop-desktop'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        ['ls-input-mobile','ls-input-desktop'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        _lastQ = '';
    }

    /* fetch & tampilkan */
    function doSearch(q, dropEl) {
        if (q === _lastQ) return;
        _lastQ = q;
        if (q.length === 0) { dropEl.style.display = 'none'; return; }
        fetch('?action=search_persons&q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(data) { renderResults(dropEl, data, q); })
            .catch(function() { dropEl.style.display = 'none'; });
    }

    /* pasang event ke sepasang input+dropdown */
    function bindSearch(inputId, dropId) {
        var inp = document.getElementById(inputId);
        var drp = document.getElementById(dropId);
        if (!inp || !drp) return;

        inp.addEventListener('input', function() {
            var q = inp.value.trim();
            clearTimeout(_timer);
            if (q.length === 0) { drp.style.display = 'none'; _lastQ = ''; return; }
            _timer = setTimeout(function() { doSearch(q, drp); }, 220);
        });

        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeAll(); inp.blur(); }
            if (e.key === 'Enter') {
                var first = drp.querySelector('.ls-item');
                if (first) first.click();
            }
            /* navigasi dengan arrow key */
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var items = Array.from(drp.querySelectorAll('.ls-item'));
                if (!items.length) return;
                var cur = drp.querySelector('.ls-item:focus');
                var idx = items.indexOf(cur);
                if (e.key === 'ArrowDown') idx = (idx + 1) % items.length;
                else idx = (idx - 1 + items.length) % items.length;
                items[idx].focus();
            }
        });

        inp.addEventListener('focus', function() {
            if (inp.value.trim().length > 0 && _lastQ === inp.value.trim()) {
                drp.style.display = 'block';
            }
        });
    }

    /* tutup jika klik di luar */
    document.addEventListener('click', function(e) {
        var wm = document.getElementById('ls-wrap-mobile');
        var wd = document.getElementById('ls-wrap-desktop');
        if (wm && !wm.contains(e.target)) document.getElementById('ls-drop-mobile').style.display = 'none';
        if (wd && !wd.contains(e.target)) document.getElementById('ls-drop-desktop').style.display = 'none';
    });

    /* init sekarang DAN setiap kali konten AJAX berubah
       (search bar ada di header — tidak ikut diganti AJAX, jadi cukup sekali) */
    bindSearch('ls-input-mobile',  'ls-drop-mobile');
    bindSearch('ls-input-desktop', 'ls-drop-desktop');

})();


</script>

</body>
</html>
