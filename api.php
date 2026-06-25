<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ================================================================
// 1. CREARE TABELE
// ================================================================
$db->exec("CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oras TEXT,
    cartier TEXT,
    categorie TEXT,
    zona_depozitare TEXT,
    status TEXT DEFAULT 'Nerezolvat',
    echipa_alocata TEXT DEFAULT 'Neasignat',
    data_creare DATE DEFAULT CURRENT_DATE
)");

// Migrare: adauga echipa_alocata daca DB-ul vechi nu o are
$cols = $db->query("PRAGMA table_info(reports)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('echipa_alocata', $cols)) {
    $db->exec("ALTER TABLE reports ADD COLUMN echipa_alocata TEXT DEFAULT 'Neasignat'");
}

$db->exec("CREATE TABLE IF NOT EXISTS locatii (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oras TEXT,
    cartier TEXT,
    zona_depozitare TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS utilizatori (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password TEXT,
    role TEXT,
    oras TEXT,
    cartier_alocat TEXT DEFAULT NULL
)");

// ================================================================
// 2. SEED LOCATII HARDCODATE (daca tabelul e gol)
// ================================================================
if ($db->query("SELECT COUNT(*) FROM locatii")->fetchColumn() == 0) {
    $locatii = [
        // Iași
        ['Iași', 'Copou',    'Punct Colectare Codrescu'],
        ['Iași', 'Copou',    'Punct Colectare Triumf'],
        ['Iași', 'Copou',    'Punct Colectare Agronomie'],
        ['Iași', 'Copou',    'Misc / Ilegal'],
        ['Iași', 'Tătărași', 'Punct Colectare Dispecer'],
        ['Iași', 'Tătărași', 'Punct Colectare Ateneu'],
        ['Iași', 'Tătărași', 'Punct Colectare Flora'],
        ['Iași', 'Tătărași', 'Misc / Ilegal'],
        ['Iași', 'Centru',   'Punct Colectare Sf. Lazăr'],
        ['Iași', 'Centru',   'Punct Colectare Palas'],
        ['Iași', 'Centru',   'Punct Colectare Piața Unirii'],
        ['Iași', 'Centru',   'Misc / Ilegal'],
        ['Iași', 'Păcurari', 'Punct Colectare Moara 1 Mai'],
        ['Iași', 'Păcurari', 'Punct Colectare Columnei'],
        ['Iași', 'Păcurari', 'Misc / Ilegal'],
        ['Iași', 'Nicolina', 'Punct Colectare Belvedere'],
        ['Iași', 'Nicolina', 'Punct Colectare Mathia'],
        ['Iași', 'Nicolina', 'Misc / Ilegal'],
        // Pașcani
        ['Pașcani', 'Centru',   'Punct Colectare Gară'],
        ['Pașcani', 'Centru',   'Punct Colectare Primărie'],
        ['Pașcani', 'Centru',   'Misc / Ilegal'],
        ['Pașcani', 'Suburbie', 'Punct Colectare Principal'],
        ['Pașcani', 'Suburbie', 'Misc / Ilegal'],
        // Cluj-Napoca
        ['Cluj-Napoca', 'Mărăști', 'Punct Colectare Piața Mărăști'],
        ['Cluj-Napoca', 'Mărăști', 'Punct Colectare Expo'],
        ['Cluj-Napoca', 'Mărăști', 'Misc / Ilegal'],
        ['Cluj-Napoca', 'Zorilor', 'Punct Colectare Observator'],
        ['Cluj-Napoca', 'Zorilor', 'Punct Colectare Spital'],
        ['Cluj-Napoca', 'Zorilor', 'Misc / Ilegal'],
    ];
    $stmt = $db->prepare("INSERT INTO locatii (oras, cartier, zona_depozitare) VALUES (?, ?, ?)");
    foreach ($locatii as $r) $stmt->execute($r);
}

// ================================================================
// 3. SEED UTILIZATORI
// Compatibil cu DB vechi (plaintext) SI DB nou (hashed).
// Daca userul exista deja cu parola plaintext, il upgradaza la hash.
// Daca tabelul e gol, insereaza direct cu hash.
// ================================================================
if ($db->query("SELECT COUNT(*) FROM utilizatori")->fetchColumn() == 0) {
    $useri = [
        ['primarie',               password_hash('iasi123',       PASSWORD_DEFAULT), 'primarie', 'Iași',         null],
        ['pascani@mail.ro',        password_hash('pascani123',    PASSWORD_DEFAULT), 'primarie', 'Pașcani',      null],
        ['cluj@mail.ro',           password_hash('cluj123',       PASSWORD_DEFAULT), 'primarie', 'Cluj-Napoca',  null],
        ['salubris',               password_hash('copou123',      PASSWORD_DEFAULT), 'salubris', 'Iași',         'Copou'],
        ['salubris.centru@mail.ro',password_hash('centru123',     PASSWORD_DEFAULT), 'salubris', 'Iași',         'Centru'],
        ['salubris.tatarasi@mail.ro',password_hash('tatarasi123', PASSWORD_DEFAULT), 'salubris', 'Iași',         'Tătărași'],
        ['salubris.nicolina@mail.ro',password_hash('nicolina123', PASSWORD_DEFAULT), 'salubris', 'Iași',         'Nicolina'],
        ['salubris.pacurari@mail.ro',password_hash('pacurari123', PASSWORD_DEFAULT), 'salubris', 'Iași',         'Păcurari'],
    ];
    $stmt = $db->prepare("INSERT OR IGNORE INTO utilizatori (username, password, role, oras, cartier_alocat) VALUES (?, ?, ?, ?, ?)");
    foreach ($useri as $u) $stmt->execute($u);
} else {
    // Upgrade parole plaintext la hash pentru userii existenti
    // Detectam dupa faptul ca hash-urile bcrypt incep intotdeauna cu '$2y$'
    $stmt = $db->query("SELECT id, password FROM utilizatori");
    $upgrade = $db->prepare("UPDATE utilizatori SET password = ? WHERE id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (substr($u['password'], 0, 4) !== '$2y$') {
            $upgrade->execute([password_hash($u['password'], PASSWORD_DEFAULT), $u['id']]);
        }
    }
}

// ================================================================
// 4. MENTENANTA: sterge rapoarte solutionate mai vechi de 30 zile
// ================================================================
$db->exec("DELETE FROM reports WHERE status = 'Soluționat' AND data_creare <= date('now', '-30 days')");

// ================================================================
// 5. RUTARE ACTIUNI
// ================================================================
$action = $_GET['action'] ?? '';

// ---------------------------------------------------------------
// AUTH: Login Primărie (accepta DOAR role = 'primarie')
// ---------------------------------------------------------------
if ($action === 'login_primarie' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $stmt = $db->prepare("SELECT * FROM utilizatori WHERE username = :u");
    $stmt->execute([':u' => trim($input['username'] ?? '')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($input['password'] ?? '', $user['password'])) {
        if ($user['role'] !== 'primarie') {
            echo json_encode(["status" => "error", "message" => "Acest cont nu are acces la panoul Primăriei."]);
            exit;
        }
        unset($user['password']);
        echo json_encode(["status" => "success", "user" => $user]);
    } else {
        echo json_encode(["status" => "error", "message" => "Date de autentificare incorecte!"]);
    }
    exit;
}

// ---------------------------------------------------------------
// AUTH: Login Salubris (accepta DOAR role = 'salubris')
// ---------------------------------------------------------------
if ($action === 'login_salubris' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $stmt = $db->prepare("SELECT * FROM utilizatori WHERE username = :u");
    $stmt->execute([':u' => trim($input['username'] ?? '')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($input['password'] ?? '', $user['password'])) {
        if ($user['role'] !== 'salubris') {
            echo json_encode(["status" => "error", "message" => "Acest cont nu are acces la panoul Salubris."]);
            exit;
        }
        unset($user['password']);
        $user['nume_echipa'] = 'Salubris ' . $user['cartier_alocat'];
        echo json_encode(["status" => "success", "user" => $user]);
    } else {
        echo json_encode(["status" => "error", "message" => "Date de autentificare incorecte!"]);
    }
    exit;
}

// ---------------------------------------------------------------
// LOCATIONS: Structura arborescenta pentru dropdown-uri
// ---------------------------------------------------------------
if ($action === 'get_locations') {
    $stmt = $db->query("SELECT oras, cartier, zona_depozitare FROM locatii ORDER BY oras, cartier, zona_depozitare");
    $raw  = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $structura = [];
    foreach ($raw as $row) {
        $o = $row['oras']; $c = $row['cartier']; $z = $row['zona_depozitare'];
        if (!isset($structura[$o]))     $structura[$o]     = [];
        if (!isset($structura[$o][$c])) $structura[$o][$c] = [];
        if (!in_array($z, $structura[$o][$c])) $structura[$o][$c][] = $z;
    }
    echo json_encode($structura);
    exit;
}

// ---------------------------------------------------------------
// LOCATIONS: Adaugare zona noua de catre Primarie
// ---------------------------------------------------------------
if ($action === 'add_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $oras  = trim($input['oras']            ?? '');
    $cart  = trim($input['cartier']         ?? '');
    $zona  = trim($input['zona_depozitare'] ?? '');

    if (!$oras || !$cart || !$zona) {
        echo json_encode(["status" => "error", "message" => "Câmpuri incomplete!"]);
        exit;
    }

    $check = $db->prepare("SELECT COUNT(*) FROM locatii WHERE oras = :o AND cartier = :c AND zona_depozitare = :z");
    $check->execute([':o' => $oras, ':c' => $cart, ':z' => $zona]);
    if ($check->fetchColumn() > 0) {
        echo json_encode(["status" => "error", "message" => "Această zonă există deja în sistem!"]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO locatii (oras, cartier, zona_depozitare) VALUES (:o, :c, :z)");
    $stmt->execute([':o' => $oras, ':c' => $cart, ':z' => $zona]);
    echo json_encode(["status" => "success", "message" => "Zona a fost adăugată!"]);
    exit;
}

// ---------------------------------------------------------------
// REPORTS: Adaugare sesizare cetatean
// ---------------------------------------------------------------
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $oras  = trim($input['oras']            ?? '');
    $cart  = trim($input['cartier']         ?? '');
    $zona  = trim($input['zona_depozitare'] ?? '');
    $categ = trim($input['categorie']       ?? '');

    if (!$oras || !$cart || !$zona || !$categ) {
        echo json_encode(["status" => "error", "message" => "Date incomplete!"]);
        exit;
    }

    $check = $db->prepare("SELECT COUNT(*) FROM locatii WHERE oras = :o AND cartier = :c AND zona_depozitare = :z");
    $check->execute([':o' => $oras, ':c' => $cart, ':z' => $zona]);
    if ($check->fetchColumn() == 0) {
        echo json_encode(["status" => "error", "message" => "Zonă invalidă!"]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO reports (oras, cartier, zona_depozitare, categorie) VALUES (:o, :c, :z, :cat)");
    $stmt->execute([':o' => $oras, ':c' => $cart, ':z' => $zona, ':cat' => $categ]);
    echo json_encode(["status" => "success", "message" => "Raport înregistrat cu succes!"]);
    exit;
}

// ---------------------------------------------------------------
// REPORTS: Tabel live
// ---------------------------------------------------------------
if ($action === 'live_table') {
    $sql = "SELECT
                oras, cartier, zona_depozitare,
                SUM(CASE WHEN status IN ('Nerezolvat', 'In Lucru') THEN 1 ELSE 0 END) AS nerezolvate,
                SUM(CASE WHEN status IN ('Curatat', 'Soluționat') THEN 1 ELSE 0 END) AS rezolvate,
                COUNT(*) AS total
            FROM reports
            GROUP BY oras, cartier, zona_depozitare
            ORDER BY total DESC";
    echo json_encode($db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---------------------------------------------------------------
// REPORTS: Lista incidente pentru admin (filtrata server-side)
// ---------------------------------------------------------------
if ($action === 'admin_reports') {
    $oras    = $_GET['oras']    ?? '';
    $cartier = $_GET['cartier'] ?? '';

    if ($oras && $cartier) {
        $stmt = $db->prepare("SELECT * FROM reports WHERE oras = :o AND cartier = :c ORDER BY id DESC");
        $stmt->execute([':o' => $oras, ':c' => $cartier]);
    } elseif ($oras) {
        $stmt = $db->prepare("SELECT * FROM reports WHERE oras = :o ORDER BY id DESC");
        $stmt->execute([':o' => $oras]);
    } else {
        echo json_encode(["status" => "error", "message" => "Parametru oras lipsa!"]);
        exit;
    }

    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---------------------------------------------------------------
// REPORTS: Update status individual (salubris)
// ---------------------------------------------------------------
if ($action === 'update_report_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $id     = (int)($input['id']     ?? 0);
    $status = $input['status'] ?? '';

    $statusValide = ['Nerezolvat', 'In Lucru', 'Curatat', 'Soluționat'];
    if (!$id || !in_array($status, $statusValide)) {
        echo json_encode(["status" => "error", "message" => "Date invalide!"]);
        exit;
    }

    $sql    = "UPDATE reports SET status = :status";
    $params = [':status' => $status, ':id' => $id];
    if (!empty($input['echipa'])) {
        $sql .= ", echipa_alocata = :echipa";
        $params[':echipa'] = $input['echipa'];
    }
    $sql .= " WHERE id = :id";

    $db->prepare($sql)->execute($params);
    echo json_encode(["status" => "success"]);
    exit;
}

// ---------------------------------------------------------------
// REPORTS: Update status bulk pe zona (primarie)
// ---------------------------------------------------------------
if ($action === 'update_report_status_bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $oras   = trim($input['oras']            ?? '');
    $cart   = trim($input['cartier']         ?? '');
    $zona   = trim($input['zona_depozitare'] ?? '');
    $status = $input['status'] ?? '';

    $statusValide = ['Nerezolvat', 'In Lucru', 'Curatat', 'Soluționat'];
    if (!$oras || !$cart || !$zona || !in_array($status, $statusValide)) {
        echo json_encode(["status" => "error", "message" => "Date invalide!"]);
        exit;
    }

    $sql    = "UPDATE reports SET status = :status";
    $params = [':status' => $status, ':oras' => $oras, ':cartier' => $cart, ':zona' => $zona];
    if (!empty($input['echipa'])) {
        $sql .= ", echipa_alocata = :echipa";
        $params[':echipa'] = $input['echipa'];
    }
    $sql .= " WHERE oras = :oras AND cartier = :cartier AND zona_depozitare = :zona AND status != 'Soluționat'";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status" => "success", "actualizate" => $stmt->rowCount()]);
    exit;
}

// ---------------------------------------------------------------
// AUTH: Inregistrare cont nou (salubris sau primarie)
// ---------------------------------------------------------------
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input    = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = trim($input['password'] ?? '');
    $role     = trim($input['role']     ?? '');
    $oras     = trim($input['oras']     ?? '');
    $cartier  = trim($input['cartier_alocat'] ?? '');

    // Validari de baza
    if (!$username || !$password || !$oras) {
        echo json_encode(["status" => "error", "message" => "Câmpuri obligatorii lipsă!"]);
        exit;
    }
    if (!in_array($role, ['primarie', 'salubris'])) {
        echo json_encode(["status" => "error", "message" => "Rol invalid!"]);
        exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(["status" => "error", "message" => "Parola trebuie să aibă cel puțin 6 caractere!"]);
        exit;
    }
    if ($role === 'salubris' && !$cartier) {
        echo json_encode(["status" => "error", "message" => "Echipa Salubris trebuie să aibă un cartier alocat!"]);
        exit;
    }

    // Verificare username unic
    $check = $db->prepare("SELECT COUNT(*) FROM utilizatori WHERE username = :u");
    $check->execute([':u' => $username]);
    if ($check->fetchColumn() > 0) {
        echo json_encode(["status" => "error", "message" => "Acest username este deja folosit!"]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO utilizatori (username, password, role, oras, cartier_alocat) VALUES (:u, :p, :r, :o, :c)");
    $stmt->execute([
        ':u' => $username,
        ':p' => password_hash($password, PASSWORD_DEFAULT),
        ':r' => $role,
        ':o' => $oras,
        ':c' => $role === 'salubris' ? $cartier : null
    ]);
    echo json_encode(["status" => "success", "message" => "Cont creat cu succes!"]);
    exit;
}

echo json_encode(["status" => "error", "message" => "Acțiune necunoscută: $action"]);
