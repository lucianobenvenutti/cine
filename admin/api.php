<?php
/**
 * Cine Recite — API Backend
 * Archivo: admin/api.php
 *
 * Requiere PHP 7.4+ y MySQL/MariaDB (phpMyAdmin para gestionarlo).
 * Ejecutar el bloque SQL de abajo UNA sola vez para crear las tablas.
 */

/* ══════════════════════════
   CONFIGURACIÓN DE BASE DE DATOS
   ↓ Cambiá estos valores por los tuyos ↓
══════════════════════════ */
define('DB_HOST', 'localhost');
define('DB_NAME', 'cine_recite');   // nombre de la base en phpMyAdmin
define('DB_USER', 'root');          // usuario MySQL (por defecto 'root' en XAMPP/WAMP)
define('DB_PASS', '');              // contraseña MySQL (vacía por defecto en XAMPP)
define('DB_CHARSET', 'utf8mb4');

/* ══════════════════════════
   USUARIO ADMIN
   ↓ Cambiá estas credenciales ↓
══════════════════════════ */
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'cine2025');   // ← cambiá esto

/* ══════════════════════════
   HEADERS
══════════════════════════ */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

/* ══════════════════════════
   CONEXIÓN
══════════════════════════ */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/* ══════════════════════════
   ROUTER
══════════════════════════ */
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $body   = file_get_contents('php://input');
    $data   = json_decode($body, true) ?? [];
    $action = $data['action'] ?? '';
}

try {
    switch ($action) {

        case 'login':
            handleLogin($data);
            break;

        case 'get_movies':
            handleGetMovies();
            break;

        case 'save_movie':
            handleSaveMovie($data['movie'] ?? []);
            break;

        case 'update_movie':
            handleUpdateMovie($data['movie'] ?? []);
            break;

        case 'delete_movie':
            handleDeleteMovie((int)($data['id'] ?? 0));
            break;

        case 'get_schedules':
            handleGetSchedules((int)($_GET['movie_id'] ?? 0));
            break;

        default:
            json_out(['ok' => false, 'msg' => 'Acción desconocida: ' . $action]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    json_out(['ok' => false, 'msg' => 'Error de base de datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    json_out(['ok' => false, 'msg' => $e->getMessage()]);
}

/* ══════════════════════════
   HANDLERS
══════════════════════════ */

function handleLogin(array $data): void {
    $user = trim($data['user'] ?? '');
    $pass = $data['pass'] ?? '';

    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        json_out(['ok' => true, 'user' => $user]);
    } else {
        json_out(['ok' => false, 'msg' => 'Usuario o contraseña incorrectos.']);
    }
}

function handleGetMovies(): void {
    $db  = getDB();
    $sql = 'SELECT * FROM movies ORDER BY created_at DESC';
    $movies = $db->query($sql)->fetchAll();

    // Cargar horarios para cada película
    foreach ($movies as &$m) {
        $m['horarios'] = getMovieSchedules((int)$m['id'], $db);
    }
    unset($m);

    json_out(['ok' => true, 'movies' => $movies]);
}

function handleSaveMovie(array $m): void {
    if (empty($m['titulo'])) throw new Exception('El título es obligatorio.');

    $db  = getDB();
    $sql = '
        INSERT INTO movies
            (titulo, sinopsis, genero, duracion, clasificacion, estado,
             anio, pais, director, reparto, poster, fondo, trailer)
        VALUES
            (:titulo, :sinopsis, :genero, :duracion, :clasificacion, :estado,
             :anio, :pais, :director, :reparto, :poster, :fondo, :trailer)
    ';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':titulo'         => $m['titulo'],
        ':sinopsis'       => $m['sinopsis']       ?? null,
        ':genero'         => $m['genero']         ?? null,
        ':duracion'       => $m['duracion']        ?? null,
        ':clasificacion'  => $m['clasificacion']  ?? null,
        ':estado'         => $m['estado']          ?? 'en_cartelera',
        ':anio'           => $m['anio']            ?: null,
        ':pais'           => $m['pais']            ?? null,
        ':director'       => $m['director']        ?? null,
        ':reparto'        => $m['reparto']         ?? null,
        ':poster'         => $m['poster']          ?? null,
        ':fondo'          => $m['fondo']           ?? null,
        ':trailer'        => $m['trailer']         ?? null,
    ]);

    $id = (int)$db->lastInsertId();
    saveSchedules($id, $m['horarios'] ?? [], $db);

    json_out(['ok' => true, 'id' => $id]);
}

function handleUpdateMovie(array $m): void {
    if (empty($m['id']))     throw new Exception('ID de película faltante.');
    if (empty($m['titulo'])) throw new Exception('El título es obligatorio.');

    $db  = getDB();
    $sql = '
        UPDATE movies SET
            titulo        = :titulo,
            sinopsis      = :sinopsis,
            genero        = :genero,
            duracion      = :duracion,
            clasificacion = :clasificacion,
            estado        = :estado,
            anio          = :anio,
            pais          = :pais,
            director      = :director,
            reparto       = :reparto,
            poster        = :poster,
            fondo         = :fondo,
            trailer       = :trailer,
            updated_at    = NOW()
        WHERE id = :id
    ';
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':titulo'         => $m['titulo'],
        ':sinopsis'       => $m['sinopsis']       ?? null,
        ':genero'         => $m['genero']         ?? null,
        ':duracion'       => $m['duracion']        ?? null,
        ':clasificacion'  => $m['clasificacion']  ?? null,
        ':estado'         => $m['estado']          ?? 'en_cartelera',
        ':anio'           => $m['anio']            ?: null,
        ':pais'           => $m['pais']            ?? null,
        ':director'       => $m['director']        ?? null,
        ':reparto'        => $m['reparto']         ?? null,
        ':poster'         => $m['poster']          ?? null,
        ':fondo'          => $m['fondo']           ?? null,
        ':trailer'        => $m['trailer']         ?? null,
        ':id'             => (int)$m['id'],
    ]);

    saveSchedules((int)$m['id'], $m['horarios'] ?? [], $db);

    json_out(['ok' => true]);
}

function handleDeleteMovie(int $id): void {
    if (!$id) throw new Exception('ID inválido.');
    $db = getDB();
    $db->prepare('DELETE FROM schedules WHERE movie_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM movies WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

function handleGetSchedules(int $movieId): void {
    $db = getDB();
    json_out(['ok' => true, 'horarios' => getMovieSchedules($movieId, $db)]);
}

/* ══════════════════════════
   HELPERS
══════════════════════════ */

function getMovieSchedules(int $movieId, PDO $db): array {
    $stmt = $db->prepare('SELECT dia, hora, version FROM schedules WHERE movie_id = ? ORDER BY FIELD(dia,"Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"), hora');
    $stmt->execute([$movieId]);
    $rows = $stmt->fetchAll();

    // Agrupar por día
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['dia']][] = ['hora' => $r['hora'], 'version' => $r['version']];
    }

    $result = [];
    foreach ($grouped as $dia => $horas) {
        $result[] = ['dia' => $dia, 'horas' => $horas];
    }
    return $result;
}

function saveSchedules(int $movieId, array $horarios, PDO $db): void {
    $db->prepare('DELETE FROM schedules WHERE movie_id = ?')->execute([$movieId]);

    $stmt = $db->prepare('INSERT INTO schedules (movie_id, dia, hora, version) VALUES (?, ?, ?, ?)');
    foreach ($horarios as $h) {
        $dia   = $h['dia']   ?? '';
        $horas = $h['horas'] ?? [];
        foreach ($horas as $entrada) {
            $hora    = is_array($entrada) ? ($entrada['hora']    ?? '') : $entrada;
            $version = is_array($entrada) ? ($entrada['version'] ?? 'subtitulada') : 'subtitulada';
            if ($dia && $hora) {
                $stmt->execute([$movieId, $dia, $hora, $version]);
            }
        }
    }
}

function json_out(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}