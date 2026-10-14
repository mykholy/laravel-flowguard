'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const checker = path.join(__dirname, 'check-preview-release.cjs');

function validate(manifest, options = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'flowguard-release-'));
  try {
    fs.mkdirSync(path.join(dir, '.release'));
    fs.mkdirSync(path.join(dir, 'docs'));
    fs.writeFileSync(path.join(dir, '.release/preview.json'), JSON.stringify(manifest));
    fs.writeFileSync(path.join(dir, `.release/${manifest.tag}.md`),
      `${manifest.tag}\n${options.notes || (manifest.channel === 'stable' ? 'stable release' : 'prerelease')}`);
    fs.writeFileSync(path.join(dir, 'CHANGELOG.md'), `## ${manifest.tag} — 2026-10-14\n`);
    fs.writeFileSync(path.join(dir, 'docs/PUBLIC_API.md'), options.omitContract ? 'draft' : 'supported contract from v1.0.0');
    fs.writeFileSync(path.join(dir, 'docs/RELEASE_CRITERIA.md'), 'Policy revision from the beta checklist');
    return spawnSync(process.execPath, [checker], { cwd: dir, encoding: 'utf8' }).status;
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

const stable = { tag: 'v1.0.0', channel: 'stable', notes: '.release/v1.0.0.md' };
const beta = { tag: 'v0.1.0-beta.4', notes: '.release/v0.1.0-beta.4.md' };
test('explicit stable and historical beta manifests are accepted', () => {
  assert.equal(validate(stable), 0);
  assert.equal(validate(beta), 0);
});
test('a beta tag cannot be published as stable', () => {
  assert.notEqual(validate({ ...beta, channel: 'stable' }), 0);
});
test('a stable tag requires its stable channel', () => {
  assert.notEqual(validate({ ...stable, channel: 'beta' }), 0);
});
test('notes must match both the version path and channel', () => {
  assert.notEqual(validate({ ...stable, notes: '.release/unrelated.md' }), 0);
  assert.notEqual(validate(stable, { notes: 'prerelease' }), 0);
});
test('a draft contract cannot be published as stable', () => {
  assert.notEqual(validate(stable, { omitContract: true }), 0);
});
