<?php
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
$pagePath = __DIR__ . '/app/pages/' . $page;
$pagePhpFile = $pagePath . '/' . $page . '.php';
$pageHtmlFile = $pagePath . '/' . $page . '.html.php';

// check if files exist
if (!file_exists($pagePhpFile) || !file_exists($pageHtmlFile)) 
{
    $page = 'dashboard';
    $pagePath = __DIR__ . '/app/pages/dashboard';
    $pagePhpFile = $pagePath . '/dashboard.php';
    $pageHtmlFile = $pagePath . '/dashboard.html.php';
}

// Include page logic first
require 'main.html.php';
?>
