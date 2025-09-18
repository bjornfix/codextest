<?php
declare(strict_types=1);

/**
 * Corporate tax and foundation intelligence dashboard.
 *
 * This PHP implementation serves data from flat JSON files and renders a
 * responsive web experience without external dependencies beyond Chart.js.
 */

const DATA_DIRECTORY = __DIR__ . '/data/jurisdictions';

/**
 * Load the jurisdiction dataset from disk.
 *
 * @return array<int, array<string, mixed>>
 */
function load_jurisdictions(): array
{
    $records = [];

    if (!ensure_data_directory()) {
        return $records;
    }

    $files = jurisdiction_file_paths();

    if ($files !== []) {
        foreach ($files as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $records[] = $entry;
                }
            }
        }
    }

    if ($records === []) {
        $legacyPath = __DIR__ . '/data/jurisdictions.json';
        if (is_file($legacyPath)) {
            $contents = file_get_contents($legacyPath);
            if ($contents !== false) {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $entry) {
                        if (is_array($entry)) {
                            $records[] = $entry;
                        }
                    }
                }
            }
        }
    }

    usort($records, static fn (array $a, array $b): int => strcasecmp((string) ($a['country'] ?? ''), (string) ($b['country'] ?? '')));

    return $records;
}

/**
 * Ensure the jurisdiction storage directory exists.
 */
function ensure_data_directory(): bool
{
    if (is_dir(DATA_DIRECTORY)) {
        return true;
    }

    if (mkdir(DATA_DIRECTORY, 0775, true)) {
        return true;
    }

    return is_dir(DATA_DIRECTORY);
}

/**
 * Normalise a region name into the corresponding JSON filename slug.
 */
function region_slug(string $region): string
{
    $slug = strtolower($region);
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    if ($slug === null) {
        $slug = '';
    }
    $slug = trim($slug, '-');

    if ($slug === '') {
        return 'region';
    }

    return $slug;
}

/**
 * Resolve the on-disk path for a given region file.
 */
function region_file_path(string $region): string
{
    return DATA_DIRECTORY . '/' . region_slug($region) . '.json';
}

/**
 * Enumerate jurisdiction JSON files on disk in a deterministic order.
 *
 * @return array<int, string>
 */
function jurisdiction_file_paths(): array
{
    if (!is_dir(DATA_DIRECTORY)) {
        return [];
    }

    $paths = glob(DATA_DIRECTORY . '/*.json');
    if ($paths === false) {
        return [];
    }

    sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

    return $paths;
}

/**
 * Fetch the dataset update token from the environment when configured.
 */
function dataset_update_token(): ?string
{
    $token = getenv('DATASET_UPDATE_TOKEN');
    if (is_string($token) && $token !== '') {
        return $token;
    }

    return null;
}

/**
 * Determine whether in-browser dataset updates are permitted.
 */
function dataset_updates_enabled(): bool
{
    return dataset_update_token() !== null;
}

/**
 * Provide default form values, optionally seeded from a jurisdiction record.
 *
 * @param array<string, mixed>|null $jurisdiction
 * @return array<string, string>
 */
function default_form_values(?array $jurisdiction = null): array
{
    return [
        'country' => (string) ($jurisdiction['country'] ?? ''),
        'original_country' => (string) ($jurisdiction['country'] ?? ''),
        'region' => (string) ($jurisdiction['region'] ?? ''),
        'corporate_tax_rate' => isset($jurisdiction['corporate_tax_rate']) ? (string) $jurisdiction['corporate_tax_rate'] : '',
        'operating_cost_index' => isset($jurisdiction['operating_cost_index']) ? (string) $jurisdiction['operating_cost_index'] : '',
        'employer_social_security_rate' => isset($jurisdiction['employer_social_security_rate']) ? (string) $jurisdiction['employer_social_security_rate'] : '',
        'incorporation_fees_usd' => isset($jurisdiction['incorporation_fees_usd']) ? (string) $jurisdiction['incorporation_fees_usd'] : '',
        'annual_filing_cost_usd' => isset($jurisdiction['annual_filing_cost_usd']) ? (string) $jurisdiction['annual_filing_cost_usd'] : '',
        'treaty_network_strength' => (string) ($jurisdiction['treaty_network_strength'] ?? ''),
        'compliance_burden' => (string) ($jurisdiction['compliance_burden'] ?? ''),
        'reputation_risk' => (string) ($jurisdiction['reputation_risk'] ?? ''),
        'incentives' => implode("\n", $jurisdiction['incentives'] ?? []),
        'notes' => implode("\n", $jurisdiction['notes'] ?? []),
        'foundation_availability' => (string) ($jurisdiction['foundation_terms']['availability'] ?? ''),
        'foundation_control' => (string) ($jurisdiction['foundation_terms']['control_requirements'] ?? ''),
        'foundation_reporting' => (string) ($jurisdiction['foundation_terms']['reporting'] ?? ''),
        'foundation_substance' => (string) ($jurisdiction['foundation_terms']['substance_requirements'] ?? ''),
        'foundation_friendly_score' => isset($jurisdiction['foundation_terms']['friendly_score']) ? (string) $jurisdiction['foundation_terms']['friendly_score'] : '',
        'foundation_notes' => implode("\n", $jurisdiction['foundation_terms']['notes'] ?? []),
        'token' => '',
    ];
}

/**
 * Convert a newline-delimited textarea value into a trimmed array.
 *
 * @return array<int, string>
 */
function parse_multiline_field(?string $value): array
{
    if ($value === null || $value === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

    return array_values(array_filter(array_map(static function (string $line): string {
        return trim($line);
    }, $lines), static function (string $line): bool {
        return $line !== '';
    }));
}

/**
 * Encode the jurisdiction dataset using two-space indentation.
 */
function encode_jurisdictions(array $jurisdictions): ?string
{
    $json = json_encode($jurisdictions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        return null;
    }

    $json = preg_replace_callback('/^( +)/m', static function (array $matches): string {
        $spaces = strlen($matches[1]);
        $level = (int) ($spaces / 4);

        return str_repeat('  ', $level);
    }, $json);

    if ($json === null) {
        return null;
    }

    return $json . "\n";
}

/**
 * Persist the jurisdiction dataset back to disk.
 *
 * @param array<int, array<string, mixed>> $jurisdictions
 */
function save_jurisdictions(array $jurisdictions): bool
{
    if (!ensure_data_directory()) {
        return false;
    }

    $existingFiles = jurisdiction_file_paths();
    $writtenFiles = [];

    $grouped = [];
    foreach ($jurisdictions as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $region = trim((string) ($entry['region'] ?? ''));
        if ($region === '') {
            $region = 'Global';
        }

        $grouped[$region][] = $entry;
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($grouped as $region => $entries) {
        usort($entries, static fn (array $a, array $b): int => strcasecmp((string) ($a['country'] ?? ''), (string) ($b['country'] ?? '')));

        $encoded = encode_jurisdictions($entries);
        if ($encoded === null) {
            return false;
        }

        $path = region_file_path($region);
        if (file_put_contents($path, $encoded) === false) {
            return false;
        }

        $writtenFiles[] = $path;
    }

    foreach ($existingFiles as $path) {
        if (!in_array($path, $writtenFiles, true)) {
            @unlink($path);
        }
    }

    $legacyPath = __DIR__ . '/data/jurisdictions.json';
    $encodedAll = encode_jurisdictions($jurisdictions);
    if ($encodedAll === null) {
        return false;
    }

    if (file_put_contents($legacyPath, $encodedAll) === false) {
        return false;
    }

    return true;
}

/**
 * Validate and handle dataset submissions from the management form.
 *
 * @param array<string, mixed> $post
 * @param array<int, array<string, mixed>> $jurisdictions
 * @return array{status: string, message: string, values: array<string, string>, jurisdictions: array<int, array<string, mixed>>, country: string|null}
 */
function handle_dataset_submission(array $post, array $jurisdictions): array
{
    $values = default_form_values();

    $values['country'] = trim((string) ($post['country'] ?? ''));
    $values['original_country'] = trim((string) ($post['original_country'] ?? $values['country']));
    $values['region'] = trim((string) ($post['region'] ?? ''));
    $values['corporate_tax_rate'] = trim((string) ($post['corporate_tax_rate'] ?? ''));
    $values['operating_cost_index'] = trim((string) ($post['operating_cost_index'] ?? ''));
    $values['employer_social_security_rate'] = trim((string) ($post['employer_social_security_rate'] ?? ''));
    $values['incorporation_fees_usd'] = trim((string) ($post['incorporation_fees_usd'] ?? ''));
    $values['annual_filing_cost_usd'] = trim((string) ($post['annual_filing_cost_usd'] ?? ''));
    $values['treaty_network_strength'] = trim((string) ($post['treaty_network_strength'] ?? ''));
    $values['compliance_burden'] = trim((string) ($post['compliance_burden'] ?? ''));
    $values['reputation_risk'] = trim((string) ($post['reputation_risk'] ?? ''));
    $values['incentives'] = (string) ($post['incentives'] ?? '');
    $values['notes'] = (string) ($post['notes'] ?? '');
    $values['foundation_availability'] = trim((string) ($post['foundation_availability'] ?? ''));
    $values['foundation_control'] = trim((string) ($post['foundation_control'] ?? ''));
    $values['foundation_reporting'] = trim((string) ($post['foundation_reporting'] ?? ''));
    $values['foundation_substance'] = trim((string) ($post['foundation_substance'] ?? ''));
    $values['foundation_friendly_score'] = trim((string) ($post['foundation_friendly_score'] ?? ''));
    $values['foundation_notes'] = (string) ($post['foundation_notes'] ?? '');
    $values['token'] = '';

    $expectedToken = dataset_update_token();
    if ($expectedToken === null) {
        return [
            'status' => 'error',
            'message' => 'Dataset updates are disabled. Configure DATASET_UPDATE_TOKEN on the server to enable saving.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    $providedToken = trim((string) ($post['token'] ?? ''));
    if ($providedToken === '') {
        return [
            'status' => 'error',
            'message' => 'Enter the dataset update token to save changes.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    if (!hash_equals($expectedToken, $providedToken)) {
        return [
            'status' => 'error',
            'message' => 'The provided dataset update token is not valid.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    if ($values['country'] === '' || $values['region'] === '') {
        return [
            'status' => 'error',
            'message' => 'Country and region are required to save a jurisdiction.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    $numericFields = [
        'corporate_tax_rate' => ['min' => 0.0],
        'operating_cost_index' => ['min' => 0.0],
        'employer_social_security_rate' => ['min' => 0.0],
        'incorporation_fees_usd' => ['min' => 0.0],
        'annual_filing_cost_usd' => ['min' => 0.0],
    ];

    $numericValues = [];
    foreach ($numericFields as $field => $rules) {
        $raw = $values[$field];
        if ($raw === '' || !is_numeric($raw)) {
            return [
                'status' => 'error',
                'message' => 'Provide numeric values for tax, cost, and fee fields.',
                'values' => $values,
                'jurisdictions' => $jurisdictions,
                'country' => null,
            ];
        }

        $numeric = (float) $raw;
        if (isset($rules['min']) && $numeric < $rules['min']) {
            return [
                'status' => 'error',
                'message' => 'Numeric fields must be greater than or equal to zero.',
                'values' => $values,
                'jurisdictions' => $jurisdictions,
                'country' => null,
            ];
        }

        $numericValues[$field] = $numeric;
    }

    $friendlyScoreRaw = $values['foundation_friendly_score'];
    if ($friendlyScoreRaw === '' || !is_numeric($friendlyScoreRaw)) {
        return [
            'status' => 'error',
            'message' => 'Provide a foundation friendliness score between 0 and 5.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    $friendlyScore = (int) round((float) $friendlyScoreRaw);
    $friendlyScore = max(0, min(5, $friendlyScore));

    $entry = [
        'country' => $values['country'],
        'region' => $values['region'],
        'corporate_tax_rate' => $numericValues['corporate_tax_rate'],
        'operating_cost_index' => $numericValues['operating_cost_index'],
        'employer_social_security_rate' => $numericValues['employer_social_security_rate'],
        'incorporation_fees_usd' => $numericValues['incorporation_fees_usd'],
        'annual_filing_cost_usd' => $numericValues['annual_filing_cost_usd'],
        'treaty_network_strength' => $values['treaty_network_strength'],
        'compliance_burden' => $values['compliance_burden'],
        'reputation_risk' => $values['reputation_risk'],
        'incentives' => parse_multiline_field($values['incentives']),
        'notes' => parse_multiline_field($values['notes']),
        'foundation_terms' => [
            'availability' => $values['foundation_availability'],
            'control_requirements' => $values['foundation_control'],
            'reporting' => $values['foundation_reporting'],
            'substance_requirements' => $values['foundation_substance'],
            'notes' => parse_multiline_field($values['foundation_notes']),
            'friendly_score' => $friendlyScore,
        ],
    ];

    $found = false;
    $original = $values['original_country'];
    foreach ($jurisdictions as $index => $existing) {
        if ($original !== '' && strcasecmp((string) $existing['country'], $original) === 0) {
            $jurisdictions[$index] = $entry;
            $found = true;
            break;
        }
        if (strcasecmp((string) $existing['country'], $entry['country']) === 0) {
            $jurisdictions[$index] = $entry;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $jurisdictions[] = $entry;
    }

    usort($jurisdictions, static fn (array $a, array $b): int => strcasecmp((string) $a['country'], (string) $b['country']));

    if (!save_jurisdictions($jurisdictions)) {
        return [
            'status' => 'error',
            'message' => 'Failed to write region JSON files in data/jurisdictions. Check file permissions and try again.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => null,
        ];
    }

    $values = default_form_values($entry);
    $values['token'] = '';

    return [
        'status' => 'success',
        'message' => 'Jurisdiction saved successfully. Regional JSON files under data/jurisdictions are now updated.',
        'values' => $values,
        'jurisdictions' => $jurisdictions,
        'country' => $entry['country'],
    ];
}

/**
 * HTML escape helper.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Convert GET parameter to float if present.
 */
function float_from_query(string $key): ?float
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    return (float) $_GET[$key];
}

/**
 * Convert GET parameter to int if present.
 */
function int_from_query(string $key): ?int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') {
        return null;
    }

    return (int) $_GET[$key];
}

/**
 * Filter jurisdictions based on query parameters.
 *
 * @param array<int, array<string, mixed>> $jurisdictions
 * @param array<string, mixed> $filters
 * @return array<int, array<string, mixed>>
 */
function filter_jurisdictions(array $jurisdictions, array $filters): array
{
    return array_values(array_filter($jurisdictions, function (array $item) use ($filters): bool {
        if ($filters['region'] !== null && $filters['region'] !== '' && strtolower($filters['region']) !== 'all') {
            if (strtolower($item['region']) !== strtolower((string) $filters['region'])) {
                return false;
            }
        }

        if ($filters['query'] !== null && $filters['query'] !== '') {
            $needle = strtolower((string) $filters['query']);
            $haystacks = [
                strtolower((string) $item['country']),
                strtolower((string) $item['region']),
                strtolower(implode(' ', $item['incentives'] ?? [])),
                strtolower(implode(' ', $item['notes'] ?? [])),
                strtolower((string) ($item['foundation_terms']['availability'] ?? '')),
            ];
            $found = false;
            foreach ($haystacks as $haystack) {
                if (strpos($haystack, $needle) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        if ($filters['max_tax'] !== null && (float) $item['corporate_tax_rate'] > $filters['max_tax']) {
            return false;
        }

        if ($filters['max_cost'] !== null && (float) $item['operating_cost_index'] > $filters['max_cost']) {
            return false;
        }

        if ($filters['max_social'] !== null && (float) $item['employer_social_security_rate'] > $filters['max_social']) {
            return false;
        }

        if ($filters['max_incorporation'] !== null && (float) $item['incorporation_fees_usd'] > $filters['max_incorporation']) {
            return false;
        }

        if ($filters['min_foundation'] !== null) {
            $score = (int) ($item['foundation_terms']['friendly_score'] ?? 0);
            if ($score < $filters['min_foundation']) {
                return false;
            }
        }

        return true;
    }));
}

/**
 * Compute region summaries with average metrics.
 *
 * @param array<int, array<string, mixed>> $jurisdictions
 * @return array<string, array<string, float|int>>
 */
function summarize_regions(array $jurisdictions): array
{
    $regions = [];
    foreach ($jurisdictions as $item) {
        $region = (string) $item['region'];
        if (!isset($regions[$region])) {
            $regions[$region] = [
                'count' => 0,
                'tax_total' => 0.0,
                'cost_total' => 0.0,
                'foundation_total' => 0,
            ];
        }

        $regions[$region]['count']++;
        $regions[$region]['tax_total'] += (float) $item['corporate_tax_rate'];
        $regions[$region]['cost_total'] += (float) $item['operating_cost_index'];
        $regions[$region]['foundation_total'] += (int) ($item['foundation_terms']['friendly_score'] ?? 0);
    }

    ksort($regions);

    foreach ($regions as $name => $stats) {
        $count = max(1, (int) $stats['count']);
        $regions[$name]['avg_tax'] = $stats['tax_total'] / $count;
        $regions[$name]['avg_cost'] = $stats['cost_total'] / $count;
        $regions[$name]['avg_foundation'] = $stats['foundation_total'] / $count;
    }

    return $regions;
}

/**
 * Build dataset for the overview chart.
 *
 * @param array<int, array<string, mixed>> $jurisdictions
 * @return array<int, array<string, float|int|string>>
 */
function chart_data(array $jurisdictions): array
{
    $rows = [];
    foreach ($jurisdictions as $item) {
        $rows[] = [
            'country' => (string) $item['country'],
            'corporate_tax_rate' => (float) $item['corporate_tax_rate'],
            'friendly_score' => (int) ($item['foundation_terms']['friendly_score'] ?? 0),
            'operating_cost_index' => (int) $item['operating_cost_index'],
        ];
    }

    usort($rows, fn ($a, $b) => $a['corporate_tax_rate'] <=> $b['corporate_tax_rate']);

    return array_slice($rows, 0, 12);
}

/**
 * Format a currency value.
 */
function format_currency(float $value): string
{
    return '$' . number_format($value, 0, '.', ',');
}

/**
 * Render a friendly score as stars.
 */
function render_stars(int $score): string
{
    $score = max(0, min(5, $score));
    return str_repeat('★', $score) . str_repeat('☆', 5 - $score);
}

$jurisdictions = load_jurisdictions();
$updates_enabled = dataset_updates_enabled();

$status_type = null;
$status_message = null;
$form_values = default_form_values();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission = handle_dataset_submission($_POST, $jurisdictions);
    $status_type = $submission['status'];
    $status_message = $submission['message'];
    $form_values = $submission['values'];
    $jurisdictions = $submission['jurisdictions'];

    if ($status_type === 'success') {
        $query = $_GET;
        $query['status'] = 'saved';
        if (!empty($submission['country'])) {
            $query['country'] = $submission['country'];
        }

        header('Location: index.php?' . http_build_query($query) . '#manage');
        exit;
    }
}

$selected_country = isset($_GET['country']) ? trim((string) $_GET['country']) : '';
if ($selected_country !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($jurisdictions as $item) {
        if (strcasecmp((string) $item['country'], $selected_country) === 0) {
            $form_values = default_form_values($item);
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $status_type !== 'success') {
    $selected_country = $form_values['original_country'] !== '' ? $form_values['original_country'] : $form_values['country'];
}

if ($status_type === null && isset($_GET['status']) && $_GET['status'] === 'saved') {
    $status_type = 'success';
    $status_message = 'Jurisdiction saved. Regional JSON files are up to date.';
}

$region_options = array_values(array_unique(array_map(fn ($item) => (string) $item['region'], $jurisdictions)));
sort($region_options);

$filters = [
    'query' => isset($_GET['query']) ? trim((string) $_GET['query']) : null,
    'region' => isset($_GET['region']) ? trim((string) $_GET['region']) : null,
    'max_tax' => float_from_query('max_tax'),
    'max_cost' => float_from_query('max_cost'),
    'max_social' => float_from_query('max_social'),
    'max_incorporation' => float_from_query('max_incorporation'),
    'min_foundation' => int_from_query('min_foundation'),
];

$filtered_jurisdictions = filter_jurisdictions($jurisdictions, $filters);
$regions = summarize_regions($jurisdictions);
$chart_rows = chart_data($jurisdictions);

$detail_name = isset($_GET['detail']) ? trim((string) $_GET['detail']) : '';
$detail_entry = null;
foreach ($jurisdictions as $item) {
    if (strcasecmp($item['country'], $detail_name) === 0) {
        $detail_entry = $item;
        break;
    }
}

$hero_stats = [
    'lowest_tax' => null,
    'lowest_cost' => null,
    'top_foundation' => null,
];

foreach ($jurisdictions as $item) {
    if ($hero_stats['lowest_tax'] === null || $item['corporate_tax_rate'] < $hero_stats['lowest_tax']['corporate_tax_rate']) {
        $hero_stats['lowest_tax'] = $item;
    }
    if ($hero_stats['lowest_cost'] === null || $item['operating_cost_index'] < $hero_stats['lowest_cost']['operating_cost_index']) {
        $hero_stats['lowest_cost'] = $item;
    }
    if ($hero_stats['top_foundation'] === null || ($item['foundation_terms']['friendly_score'] ?? 0) > ($hero_stats['top_foundation']['foundation_terms']['friendly_score'] ?? -1)) {
        $hero_stats['top_foundation'] = $item;
    }
}

$chart_json = '[]';
try {
    $chart_json = json_encode($chart_rows, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Keep fallback empty dataset if encoding fails.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Corporate Tax &amp; Foundation Intelligence</title>
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script>
        window.chartData = <?php echo $chart_json; ?>;
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1>Corporate Tax &amp; Foundation Intelligence Hub</h1>
            <p class="tagline">Compare global jurisdictions across corporate tax rates, running costs, and foundation ownership terms with a responsive, flat-file dashboard.</p>
            <div class="header-actions">
                <a class="btn secondary" href="#manage" data-focus-target="manage" <?php echo $updates_enabled ? '' : 'aria-disabled="true"'; ?>>Manage dataset</a>
            </div>
            <?php if (!$updates_enabled): ?>
                <p class="header-helper">To unlock in-app edits, set <code>DATASET_UPDATE_TOKEN</code> in your server environment. The form stays read-only until a matching token is provided.</p>
            <?php endif; ?>
            <div class="hero-grid">
                <?php if ($hero_stats['lowest_tax'] !== null): ?>
                    <div class="hero-card">
                        <h2>Lowest corporate tax</h2>
                        <p class="hero-metric"><?php echo e(number_format((float) $hero_stats['lowest_tax']['corporate_tax_rate'], 1)); ?>%</p>
                        <p class="hero-caption"><?php echo e($hero_stats['lowest_tax']['country']); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($hero_stats['lowest_cost'] !== null): ?>
                    <div class="hero-card">
                        <h2>Leanest operating cost</h2>
                        <p class="hero-metric"><?php echo e((string) $hero_stats['lowest_cost']['operating_cost_index']); ?></p>
                        <p class="hero-caption"><?php echo e($hero_stats['lowest_cost']['country']); ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($hero_stats['top_foundation'] !== null): ?>
                    <div class="hero-card">
                        <h2>Foundation friendly</h2>
                        <p class="hero-metric"><?php echo e(render_stars((int) ($hero_stats['top_foundation']['foundation_terms']['friendly_score'] ?? 0))); ?></p>
                        <p class="hero-caption"><?php echo e($hero_stats['top_foundation']['country']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="section" id="overview">
            <div class="container">
                <div class="section-header">
                    <h2>Global overview</h2>
                    <p>Quickly spot attractive jurisdictions with the blended view of corporate tax burden, operating expenditure, and foundation flexibility. Each card is mobile-friendly, making strategic reviews effortless across devices.</p>
                </div>
                <div class="chart-wrapper">
                    <canvas id="taxChart" aria-label="Corporate tax and foundation scores by jurisdiction" role="img"></canvas>
                </div>
            </div>
        </section>

        <section class="section alt" id="regions">
            <div class="container">
                <div class="section-header">
                    <h2>Regional temperature check</h2>
                    <p>Compare average corporate tax rates, operating cost indices, and foundation friendliness by region.</p>
                </div>
                <div class="region-grid">
                    <?php foreach ($regions as $region => $stats): ?>
                        <article class="region-card">
                            <h3><?php echo e($region); ?></h3>
                            <dl>
                                <div>
                                    <dt>Jurisdictions tracked</dt>
                                    <dd><?php echo e((string) $stats['count']); ?></dd>
                                </div>
                                <div>
                                    <dt>Average corporate tax</dt>
                                    <dd><?php echo e(number_format((float) $stats['avg_tax'], 1)); ?>%</dd>
                                </div>
                                <div>
                                    <dt>Average operating cost index</dt>
                                    <dd><?php echo e(number_format((float) $stats['avg_cost'], 0)); ?></dd>
                                </div>
                                <div>
                                    <dt>Average foundation friendliness</dt>
                                    <dd><?php echo e(number_format((float) $stats['avg_foundation'], 1)); ?>/5</dd>
                                </div>
                            </dl>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section" id="filters">
            <div class="container">
                <div class="section-header">
                    <h2>Find your next launchpad</h2>
                    <p>Use flexible filters to shortlist jurisdictions that match your tax, cost, and foundation governance requirements. Results update instantly based on the flat-file dataset.</p>
                </div>
                <form class="filter-form" method="get" action="#results">
                    <div class="form-grid">
                        <label>
                            <span>Keyword</span>
                            <input type="search" name="query" value="<?php echo e($filters['query'] ?? ''); ?>" placeholder="Country, incentive, or note">
                        </label>
                        <label>
                            <span>Region</span>
                            <select name="region">
                                <option value="all">All regions</option>
                                <?php foreach ($region_options as $region): ?>
                                    <option value="<?php echo e($region); ?>" <?php echo ($filters['region'] === $region) ? 'selected' : ''; ?>><?php echo e($region); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Max corporate tax (%)</span>
                            <input type="number" name="max_tax" step="0.1" min="0" value="<?php echo $filters['max_tax'] !== null ? e((string) $filters['max_tax']) : ''; ?>">
                        </label>
                        <label>
                            <span>Max operating cost index</span>
                            <input type="number" name="max_cost" step="1" min="0" value="<?php echo $filters['max_cost'] !== null ? e((string) $filters['max_cost']) : ''; ?>">
                        </label>
                        <label>
                            <span>Max employer social security (%)</span>
                            <input type="number" name="max_social" step="0.1" min="0" value="<?php echo $filters['max_social'] !== null ? e((string) $filters['max_social']) : ''; ?>">
                        </label>
                        <label>
                            <span>Max incorporation fees (USD)</span>
                            <input type="number" name="max_incorporation" step="100" min="0" value="<?php echo $filters['max_incorporation'] !== null ? e((string) $filters['max_incorporation']) : ''; ?>">
                        </label>
                        <label>
                            <span>Min foundation friendliness</span>
                            <input type="number" name="min_foundation" min="0" max="5" value="<?php echo $filters['min_foundation'] !== null ? e((string) $filters['min_foundation']) : ''; ?>">
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn primary">Apply filters</button>
                        <a class="btn" href="index.php">Clear</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="section alt" id="results">
            <div class="container">
                <div class="section-header">
                    <h2>Jurisdiction short list</h2>
                    <p><?php echo e((string) count($filtered_jurisdictions)); ?> jurisdictions match your criteria.</p>
                </div>
                <div class="card-grid">
                    <?php foreach ($filtered_jurisdictions as $item): ?>
                        <article class="jurisdiction-card">
                            <header>
                                <h3><?php echo e($item['country']); ?></h3>
                                <p class="region-label"><?php echo e($item['region']); ?></p>
                            </header>
                            <dl>
                                <div>
                                    <dt>Corporate tax</dt>
                                    <dd><?php echo e(number_format((float) $item['corporate_tax_rate'], 1)); ?>%</dd>
                                </div>
                                <div>
                                    <dt>Operating cost index</dt>
                                    <dd><?php echo e((string) $item['operating_cost_index']); ?></dd>
                                </div>
                                <div>
                                    <dt>Employer social security</dt>
                                    <dd><?php echo e(number_format((float) $item['employer_social_security_rate'], 1)); ?>%</dd>
                                </div>
                                <div>
                                    <dt>Foundation friendliness</dt>
                                    <dd><?php echo e(render_stars((int) ($item['foundation_terms']['friendly_score'] ?? 0))); ?></dd>
                                </div>
                            </dl>
                            <ul class="list">
                                <?php foreach (array_slice($item['incentives'] ?? [], 0, 2) as $incentive): ?>
                                    <li><?php echo e($incentive); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php
                                $link_params = $_GET;
                                unset($link_params['detail']);
                                $link_params['detail'] = $item['country'];
                                $detail_url = 'index.php?' . http_build_query($link_params);
                            ?>
                            <div class="card-actions">
                                <a class="btn secondary" href="<?php echo e($detail_url); ?>#detail">View details</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section" id="detail">
            <div class="container">
                <div class="section-header">
                    <h2>Jurisdiction deep dive</h2>
                    <?php if ($detail_entry === null): ?>
                        <p>Select a jurisdiction to see detailed tax, compliance, and foundation considerations.</p>
                    <?php else: ?>
                        <p>Full profile for <?php echo e($detail_entry['country']); ?> including compliance costs and foundation governance.</p>
                    <?php endif; ?>
                </div>
                <?php if ($detail_entry !== null): ?>
                    <article class="detail-card">
                        <header>
                            <h3><?php echo e($detail_entry['country']); ?></h3>
                            <p class="region-label"><?php echo e($detail_entry['region']); ?></p>
                        </header>
                        <div class="detail-grid">
                            <div>
                                <h4>Tax &amp; cost snapshot</h4>
                                <ul>
                                    <li><strong>Corporate tax:</strong> <?php echo e(number_format((float) $detail_entry['corporate_tax_rate'], 1)); ?>%</li>
                                    <li><strong>Employer social security:</strong> <?php echo e(number_format((float) $detail_entry['employer_social_security_rate'], 1)); ?>%</li>
                                    <li><strong>Operating cost index:</strong> <?php echo e((string) $detail_entry['operating_cost_index']); ?></li>
                                    <li><strong>Incorporation fees:</strong> <?php echo e(format_currency((float) $detail_entry['incorporation_fees_usd'])); ?></li>
                                    <li><strong>Annual filing cost:</strong> <?php echo e(format_currency((float) $detail_entry['annual_filing_cost_usd'])); ?></li>
                                </ul>
                            </div>
                            <div>
                                <h4>Compliance profile</h4>
                                <ul>
                                    <li><strong>Treaty network:</strong> <?php echo e($detail_entry['treaty_network_strength']); ?></li>
                                    <li><strong>Compliance burden:</strong> <?php echo e($detail_entry['compliance_burden']); ?></li>
                                    <li><strong>Reputation risk:</strong> <?php echo e($detail_entry['reputation_risk']); ?></li>
                                </ul>
                                <h4>Key incentives</h4>
                                <ul>
                                    <?php foreach ($detail_entry['incentives'] as $incentive): ?>
                                        <li><?php echo e($incentive); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div>
                                <h4>Foundation governance</h4>
                                <ul>
                                    <li><strong>Availability:</strong> <?php echo e($detail_entry['foundation_terms']['availability']); ?></li>
                                    <li><strong>Control requirements:</strong> <?php echo e($detail_entry['foundation_terms']['control_requirements']); ?></li>
                                    <li><strong>Reporting:</strong> <?php echo e($detail_entry['foundation_terms']['reporting']); ?></li>
                                    <li><strong>Substance:</strong> <?php echo e($detail_entry['foundation_terms']['substance_requirements']); ?></li>
                                    <li><strong>Friendliness score:</strong> <?php echo e(render_stars((int) ($detail_entry['foundation_terms']['friendly_score'] ?? 0))); ?></li>
                                </ul>
                                <?php if (!empty($detail_entry['foundation_terms']['notes'])): ?>
                                    <h4>Foundation notes</h4>
                                    <ul>
                                        <?php foreach ($detail_entry['foundation_terms']['notes'] as $note): ?>
                                            <li><?php echo e($note); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($detail_entry['notes'])): ?>
                            <div class="detail-notes">
                                <h4>Operating notes</h4>
                                <ul>
                                    <?php foreach ($detail_entry['notes'] as $note): ?>
                                        <li><?php echo e($note); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endif; ?>
            </div>
        </section>

        <section class="section alt" id="manage">
            <div class="container">
                <div class="section-header">
                    <h2>Manage dataset</h2>
                    <p>Refresh the flat-file dataset directly in the browser. Provide the configured token to save updates securely.</p>
                </div>
                <?php if ($status_message !== null): ?>
                    <div class="status-banner status-<?php echo $status_type === 'success' ? 'success' : 'error'; ?>"><?php echo e($status_message); ?></div>
                <?php endif; ?>
                <?php if (!$updates_enabled): ?>
                    <div class="status-banner status-warning">Dataset edits are disabled until <code>DATASET_UPDATE_TOKEN</code> is set on the server.</div>
                <?php endif; ?>
                <form class="manage-selector" method="get">
                    <label>
                        <span>Load existing jurisdiction</span>
                        <select name="country">
                            <option value="">Add new jurisdiction</option>
                            <?php foreach ($jurisdictions as $item): ?>
                                <?php $is_selected = ($selected_country !== '' && strcasecmp($selected_country, (string) $item['country']) === 0); ?>
                                <option value="<?php echo e($item['country']); ?>" <?php echo $is_selected ? 'selected' : ''; ?>><?php echo e($item['country']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn secondary">Load</button>
                </form>
                <form class="manage-form" id="manage-form" method="post" <?php echo $updates_enabled ? '' : 'aria-disabled="true"'; ?>>
                    <input type="hidden" name="original_country" value="<?php echo e($form_values['original_country']); ?>">
                    <div class="manage-columns">
                        <div class="manage-group">
                            <h3>Jurisdiction snapshot</h3>
                            <label>
                                <span>Country</span>
                                <input type="text" name="country" value="<?php echo e($form_values['country']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Region</span>
                                <input type="text" name="region" value="<?php echo e($form_values['region']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Corporate tax rate (%)</span>
                                <input type="number" name="corporate_tax_rate" step="0.1" min="0" value="<?php echo e($form_values['corporate_tax_rate']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Operating cost index</span>
                                <input type="number" name="operating_cost_index" step="1" min="0" value="<?php echo e($form_values['operating_cost_index']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Employer social security (%)</span>
                                <input type="number" name="employer_social_security_rate" step="0.1" min="0" value="<?php echo e($form_values['employer_social_security_rate']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Incorporation fees (USD)</span>
                                <input type="number" name="incorporation_fees_usd" step="100" min="0" value="<?php echo e($form_values['incorporation_fees_usd']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Annual filing cost (USD)</span>
                                <input type="number" name="annual_filing_cost_usd" step="100" min="0" value="<?php echo e($form_values['annual_filing_cost_usd']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Treaty network strength</span>
                                <textarea name="treaty_network_strength" rows="3"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['treaty_network_strength']); ?></textarea>
                            </label>
                            <label>
                                <span>Compliance burden</span>
                                <input type="text" name="compliance_burden" value="<?php echo e($form_values['compliance_burden']); ?>"<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                            <label>
                                <span>Reputation risk</span>
                                <input type="text" name="reputation_risk" value="<?php echo e($form_values['reputation_risk']); ?>"<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                        </div>
                        <div class="manage-group">
                            <h3>Foundation terms</h3>
                            <label>
                                <span>Availability</span>
                                <textarea name="foundation_availability" rows="3"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['foundation_availability']); ?></textarea>
                            </label>
                            <label>
                                <span>Control requirements</span>
                                <textarea name="foundation_control" rows="3"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['foundation_control']); ?></textarea>
                            </label>
                            <label>
                                <span>Reporting</span>
                                <textarea name="foundation_reporting" rows="3"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['foundation_reporting']); ?></textarea>
                            </label>
                            <label>
                                <span>Substance requirements</span>
                                <textarea name="foundation_substance" rows="3"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['foundation_substance']); ?></textarea>
                            </label>
                            <label>
                                <span>Friendly score (0–5)</span>
                                <input type="number" name="foundation_friendly_score" min="0" max="5" step="1" value="<?php echo e($form_values['foundation_friendly_score']); ?>" required<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                            </label>
                        </div>
                    </div>
                    <div class="manage-group manage-notes">
                        <h3>Incentives &amp; notes</h3>
                        <label>
                            <span>Incentives (one per line)</span>
                            <textarea name="incentives" rows="4"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['incentives']); ?></textarea>
                        </label>
                        <label>
                            <span>Operating notes (one per line)</span>
                            <textarea name="notes" rows="4"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['notes']); ?></textarea>
                        </label>
                        <label>
                            <span>Foundation notes (one per line)</span>
                            <textarea name="foundation_notes" rows="4"<?php echo $updates_enabled ? '' : ' disabled'; ?>><?php echo e($form_values['foundation_notes']); ?></textarea>
                        </label>
                    </div>
                    <div class="manage-group manage-security">
                        <h3>Authentication</h3>
                        <label>
                            <span>Dataset update token</span>
                            <input type="password" name="token" autocomplete="off" placeholder="Enter DATASET_UPDATE_TOKEN"<?php echo $updates_enabled ? '' : ' disabled'; ?>>
                        </label>
                        <p class="manage-helper">Provide the token each time you save. It is verified server-side and never stored in the dataset.</p>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn primary"<?php echo $updates_enabled ? '' : ' disabled'; ?>>Save changes</button>
                    </div>
                </form>
            </div>
        </section>

    </main>

    <footer class="site-footer">
        <div class="container">
            <p>Data sourced from curated flat files. Update the JSON documents in <code>data/jurisdictions/</code> to refresh insights instantly.</p>
        </div>
    </footer>
</body>
</html>
