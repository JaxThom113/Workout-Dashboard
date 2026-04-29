<?php

class Database 
{
    private static $connection = null;

    public static function connect() 
    {
        if (self::$connection !== null)
            return self::$connection;

        // load environment variables for AWS
        $env = parse_ini_file(__DIR__ . '/../../.env');
        $host = $env['DB_HOST'];
        $port = $env['DB_PORT'];
        $user = $env['DB_USER'];
        $password = $env['DB_PASSWORD'];
        $database = $env['DB_NAME'];

        // test connection
        try 
        {
            // call MySQL to set up a connection
            self::$connection = new mysqli($host, $user, $password, $database, (int)$port);

            if (self::$connection->connect_error)
                die('Connection failed: ' . self::$connection->connect_error);

            self::$connection->set_charset('utf8mb4');
            return self::$connection;
        } 
        catch (Exception $e) 
        {
            die('Database Error: ' . $e->getMessage());
        }
    }

    public static function getConnection() 
    {
        return self::connect();
    }

    public static function query($sql) 
    {
        $conn = self::connect();
        return $conn->query($sql);
    }

    public static function prepare($sql) 
    {
        $conn = self::connect();
        return $conn->prepare($sql);
    }
}
?>