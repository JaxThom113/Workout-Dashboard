<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jaxon's Workout Dashboard</title>
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/<?= htmlspecialchars($page) ?>.css">
</head>
<body>
    <main>
        <?php require __DIR__ . '/app/components/navbar/navbar.php'; ?>

        <?php require $pagePhpFile; ?>

        <?php require __DIR__ . '/app/components/footer/footer.php'; ?>
    </main>
</body>
</html>