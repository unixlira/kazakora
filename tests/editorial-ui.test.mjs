import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
globalThis.window = {location:{origin:'http://localhost'}};
globalThis.document = {cookie:'',querySelector:()=>null};
const read = p => process.env.BASELINE_SOURCE ? execFileSync('git',['show',`HEAD:resources/js/Modules/${p}`],{encoding:'utf8'}) : readFileSync(new URL('../resources/js/Modules/' + p, import.meta.url), 'utf8');
test('feed shows magazine cards with per-post interactions; detail renders the full body as text', () => {
  const index = read('Blog/Index.vue');
  assert.match(index, /:interaction="post.interaction/);
  assert.match(index, /hasMore && nextPageUrl/);
  assert.match(index, /previousPageUrl/);
  const card = read('Blog/Components/StoryCard.vue');
  assert.match(card, /<SocialActions compact :post="post" :interaction="interaction"\/>/);
  assert.match(card, /\{\{ post.excerpt \}\}/);
  assert.match(card, /post.category_name \|\| post.category/);
  const story = read('Blog/Components/EditorialStory.vue');
  assert.match(story, /class="plain-body article-body"/);
  assert.match(story, /\{\{ para.text \}\}/);
  assert.match(story, /<SocialActions :post="post" :interaction="interaction">/);
  for (const file of ['Blog/Index.vue', 'Blog/Show.vue', 'Blog/BlogLayout.vue', 'Blog/Components/StoryCard.vue', 'Blog/Components/EditorialStory.vue', 'Blog/Components/SocialActions.vue', 'Blog/Components/ContextualProducts.vue']) {
    assert.doesNotMatch(read(file), /v-html|innerHTML/, file);
  }
});
test('blog uses the same footer component as the store', () => {
  assert.match(read('Blog/BlogLayout.vue'), /<RodapeLoja \/>/);
  const store = readFileSync(new URL('../resources/js/Shared/Layouts/AppLayout.vue', import.meta.url), 'utf8');
  assert.match(store, /<RodapeLoja \/>/);
});
test('share offers WhatsApp, copy link and native share only when supported', () => {
  const social = read('Blog/Components/SocialActions.vue');
  assert.match(social, /https:\/\/wa.me\/\?text=/);
  assert.match(social, /navigator.clipboard.writeText/);
  assert.match(social, /typeof navigator.share==='function'/);
  assert.match(social, /v-if="canNativeShare"/);
});
test('body paragraphs, labels and dates are formatted as plain text', async () => {
  const f = await import('../resources/js/Modules/Blog/blogFormat.js');
  const blocks = f.paragraphs('Ingredientes: 1 litro de água\n\nNa bancada\n\nSepare tudo. Depois <b>mexa</b>.');
  assert.deepEqual(blocks.map(b => [b.kind, b.label]), [['p', 'Ingredientes'], ['h', ''], ['p', '']]);
  assert.equal(blocks[2].text, 'Separe tudo. Depois <b>mexa</b>.');
  assert.equal(f.longDate('2026-11-19T23:58:15-03:00'), '19 de novembro de 2026');
  assert.equal(f.blogUrl({ categoria: 'casa', tipo: '' }), '/blog?categoria=casa');
  assert.equal(f.typeLabel('recipe'), 'Receita');
});
test('missing and guest interaction capabilities never send mutation requests', async () => {
  let script = read('Blog/Components/SocialActions.vue').split('<script setup>')[1].split('</script>')[0].replace(/import .*?;/g, '');
  for (const interaction of [{}, {canInteract:false}]) {
    let requests = 0;
    const methods = new Function('defineProps','ref','computed','fetch', script + ';return {like,comment};')(
      () => ({post:{slug:'fixture'},interaction}), value=>({value}), fn=>({value:fn}), () => { requests++; throw new Error('guest request'); });
    await methods.like(); await methods.comment();
    assert.equal(requests, 0);
  }
});
test('feed comment labels and share dialogs are scoped to their post', () => {
  const social = read('Blog/Components/SocialActions.vue');
  assert.match(social, /:for="`editorial-comment-\$\{post.id\}`"/);
  assert.match(social, /:id="`editorial-comment-\$\{post.id\}`"/);
  assert.match(social, /:aria-labelledby="`share-title-\$\{post.id\}`"/);
  assert.match(social, /:id="`share-title-\$\{post.id\}`"/);
});
test('admin search exposes status and preserves selections on failed searches', () => {
  const edit = read('Admin/Editorial/Edit.vue');
  assert.match(edit, /selectedProducts/);
  assert.match(edit, /response.ok/);
  assert.match(edit, /role="status"/);
  assert.match(edit, /\/admin\/editorial\/products\?q=/);
});
