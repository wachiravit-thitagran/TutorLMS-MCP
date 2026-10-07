import { spawnSync } from 'node:child_process';

const result = spawnSync('npm', ['audit', '--audit-level=high'], {
  stdio: 'inherit',
});

if (result.error) {
  console.error(result.error);
  process.exit(1);
}

process.exit(result.status ?? 1);
