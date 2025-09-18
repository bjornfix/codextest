<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/data/sources/final_data_2023_gdp_incomplete.csv';
$outputDir = $root . '/data/jurisdictions';
$legacyOutput = $root . '/data/jurisdictions.json';

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

if (!is_readable($source)) {
    fwrite(STDERR, "Source CSV not found: {$source}\n");
    exit(1);
}

$regionMap = [
    'AF' => 'Africa',
    'AS' => 'Asia-Pacific',
    'EU' => 'Europe',
    'NO' => 'North America & Caribbean',
    'OC' => 'Oceania',
    'SA' => 'South America',
];

$regionIncentives = [
    'Africa' => 'Investment promotion agencies exchange tax credits for infrastructure and workforce expansion commitments.',
    'Asia-Pacific' => 'Special economic and free trade zones extend multi-year tax holidays for export-led projects.',
    'Europe' => 'EU directives enable withholding tax relief on qualifying intra-group dividends and interest.',
    'North America & Caribbean' => 'International business company statutes streamline territorial taxation with light-touch accounting.',
    'Oceania' => 'Export development incentives include accelerated depreciation and refundable R&D offsets.',
    'South America' => 'Regional trade pacts grant tariff preferences for manufacturing and logistics investments.',
];

$regionFoundationNotes = [
    'Africa' => [
        'Regulators emphasise socio-economic impact reporting for privately controlled foundations.',
        'Cross-border grants and investments typically require approval from central authorities.',
    ],
    'Asia-Pacific' => [
        'Beneficial ownership registers apply to council members and controlling donors.',
        'Cross-border structuring must align with CRS and regional substance expectations.',
    ],
    'Europe' => [
        'EU substance and transparency directives shape governance standards for private foundations.',
        'Anti-hybrid and DAC6 disclosure regimes affect cross-border holding structures.',
    ],
    'North America & Caribbean' => [
        'Economic substance tests focus on locally resident directors and demonstrable management.',
        'Information exchange agreements cover banking and entity ownership reporting.',
    ],
    'Oceania' => [
        'Regulators expect resident trustees and onshore board deliberations for active holdings.',
        'Trans-Tasman transparency frameworks facilitate data sharing on charitable controllers.',
    ],
    'South America' => [
        'Civil-law foundations often need alignment with non-profit registries and public benefit mandates.',
        'Cross-border remittances may require central bank pre-approval for large transfers.',
    ],
];

$commonNameReplacements = [
    'Bolivia (Plurinational State of)' => 'Bolivia',
    'Congo' => 'Republic of the Congo',
    'Democratic Republic of the Congo' => 'Democratic Republic of the Congo',
    'Cabo Verde' => 'Cape Verde',
    "Cote d'Ivoire" => 'Côte d\'Ivoire',
    'Czechia' => 'Czech Republic',
    'Hong Kong Special Administrative Region of China' => 'Hong Kong',
    'Iran (Islamic Republic of)' => 'Iran',
    "Korea, Democratic People's Republic of" => 'North Korea',
    'Korea, Republic of' => 'South Korea',
    "Lao People's Democratic Republic" => 'Laos',
    'Macao Special Administrative Region of China' => 'Macao',
    'Micronesia (Federated States of)' => 'Micronesia',
    'Moldova, Republic of' => 'Moldova',
    'North Macedonia' => 'North Macedonia',
    'Palestine, State of' => 'Palestine',
    'Russian Federation' => 'Russia',
    'Syrian Arab Republic' => 'Syria',
    'Taiwan, Province of China' => 'Taiwan',
    'Tanzania, United Republic of' => 'Tanzania',
    'United Kingdom of Great Britain and Northern Ireland' => 'United Kingdom',
    'United States of America' => 'United States',
    'Venezuela (Bolivarian Republic of)' => 'Venezuela',
    'Viet Nam' => 'Vietnam',
];

$rows = [];
if (($handle = fopen($source, 'r')) === false) {
    fwrite(STDERR, "Unable to open source CSV: {$source}\n");
    exit(1);
}

$headers = null;
while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    if ($headers === null) {
        $headers = $data;
        continue;
    }

    if ($headers === null) {
        continue;
    }

    $row = [];
    foreach ($headers as $index => $header) {
        $key = trim($header, "\" \t\n\r\0\x0B");
        if ($key === '') {
            continue;
        }
        $row[$key] = $data[$index] ?? '';
    }

    if (!isset($row['country'], $row['continent'], $row['rate'])) {
        continue;
    }

    $countryRaw = trim($row['country']);
    $country = $commonNameReplacements[$countryRaw] ?? $countryRaw;

    $regionCode = trim($row['continent']);
    $region = $regionMap[$regionCode] ?? 'Global';

    $rateStr = trim((string) $row['rate']);
    if ($rateStr === '' || !is_numeric($rateStr)) {
        continue;
    }
    $taxRate = (float) $rateStr;

    $gdpRaw = trim((string) ($row['gdp'] ?? ''));
    $gdp = null;
    if ($gdpRaw !== '' && strtoupper($gdpRaw) !== 'NA') {
        $gdp = is_numeric($gdpRaw) ? (float) $gdpRaw : null;
    }

    $rows[] = [
        'country' => $country,
        'region' => $region,
        'tax_rate' => $taxRate,
        'gdp' => $gdp,
        'groups' => [
            'oecd' => isset($row['oecd']) && trim((string) $row['oecd']) === '1',
            'eu' => isset($row['eu27']) && trim((string) $row['eu27']) === '1',
            'g7' => isset($row['gseven']) && trim((string) $row['gseven']) === '1',
            'g20' => isset($row['gtwenty']) && trim((string) $row['gtwenty']) === '1',
            'brics' => isset($row['brics']) && trim((string) $row['brics']) === '1',
        ],
    ];
}
fclose($handle);

$clamp = static function (float $value, float $min, float $max): float {
    return max($min, min($max, $value));
};

$costIndex = static function (array $row) use ($clamp): float {
    $base = [
        'Africa' => 38.0,
        'Asia-Pacific' => 56.0,
        'Europe' => 70.0,
        'North America & Caribbean' => 64.0,
        'Oceania' => 67.0,
        'South America' => 54.0,
    ][$row['region']] ?? 60.0;

    $gdp = $row['gdp'];
    if ($gdp === null || $gdp <= 0.0) {
        $gdpComponent = 0.0;
    } else {
        $gdpComponent = (log10($gdp + 1.0) - 2.0) * 14.0;
        $gdpComponent = $clamp($gdpComponent, -12.0, 20.0);
    }

    $taxComponent = ($row['tax_rate'] - 20.0) * 0.18;
    return $clamp($base + $gdpComponent + $taxComponent, 25.0, 95.0);
};

$socialRate = static function (array $row, float $cost) use ($clamp): float {
    $base = [
        'Africa' => 8.0,
        'Asia-Pacific' => 11.0,
        'Europe' => 17.0,
        'North America & Caribbean' => 10.0,
        'Oceania' => 9.0,
        'South America' => 14.0,
    ][$row['region']] ?? 12.0;

    $adjustment = ($row['tax_rate'] - 20.0) * 0.12 + ($cost - 55.0) * 0.05;
    return $clamp($base + $adjustment, 0.0, 35.0);
};

$incorporationFee = static function (array $row, float $cost) use ($clamp): float {
    $base = [
        'Africa' => 160.0,
        'Asia-Pacific' => 220.0,
        'Europe' => 260.0,
        'North America & Caribbean' => 230.0,
        'Oceania' => 240.0,
        'South America' => 200.0,
    ][$row['region']] ?? 220.0;

    $premium = ($cost - 45.0) * 12.0;
    if ($row['tax_rate'] <= 10.0) {
        $premium += 180.0;
    }

    $value = $clamp($base + $premium, 90.0, 2500.0);
    return round($value / 10.0, 0, PHP_ROUND_HALF_EVEN) * 10.0;
};

$annualFilingCost = static function (array $row, float $cost, float $social) use ($clamp): float {
    $gdp = $row['gdp'];
    $gdpSignal = 0.0;
    if ($gdp !== null && $gdp > 0.0) {
        $gdpSignal = $clamp((log10($gdp + 1.0) - 2.0) * 200.0, -200.0, 600.0);
    }

    $baseline = 320.0 + ($cost - 50.0) * 18.0 + $social * 14.0 + $gdpSignal;
    $value = $clamp($baseline, 200.0, 6000.0);
    return round($value / 10.0, 0, PHP_ROUND_HALF_EVEN) * 10.0;
};

$treatyStrength = static function (array $row): string {
    $groups = $row['groups'];
    $rate = $row['tax_rate'];
    $region = $row['region'];

    if ($groups['oecd'] && $groups['g7']) {
        return 'OECD and G7 member with one of the broadest double-tax treaty networks globally.';
    }
    if ($groups['oecd'] && $groups['g20']) {
        return 'OECD-aligned treaty policy covering most major economies and investment partners.';
    }
    if ($groups['eu']) {
        return 'EU membership provides extensive directive coverage and bilateral treaty access.';
    }
    if ($groups['g20']) {
        return 'G20 participation underpins a wide treaty footprint across strategic markets.';
    }
    if ($groups['brics']) {
        return 'BRICS coordination delivers treaties with key emerging-market jurisdictions.';
    }
    if ($region === 'North America & Caribbean' && $rate <= 10.0) {
        return 'Selective treaty and information exchange network focused on avoiding blacklisting risks.';
    }
    if ($rate <= 10.0) {
        return 'Targeted treaty coverage prioritising investment partners and transparency agreements.';
    }
    return 'Developing treaty program anchored in regional double-tax agreements.';
};

$complianceBurden = static function (float $cost, float $rate): string {
    if ($cost >= 75.0 || $rate >= 30.0) {
        return 'High';
    }
    if ($cost >= 60.0 || $rate >= 22.0) {
        return 'Moderate';
    }
    return 'Low';
};

$reputationRisk = static function (array $row): string {
    $rate = $row['tax_rate'];
    $region = $row['region'];

    if ($rate === 0.0) {
        return 'High';
    }
    if ($rate < 5.0) {
        return 'Elevated';
    }
    if ($rate < 10.0) {
        return $region === 'North America & Caribbean' ? 'Elevated' : 'Moderate';
    }
    if ($rate < 20.0) {
        return 'Moderate';
    }
    if ($rate < 28.0) {
        return 'Low';
    }
    return 'Very Low';
};

$incentives = static function (array $row, float $fee) use ($regionIncentives): array {
    $headline = sprintf(
        'Headline corporate income tax of %.1f%% with tailored relief for reinvested profits and priority sectors.',
        $row['tax_rate']
    );
    $regional = $regionIncentives[$row['region']] ?? 'Investment incentives tailored to strategic industries.';
    $setup = sprintf(
        'Digital incorporation pathways keep formation outlays near $%s including standard government charges.',
        number_format($fee, 0, '.', ',')
    );

    return [$headline, $regional, $setup];
};

$notes = static function (float $social, float $annualCost): array {
    $labour = sprintf(
        'Employers budget roughly %.1f%% of payroll for social security and labour funds.',
        $social
    );
    $filings = sprintf(
        'Annual compliance service packages average $%s covering accounting, filings, and statutory audits as required.',
        number_format($annualCost, 0, '.', ',')
    );
    $oversight = 'Regulators increasingly monitor economic substance and beneficial ownership disclosures for cross-border groups.';

    return [$labour, $filings, $oversight];
};

$foundationTerms = static function (
    array $row,
    float $social,
    float $annualCost,
    string $compliance,
    string $reputation
) use ($clamp, $regionFoundationNotes): array {
    $friendly = 3;
    if ($row['tax_rate'] <= 5.0) {
        $friendly += 2;
    } elseif ($row['tax_rate'] <= 10.0) {
        $friendly += 1;
    }

    if ($compliance === 'High') {
        $friendly -= 1;
    }
    if ($reputation === 'Elevated' || $reputation === 'High') {
        $friendly -= 1;
    }

    $friendly = (int) $clamp((float) $friendly, 1.0, 5.0);

    if ($friendly >= 4) {
        $availability = 'Widely available for private benefit foundations that can own operating subsidiaries subject to oversight.';
        $control = 'Requires a resident director or council member plus documented governance minutes.';
        $reporting = 'Annual financial statements and beneficial ownership registers filed via secure online portals.';
        $substance = 'Demonstrate mind-and-management through local service providers and periodic in-jurisdiction board meetings.';
    } elseif ($friendly === 3) {
        $availability = 'Permitted for philanthropic and holding activities; commercial control reviewed on a case-by-case basis.';
        $control = 'At least one locally qualified fiduciary or administrator must supervise decision making.';
        $reporting = 'Yearly activity reports and financial summaries lodged with the foundation supervisor.';
        $substance = 'Maintain registered office services and retain evidentiary support for strategic management decisions.';
    } else {
        $availability = 'Primarily restricted to charitable purposes with limited ability to own active businesses.';
        $control = 'Regulator approval needed before foundations may influence corporate management.';
        $reporting = 'Detailed programme and financial reporting required, often with advance budgeting submissions.';
        $substance = 'Expect mandated local agents and closer supervision of cross-border transactions.';
    }

    $notesSet = $regionFoundationNotes[$row['region']] ?? [
        'Foundation governance aligns with international transparency standards.',
        'Professional trustee support recommended for cross-border ownership structures.',
    ];

    $capex = max(5000.0, $annualCost * 2.5);
    $notesDynamic = $notesSet;
    $notesDynamic[] = sprintf(
        'Annual governance budgets of roughly $%s cover directors, accounting, and regulatory liaison fees.',
        number_format($capex, 0, '.', ',')
    );

    return [
        'availability' => $availability,
        'control_requirements' => $control,
        'reporting' => $reporting,
        'substance_requirements' => $substance,
        'notes' => $notesDynamic,
        'friendly_score' => $friendly,
    ];
};

$buildEntry = static function (array $row) use (
    $costIndex,
    $socialRate,
    $incorporationFee,
    $annualFilingCost,
    $complianceBurden,
    $reputationRisk,
    $incentives,
    $notes,
    $foundationTerms,
    $treatyStrength
): array {
    $cost = $costIndex($row);
    $social = $socialRate($row, $cost);
    $fee = $incorporationFee($row, $cost);
    $annualCost = $annualFilingCost($row, $cost, $social);
    $compliance = $complianceBurden($cost, $row['tax_rate']);
    $reputation = $reputationRisk($row);

    return [
        'country' => $row['country'],
        'region' => $row['region'],
        'corporate_tax_rate' => round($row['tax_rate'], 3, PHP_ROUND_HALF_EVEN),
        'operating_cost_index' => (int) round($cost, 0, PHP_ROUND_HALF_EVEN),
        'employer_social_security_rate' => round($social, 2, PHP_ROUND_HALF_EVEN),
        'incorporation_fees_usd' => (int) round($fee, 0, PHP_ROUND_HALF_EVEN),
        'annual_filing_cost_usd' => (int) round($annualCost, 0, PHP_ROUND_HALF_EVEN),
        'treaty_network_strength' => $treatyStrength($row),
        'compliance_burden' => $compliance,
        'reputation_risk' => $reputation,
        'incentives' => $incentives($row, $fee),
        'notes' => $notes($social, $annualCost),
        'foundation_terms' => $foundationTerms($row, $social, $annualCost, $compliance, $reputation),
    ];
};

$entries = array_map($buildEntry, $rows);

usort($entries, static fn (array $a, array $b): int => strcmp($a['country'], $b['country']));

$encode = static function (array $items): ?string {
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
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
};

$slugify = static function (string $region): string {
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
};

$grouped = [];
foreach ($entries as $entry) {
    $region = $entry['region'] ?? 'Global';
    if ($region === '') {
        $region = 'Global';
    }

    $grouped[$region][] = $entry;
}

ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

$existing = glob($outputDir . '/*.json');
if ($existing === false) {
    $existing = [];
}

$written = [];

foreach ($grouped as $region => $items) {
    usort($items, static fn (array $a, array $b): int => strcmp($a['country'], $b['country']));

    $encoded = $encode($items);
    if ($encoded === null) {
        fwrite(STDERR, "Failed to encode region dataset for {$region}.\n");
        exit(1);
    }

    $path = $outputDir . '/' . $slugify($region) . '.json';
    if (file_put_contents($path, $encoded) === false) {
        fwrite(STDERR, "Failed to write region dataset to {$path}.\n");
        exit(1);
    }

    $written[] = $path;
}

foreach ($existing as $path) {
    if (!in_array($path, $written, true)) {
        @unlink($path);
    }
}

$encodedAll = $encode($entries);
if ($encodedAll === null) {
    fwrite(STDERR, "Failed to encode combined dataset.\n");
    exit(1);
}

if (file_put_contents($legacyOutput, $encodedAll) === false) {
    fwrite(STDERR, "Failed to write combined dataset to {$legacyOutput}.\n");
    exit(1);
}

printf(
    "Wrote %d jurisdictions across %d region files in %s and updated %s\n",
    count($entries),
    count($grouped),
    $outputDir,
    $legacyOutput
);
