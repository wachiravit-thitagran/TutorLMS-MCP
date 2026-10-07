import { spawnSync } from 'node:child_process';

const allowedAdvisories = new Set([
  // Upstream @wordpress/env -> simple-git dev-tooling issue. simple-git 4.0.2
  // contains the fix, but forcing that major into wp-env currently breaks its
  // API contract (SimpleGit is not a function). This is CI-only tooling and is
  // not shipped in the WordPress plugin ZIP.
  'https://github.com/advisories/GHSA-v5rq-49vh-5v5c',
]);

const result = spawnSync('npm', ['audit', '--json'], {
  encoding: 'utf8',
  maxBuffer: 20 * 1024 * 1024,
});

let report;
try {
  report = JSON.parse(result.stdout || '{}');
} catch (error) {
  console.error('Could not parse npm audit JSON output.');
  console.error(result.stdout);
  console.error(result.stderr);
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
  const onlyAllowed = urls.size > 0 && [...urls].every((url) => allowedAdvisories.has(url));

  if (onlyAllowed) {
    allowed.push({ name, severity: item.severity, urls: [...urls] });
  } else {
    blocked.push({ name, severity: item.severity, urls: [...urls] });
  }
}

for (const item of allowed) {
  console.warn(
    `ALLOWLISTED ${item.severity.toUpperCase()}: ${item.name} -> ${item.urls.join(', ')}`
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
  `Node audit policy passed. ${allowed.length} explicitly allowlisted high/critical dev-tooling finding(s).`
);
