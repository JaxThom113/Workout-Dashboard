<div class="training-log-content">
    <h1>Training Log</h1>
    <div class="meta">
        <span><?= count($workout_pages) ?> workout<?= count($workout_pages) !== 1 ? 's' : '' ?></span>
        <?php if ($from_cache && $cache_age !== null): ?>
            <span>cached <?= $cache_age ?> minutes ago</span>
        <?php else: ?>
            <span>just loaded from Notion!</span>
        <?php endif; ?>
        <a href="/?page=dashboard&refresh=1">🔄 Refresh from Notion</a>
    </div>
</div>

<?php if (empty($workout_pages)): ?>
    <p class="no-entries">No workout entries found. Make sure your integration has access to the Training page.</p>
<?php else: ?>
    <?php foreach ($workout_pages as $i => $page): ?>
        <div class="workout-entry">
            <h2><?= htmlspecialchars($page['title']) ?></h2>
            <?= $page['content'] ?>
        </div>
        <?php if ($i < count($workout_pages) - 1): ?>
            <hr class="separator">
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
