'use strict';

const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync('.release/preview.json', 'utf8'));
const channel = manifest.channel || 'beta';
const stable = channel === 'stable';
if (!(stable ? /^v1\.\d+\.\d+$/.test(manifest.tag)
  : channel === 'beta' && /^v0\.\d+\.\d+-beta\.[1-9]\d*$/.test(manifest.tag))) {
  throw new Error('Release tag must match its explicit stable or beta channel.');
}
if (manifest.notes !== `.release/${manifest.tag}.md`) {
  throw new Error('Release notes must match the tag.');
}
const notes = fs.readFileSync(manifest.notes, 'utf8');
if (!notes.includes(manifest.tag) || !(stable ? notes.includes('stable release') : notes.includes('prerelease'))) {
  throw new Error('Release notes must identify the tag and channel.');
}
if (!fs.readFileSync('CHANGELOG.md', 'utf8').includes(`## ${manifest.tag} `)) {
  throw new Error('The release needs a dated changelog entry.');
}
if (stable) {
  if (!fs.readFileSync('docs/PUBLIC_API.md', 'utf8').includes('supported contract from v1.0.0')
    || !fs.readFileSync('docs/RELEASE_CRITERIA.md', 'utf8').includes('Policy revision from the beta checklist')) {
    throw new Error('Stable publication requires the supported contract and explicit release scope.');
  }
}
console.log(`Validated ${manifest.tag} (${channel}); exact-commit checks remain required before publication.`);
