<?php

$host = 'localhost';
$db = 'match_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Set up the database connection with MySQLi
$mysqli = new mysqli($host, $user, $pass, $db);

// Check connection
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $mysqli->connect_error]);
    exit;
}

// Set headers to allow cross-origin resource sharing (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Function to sanitize input data
function sanitizeInput($data)
{
    global $mysqli;
    return htmlspecialchars(strip_tags($mysqli->real_escape_string($data)));
}

// Function to generate a unique filename to prevent overwriting
function generateUniqueFilename($uploadDir, $filename)
{
    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $counter = 1;
    while (file_exists($uploadDir . $filename)) {
        $filename = $basename . '_' . $counter . '.' . $extension;
        $counter++;
    }
    return $filename;
}

// Function to handle POST requests (Create a new player)
function createPlayer()
{
    global $mysqli;

    // Check if form data is passed correctly
    if (empty($_POST['team_name']) || empty($_POST['player_name']) || empty($_POST['player_shortname']) || empty($_FILES['player_image'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Sanitize input data
    $team_name = sanitizeInput($_POST['team_name']);
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

    $uploadFile = $uploadDir . basename($fileName);

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

    $player_image = $uploadFile;

    // Check if the team_name exists in manageteam table
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM manageteam WHERE team_name = ?");
    $stmt->bind_param("s", $team_name);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Team name not found in manageteam table']);
        exit;
    }

    // Insert player into players table
    $sql = "INSERT INTO players (team_name, player_name, player_shortname, player_image) VALUES (?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ssss", $team_name, $player_name, $player_shortname, $player_image);

    if ($stmt->execute()) {
        $newPlayerId = $mysqli->insert_id;
        echo json_encode(['id' => $newPlayerId, 'message' => 'Player created successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create player: ' . $mysqli->error]);
    }
}

// Function to handle GET requests (Retrieve players)
function getPlayers($id = null)
{
    global $mysqli;
    if ($id === null) {
        $sql = "SELECT * FROM players";
        $result = $mysqli->query($sql);

        if ($result->num_rows > 0) {
            $players = [];
            while ($row = $result->fetch_assoc()) {
                $players[] = $row;
            }
            echo json_encode($players);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'No players found']);
        }
    } else {
        // Fetch single player by ID
        $stmt = $mysqli->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $player = $result->fetch_assoc();
            echo json_encode($player);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Player not found']);
        }
        $stmt->close();
    }
}

// Function to handle PUT/PATCH requests (Update a player)
// Function to handle PUT requests (Update a player)
function updatePlayer($id)
{
    global $mysqli;

    // Check if form data is passed correctly (form-data in POST)
    if (empty($_POST['team_name']) || empty($_POST['player_name']) || empty($_POST['player_shortname'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Sanitize input data
    $team_name = sanitizeInput($_POST['team_name']);
    $player_name = sanitizeInput($_POST['player_name']);
    $player_shortname = sanitizeInput($_POST['player_shortname']);

    // Check if the team_name exists in manageteam table
    $stmt = $mysqli->prepare("SELECT COUNT(*) FROM manageteam WHERE team_name = ?");
    $stmt->bind_param("s", $team_name);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Team name not found in manageteam table']);
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

        $uploadFile = $uploadDir . basename($fileName);

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

        $player_image = $uploadFile;

        // Update player in players table with new image path
        $sql = "UPDATE players SET team_name = ?, player_name = ?, player_shortname = ?, player_image = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssssi", $team_name, $player_name, $player_shortname, $player_image, $id);
    } else {
        // Update player in players table without updating image
        $sql = "UPDATE players SET team_name = ?, player_name = ?, player_shortname = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("sssi", $team_name, $player_name, $player_shortname, $id);
    }

    // Execute update query
    if ($stmt->execute()) {
        echo json_encode(['message' => 'Player updated successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update player: ' . $mysqli->error]);
    }
}


// Function to handle DELETE requests (Delete a player)
function deletePlayer($id)
{
    global $mysqli;

    // Delete player from players table
    $sql = "DELETE FROM players WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(['message' => 'Player deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete player: ' . $mysqli->error]);
    }
}

// Routing logic based on HTTP method and URL parameters
$method = $_SERVER['REQUEST_METHOD'];
$request = rtrim($_SERVER['REQUEST_URI'], '/');

// Extract player ID from URL if present
$urlParts = explode('/', $request);
$id = end($urlParts);

switch ($method) {
    case 'GET':
        if ($id === 'addplayer' || $id === '') {
            getPlayers(); // Fetch all players
        } else {
            getPlayers($id); // Fetch player by ID
        }
        break;
    case 'POST':
        createPlayer();
        break;
    case 'PUT':
    case 'PATCH':
        updatePlayer($id);
        break;
    case 'DELETE':
        deletePlayer($id);
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
}
