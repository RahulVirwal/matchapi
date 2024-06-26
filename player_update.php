<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$host = 'localhost';
$db = 'match_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$baseUrl = '/api/uploads/';
$uploadsDir = __DIR__ . '/uploads/';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

function validateImage($file)
{
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $fileType = $finfo->buffer($file['content']);

    if (!in_array($fileType, $allowedTypes)) {
        return "Invalid file type. Only JPG, PNG, and GIF types are accepted.";
    }
    return true;
}
function saveImage($content, $filename)
{
    global $uploadsDir;

    // Ensure uploads directory exists and is writable
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0777, true)) {
            return ['error' => 'Failed to create uploads directory.'];
        }
    } elseif (!is_writable($uploadsDir)) {
        return ['error' => 'Uploads directory is not writable.'];
    }

    // Generate unique encrypted filename
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $encryptedFilename = md5(uniqid(rand(), true)) . '.' . $extension;
    $targetFile = $uploadsDir . $encryptedFilename;

    // Save file content to the target directory
    if (file_put_contents($targetFile, $content)) {
        return ['success' => true, 'filename' => $encryptedFilename];
    } else {
        return ['error' => 'Failed to save file.'];
    }
}



function parseFormData()
{
    $rawData = file_get_contents("php://input");
    preg_match('/boundary=(.*)$/', $_SERVER['CONTENT_TYPE'], $matches);
    $boundary = $matches[1];
    $blocks = preg_split("/-+$boundary/", $rawData);
    array_pop($blocks);

    $data = [];

    foreach ($blocks as $block) {
        if (empty($block)) continue;

        list($header, $body) = explode("\r\n\r\n", trim($block), 2);
        preg_match('/name="([^"]+)"/', $header, $matches);
        $name = $matches[1];

        if (strpos($header, 'filename=') !== false) {
            preg_match('/filename="([^"]+)"/', $header, $matches);
            $filename = $matches[1];
            $data[$name] = [
                'content' => $body,
                'name' => $filename
            ];
        } else {
            $data[$name] = trim($body);
        }
    }

    return $data;
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = parseFormData();
    $id_from_url = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id_from_url !== null && isset($data['team_name']) && isset($data['match_name']) && isset($data['player_name']) && isset($data['player_shortname'])) {
        $id = $id_from_url;
        $team_name = htmlspecialchars($data['team_name']);
        $match_name = htmlspecialchars($data['match_name']);
        $player_name = htmlspecialchars($data['player_name']);
        $player_shortname = htmlspecialchars($data['player_shortname']);
        $player_image = isset($data['player_image']) ? $data['player_image'] : null;

        $imagePath = null; // Initialize image path variable

        if ($player_image) {
            $validationResult = validateImage($player_image);
            if ($validationResult !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validationResult]);
                exit;
            }

            $saveResult = saveImage($player_image['content'], $player_image['name']);
            if (isset($saveResult['error'])) {
                http_response_code(500);
                echo json_encode(['error' => $saveResult['error']]);
                exit;
            }

            $imagePath = $saveResult['filename']; // Store only the filename
        }

        try {
            $updateFields = ["team_name = :team_name", "match_name = :match_name", "player_name = :player_name", "player_shortname = :player_shortname"];
            $queryParams = ['team_name' => $team_name, 'match_name' => $match_name, 'player_name' => $player_name, 'player_shortname' => $player_shortname, 'id' => $id];

            if ($imagePath !== null) {
                $updateFields[] = "player_image = :player_image";
                $queryParams['player_image'] = $imagePath;
            }

            $updateQuery = "UPDATE players SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $pdo->prepare($updateQuery);

            if ($stmt->execute($queryParams)) {
                $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ?");
                $stmt->execute([$id]);
                $updatedPlayer = $stmt->fetch();

                // Provide full URL for the image in the response
                if ($updatedPlayer['player_image']) {
                    $updatedPlayer['player_image'] = $baseUrl . $updatedPlayer['player_image'];
                }

                echo json_encode(['success' => true, 'data' => $updatedPlayer]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update player']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        $errors = [];
        if ($id_from_url === null) {
            $errors[] = "Missing or invalid player ID.";
        }
        if (!isset($data['team_name'])) {
            $errors[] = "Missing 'team_name' field in request body.";
        }
        if (!isset($data['match_name'])) {
            $errors[] = "Missing 'match_name' field in request body.";
        }
        if (!isset($data['player_name'])) {
            $errors[] = "Missing 'player_name' field in request body.";
        }
        if (!isset($data['player_shortname'])) {
            $errors[] = "Missing 'player_shortname' field in request body.";
        }
        echo json_encode(['error' => implode(". ", $errors)]);
    }
    exit;
}

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
