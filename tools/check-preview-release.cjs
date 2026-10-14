'use strict';

const fs = require('node:fs');
const manifest = JSON.parse(fs.readFileSync('.release/preview.json', 'utf8'));
if (!/^v0\.\d+\.\d+-beta\.[1-9]\d*$/.test(manifest.tag)) {
  throw new Error('This pipeline only publishes explicit 0.x beta tags.');
}
if (manifest.notes !== `.release/${manifest.tag}.md`) {
  throw new Error('Release notes must match the beta tag.');
}
const notes = fs.readFileSync(manifest.notes, 'utf8');
if (!notes.includes(manifest.tag) || !notes.includes('prerelease')) {
  throw new Error('Release notes must identify the tag and prerelease status.');
}
if (!fs.readFileSync('CHANGELOG.md', 'utf8').includes(`## ${manifest.tag} `)) {
  throw new Error('The beta needs a dated changelog entry.');
}
console.log(`Validated ${manifest.tag}; stable publication is not enabled.`);
