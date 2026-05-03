<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jaxon's Workout Dashboard</title>
    <link rel="icon" type="image/x-icon" href="/assets/images/favicon.ico">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Main CSS & page-specific CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/<?= htmlspecialchars($page) ?>.css">
</head>
<body>
    <main>
        <?php require __DIR__ . '/components/debug/debug.php'; ?>

        <?php require __DIR__ . '/components/navbar/navbar.php'; ?>

        <?php require $pagePhpFile; ?>

        <?php require __DIR__ . '/components/footer/footer.php'; ?>
    </main>
</body>
</html>