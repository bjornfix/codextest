# Corporate Tax & Foundation Intelligence Dashboard

This repository ships a fully responsive PHP dashboard that helps you analyse
corporate income tax rates, on-the-ground running costs, and the legal
friendliness of using foundations to own international operating companies. The
interface is rendered from flat JSON files, so you can host it on any standard
PHP-capable server without databases or background services.

## Key capabilities

- **Adaptive overview** – Gradient hero cards highlight the lowest corporate tax
  rate, leanest running cost, and most foundation-friendly jurisdiction at a
  glance on every screen size.
- **Graphical insights** – A combined bar/line chart compares corporate tax,
  operating cost indices, and foundation friendliness for the leanest 12
  jurisdictions in the dataset. If JavaScript libraries are unavailable, the
  overview automatically switches to inline comparison cards so you still get a
  quick visual ranking.
- **Regional rollups** – Automatically computed averages summarise the tax and
  cost environment across each region.
- **Powerful filtering** – Search by keyword, region, tax thresholds, cost
  ceilings, and minimum foundation friendliness directly from the flat-file
  dataset—no database required.
- **Deep dives** – Rich jurisdiction dossiers consolidate incentives, compliance
  burdens, and detailed foundation governance guidance.
- **Worldwide coverage** – 225 jurisdictions are preloaded using the Tax
  Foundation worldwide corporate tax rate dataset, enriched with operating cost
  and foundation heuristics for every entry.
- **On-the-fly updates** – A built-in management form lets you add or edit
  jurisdictions directly from the dashboard; changes persist to the JSON store
  instantly.

## Quick start

1. Ensure you have PHP 8.1+ installed locally or on your server.
2. Serve the flat-file site from the project root on the standard HTTP port:

   ```bash
   sudo php -S 0.0.0.0:80
   ```

   > **Note:** Binding to port 80 requires elevated privileges. If you prefer
   > to serve the files through Apache, Nginx, or another web server, configure
   > that server to listen on port 80 and serve this project directory.

3. Visit [http://localhost/](http://localhost/) in your browser. The
   dashboard automatically scales from phones to large desktop displays.

Use the **Maintain the dataset** section (`#manage`) to update or add
jurisdictions without leaving the app. All changes are written straight back to
the flat JSON file, so a simple refresh reflects your edits.

Need a portable bundle? Create it locally with:

```bash
zip -r codextest.zip assets data index.php README.md scripts .gitignore
```

That archive mirrors the flat-file layout that powers the dashboard so you can
transfer or deploy it wherever you need without additional build tooling.

No additional build step is required. Updating the JSON data instantly refreshes
all sections on reload.

## Data source

The dashboard reads from [`data/jurisdictions.json`](data/jurisdictions.json).
Each record contains:

| Field | Description |
| --- | --- |
| `country` | Jurisdiction name. |
| `region` | Region grouping used for summaries and filters. |
| `corporate_tax_rate` | Headline statutory corporate income tax rate. |
| `operating_cost_index` | Relative operating cost index (lower numbers are cheaper). |
| `employer_social_security_rate` | Average employer-side payroll contributions. |
| `incorporation_fees_usd` | Typical upfront incorporation fees in USD. |
| `annual_filing_cost_usd` | Estimated annual compliance costs in USD. |
| `treaty_network_strength` | Qualitative view of tax treaty coverage. |
| `compliance_burden` | Indicative compliance load (Low/Moderate/High). |
| `reputation_risk` | Qualitative reputational perception. |
| `incentives` | Array of notable incentives or regimes. |
| `notes` | Additional observations about doing business in the jurisdiction. |
| `foundation_terms` | Nested object covering availability, control, reporting, substance, and friendliness score. |

The `foundation_terms.friendly_score` ranges from **0 (unfriendly)** to **5 (very
friendly)** to help you rank foundation ownership suitability. Raw statutory tax
rates are sourced from the Tax Foundation's 2023 Worldwide Corporate Tax Rates
study (bundled in [`data/sources/final_data_2023_gdp_incomplete.csv`](data/sources/final_data_2023_gdp_incomplete.csv))
and enriched with heuristic operating-cost, compliance, and foundation metrics
to cover every jurisdiction globally.

## Maintaining the dataset

- **In-browser edits:** Scroll to the **Maintain the dataset** panel and submit
  the form to add a new jurisdiction or overwrite an existing record. Multi-line
  fields accept one list item per line and persist back to `data/jurisdictions.json`.
- **Rebuild from source:** To regenerate the entire dataset from the Tax
  Foundation CSV without leaving the PHP toolchain, run:

  ```bash
  php scripts/build_dataset.php
  ```

  The PHP script rewrites `data/jurisdictions.json` with the same two-space
  indentation so manual edits stay readable.

## Customisation tips

- **Add or edit jurisdictions:** Use the in-app form or update the JSON file—no
  schema migrations or database connections needed.
- **Change styling:** Modify [`assets/styles.css`](assets/styles.css) to tailor
  colours, spacing, or branding.
- **Extend the chart:** Adjust [`assets/app.js`](assets/app.js) to plot
  additional metrics or change visualisations.
- **Embed elsewhere:** Because the dashboard is a single `index.php` file, you
  can drop it into existing PHP sites or include it via template engines.

> **Disclaimer:** Figures are indicative averages gathered from public sources
> and practitioner experience. Always confirm the latest rules with a local
> advisor before acting.

