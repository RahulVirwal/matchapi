<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, PATCH, OPTIONS, GET, POST, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

include 'database/database.php';

$baseUrl = '/api/uploads/';



// Helper function to get input data
function getInputData()
{
    if (isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] === 'application/json') {
        return json_decode(file_get_contents('php://input'), true);
    } else {
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

    if (!isset($file['type']) || !in_array($file['type'], $allowedTypes)) {
        return "Invalid file type. Only JPG, PNG, and GIF types are accepted.";
    }
    if (!isset($file['size']) || $file['size'] > $maxFileSize) {
        return "File size exceeds the maximum limit of 2MB.";
    }
    return true;
}

// Save image with a unique filename using md5
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
        return $encryptedFilename;
    }
    return false;
}

// Extract the ID from the URL
function getIdFromUrl()
{
    $urlPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $urlSegments = explode('/', trim($urlPath, '/'));
    $id = isset($urlSegments[2]) ? (int)$urlSegments[2] : null;
    return $id;
}
//

$baseUrl = '/api/uploads/';
$uploadsDir = __DIR__ . '/uploads/';



function validateImage1($file)
{
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $fileType = $finfo->buffer($file['content']);

    if (!in_array($fileType, $allowedTypes)) {
        return "Invalid file type. Only JPG, PNG, and GIF types are accepted.";
    }
    return true;
}

function saveImage1($content, $filename)
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

function parseFormData1()
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
    $data = parseFormData1();
    $id_from_url = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id_from_url !== null && isset($data['name']) && isset($data['shortname'])) {
        $id = $id_from_url;
        $name = htmlspecialchars($data['name']);
        $shortname = htmlspecialchars($data['shortname']);
        $image = isset($data['image']) ? $data['image'] : null;

        $imagePath = null; // Initialize image path variable

        if ($image) {
            $validationResult = validateImage1($image);
            if ($validationResult !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validationResult]);
                exit;
            }

            $saveResult = saveImage1($image['content'], $image['name']);
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



// GET request to fetch all matches or a specific match by ID
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = getIdFromUrl();
    if ($id !== null) {
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
        $stmt = $pdo->query("SELECT * FROM matches");
        $matches = $stmt->fetchAll();
        $matches = addBaseUrlToImagePath($matches, $baseUrl);
        echo json_encode(['matches' => $matches]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['name']) && !empty($_POST['shortname']) && !empty($_FILES['image']['name'])) {
        $name = htmlspecialchars($_POST['name']);
        $shortname = htmlspecialchars($_POST['shortname']);

        $validationResult = validateImage($_FILES['image']);
        if ($validationResult === true) {
            $uniqueFileName = saveImage($_FILES['image']['tmp_name'], $_FILES['image']['name']);
            if ($uniqueFileName) {
                $imagePath = $uniqueFileName;
                $stmt = $pdo->prepare("INSERT INTO matches (name, shortname, image) VALUES (?, ?, ?)");
                if ($stmt->execute([$name, $shortname, $imagePath])) {
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
    $id = getIdFromUrl();
    if ($id) {
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
    $data = parseFormData1();
    $id_from_url = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($id_from_url !== null && isset($data['name']) && isset($data['shortname'])) {
        $id = $id_from_url;
        $name = htmlspecialchars($data['name']);
        $shortname = htmlspecialchars($data['shortname']);
        $image = isset($data['image']) ? $data['image'] : null;

        $imagePath = null; // Initialize image path variable

        if ($image) {
            $validationResult = validateImage1($image);
            if ($validationResult !== true) {
                http_response_code(400);
                echo json_encode(['error' => $validationResult]);
                exit;
            }

            $saveResult = saveImage1($image['content'], $image['name']);
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
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse multipart form data
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

