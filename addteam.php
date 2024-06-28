<?php

include 'database/database.php';

// Set headers for CORS and content type
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

$baseUrl = '/api/uploads/';
$uploadsDir = __DIR__ . '/uploads/';

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Function to create the uploads directory if it does not exist
function createUploadsDirectory() {
    global $uploadsDir;
    if (!is_dir($uploadsDir)) {
        if (!mkdir($uploadsDir, 0755, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to create uploads directory.']);
            exit;
        }
    }
}

// Function to parse form-data in a request (including files)
function parseFormData() {
    $data = [];
    $data['match_name'] = $_POST['match_name'] ?? '';
    $data['team_name'] = $_POST['team_name'] ?? '';
    $data['shortname'] = $_POST['shortname'] ?? '';
    // Check if file was uploaded
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        createUploadsDirectory(); // Ensure uploads directory exists
        $fileTmpPath = $_FILES['image']['tmp_name'];
        $fileName = $_FILES['image']['name'];
        $fileSize = $_FILES['image']['size'];
        $fileType = $_FILES['image']['type'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        // Generate unique filename using MD5 hash
        $uniqueFileName = md5(uniqid()) . '.' . $fileExtension;
        $uploadFileDir = './uploads/';
        $destFilePath = $uploadFileDir . $uniqueFileName;

        // Move the uploaded file to a permanent location
        if (move_uploaded_file($fileTmpPath, $destFilePath)) {
            $data['image'] = $uniqueFileName; // Store filename in data
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
            exit;
        }
    }

    return $data;
}

// Function to send JSON response
function sendJsonResponse($status, $message, $data = null) {
    header('Content-Type: application/json');
    http_response_code($status);

    // If data includes an image field, prepend /api/uploads/ to the image path
    if ($data && isset($data['image'])) {
        $data['image'] = '/api/uploads/' . $data['image'];
    }

    echo json_encode(['status' => $message, 'data' => $data]);
}

// Helper functions for file validation and saving
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

// Function to parse form-data for PUT/PATCH requests
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

// Function to check if match_name exists in the matches table
function matchExists($match_name, $pdo) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE name = :name');
    $stmt->execute(['name' => $match_name]);
    return $stmt->fetchColumn() > 0;
}

// Handle CRUD operations
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Read manager team(s)
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $stmt = $pdo->prepare('SELECT * FROM manageteam WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $team = $stmt->fetch();
            if ($team) {
                sendJsonResponse(200, 'success', $team);
            } else {
                sendJsonResponse(404, 'error', 'Manager team not found.');
            }
        } else {
            $stmt = $pdo->query('SELECT * FROM manageteam');
            $teams = $stmt->fetchAll();
            foreach ($teams as &$team) {
                if (isset($team['image'])) {
                    $team['image'] = '/api/uploads/' . $team['image'];
                }
            }
            sendJsonResponse(200, 'success', $teams);
        }
        break;

    case 'POST':
        // Create a new manager team
        $data = parseFormData();

        if (isset($data['match_name']) && isset($data['team_name']) && isset($data['shortname'])) {
            if (!matchExists($data['match_name'], $pdo)) {
                sendJsonResponse(404, 'error', 'Match name not found.');
                exit;
            }

            $stmt = $pdo->prepare('INSERT INTO manageteam (match_name, team_name, shortname, image) VALUES (:match_name, :team_name, :shortname, :image)');
            if ($stmt->execute(['match_name' => $data['match_name'], 'team_name' => $data['team_name'], 'shortname' => $data['shortname'], 'image' => $data['image']])) {
                sendJsonResponse(201, 'success', 'Manager team created successfully.');
            } else {
                sendJsonResponse(500, 'error', 'Failed to create manager team.');
            }
        } else {
            sendJsonResponse(400, 'error', 'Required data missing.');
        }
        break;

    case 'PUT':
    case 'PATCH':
        // Update a manager team
        $data = parseFormData1();
        $id_from_url = isset($_GET['id']) ? (int)$_GET['id'] : null;

        if ($id_from_url && isset($data['team_name']) && isset($data['match_name']) && isset($data['shortname'])) {
            if (!matchExists($data['match_name'], $pdo)) {
                sendJsonResponse(404, 'error', 'Match name not found.');
                exit;
            }

            $team_name = $data['team_name'];
            $match_name = $data['match_name'];
            $shortname = $data['shortname'];
            $id = $id_from_url;

            $imagePath = null;
            if (isset($data['image'])) {
                $file = $data['image'];
                $validationResult = validateImage($file);
                if ($validationResult !== true) {
                    http_response_code(400);
                    echo json_encode(['error' => $validationResult]);
                    exit;
                }
                $saveResult = saveImage($file['content'], $file['name']);
                if (isset($saveResult['error'])) {
                    http_response_code(500);
                    echo json_encode(['error' => $saveResult['error']]);
                    exit;
                }

                $imagePath = $saveResult['filename'];
            }

            try {
                $updateFields = ["team_name = :team_name", "match_name = :match_name", "shortname = :shortname"];
                $queryParams = ['team_name' => $team_name, 'match_name' => $match_name, 'shortname' => $shortname, 'id' => $id];

                if ($imagePath !== null) {
                    $updateFields[] = "image = :image";
                    $queryParams['image'] = $imagePath;
                }

                $updateQuery = "UPDATE manageteam SET " . implode(', ', $updateFields) . " WHERE id = :id";
                $stmt = $pdo->prepare($updateQuery);

                if ($stmt->execute($queryParams)) {
                    $stmt = $pdo->prepare("SELECT * FROM manageteam WHERE id = ?");
                    $stmt->execute([$id]);
                    $updatedTeam = $stmt->fetch();

                    // Provide full URL for the image in the response
                    if ($updatedTeam['image']) {
                        $updatedTeam['image'] = $baseUrl . $updatedTeam['image'];
                    }

                    echo json_encode(['success' => true, 'data' => $updatedTeam]);
                } else {
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to update team']);
                }
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
        } else {
            http_response_code(400);
            $errors = [];
            if ($id_from_url === null) {
                $errors[] = "Missing or invalid team ID.";
            }
            if (!isset($data['team_name'])) {
                $errors[] = "Missing 'team_name' field in request body.";
            }
            if (!isset($data['match_name'])) {
                $errors[] = "Missing 'match_name' field in request body.";
            }
            if (!isset($data['shortname'])) {
                $errors[] = "Missing 'shortname' field in request body.";
            }
            echo json_encode(['error' => implode(". ", $errors)]);
        }
        break;

    case 'DELETE':
        // Extract ID from URL
        $urlSegments = explode('/', $_SERVER['REQUEST_URI']);
        $id = intval(end($urlSegments)); // Assuming the ID is the last segment of the URL

        // Delete a manager team
        if ($id) {
            $stmt = $pdo->prepare('DELETE FROM manageteam WHERE id = :id');
            if ($stmt->execute(['id' => $id])) {
                sendJsonResponse(200, 'success', 'Manager team deleted successfully.');
            } else {
                sendJsonResponse(500, 'error', 'Failed to delete manager team.');
            }
        } else {
            sendJsonResponse(400, 'error', 'ID is required to delete.');
        }
        break;

    default:
        sendJsonResponse(405, 'error', 'Method Not Allowed');
        break;
}

?>
