<div class="training-log-content">
    <h1>Training Log</h1>
    
    <?php if ($db_message): ?>
        <div class="db-message db-message-<?= $db_message_type ?>">
            <?= $db_message ?>
        </div>
    <?php endif; ?>

    <div class="meta">
        <span><?= count($workout_pages) ?> workout<?= count($workout_pages) !== 1 ? 's' : '' ?></span>
        <?php if ($from_cache && $cache_age !== null): ?>
            <span>cached <?= $cache_age ?> minutes ago</span>
        <?php else: ?>
            <span>just loaded from Notion!</span>
        <?php endif; ?>

        <a href="/?page=training-log&refresh=1">🔄 Refresh from Notion</a>
        
        <?php if (GEMINI_API_KEY && GEMINI_MODEL && !empty($workout_pages)): ?>
            <a href="/?page=training-log&process=1">💾 Update Database</a>
        <?php endif; ?>
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
