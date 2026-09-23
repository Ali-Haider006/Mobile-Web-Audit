<?php
declare(strict_types=1);

require __DIR__ . '/_init.php';
wva_require_schema();

use Wva\Config;
use Wva\Helpers;
use Wva\Importer;
use Wva\Repo\Pages;
use Wva\Repo\Sites;
use Wva\Sitemap;

$siteId = wva_int('site_id');
$site   = $siteId > 0 ? Sites::find($siteId) : null;
if ($site === null) {
    Helpers::flash('Pick a site first.', 'warn');
    Helpers::redirect('sites.php');
}

$maxUrls = (int) Config::get('max_sitemap_urls', 2000);
$preview = null;
$token   = null;
$notes   = [];
$sitemaps = [];

if (wva_is_post()) {
    Helpers::checkCsrf();
    $action = wva_str('action');

    try {
        if ($action === 'add_rule') {
            Sites::addExclusionRule($siteId, wva_str('pattern'));
            $excluded = Pages::applyExclusionRules($siteId);
            Helpers::flash('Rule added.' . ($excluded ? ' ' . $excluded . ' already-imported page(s) excluded by it.' : ''), 'ok');
            Helpers::redirect('import.php?site_id=' . $siteId);
        }

        if ($action === 'delete_rule') {
            Sites::deleteExclusionRule($siteId, wva_int('rule_id'));
            Helpers::flash('Rule removed. Pages it excluded stay excluded until you re-tick them.', 'ok');
            Helpers::redirect('import.php?site_id=' . $siteId);
        }

        if ($action === 'crawl') {
            $sitemapUrl = wva_str('sitemap_url');
            $crawl = Sitemap::crawl((string) $site['url'], $sitemapUrl !== '' ? $sitemapUrl : ($site['sitemap_url'] ?: null), $maxUrls);
            if ($sitemapUrl !== '') {
                Sites::update(
                    $siteId,
                    (string) $site['name'],
                    $site['score_threshold'] === null ? null : (int) $site['score_threshold'],
                    $sitemapUrl,
                    (bool) $site['is_active']
                );
            }
            $token = bin2hex(random_bytes(8));
            $_SESSION['wva_import'] = [
                'token'    => $token,
                'site_id'  => $siteId,
                'urls'     => $crawl['urls'],
                'sitemaps' => $crawl['sitemaps'],
                'notes'    => $crawl['notes'],
            ];
            $preview  = Importer::preview($siteId, $crawl['urls']);
            $notes    = $crawl['notes'];
            $sitemaps = $crawl['sitemaps'];
        }

        if ($action === 'save') {
            $stored = $_SESSION['wva_import'] ?? null;
            if (!is_array($stored) || ($stored['token'] ?? '') !== wva_str('token') || (int) $stored['site_id'] !== $siteId) {
                throw new RuntimeException('That import expired. Scan the sitemap again.');
            }
            $urls = $stored['urls'];

            $mode = wva_str('select_mode', 'rules');
            if ($mode === 'custom') {
                $csv = wva_str('selected_csv');
                $indexes = $csv !== ''
                    ? array_map('intval', array_filter(explode(',', $csv), 'is_numeric'))
                    : array_map('intval', (array) ($_POST['selected'] ?? []));
                $selected = [];
                foreach ($indexes as $index) {
                    if (isset($urls[$index])) {
                        $selected[] = $urls[$index]['loc'];
                    }
                }
            } else {
                // "Track everything the rules don't exclude" - the default.
                $selected = [];
                foreach (Importer::preview($siteId, $urls) as $row) {
                    if ($row['tracked']) {
                        $selected[] = $row['loc'];
                    }
                }
            }

            $result = Importer::save($siteId, $urls, $selected);
            unset($_SESSION['wva_import']);
            Helpers::flash(sprintf(
                'Imported %d URL(s): %d new, %d tracked, %d excluded.',
                $result['added'] + $result['updated'],
                $result['added'],
                $result['tracked'],
                $result['excluded']
            ), 'ok');
            Helpers::redirect('site.php?id=' . $siteId);
        }
    } catch (Throwable $e) {
        Helpers::flash($e->getMessage(), 'error');
        Helpers::redirect('import.php?site_id=' . $siteId);
    }
}

$rules  = Sites::exclusionRules($siteId);
$title  = 'Import sitemap';
$active = 'sites';
require WVA_ROOT . '/src/views/header.php';
?>
<h1>Import sitemap — <?= Helpers::h($site['name']) ?></h1>
<p class="sub"><?= Helpers::h($site['url']) ?> · <a href="site.php?id=<?= $siteId ?>">back to site</a></p>

<div class="card">
    <h3>1. Scan the sitemap</h3>
    <p class="small muted">robots.txt is checked first, then the usual filenames. Sitemap indexes and .gz are followed.
        Hard limit: <?= $maxUrls ?> URLs.</p>
    <form method="post">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="crawl">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="row">
            <div class="field">
                <label for="sitemap_url">Sitemap URL (optional — leave blank to auto-discover)</label>
                <input type="text" id="sitemap_url" name="sitemap_url"
                       value="<?= Helpers::h($site['sitemap_url'] ?? '') ?>"
                       placeholder="<?= Helpers::h(rtrim((string) $site['url'], '/')) ?>/sitemap.xml">
            </div>
            <div><button class="btn primary" type="submit">Scan sitemap</button></div>
        </div>
    </form>
</div>

<div class="card">
    <h3>Exclusion rules</h3>
    <p class="small muted">Wildcard patterns matched against the full URL. Anything matching is excluded on every
        import — e.g. <code>*/tag/*</code>, <code>*?s=*</code>, <code>*/wp-json/*</code>.</p>
    <?php if ($rules): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Pattern</th><th>Added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><code><?= Helpers::h($rule['pattern']) ?></code></td>
                    <td class="small muted"><?= Helpers::h(Helpers::ago($rule['created_at'])) ?></td>
                    <td class="num">
                        <form method="post" class="inline">
                            <?= Helpers::csrfField() ?>
                            <input type="hidden" name="action" value="delete_rule">
                            <input type="hidden" name="site_id" value="<?= $siteId ?>">
                            <input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>">
                            <button class="btn small" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    <form method="post" style="margin-top:12px">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="add_rule">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <div class="row">
            <div class="field">
                <label for="pattern">New pattern</label>
                <input type="text" id="pattern" name="pattern" placeholder="*/tag/*">
            </div>
            <div><button class="btn" type="submit">Add rule</button></div>
        </div>
    </form>
</div>

<?php if ($preview !== null): ?>
    <h2>2. Choose what to track</h2>
    <?php if ($notes || $sitemaps): ?>
        <div class="card tight small muted">
            <?php foreach ($notes as $note): ?><div><?= Helpers::h($note) ?></div><?php endforeach; ?>
            <?php foreach ($sitemaps as $sitemap): ?><div>Read: <?= Helpers::h($sitemap) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!$preview): ?>
        <div class="card"><div class="empty">The sitemap held no usable URLs.</div></div>
    <?php else: ?>
    <form method="post" id="import-form">
        <?= Helpers::csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="site_id" value="<?= $siteId ?>">
        <input type="hidden" name="token" value="<?= Helpers::h($token) ?>">
        <input type="hidden" name="selected_csv" id="selected_csv" value="">
        <div class="card">
            <div class="row" style="margin-bottom:14px">
                <div class="field">
                    <label>What should we track?</label>
                    <label class="choice">
                        <input type="radio" name="select_mode" value="rules" checked style="width:auto">
                        Everything except the exclusion rules above
                    </label>
                    <label class="choice">
                        <input type="radio" name="select_mode" value="custom" style="width:auto">
                        Only the URLs I tick below
                    </label>
                </div>
                <div class="actions">
                    <button class="btn small" type="button" data-select="all">Tick all</button>
                    <button class="btn small" type="button" data-select="none">Untick all</button>
                    <input type="text" id="filter" placeholder="Filter URLs…" style="width:200px">
                </div>
            </div>

            <div class="table-wrap" style="max-height:520px;overflow:auto">
            <table id="preview-table">
                <thead><tr>
                    <th class="checkbox-cell"></th><th>URL</th><th>Last modified</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview as $index => $row): ?>
                    <tr data-url="<?= Helpers::h(strtolower($row['loc'])) ?>">
                        <td class="checkbox-cell">
                            <input type="checkbox" name="selected[]" value="<?= $index ?>"
                                   data-idx="<?= $index ?>" <?= $row['tracked'] ? 'checked' : '' ?>>
                        </td>
                        <td class="url-cell"><?= Helpers::h(Helpers::pathOf($row['loc'])) ?>
                            <div class="small muted"><?= Helpers::h($row['loc']) ?></div></td>
                        <td class="small muted"><?= Helpers::h($row['lastmod'] ?? '—') ?></td>
                        <td class="small">
                            <?php if ($row['excluded_by'] !== null): ?>
                                <span class="pill excluded">Rule: <?= Helpers::h($row['excluded_by']) ?></span>
                            <?php elseif ($row['known']): ?>
                                <span class="pill">Already known</span>
                            <?php else: ?>
                                <span class="pill tracked">New</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="sticky-bar">
                <button class="btn primary" type="submit">Import <?= count($preview) ?> URL(s)</button>
                <span class="small muted"><span id="tick-count"><?= count(array_filter($preview, static fn ($r) => $r['tracked'])) ?></span> ticked
                    · unticked URLs are stored but never audited</span>
            </div>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('import-form');
        var table = document.getElementById('preview-table');
        var boxes = Array.prototype.slice.call(table.querySelectorAll('input[type=checkbox]'));
        var count = document.getElementById('tick-count');

        function refresh() {
            count.textContent = boxes.filter(function (b) { return b.checked; }).length;
        }
        table.addEventListener('change', refresh);

        document.querySelectorAll('[data-select]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var on = btn.getAttribute('data-select') === 'all';
                boxes.forEach(function (box) {
                    if (box.closest('tr').style.display !== 'none') { box.checked = on; }
                });
                document.querySelector('input[name=select_mode][value=custom]').checked = true;
                refresh();
            });
        });

        var filter = document.getElementById('filter');
        filter.addEventListener('input', function () {
            var needle = filter.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                tr.style.display = !needle || tr.getAttribute('data-url').indexOf(needle) !== -1 ? '' : 'none';
            });
        });

        /* Post the ticked indexes as one field: a big sitemap would otherwise
           blow past PHP's max_input_vars and silently lose selections. */
        form.addEventListener('submit', function () {
            document.getElementById('selected_csv').value = boxes
                .filter(function (b) { return b.checked; })
                .map(function (b) { return b.getAttribute('data-idx'); })
                .join(',');
            boxes.forEach(function (b) { b.removeAttribute('name'); });
        });
    })();
    </script>
    <?php endif; ?>
<?php endif; ?>
<?php require WVA_ROOT . '/src/views/footer.php'; ?>
