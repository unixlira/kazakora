import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
globalThis.window = {location:{origin:'http://localhost'}};
globalThis.document = {cookie:'',querySelector:()=>null};
const read = p => process.env.BASELINE_SOURCE ? execFileSync('git',['show',`HEAD:resources/js/Modules/${p}`],{encoding:'utf8'}) : readFileSync(new URL('../resources/js/Modules/' + p, import.meta.url), 'utf8');
test('feed renders complete body and per-post interactions after carousel', () => {
  const story = read('Blog/Components/EditorialStory.vue');
  assert.match(story, /<div class="plain-body">/);
  assert.ok(story.indexOf('mobile-products') < story.indexOf('{{post.body}}'));
  assert.doesNotMatch(story, /SocialActions v-if="detail"/);
  assert.match(read('Blog/Index.vue'), /:interaction="post.interaction/);
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
test('admin search exposes status and preserves selections on failed searches', () => {
  const edit = read('Admin/Editorial/Edit.vue');
  assert.match(edit, /selectedProducts/);
  assert.match(edit, /response.ok/);
  assert.match(edit, /role="status"/);
  assert.match(edit, /\/admin\/editorial\/products\?q=/);
});
