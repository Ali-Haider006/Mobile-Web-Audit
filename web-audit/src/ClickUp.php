<?php
declare(strict_types=1);

namespace Wva;

use RuntimeException;
use Throwable;

/**
 * ClickUp API v2 client - just the parts this tool needs: read the workspace
 * hierarchy so a list can be picked from a dropdown, and create a task in the
 * chosen list.
 *
 * Auth is a personal API token (pk_...), sent in the Authorization header with
 * no "Bearer" prefix, which is how ClickUp's personal tokens work. Generate one
 * in ClickUp under Settings -> Apps.
 *
 * The parsing is separated from the HTTP so it can be tested without network
 * access - see bin/selftest.php.
 */
final class ClickUp
{
    public const BASE = 'https://api.clickup.com/api/v2';

    /** ClickUp priorities. The API takes the number, people read the word. */
    public const PRIORITY_URGENT = 1;
    public const PRIORITY_HIGH   = 2;
    public const PRIORITY_NORMAL = 3;
    public const PRIORITY_LOW    = 4;

    public static function configured(): bool
    {
        return self::token() !== '';
    }

    /** From .env / config/local.php only - never stored or edited in the UI. */
    public static function token(): string
    {
        $fromFile = trim((string) Config::get('clickup_token', ''));
        if ($fromFile !== '') {
            return $fromFile;
        }
        return Settings::storedOnly('clickup_token');
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private static function get(string $path, array $query = []): array
    {
        return self::call('GET', $path, $query, null);
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private static function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $token = self::token();
        if ($token === '') {
            throw new RuntimeException('No ClickUp API token set. Add one under Settings.');
        }

        $url = self::BASE . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization: ' . $token,
            'Accept: application/json',
        ];
        $payload = null;
        if ($body !== null) {
            $payload   = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $response = Http::request($method, $url, $headers, $payload, 30);
        $decoded  = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            throw new RuntimeException('ClickUp returned a non-JSON response (HTTP ' . $response['status'] . ').');
        }
        if ($response['status'] >= 400) {
            throw new RuntimeException(self::errorMessage($decoded, $response['status']));
        }

        return $decoded;
    }

    /**
     * ClickUp reports failures as {"err": "...", "ECODE": "..."}.
     *
     * @param array<string,mixed> $payload
     */
    public static function errorMessage(array $payload, int $status): string
    {
        $message = (string) ($payload['err'] ?? $payload['error'] ?? '');
        $code    = (string) ($payload['ECODE'] ?? '');

        if ($message === '') {
            $message = 'HTTP ' . $status;
        }
        if ($status === 401) {
            $message .= ' - check the API token.';
        } elseif ($status === 429) {
            $message .= ' - ClickUp rate limit reached, try again in a minute.';
        }

        return 'ClickUp: ' . $message . ($code !== '' ? ' (' . $code . ')' : '');
    }

    /**
     * Every list the token can see, flattened for a dropdown, each with a
     * readable path so two lists called "Website" are still distinguishable.
     *
     * @return array<int,array{id:string,name:string,path:string,space:string,folder:?string}>
     */
    public static function fetchLists(): array
    {
        $out = [];

        foreach (self::parseNamed(self::get('/team'), 'teams') as $team) {
            $teamId = (string) $team['id'];

            foreach (self::parseNamed(self::get('/team/' . rawurlencode($teamId) . '/space', ['archived' => 'false']), 'spaces') as $space) {
                $spaceId   = (string) $space['id'];
                $spaceName = (string) $space['name'];

                // Lists sitting directly in the space, with no folder.
                foreach (self::parseNamed(self::get('/space/' . rawurlencode($spaceId) . '/list', ['archived' => 'false']), 'lists') as $list) {
                    $out[] = self::listRow($list, $spaceName, null);
                }

                foreach (self::parseNamed(self::get('/space/' . rawurlencode($spaceId) . '/folder', ['archived' => 'false']), 'folders') as $folder) {
                    $folderName = (string) $folder['name'];
                    // The folder payload usually embeds its lists; fall back to
                    // a request when it does not.
                    $lists = is_array($folder['lists'] ?? null) && $folder['lists'] !== []
                        ? $folder['lists']
                        : self::parseNamed(self::get('/folder/' . rawurlencode((string) $folder['id']) . '/list', ['archived' => 'false']), 'lists');

                    foreach ($lists as $list) {
                        if (is_array($list) && isset($list['id'], $list['name'])) {
                            $out[] = self::listRow($list, $spaceName, $folderName);
                        }
                    }
                }
            }
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    public static function parseNamed(array $payload, string $key): array
    {
        $rows = $payload[$key] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn ($row): bool => is_array($row) && isset($row['id'], $row['name'])
        ));
    }

    /**
     * @param array<string,mixed> $list
     * @return array{id:string,name:string,path:string,space:string,folder:?string}
     */
    private static function listRow(array $list, string $space, ?string $folder): array
    {
        $name = (string) $list['name'];
        return [
            'id'     => (string) $list['id'],
            'name'   => $name,
            'space'  => $space,
            'folder' => $folder,
            'path'   => $space . ($folder !== null ? ' / ' . $folder : '') . ' / ' . $name,
        ];
    }

    /**
     * Create a task. Returns the bits worth storing.
     *
     * @param array<string,mixed> $fields
     * @return array{id:string,url:string}
     */
    public static function createTask(string $listId, array $fields): array
    {
        if (trim($listId) === '') {
            throw new RuntimeException('No ClickUp list chosen for this site.');
        }

        $payload = self::parseCreated(
            self::call('POST', '/list/' . rawurlencode($listId) . '/task', [], $fields)
        );

        if ($payload['id'] === '') {
            throw new RuntimeException('ClickUp accepted the request but returned no task id.');
        }

        return $payload;
    }

    /**
     * Add a comment to a task. Used to report a repeat failure or a recovery
     * on the existing task instead of opening another one.
     */
    public static function comment(string $taskId, string $text): void
    {
        self::call('POST', '/task/' . rawurlencode($taskId) . '/comment', [], [
            'comment_text' => $text,
            'notify_all'   => false,
        ]);
    }

    /** Move a task to a status by name, e.g. "complete". */
    public static function setStatus(string $taskId, string $status): void
    {
        self::call('PUT', '/task/' . rawurlencode($taskId), [], ['status' => $status]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{id:string,url:string}
     */
    public static function parseCreated(array $payload): array
    {
        $id = (string) ($payload['id'] ?? '');
        return [
            'id'  => $id,
            'url' => (string) ($payload['url'] ?? ($id !== '' ? 'https://app.clickup.com/t/' . $id : '')),
        ];
    }

    /** Our priority names map onto ClickUp's four levels. */
    public static function priorityFor(string $priority): int
    {
        return match ($priority) {
            'critical' => self::PRIORITY_URGENT,
            'high'     => self::PRIORITY_HIGH,
            default    => self::PRIORITY_NORMAL,
        };
    }

    /** The cached dropdown options, so the UI does not call the API per page. */
    public static function cachedLists(): array
    {
        $raw = (string) Settings::get('clickup_list_cache', '');
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Everyone in the workspaces the token can see, for the assignee dropdown.
     * GET /team embeds members, so this is one request per token.
     *
     * @return array<int,array{id:int,name:string,email:string}>
     */
    public static function fetchMembers(): array
    {
        return self::parseMembers(self::get('/team'));
    }

    /**
     * Pure, so it can be tested against a saved payload.
     *
     * @param array<string,mixed> $payload
     * @return array<int,array{id:int,name:string,email:string}>
     */
    public static function parseMembers(array $payload): array
    {
        $people = [];

        foreach (self::parseNamed($payload, 'teams') as $team) {
            foreach ((array) ($team['members'] ?? []) as $member) {
                $user = is_array($member) ? ($member['user'] ?? $member) : null;
                if (!is_array($user) || !isset($user['id'])) {
                    continue;
                }
                $id = (int) $user['id'];
                if ($id === 0) {
                    continue;
                }
                $name = trim((string) ($user['username'] ?? ''));
                $mail = trim((string) ($user['email'] ?? ''));
                // Someone invited but not yet signed up has no username.
                $people[$id] = [
                    'id'    => $id,
                    'name'  => $name !== '' ? $name : ($mail !== '' ? $mail : 'User ' . $id),
                    'email' => $mail,
                ];
            }
        }

        usort($people, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_values($people);
    }

    /** @return array<int,array<string,mixed>> */
    public static function cachedMembers(): array
    {
        $raw = (string) Settings::get('clickup_member_cache', '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whoever owns the personal token - i.e. the person who set this tool up.
     * GET /user is one request and needs no workspace id.
     *
     * @return array{id:int,name:string,email:string}|null
     */
    public static function fetchTokenOwner(): ?array
    {
        return self::parseTokenOwner(self::get('/user'));
    }

    /**
     * Pure, so it can be tested against a saved payload.
     *
     * @param array<string,mixed> $payload
     * @return array{id:int,name:string,email:string}|null
     */
    public static function parseTokenOwner(array $payload): ?array
    {
        $user = $payload['user'] ?? null;
        if (!is_array($user) || !isset($user['id'])) {
            return null;
        }
        $id = (int) $user['id'];
        if ($id === 0) {
            return null;
        }
        $name = trim((string) ($user['username'] ?? ''));
        $mail = trim((string) ($user['email'] ?? ''));

        return [
            'id'    => $id,
            'name'  => $name !== '' ? $name : ($mail !== '' ? $mail : 'User ' . $id),
            'email' => $mail,
        ];
    }

    /** The token owner's ClickUp id, 0 until the people list has been loaded. */
    public static function tokenOwnerId(): int
    {
        return (int) Settings::get('clickup_token_owner', 0);
    }

    /**
     * Loads the people and remembers who the token belongs to. First time
     * round, that person also becomes the default assignee - the tool is set
     * up by the person who wants the tasks - but it never overwrites a choice
     * already made in Settings.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function refreshMembers(): array
    {
        $people = self::fetchMembers();
        Settings::set('clickup_member_cache', (string) json_encode($people, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $owner = null;
        try {
            $owner = self::fetchTokenOwner();
        } catch (Throwable $e) {
            // Knowing who the token belongs to is a nicety, not a requirement.
        }
        if ($owner !== null) {
            Settings::set('clickup_token_owner', (string) $owner['id']);
            if (trim((string) Settings::get('clickup_default_assignee', '')) === '') {
                Settings::set('clickup_default_assignee', (string) $owner['id']);
            }
        }

        return $people;
    }

    /**
     * Dropdown label. The token owner is marked so whoever set the tool up can
     * find themselves in a workspace with a hundred people in it.
     *
     * @param array<string,mixed> $member
     */
    public static function memberLabel(array $member): string
    {
        $name  = (string) ($member['name'] ?? $member['id'] ?? '');
        $owner = self::tokenOwnerId();

        return $owner !== 0 && (int) ($member['id'] ?? 0) === $owner ? $name . ' (you)' : $name;
    }

    public static function memberName(int $id): string
    {
        foreach (self::cachedMembers() as $member) {
            if ((int) ($member['id'] ?? 0) === $id) {
                return (string) ($member['name'] ?? $id);
            }
        }
        return (string) $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function refreshLists(): array
    {
        $lists = self::fetchLists();
        Settings::set('clickup_list_cache', (string) json_encode($lists, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Settings::set('clickup_list_cache_at', Database::now());
        return $lists;
    }

    public static function listName(string $listId): string
    {
        foreach (self::cachedLists() as $list) {
            if ((string) ($list['id'] ?? '') === $listId) {
                return (string) ($list['path'] ?? $list['name'] ?? $listId);
            }
        }
        return $listId;
    }
}
