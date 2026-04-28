<?php
// load secrets from .env
$env = parse_ini_file(__DIR__ . '/../../../../.env');
define('NOTION_TOKEN', $env['NOTION_TOKEN'] ?? '');
define('TRAINING_PAGE_ID', $env['TRAINING_PAGE_ID'] ?? '');

// cache json
define('CACHE_FILE', '/var/www/database/cache/cache.json');

/*
    Cache
*/

function get_cached_pages(): ?array 
{
    if (!file_exists(CACHE_FILE)) 
        return null;

    $data = json_decode(file_get_contents(CACHE_FILE), true);

    if (!is_array($data))
        return null;

    return $data;
}

function save_cache(array $pages): void 
{
    file_put_contents(CACHE_FILE, json_encode($pages));
}

/*
    Make a GET request to the Notion API.
*/
function notion_get(string $endpoint): array 
{
    $ch = curl_init("https://api.notion.com/v1/$endpoint");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . NOTION_TOKEN,
            'Notion-Version: 2022-06-28',
        ],
    ]);

    $result = curl_exec($ch);
    return json_decode($result, true) ?? [];
}

/*
    Fetch all child blocks of a page/block, handling pagination.
*/
function get_children(string $block_id): array 
{
    $results = [];
    $cursor = null;

    do {
        $url = "blocks/$block_id/children?page_size=100";
        if ($cursor) 
            $url .= "&start_cursor=$cursor";

        $data = notion_get($url);
        $results = array_merge($results, $data['results'] ?? []);
        $cursor = $data['next_cursor'] ?? null;
    } while ($cursor);

    return $results;
}

/*
    Block to HTML renderer
    Convert an array of rich_text objects into an HTML string.
*/
function render_rich_text(array $rich_texts): string 
{
    $html = '';

    foreach ($rich_texts as $rt) 
    {
        $text = htmlspecialchars($rt['plain_text'] ?? '');
        $ann  = $rt['annotations'] ?? [];

        if ($ann['bold'] ?? false) 
            $text = "<strong>$text</strong>";
        if ($ann['italic'] ?? false) 
            $text = "<em>$text</em>";
        if ($ann['strikethrough'] ?? false) 
            $text = "<s>$text</s>";
        if ($ann['underline'] ?? false) 
            $text = "<u>$text</u>";
        if ($ann['code'] ?? false) 
            $text = "<code>$text</code>";

        $html .= $text;
    }

    return $html;
}

/*
    Render a single Notion block to HTML.
    Returns null for block types we intentionally skip (child_page links, etc.)
*/
function render_block(array $block): ?string 
{
    $type = $block['type'] ?? '';

    $content = $block[$type] ?? [];
    $rt = $content['rich_text'] ?? [];

    switch ($type) 
    {
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

/*
    Render all blocks of a page into an HTML string.
    Wraps consecutive list items in <ul> or <ol> tags.
*/
function render_page_blocks(string $page_id): string 
{
    $blocks = get_children($page_id);
    $html = '';
    $in_ul = false;
    $in_ol = false;

    foreach ($blocks as $block) 
    {
        $type = $block['type'] ?? '';

        if ($type !== 'bulleted_list_item' && $in_ul) 
        { 
            $html .= '</ul>'; 
            $in_ul = false; 
        }
        if ($type !== 'numbered_list_item' && $in_ol) 
        { 
            $html .= '</ol>'; 
            $in_ol = false; 
        }

        if ($type === 'bulleted_list_item' && !$in_ul) 
        { 
            $html .= '<ul>'; 
            $in_ul = true; 
        }
        if ($type === 'numbered_list_item' && !$in_ol) 
        { 
            $html .= '<ol>'; 
            $in_ol = true; 
        }

        $rendered = render_block($block);
        if ($rendered !== null)
            $html .= $rendered;
    }

    if ($in_ul) 
        $html .= '</ul>';
    if ($in_ol) 
        $html .= '</ol>';

    return $html;
}

/*
    Recursively find all "leaf" workout day pages.
    A leaf page matches the pattern "M/D/YY - Workout Type" e.g. "1/1/25 - Pull"
*/
function find_workout_pages(string $page_id): array 
{
    $children = get_children($page_id);
    $ignore_pages = ["Body Weight Log", "Training Splits", "Rep Maxes"];
    $workout_pages = [];

    foreach ($children as $block) 
    {
        if (($block['type'] ?? '') !== 'child_page') 
            continue;

        $title = $block['child_page']['title'] ?? 'Untitled';
        $child_id = $block['id'];

        // skip over specified pages
        if (in_array($title, $ignore_pages))
            continue;

        // check for MM/DD/YY - Workout Type format
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}\s*-\s*.+$/', $title)) 
        {
            // fetch and store title and content
            $workout_pages[] = [
                'title'   => $title,
                'content' => render_page_blocks($child_id),
            ];
        } 
        else 
        {
            // recurse into container pages (Training Log, 2024 Training Log, etc.)
            $workout_pages = array_merge($workout_pages, find_workout_pages($child_id));
        }
    }

    return $workout_pages;
}

function sort_workout_pages(array &$pages): void 
{
    // compare two pages at a time, decide which should come first
    usort(
        $pages, 
        function ($a, $b) 
        {
            // extract the date from the start of both titles, MM/DD/YY format
            preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $a['title'], $date_a);
            preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/', $b['title'], $date_b);

            // index 1 is the month (MM), 2 is day (DD), 3 is year (YY)
            $timestamp_a = mktime(0, 0, 0, (int)$date_a[1], (int)$date_a[2], (int)$date_a[3]);
            $timestamp_b = mktime(0, 0, 0, (int)$date_b[1], (int)$date_b[2], (int)$date_b[3]);

            return $timestamp_a <=> $timestamp_b;
        }
    );
}

function find_runs(string $page_id): array 
{
    $runs = [];

    return $runs;
}

/*
    Main
*/
$workout_pages = get_cached_pages();
$from_cache = true;

if ($workout_pages === null) 
{
    $from_cache = false;
    $workout_pages = find_workout_pages(TRAINING_PAGE_ID);
    sort_workout_pages($workout_pages);
    save_cache($workout_pages);
}

$cache_age = null;

if (file_exists(CACHE_FILE))
    $cache_age = round((time() - filemtime(CACHE_FILE)) / 60);

// for whatever readon you can't use __DIR__ . here
require __DIR__ . '/training-log.html.php';
?>
