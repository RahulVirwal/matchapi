<?php

include 'database/database.php';

// Set headers to allow cross-origin resource sharing (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Function to sanitize input data
function sanitizeInput($data)
{
    global $pdo;
    return htmlspecialchars(strip_tags($data));
}

// Function to generate a unique encrypted filename to prevent overwriting
function generateUniqueFilename($uploadDir, $filename)
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $hashedName = md5(uniqid($basename, true)); // Generate a hashed unique name
    return $hashedName . '.' . $extension;
}

// Function to handle POST requests (Create a new player)
function createPlayer()
{
    global $pdo;

    // Check if form data is passed correctly
    if (empty($_POST['team_name']) || empty($_POST['match_name']) || empty($_POST['player_name']) || empty($_POST['player_shortname']) || empty($_FILES['player_image'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Sanitize input data
    $team_name = sanitizeInput($_POST['team_name']);
    $match_name = sanitizeInput($_POST['match_name']);
    $player_name = sanitizeInput($_POST['player_name']);
    $player_shortname = sanitizeInput($_POST['player_shortname']);

    // Process player image upload securely
    $uploadDir = 'uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true); // Create uploads directory if it doesn't exist
    }

    $fileName = $_FILES['player_image']['name'];
    $tempFilePath = $_FILES['player_image']['tmp_name'];
    $fileSize = $_FILES['player_image']['size'];
    $fileType = $_FILES['player_image']['type'];

    // Validate file upload
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($fileType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only JPG, PNG, and GIF files are allowed']);
        exit;
    }

    // Check file size (max 5MB)
    $maxFileSize = 5 * 1024 * 1024; // 5MB in bytes
    if ($fileSize > $maxFileSize) {
        http_response_code(400);
        echo json_encode(['error' => 'File size exceeds maximum allowed (5MB)']);
        exit;
    }

    // Generate a unique encrypted filename to prevent overwriting
    $uniqueFilename = generateUniqueFilename($uploadDir, $fileName);
    $uploadFile = $uploadDir . $uniqueFilename;

    if (!move_uploaded_file($tempFilePath, $uploadFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }

    $player_image = '/api/uploads/' . $uniqueFilename; // Store the path with encrypted filename

    // Check if the team_name exists in manageteam table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM manageteam WHERE team_name = ? AND match_name = ?");
    $stmt->execute([$team_name, $match_name]);
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Team name or match name not found in manageteam table']);
        exit;
    }

    // Insert player into players table
    $sql = "INSERT INTO players (team_name, match_name, player_name, player_shortname, player_image) VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    if ($stmt->execute([$team_name, $match_name, $player_name, $player_shortname, $player_image])) {
        $newPlayerId = $pdo->lastInsertId();
        echo json_encode(['id' => $newPlayerId, 'message' => 'Player created successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create player']);
    }
}

// Function to handle GET requests (Retrieve players)
function getPlayers($id = null)
{
    global $pdo;
    if ($id === null) {
        $sql = "SELECT * FROM players";
        $stmt = $pdo->query($sql);
        $players = $stmt->fetchAll();
        if ($players) {
            echo json_encode($players);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'No players found']);
        }
    } else {
        // Fetch single player by ID
        $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$id]);
        $player = $stmt->fetch();
        if ($player) {
            echo json_encode($player);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
        }
    }
}

// Function to handle PUT requests (Update a player)
function updatePlayer($id)
{
    global $pdo;

    // Check if form data is passed correctly
    if (empty($_POST['team_name']) || empty($_POST['match_name']) || empty($_POST['player_name']) || empty($_POST['player_shortname'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Sanitize input data
    $team_name = sanitizeInput($_POST['team_name']);
    $match_name = sanitizeInput($_POST['match_name']);
    $player_name = sanitizeInput($_POST['player_name']);
    $player_shortname = sanitizeInput($_POST['player_shortname']);

    // Check if the team_name exists in manageteam table
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM manageteam WHERE team_name = ? AND match_name = ?");
    $stmt->execute([$team_name, $match_name]);
    $count = $stmt->fetchColumn();

    if ($count == 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Team name or match name not found in manageteam table']);
        exit;
    }

    // Process player image update if provided
    if (!empty($_FILES['player_image'])) {
        $uploadDir = 'uploads/';

        // Validate and handle file upload
        $fileName = $_FILES['player_image']['name'];
        $tempFilePath = $_FILES['player_image']['tmp_name'];
        $fileSize = $_FILES['player_image']['size'];
        $fileType = $_FILES['player_image']['type'];

        // Validate file upload
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($fileType, $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'Only JPG, PNG, and GIF files are allowed']);
            exit;
        }

        // Check file size (max 5MB)
        $maxFileSize = 5 * 1024 * 1024; // 5MB in bytes
        if ($fileSize > $maxFileSize) {
            http_response_code(400);
            echo json_encode(['error' => 'File size exceeds maximum allowed (5MB)']);
            exit;
        }

        // Generate a unique filename to prevent overwriting
        $uniqueFilename = generateUniqueFilename($uploadDir, $fileName);
        $uploadFile = $uploadDir . $uniqueFilename;

        if (!move_uploaded_file($tempFilePath, $uploadFile)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to move uploaded file']);
            exit;
        }

        $player_image = '/api/uploads/' . $uniqueFilename;

        // Update player in players table with new image path
        $sql = "UPDATE players SET team_name = ?, match_name = ?, player_name = ?, player_shortname = ?, player_image = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$team_name, $match_name, $player_name, $player_shortname, $player_image, $id])) {
            echo json_encode(['message' => 'Player updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update player']);
        }
    } else {
        // Update player in players table without changing the image
        $sql = "UPDATE players SET team_name = ?, match_name = ?, player_name = ?, player_shortname = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        if ($stmt->execute([$team_name, $match_name, $player_name, $player_shortname, $id])) {
            echo json_encode(['message' => 'Player updated successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update player']);
        }
    }
}

// Function to handle DELETE requests (Delete a player)
function deletePlayer($id)
{
    global $pdo;

    // Fetch player image path for deletion
    $stmt = $pdo->prepare("SELECT player_image FROM players WHERE id = ?");
    $stmt->execute([$id]);
    $player = $stmt->fetch();

    if (!$player) {
        http_response_code(404);
        echo json_encode(['error' => 'Player not found']);
        exit;
    }

    $player_image = $player['player_image'];

    // Delete player from players table
    $stmt = $pdo->prepare("DELETE FROM players WHERE id = ?");
    if ($stmt->execute([$id])) {
        // Delete the player image file
        if (file_exists($player_image)) {
            unlink($player_image);
        }
        echo json_encode(['message' => 'Player deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete player']);
    }
}

// Handle incoming HTTP requests
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

switch ($method) {
    case 'GET':
        getPlayers($id);
        break;
    case 'POST':
        createPlayer();
        break;
    case 'PUT':
        if ($id !== null) {
            updatePlayer($id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing player ID']);
        }
        break;
    case 'DELETE':
        if ($id !== null) {
            deletePlayer($id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing player ID']);
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
?>
