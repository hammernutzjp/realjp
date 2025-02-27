<?php
// Database connection parameters
$servername = "sql108.infinityfree.com";
$username = "if0_38046755";
$password = "psuAgIge8JV48FY";
$dbname = "if0_38046755_realprop";


// File path handling
$jsonFile = "kenbiya_tokyo_data.json";
$scriptDir = __DIR__; // Get the directory of the current script
$fullPath = $scriptDir . '/' . $jsonFile;

echo "Looking for JSON file at: " . $fullPath . "<br>";

// Check if file exists and is readable
if (!file_exists($fullPath)) {
    die("Error: JSON file not found at path: " . $fullPath . "<br>
         Current directory: " . getcwd() . "<br>
         Please ensure the file exists and has correct permissions.");
}

if (!is_readable($fullPath)) {
    die("Error: JSON file exists but is not readable. Please check file permissions.");
}


// Create database connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Select the database
$conn->select_db($dbname);

// Read JSON file
$jsonData = file_get_contents($fullPath);
if ($jsonData === false) {
    die("Error: Could not read the contents of the JSON file. Check file permissions.");
}

$jsonData = file_get_contents($jsonFile);
$data = json_decode($jsonData, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error parsing JSON: " . json_last_error_msg());
}

// Validate data structure
if (empty($data) || !is_array($data)) {
    die("Error: Invalid JSON structure or empty data.");
}

// Get the first record to determine table structure
$firstRecord = reset($data);
if (!is_array($firstRecord)) {
    // If the JSON is not an array of objects but a single object
    $firstRecord = $data;
    $data = [$data]; // Convert to array for consistent processing
}

// Create table based on JSON structure
$tableName = "kenbiya_tokyo_data";
$columns = [];

foreach ($firstRecord as $key => $value) {
    // Determine MySQL data type based on JSON value type
    $type = getColumnType($value);
    $columns[] = "`" . $key . "` " . $type;
}

// Add an auto-increment primary key
$sql = "CREATE TABLE IF NOT EXISTS `$tableName` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    " . implode(",\n    ", $columns) . "
)";

if ($conn->query($sql) !== TRUE) {
    die("Error creating table: " . $conn->error);
}

echo "Table created successfully.<br>";

// Insert data into the table
$insertCount = 0;
$errorCount = 0;

foreach ($data as $record) {
    $columns = array_keys($record);
    $escapedColumns = array_map(function($col) {
        return "`" . $col . "`";
    }, $columns);
    
    $placeholders = array_fill(0, count($columns), "?");
    
    $sql = "INSERT INTO `$tableName` (" . implode(", ", $escapedColumns) . ") 
            VALUES (" . implode(", ", $placeholders) . ")";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        // Create binding parameters
        $types = '';
        $bindParams = [];
        
        foreach ($record as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } elseif (is_bool($value)) {
                $types .= 'i';
                $value = $value ? 1 : 0;
            } else {
                $types .= 's';
                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }
            }
            $bindParams[] = $value;
        }
        
        // Create the bind_param arguments
        $bindArgs = array_merge([$types], $bindParams);
        
        // Use reflection to call bind_param with dynamic arguments
        $refStmt = new ReflectionClass($stmt);
        $refMethod = $refStmt->getMethod('bind_param');
        $refMethod->invokeArgs($stmt, refValues($bindArgs));
        
        if ($stmt->execute()) {
            $insertCount++;
        } else {
            $errorCount++;
            echo "Error inserting record: " . $stmt->error . "<br>";
        }
        
        $stmt->close();
    } else {
        $errorCount++;
        echo "Error preparing statement: " . $conn->error . "<br>";
    }
}

echo "Import completed. Records inserted: $insertCount. Errors: $errorCount.<br>";

// Close connection
$conn->close();

// Helper function to determine column type
function getColumnType($value) {
    if (is_int($value)) {
        return "INT";
    } elseif (is_float($value)) {
        return "DOUBLE";
    } elseif (is_bool($value)) {
        return "TINYINT(1)";
    } elseif (is_array($value) || is_object($value)) {
        return "JSON";
    } elseif (is_string($value)) {
        // Check if it's a date
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) && strtotime($value) !== false) {
            return "DATE";
        }
        // Check if it's a datetime
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) && strtotime($value) !== false) {
            return "DATETIME";
        }
        
        // For strings, use VARCHAR for short strings, TEXT for longer ones
        $len = strlen($value);
        if ($len <= 255) {
            return "VARCHAR($len)";
        } else {
            return "TEXT";
        }
    } else {
        return "TEXT";
    }
}

// Helper function for bind_param with references
function refValues($arr) {
    if (strnatcmp(phpversion(), '5.3') >= 0) {
        $refs = [];
        foreach ($arr as $key => $value) {
            $refs[$key] = &$arr[$key];
        }
        return $refs;
    }
    return $arr;
}
?>