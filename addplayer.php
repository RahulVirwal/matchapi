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

            if ($id_from_url !== null && isset($data['team_name']) && isset($data['match_name']) && isset($data['player_name']) && isset($data['player_shortname'])) {
                $id = $id_from_url;
                $team_name = htmlspecialchars($data['team_name']);
                $match_name = htmlspecialchars($data['match_name']);
                $player_name = htmlspecialchars($data['player_name']);
                $player_shortname = htmlspecialchars($data['player_shortname']);
                $player_image = isset($data['player_image']) ? $data['player_image'] : null;

                $imagePath = null; // Initialize image path variable

                if ($player_image) {
                    $validationResult = validateImage1($player_image);
                    if ($validationResult !== true) {
                        http_response_code(400);
                        echo json_encode(['error' => $validationResult]);
                        exit;
                    }

                    $saveResult = saveImage1($player_image['content'], $player_image['name']);
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
