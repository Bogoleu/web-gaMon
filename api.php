<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Creare tabele dinamicce
$db->exec("CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oras TEXT,
    cartier TEXT,
    categorie TEXT,
    zona_depozitare TEXT,
    status TEXT DEFAULT 'Nerezolvat', /* Nerezolvat, In Lucru, Curatat, Soluționat */
    echipa_alocata TEXT DEFAULT 'Neasignat',
    data_creare DATE DEFAULT CURRENT_DATE
)");

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
    role TEXT, /* primarie, salubris */
    oras TEXT,
    cartier_alocat TEXT DEFAULT NULL
)");

// Populare inițială cu date de test (dacă tabelele sunt goale)
$verifLoc = $db->query("SELECT COUNT(*) FROM locatii")->fetchColumn();
if ($verifLoc == 0) {
    $db->exec("INSERT INTO locatii (oras, cartier, zona_depozitare) VALUES
        ('Iași', 'Copou', 'Punct Colectare Codrescu'),
        ('Iași', 'Copou', 'Punct Colectare Triumf'),
        ('Iași', 'Copou', 'Misc / Ilegal'),
        ('Iași', 'Tătărași', 'Punct Colectare Dispecer'),
        ('Iași', 'Centru', 'Punct Colectare Palas'),
        ('Pașcani', 'Centru', 'Punct Colectare Gară')");
}

$verifUser = $db->query("SELECT COUNT(*) FROM utilizatori")->fetchColumn();
if ($verifUser == 0) {
    $db->exec("INSERT INTO utilizatori (username, password, role, oras, cartier_alocat) VALUES
        /* Conturi oficiale Primării */
        ('iasi@mail.ro', 'iasi123', 'primarie', 'Iași', NULL),
        ('pascani@mail.ro', 'pascani123', 'primarie', 'Pașcani', NULL),
        ('cluj-napoca@mail.ro', 'cluj123', 'primarie', 'Cluj-Napoca', NULL),

        /* Conturi oficiale e-mail pentru Echipele Salubris */
        ('salubris.copou@mail.ro', 'copou123', 'salubris', 'Iași', 'Copou'),
        ('salubris.centru@mail.ro', 'centru123', 'salubris', 'Iași', 'Centru'),
        ('salubris.tatarasi@mail.ro', 'tatarasi123', 'salubris', 'Iași', 'Tătărași')");
}

// 2. CRON JOB INVIZIBIL: Șterge automat soluționările mai vechi de 30 de zile
$db->exec("DELETE FROM reports WHERE status = 'Soluționat' AND data_creare <= date('now', '-30 days')");

$action = $_GET['action'] ?? '';

// AUTH: Login system
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("SELECT username, role, oras, cartier_alocat FROM utilizatori WHERE username = :u AND password = :p");
    $stmt->execute([':u' => $input['username'], ':p' => $input['password']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo json_encode(["status" => "success", "user" => $user]);
    } else {
        echo json_encode(["status" => "error", "message" => "Date incorecte!"]);
    }
    exit;
}

// LOCATIONS: Adăugare zonă nouă de către Primărie
if ($action === 'add_location' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("INSERT INTO locatii (oras, cartier, zona_depozitare) VALUES (:o, :c, :z)");
    $stmt->execute([':o' => $input['oras'], ':c' => $input['cartier'], ':z' => $input['zona_depozitare']]);
    echo json_encode(["status" => "success"]);
    exit;
}

// LOCATIONS: Extragere structură arborescentă pentru Dropdowns
if ($action === 'get_locations') {
    $stmt = $db->query("SELECT oras, cartier, zona_depozitare FROM locatii");
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $structura = [];
    foreach ($raw as $row) {
        $o = $row['oras']; $c = $row['cartier']; $z = $row['zona_depozitare'];
        if (!isset($structura[$o])) $structura[$o] = [];
        if (!isset($structura[$o][$c])) $structura[$o][$c] = [];
        if (!in_array($z, $structura[$o][$c])) $structura[$o][$c][] = $z;
    }
    echo json_encode($structura);
    exit;
}

// ACTION: Adăugare sesizare cetățean
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stmt = $db->prepare("INSERT INTO reports (oras, cartier, zona_depozitare, categorie) VALUES (:oras, :cartier, :zona_depozitare, :categorie)");
    $stmt->execute([
        ':oras' => htmlspecialchars($input['oras']), ':cartier' => htmlspecialchars($input['cartier']),
        ':zona_depozitare' => htmlspecialchars($input['zona_depozitare']), ':categorie' => htmlspecialchars($input['categorie'])
    ]);
    echo json_encode(["status" => "success", "message" => "Raport înregistrat!"]);
    exit;
}

// ACTION: Live Table combinat
if($action === 'live_table') {
    $sql = "SELECT oras, cartier, zona_depozitare,
            SUM(CASE WHEN status != 'Soluționat' THEN 1 ELSE 0 END) as nerezolvate,
            SUM(CASE WHEN status = 'Soluționat' THEN 1 ELSE 0 END) as rezolvate,
            COUNT(*) as total
            FROM reports GROUP BY oras, cartier, zona_depozitare ORDER BY total DESC";
    echo json_encode($db->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ACTION: Listare toate incidentele pentru panourile administrative
if ($action === 'admin_reports') {
    $stmt = $db->query("SELECT * FROM reports ORDER BY id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ACTION: Schimbare Status / Alocare flux salubrizare
if ($action === 'update_report_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sql = "UPDATE reports SET status = :status";
    $params = [':status' => $input['status'], ':id' => $input['id']];

    if (isset($input['echipa'])) {
        $sql .= ", echipa_alocata = :echipa";
        $params[':echipa'] = $input['echipa'];
    }
    $sql .= " WHERE id = :id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status" => "success"]);
    exit;
}
?>