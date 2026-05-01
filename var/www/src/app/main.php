<?php

/*
    Database
*/
require __DIR__ . '/../services/Database.php';

/*
    Cache & Headers
*/

// Handle cache refresh before ANY output (must come before headers)
if (isset($_GET['refresh'])) 
{
    define('CACHE_FILE', __DIR__ . '/../../database/cache/cache.json');
    if (file_exists(CACHE_FILE)) 
        unlink(CACHE_FILE);
    
    $page = $_GET['page'] ?? 'dashboard';
    header('Location: /?page=' . urlencode($page));
    exit;
}

/*
    Database Connection (debug output)
*/

try 
{
    $conn = Database::connect();
    echo "✓ Successfully connected to AWS RDS!<br>";
    echo "Database: " . htmlspecialchars($conn->get_charset()->charset) . "<br>";
    
    // Test query
    $result = $conn->query("SELECT VERSION()");
    if ($result) 
    {
        $row = $result->fetch_row();
        echo "MySQL Version: " . htmlspecialchars($row[0]) . "<br>";
    }
} 
catch (Exception $e) 
{
    echo "✗ Connection failed: " . htmlspecialchars($e->getMessage());
}

/*
    Routing
*/

// get the requested page from URL parameter, default to dashboard
$page = $_GET['page'] ?? 'dashboard';

// list of allowed (existing) pages
$allowedPages = [
    'dashboard', 
    'training-log',
    'about'
];

// if selected page is not allowed, default to dashboard
if (!in_array($page, $allowedPages))
    $page = 'dashboard';

// build the path to the page files
$pagePhpFile = __DIR__ . '/pages/' . $page . '/' . $page . '.php';
$pageHtmlFile = __DIR__ . '/pages/' . $page . '/' . $page . '.html.php';

// if files don't exist, default to dashboard
if (!file_exists($pagePhpFile) || !file_exists($pageHtmlFile)) 
{
    $page = 'dashboard';
    $pagePhpFile = __DIR__ . '/pages/dashboard/dashboard.php';
    $pageHtmlFile = __DIR__ . '/pages/dashboard/dashboard.html.php';
}

// Include page logic first
require __DIR__ . '/main.html.php';
?>
