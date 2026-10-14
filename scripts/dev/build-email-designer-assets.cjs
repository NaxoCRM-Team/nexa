const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const destination = path.join(root, 'espocrm/client/custom/lib/email-designer');
const files = [
    ['grapesjs/dist/grapes.min.js', 'grapes.min.js'],
    ['grapesjs/dist/css/grapes.min.css', 'grapes.min.css'],
    ['grapesjs/LICENSE', 'grapesjs-LICENSE'],
    ['grapesjs-preset-newsletter/dist/index.js', 'newsletter.js'],
    ['grapesjs-preset-newsletter/LICENSE', 'newsletter-LICENSE'],
    ['dompurify/dist/purify.min.js', 'purify.min.js'],
    ['dompurify/LICENSE', 'dompurify-LICENSE'],
];
fs.mkdirSync(destination, {recursive: true});
for (const [source, name] of files) fs.copyFileSync(path.join(root, 'node_modules', source), path.join(destination, name));
console.log('Local email designer assets built.');
