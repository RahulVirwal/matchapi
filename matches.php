<?php
header("Content-Type: application/json");

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

$baseUrl = '/api/';  // Set this to your actual base URL

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Helper function to get input data
function getInputData()
{
    // Check if the content type is JSON
    if ($_SERVER['CONTENT_TYPE'] === 'application/json') {
        return json_decode(file_get_contents('php://input'), true);
    } else {
        // For form-data (POSTMAN form-data), return $_POST directly
        return $_POST;
    }
}

// Helper function to add base URL to image path
function addBaseUrlToImagePath($matches, $baseUrl)
{
    foreach ($matches as &$match) {
        if (!empty($match['image'])) {
            $match['image'] = $baseUrl . $match['image'];
        }
    }
    return $matches;
}

// Validate image file
function validateImage($file)
{
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxFileSize = 2 * 1024 * 1024; // 2MB

    if (!in_array($file['type'], $allowedTypes)) {
        return "Invalid file type. Only JPG, PNG, and GIF types are accepted.";
    }
    if ($file['size'] > $maxFileSize) {
        return "File size exceeds the maximum limit of 2MB.";
    }
    return true;
}

// Generate a unique filename using md5
function generateUniqueFileName($file)
{
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $hash = md5_file($file['tmp_name']);
    return $hash . '.' . $ext;
}

// GET request to fetch all matches or a specific match by ID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        // Fetch match by ID
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
        $stmt->execute([$id]);
        $match = $stmt->fetch();

        if ($match) {
            $match['image'] = $baseUrl . $match['image'];
            echo json_encode(['match' => $match]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Match not found']);
        }
    } else {
        // Fetch all matches
        $stmt = $pdo->query("SELECT * FROM matches");
        $matches = $stmt->fetchAll();
        $matches = addBaseUrlToImagePath($matches, $baseUrl);
        echo json_encode(['matches' => $matches]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // POST request to create a new match
    if (!empty($_POST['name']) && !empty($_POST['shortname']) && !empty($_FILES['image']['name'])) {
        $name = htmlspecialchars($_POST['name']);
        $shortname = htmlspecialchars($_POST['shortname']);

        // Validate and handle the file upload
        $validationResult = validateImage($_FILES['image']);
        if ($validationResult === true) {
            $target_dir = "uploads/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $uniqueFileName = generateUniqueFileName($_FILES['image']);
            $image = $target_dir . $uniqueFileName;
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $image)) {
                // Insert new match into database
                $stmt = $pdo->prepare("INSERT INTO matches (name, shortname, image) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $shortname, $image])) {
                    echo json_encode(['success' => true, 'message' => 'Match added successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to add match']);
                }
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to upload image']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => $validationResult]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // DELETE request to delete a match by ID
    $input = getInputData();
    if (!empty($input['id'])) {
        $id = (int)$input['id'];
        $stmt = $pdo->prepare("DELETE FROM matches WHERE id = ?");
        if ($stmt->execute([$id])) {
            echo json_encode(['success' => true, 'message' => 'Match deleted successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete match']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'PATCH') {
    $data = parseFormData();
    $id_from_url = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id_from_url !== null && isset($data['name']) && isset($data['shortname'])) {
        $id = $id_from_url;
        $name = htmlspecialchars($data['name']);
        $shortname = htmlspecialchars($data['shortname']);
        $image = isset($data['image']) ? $data['image'] : null;

        if ($image) {
            $validationResult = validateImage($image);
            if ($validationResult !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validationResult]);
                exit;
            }

            $savedImagePath = saveImage($image['tmp_name'], $image['name']);
            if (!$savedImagePath) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save image']);
                exit;
            }

            $imagePath = $baseUrl . basename($savedImagePath);
        }

        try {
            $updateFields = ["name = :name", "shortname = :shortname"];
            $queryParams = ['name' => $name, 'shortname' => $shortname, 'id' => $id];

            if ($image !== null) {
                $updateFields[] = "image = :image";
                $queryParams['image'] = $imagePath;
            }

            $updateQuery = "UPDATE matches SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $pdo->prepare($updateQuery);

            if ($stmt->execute($queryParams)) {
                $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
                $stmt->execute([$id]);
                $updatedMatch = $stmt->fetch();
                echo json_encode(['success' => true, 'data' => $updatedMatch]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to update match']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        $errors = [];
        if ($id_from_url === null) {
            $errors[] = "Missing or invalid match ID.";
        }
        if (!isset($data['name'])) {
            $errors[] = "Missing 'name' field in request body.";
        }
        if (!isset($data['shortname'])) {
            $errors[] = "Missing 'shortname' field in request body.";
        }
        echo json_encode(['error' => implode(". ", $errors)]);
    }
    exit;
}
// Handle OPTIONS request for CORS preflight
elseif ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// function validateImage($file)
// {
//     $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
//     $maxFileSize = 2 * 1024 * 1024; // 2MB

//     if (!isset($file['type']) || !in_array($file['type'], $allowedTypes)) {
//         return "Invalid file type. Only JPG, PNG, and GIF types are accepted.";
//     }
//     if (!isset($file['size']) || $file['size'] > $maxFileSize) {
//         return "File size exceeds the maximum limit of 2MB.";
//     }
//     return true;
// }

function saveImage($filePath, $filename)
{
    $targetDir = __DIR__ . '/uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $encryptedFilename = md5(uniqid(rand(), true)) . '.' . $extension;
    $targetFile = $targetDir . $encryptedFilename;

    if (move_uploaded_file($filePath, $targetFile)) {
        return $targetFile;
    }
    return false;
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
            $tmpFilePath = tempnam(sys_get_temp_dir(), 'php');
            file_put_contents($tmpFilePath, $body);
            $data[$name] = [
                'tmp_name' => $tmpFilePath,
                'name' => $filename,
                'type' => mime_content_type($tmpFilePath),
                'size' => filesize($tmpFilePath)
            ];
        } else {
            $data[$name] = trim($body);
        }
    }

    return $data;
}
