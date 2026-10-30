const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {markdown, check} = require('./check.cjs');

test('Fenced examples do not create headings or links; diagrams remain available for rendering', () => {
  const result = markdown('# Actual\n```bash\n# Not a heading\n[x](missing.md)\n```\n```mermaid\nflowchart LR\n A --> B\n```\n[x](#actual)');
  assert.deepEqual([...result.anchors], ['actual']);
  assert.deepEqual(result.links, ['#actual']);
  assert.deepEqual(result.diagrams, ['flowchart LR\n A --> B']);
});

test('Heading punctuation, inline code and duplicates use stable repository anchors', () => {
  const result = markdown('## Read `GET /metrics`\n## Read `GET /metrics`\n## Résumé');
  assert.deepEqual([...result.anchors], ['read-get-metrics', 'read-get-metrics-1', 'résumé']);
  assert.throws(() => markdown('```bash\nunclosed'), /Unclosed/);
});

test('Local targets, fragments and escaped spaces are checked without fetching external URLs', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'bridge-docs-'));
  try {
    for (const name of ['docs', 'monitoring', 'skills', 'tools/demo-viewer']) fs.mkdirSync(path.join(root, name), {recursive: true});
    fs.writeFileSync(path.join(root, 'docs', 'A guide.md'), '# Destination\n');
    fs.writeFileSync(path.join(root, 'README.md'), '# Home\n[x](docs/A%20guide.md#destination)\n[x](https://invalid.example/no-network)');
    assert.deepEqual(check(root).errors, []);
    fs.appendFileSync(path.join(root, 'README.md'), '\n[x](docs/A%20guide.md#absent)\n[x](absent.md)\n[x](../outside.md)');
    const result = check(root);
    assert.equal(result.errors.length, 3);
    assert.ok(result.errors.some(x => x.includes('Heading #absent')));
    assert.ok(result.errors.some(x => x.includes('does not exist')));
    assert.ok(result.errors.some(x => x.includes('leaves the repository')));
  } finally {
    assert.equal(path.dirname(path.resolve(root)), path.resolve(os.tmpdir()));
    assert.ok(path.basename(root).startsWith('bridge-docs-'));
    fs.rmSync(root, {recursive: true, force: true});
  }
});
