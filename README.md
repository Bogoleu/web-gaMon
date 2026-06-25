# GaMon — Garbage Monitoring on Web

**Autor:** Negrea Iustin 2E3

Aplicație web pentru gestionarea colectării, sortării și reciclării deșeurilor, cu trei roluri: cetățean, primărie și echipă Salubris.

---

## Structură

| Fișier | Rol |
|--------|-----|
| `portal.html` | Hub central cu linkuri către cele 3 secțiuni |
| `index.html` | Cetățean — raportează gunoi, vede statistici live |
| `login_primarie.html` → `primarie.html` | Login + panou administrație |
| `login_salubris.html` → `salubris.html` | Login + panou echipă salubrizare |
| `api.php` | Backend PHP — autentificare, rapoarte, locații, PDF |
| `database.db` | Bază SQLite cu tabelele `reports`, `locatii`, `utilizatori` |
| `fpdf.php` + `font/` | Bibliotecă pentru generare rapoarte PDF |

---

## Flux

1. **Cetățeanul** completează un formular (oraș → cartier → zonă → categorie) și trimite un raport
2. **Primăria** alocă o echipă Salubris unei zone → status devine `In Lucru`
3. **Salubris** primește misiunea, curăță zona și marchează `Curatat`
4. **Primăria** confirmă rezolvarea → status `Soluționat` (se șterge automat după 30 zile)

Statisticile se afișează live cu bare colorate (albastru = nerezolvat, verde = rezolvat). Un banner desemnează **Campionul Curățeniei** — cartierul cu cele mai puține reclamații totale.

---

## Autentificare

Conturile implicite sunt pre-populate în `api.php`. Exemple:

| Rol | Utilizator | Parolă |
|-----|-----------|--------|
| Primărie Iași | `primarie` | `iasi123` |
| Salubris Copou | `salubris` | `copou123` |

## Tehnologii

PHP (PDO/SQLite), JavaScript vanilla, HTML/CSS.
