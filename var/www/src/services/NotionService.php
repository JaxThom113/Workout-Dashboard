<?php

/*
    This service is for interacting with Notion API and getting page content
    from workout logs
*/
class NotionService
{
    // environment variables
    private string $notionToken;
    private string $trainingPageId;

    // pages to ignore in search
    private array $ignorePages = ["Body Weight Log", "Training Splits", "Rep Maxes"];

    public function __construct(string $notionToken, string $trainingPageId)
    {
        $this->notionToken = $notionToken;
        $this->trainingPageId = $trainingPageId;
    }

    public function getWorkoutPages(): array
    {
        $pages = $this->findWorkoutPages($this->trainingPageId);
        $this->sortWorkoutPages($pages);

        return $pages;
    }

    private function notionGet(string $endpoint): array
    {
        // access Notion API, use common cURL operations
        $ch = curl_init('https://api.notion.com/v1/' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->notionToken,
                'Notion-Version: 2022-06-28',
            ],
        ]);

        $result = curl_exec($ch);
        return json_decode($result, true) ?? [];
    }

    private function getChildren(string $blockId): array
    {
        $results = [];
        $cursor = null;

        do
        {
            // get children blocks for a given block, handle pagination if more than 100 children
            $url = "blocks/$blockId/children?page_size=100";
            if ($cursor)
                $url .= "&start_cursor=$cursor";

            $data = $this->notionGet($url);
            $results = array_merge($results, $data['results'] ?? []);
            $cursor = $data['next_cursor'] ?? null;
        } 
        while ($cursor);

        return $results;
    }

    private function renderRichText(array $richTexts): string
    {
        $html = '';

        // convert Notion rich text to HTML
        foreach ($richTexts as $rt)
        {
            $text = htmlspecialchars($rt['plain_text'] ?? '');
            $ann = $rt['annotations'] ?? [];

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

    private function renderBlock(array $block): ?string
    {
        $type = $block['type'] ?? '';
        $content = $block[$type] ?? [];
        $rt = $content['rich_text'] ?? [];

        // given a Notion block, render using specific HTML tags based on block type
        switch ($type)
        {
            case 'paragraph':
                $inner = $this->renderRichText($rt);
                return $inner !== '' ? "<p>$inner</p>" : '<p>&nbsp;</p>';
            case 'heading_1':
                return '<h1>' . $this->renderRichText($rt) . '</h1>';
            case 'heading_2':
                return '<h2>' . $this->renderRichText($rt) . '</h2>';
            case 'heading_3':
                return '<h3>' . $this->renderRichText($rt) . '</h3>';
            case 'bulleted_list_item':
                return '<li>' . $this->renderRichText($rt) . '</li>';
            case 'numbered_list_item':
                return '<li>' . $this->renderRichText($rt) . '</li>';
            case 'to_do':
                $checked = ($content['checked'] ?? false) ? 'checked' : '';
                return '<li><input type="checkbox" ' . $checked . ' disabled> ' . $this->renderRichText($rt) . '</li>';
            case 'toggle':
                return '<details><summary>' . $this->renderRichText($rt) . '</summary></details>';
            case 'quote':
                return '<blockquote>' . $this->renderRichText($rt) . '</blockquote>';
            case 'callout':
                $emoji = $content['icon']['emoji'] ?? '';
                return '<div class="callout">' . $emoji . ' ' . $this->renderRichText($rt) . '</div>';
            case 'code':
                $lang = htmlspecialchars($content['language'] ?? '');
                return '<pre><code class="' . $lang . '">' . $this->renderRichText($rt) . '</code></pre>';
            case 'divider':
                return '<hr class="inner-divider">';
            case 'child_page':
            case 'child_database':
                return null;
            default:
                return null;
        }
    }

    private function renderPageBlocks(string $pageId): string
    {
        $blocks = $this->getChildren($pageId);
        $html = '';
        $inUl = false;
        $inOl = false;

        // keep track of <ul> or <ol> list leveling when rendering blocks
        foreach ($blocks as $block)
        {
            $type = $block['type'] ?? '';

            if ($type !== 'bulleted_list_item' && $inUl)
            {
                $html .= '</ul>';
                $inUl = false;
            }
            if ($type !== 'numbered_list_item' && $inOl)
            {
                $html .= '</ol>';
                $inOl = false;
            }

            if ($type === 'bulleted_list_item' && !$inUl)
            {
                $html .= '<ul>';
                $inUl = true;
            }
            if ($type === 'numbered_list_item' && !$inOl)
            {
                $html .= '<ol>';
                $inOl = true;
            }

            $rendered = $this->renderBlock($block);
            if ($rendered !== null)
                $html .= $rendered;
        }

        if ($inUl)
            $html .= '</ul>';
        if ($inOl)
            $html .= '</ol>';

        return $html;
    }

    private function findWorkoutPages(string $pageId): array
    {
        $children = $this->getChildren($pageId);
        $workoutPages = [];

        // depth-first search through tree of Notion pages to find workout logs
        foreach ($children as $block)
        {
            if (($block['type'] ?? '') !== 'child_page')
                continue;

            $title = $block['child_page']['title'] ?? 'Untitled';
            $childId = $block['id'] ?? '';

            if ($childId === '' || in_array($title, $this->ignorePages, true))
                continue;

            // check for workout page with "MM/DD/YYYY - Workout Name" format
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}\s*-\s*.+$/', $title))
            {
                $workoutPages[] = [
                    'id' => $childId,
                    'title' => $title,
                    'content' => $this->renderPageBlocks($childId),
                ];
            }
            else
            {
                // otherwise, go into this page and search for children
                $workoutPages = array_merge($workoutPages, $this->findWorkoutPages($childId));
            }
        }

        return $workoutPages;
    }

    private function sortWorkoutPages(array &$pages): void
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
                $timestamp_a = mktime(0, 0, 0, (int) ($date_a[1] ?? 1), (int) ($date_a[2] ?? 1), (int) ($date_a[3] ?? 1970));
                $timestamp_b = mktime(0, 0, 0, (int) ($date_b[1] ?? 1), (int) ($date_b[2] ?? 1), (int) ($date_b[3] ?? 1970));

                return $timestamp_a <=> $timestamp_b;
            }
        );
    }


    /*
        TODO: Implement different logic for runs
    */
    public function find_runs(string $page_id): array 
    {
        $runs = [];

        return $runs;
    }
}

?>
