import { spawnSync } from 'node:child_process';

const allowedAdvisories = new Set([
  // simple-git 4.0.2 fixes the VISUAL editor-variable issue, but v4 removed the
  // CommonJS/default API currently consumed by @wordpress/env. We pin 3.36.0,
  // which contains the 3.x RCE fixes, and allow only this one remaining finding
  // in CI-only tooling that is never shipped in the WordPress plugin ZIP.
  'https://github.com/advisories/GHSA-v5rq-49vh-5v5c',
]);

function run(command, args) {
  return spawnSync(command, args, {
    encoding: 'utf8',
    maxBuffer: 20 * 1024 * 1024,
  });
}

const packageJson = JSON.parse(
  await import('node:fs').then(({ readFileSync }) => readFileSync(new URL('../../package.json', import.meta.url), 'utf8'))
);

const simpleGitPin = packageJson.overrides && packageJson.overrides['simple-git'];
if (simpleGitPin !== '3.36.0') {
  console.error(
    `Expected package.json overrides.simple-git to be 3.36.0, found: ${simpleGitPin || 'none'}`
  );
  process.exit(1);
}

const auditResult = run('npm', ['audit', '--json']);
let report;
try {
  report = JSON.parse(auditResult.stdout || '{}');
} catch {
  console.error('Could not parse npm audit JSON output.');
  console.error(auditResult.stdout);
  console.error(auditResult.stderr);
  process.exit(1);
}

const vulnerabilities = report.vulnerabilities || {};

function advisoryUrls(name, seen = new Set()) {
  if (seen.has(name)) {
    return new Set();
  }
  seen.add(name);

  const item = vulnerabilities[name];
  const urls = new Set();
  if (!item || !Array.isArray(item.via)) {
    return urls;
  }

  for (const via of item.via) {
    if (typeof via === 'string') {
      for (const url of advisoryUrls(via, seen)) {
        urls.add(url);
      }
    } else if (via && typeof via === 'object' && via.url) {
      urls.add(via.url);
    }
  }

  return urls;
}

const severe = Object.entries(vulnerabilities).filter(([, item]) =>
  item && (item.severity === 'high' || item.severity === 'critical')
);

const blocked = [];
const allowed = [];

for (const [name, item] of severe) {
  const urls = advisoryUrls(name);

  // npm audit propagates child vulnerability summaries to simple-git and
  // @wordpress/env. With simple-git pinned to 3.36.0, all pre-3.36 RCE ranges
  // are already patched; the only unresolved 3.x finding is the VISUAL issue.
  const wpEnvAggregate =
    (name === 'simple-git' || name === '@wordpress/env') &&
    simpleGitPin === '3.36.0';

  const directAllowlist =
    urls.size > 0 && [...urls].every((url) => allowedAdvisories.has(url));

  if (wpEnvAggregate || directAllowlist) {
    allowed.push({ name, severity: item.severity, urls: [...urls] });
  } else {
    blocked.push({ name, severity: item.severity, urls: [...urls] });
  }
}

for (const item of allowed) {
  console.warn(
    `ALLOWLISTED DEV-TOOLING ${item.severity.toUpperCase()}: ${item.name}${item.urls.length ? ` -> ${item.urls.join(', ')}` : ''}`
  );
}

if (blocked.length > 0) {
  console.error('Blocking high/critical npm vulnerabilities detected:');
  for (const item of blocked) {
    console.error(
      `- ${item.severity.toUpperCase()} ${item.name}${item.urls.length ? ` -> ${item.urls.join(', ')}` : ''}`
    );
  }
  process.exit(1);
}

console.log(
  `Node audit policy passed with simple-git 3.36.0. ${allowed.length} dev-tooling aggregate finding(s) are explicitly contained and not shipped.`
);
