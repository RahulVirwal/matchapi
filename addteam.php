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
    parse_str(file_get_contents('php://input'), $parsedInput);

    $data['match_name'] = $parsedInput['match_name'] ?? '';
    $data['team_name'] = $parsedInput['team_name'] ?? '';
    $data['shortname'] = $parsedInput['shortname'] ?? '';

    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        createUploadsDirectory(); 
        $fileTmpPath = $_FILES['image']['tmp_name'];
        $fileName = $_FILES['image']['name'];
        $fileSize = $_FILES['image']['size'];
        $fileType = $_FILES['image']['type'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        
        $uniqueFileName = md5(uniqid()) . '.' . $fileExtension;
        $uploadFileDir = './uploads/';
        $destFilePath = $uploadFileDir . $uniqueFileName;

        
        if (move_uploaded_file($fileTmpPath, $destFilePath)) {
            $data['image'] = $uniqueFileName; 
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to move uploaded file.']);
            exit;
        }
    }

    return $data;
}


function sendJsonResponse($status, $message, $data = null) {
    header('Content-Type: application/json');
    http_response_code($status);

    
    if ($data && isset($data['image'])) {
        $data['image'] = '/api/uploads/' . $data['image'];
    }

    echo json_encode(['status' => $message, 'data' => $data]);
    exit;
}


function getIdFromRequest() {
    $urlSegments = explode('/', $_SERVER['REQUEST_URI']);
    foreach ($urlSegments as $segment) {
        if (is_numeric($segment)) {
            return intval($segment);
        }
    }
    return null;
}


function validateFormData($data) {
    $errors = [];

    if (empty($data['match_name'])) {
        $errors[] = 'Match name is required.';
    } else {
        
        $stmt = $GLOBALS['pdo']->prepare('SELECT name FROM matches WHERE name = :name');
        $stmt->execute(['name' => $data['match_name']]);
        $match = $stmt->fetch();

        if (!$match) {
            $errors[] = 'Match name does not exist.';
        }
    }

    if (empty($data['team_name'])) {
        $errors[] = 'Team name is required.';
    }

    if (empty($data['shortname'])) {
        $errors[] = 'Shortname is required.';
    }

    return $errors;
}


switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':

        $id = getIdFromRequest();
        if ($id) {
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
        $validationErrors = validateFormData($data);

        if (empty($validationErrors)) {
            $stmt = $pdo->prepare('SELECT name FROM matches WHERE name = :name');
            $stmt->execute(['name' => $data['match_name']]);
            $match = $stmt->fetch();

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
            sendJsonResponse(400, 'error', $validationErrors);
        }
        break;

    case 'PUT':
        $id = getIdFromRequest();
        
        $putData = parseFormData();
        $validationErrors = validateFormData($putData);

        if (empty($validationErrors)) {
            $stmt = $pdo->prepare('UPDATE manageteam SET match_name = :match_name, team_name = :team_name, shortname = :shortname, image = :image WHERE id = :id');
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':match_name', $putData['match_name'], PDO::PARAM_STR);
            $stmt->bindParam(':team_name', $putData['team_name'], PDO::PARAM_STR);
            $stmt->bindParam(':shortname', $putData['shortname'], PDO::PARAM_STR);
            $stmt->bindParam(':image', $putData['image'], PDO::PARAM_STR);

            try {
                if ($stmt->execute()) {
                    sendJsonResponse(200, 'success', 'Manager team updated successfully.');
                } else {
                    sendJsonResponse(500, 'error', 'Failed to update manager team.');
                }
            } catch (\PDOException $e) {
                sendJsonResponse(500, 'error', 'Database error: ' . $e->getMessage());
            }
        } else {
            sendJsonResponse(400, 'error', $validationErrors);
        }
        break;

    case 'DELETE':
        $id = getIdFromRequest();

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
