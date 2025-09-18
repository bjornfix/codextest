<?php
declare(strict_types=1);

/**
 * Corporate tax and foundation intelligence dashboard.
 *
 * This PHP implementation serves data from flat JSON files and renders a
 * responsive web experience without external dependencies beyond Chart.js.
 */

const DATA_PATH = __DIR__ . '/data/jurisdictions.json';

/**
 * Load the jurisdiction dataset from disk.
 *
 * @return array<int, array<string, mixed>>
 */
function load_jurisdictions(): array
{
    if (!file_exists(DATA_PATH)) {
        return [];
    }

    $contents = file_get_contents(DATA_PATH);
    if ($contents === false) {
        return [];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
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

/**
 * Convert a newline separated textarea input into an array of trimmed strings.
 *
 * @param string $value
 * @return array<int, string>
 */
function lines_to_array(string $value): array
{
    $parts = preg_split('/\r\n|\r|\n/', $value) ?: [];
    $result = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '') {
            $result[] = $trimmed;
        }
    }

    return $result;
}

/**
 * Default field values for the dataset management form.
 *
 * @return array<string, string>
 */
function default_form_values(): array
{
    return [
        'country' => '',
        'region' => '',
        'corporate_tax_rate' => '',
        'operating_cost_index' => '',
        'employer_social_security_rate' => '',
        'incorporation_fees_usd' => '',
        'annual_filing_cost_usd' => '',
        'treaty_network_strength' => '',
        'compliance_burden' => '',
        'reputation_risk' => '',
        'incentives' => '',
        'notes' => '',
        'foundation_availability' => '',
        'foundation_control' => '',
        'foundation_reporting' => '',
        'foundation_substance' => '',
        'foundation_notes' => '',
        'foundation_friendly_score' => '',
    ];
}

/**
 * Convert a jurisdiction entry into form-friendly string values.
 *
 * @param array<string, mixed> $entry
 * @return array<string, string>
 */
function entry_to_form_values(array $entry): array
{
    $values = default_form_values();
    $values['country'] = (string) ($entry['country'] ?? '');
    $values['region'] = (string) ($entry['region'] ?? '');
    $values['corporate_tax_rate'] = isset($entry['corporate_tax_rate']) ? (string) ((float) $entry['corporate_tax_rate']) : '';
    $values['operating_cost_index'] = isset($entry['operating_cost_index']) ? (string) ((int) $entry['operating_cost_index']) : '';
    $values['employer_social_security_rate'] = isset($entry['employer_social_security_rate']) ? (string) ((float) $entry['employer_social_security_rate']) : '';
    $values['incorporation_fees_usd'] = isset($entry['incorporation_fees_usd']) ? (string) ((int) $entry['incorporation_fees_usd']) : '';
    $values['annual_filing_cost_usd'] = isset($entry['annual_filing_cost_usd']) ? (string) ((int) $entry['annual_filing_cost_usd']) : '';
    $values['treaty_network_strength'] = (string) ($entry['treaty_network_strength'] ?? '');
    $values['compliance_burden'] = (string) ($entry['compliance_burden'] ?? '');
    $values['reputation_risk'] = (string) ($entry['reputation_risk'] ?? '');
    $values['incentives'] = isset($entry['incentives']) ? implode("\n", (array) $entry['incentives']) : '';
    $values['notes'] = isset($entry['notes']) ? implode("\n", (array) $entry['notes']) : '';

    $foundation = $entry['foundation_terms'] ?? [];
    if (is_array($foundation)) {
        $values['foundation_availability'] = (string) ($foundation['availability'] ?? '');
        $values['foundation_control'] = (string) ($foundation['control_requirements'] ?? '');
        $values['foundation_reporting'] = (string) ($foundation['reporting'] ?? '');
        $values['foundation_substance'] = (string) ($foundation['substance_requirements'] ?? '');
        $values['foundation_notes'] = isset($foundation['notes']) ? implode("\n", (array) $foundation['notes']) : '';
        $values['foundation_friendly_score'] = isset($foundation['friendly_score']) ? (string) ((int) $foundation['friendly_score']) : '';
    }

    return $values;
}

/**
 * Sanitize POSTed dataset form input into trimmed strings.
 *
 * @param array<string, mixed> $input
 * @return array<string, string>
 */
function sanitize_form_input(array $input): array
{
    $values = default_form_values();
    foreach ($values as $key => $_) {
        if (isset($input[$key])) {
            $values[$key] = trim((string) $input[$key]);
        }
    }

    return $values;
}

/**
 * Encode the dataset to JSON with two-space indentation.
 *
 * @param array<int, array<string, mixed>> $data
 */
function encode_dataset(array $data): string
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode dataset.');
    }

    return preg_replace_callback('/^( +)/m', function (array $matches): string {
        $spaces = strlen($matches[1]);
        return str_repeat(' ', intdiv($spaces, 4) * 2);
    }, $encoded) ?? $encoded;
}

/**
 * Handle dataset management submissions and persist updates.
 *
 * @param array<string, mixed> $input
 * @param array<int, array<string, mixed>> $jurisdictions
 * @return array{
 *     status: string,
 *     message: string,
 *     values: array<string, string>,
 *     jurisdictions: array<int, array<string, mixed>>,
 *     country: string
 * }
 */
function handle_dataset_submission(array $input, array $jurisdictions): array
{
    $values = sanitize_form_input($input);
    $errors = [];

    if ($values['country'] === '') {
        $errors[] = 'Country name is required.';
    }
    if ($values['region'] === '') {
        $errors[] = 'Region is required.';
    }

    $numeric_fields = [
        'corporate_tax_rate' => 'Corporate tax rate',
        'operating_cost_index' => 'Operating cost index',
        'employer_social_security_rate' => 'Employer social security rate',
        'incorporation_fees_usd' => 'Incorporation fees (USD)',
        'annual_filing_cost_usd' => 'Annual filing cost (USD)',
        'foundation_friendly_score' => 'Foundation friendly score',
    ];

    foreach ($numeric_fields as $field => $label) {
        if ($values[$field] === '') {
            $errors[] = $label . ' is required.';
            continue;
        }
        if (!is_numeric($values[$field])) {
            $errors[] = $label . ' must be numeric.';
        }
    }

    $required_text = [
        'treaty_network_strength' => 'Treaty network strength',
        'compliance_burden' => 'Compliance burden',
        'reputation_risk' => 'Reputation risk',
        'foundation_availability' => 'Foundation availability',
        'foundation_control' => 'Foundation control requirements',
        'foundation_reporting' => 'Foundation reporting',
        'foundation_substance' => 'Foundation substance requirements',
    ];

    foreach ($required_text as $field => $label) {
        if ($values[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }

    if ($errors !== []) {
        return [
            'status' => 'error',
            'message' => implode(' ', $errors),
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => $values['country'],
        ];
    }

    $entry = [
        'country' => $values['country'],
        'region' => $values['region'],
        'corporate_tax_rate' => (float) $values['corporate_tax_rate'],
        'operating_cost_index' => (int) round((float) $values['operating_cost_index']),
        'employer_social_security_rate' => (float) $values['employer_social_security_rate'],
        'incorporation_fees_usd' => (int) round((float) $values['incorporation_fees_usd']),
        'annual_filing_cost_usd' => (int) round((float) $values['annual_filing_cost_usd']),
        'treaty_network_strength' => $values['treaty_network_strength'],
        'compliance_burden' => $values['compliance_burden'],
        'reputation_risk' => $values['reputation_risk'],
        'incentives' => lines_to_array($values['incentives']),
        'notes' => lines_to_array($values['notes']),
        'foundation_terms' => [
            'availability' => $values['foundation_availability'],
            'control_requirements' => $values['foundation_control'],
            'reporting' => $values['foundation_reporting'],
            'substance_requirements' => $values['foundation_substance'],
            'notes' => lines_to_array($values['foundation_notes']),
            'friendly_score' => max(0, min(5, (int) round((float) $values['foundation_friendly_score']))),
        ],
    ];

    $updated = false;
    foreach ($jurisdictions as $index => $existing) {
        if (strcasecmp((string) ($existing['country'] ?? ''), $entry['country']) === 0) {
            $jurisdictions[$index] = $entry;
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $jurisdictions[] = $entry;
    }

    usort($jurisdictions, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['country'] ?? ''), (string) ($b['country'] ?? ''));
    });

    try {
        $json = encode_dataset($jurisdictions);
    } catch (Throwable $exception) {
        return [
            'status' => 'error',
            'message' => 'Failed to encode dataset: ' . $exception->getMessage(),
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => $entry['country'],
        ];
    }

    if (file_put_contents(DATA_PATH, $json, LOCK_EX) === false) {
        return [
            'status' => 'error',
            'message' => 'Failed to write updated dataset to disk.',
            'values' => $values,
            'jurisdictions' => $jurisdictions,
            'country' => $entry['country'],
        ];
    }

    return [
        'status' => 'success',
        'message' => ($updated ? 'Updated ' : 'Added ') . $entry['country'],
        'values' => default_form_values(),
        'jurisdictions' => $jurisdictions,
        'country' => $entry['country'],
    ];
}

$jurisdictions = load_jurisdictions();
$status_message = null;
$status_type = null;
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
        $query['country'] = $submission['country'];
        header('Location: index.php?' . http_build_query($query) . '#manage');
        exit;
    }
} else {
    if (isset($_GET['status']) && $_GET['status'] === 'saved') {
        $status_type = 'success';
        $saved_country = isset($_GET['country']) ? trim((string) $_GET['country']) : 'Jurisdiction';
        $status_message = $saved_country . ' saved successfully.';
    }
}

$edit_entry = null;
$editing_country = isset($_GET['edit']) ? trim((string) $_GET['edit']) : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editing_country !== '') {
    foreach ($jurisdictions as $item) {
        if (strcasecmp((string) $item['country'], $editing_country) === 0) {
            $edit_entry = $item;
            $form_values = entry_to_form_values($item);
            break;
        }
    }
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

$compliance_levels = ['Low', 'Moderate', 'High'];
$reputation_levels = ['Very Low', 'Low', 'Moderate', 'Elevated', 'High'];

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
                                unset($link_params['status'], $link_params['country'], $link_params['edit']);
                                $link_params['detail'] = $item['country'];
                                $detail_url = 'index.php?' . http_build_query($link_params);

                                $edit_params = $_GET;
                                unset($edit_params['status'], $edit_params['country'], $edit_params['detail']);
                                $edit_params['edit'] = $item['country'];
                                $edit_url = 'index.php?' . http_build_query($edit_params) . '#manage';
                            ?>
                            <div class="card-actions">
                                <a class="btn secondary" href="<?php echo e($detail_url); ?>#detail">View details</a>
                                <a class="btn" href="<?php echo e($edit_url); ?>">Edit entry</a>
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
                    <h2>Maintain the dataset</h2>
                    <p>Adjust or append jurisdictions without leaving the flat-file workflow. Updates persist immediately to the JSON store.</p>
                </div>
                <?php if ($status_message !== null): ?>
                    <div class="alert <?php echo $status_type === 'success' ? 'success' : 'error'; ?>">
                        <?php echo e($status_message); ?>
                    </div>
                <?php endif; ?>
                <?php if ($edit_entry !== null): ?>
                    <div class="alert info">
                        Editing <?php echo e($edit_entry['country']); ?>. Saving will overwrite the existing record.
                    </div>
                <?php endif; ?>
                <form class="manage-form" method="post">
                    <datalist id="region-list">
                        <?php foreach ($region_options as $region): ?>
                            <option value="<?php echo e($region); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <datalist id="compliance-list">
                        <?php foreach ($compliance_levels as $level): ?>
                            <option value="<?php echo e($level); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <datalist id="reputation-list">
                        <?php foreach ($reputation_levels as $level): ?>
                            <option value="<?php echo e($level); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-grid wide">
                        <label>
                            <span>Country</span>
                            <input type="text" name="country" required value="<?php echo e($form_values['country']); ?>">
                        </label>
                        <label>
                            <span>Region</span>
                            <input type="text" name="region" list="region-list" required value="<?php echo e($form_values['region']); ?>">
                        </label>
                        <label>
                            <span>Corporate tax rate (%)</span>
                            <input type="number" step="0.1" min="0" name="corporate_tax_rate" required value="<?php echo e($form_values['corporate_tax_rate']); ?>">
                        </label>
                        <label>
                            <span>Operating cost index</span>
                            <input type="number" step="1" min="0" name="operating_cost_index" required value="<?php echo e($form_values['operating_cost_index']); ?>">
                        </label>
                        <label>
                            <span>Employer social security (%)</span>
                            <input type="number" step="0.1" min="0" name="employer_social_security_rate" required value="<?php echo e($form_values['employer_social_security_rate']); ?>">
                        </label>
                        <label>
                            <span>Incorporation fees (USD)</span>
                            <input type="number" step="10" min="0" name="incorporation_fees_usd" required value="<?php echo e($form_values['incorporation_fees_usd']); ?>">
                        </label>
                        <label>
                            <span>Annual filing cost (USD)</span>
                            <input type="number" step="10" min="0" name="annual_filing_cost_usd" required value="<?php echo e($form_values['annual_filing_cost_usd']); ?>">
                        </label>
                        <label>
                            <span>Compliance burden</span>
                            <input type="text" name="compliance_burden" list="compliance-list" required value="<?php echo e($form_values['compliance_burden']); ?>">
                        </label>
                        <label>
                            <span>Reputation risk</span>
                            <input type="text" name="reputation_risk" list="reputation-list" required value="<?php echo e($form_values['reputation_risk']); ?>">
                        </label>
                        <label>
                            <span>Foundation friendly score (0-5)</span>
                            <input type="number" min="0" max="5" step="1" name="foundation_friendly_score" required value="<?php echo e($form_values['foundation_friendly_score']); ?>">
                        </label>
                    </div>
                    <div class="form-grid wide">
                        <label class="full">
                            <span>Treaty network strength</span>
                            <textarea name="treaty_network_strength" rows="2" required><?php echo e($form_values['treaty_network_strength']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Incentives (one per line)</span>
                            <textarea name="incentives" rows="3"><?php echo e($form_values['incentives']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Operating notes (one per line)</span>
                            <textarea name="notes" rows="3"><?php echo e($form_values['notes']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Foundation availability</span>
                            <textarea name="foundation_availability" rows="2" required><?php echo e($form_values['foundation_availability']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Foundation control requirements</span>
                            <textarea name="foundation_control" rows="2" required><?php echo e($form_values['foundation_control']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Foundation reporting</span>
                            <textarea name="foundation_reporting" rows="2" required><?php echo e($form_values['foundation_reporting']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Foundation substance requirements</span>
                            <textarea name="foundation_substance" rows="2" required><?php echo e($form_values['foundation_substance']); ?></textarea>
                        </label>
                        <label class="full">
                            <span>Foundation notes (one per line)</span>
                            <textarea name="foundation_notes" rows="3"><?php echo e($form_values['foundation_notes']); ?></textarea>
                        </label>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn primary">Save jurisdiction</button>
                        <button type="reset" class="btn">Reset</button>
                        <?php if ($edit_entry !== null): ?>
                            <a class="btn" href="index.php#manage">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                    <p class="form-helper">Lists accept one item per line and are stored automatically as arrays in <code>data/jurisdictions.json</code>.</p>
                </form>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>Data sourced from curated flat files. Update <code>data/jurisdictions.json</code> to refresh insights instantly.</p>
        </div>
    </footer>
</body>
</html>
