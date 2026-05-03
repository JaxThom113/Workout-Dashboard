<?php

/*
    Database Connection (debug output)
*/

require_once __DIR__ . '/../../../services/Database.php';

$databaseDebug = "";
try 
{
    $conn = Database::connect();
    $databaseDebug .= "AWS RDS connection successful<br>";
    $databaseDebug .= "Database: " . htmlspecialchars($conn->get_charset()->charset) . "<br>";    
    
    // Test query
    $result = $conn->query("SELECT VERSION()");
    if ($result) 
    {
        $row = $result->fetch_row();
        $databaseDebug .= "MySQL Version: " . htmlspecialchars($row[0]) . "<br>";
    }
} 
catch (Exception $e) 
{
    $databaseDebug .= "AWS RDS connection failed: " . htmlspecialchars($e->getMessage());
}

require __DIR__ . '/debug.html.php';
?>
