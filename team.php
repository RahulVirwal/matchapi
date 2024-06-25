<?php

// Database credentials
$host = 'localhost';
$db = 'match_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Set up the database connection
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Function to create the uploads directory if it does not exist
function createUploadsDirectory() {
    $uploadsDir = './uploads';
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
        
        // Debugging: Output the parsed form data
        error_log("Parsed form data: " . json_encode($data));

        if (isset($data['match_name']) && isset($data['team_name']) && isset($data['shortname'])) {
            $stmt = $pdo->prepare('SELECT name FROM matches WHERE name = :name');
            $stmt->execute(['name' => $data['match_name']]);
            $match = $stmt->fetch();

            // Debugging: Output the match result
            error_log("Match result: " . json_encode($match));

            if ($match) {
                $stmt = $pdo->prepare('INSERT INTO manageteam (match_name, team_name, shortname, image) VALUES (:match_name, :team_name, :shortname, :image)');
                if ($stmt->execute(['match_name' => $data['match_name'], 'team_name' => $data['team_name'], 'shortname' => $data['shortname'], 'image' => $data['image']])) {
                    sendJsonResponse(201, 'success', 'Manager team created successfully.');
                } else {
                    sendJsonResponse(500, 'error', 'Failed to create manager team.');
                }
            } else {
                sendJsonResponse(404, 'error', 'Match name not found.');
            }
        } else {
            sendJsonResponse(400, 'error', 'Required data missing.');
        }
        break;

    case 'PUT':
    case 'PATCH':
        // Extract ID from URL
        $urlSegments = explode('/', $_SERVER['REQUEST_URI']);
        $id = intval(end($urlSegments)); // Assuming the ID is the last segment of the URL

        // Update a manager team
        $data = parseFormData();
        
        // Debugging: Output the parsed form data
        error_log("Parsed form data: " . json_encode($data));

        if ($id && isset($data['match_name']) && isset($data['team_name']) && isset($data['shortname'])) {
            $stmt = $pdo->prepare('SELECT name FROM matches WHERE name = :name');
            $stmt->execute(['name' => $data['match_name']]);
            $match = $stmt->fetch();

            // Debugging: Output the match result
            error_log("Match result: " . json_encode($match));

            if ($match) {
                $stmt = $pdo->prepare('UPDATE manageteam SET match_name = :match_name, team_name = :team_name, shortname = :shortname, image = :image WHERE id = :id');
                if ($stmt->execute(['id' => $id, 'match_name' => $data['match_name'], 'team_name' => $data['team_name'], 'shortname' => $data['shortname'], 'image' => $data['image']])) {
                    sendJsonResponse(200, 'success', 'Manager team updated successfully.');
                } else {
                    sendJsonResponse(500, 'error', 'Failed to update manager team.');
                }
            } else {
                sendJsonResponse(404, 'error', 'Match name not found.');
            }
        } else {
            sendJsonResponse(400, 'error', 'Required data missing.');
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
