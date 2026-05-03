<?php

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
