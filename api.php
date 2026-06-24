<?php // Aici spun ca e limbaj php (toate fisierele php incep asa)
//header este o functie de trimis instructiuni inainte d a trimite date

header("Access-Control-Allow-Origin: *"); //Permite paginii sa vb cu API fara sa fie blocate de "CORS"?
header("Content-Type: application/json; charset=UTF-8"); //Browserul stie ca e vorba de date brute JSON

// 1. Conectare la SQLite (creează fișierul automat dacă nu există)
$db = new PDO('sqlite:database.db'); //( $ = o variabila, dar php stie automat;PDO este Librarie pt baze de date)
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//$db->exec("DROP TABLE IF EXISTS reports");
// 2. Creare tabelă dacă nu există (Prepared statements nativ pentru siguranță)
$db->exec("CREATE TABLE IF NOT EXISTS reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oras TEXT,
    cartier TEXT,
    categorie TEXT,
    zona_depozitare TEXT,
    status TEXT DEFAULT 'Nerezolvat',
    data_creare DATE DEFAULT CURRENT_DATE
)");

// Prevenire SQL Injection: Toate cererile folosesc parametrii propuși de PDO

$action = $_GET['action'] ?? ''; // Acel action este inlocuit dupa de actiunea prorpiu zisa care e verificata mai jos

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $db->prepare("INSERT INTO reports (oras,cartier,zona_depozitare, categorie) VALUES (:oras, :cartier,:zona_depozitare, :categorie)");
    $stmt->execute([
        ':oras' => htmlspecialchars($input['oras']),
        ':cartier' => htmlspecialchars($input['cartier']), // Prevenire XSS
        ':zona_depozitare' => htmlspecialchars($input['zona_depozitare']),
        ':categorie' => htmlspecialchars($input['categorie'])
    ]);
    
    echo json_encode(["status" => "success", "message" => "Raport salvat!"]);
    exit;
}

// ACTION: Ia datele pentru grafice (Apelat din dashboard.html)
if ($action === 'stats') {
    $stmt = $db->query("SELECT cartier, COUNT(*) as total FROM reports GROUP BY cartier ORDER BY total DESC");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($data);
    exit;
}

if($action === 'live_table') {
    $sql= "SELECT oras, cartier, zona_depozitare,
                       SUM(CASE WHEN status = 'Nerezolvat' THEN 1 ELSE 0 END) as nerezolvate,
                       SUM(CASE WHEN status = 'Soluționat' THEN 1 ELSE 0 END) as rezolvate,
                       COUNT(*) as total
                       FROM reports
                       GROUP BY oras, cartier, zona_depozitare
                       ORDER BY total ASC";


    $stmt = $db->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($data);
    exit;
}

// ACTION: Export CSV
if ($action === 'export_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="raport_gamon.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Cartier', 'Categorie', 'Status', 'Data']);
    
    $stmt = $db->query("SELECT * FROM reports");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// EXPORT PDF: TODO-> DASHBOARD CHANGE
if ($action === 'export_pdf') {
    require('fpdf.php');

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B', 16);
    //Titlu
    $pdf->Cell(0,10, 'Raport GaMon - Situatie', 0, 1,'C');
    $pdf->Ln(10); //E vorba de mm de spatiu gol

    //Antet tabel
    $pdf->SetFont('Arial','B', 12 );
    $pdf->Cell(20,10,'ID',1);
    $pdf->Cell(50,10,'Cartier',1);
    $pdf->Cell(60,10,'Categorie',1);
    $pdf->Cell(40,10,'Status',1);
    $pdf->Ln(); //Asa e doar un rand

    //Extragere din baza de date
    $pdf->SetFont('Arial','',12);
    $stmt = $db->query("SELECT * FROM reports");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
        $pdf->Cell(15,10, $row['id'], 1);
        $pdf->Cell(45,10, $row['cartier'], 1);
        $pdf->Cell(50,10, $row['zona_depozitare'], 1);
        $pdf->Cell(45,10, $row['categorie'], 1);
        $pdf->Cell(35,10, $row['status'], 1);
        $pdf->Ln();
    }
    $pdf->Output('D','Raport_GaMon.pdf');
    exit;
}