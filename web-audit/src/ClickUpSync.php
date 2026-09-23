<?php
declare(strict_types=1);

namespace Wva;

use Throwable;
use Wva\Repo\Sites;
use Wva\Repo\Tasks;

/**
 * Turns one of our tasks into a ClickUp task.
 *
 * Which list it lands in: the site's own list if it has one, otherwise the
 * global default from Settings. That way one workspace can hold a list per
 * client without configuring anything per task.
 */
final class ClickUpSync
{
    /** The list a given site's tasks belong in, or '' when none is chosen. */
    public static function listIdFor(?array $site): string
    {
        $perSite = trim((string) ($site['clickup_list_id'] ?? ''));
        if ($perSite !== '') {
            return $perSite;
        }
        return trim((string) Settings::get('clickup_default_list_id', ''));
    }

    /**
     * Push a task. Never throws - a ClickUp outage must not break an audit, so
     * the failure is recorded on the task and reported to the caller.
     *
     * $listId overrides where it goes; $overrides carries the operator's edits
     * to title / description / priority from the review screen.
     *
     * @param array{title?:string,description?:string,priority?:string} $overrides
     * @return array{ok:bool, url:?string, error:?string, skipped:?string}
     */
    public static function push(int $taskId, ?string $listId = null, array $overrides = []): array
    {
        $task = Tasks::find($taskId);
        if ($task === null) {
            return ['ok' => false, 'url' => null, 'error' => 'Task not found', 'skipped' => null];
        }
        if (!empty($task['clickup_task_id'])) {
            return ['ok' => true, 'url' => (string) $task['clickup_task_url'], 'error' => null, 'skipped' => 'already in ClickUp'];
        }
        if (!ClickUp::configured()) {
            return ['ok' => false, 'url' => null, 'error' => null, 'skipped' => 'no ClickUp token set'];
        }

        $site   = Sites::find((int) $task['site_id']);
        $listId = $listId !== null && trim($listId) !== '' ? trim($listId) : self::listIdFor($site);
        if ($listId === '') {
            return ['ok' => false, 'url' => null, 'error' => null, 'skipped' => 'no ClickUp list chosen for this site'];
        }

        try {
            $created = ClickUp::createTask($listId, self::payload($task, $overrides));
            Tasks::recordClickUp($taskId, $created['id'], $created['url']);
            return ['ok' => true, 'url' => $created['url'], 'error' => null, 'skipped' => null];
        } catch (Throwable $e) {
            Tasks::recordClickUpError($taskId, $e->getMessage());
            return ['ok' => false, 'url' => null, 'error' => $e->getMessage(), 'skipped' => null];
        }
    }

    /**
     * @param array<string,mixed> $task
     * @param array{title?:string,description?:string,priority?:string} $overrides
     * @return array<string,mixed>
     */
    public static function payload(array $task, array $overrides = []): array
    {
        $title = trim((string) ($overrides['title'] ?? '')) !== ''
            ? (string) $overrides['title']
            : (string) $task['title'];
        $body = trim((string) ($overrides['description'] ?? '')) !== ''
            ? (string) $overrides['description']
            : self::description($task);
        $priority = trim((string) ($overrides['priority'] ?? '')) !== ''
            ? (string) $overrides['priority']
            : (string) $task['priority'];

        $fields = [
            'name'                 => substr($title, 0, 255),
            'markdown_description' => $body,
            'priority'             => ClickUp::priorityFor($priority),
        ];

        $tags = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Settings::get('clickup_tags', 'core-web-vitals'))
        )));
        if ($tags !== []) {
            $fields['tags'] = $tags;
        }

        return $fields;
    }

    /**
     * The task body: what is wrong, the numbers, and links back to both the
     * page and this tool.
     *
     * @param array<string,mixed> $task
     */
    public static function description(array $task): string
    {
        $threshold = (int) $task['threshold'];
        $score     = $task['latest_score'] === null ? (int) $task['score_at_open'] : (int) $task['latest_score'];

        $lines = [];
        $lines[] = '**Mobile performance ' . $score . '** against a target of ' . $threshold . '.';
        $lines[] = '';
        $lines[] = '- Page: ' . (string) $task['url'];
        $lines[] = '- Site: ' . (string) ($task['site_name'] ?? '');
        $lines[] = '- Opened: ' . (string) $task['opened_at'] . ' UTC';

        $appUrl = rtrim((string) Settings::get('app_url', ''), '/');
        if ($appUrl !== '') {
            $lines[] = '- History: ' . $appUrl . '/page.php?id=' . (int) $task['page_id'];
        }

        $details = trim((string) $task['details']);
        if ($details !== '') {
            $lines[] = '';
            $lines[] = '```';
            $lines[] = $details;
            $lines[] = '```';
        }

        $lines[] = '';
        $lines[] = '_Opened automatically by Mobile Web Audit._';

        return implode("\n", $lines);
    }
}
