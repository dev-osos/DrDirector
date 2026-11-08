# DR - Director

<p align="center">
  <img src="icons/icon-192.png" alt="DR - Director app icon" width="112">
</p>

<h3 align="center">A multi-source medication information assistant for pregnancy</h3>

<p align="center">
  An Arabic-first Progressive Web App (PWA) that checks available medication references, compares pregnancy-related statements, and presents an evidence-based summary with links to the original sources.
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white">
  <img alt="PWA" src="https://img.shields.io/badge/Progressive%20Web%20App-Installable-5A0FC8">
  <img alt="Language" src="https://img.shields.io/badge/Interface-Arabic%20%7C%20RTL-0F766E">
</p>

---

## Overview

**DR - Director** is a lightweight web application designed to help users explore medication-related pregnancy information from multiple references in one place.

Enter a medication name to retrieve available pregnancy-related information, classify statements found in the sources, and compare the source results. The application displays the overall outcome, source-by-source findings, supporting text snippets, and links to the original references.

The interface is built in Arabic with right-to-left (RTL) layout and can be installed on supported devices as a Progressive Web App.

> **Important:** DR - Director is an informational tool, not a medical device or a substitute for professional medical advice. Its automated text classification and source-voting approach can be incomplete or incorrect. Never start, stop, or change medication during pregnancy based only on this application. Always consult a qualified physician or pharmacist.

## Highlights

- **Multi-source lookup:** Retrieves pregnancy-related medication information from the FDA drug-label API and, when configured, Lexicomp Online.
- **Source-level comparison:** Each available reference is classified as safe-leaning, unsafe-leaning, neutral, or unavailable according to detected text patterns.
- **Conservative decision mode:** Strict mode prevents a positive "safe" outcome when a source contains a hard warning such as contraindication or Category X language; the result is changed to "consult a doctor".
- **Evidence snippets:** Shows excerpts that triggered the text classification, alongside links to the original source.
- **Medication history:** Saves analyzed results in MySQL and provides a recent-history view.
- **Result caching:** Reuses stored results for repeated medication queries; users can manually refresh a result.
- **Installable PWA:** Includes a web app manifest, app icons, and a service worker for the application shell.
- **Responsive Arabic interface:** RTL layout designed for mobile and desktop screens.

## Data sources

| Source | Integration | Notes |
| --- | --- | --- |
| FDA drug labels | Enabled | Queries the OpenFDA drug-label API and checks relevant label sections. Up to five labels can be configured. |
| Lexicomp Online | Optional | Requires an authorized, active session cookie configured on the server. Access may expire or be subject to the provider's terms. |
| RxList | Implemented, disabled by default | The connector is present in the code, but is disabled in the default configuration. |

Source availability, coverage, and wording may change. A missing result does not mean a medication is safe or unsafe.

## How the result is calculated

The backend scans retrieved source text for configured pregnancy-related phrases and classifies detected statements. It then compares the source-level outcomes:

- More safe-leaning than unsafe-leaning sources: **Safe-leaning result**.
- More unsafe-leaning than safe-leaning sources: **Unsafe-leaning result**.
- Equal counts, or only neutral information: **Consult a doctor**.
- No usable information: **Insufficient information**.

With `strict_mode` enabled, a hard warning can override a safe-leaning majority and produce a consult result.

This is a rule-based text-matching heuristic, not a clinical evidence grading system. It does not account for every drug, dose, route, trimester, patient condition, or interaction, and the number of pages is not a measure of evidence quality.

## Technology stack

- **Backend:** PHP 8+
- **Database:** MySQL / MariaDB through PDO
- **HTTP requests:** PHP cURL and cURL multi
- **Frontend:** HTML, CSS, and vanilla JavaScript
- **PWA:** Web App Manifest and Service Worker
- **Typography:** Cairo font

## Project structure

```text
DrDirector/
├── api.php                 # API endpoints, source retrieval, classification and decisions
├── config.php              # Database and source configuration
├── schema.sql              # Database schema
├── index.html              # Arabic RTL application interface
├── manifest.json           # PWA metadata and install configuration
├── sw.js                   # Service worker and app-shell caching
├── assets/
│   ├── app.js              # Chat interface, API calls, history and install flow
│   └── style.css           # Application styling
└── icons/                  # PWA icons
```

## Requirements

- PHP **8.0 or newer**
- MySQL or MariaDB
- PHP extensions:
  - `pdo_mysql`
  - `curl`
  - `mbstring`
  - `json`
- A web server configured to run PHP
- HTTPS for service-worker registration and normal PWA installation (localhost is generally treated as a secure context for development)
- Outbound server access to the configured medication information sources

## Installation

### 1. Get the project

```bash
git clone https://github.com/dev-osos/DrDirector.git
cd DrDirector
```

Alternatively, download the repository ZIP and place the project in your PHP web-server directory.

### 2. Create the database

Create a MySQL database and a database user with the required privileges. Then import the schema:

```bash
mysql -u YOUR_DB_USER -p YOUR_DATABASE < schema.sql
```

If your hosting provider does not allow `CREATE DATABASE` or `USE` in imported SQL files, create the database through the hosting control panel and import only the table definition from `schema.sql`.

The API also creates the `drug_checks` table automatically after it connects successfully to the configured database.

### 3. Configure the application

Edit `config.php` and set your database connection:

```php
'db' => [
    'host'    => 'localhost',
    'name'    => 'YOUR_DATABASE',
    'user'    => 'YOUR_DB_USER',
    'pass'    => 'YOUR_DB_PASSWORD',
    'charset' => 'utf8mb4',
],
```

Review the remaining options in the same file:

- `strict_mode`: enables the conservative hard-warning override.
- `cache_days`: number of days before cached results are considered expired. Use `0` to keep them indefinitely.
- `http_timeout`: outbound request timeout in seconds.
- `max_fda_labels`: maximum number of FDA labels to inspect.
- `lexi.enabled` and `lexi.cookie`: optional Lexicomp integration settings.
- `drugs.enabled`: RxList integration toggle; disabled by default.

**Security:** Never commit database passwords, session cookies, API keys, or other credentials. Keep production secrets outside version control, and use a private server-side configuration file or environment variables. If a credential has ever been committed to a public repository, revoke or rotate it immediately.

### 4. Deploy over HTTPS

Upload the project to your PHP hosting environment and open its URL over HTTPS. Confirm that the server can connect to the database and make outbound HTTPS requests.

### 5. Install the PWA

Open the application in a supported browser. Use the browser's install option (or the in-app install button when available) to add DR - Director to your device.

## API

The frontend communicates with `api.php`.

| Request | Purpose |
| --- | --- |
| `POST api.php` | Analyze a medication. JSON body: `{"drug":"Paracetamol"}` |
| `POST api.php` | Force a fresh lookup. JSON body: `{"drug":"Paracetamol","refresh":true}` |
| `GET api.php?action=history` | Retrieve the latest saved medication history. |

The analysis response includes the medication name, overall verdict, source counts, strict-mode override status, source links, evidence snippets, cache status, and check timestamp.

## Configuration notes

### Lexicomp

Lexicomp access is optional and requires an authorized session. Session cookies expire and must be handled securely. Do not publish cookies in source files, screenshots, issues, or commits. Disable the integration if you do not have authorized access.

### RxList

The RxList connector is included but disabled by default. Enable it only after verifying that requests from your hosting environment are permitted and that the integration complies with the source website's terms.

## Privacy and security

- The application stores medication query names, result classifications, source evidence, and query counters in the configured database.
- Protect `config.php` and database credentials from public access.
- Use HTTPS in production.
- Restrict database access to the application and trusted administrators.
- Do not store live third-party session cookies in a public repository.
- Review applicable privacy, security, and medical-software requirements before deploying for real-world use.

## Contributing

Contributions, bug reports, and suggestions are welcome.

1. Fork the repository.
2. Create a feature branch: `git checkout -b feature/your-feature`.
3. Commit your changes: `git commit -m "Add your feature"`.
4. Push the branch: `git push origin feature/your-feature`.
5. Open a Pull Request with a clear description and testing notes.

## License

This project includes a [LICENSE](LICENSE) file. Review it for the applicable terms before using, modifying, or distributing the project.

---

<p align="center">
  <strong>DR - Director</strong><br>
  Built by <a href="https://github.com/dev-osos">Osama Saied</a>
</p>
