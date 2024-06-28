<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include 'database/database.php';

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

    // Generate unique filename
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

    if ($id_from_url !== null && isset($data['name']) && isset($data['shortname'])) {
        $id = $id_from_url;
        $name = htmlspecialchars($data['name']);
        $shortname = htmlspecialchars($data['shortname']);
        $image = isset($data['image']) ? $data['image'] : null;

        $imagePath = null; // Initialize image path variable

        if ($image) {
            $validationResult = validateImage($image);
            if ($validationResult !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validationResult]);
                exit;
            }

            $saveResult = saveImage($image['content'], $image['name']);
            if (isset($saveResult['error'])) {
                http_response_code(500);
                echo json_encode(['error' => $saveResult['error']]);
                exit;
            }

            $imagePath = $saveResult['filename']; // Store only the filename
        }

        try {
            $updateFields = ["name = :name", "shortname = :shortname"];
            $queryParams = ['name' => $name, 'shortname' => $shortname, 'id' => $id];

            if ($imagePath !== null) {
                $updateFields[] = "image = :image";
                $queryParams['image'] = $imagePath;
            }

            $updateQuery = "UPDATE matches SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $stmt = $pdo->prepare($updateQuery);

            if ($stmt->execute($queryParams)) {
                $stmt = $pdo->prepare("SELECT * FROM matches WHERE id = ?");
                $stmt->execute([$id]);
                $updatedMatch = $stmt->fetch();

                // Provide full URL for the image in the response
                if ($updatedMatch['image']) {
                    $updatedMatch['image'] = $baseUrl . $updatedMatch['image'];
                }

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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
?>
