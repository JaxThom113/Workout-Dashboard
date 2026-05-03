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
    <p class="no-entries">No workout entries found or Notion API connection failed.</p>
<?php else: ?>
    <div class="training-log-bootstrap-accordions px-3 pb-4">
        <?php
            $tl_accordion_root_id = 'trainingLogYears';
            $tl_year_order = [2024, 2025, 2026];
            $tl_nested = $workouts_nested;
        ?>
        <div class="accordion training-log-accordion-root" id="<?= htmlspecialchars($tl_accordion_root_id, ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($tl_year_order as $year): ?>
                <?php
                    $months = $tl_nested[$year] ?? [];
                    $year_total = 0;
                    foreach ($months as $plist)
                        $year_total += count($plist);

                    $collapse_year_id = 'tl-collapse-y-' . $year;
                    $heading_year_id = 'tl-heading-y-' . $year;
                    $months_accordion_id = 'tl-months-' . $year;
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header" id="<?= htmlspecialchars($heading_year_id, ENT_QUOTES, 'UTF-8') ?>">
                        <button
                            class="accordion-button collapsed"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= htmlspecialchars($collapse_year_id, ENT_QUOTES, 'UTF-8') ?>"
                            aria-expanded="false"
                            aria-controls="<?= htmlspecialchars($collapse_year_id, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?= (int) $year ?> (<?= (int) $year_total ?> workout<?= $year_total !== 1 ? 's' : '' ?>)
                        </button>
                    </h2>
                    <div
                        id="<?= htmlspecialchars($collapse_year_id, ENT_QUOTES, 'UTF-8') ?>"
                        class="accordion-collapse collapse"
                        aria-labelledby="<?= htmlspecialchars($heading_year_id, ENT_QUOTES, 'UTF-8') ?>"
                        data-bs-parent="#<?= htmlspecialchars($tl_accordion_root_id, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <div class="accordion-body">
                            <?php if (empty($months)): ?>
                                <p class="training-log-empty-branch mb-0">No workout entries for <?= (int) $year ?>.</p>
                            <?php else: ?>
                                <div class="accordion training-log-accordion-nested" id="<?= htmlspecialchars($months_accordion_id, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($months as $month => $pages): ?>
                                        <?php
                                            $month_pad = sprintf('%02d', (int) $month);
                                            $collapse_month_id = 'tl-collapse-ym-' . $year . '-' . $month_pad;
                                            $heading_month_id = 'tl-heading-ym-' . $year . '-' . $month_pad;
                                            $workouts_accordion_id = 'tl-workouts-' . $year . '-' . $month_pad;
                                            $month_label = date('F', mktime(0, 0, 0, (int) $month, 1, (int) $year));
                                            $month_count = count($pages);
                                        ?>
                                        <div class="accordion-item">
                                            <h3 class="accordion-header" id="<?= htmlspecialchars($heading_month_id, ENT_QUOTES, 'UTF-8') ?>">
                                                <button
                                                    class="accordion-button collapsed"
                                                    type="button"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#<?= htmlspecialchars($collapse_month_id, ENT_QUOTES, 'UTF-8') ?>"
                                                    aria-expanded="false"
                                                    aria-controls="<?= htmlspecialchars($collapse_month_id, ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                                    <?= htmlspecialchars($month_label) ?> (<?= (int) $month_count ?>)
                                                </button>
                                            </h3>
                                            <div
                                                id="<?= htmlspecialchars($collapse_month_id, ENT_QUOTES, 'UTF-8') ?>"
                                                class="accordion-collapse collapse"
                                                aria-labelledby="<?= htmlspecialchars($heading_month_id, ENT_QUOTES, 'UTF-8') ?>"
                                                data-bs-parent="#<?= htmlspecialchars($months_accordion_id, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <div class="accordion-body">
                                                    <div class="accordion training-log-accordion-nested" id="<?= htmlspecialchars($workouts_accordion_id, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?php foreach ($pages as $wi => $page): ?>
                                                            <?php
                                                                $collapse_work_id = 'tl-collapse-w-' . $year . '-' . $month_pad . '-' . (int) $wi;
                                                                $heading_work_id = 'tl-heading-w-' . $year . '-' . $month_pad . '-' . (int) $wi;
                                                            ?>
                                                            <div class="accordion-item">
                                                                <h4 class="accordion-header" id="<?= htmlspecialchars($heading_work_id, ENT_QUOTES, 'UTF-8') ?>">
                                                                    <button
                                                                        class="accordion-button collapsed"
                                                                        type="button"
                                                                        data-bs-toggle="collapse"
                                                                        data-bs-target="#<?= htmlspecialchars($collapse_work_id, ENT_QUOTES, 'UTF-8') ?>"
                                                                        aria-expanded="false"
                                                                        aria-controls="<?= htmlspecialchars($collapse_work_id, ENT_QUOTES, 'UTF-8') ?>"
                                                                    >
                                                                        <?= htmlspecialchars($page['title'] ?? '') ?>
                                                                    </button>
                                                                </h4>
                                                                <div
                                                                    id="<?= htmlspecialchars($collapse_work_id, ENT_QUOTES, 'UTF-8') ?>"
                                                                    class="accordion-collapse collapse"
                                                                    aria-labelledby="<?= htmlspecialchars($heading_work_id, ENT_QUOTES, 'UTF-8') ?>"
                                                                    data-bs-parent="#<?= htmlspecialchars($workouts_accordion_id, ENT_QUOTES, 'UTF-8') ?>"
                                                                >
                                                                    <div class="accordion-body workout-entry workout-entry-panel">
                                                                        <?= $page['content'] ?? '' ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
