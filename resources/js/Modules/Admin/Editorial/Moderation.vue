<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
defineProps({comments:{type:Array,default:()=>[]}});const form=useForm({});
function moderate(id,action){form.post(`/admin/editorial/comments/${id}/${action}`,{preserveScroll:true});}
</script>
<template><AdminLayout><Head title="Moderação editorial"/><section class="p-6 max-w-4xl"><a href="/admin/editorial/posts">← Editorial</a><h1 class="text-2xl font-bold my-4">Revisão de comentários</h1><p>Revise também desinformação e contexto. O filtro automático não substitui a revisão humana.</p><p v-if="!comments.length" class="my-6">Nenhum comentário para revisar.</p><article v-for="c in comments" :key="c.id" class="my-4 border rounded p-4"><h2 class="font-bold">{{c.display_name}} · {{c.status}}</h2><p class="whitespace-pre-wrap break-words my-3">{{c.body}}</p><div class="flex gap-4"><button v-if="c.status!=='approved'" class="border rounded p-3" :disabled="form.processing" @click="moderate(c.id,'approve')">Aprovar</button><button v-if="c.status!=='rejected'" class="border rounded p-3" :disabled="form.processing" @click="moderate(c.id,'reject')">Rejeitar</button></div></article><p v-for="(error,key) in form.errors" :key="key" role="alert">{{error}}</p></section></AdminLayout></template>
