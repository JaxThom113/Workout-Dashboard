<link rel="stylesheet" href="/assets/css/navbar.css">

<nav class="nav-bar">
    <div class="nav-header">
        <img src="/assets/images/notion_gym_icon.png" width=35 />
        <h2>Jaxon's Workout Dashboard</h2>
    </div>
    
    <div class="nav-links">
        <a href="/?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="/?page=training-log" class="<?= $page === 'training-log' ? 'active' : '' ?>">Training Log</a>
        <a href="/?page=about" class="<?= $page === 'about' ? 'active' : '' ?>">About</a>
    </div>
</nav>