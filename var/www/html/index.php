<?php
/**
 * Notion Training Log Viewer
 *
 * Recursively walks your Training > Training Log > Year pages
 * and prints all individual workout day entries.
 *
 * SETUP:
 *   1. Go to https://www.notion.so/my-integrations and create an integration.
 *   2. Copy the "Internal Integration Secret" into /var/www/.env as NOTION_TOKEN.
 *   3. Open your "Training" page in Notion. The page ID is in the URL:
 *      https://notion.so/Your-Page-Title-{PAGE_ID}
 *      Copy the ID and paste it into /var/www/.env as TRAINING_PAGE_ID.
 *   4. Share your "Training" page with your integration:
 *      Open the page → top-right "..." menu → Connections → add your integration.
 *      (You only need to share the top-level page; sub-pages inherit access.)
 *   5. Create the cache file with correct permissions:
 *      sudo touch /var/www/cache.json
 *      sudo chown www-data:www-data /var/www/cache.json
 */

// ─── Load .env ────────────────────────────────────────────────────────────────

$env = parse_ini_file(__DIR__ . '/../.env');
define('NOTION_TOKEN',     $env['NOTION_TOKEN']     ?? '');
define('TRAINING_PAGE_ID', $env['TRAINING_PAGE_ID'] ?? '');

// ─── Cache ────────────────────────────────────────────────────────────────────

define('CACHE_FILE', '/var/www/cache.json');
define('CACHE_TTL',  3600); // seconds — auto-refresh cache every 1 hour

// Manual refresh: visit /?refresh=1 to bust the cache immediately
if (isset($_GET['refresh'])) {
    if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);
    header('Location: /');
    exit;
}

function get_cached_pages(): ?array {
    if (!file_exists(CACHE_FILE)) return null;
    if (time() - filemtime(CACHE_FILE) > CACHE_TTL) return null;
    $data = json_decode(file_get_contents(CACHE_FILE), true);
    return is_array($data) ? $data : null;
}

function save_cache(array $pages): void {
    file_put_contents(CACHE_FILE, json_encode($pages));
}

// ─── Notion API Helpers ───────────────────────────────────────────────────────

/**
 * Make a GET request to the Notion API.
 */
function notion_get(string $endpoint): array {
    $ch = curl_init("https://api.notion.com/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . NOTION_TOKEN,
            'Notion-Version: 2022-06-28',
        ],
    ]);
    $result = curl_exec($ch);
    return json_decode($result, true) ?? [];
}

/**
 * Fetch all child blocks of a page/block, handling pagination.
 */
function get_children(string $block_id): array {
    $results = [];
    $cursor  = null;

    do {
        $url  = "blocks/$block_id/children?page_size=100";
        if ($cursor) $url .= "&start_cursor=$cursor";
        $data    = notion_get($url);
        $results = array_merge($results, $data['results'] ?? []);
        $cursor  = $data['next_cursor'] ?? null;
    } while ($cursor);

    return $results;
}

// ─── Block → HTML Renderer ────────────────────────────────────────────────────

/**
 * Convert an array of rich_text objects into an HTML string.
 */
function render_rich_text(array $rich_texts): string {
    $html = '';
    foreach ($rich_texts as $rt) {
        $text = htmlspecialchars($rt['plain_text'] ?? '');
        $ann  = $rt['annotations'] ?? [];
        if ($ann['bold']          ?? false) $text = "<strong>$text</strong>";
        if ($ann['italic']        ?? false) $text = "<em>$text</em>";
        if ($ann['strikethrough'] ?? false) $text = "<s>$text</s>";
        if ($ann['underline']     ?? false) $text = "<u>$text</u>";
        if ($ann['code']          ?? false) $text = "<code>$text</code>";
        $html .= $text;
    }
    return $html;
}

/**
 * Render a single Notion block to HTML.
 * Returns null for block types we intentionally skip (child_page links, etc.)
 */
function render_block(array $block): ?string {
    $type    = $block['type'] ?? '';
    $content = $block[$type]  ?? [];
    $rt      = $content['rich_text'] ?? [];

    switch ($type) {
        case 'paragraph':
            $inner = render_rich_text($rt);
            return $inner !== '' ? "<p>$inner</p>" : '<p>&nbsp;</p>';

        case 'heading_1':
            return '<h1>' . render_rich_text($rt) . '</h1>';
        case 'heading_2':
            return '<h2>' . render_rich_text($rt) . '</h2>';
        case 'heading_3':
            return '<h3>' . render_rich_text($rt) . '</h3>';

        case 'bulleted_list_item':
            return '<li>' . render_rich_text($rt) . '</li>';
        case 'numbered_list_item':
            return '<li>' . render_rich_text($rt) . '</li>';

        case 'to_do':
            $checked = ($content['checked'] ?? false) ? 'checked' : '';
            return '<li><input type="checkbox" ' . $checked . ' disabled> ' . render_rich_text($rt) . '</li>';

        case 'toggle':
            return '<details><summary>' . render_rich_text($rt) . '</summary></details>';

        case 'quote':
            return '<blockquote>' . render_rich_text($rt) . '</blockquote>';

        case 'callout':
            $emoji = $content['icon']['emoji'] ?? '';
            return '<div class="callout">' . $emoji . ' ' . render_rich_text($rt) . '</div>';

        case 'code':
            $lang = htmlspecialchars($content['language'] ?? '');
            return '<pre><code class="' . $lang . '">' . render_rich_text($rt) . '</code></pre>';

        case 'divider':
            return '<hr class="inner-divider">';

        case 'child_page':
        case 'child_database':
            return null;

        default:
            return null;
    }
}

/**
 * Render all blocks of a page into an HTML string.
 * Wraps consecutive list items in <ul> or <ol> tags.
 */
function render_page_blocks(string $page_id): string {
    $blocks = get_children($page_id);
    $html   = '';
    $in_ul  = false;
    $in_ol  = false;

    foreach ($blocks as $block) {
        $type = $block['type'] ?? '';

        if ($type !== 'bulleted_list_item' && $in_ul) { $html .= '</ul>'; $in_ul = false; }
        if ($type !== 'numbered_list_item' && $in_ol) { $html .= '</ol>'; $in_ol = false; }

        if ($type === 'bulleted_list_item' && !$in_ul) { $html .= '<ul>'; $in_ul = true; }
        if ($type === 'numbered_list_item' && !$in_ol) { $html .= '<ol>'; $in_ol = true; }

        $rendered = render_block($block);
        if ($rendered !== null) {
            $html .= $rendered;
        }
    }

    if ($in_ul) $html .= '</ul>';
    if ($in_ol) $html .= '</ol>';

    return $html;
}

// ─── Tree Traversal ───────────────────────────────────────────────────────────

/**
 * Recursively find all "leaf" workout day pages.
 * A leaf page matches the pattern "M/D/YY - Workout Type" e.g. "1/1/25 - Pull"
 */
function find_workout_pages(string $page_id): array {
    $children      = get_children($page_id);
    $workout_pages = [];

    foreach ($children as $block) {
        if (($block['type'] ?? '') !== 'child_page') continue;

        $title    = $block['child_page']['title'] ?? 'Untitled';
        $child_id = $block['id'];

        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}\s*-\s*.+$/', $title)) {
            // This is a leaf workout page — fetch and store its rendered content now
            $workout_pages[] = [
                'title'   => $title,
                'content' => render_page_blocks($child_id),
            ];
        } else {
            // Recurse into container pages (Training Log, 2024 Training Log, etc.)
            $workout_pages = array_merge($workout_pages, find_workout_pages($child_id));
        }
    }

    return $workout_pages;
}

// ─── Sort Helper ──────────────────────────────────────────────────────────────

function sort_workout_pages(array &$pages): void {
    usort($pages, function ($a, $b) {
        preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $a['title'], $ma);
        preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $b['title'], $mb);

        $ya = (int)$ma[3]; $yb = (int)$mb[3];
        if ($ya < 100) $ya += 2000;
        if ($yb < 100) $yb += 2000;

        $da = mktime(0, 0, 0, (int)$ma[1], (int)$ma[2], $ya);
        $db = mktime(0, 0, 0, (int)$mb[1], (int)$mb[2], $yb);

        return $da <=> $db;
    });
}

// ─── Main ─────────────────────────────────────────────────────────────────────

$workout_pages = get_cached_pages();
$from_cache    = true;

if ($workout_pages === null) {
    $from_cache    = false;
    $workout_pages = find_workout_pages(TRAINING_PAGE_ID);
    sort_workout_pages($workout_pages);
    save_cache($workout_pages);
}

$cache_age = file_exists(CACHE_FILE)
    ? round((time() - filemtime(CACHE_FILE)) / 60)
    : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Log</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
            background: #f9f9f9;
            color: #333;
        }
        header { margin-bottom: 2rem; }
        header h1 { font-size: 2rem; margin: 0 0 0.4rem; }
        .meta { color: #888; font-size: 0.9rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
        .meta a { color: #555; text-decoration: none; border: 1px solid #ccc; border-radius: 4px; padding: 0.2rem 0.6rem; font-size: 0.85rem; }
        .meta a:hover { background: #eee; }
        .workout-entry { background: #fff; border-radius: 8px; padding: 1.2rem 1.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .workout-entry h2 { margin: 0 0 0.8rem; font-size: 1.2rem; color: #1a1a2e; border-bottom: 1px solid #eee; padding-bottom: 0.5rem; }
        .workout-entry p  { margin: 0.3rem 0; line-height: 1.6; }
        .workout-entry ul, .workout-entry ol { padding-left: 1.5rem; margin: 0.3rem 0; }
        .workout-entry li { margin: 0.2rem 0; }
        .workout-entry blockquote { border-left: 3px solid #ccc; margin: 0.5rem 0; padding-left: 1rem; color: #666; }
        .workout-entry code { background: #f0f0f0; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.9em; }
        .workout-entry pre  { background: #f0f0f0; padding: 1rem; border-radius: 5px; overflow-x: auto; }
        .workout-entry .callout { background: #f8f8e8; border-left: 4px solid #e0c800; padding: 0.5rem 1rem; border-radius: 4px; }
        .workout-entry hr.inner-divider { border: none; border-top: 1px solid #eee; margin: 0.5rem 0; }
        .separator { border: none; border-top: 2px solid #ddd; margin: 1.5rem 0; }
        .no-entries { color: #888; font-style: italic; }
    </style>
</head>
<body>

<header>
    <h1>🏋️ Training Log</h1>
    <div class="meta">
        <span><?= count($workout_pages) ?> workout<?= count($workout_pages) !== 1 ? 's' : '' ?></span>
        <?php if ($from_cache && $cache_age !== null): ?>
            <span>cached <?= $cache_age ?> min ago</span>
        <?php else: ?>
            <span>freshly loaded from Notion</span>
        <?php endif; ?>
        <a href="/?refresh=1">🔄 Refresh from Notion</a>
    </div>
</header>

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

</body>
</html>