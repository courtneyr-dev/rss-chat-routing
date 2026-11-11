// Headless check of assets/js/editor.js: drive Panel() with canned editor
// state and assert the three-state control + effective message logic.
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');

let registered = null;
let spoken = [];
let effects = [];

function makeWp(state) {
  return {
    plugins: { registerPlugin: (name, opts) => { registered = { name, opts }; } },
    element: {
      createElement: (type, props, ...children) => ({ type, props, children: children.flat() }),
      Fragment: 'Fragment',
      useEffect: (fn) => { effects.push(fn); fn(); },
    },
    i18n: { __: (s) => s, sprintf: (s, v) => s.replace('%s', v) },
    a11y: { speak: (msg) => spoken.push(msg) },
    components: { RadioControl: 'RadioControl' },
    editor: { PluginDocumentSettingPanel: 'Panel', PluginPrePublishPanel: 'PrePublish' },
    data: {
      useSelect: (fn) => fn((store) => ({
        getCurrentPostType: () => state.postType,
        getEditedPostAttribute: (attr) => state[attr],
      })),
      useDispatch: () => ({ editPost: (edit) => { state.lastEdit = edit; } }),
    },
  };
}

function textOf(node, out = []) {
  if (typeof node === 'string') out.push(node);
  else if (node && node.children) node.children.forEach((c) => textOf(c, out));
  return out;
}

function render(state, config) {
  registered = null; spoken = []; effects = [];
  const fn = new Function('window', 'wp', 'config',
    src.replace(/\( function \( wp, config \) \{/, '(function (wp, config) {')
       .replace(/\)\( window\.wp, window\.rssChatRouting \);\s*$/, ')(wp, config);'));
  fn({}, makeWp(state), config);
  if (!registered) throw new Error('panel did not register');
  return registered.opts.render();
}

const config = {
  metaKey: '_rss_chat_routing',
  defaultFormat: 'status',
  defaultFormatLabel: 'Status',
  defaultKind: 'note',
  legacyAll: false,
  kindsById: { 7: { slug: 'note', name: 'Note' } },
  settingsUrl: 'https://example.test/settings',
};

const cases = [
  { name: 'status format matches', state: { postType: 'post', meta: {}, format: 'status', kind: [] },
    expect: 'Included because the post format is Status.' },
  { name: 'kind matches', state: { postType: 'post', meta: {}, format: '', kind: [7] },
    expect: 'Included because the post kind is Note.' },
  { name: 'no match', state: { postType: 'post', meta: {}, format: 'aside', kind: [] },
    expect: 'Not included by the site default.' },
  { name: 'explicit include', state: { postType: 'post', meta: { _rss_chat_routing: 'include' }, format: '', kind: [] },
    expect: 'Included by this post’s override.' },
  { name: 'explicit exclude beats match', state: { postType: 'post', meta: { _rss_chat_routing: 'exclude' }, format: 'status', kind: [] },
    expect: 'Excluded by this post’s override.' },
  { name: 'legacy stored 0 reads as exclude', state: { postType: 'post', meta: { _rss_chat_routing: '0' }, format: 'status', kind: [] },
    expect: 'Excluded by this post’s override.' },
];

let failures = 0;
for (const c of cases) {
  const tree = render(c.state, config);
  const text = textOf(tree).join(' ');
  const ok = text.includes(c.expect) && spoken.includes(c.expect);
  if (!ok) { failures++; console.log('FAIL:', c.name, '\n  wanted:', c.expect, '\n  got:', text, '\n  spoken:', spoken); }
  else console.log('ok:', c.name);
  // The radio control carries the three named states.
  const radio = JSON.stringify(tree);
  for (const v of ['include', 'exclude']) {
    if (!radio.includes('"' + v + '"')) { failures++; console.log('FAIL: missing radio value', v); }
  }
}

// Sitewide send modes.
function find(node, type) {
  if (!node || typeof node !== 'object') return null;
  if (node.type === type) return node;
  for (const c of node.children || []) { const hit = find(c, type); if (hit) return hit; }
  return null;
}

const modeCases = [
  { mode: 'opt_in', meta: {}, format: 'status',
    expect: 'Not sent: the site sends only posts you include.', label: 'Use the site default (don’t send)' },
  { mode: 'opt_in', meta: { _rss_chat_routing: 'include' }, format: '',
    expect: 'Included by this post’s override.' },
  { mode: 'opt_out', meta: {}, format: 'aside',
    expect: 'Included: the site sends every post you don’t exclude.', label: 'Use the site default (send)' },
  { mode: 'opt_out', meta: { _rss_chat_routing: 'exclude' }, format: '',
    expect: 'Excluded by this post’s override.' },
  { mode: 'per_post', meta: {}, format: 'status',
    expect: 'Not sent yet: choose Include or Exclude for this post.', label: 'Not chosen yet (won’t send)', prepublishOpen: true },
  { mode: 'per_post', meta: { _rss_chat_routing: 'include' }, format: '',
    expect: 'Included by this post’s override.', prepublishOpen: false },
];

for (const c of modeCases) {
  const name = c.mode + ' ' + JSON.stringify(c.meta);
  const tree = render({ postType: 'post', meta: c.meta, format: c.format, kind: [] }, Object.assign({}, config, { sendMode: c.mode }));
  const text = textOf(tree).join(' ');
  const json = JSON.stringify(tree);
  const pre = find(tree, 'PrePublish');
  let ok = text.includes(c.expect) && spoken.includes(c.expect);
  if (c.label && !json.includes(JSON.stringify(c.label))) ok = false;
  if ('prepublishOpen' in c) {
    if (!pre || pre.props.initialOpen !== c.prepublishOpen || !find(pre, 'RadioControl')) ok = false;
  } else if (pre) {
    ok = false;
  }
  if (!ok) { failures++; console.log('FAIL:', name, '\n  wanted:', c.expect, '\n  got:', text, '\n  prepublish:', pre && pre.props); }
  else console.log('ok:', name);
}

// Non-post types render nothing.
const nullTree = render({ postType: 'page', meta: {}, format: '', kind: [] }, config);
if (nullTree !== null) { failures++; console.log('FAIL: page rendered a panel'); } else console.log('ok: non-post renders null');

process.exit(failures ? 1 : 0);
