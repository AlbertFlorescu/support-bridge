const fs = require('node:fs');
const path = require('node:path');

function markdown(text) {
  const prose = [];
  const anchors = new Set();
  const duplicates = new Map();
  const diagrams = [];
  let fence = null;
  let diagram = null;
  for (const line of text.split(/\r?\n/)) {
    const marker = line.match(/^\s*(`{3,}|~{3,})(.*)$/);
    if (marker) {
      if (fence === null) {
        fence = marker[1];
        if (marker[2].trim() === 'mermaid') diagram = [];
      } else if (marker[1][0] === fence[0] && marker[1].length >= fence.length && marker[2].trim() === '') {
        if (diagram !== null) diagrams.push(diagram.join('\n'));
        fence = null;
        diagram = null;
      }
      continue;
    }
    if (fence !== null) {
      if (diagram !== null) diagram.push(line);
      continue;
    }
    prose.push(line);
    const heading = line.match(/^#{1,6}\s+(.+?)(?:\s+#+)?\s*$/);
    if (heading) {
      const slug = heading[1].replace(/<[^>]+>/g, '').replace(/[^\p{L}\p{N}_\- ]/gu, '').toLowerCase().replace(/ /g, '-');
      const count = duplicates.get(slug) ?? 0;
      anchors.add(slug + (count ? `-${count}` : ''));
      duplicates.set(slug, count + 1);
    }
  }
  if (fence !== null) throw new Error('Unclosed Markdown fence.');
  const links = [...prose.join('\n').matchAll(/!?\[[^\]\n]*\]\((<[^>]+>|[^\s)]+)(?:\s+"[^"]*")?\)/g)]
    .map(match => match[1].replace(/^<|>$/g, ''));
  return {anchors, diagrams, links};
}

function documents(root) {
  const result = [];
  function visit(directory) {
    for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
      const location = path.join(directory, entry.name);
      if (entry.isSymbolicLink()) continue;
      if (entry.isDirectory()) {
        if (!['node_modules', 'vendor', 'meetings', '__pycache__'].includes(entry.name)) visit(location);
      } else if (entry.name.endsWith('.md')) result.push(location);
    }
  }
  for (const file of fs.readdirSync(root)) if (file.endsWith('.md')) result.push(path.join(root, file));
  for (const folder of ['docs', 'monitoring', 'skills', 'tools/demo-viewer', '.github']) {
    if (fs.existsSync(path.join(root, folder))) visit(path.join(root, folder));
  }
  return result.sort();
}

function check(root) {
  const files = documents(root);
  const parsed = new Map();
  const errors = [];
  let links = 0;
  let diagrams = 0;
  for (const file of files) {
    try {
      const item = markdown(fs.readFileSync(file, 'utf8'));
      parsed.set(file, item);
      diagrams += item.diagrams.length;
    } catch (error) { errors.push(`${path.relative(root, file)}: ${error.message}`); }
  }
  for (const [file, item] of parsed) {
    for (const link of item.links) {
      if (/^[a-z][a-z0-9+.-]*:/i.test(link) || link.startsWith('//')) continue;
      links++;
      try {
        const hash = link.indexOf('#');
        const destination = hash === -1 ? link : link.slice(0, hash);
        const anchor = hash === -1 ? '' : decodeURIComponent(link.slice(hash + 1));
        const target = destination ? path.resolve(path.dirname(file), decodeURIComponent(destination)) : file;
        const relative = path.relative(root, target);
        if (relative === '..' || relative.startsWith(`..${path.sep}`) || path.isAbsolute(relative)) {
          throw new Error('Target leaves the repository.');
        }
        if (!fs.existsSync(target)) throw new Error('Target does not exist.');
        if (anchor && target.endsWith('.md')) {
          const targetDocument = parsed.get(target) ?? markdown(fs.readFileSync(target, 'utf8'));
          if (!targetDocument.anchors.has(anchor)) throw new Error(`Heading #${anchor} does not exist.`);
        }
      } catch (error) { errors.push(`${path.relative(root, file)} -> ${link}: ${error.message}`); }
    }
  }
  return {files: files.length, links, diagrams, errors};
}

if (require.main === module) {
  const result = check(path.resolve(__dirname, '../..'));
  for (const error of result.errors) console.error(error);
  console.log(`Checked ${result.files} Markdown files, ${result.links} local links and ${result.diagrams} Mermaid blocks.`);
  console.log('External URLs and Mermaid syntax/rendering require separate review.');
  process.exitCode = result.errors.length ? 1 : 0;
}

module.exports = {markdown, check};
